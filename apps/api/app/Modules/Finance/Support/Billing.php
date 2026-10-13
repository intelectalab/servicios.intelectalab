<?php

namespace App\Modules\Finance\Support;

use App\Modules\Core\Models\Customer;
use App\Modules\Finance\Models\BillableEvent;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Operations\Models\WorkOrder;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reglas compartidas de facturación: IGV, series y correlativos, datos fiscales del cliente y eventos que nacen solos. */
class Billing
{
    /** IGV, en porcentaje. Todos los servicios nacen afectos. */
    public const TAX_RATE = 18;

    /** Serie con la que se emite cada tipo de comprobante. */
    private const SERIES = ['INVOICE' => 'F001', 'RECEIPT' => 'B001'];

    /** Mientras no haya un proveedor de facturación electrónica conectado, nada se envía a SUNAT. */
    public const NOT_SENT_NOTE = 'Sin proveedor de facturación electrónica conectado: el comprobante no se envió a SUNAT.';

    /** Días de crédito cuando el cliente no tiene los suyos. */
    public const DEFAULT_TERMS_DAYS = 30;

    public static function withTax(float $base): float
    {
        return round($base * (1 + self::TAX_RATE / 100), 2);
    }

    /**
     * Serie de un comprobante. La nota de crédito sigue a lo que corrige: la de una factura
     * empieza con F y la de una boleta con B.
     */
    public static function seriesFor(string $type, ?Invoice $corrects = null): string
    {
        if ($type === 'CREDIT_NOTE') {
            return $corrects?->invoice_type === 'RECEIPT' ? 'BC01' : 'FC01';
        }

        return self::SERIES[$type] ?? 'F001';
    }

    /**
     * Siguiente correlativo de una serie. Bloquea la fila de la serie hasta que termine la
     * transacción: dos emisiones a la vez no reciben el mismo número. Debe llamarse dentro de una.
     */
    public static function nextNumber(string $tenantId, string $type, string $series): int
    {
        $configuration = fn () => DB::table('fiscal_configurations')->where('tenant_id', $tenantId)->where('country_code', 'PE')->value('id');
        $configurationId = $configuration();
        if (! $configurationId) {
            DB::table('fiscal_configurations')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'country_code' => 'PE']);
            $configurationId = $configuration();
        }

        DB::table('fiscal_series')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'fiscal_configuration_id' => $configurationId,
            'document_type' => $type, 'series' => $series, 'next_number' => 1,
        ]);
        $row = DB::table('fiscal_series')->where('tenant_id', $tenantId)->where('document_type', $type)->where('series', $series)
            ->lockForUpdate()->first(['id', 'next_number']);
        DB::table('fiscal_series')->where('id', $row->id)->update(['next_number' => $row->next_number + 1]);

        return (int) $row->next_number;
    }

    public static function formatNumber(string $series, int $number): string
    {
        return sprintf('%s-%08d', $series, $number);
    }

    /**
     * Nombre y documento del cliente tal como irán en el comprobante.
     *
     * @return array{name: string, tax_id: ?string, has_ruc: bool}
     */
    public static function identity(?Customer $customer): array
    {
        $party = $customer?->party;
        $organization = $party?->organization;
        $person = $party?->person;

        $name = $organization
            ? (string) ($organization->legal_name ?: $organization->trade_name)
            : trim(($person->given_names ?? '').' '.($person->family_name ?? ''));
        $taxId = trim((string) ($organization ? $organization->tax_identifier : ($person->document_number ?? '')));
        $isRuc = (bool) preg_match('/^(10|15|16|17|20)\d{9}$/', $taxId)
            && ($organization || strtoupper((string) ($person->document_type ?? '')) === 'RUC');

        return ['name' => $name, 'tax_id' => $taxId !== '' ? $taxId : null, 'has_ruc' => $isRuc];
    }

    /** Quien vendió el contrato: de esa persona son, por alcance, sus eventos, comprobantes y cuentas por cobrar. */
    public static function sellerOf(?string $contractId): ?string
    {
        return $contractId ? DB::table('contracts')->where('id', $contractId)->value('created_by') : null;
    }

    /**
     * Al completar una orden de trabajo de un servicio que se cobra por evento, deja su evento
     * facturable (uno por orden: completarla otra vez no crea otro). Debe llamarse dentro de la
     * transacción que completa la orden.
     */
    public static function eventFromWorkOrder(WorkOrder $order, Request $request): ?BillableEvent
    {
        $contracted = $order->contracted_service_id
            ? DB::table('contracted_services')
                ->join('services', 'services.id', '=', 'contracted_services.service_id')
                ->where('contracted_services.id', $order->contracted_service_id)
                ->first(['contracted_services.price', 'contracted_services.contract_id', 'contracted_services.customer_id', 'services.billing_mode'])
            : null;
        if (! $contracted || $contracted->billing_mode !== 'PER_EVENT' || (float) $contracted->price <= 0) {
            return null;
        }

        $existing = BillableEvent::ignoringAccessScope()->where('source_type', 'WORK_ORDER')->where('source_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        $contract = DB::table('contracts')->where('id', $contracted->contract_id)->first(['currency', 'created_by']);
        $base = round((float) $contracted->price, 2);
        $event = BillableEvent::create([
            'tenant_id' => $order->tenant_id,
            'source_type' => 'WORK_ORDER',
            'source_id' => $order->id,
            'customer_id' => $contracted->customer_id,
            'contract_id' => $contracted->contract_id,
            'description' => Str::limit("{$order->code} · {$order->title}", 500, ''),
            'base' => $base,
            'amount' => self::withTax($base),
            'currency' => in_array($contract?->currency, ['PEN', 'USD'], true) ? $contract->currency : 'PEN',
            'status' => 'PENDING',
            'idempotency_key' => "wo:{$order->id}",
            'owner_user_id' => $contract?->created_by,
            'created_by' => $request->user()->id,
            // El evento es de la sede de la orden.
            'branch_id' => $order->branch_id,
        ]);

        AuditLogger::log(
            tenantId: $order->tenant_id,
            userId: $request->user()->id,
            action: 'billable_event.create',
            entityType: 'BillableEvent',
            entityId: $event->id,
            metadata: ['to' => 'PENDING', 'source' => 'WORK_ORDER', 'amount' => (float) $event->amount, 'note' => $order->code],
        );

        return $event;
    }
}
