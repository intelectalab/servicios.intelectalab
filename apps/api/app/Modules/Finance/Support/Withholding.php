<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Models\Invoice;

/**
 * Detracción (SPOT) y retención del IGV de una factura.
 *
 *   - Detracción: el cliente deposita un porcentaje del total en la cuenta de detracciones de la
 *     empresa en el Banco de la Nación. Aplica a facturas por más de S/ 700, si la empresa la
 *     tiene activada, con el código de servicio del comprobante.
 *   - Retención: si el cliente es agente de retención y no corresponde detracción, retiene el 3 %.
 *
 * El importe se calcula y se guarda siempre en soles. Los códigos y porcentajes son los del
 * Anexo 3 del SPOT (servicios); deben validarse con el área contable de cada empresa.
 */
class Withholding
{
    /** Código del Anexo 3 => [servicio, porcentaje]. */
    public const CODES = [
        '012' => ['Intermediación laboral y tercerización', 12],
        '019' => ['Arrendamiento de bienes', 10],
        '020' => ['Mantenimiento y reparación de bienes muebles', 12],
        '021' => ['Movimiento de carga', 10],
        '022' => ['Otros servicios empresariales', 12],
        '024' => ['Comisión mercantil', 10],
        '025' => ['Fabricación de bienes por encargo', 10],
        '026' => ['Servicio de transporte de personas', 10],
        '030' => ['Contratos de construcción', 4],
        '037' => ['Demás servicios gravados con el IGV', 12],
    ];

    public const DEFAULT_CODE = '037';

    /** Aplican a comprobantes por más de este importe, en soles. */
    public const THRESHOLD = 700;

    public const RETENTION_RATE = 3;

    /** Cómo decide cada comprobante => texto visible. */
    public const MODES = ['AUTO' => 'Automática (según configuración)', 'NONE' => 'No aplica', 'DETRACTION' => 'Detracción', 'RETENTION' => 'Retención 3 %'];

    public const STATES = ['PENDING' => 'Por recibir', 'RECEIVED' => 'Constancia recibida'];

    /**
     * Detracción o retención que le corresponde a un comprobante, o null si ninguna.
     *
     * @param  array  $settings  configuración fiscal de la empresa (`FiscalSettings::for`)
     * @param  float|null  $rate  tipo de cambio del comprobante si es en dólares
     * @return array{type: string, code: ?string, rate: float, amount: float}|null
     */
    public static function compute(Invoice $invoice, array $settings, bool $retentionAgent, ?float $rate): ?array
    {
        if ($invoice->invoice_type !== 'INVOICE') {
            return null;
        }
        $totalPen = round((float) $invoice->total * ($invoice->currency === 'USD' ? (float) $rate : 1), 2);
        if ($totalPen <= self::THRESHOLD) {
            return null;
        }

        $mode = $invoice->withholding_mode ?: 'AUTO';
        $detraction = function (string $code) use ($totalPen): array {
            $pct = (float) self::CODES[$code][1];

            // El depósito de detracción se hace en soles y sin decimales.
            return ['type' => 'DETRACTION', 'code' => $code, 'rate' => $pct, 'amount' => (float) round($totalPen * $pct / 100)];
        };
        $retention = fn (): array => ['type' => 'RETENTION', 'code' => null, 'rate' => (float) self::RETENTION_RATE, 'amount' => round($totalPen * self::RETENTION_RATE / 100, 2)];

        return match (true) {
            $mode === 'NONE' => null,
            $mode === 'DETRACTION' => $detraction(isset(self::CODES[$invoice->withholding_code]) ? $invoice->withholding_code : $settings['detraction']['code']),
            $mode === 'RETENTION' => $retention(),
            $settings['detraction']['enabled'] => $detraction($settings['detraction']['code']),
            $retentionAgent => $retention(),
            default => null,
        };
    }

    public static function label(?string $type, ?string $code = null, ?float $rate = null): ?string
    {
        return match ($type) {
            'DETRACTION' => 'Detracción '.rtrim(rtrim(number_format((float) $rate, 2), '0'), '.').' %'.($code ? " (código {$code})" : ''),
            'RETENTION' => 'Retención '.rtrim(rtrim(number_format((float) $rate, 2), '0'), '.').' %',
            default => null,
        };
    }
}
