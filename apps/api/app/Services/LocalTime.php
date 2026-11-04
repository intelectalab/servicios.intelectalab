<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * "Hoy" en la hora de las empresas (Perú). La aplicación guarda y compara las horas en UTC, pero
 * las fechas de negocio (emisión, vencimiento, el filtro "hoy", el año del presupuesto) son las
 * del calendario de Lima: de noche en Perú, en UTC ya es el día siguiente.
 */
class LocalTime
{
    public const ZONE = 'America/Lima';

    /** Fecha de hoy en Lima, en SQL (para comparar con columnas date). */
    public const SQL_TODAY = "(now() AT TIME ZONE 'America/Lima')::date";

    /**
     * La fecha de hoy en Lima, a medianoche en la zona de la aplicación: así se compara bien con
     * las columnas date, que Laravel lee de la misma forma.
     */
    public static function today(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', now(self::ZONE)->toDateString(), config('app.timezone'))->startOfDay();
    }

    /** El instante en que empezó hoy en Lima (para comparar con horas guardadas). */
    public static function startOfDay(): Carbon
    {
        return now(self::ZONE)->startOfDay()->utc();
    }
}
