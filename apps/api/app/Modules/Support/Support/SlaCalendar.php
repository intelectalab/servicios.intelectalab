<?php

namespace App\Modules\Support\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Calendario laboral de la empresa y cálculo de vencimientos del SLA.
 *
 * - Horario por día de la semana (0 = domingo … 6 = sábado), en la zona horaria de la empresa;
 *   un día sin horario o feriado no cuenta.
 * - Cada prioridad tiene su política: minutos de respuesta y de resolución, y si se cuentan en
 *   horario hábil o 24×7. Un contrato Premium multiplica los tiempos por el factor de la empresa.
 * - Calendario, feriados y políticas se crean la primera vez que la empresa entra, con lo del
 *   prototipo (horario de oficina de Lima, feriados nacionales de Perú, P1 en 24×7).
 *
 * No guarda nada en estáticos: una petición que se deshace no deja datos fantasma.
 */
class SlaCalendar
{
    public const MODES = ['BUSINESS' => 'Horario hábil', '24X7' => '24×7'];

    public const PRIORITIES = ['P1' => 'Crítica', 'P2' => 'Alta', 'P3' => 'Media', 'P4' => 'Baja'];

    /** Horario de oficina de Lima del prototipo: lunes a viernes 8:30–18:00, sábado 9:00–13:00. */
    public const DEFAULT_HOURS = [
        '1' => ['08:30', '18:00'], '2' => ['08:30', '18:00'], '3' => ['08:30', '18:00'],
        '4' => ['08:30', '18:00'], '5' => ['08:30', '18:00'], '6' => ['09:00', '13:00'],
    ];

    /** Prioridad => [respuesta, resolución] en minutos y modo, los del prototipo. */
    public const DEFAULT_POLICIES = [
        'P1' => [60, 240, '24X7'],
        'P2' => [120, 480, 'BUSINESS'],
        'P3' => [240, 1440, 'BUSINESS'],
        'P4' => [480, 4320, 'BUSINESS'],
    ];

    /** Feriados nacionales de Perú (referenciales: cada empresa los ajusta). */
    public const DEFAULT_HOLIDAYS = [
        '2026-01-01' => 'Año Nuevo', '2026-04-02' => 'Jueves Santo', '2026-04-03' => 'Viernes Santo',
        '2026-05-01' => 'Día del Trabajo', '2026-06-07' => 'Batalla de Arica y Día de la Bandera',
        '2026-06-29' => 'San Pedro y San Pablo', '2026-07-23' => 'Día de la Fuerza Aérea del Perú',
        '2026-07-28' => 'Fiestas Patrias', '2026-07-29' => 'Fiestas Patrias', '2026-08-06' => 'Batalla de Junín',
        '2026-08-30' => 'Santa Rosa de Lima', '2026-10-08' => 'Combate de Angamos', '2026-11-01' => 'Todos los Santos',
        '2026-12-08' => 'Inmaculada Concepción', '2026-12-09' => 'Batalla de Ayacucho', '2026-12-25' => 'Navidad',
        '2027-01-01' => 'Año Nuevo', '2027-03-25' => 'Jueves Santo', '2027-03-26' => 'Viernes Santo',
        '2027-05-01' => 'Día del Trabajo', '2027-06-07' => 'Batalla de Arica y Día de la Bandera',
        '2027-06-29' => 'San Pedro y San Pablo', '2027-07-23' => 'Día de la Fuerza Aérea del Perú',
        '2027-07-28' => 'Fiestas Patrias', '2027-07-29' => 'Fiestas Patrias', '2027-08-06' => 'Batalla de Junín',
        '2027-08-30' => 'Santa Rosa de Lima', '2027-10-08' => 'Combate de Angamos', '2027-11-01' => 'Todos los Santos',
        '2027-12-08' => 'Inmaculada Concepción', '2027-12-09' => 'Batalla de Ayacucho', '2027-12-25' => 'Navidad',
    ];

    /** Tope de días que se recorren al sumar horas hábiles (un calendario sin horario no cuelga). */
    private const MAX_DAYS = 800;

    /** @param array<string, array{0: string, 1: string}> $hours @param array<string, true> $holidays */
    private function __construct(
        public readonly object $calendar,
        public readonly array $hours,
        public readonly array $holidays,
        public readonly string $timezone,
        public readonly float $premiumFactor,
    ) {}

    public static function for(string $tenantId): self
    {
        $calendar = self::ensure($tenantId);

        return new self(
            $calendar,
            json_decode($calendar->hours, true) ?: [],
            array_fill_keys(
                DB::table('support_holidays')->where('calendar_id', $calendar->id)->whereNull('removed_at')->pluck('holiday_date')->all(),
                true,
            ),
            $calendar->timezone,
            (float) $calendar->premium_factor,
        );
    }

    /** El calendario de la empresa; la primera vez lo crea con sus feriados y sus políticas. */
    public static function ensure(string $tenantId): object
    {
        $calendar = DB::table('support_calendars')->where('tenant_id', $tenantId)->first();
        if ($calendar) {
            self::ensurePolicies($tenantId);

            return $calendar;
        }

        $id = (string) Str::uuid();
        $inserted = DB::table('support_calendars')->insertOrIgnore([
            'id' => $id, 'tenant_id' => $tenantId, 'hours' => json_encode(self::DEFAULT_HOURS),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted) {
            DB::table('support_holidays')->insert(collect(self::DEFAULT_HOLIDAYS)->map(fn (string $name, string $date) => [
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'calendar_id' => $id,
                'holiday_date' => $date, 'name' => $name, 'created_at' => now(),
            ])->values()->all());
        }
        self::ensurePolicies($tenantId);

        return DB::table('support_calendars')->where('tenant_id', $tenantId)->first();
    }

    private static function ensurePolicies(string $tenantId): void
    {
        if (DB::table('support_slas')->where('tenant_id', $tenantId)->count() >= count(self::PRIORITIES)) {
            return;
        }
        foreach (self::DEFAULT_POLICIES as $priority => [$response, $resolution, $mode]) {
            DB::table('support_slas')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'name' => self::PRIORITIES[$priority], 'priority' => $priority,
                'response_minutes' => $response, 'resolution_minutes' => $resolution, 'calendar_mode' => $mode, 'active' => true,
            ]);
        }
    }

    /** Políticas de la empresa, de P1 a P4. */
    public static function policies(string $tenantId)
    {
        self::ensure($tenantId);

        return DB::table('support_slas')->where('tenant_id', $tenantId)->orderBy('priority')
            ->get(['id', 'priority', 'name', 'response_minutes', 'resolution_minutes', 'calendar_mode'])
            ->keyBy('priority');
    }

    /** Suma minutos a un momento: corridos en 24×7, o solo dentro del horario hábil. */
    public function add(Carbon $from, int $minutes, string $mode): Carbon
    {
        if ($mode !== 'BUSINESS') {
            return $from->copy()->addMinutes($minutes);
        }

        $remaining = $minutes;
        $cursor = $from->copy()->setTimezone($this->timezone);
        for ($day = 0; $day < self::MAX_DAYS && $remaining > 0; $day++) {
            foreach ($this->windows($cursor) as [$start, $end]) {
                $begin = $cursor->greaterThan($start) ? $cursor->copy() : $start;
                if ($begin->greaterThanOrEqualTo($end)) {
                    continue;
                }
                $available = (int) $begin->diffInMinutes($end);
                if ($available >= $remaining) {
                    return $begin->addMinutes($remaining)->setTimezone('UTC');
                }
                $remaining -= $available;
            }
            $cursor = $cursor->copy()->addDay()->startOfDay();
        }

        return $cursor->setTimezone('UTC');
    }

    /** Minutos entre dos momentos, corridos o solo los hábiles. */
    public function between(Carbon $from, Carbon $to, string $mode): int
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }
        if ($mode !== 'BUSINESS') {
            return (int) $from->diffInMinutes($to);
        }

        $total = 0;
        $cursor = $from->copy()->setTimezone($this->timezone);
        $limit = $to->copy()->setTimezone($this->timezone);
        for ($day = 0; $day < self::MAX_DAYS && $cursor->lessThan($limit); $day++) {
            foreach ($this->windows($cursor) as [$start, $end]) {
                $begin = $cursor->greaterThan($start) ? $cursor : $start;
                $finish = $limit->lessThan($end) ? $limit : $end;
                if ($finish->greaterThan($begin)) {
                    $total += (int) $begin->diffInMinutes($finish);
                }
            }
            $cursor = $cursor->copy()->addDay()->startOfDay();
        }

        return $total;
    }

    /** @return array<int, array{0: Carbon, 1: Carbon}> ventanas hábiles del día de `$day` (en la zona de la empresa) */
    private function windows(Carbon $day): array
    {
        if (isset($this->holidays[$day->toDateString()])) {
            return [];
        }
        $window = $this->hours[(string) $day->dayOfWeek] ?? null;
        if (! $window) {
            return [];
        }

        [$from, $to] = $window;

        return [[$day->copy()->setTimeFromTimeString($from), $day->copy()->setTimeFromTimeString($to)]];
    }

    /** Factor de la empresa si el contrato es Premium; 1 si no. */
    public function factorFor(?string $slaLevel): float
    {
        return strtoupper((string) $slaLevel) === 'PREMIUM' ? $this->premiumFactor : 1.0;
    }
}
