<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Support\SlaCalendar;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Calendario laboral y SLA (Administración): horario de atención por día, modo de cálculo por
 * prioridad (horario hábil o 24×7), tiempos de respuesta y resolución, factor del contrato
 * Premium y feriados. Cambiarlo no mueve los vencimientos de los tickets ya clasificados.
 *
 * Toda la configuración es un solo registro por empresa con su `lock_version`: quien guarda con
 * una versión vieja recibe 409 y no pisa lo que otra persona cambió.
 */
class SlaSettingsController extends Controller
{
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function show(Request $request)
    {
        return response()->json($this->payload($request->attributes->get('tenant_id')));
    }

    /** Horario de atención y modo de cálculo por prioridad (el formulario del prototipo). */
    public function updateCalendar(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'hours' => ['present', 'array'],
            'hours.*' => ['nullable', 'array'],
            'hours.*.from' => ['nullable', 'regex:'.self::TIME],
            'hours.*.to' => ['nullable', 'regex:'.self::TIME],
            'modes' => ['required', 'array'],
            'modes.*' => ['required', 'in:'.implode(',', array_keys(SlaCalendar::MODES))],
            'lock_version' => ['nullable', 'integer'],
        ], ['hours.*.from.regex' => 'Usa horas como 08:30.', 'hours.*.to.regex' => 'Usa horas como 18:00.']);

        $hours = [];
        foreach ($data['hours'] as $day => $window) {
            if (! in_array((string) $day, ['0', '1', '2', '3', '4', '5', '6'], true)) {
                throw ValidationException::withMessages(['hours' => ['Día de la semana no válido.']]);
            }
            if (! $window) {
                continue;
            }
            if (empty($window['from']) || empty($window['to'])) {
                throw ValidationException::withMessages(["hours.{$day}" => ['Indica desde y hasta qué hora se atiende el '.$this->dayName((int) $day).'.']]);
            }
            if ($window['from'] >= $window['to']) {
                throw ValidationException::withMessages(["hours.{$day}" => ['La hora de inicio debe ser menor que la de fin ('.$this->dayName((int) $day).').']]);
            }
            $hours[(string) $day] = [$window['from'], $window['to']];
        }
        if (! $hours && collect($data['modes'])->contains('BUSINESS')) {
            throw ValidationException::withMessages(['hours' => ['Sin ningún día de atención, el horario hábil nunca avanza: marca al menos un día o usa 24×7.']]);
        }
        foreach (array_keys($data['modes']) as $priority) {
            if (! isset(SlaCalendar::PRIORITIES[$priority])) {
                throw ValidationException::withMessages(['modes' => ['Prioridad no válida.']]);
            }
        }

        $calendar = $this->lockedCalendar($tenantId, $data['lock_version'] ?? null);

        DB::transaction(function () use ($request, $tenantId, $calendar, $hours, $data) {
            $policies = SlaCalendar::policies($tenantId);
            $before = ['hours' => json_decode($calendar->hours, true), 'modes' => $policies->map->calendar_mode->all()];

            DB::table('support_calendars')->where('id', $calendar->id)->update([
                'hours' => json_encode($hours, JSON_FORCE_OBJECT), 'updated_by' => $request->user()->id, 'updated_at' => now(),
                'lock_version' => DB::raw('lock_version + 1'),
            ]);
            foreach ($data['modes'] as $priority => $mode) {
                DB::table('support_slas')->where('tenant_id', $tenantId)->where('priority', $priority)->update(['calendar_mode' => $mode, 'updated_at' => now()]);
            }

            $this->audit($request, $calendar->id, 'sla.calendar.update', $before, ['hours' => $hours, 'modes' => $data['modes']], 'Horario de atención guardado');
        });

        return response()->json($this->payload($tenantId));
    }

    /** Tiempos de respuesta y resolución por prioridad y factor del contrato Premium. */
    public function updatePolicies(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*.priority' => ['required', 'in:'.implode(',', array_keys(SlaCalendar::PRIORITIES))],
            'policies.*.response_hours' => ['required', 'numeric', 'min:0.25', 'max:720'],
            'policies.*.resolution_hours' => ['required', 'numeric', 'min:0.25', 'max:720'],
            'premium_percent' => ['required', 'integer', 'min:10', 'max:100'],
            'lock_version' => ['nullable', 'integer'],
        ], [
            'policies.*.response_hours.min' => 'El tiempo mínimo es 0,25 h (15 minutos).',
            'policies.*.resolution_hours.min' => 'El tiempo mínimo es 0,25 h (15 minutos).',
            'policies.*.response_hours.max' => 'El tiempo máximo es 720 h (30 días).',
            'policies.*.resolution_hours.max' => 'El tiempo máximo es 720 h (30 días).',
            'premium_percent.min' => 'El contrato Premium debe tener entre 10 % y 100 % del tiempo.',
            'premium_percent.max' => 'El contrato Premium debe tener entre 10 % y 100 % del tiempo.',
        ]);

        $rows = [];
        foreach ($data['policies'] as $index => $policy) {
            $response = (int) round($policy['response_hours'] * 60);
            $resolution = (int) round($policy['resolution_hours'] * 60);
            if ($resolution < $response) {
                throw ValidationException::withMessages(["policies.{$index}.resolution_hours" => ["La resolución de {$policy['priority']} no puede ser menor que la respuesta."]]);
            }
            $rows[$policy['priority']] = [$response, $resolution];
        }

        $calendar = $this->lockedCalendar($tenantId, $data['lock_version'] ?? null);

        DB::transaction(function () use ($request, $tenantId, $calendar, $rows, $data) {
            $policies = SlaCalendar::policies($tenantId);
            $before = [
                'premium_factor' => (float) $calendar->premium_factor,
                'policies' => $policies->map(fn ($policy) => [(int) $policy->response_minutes, (int) $policy->resolution_minutes])->all(),
            ];

            foreach ($rows as $priority => [$response, $resolution]) {
                DB::table('support_slas')->where('tenant_id', $tenantId)->where('priority', $priority)
                    ->update(['response_minutes' => $response, 'resolution_minutes' => $resolution, 'updated_at' => now()]);
            }
            $factor = round($data['premium_percent'] / 100, 4);
            DB::table('support_calendars')->where('id', $calendar->id)->update([
                'premium_factor' => $factor, 'updated_by' => $request->user()->id, 'updated_at' => now(),
                'lock_version' => DB::raw('lock_version + 1'),
            ]);

            $this->audit($request, $calendar->id, 'sla.policies.update', $before, ['premium_factor' => $factor, 'policies' => $rows], 'Tiempos de SLA guardados');
        });

        return response()->json($this->payload($tenantId));
    }

    public function addHoliday(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:150'],
        ], ['date.required' => 'Indica la fecha.', 'name.required' => 'Indica el nombre del feriado.']);
        if (trim($data['name']) === '') {
            throw ValidationException::withMessages(['name' => ['Indica el nombre del feriado.']]);
        }

        $calendar = SlaCalendar::ensure($tenantId);
        if (DB::table('support_holidays')->where('calendar_id', $calendar->id)->whereNull('removed_at')->where('holiday_date', $data['date'])->exists()) {
            throw ValidationException::withMessages(['date' => ['Ese día ya es feriado.']]);
        }

        DB::transaction(function () use ($request, $tenantId, $calendar, $data) {
            DB::table('support_holidays')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'calendar_id' => $calendar->id,
                'holiday_date' => $data['date'], 'name' => trim($data['name']), 'created_by' => $request->user()->id, 'created_at' => now(),
            ]);
            DB::table('support_calendars')->where('id', $calendar->id)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            $this->audit($request, $calendar->id, 'sla.holiday.add', null, ['date' => $data['date'], 'name' => trim($data['name'])],
                Carbon::parse($data['date'])->format('d/m/Y').' · '.trim($data['name']));
        });

        return response()->json($this->payload($tenantId), 201);
    }

    /** Quitar un feriado: queda retirado, con su historia (no se borra). */
    public function removeHoliday(string $holiday, Request $request)
    {
        abort_unless(Str::isUuid($holiday), 404);
        $tenantId = $request->attributes->get('tenant_id');
        $row = DB::table('support_holidays')->where('tenant_id', $tenantId)->where('id', $holiday)->whereNull('removed_at')->first();
        abort_unless($row, 404);

        DB::transaction(function () use ($request, $row) {
            DB::table('support_holidays')->where('id', $row->id)->update(['removed_at' => now(), 'removed_by' => $request->user()->id]);
            DB::table('support_calendars')->where('id', $row->calendar_id)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            $this->audit($request, $row->calendar_id, 'sla.holiday.remove', ['date' => $row->holiday_date, 'name' => $row->name], null,
                Carbon::parse($row->holiday_date)->format('d/m/Y').' · '.$row->name);
        });

        return response()->json($this->payload($tenantId));
    }

    private function payload(string $tenantId): array
    {
        $clock = SlaCalendar::for($tenantId);
        $calendar = $clock->calendar;
        $now = now();
        $policies = SlaCalendar::policies($tenantId);

        return [
            'calendar' => [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'timezone' => $calendar->timezone,
                'hours' => collect(range(0, 6))->mapWithKeys(fn (int $day) => [
                    (string) $day => isset($clock->hours[(string) $day]) ? ['from' => $clock->hours[(string) $day][0], 'to' => $clock->hours[(string) $day][1]] : null,
                ])->all(),
                'premium_percent' => (int) round($calendar->premium_factor * 100),
                'lock_version' => (int) $calendar->lock_version,
                'updated_at' => $calendar->updated_at ? Carbon::parse($calendar->updated_at)->toIso8601String() : null,
                'updated_by' => $calendar->updated_by ? DB::table('users')->where('id', $calendar->updated_by)->value('name') : null,
            ],
            'policies' => $policies->map(fn ($policy) => [
                'priority' => $policy->priority,
                'label' => $policy->name,
                'response_minutes' => (int) $policy->response_minutes,
                'resolution_minutes' => (int) $policy->resolution_minutes,
                'mode' => $policy->calendar_mode,
            ])->values(),
            // "Ticket creado ahora": cuándo vencería su resolución en cada prioridad, normal y con contrato Premium.
            'simulation' => [
                'from' => $now->toIso8601String(),
                'rows' => $policies->map(fn ($policy) => [
                    'priority' => $policy->priority,
                    'due' => $clock->add($now, (int) $policy->resolution_minutes, $policy->calendar_mode)->toIso8601String(),
                    'premium_due' => $clock->add($now, max(1, (int) round($policy->resolution_minutes * $clock->premiumFactor)), $policy->calendar_mode)->toIso8601String(),
                ])->values(),
            ],
            'holidays' => DB::table('support_holidays')->where('calendar_id', $calendar->id)->whereNull('removed_at')->orderBy('holiday_date')
                ->get(['id', 'holiday_date', 'name'])
                ->map(fn ($holiday) => ['id' => $holiday->id, 'date' => $holiday->holiday_date, 'name' => $holiday->name])->values(),
            'today' => $now->copy()->setTimezone($calendar->timezone)->toDateString(),
            'history' => AuditHistory::for('SlaSettings', $calendar->id),
        ];
    }

    /** El calendario de la empresa, comprobando que nadie lo cambió desde que la persona lo abrió. */
    private function lockedCalendar(string $tenantId, ?int $expected): object
    {
        $calendar = SlaCalendar::ensure($tenantId);
        if ($expected !== null && (int) $calendar->lock_version !== $expected) {
            abort(response()->json([
                'message' => 'Otra persona modificó el calendario mientras lo tenías abierto. Actualiza la página para ver la versión vigente y vuelve a intentarlo.',
                'code' => 'CONFLICT',
            ], 409));
        }

        return $calendar;
    }

    private function dayName(int $day): string
    {
        return ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'][$day];
    }

    private function audit(Request $request, string $calendarId, string $action, ?array $before, ?array $after, string $note): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'SlaSettings',
            entityId: $calendarId,
            beforeData: $before,
            afterData: $after,
            metadata: ['note' => $note],
        );
    }
}
