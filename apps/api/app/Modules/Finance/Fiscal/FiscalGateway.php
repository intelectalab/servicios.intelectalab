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
