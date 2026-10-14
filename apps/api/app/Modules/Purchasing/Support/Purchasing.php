<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Chart;
use App\Modules\Core\Models\Supplier;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Support\Billing;
use App\Modules\Finance\Support\Withholding;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;

/** Reglas compartidas de Compras: cuentas de gasto, datos del proveedor, cruce de 3 vías, detracción y retención de 4.ª. */
class Purchasing
{
    /**
     * Cuentas de gasto con que nace el plan de cuentas. Desde Contabilidad, las que se ofrecen al
     * comprar salen del plan de la empresa (sus cuentas de gasto imputables y activas).
     */
    public const EXPENSE_ACCOUNTS = [
        '6211' => 'Sueldos y salarios',
        '6311' => 'Transporte de carga y movilidad',
        '6343' => 'Mantenimiento y reparaciones',
        '6352' => 'Alquiler de edificaciones',
        '6361' => 'Energía eléctrica',
        '6364' => 'Telefonía e internet',
        '6391' => 'Gastos bancarios',
        '6392' => 'Servicios de terceros subcontratados',
        '6394' => 'Licencias y servicios cloud',
        '6563' => 'Suministros y repuestos',
        '6761' => 'Pérdida por diferencia de cambio',
    ];

    public const DEFAULT_EXPENSE_ACCOUNT = '6392';

    /** Diferencia máxima, en porcentaje, entre lo facturado y lo recibido de una orden de compra. */
    public const MATCH_TOLERANCE = 2;

    /** Retención de 4.ª categoría de un recibo por honorarios: porcentaje y monto desde el que aplica (soles). */
    public const RETENTION_4TA_RATE = 8;

    public const RETENTION_4TA_THRESHOLD = 1500;

    private const OPTIONS_LIMIT = 500;

    /** Nombre del proveedor de la fila (tablas con `supplier_id`), para buscar y ordenar por él. */
    public static function supplierName(string $table): string
    {
        return "(SELECT COALESCE(o.trade_name, o.legal_name, concat_ws(' ', p.given_names, p.family_name))
            FROM suppliers s
            LEFT JOIN organizations o ON o.party_id = s.party_id
            LEFT JOIN persons p ON p.party_id = s.party_id
            WHERE s.id = {$table}.supplier_id)";
    }

    /** "CC-01 · Operaciones" del centro de costo de la fila. */
    public static function costCenterName(string $table): string
    {
        return "(SELECT concat_ws(' · ', cc.code, cc.name) FROM cost_centers cc WHERE cc.id = {$table}.cost_center_id)";
    }

    public static function accountLabel(?string $code): string
    {
        $tenantId = request()->attributes->get('tenant_id');
        if ($tenantId) {
            return Chart::label((string) $tenantId, $code);
        }

        return $code === null ? '' : (isset(self::EXPENSE_ACCOUNTS[$code]) ? "{$code} · ".self::EXPENSE_ACCOUNTS[$code] : $code);
    }

    /** Regla de validación: una cuenta de gasto imputable y activa del plan de la empresa. */
    public static function expenseAccountRule(string $tenantId): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($tenantId) {
            if (! Chart::postable($tenantId, is_string($value) ? $value : null, ['EXPENSE'])) {
                $fail('Selecciona una cuenta de gasto del plan de cuentas.');
            }
        };
    }

    /**
     * Nombre y documento del proveedor tal como quedan en lo que se le compra.
     *
     * @return array{name: string, tax_id: ?string, has_ruc: bool, party_id: ?string, detraction_account: ?string}
     */
    public static function identity(?Supplier $supplier): array
    {
        $party = $supplier?->party;
        $organization = $party?->organization;
        $person = $party?->person;

        $name = $organization
            ? (string) ($organization->trade_name ?: $organization->legal_name)
            : trim(($person->given_names ?? '').' '.($person->family_name ?? ''));
        $taxId = trim((string) ($organization ? $organization->tax_identifier : ($person->document_number ?? '')));

        return [
            'name' => $name,
            'tax_id' => $taxId !== '' ? $taxId : null,
            'has_ruc' => (bool) preg_match('/^(10|15|16|17|20)\d{9}$/', $taxId),
            'party_id' => $supplier?->party_id,
            'detraction_account' => $supplier?->detraction_account ?: null,
        ];
    }

    /** Proveedores activos de la empresa, para los formularios. */
    public static function suppliers(string $tenantId): array
    {
        return Supplier::query()->with('party.person', 'party.organization')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')
            ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)->get()
            ->map(function (Supplier $supplier) {
                $identity = self::identity($supplier);

                return ['id' => $supplier->id, 'name' => $identity['name'], 'tax_id' => $identity['tax_id'], 'detraction_account' => $identity['detraction_account']];
            })
            ->filter(fn (array $supplier) => $supplier['name'] !== '')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    public static function supplier(string $tenantId, string $id): ?Supplier
    {
        return Supplier::with('party.person', 'party.organization')->where('tenant_id', $tenantId)->whereKey($id)->first();
    }

    /** Centros de costo de la empresa. `$onlyActive`: los que se pueden elegir en un documento nuevo. */
    public static function costCenters(string $tenantId, bool $onlyActive = true): array
    {
        return DB::table('cost_centers')->where('tenant_id', $tenantId)->when($onlyActive, fn ($q) => $q->where('active', true))
            ->orderBy('code')->limit(self::OPTIONS_LIMIT)->get(['id', 'code', 'name', 'active'])
            ->map(fn ($center) => ['id' => $center->id, 'code' => $center->code, 'name' => $center->name, 'active' => (bool) $center->active])->all();
    }

    public static function expenseAccounts(string $tenantId): array
    {
        return collect(Chart::options($tenantId, ['EXPENSE']))->map(fn (array $account) => ['code' => $account['code'], 'name' => $account['name']])->values()->all();
    }

    public static function detractionCodes(): array
    {
        return collect(Withholding::CODES)->map(fn (array $code, string $key) => ['code' => (string) $key, 'name' => $code[0], 'rate' => (float) $code[1]])->values()->all();
    }

    /** Tipo de cambio de venta vigente a una fecha, o null si no hay ninguno registrado. */
    public static function rate(?string $date = null): ?float
    {
        $rate = ExchangeRate::current('USD', $date)?->sell;

        return $rate ? (float) $rate : null;
    }

    public static function withTax(float $base): float
    {
        return round($base * (1 + Billing::TAX_RATE / 100), 2);
    }

    /**
     * Detracción de un comprobante de proveedor: con el código del Anexo 3 que trae la factura,
     * si supera S/ 700. Se deposita en soles y sin decimales.
     *
     * @return array{code: string, pct: float, amount: float}|null
     */
    public static function detraction(string $docType, ?string $code, float $totalPen): ?array
    {
        if ($docType !== 'FACTURA' || $code === null || ! isset(Withholding::CODES[$code]) || $totalPen <= Withholding::THRESHOLD) {
            return null;
        }
        $pct = (float) Withholding::CODES[$code][1];

        return ['code' => $code, 'pct' => $pct, 'amount' => (float) round($totalPen * $pct / 100)];
    }

    /** Retención de 4.ª categoría de un recibo por honorarios, en su moneda. */
    public static function retention4ta(string $docType, float $total, float $totalPen): float
    {
        return $docType === 'RECIBO_HONORARIOS' && $totalPen > self::RETENTION_4TA_THRESHOLD
            ? round($total * self::RETENTION_4TA_RATE / 100, 2)
            : 0.0;
    }

    /**
     * Cruce de 3 vías: lo facturado (este comprobante más los ya aprobados de la misma orden) frente
     * a lo recibido de la orden de compra. Sin orden no hay nada que cruzar.
     *
     * @return array{ok: bool, tone: string, label: string}
     */
    public static function match(SupplierInvoice $invoice): array
    {
        if (! $invoice->purchase_order_id) {
            return ['ok' => true, 'tone' => 'neutral', 'label' => 'Sin orden de compra (gasto recurrente)'];
        }

        // La orden es un dato del comprobante: se cruza aunque quien mira no la alcance.
        $order = PurchaseOrder::ignoringAccessScope()->whereKey($invoice->purchase_order_id)
            ->select('purchase_orders.*')->selectRaw(PurchaseOrder::RECEIVED_PCT.' AS received_pct')->first();
        if (! $order) {
            return ['ok' => false, 'tone' => 'danger', 'label' => 'OC no encontrada'];
        }
        $received = (float) $order->received_pct;
        if ($received <= 0) {
            return ['ok' => false, 'tone' => 'danger', 'label' => 'La OC no tiene recepción registrada'];
        }

        $expected = (float) $order->subtotal * $received / 100;
        $previous = (float) DB::table('supplier_invoices')->where('purchase_order_id', $order->id)->where('id', '<>', $invoice->id)
            ->where('status', 'APPROVED')->sum('subtotal');
        $difference = $expected > 0 ? ((float) $invoice->subtotal + $previous - $expected) / $expected * 100 : 100.0;
        $shown = number_format($difference, 1, '.', '');
        $receivedShown = rtrim(rtrim(number_format($received, 2, '.', ''), '0'), '.');

        return abs($difference) <= self::MATCH_TOLERANCE
            ? ['ok' => true, 'tone' => 'success', 'label' => "OC {$order->order_number} · recibido {$receivedShown} % · diferencia {$shown} %"]
            : ['ok' => false, 'tone' => 'danger', 'label' => "Diferencia {$shown} % con la OC {$order->order_number} (tolerancia ".self::MATCH_TOLERANCE.' %)'];
    }
}
