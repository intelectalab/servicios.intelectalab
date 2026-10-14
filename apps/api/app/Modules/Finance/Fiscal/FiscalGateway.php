<?php

namespace App\Modules\Finance\Fiscal;

use App\Modules\Finance\Models\Invoice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Único punto por el que un comprobante sale hacia SUNAT. Elige el proveedor de la empresa,
 * presenta el comprobante cuando la emisión ya quedó guardada (nunca dentro de la transacción) y
 * anota lo que respondió: en el comprobante, en `fiscal_documents` y en auditoría.
 *
 * Sin proveedor conectado no hace nada: el comprobante queda "Sin enviar".
 */
class FiscalGateway
{
    /** Proveedor de facturación electrónica de la empresa; null si no tiene ninguno conectado. */
    public static function providerFor(string $tenantId): ?FiscalProvider
    {
        $code = self::configuration($tenantId)?->provider_code;
        $class = $code ? (config('fiscal.providers')[$code] ?? null) : null;

        return $class ? app($class) : null;
    }

    /** Para la ficha: si hay proveedor y cómo se llama. */
    public static function summary(string $tenantId): array
    {
        $provider = self::providerFor($tenantId);

        return ['connected' => $provider !== null, 'name' => $provider?->name()];
    }

    /**
     * Deja el comprobante "En envío" y lo presenta en cuanto la operación en curso queda guardada.
     * Devuelve false si la empresa no tiene proveedor (no cambia nada).
     */
    public static function queue(Invoice $invoice, ?string $userId): bool
    {
        if (! self::providerFor($invoice->tenant_id)) {
            return false;
        }

        $invoice->update(['fiscal_status' => 'PENDING', 'fiscal_response' => null]);
        $invoiceId = $invoice->id;
        $tenantId = $invoice->tenant_id;
        // Fuera de la transacción: si el envío falla, la emisión no se pierde; si la emisión se
        // deshace, nada llegó a enviarse.
        DB::afterCommit(fn () => self::send($invoiceId, $tenantId, $userId));

        return true;
    }

    /** Presenta el comprobante y guarda la respuesta. Un fallo de comunicación lo deja rechazado, con su motivo, para reintentar. */
    public static function send(string $invoiceId, string $tenantId, ?string $userId = null): void
    {
        $provider = self::providerFor($tenantId);
        $invoice = Invoice::ignoringAccessScope()->where('tenant_id', $tenantId)->find($invoiceId);
        if (! $provider || ! $invoice || $invoice->fiscal_status !== 'PENDING' || ! $invoice->number) {
            return;
        }

        try {
            $result = $provider->send(FiscalDocument::from($invoice), self::settings($tenantId));
        } catch (Throwable $e) {
            report($e);
            $result = FiscalResult::rejected('No se pudo comunicar con el proveedor de facturación electrónica. Reintenta el envío.');
        }

        DB::transaction(function () use ($invoice, $tenantId, $userId, $result) {
            $seriesId = DB::table('fiscal_series')->where('tenant_id', $tenantId)
                ->where('document_type', $invoice->invoice_type)->where('series', $invoice->series)->value('id');

            $key = ['tenant_id' => $tenantId, 'document_type' => $invoice->invoice_type, 'series' => $invoice->series, 'number' => $invoice->number];
            $documentId = DB::table('fiscal_documents')->where($key)->value('id') ?? (string) Str::uuid();
            DB::table('fiscal_documents')->updateOrInsert($key, [
                'id' => $documentId,
                'invoice_id' => $invoice->id,
                'fiscal_series_id' => $seriesId,
                'status' => $result->status,
                'sunat_status' => $result->code,
                'xml_hash' => $result->hash && strlen($result->hash) === 64 ? $result->hash : null,
                'cdr_code' => $result->cdr,
                'sent_at' => now(),
                'accepted_at' => $result->status === 'ACCEPTED' ? now() : null,
                'rejected_at' => $result->status === 'REJECTED' ? now() : null,
                'provider_response' => json_encode($result->raw),
            ]);

            $invoice->update([
                'fiscal_status' => $result->status,
                'fiscal_response' => Str::limit((string) $result->message, 1000, '…'),
                'fiscal_document_id' => $documentId,
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $userId,
                action: 'invoice.fiscal_'.strtolower($result->status),
                entityType: 'Invoice',
                entityId: $invoice->id,
                metadata: array_filter(['note' => $result->message, 'code' => $result->code]),
            );
        });
    }

    /** ¿El proveedor de la empresa sabe presentar comunicaciones de baja? */
    public static function supportsVoid(string $tenantId): bool
    {
        return self::providerFor($tenantId) instanceof FiscalVoidProvider;
    }

    /** Presenta la comunicación de baja en cuanto la operación en curso queda guardada. */
    public static function queueVoid(string $requestId, string $tenantId, ?string $userId): void
    {
        DB::afterCommit(fn () => self::sendVoid($requestId, $tenantId, $userId));
    }

    /**
     * Presenta una baja y guarda la respuesta. Aceptada, el comprobante queda anulado y su cuenta
     * por cobrar, sin saldo. Rechazada (o sin comunicación), el comprobante sigue emitido.
     */
    public static function sendVoid(string $requestId, string $tenantId, ?string $userId = null): void
    {
        $provider = self::providerFor($tenantId);
        $request = DB::table('fiscal_void_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->where('status', 'PENDING')->first();
        $invoice = $request ? Invoice::ignoringAccessScope()->where('tenant_id', $tenantId)->find($request->invoice_id) : null;
        if (! $provider instanceof FiscalVoidProvider || ! $invoice || $invoice->status !== 'ISSUED') {
            return;
        }

        try {
            $result = $provider->void(FiscalDocument::from($invoice), $request->reason, self::settings($tenantId));
        } catch (Throwable $e) {
            report($e);
            $result = FiscalResult::rejected('No se pudo comunicar con el proveedor de facturación electrónica. Vuelve a solicitar la baja.');
        }

        DB::transaction(function () use ($invoice, $request, $tenantId, $userId, $result) {
            DB::table('fiscal_void_requests')->where('id', $request->id)->update([
                'status' => $result->status,
                'ticket' => $result->code ? Str::limit($result->code, 100, '') : $request->ticket,
                'response' => Str::limit((string) $result->message, 1000, '…'),
                'resolved_at' => $result->status === 'PENDING' ? null : now(),
            ]);

            if ($result->status === 'ACCEPTED') {
                $invoice->update(['status' => 'VOID']);
                $receivable = \App\Modules\Finance\Models\Receivable::ignoringAccessScope()->where('invoice_id', $invoice->id)->lockForUpdate()->first();
                if ($receivable && (float) $receivable->balance_amount > 0) {
                    // El saldo y el estado de la cuenta los actualiza la base con este movimiento.
                    \App\Modules\Finance\Models\ReceivableMovement::create([
                        'tenant_id' => $tenantId, 'receivable_id' => $receivable->id, 'movement_type' => 'VOID',
                        'amount' => -1 * (float) $receivable->balance_amount, 'reference' => 'Baja '.($result->code ?: $invoice->invoice_number), 'created_by' => $userId,
                    ]);
                }
            }

            AuditLogger::log(
                tenantId: $tenantId, userId: $userId, action: 'invoice.void_'.strtolower($result->status), entityType: 'Invoice', entityId: $invoice->id,
                metadata: array_filter([
                    'from' => $result->status === 'ACCEPTED' ? 'ISSUED' : null, 'to' => $result->status === 'ACCEPTED' ? 'VOID' : null,
                    'note' => $result->message, 'code' => $result->code,
                ]),
            );
        });
    }

    /** Datos fiscales de la empresa que recibe el proveedor. Las credenciales no van aquí: las resuelve su clase. */
    private static function settings(string $tenantId): array
    {
        $configuration = self::configuration($tenantId);

        return [
            'tax_identifier' => $configuration?->tax_identifier,
            'environment' => $configuration?->environment ?? 'BETA',
            'configuration' => json_decode($configuration?->configuration ?? '{}', true) ?: [],
        ];
    }

    private static function configuration(string $tenantId): ?object
    {
        return DB::table('fiscal_configurations')->where('tenant_id', $tenantId)->where('country_code', 'PE')->where('active', true)
            ->first(['provider_code', 'tax_identifier', 'environment', 'configuration']);
    }
}
