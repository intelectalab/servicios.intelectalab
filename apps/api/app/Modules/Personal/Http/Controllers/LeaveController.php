<?php

namespace App\Modules\Personal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckPermission;
use App\Modules\Core\Models\Employee;
use App\Modules\Personal\Models\LeaveRequest;
use App\Modules\Personal\Support\Personal;
use App\Services\AccessScope;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\LocalTime;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Personal → Vacaciones y ausencias (P-HRM-03): Solicitada → Aprobada / Rechazada; aprobada se
 * puede anular. Una ausencia aprobada no se cruza con otra del mismo trabajador y bloquea su
 * agenda (no se le asignan OT ni citas); nadie aprueba su propia solicitud (RN-HRM-03).
 *
 * Con RRHH maestro, las ausencias de sus trabajadores se piden y aprueban en el SaaS de RRHH y
 * llegan ya decididas.
 */
class LeaveController extends Controller
{
    private const NAME = "(SELECT btrim(p.given_names || ' ' || p.family_name) FROM employees e JOIN persons p ON p.party_id = e.person_party_id WHERE e.id = hr_leave_requests.employee_id)";

    public function index(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        Personal::ensureLeaveTypes($tenantId);
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $query = $this->listing()
            ->when(isset(Personal::LEAVE_STATES[$status]), fn ($q) => $q->where('hr_leave_requests.status', $status))
            ->when($request->filled('leave_type_id'), fn ($q) => $q->where('hr_leave_requests.leave_type_id', $request->query('leave_type_id')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('hr_leave_requests.employee_id', $request->query('employee_id')))
            ->when($request->query('mine') === '1', fn ($q) => $q->where('hr_leave_requests.user_id', $request->user()->id))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->whereRaw(self::NAME.' ILIKE ?', [$like])->orWhere('hr_leave_requests.reason', 'ILIKE', $like));
            });

        ColumnFilters::apply($query, $request, [
            'employee' => ColumnFilters::text(self::NAME),
            'type' => ColumnFilters::text('hr_leave_types.name'),
            'from' => ColumnFilters::date('hr_leave_requests.date_from'),
            'to' => ColumnFilters::date('hr_leave_requests.date_to'),
            'days' => ColumnFilters::number('hr_leave_requests.days'),
        ]);
        ListSort::apply($query, $request, [
            'employee' => self::NAME,
            'type' => 'hr_leave_types.name',
            'from' => 'hr_leave_requests.date_from',
            'to' => 'hr_leave_requests.date_to',
            'days' => 'hr_leave_requests.days',
            'status' => ListSort::labels('hr_leave_requests.status', Personal::LEAVE_STATES),
            'requested' => 'hr_leave_requests.created_at',
        ], fn ($q) => $q->orderByRaw("CASE hr_leave_requests.status WHEN 'REQUESTED' THEN 0 ELSE 1 END")->orderByDesc('hr_leave_requests.date_from'), 'hr_leave_requests.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn ($row) => $this->row($row, $request));

        $today = LocalTime::today()->toDateString();
        $visible = fn () => $this->listing();

        return response()->json([
            ...$page->toArray(),
            'kpis' => [
                'pending' => $visible()->where('hr_leave_requests.status', 'REQUESTED')->count(),
                'absent_today' => $visible()->where('hr_leave_requests.status', 'APPROVED')
                    ->where('hr_leave_requests.date_from', '<=', $today)->where('hr_leave_requests.date_to', '>=', $today)->count(),
                'upcoming' => $visible()->where('hr_leave_requests.status', 'APPROVED')
                    ->where('hr_leave_requests.date_from', '>', $today)->where('hr_leave_requests.date_from', '<=', LocalTime::today()->addDays(30)->toDateString())->count(),
                'days_this_year' => (int) $visible()->where('hr_leave_requests.status', 'APPROVED')
                    ->whereYear('hr_leave_requests.date_from', LocalTime::today()->year)->sum('hr_leave_requests.days'),
            ],
        ]);
    }

    /** Calendario del equipo: ausencias aprobadas y solicitadas que tocan el rango. */
    public function calendar(Request $request)
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 62) {
            throw ValidationException::withMessages(['to' => ['El calendario muestra hasta dos meses.']]);
        }
        $rows = $this->listing()->whereIn('hr_leave_requests.status', ['APPROVED', 'REQUESTED'])
            ->where('hr_leave_requests.date_from', '<=', $data['to'])->where('hr_leave_requests.date_to', '>=', $data['from'])
            ->orderBy('hr_leave_requests.date_from')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($row) => $this->row($row, $request))->values()]);
    }

    /** Tipos, y a quién puede la persona registrarle una ausencia (ella misma si su alcance es propio). */
    public function options(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        Personal::ensureLeaveTypes($tenantId);
        $connection = Personal::activeConnection($tenantId);

        return response()->json([
            'types' => DB::table('hr_leave_types')->where('tenant_id', $tenantId)->orderBy('name')->get(),
            'employees' => $this->requestable($request)->where('employees.status', 'ACTIVE')
                ->join('persons', 'persons.party_id', '=', 'employees.person_party_id')
                ->orderByRaw("btrim(persons.given_names || ' ' || persons.family_name)")->limit(500)
                ->addSelect(DB::raw("btrim(persons.given_names || ' ' || persons.family_name) as full_name"))->get()
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name, 'mine' => $e->user_id === $request->user()->id,
                    'mastered' => $connection && $e->source === 'HR_SAAS']),
            'mode' => $connection ? 'HR_SAAS_MASTER' : 'ERP_MASTER',
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        Personal::ensureLeaveTypes($tenantId);
        $data = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'leave_type_id' => ['required', 'uuid'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'days' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ], [
            'employee_id.required' => 'Elige el trabajador.',
            'leave_type_id.required' => 'Elige el tipo de ausencia.',
            'date_from.required' => 'Indica desde cuándo.',
            'date_to.required' => 'Indica hasta cuándo.',
            'date_to.after_or_equal' => 'La fecha final no puede ser antes de la inicial.',
        ]);
        $employee = $this->requestable($request)->where('employees.id', $data['employee_id'])->first();
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => ['Solo puedes registrar ausencias de las personas que alcanzas.']]);
        }
        if ($employee->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['employee_id' => ['El trabajador no está activo.']]);
        }
        $this->assertLocal($tenantId, $employee);
        $type = DB::table('hr_leave_types')->where('tenant_id', $tenantId)->where('id', $data['leave_type_id'])->where('active', true)->first();
        if (! $type) {
            throw ValidationException::withMessages(['leave_type_id' => ['El tipo de ausencia no existe o está inactivo.']]);
        }
        $from = Carbon::parse($data['date_from'])->toDateString();
        $to = Carbon::parse($data['date_to'])->toDateString();
        $span = Personal::days($from, $to);
        if ($span > 366) {
            throw ValidationException::withMessages(['date_to' => ['Una ausencia no puede pasar de un año.']]);
        }
        $days = (int) ($data['days'] ?? $span);
        if ($days > $span) {
            throw ValidationException::withMessages(['days' => ["Son {$span} día(s) de calendario como máximo."]]);
        }
        $overlap = DB::table('hr_leave_requests')->where('tenant_id', $tenantId)->where('employee_id', $employee->id)
            ->whereIn('status', ['REQUESTED', 'APPROVED'])->where('date_from', '<=', $to)->where('date_to', '>=', $from)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['date_from' => ['Ya hay una solicitud o ausencia de esta persona en esas fechas.']]);
        }

        $auto = ! $type->requires_approval;
        $id = DB::transaction(function () use ($request, $tenantId, $employee, $type, $from, $to, $days, $data, $auto) {
            $id = (string) Str::uuid();
            DB::table('hr_leave_requests')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'user_id' => $employee->user_id, 'branch_id' => $employee->branch_id, 'date_from' => $from, 'date_to' => $to, 'days' => $days,
                'reason' => trim((string) ($data['reason'] ?? '')) ?: null, 'status' => $auto ? 'APPROVED' : 'REQUESTED',
                'decided_at' => $auto ? now() : null, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'leave_request.create', entityType: 'LeaveRequest', entityId: $id,
                afterData: ['type' => $type->name, 'from' => $from, 'to' => $to, 'days' => $days, 'status' => $auto ? 'APPROVED' : 'REQUESTED']);
            $leave = LeaveRequest::find($id);
            if (! $auto) {
                $name = $this->employeeName($employee->person_party_id);
                Notifier::send('leave.requested', 'Ausencia por aprobar', "{$name}: {$type->name} del ".$this->date($from).' al '.$this->date($to)." ({$days} día(s)).",
                    '/hr/leaves', array_diff(Notifier::withPermission('leaves.approve', $leave), array_filter([$employee->user_id])));
            }

            return $id;
        });

        return response()->json($this->row($this->find($id), $request), 201);
    }

    public function approve(Request $request, string $leave)
    {
        return $this->decide($request, $leave, 'APPROVED');
    }

    public function reject(Request $request, string $leave)
    {
        $request->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => 'Escribe el motivo del rechazo.']);

        return $this->decide($request, $leave, 'REJECTED');
    }

    /** Anular: quien gestiona ausencias, o quien la pidió mientras siga solicitada. */
    public function cancel(Request $request, string $leave)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($leave);
        $own = $row->created_by === $request->user()->id || $row->user_id === $request->user()->id;
        if (! CheckPermission::granted($request, 'leaves.manage') && ! ($own && $row->status === 'REQUESTED')) {
            return response()->json(['message' => 'Solo quien gestiona ausencias puede anular una aprobada.', 'code' => 'FORBIDDEN'], 403);
        }
        if (! in_array($row->status, ['REQUESTED', 'APPROVED'], true)) {
            throw ValidationException::withMessages(['status' => ['Esta solicitud ya está cerrada.']]);
        }
        $this->assertLocal($tenantId, $row, true);
        $note = trim((string) $request->input('note', '')) ?: null;
        DB::transaction(function () use ($request, $tenantId, $row, $note) {
            DB::table('hr_leave_requests')->where('id', $row->id)->update(['status' => 'CANCELLED', 'decision_note' => $note ?? $row->decision_note, 'updated_at' => now()]);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'leave_request.cancel', entityType: 'LeaveRequest', entityId: $row->id,
                beforeData: ['status' => $row->status], afterData: ['status' => 'CANCELLED', 'note' => $note]);
        });

        return response()->json($this->row($this->find($row->id), $request));
    }

    // ------------------------------------------------------------------ Tipos

    public function types(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        Personal::ensureLeaveTypes($tenantId);

        return response()->json(['data' => DB::table('hr_leave_types')->where('tenant_id', $tenantId)->orderBy('name')->get()]);
    }

    public function storeType(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $data = $this->typeData($request, $tenantId, null);
        $id = DB::table('hr_leave_types')->insertGetId([...$data, 'tenant_id' => $tenantId, 'created_at' => now(), 'updated_at' => now()], 'id');
        AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'leave_type.create', entityType: 'LeaveType', entityId: $id, afterData: $data);

        return response()->json(DB::table('hr_leave_types')->where('id', $id)->first(), 201);
    }

    public function updateType(Request $request, string $type)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = DB::table('hr_leave_types')->where('tenant_id', $tenantId)->where('id', $type)->first() ?? abort(404, 'Tipo no encontrado.');
        $data = $this->typeData($request, $tenantId, $row->id);
        DB::table('hr_leave_types')->where('id', $row->id)->update([...$data, 'updated_at' => now()]);
        AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'leave_type.update', entityType: 'LeaveType', entityId: $row->id,
            beforeData: array_intersect_key((array) $row, $data), afterData: $data);

        return response()->json(DB::table('hr_leave_types')->where('id', $row->id)->first());
    }

    // ------------------------------------------------------------------ Apoyo

    private function decide(Request $request, string $leave, string $to)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($leave);
        if ($row->status !== 'REQUESTED') {
            throw ValidationException::withMessages(['status' => ['Solo se decide una solicitud pendiente.']]);
        }
        if ($row->user_id === $request->user()->id) {
            throw ValidationException::withMessages(['status' => ['No puedes aprobar ni rechazar tu propia solicitud.']]);
        }
        $this->assertLocal($tenantId, $row, true);
        $note = trim((string) $request->input('note', '')) ?: null;

        DB::transaction(function () use ($request, $tenantId, $row, $to, $note) {
            DB::table('hr_leave_requests')->where('id', $row->id)->update([
                'status' => $to, 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now(),
            ]);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: $to === 'APPROVED' ? 'leave_request.approve' : 'leave_request.reject',
                entityType: 'LeaveRequest', entityId: $row->id, beforeData: ['status' => 'REQUESTED'], afterData: ['status' => $to, 'note' => $note]);
            if ($row->user_id) {
                Notifier::send('leave.decided', $to === 'APPROVED' ? 'Ausencia aprobada' : 'Ausencia rechazada',
                    "{$row->type_name} del ".$this->date($row->date_from).' al '.$this->date($row->date_to).($note ? ": {$note}" : '.'),
                    '/hr/leaves', [$row->user_id]);
            }
        });

        $result = $this->row($this->find($row->id), $request);
        // Lo que ya tenía agendado en esas fechas: hay que reasignarlo.
        $result['conflicts'] = $to === 'APPROVED' ? $this->conflicts($tenantId, $row) : [];
        if ($result['conflicts']) {
            Notifier::send('leave.conflicts', 'Reasignar trabajo por una ausencia',
                "{$row->employee_name} estará ausente del ".$this->date($row->date_from).' al '.$this->date($row->date_to).' y tiene '.count($result['conflicts']).' trabajo(s) agendado(s): '
                .collect($result['conflicts'])->pluck('label')->take(5)->implode(', ').'.',
                '/agenda', Notifier::withPermission('work_orders.manage'));
        }

        return response()->json($result);
    }

    /** OT y citas de la persona dentro de la ausencia. */
    private function conflicts(string $tenantId, object $row): array
    {
        if (! $row->user_id) {
            return [];
        }
        $from = Carbon::parse($row->date_from, 'America/Lima')->startOfDay();
        $to = Carbon::parse($row->date_to, 'America/Lima')->endOfDay();
        $orders = DB::table('work_orders')->where('tenant_id', $tenantId)->where('assigned_user_id', $row->user_id)
            ->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->whereNotNull('scheduled_start')
            ->where('scheduled_start', '<=', $to)->where(fn ($q) => $q->whereNull('scheduled_end')->orWhere('scheduled_end', '>=', $from))
            ->get(['id', 'code', 'title'])->map(fn ($o) => ['type' => 'work_order', 'id' => $o->id, 'label' => "{$o->code} · {$o->title}"]);
        $events = DB::table('agenda_events')->where('tenant_id', $tenantId)->where('assigned_user_id', $row->user_id)
            ->whereNotIn('status', ['CANCELLED', 'DONE', 'COMPLETED'])->where('starts_at', '<=', $to)->where('ends_at', '>=', $from)
            ->get(['id', 'title', 'starts_at'])->map(fn ($e) => ['type' => 'agenda', 'id' => $e->id, 'label' => $e->title]);

        return $orders->concat($events)->values()->all();
    }

    private function assertLocal(string $tenantId, object $row, bool $isLeave = false): void
    {
        if (! Personal::activeConnection($tenantId)) {
            return;
        }
        $mastered = $isLeave
            ? ($row->source === 'HR_SAAS' || DB::table('employees')->where('id', $row->employee_id)->value('source') === 'HR_SAAS')
            : $row->source === 'HR_SAAS';
        if ($mastered) {
            Personal::mastered('Las ausencias de este trabajador se piden y aprueban en el SaaS de RRHH:');
        }
    }

    /** Trabajadores a los que la persona puede registrarles una ausencia (según su alcance en ausencias). */
    private function requestable(Request $request)
    {
        return tap(Employee::query()->select('employees.*'), fn ($q) => AccessScope::apply($q, 'leaves'));
    }

    private function listing()
    {
        return LeaveRequest::query()
            ->join('hr_leave_types', 'hr_leave_types.id', '=', 'hr_leave_requests.leave_type_id')
            ->leftJoin('users as decider', 'decider.id', '=', 'hr_leave_requests.decided_by')
            ->select('hr_leave_requests.*', 'hr_leave_types.name as type_name', 'hr_leave_types.code as type_code', 'hr_leave_types.paid',
                'decider.name as decided_by_name', DB::raw(self::NAME.' as employee_name'))
            ->tap(fn ($q) => AccessScope::apply($q, 'leaves'));
    }

    private function find(string $id): object
    {
        $row = $this->listing()->where('hr_leave_requests.id', $id)->first();
        if (! $row) {
            abort(response()->json(['message' => 'Solicitud no encontrada.', 'code' => 'NOT_FOUND'], 404));
        }

        return (object) $row->getAttributes();
    }

    private function row($row, Request $request): array
    {
        $row = is_object($row) && method_exists($row, 'getAttributes') ? (object) $row->getAttributes() : (object) $row;
        $mine = $row->user_id === $request->user()->id;

        return [
            'id' => $row->id,
            'employee' => ['id' => $row->employee_id, 'name' => $row->employee_name],
            'type' => ['id' => $row->leave_type_id, 'name' => $row->type_name, 'code' => $row->type_code, 'paid' => (bool) $row->paid],
            'date_from' => $row->date_from,
            'date_to' => $row->date_to,
            'days' => (int) $row->days,
            'reason' => $row->reason,
            'status' => $row->status,
            'status_label' => Personal::LEAVE_STATES[$row->status] ?? $row->status,
            'decided_by' => $row->decided_by ? ['id' => $row->decided_by, 'name' => $row->decided_by_name] : null,
            'decided_at' => $row->decided_at,
            'decision_note' => $row->decision_note,
            'source' => $row->source,
            'mine' => $mine,
            'created_at' => $row->created_at,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    private function typeData(Request $request, string $tenantId, ?string $id): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('hr_leave_types', 'code')->where('tenant_id', $tenantId)->ignore($id)],
            'name' => ['required', 'string', 'max:120'],
            'paid' => ['sometimes', 'boolean'],
            'requires_approval' => ['sometimes', 'boolean'],
            'days_per_year' => ['nullable', 'integer', 'min:1', 'max:366'],
            'active' => ['sometimes', 'boolean'],
        ], ['code.unique' => 'Ya hay un tipo con ese código.', 'code.required' => 'Escribe el código.', 'name.required' => 'Escribe el nombre.']);

        return [
            'code' => strtoupper(trim($data['code'])), 'name' => trim($data['name']), 'paid' => (bool) ($data['paid'] ?? true),
            'requires_approval' => (bool) ($data['requires_approval'] ?? true), 'days_per_year' => $data['days_per_year'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
        ];
    }

    private function employeeName(string $partyId): string
    {
        return (string) DB::table('persons')->where('party_id', $partyId)->selectRaw("btrim(given_names || ' ' || family_name) as n")->value('n');
    }

    private function date(string $date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }
}
