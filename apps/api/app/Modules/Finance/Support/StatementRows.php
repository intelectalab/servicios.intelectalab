<?php

namespace App\Modules\Finance\Support;

use App\Services\SpreadsheetImport;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Convierte las filas de un extracto bancario (leídas de Excel o CSV por `SpreadsheetImport`) en
 * líneas listas para conciliar: fecha, descripción, monto con signo y n.º de operación. Lo que no
 * se puede leer (una fila sin fecha o sin importe) se deja fuera con su aviso; nada se inventa.
 */
class StatementRows
{
    public const HEADERS = ['fecha', 'descripcion', 'monto', 'operacion'];

    /** Otros títulos que los bancos usan para cada columna. */
    public const ALIASES = [
        'fecha_operacion' => 'fecha', 'fecha_de_operacion' => 'fecha', 'fecha_proceso' => 'fecha', 'fecha_valor' => 'fecha', 'date' => 'fecha',
        'detalle' => 'descripcion', 'concepto' => 'descripcion', 'glosa' => 'descripcion', 'referencia' => 'descripcion', 'description' => 'descripcion',
        'importe' => 'monto', 'cargo_abono' => 'monto', 'amount' => 'monto',
        'n_operacion' => 'operacion', 'no_operacion' => 'operacion', 'nro_operacion' => 'operacion', 'num_operacion' => 'operacion',
        'numero_de_operacion' => 'operacion', 'numero_operacion' => 'operacion', 'operacion_numero' => 'operacion',
    ];

    /**
     * @param  array<int, array<string, string>>  $rows  filas por número, como las entrega `SpreadsheetImport::read`
     * @return array{lines: array<int, array{date: string, description: string, amount: float, op_number: string, fingerprint: string}>, warnings: string[]}
     */
    public static function parse(array $rows): array
    {
        $lines = [];
        $warnings = [];
        $seen = [];
        foreach ($rows as $number => $row) {
            $day = self::date((string) ($row['fecha'] ?? ''));
            $value = self::amount((string) ($row['monto'] ?? ''));
            if (! $day) {
                $warnings[] = "Fila {$number}: la fecha no es válida (usa día/mes/año) o es futura.";

                continue;
            }
            if ($value === null || abs($value) < 0.005) {
                $warnings[] = "Fila {$number}: el monto no es válido (los cargos van en negativo).";

                continue;
            }
            $op = trim((string) ($row['operacion'] ?? ''));
            // Dos líneas iguales el mismo día (dos ITF, dos comisiones) son dos líneas: las distingue su orden.
            $key = "{$day}|".number_format($value, 2, '.', '')."|{$op}";
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            $lines[] = [
                'date' => $day,
                'description' => trim((string) ($row['descripcion'] ?? '')),
                'amount' => $value,
                'op_number' => $op,
                'fingerprint' => mb_substr("{$key}|{$seen[$key]}", 0, 255),
            ];
        }

        return ['lines' => $lines, 'warnings' => $warnings];
    }

    /** Fecha como día/mes/año (como la entregan los bancos), año-mes-día o número de serie de Excel. Nunca futura más allá de mañana. */
    public static function date(string $text): ?string
    {
        $text = trim($text);
        try {
            $date = match (true) {
                (bool) preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $text, $m) => checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? Carbon::create((int) $m[3], (int) $m[2], (int) $m[1]) : null,
                (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) => checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? Carbon::create((int) $m[1], (int) $m[2], (int) $m[3]) : null,
                // Celda de fecha de Excel: llega como su número de serie.
                is_numeric($text) && (float) $text > 20000 && (float) $text < 80000 => Carbon::parse(SpreadsheetImport::date($text)),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return $date && $date->year >= 2000 && $date->lte(now()->addDay()) ? $date->toDateString() : null;
    }

    /** Importe con signo. Acepta "1,234.56", "1.234,56", "-25.00", "(25.00)" y símbolos de moneda. */
    public static function amount(string $text): ?float
    {
        $text = trim($text);
        $negative = str_contains($text, '-') || (str_starts_with($text, '(') && str_ends_with($text, ')'));
        $digits = preg_replace('/[^\d.,]/', '', $text);
        if ($digits === '' || ! preg_match('/\d/', $digits)) {
            return null;
        }

        $comma = strrpos($digits, ',');
        $dot = strrpos($digits, '.');
        if ($comma !== false && $dot !== false) {
            // El último de los dos es el separador decimal.
            $digits = $comma > $dot ? str_replace(',', '.', str_replace('.', '', $digits)) : str_replace(',', '', $digits);
        } elseif ($comma !== false) {
            // Solo coma: decimal si deja uno o dos dígitos detrás; si no, separador de miles.
            $digits = preg_match('/,\d{1,2}$/', $digits) ? str_replace(',', '.', $digits) : str_replace(',', '', $digits);
        } elseif ($dot !== false && substr_count($digits, '.') > 1) {
            $digits = str_replace('.', '', $digits);
        }

        return is_numeric($digits) ? round(($negative ? -1 : 1) * (float) $digits, 2) : null;
    }
}
