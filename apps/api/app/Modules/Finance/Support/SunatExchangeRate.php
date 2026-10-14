<?php

namespace App\Modules\Finance\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Tipo de cambio del dólar que publica SUNAT. Se lee de su archivo público, una línea con
 * "día/mes/año|compra|venta|". Si SUNAT no responde o responde otra cosa, falla: nunca se
 * inventa ni se estima un valor.
 */
class SunatExchangeRate
{
    /**
     * @return array{date: string, buy: float, sell: float}
     *
     * @throws RuntimeException si no se pudo obtener un tipo de cambio válido
     */
    public static function fetch(): array
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)->accept('text/plain')->get((string) config('fiscal.exchange_rate_url'));
        } catch (Throwable $e) {
            report($e);
            throw new RuntimeException('No se pudo conectar con SUNAT. Inténtalo de nuevo en unos minutos o registra el tipo de cambio a mano.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('SUNAT no respondió (código '.$response->status().'). Inténtalo de nuevo o registra el tipo de cambio a mano.');
        }

        // "13/10/2026|3.434|3.448|"
        if (! preg_match('/^\s*(\d{2})\/(\d{2})\/(\d{4})\|(\d{1,2}\.\d{1,4})\|(\d{1,2}\.\d{1,4})\|/', (string) $response->body(), $m)
            || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            throw new RuntimeException('SUNAT respondió con un formato que no se reconoce. Registra el tipo de cambio a mano.');
        }

        $date = Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
        [$buy, $sell] = [(float) $m[4], (float) $m[5]];
        // Lo publicado debe ser de estos días y tener sentido; si no, no se guarda.
        if ($buy <= 0 || $sell <= 0 || $buy > $sell * 1.2 || abs($date->diffInDays(today(), false)) > 7) {
            throw new RuntimeException('El tipo de cambio que publicó SUNAT no parece válido. Registra el tipo de cambio a mano.');
        }

        return ['date' => $date->toDateString(), 'buy' => $buy, 'sell' => $sell];
    }
}
