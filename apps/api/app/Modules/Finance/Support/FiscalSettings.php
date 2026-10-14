<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Configuración fiscal de la empresa: series con las que emite, si está en contingencia y cómo
 * aplica detracciones. Vive en `fiscal_configurations` (una fila por empresa); lo que la empresa
 * aún no definió toma los valores de siempre.
 */
class FiscalSettings
{
    /** Clave de la serie => [tipo de comprobante en `fiscal_series`, texto visible, serie por defecto, patrón]. */
    public const SERIES = [
        'INVOICE' => ['INVOICE', 'Factura', 'F001', '/^F[A-Z0-9]{3}$/'],
        'RECEIPT' => ['RECEIPT', 'Boleta', 'B001', '/^B[A-Z0-9]{3}$/'],
        'CREDIT_NOTE_INVOICE' => ['CREDIT_NOTE', 'Nota de crédito de factura', 'FC01', '/^F[A-Z0-9]{3}$/'],
        'CREDIT_NOTE_RECEIPT' => ['CREDIT_NOTE', 'Nota de crédito de boleta', 'BC01', '/^B[A-Z0-9]{3}$/'],
    ];

    /** Series de contingencia (numéricas): solo facturas y boletas. Distintas entre sí: el número del comprobante no se repite. */
    public const CONTINGENCY_SERIES = ['INVOICE' => '0001', 'RECEIPT' => '0002'];

    public const CONTINGENCY_PATTERN = '/^0\d{3}$/';

    public const CONTINGENCY_NOTE = 'Emitido en contingencia: se informará a SUNAT al restablecer el servicio.';

    /**
     * @return array{id: ?string, provider_code: ?string, tax_identifier: ?string, environment: string, mode: string,
     *     series: array<string, string>, contingency_series: array<string, string>,
     *     detraction: array{enabled: bool, code: string, account: ?string}}
     */
    public static function for(string $tenantId): array
    {
        $row = DB::table('fiscal_configurations')->where('tenant_id', $tenantId)->where('country_code', 'PE')
            ->first(['id', 'provider_code', 'tax_identifier', 'environment', 'configuration']);
        $stored = json_decode($row?->configuration ?? '{}', true) ?: [];
        $code = (string) ($stored['detraction']['code'] ?? Withholding::DEFAULT_CODE);

        return [
            'id' => $row?->id,
            'provider_code' => $row?->provider_code ?: null,
            'tax_identifier' => $row?->tax_identifier ?: null,
            'environment' => $row?->environment ?? 'BETA',
            'mode' => ($stored['mode'] ?? 'ONLINE') === 'CONTINGENCY' ? 'CONTINGENCY' : 'ONLINE',
            'series' => array_map(fn (string $key) => (string) ($stored['series'][$key] ?? self::SERIES[$key][2]), array_combine(array_keys(self::SERIES), array_keys(self::SERIES))),
            'contingency_series' => array_map(
                fn (string $key) => (string) ($stored['contingency_series'][$key] ?? self::CONTINGENCY_SERIES[$key]),
                array_combine(array_keys(self::CONTINGENCY_SERIES), array_keys(self::CONTINGENCY_SERIES)),
            ),
            'detraction' => [
                // Sin configurar no se aplica: un porcentaje supuesto mostraría un neto a cobrar falso.
                'enabled' => (bool) ($stored['detraction']['enabled'] ?? false),
                'code' => isset(Withholding::CODES[$code]) ? $code : Withholding::DEFAULT_CODE,
                'account' => ($stored['detraction']['account'] ?? null) ?: null,
            ],
        ];
    }

    /** Fila de configuración de la empresa; la crea si aún no existe. */
    public static function ensure(string $tenantId): string
    {
        $find = fn () => DB::table('fiscal_configurations')->where('tenant_id', $tenantId)->where('country_code', 'PE')->value('id');
        $id = $find();
        if (! $id) {
            DB::table('fiscal_configurations')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'country_code' => 'PE']);
            $id = $find();
        }

        return $id;
    }

    /** Guarda parte de la configuración sin perder el resto. */
    public static function patch(string $tenantId, array $columns, array $configuration): void
    {
        $id = self::ensure($tenantId);
        $current = json_decode(DB::table('fiscal_configurations')->where('id', $id)->lockForUpdate()->value('configuration') ?? '{}', true) ?: [];
        DB::table('fiscal_configurations')->where('id', $id)->update([
            ...$columns,
            'configuration' => json_encode(array_replace_recursive($current, $configuration)),
            'updated_at' => now(),
        ]);
    }

    /**
     * Serie con la que sale un comprobante. La nota de crédito sigue a lo que corrige (la de una
     * factura empieza con F y la de una boleta con B). En contingencia, facturas y boletas usan
     * su serie de contingencia.
     *
     * @return array{0: string, 1: string} tipo en `fiscal_series` y serie
     */
    public static function seriesFor(array $settings, string $type, ?Invoice $corrects = null): array
    {
        if ($type === 'CREDIT_NOTE') {
            return ['CREDIT_NOTE', $settings['series'][$corrects?->invoice_type === 'RECEIPT' ? 'CREDIT_NOTE_RECEIPT' : 'CREDIT_NOTE_INVOICE']];
        }
        $key = $type === 'RECEIPT' ? 'RECEIPT' : 'INVOICE';

        return [$key, $settings['mode'] === 'CONTINGENCY' ? $settings['contingency_series'][$key] : $settings['series'][$key]];
    }

    /** Último correlativo emitido de una serie (0 si aún no se usó). */
    public static function lastNumber(string $tenantId, string $type, string $series): int
    {
        return max(0, (int) DB::table('fiscal_series')->where('tenant_id', $tenantId)->where('document_type', $type)->where('series', $series)->value('next_number') - 1);
    }
}
