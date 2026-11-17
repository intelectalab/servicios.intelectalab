<?php

namespace App\Modules\Personal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Employee;
use App\Modules\Personal\HrSaas\HrSaasSync;
use App\Modules\Personal\Support\Personal;
use App\Services\AccessScope;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Personal → Trabajadores y puestos (P-HRM-01, kit v1.5 D-44): cada trabajador es una persona
 * de Clientes y actores con rol Empleado; su usuario del sistema es opcional. Lleva puesto, sede,
 * jornada, tipo de contrato y costo por hora; nunca sueldo, AFP ni boletas.
 *
 * Con la conexión con el SaaS de RRHH activa, lo que llega de RRHH es de solo lectura: aquí solo
 * se cambia el usuario vinculado y la sede operativa (RN-HRM-06, 422 HR_MASTERED).
 */
class EmployeeController extends Controller
{
    private const NAME = "btrim(persons.given_names || ' ' || persons.family_name)";

    public function index(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $today = LocalTime::today()->toDateString();

        $query = $this->listing()
            ->when(isset(Personal::EMPLOYEE_STATES[$status]), fn ($q) => $q->where('employees.status', $status))
            ->when($status === 'ON_LEAVE', fn ($q) => $q->whereExists($this->leaveToday($today)))
            ->when($request->filled('position_id'), fn ($q) => $q->where('employees.position_id', $request->query('position_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('employees.branch_id', $request->query('branch_id')))
            ->when(in_array($request->query('source'), ['LOCAL', 'HR_SAAS'], true), fn ($q) => $q->where('employees.source', $request->query('source')))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->whereRaw(self::NAME.' ILIKE ?', [$like])
                    ->orWhere('employees.employee_code', 'ILIKE', $like)
                    ->orWhere('persons.document_number', 'ILIKE', $like)
                    ->orWhere('employees.payroll_external_id', 'ILIKE', $like));
            });

        ColumnFilters::apply($query, $request, [
            'name' => ColumnFilters::text(self::NAME),
            'code' => ColumnFilters::text('employees.employee_code'),
            'document' => ColumnFilters::text('persons.document_number'),
            'position' => ColumnFilters::text('coalesce(hr_positions.name, employees.position)'),
            'branch' => ColumnFilters::text('branches.name'),
            'hourly_cost' => ColumnFilters::number('employees.hourly_cost'),
            'weekly_hours' => ColumnFilters::number('employees.weekly_hours'),
            'hire_date' => ColumnFilters::date('employees.hire_date'),
        ]);

        ListSort::apply($query, $request, [
            'name' => self::NAME,
            'code' => 'employees.employee_code',
            'document' => 'persons.document_number',
            'position' => 'coalesce(hr_positions.name, employees.position)',
            'branch' => 'branches.name',
            'contract' => ListSort::labels('employees.contract_type', Personal::CONTRACT_TYPES),
            'weekly_hours' => 'employees.weekly_hours',
            'hourly_cost' => 'employees.hourly_cost',
            'hire_date' => 'employees.hire_date',
            'status' => ListSort::labels('employees.status', Personal::EMPLOYEE_STATES),
            'user' => 'users.name',
        ], fn ($q) => $q->orderByRaw(self::NAME), 'employees.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $onLeave = $this->onLeave($tenantId, $page->getCollection()->pluck('id')->all(), $today);
        $page->getCollection()->transform(fn ($row) => $this->row($row, $onLeave));

        return response()->json([
            ...$page->toArray(),
            'kpis' => $this->kpis($tenantId, $today),
            'mode' => Personal::activeConnection($tenantId) ? 'HR_SAAS_MASTER' : 'ERP_MASTER',
        ]);
    }

    /** Lo que el formulario necesita: puestos, sedes, usuarios sin trabajador y personas sin ficha. */
    public function options(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        return response()->json([
            'positions' => DB::table('hr_positions')->where('tenant_id', $tenantId)->orderBy('name')
                ->get(['id', 'code', 'name', 'default_hourly_cost', 'active', 'source']),
            'branches' => DB::table('branches')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'code', 'name']),
            'users' => DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')
                ->where('tenant_users.tenant_id', $tenantId)->where('tenant_users.status', 'ACTIVE')
                ->leftJoin('employees', fn ($join) => $join->on('employees.user_id', '=', 'users.id')->where('employees.tenant_id', $tenantId))
                ->orderBy('users.name')->get(['users.id', 'users.name', 'users.email', 'employees.id as employee_id']),
            // Personas de Clientes y actores que aún no son trabajadores (para no duplicar identidades).
            'people' => DB::table('parties')->join('persons', 'persons.party_id', '=', 'parties.id')
                ->where('parties.tenant_id', $tenantId)->where('parties.party_type', 'PERSON')->where('parties.status', 'ACTIVE')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('employees')->whereColumn('employees.person_party_id', 'parties.id'))
                ->orderByRaw(self::NAME)->limit(500)
                ->get(['parties.id', DB::raw(self::NAME.' as name'), 'persons.document_type', 'persons.document_number']),
            'contract_types' => collect(Personal::CONTRACT_TYPES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'mode' => Personal::activeConnection($tenantId) ? 'HR_SAAS_MASTER' : 'ERP_MASTER',
        ]);
    }

    public function show(Request $request, string $employee)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($employee);
        $today = LocalTime::today()->toDateString();
        $detail = $this->row($row, $this->onLeave($tenantId, [$row->id], $today));

        $detail['leaves'] = DB::table('hr_leave_requests as r')->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->where('r.employee_id', $row->id)->orderByDesc('r.date_from')->limit(20)
            ->get(['r.id', 'r.date_from', 'r.date_to', 'r.days', 'r.status', 'r.source', 't.name as type'])
            ->map(fn ($leave) => [...(array) $leave, 'status_label' => Personal::LEAVE_STATES[$leave->status] ?? $leave->status]);
        // Horas aprobadas del mes y del anterior: lo que irá a la planilla.
        $detail['hours'] = $row->user_id ? DB::table('time_entries')->where('tenant_id', $tenantId)->where('user_id', $row->user_id)
            ->where('status', 'APPROVED')->where('started_at', '>=', LocalTime::today()->startOfMonth()->subMonth())
            ->selectRaw("to_char(started_at AT TIME ZONE 'America/Lima', 'YYYY-MM') as period, round(sum(hours)::numeric, 2) as hours, round(sum(hours * hourly_cost)::numeric, 2) as cost")
            ->groupBy('period')->orderByDesc('period')->get() : [];
        $detail['history'] = AuditHistory::for('Employee', $row->id);

        return response()->json($detail);
    }

    public function store(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        if (Personal::activeConnection($tenantId)) {
            Personal::mastered('Con la conexión con el SaaS de RRHH activa, el alta de un trabajador');
        }
        $data = $this->validated($request, true);

        $id = DB::transaction(function () use ($request, $tenantId, $data) {
            $partyId = $data['party_id'] ?? null;
            if ($partyId) {
                $party = DB::table('parties')->where('tenant_id', $tenantId)->where('id', $partyId)->where('party_type', 'PERSON')->first();
                if (! $party) {
                    throw ValidationException::withMessages(['party_id' => ['Elige una persona de Clientes y actores.']]);
                }
            } else {
                // Si el documento ya existe, se usa esa persona: no se duplica la identidad.
                $partyId = DB::table('persons')->join('parties', 'parties.id', '=', 'persons.party_id')
                    ->where('parties.tenant_id', $tenantId)->where('persons.document_type', $data['document_type'])
                    ->where('persons.document_number', $data['document_number'])->value('persons.party_id');
                if (! $partyId) {
                    $partyId = (string) Str::uuid();
                    DB::table('parties')->insert(['id' => $partyId, 'tenant_id' => $tenantId, 'party_type' => 'PERSON', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('persons')->insert([
                        'party_id' => $partyId, 'given_names' => $data['given_names'], 'family_name' => $data['family_name'],
                        'document_type' => $data['document_type'], 'document_number' => $data['document_number'], 'metadata' => '{}',
                    ]);
                    foreach (['email' => 'EMAIL', 'phone' => 'PHONE'] as $field => $type) {
                        if (! empty($data[$field])) {
                            DB::table('contacts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'party_id' => $partyId,
                                'contact_type' => $type, 'value' => $data[$field], 'is_primary' => true, 'created_at' => now()]);
                        }
                    }
                }
            }
            if (DB::table('employees')->where('tenant_id', $tenantId)->where('person_party_id', $partyId)->exists()) {
                throw ValidationException::withMessages(['party_id' => ['Esa persona ya es trabajador: búscala en la lista.']]);
            }

            $id = (string) Str::uuid();
            DB::table('employees')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'person_party_id' => $partyId, 'employee_code' => HrSaasSync::nextCode($tenantId),
                'status' => 'ACTIVE', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ...$this->fields($tenantId, $data),
            ]);
            $this->assertUserFree($tenantId, $data['user_id'] ?? null, $id);
            Personal::copyCostToUser($tenantId, $data['user_id'] ?? null, $data['hourly_cost']);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'employee.create', entityType: 'Employee', entityId: $id,
                afterData: $this->auditData($data));

            return $id;
        });

        return response()->json($this->row($this->find($id), []), 201);
    }

    public function update(Request $request, string $employee)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($employee);

        if ($row->source === 'HR_SAAS' && Personal::activeConnection($tenantId)) {
            // Solo el usuario vinculado y la sede operativa (RN-HRM-06).
            $data = $request->validate([
                'user_id' => ['sometimes', 'nullable', 'uuid'],
                'branch_id' => ['sometimes', 'nullable', 'uuid'],
            ]);
            $protected = ['given_names', 'family_name', 'document_type', 'document_number', 'position_id', 'hire_date', 'contract_type',
                'weekly_hours', 'hourly_cost', 'currency', 'payroll_external_id'];
            if ($request->hasAny($protected)) {
                Personal::mastered();
            }
        } else {
            $data = $this->validated($request, false);
        }

        DB::transaction(function () use ($request, $tenantId, $row, $data) {
            $values = $row->source === 'HR_SAAS' && Personal::activeConnection($tenantId)
                ? array_intersect_key($data, array_flip(['user_id', 'branch_id']))
                : $this->fields($tenantId, $data);
            if (array_key_exists('branch_id', $values) && $values['branch_id']
                && ! DB::table('branches')->where('tenant_id', $tenantId)->where('id', $values['branch_id'])->exists()) {
                throw ValidationException::withMessages(['branch_id' => ['La sede no existe.']]);
            }
            DB::table('employees')->where('id', $row->id)->update([...$values, 'updated_at' => now()]);
            if (array_key_exists('user_id', $values)) {
                $this->assertUserFree($tenantId, $values['user_id'], $row->id);
                if ($values['user_id'] && $values['user_id'] !== $row->user_id) {
                    self::userLinked($tenantId, $row->id, $values['user_id']);
                }
            }
            $fresh = DB::table('employees')->where('id', $row->id)->first();
            Personal::copyCostToUser($tenantId, $fresh->user_id, $fresh->hourly_cost);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'employee.update', entityType: 'Employee', entityId: $row->id,
                beforeData: $this->auditData((array) $row), afterData: $this->auditData((array) $fresh));
        });

        return response()->json($this->row($this->find($row->id), []));
    }

    /** Cese: se registra con fecha y motivo; con OT abiertas se avisa para reasignarlas. */
    public function terminate(Request $request, string $employee)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($employee);
        if ($row->source === 'HR_SAAS' && Personal::activeConnection($tenantId)) {
            Personal::mastered('El cese de este trabajador');
        }
        if ($row->status === 'TERMINATED') {
            throw ValidationException::withMessages(['status' => ['El trabajador ya está cesado.']]);
        }
        $data = $request->validate([
            'termination_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ], ['termination_date.required' => 'Indica la fecha de cese.', 'reason.required' => 'Indica el motivo del cese.']);
        if ($row->hire_date && $data['termination_date'] < $row->hire_date) {
            throw ValidationException::withMessages(['termination_date' => ['El cese no puede ser antes del ingreso.']]);
        }

        DB::transaction(function () use ($request, $tenantId, $row, $data) {
            DB::table('employees')->where('id', $row->id)->update([
                'status' => 'TERMINATED', 'termination_date' => $data['termination_date'], 'termination_reason' => trim($data['reason']), 'updated_at' => now(),
            ]);
            HrSaasSync::warnOpenWork($tenantId, $row);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'employee.terminate', entityType: 'Employee', entityId: $row->id,
                afterData: ['termination_date' => $data['termination_date'], 'reason' => trim($data['reason'])]);
        });

        return response()->json($this->row($this->find($row->id), []));
    }

    /** Deshacer un cese registrado por error (o un reingreso). */
    public function reinstate(Request $request, string $employee)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = $this->find($employee);
        if ($row->source === 'HR_SAAS' && Personal::activeConnection($tenantId)) {
            Personal::mastered('El reingreso de este trabajador');
        }
        if ($row->status !== 'TERMINATED') {
            throw ValidationException::withMessages(['status' => ['El trabajador no está cesado.']]);
        }
        DB::transaction(function () use ($request, $tenantId, $row) {
            DB::table('employees')->where('id', $row->id)->update(['status' => 'ACTIVE', 'termination_date' => null, 'termination_reason' => null, 'updated_at' => now()]);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'employee.reinstate', entityType: 'Employee', entityId: $row->id,
                beforeData: ['termination_date' => $row->termination_date, 'reason' => $row->termination_reason]);
        });

        return response()->json($this->row($this->find($row->id), []));
    }

    // ------------------------------------------------------------------ Puestos

    public function positions(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $search = trim((string) $request->query('search', ''));
        $query = DB::table('hr_positions')->where('hr_positions.tenant_id', $tenantId)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ILIKE', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('code', 'ILIKE', '%'.addcslashes($search, '%_\\').'%')->orWhere('department', 'ILIKE', '%'.addcslashes($search, '%_\\').'%')))
            ->when($request->query('active') === 'true', fn ($q) => $q->where('active', true))
            ->when($request->query('active') === 'false', fn ($q) => $q->where('active', false))
            ->select('hr_positions.*')
            ->selectSub(fn ($q) => $q->from('employees')->selectRaw('count(*)')->whereColumn('employees.position_id', 'hr_positions.id')->where('employees.status', 'ACTIVE'), 'employees');

        ColumnFilters::apply($query, $request, [
            'code' => ColumnFilters::text('hr_positions.code'),
            'name' => ColumnFilters::text('hr_positions.name'),
            'department' => ColumnFilters::text('hr_positions.department'),
            'cost' => ColumnFilters::number('hr_positions.default_hourly_cost'),
        ]);
        ListSort::apply($query, $request, [
            'code' => 'hr_positions.code', 'name' => 'hr_positions.name', 'department' => 'hr_positions.department',
            'cost' => 'hr_positions.default_hourly_cost',
            'employees' => '(SELECT count(*) FROM employees e WHERE e.position_id = hr_positions.id AND e.status = \'ACTIVE\')',
            'active' => 'hr_positions.active',
        ], fn ($q) => $q->orderBy('hr_positions.name'), 'hr_positions.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn ($p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'department' => $p->department,
            'default_hourly_cost' => $p->default_hourly_cost !== null ? (float) $p->default_hourly_cost : null, 'currency' => $p->currency,
            'active' => (bool) $p->active, 'source' => $p->source, 'external_id' => $p->external_id, 'employees' => (int) $p->employees,
            'lock_version' => (int) $p->lock_version,
        ]);

        return response()->json($page);
    }

    public function storePosition(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        if (Personal::activeConnection($tenantId)) {
            Personal::mastered('Con la conexión con el SaaS de RRHH activa, un puesto nuevo');
        }
        $data = $this->positionData($request, $tenantId, null);
        $id = DB::table('hr_positions')->insertGetId([...$data, 'tenant_id' => $tenantId, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()], 'id');
        AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'hr_position.create', entityType: 'HrPosition', entityId: $id, afterData: $data);

        return response()->json(DB::table('hr_positions')->where('id', $id)->first(), 201);
    }

    public function updatePosition(Request $request, string $position)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = DB::table('hr_positions')->where('tenant_id', $tenantId)->where('id', $position)->first() ?? abort(404, 'Puesto no encontrado.');
        if ($row->source === 'HR_SAAS' && Personal::activeConnection($tenantId)) {
            Personal::mastered('Este puesto');
        }
        $data = $this->positionData($request, $tenantId, $row->id);
        DB::transaction(function () use ($request, $tenantId, $row, $data) {
            DB::table('hr_positions')->where('id', $row->id)->update([...$data, 'updated_at' => now()]);
            // El texto del cargo (que ven Clientes y actores) sigue al puesto.
            DB::table('employees')->where('position_id', $row->id)->update(['position' => $data['name']]);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'hr_position.update', entityType: 'HrPosition', entityId: $row->id,
                beforeData: array_intersect_key((array) $row, $data), afterData: $data);
        });

        return response()->json(DB::table('hr_positions')->where('id', $row->id)->first());
    }

    // ------------------------------------------------------------------ Apoyo

    /** Con RRHH maestro, vincular un trabajador con un usuario se le avisa a RRHH (§4). */
    public static function userLinked(string $tenantId, string $employeeId, string $userId): void
    {
        $connection = Personal::activeConnection($tenantId);
        $employee = DB::table('employees')->where('id', $employeeId)->first();
        if (! $connection || ! $employee || ! $employee->payroll_external_id) {
            return;
        }
        $email = DB::table('users')->where('id', $userId)->value('email');
        $outbound = HrSaasSync::queue($tenantId, $connection, 'employee.user_linked',
            ['employee_external_id' => $employee->payroll_external_id, 'erp_user_email' => $email], null, request()->user()?->id);
        DB::afterCommit(fn () => rescue(fn () => HrSaasSync::deliver($tenantId, $outbound), report: false));
    }

    private function listing()
    {
        return Employee::query()
            ->join('persons', 'persons.party_id', '=', 'employees.person_party_id')
            ->leftJoin('hr_positions', 'hr_positions.id', '=', 'employees.position_id')
            ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
            ->leftJoin('users', 'users.id', '=', 'employees.user_id')
            ->select('employees.*', DB::raw(self::NAME.' as full_name'), 'persons.document_type', 'persons.document_number',
                'hr_positions.name as position_name', 'branches.name as branch_name', 'users.name as user_name', 'users.email as user_email')
            ->tap(fn ($query) => AccessScope::apply($query, 'employees'));
    }

    private function find(string $id): object
    {
        $row = $this->listing()->where('employees.id', $id)->first();
        if (! $row) {
            abort(response()->json(['message' => 'Trabajador no encontrado.', 'code' => 'NOT_FOUND'], 404));
        }

        return (object) $row->getAttributes();
    }

    private function row($row, array $onLeave): array
    {
        $row = is_object($row) && method_exists($row, 'getAttributes') ? (object) $row->getAttributes() : (object) $row;

        return [
            'id' => $row->id,
            'code' => $row->employee_code,
            'name' => $row->full_name,
            'party_id' => $row->person_party_id,
            'document_type' => $row->document_type,
            'document_number' => $row->document_number,
            'position' => $row->position_id ? ['id' => $row->position_id, 'name' => $row->position_name] : ($row->position ? ['id' => null, 'name' => $row->position] : null),
            'branch' => $row->branch_id ? ['id' => $row->branch_id, 'name' => $row->branch_name] : null,
            'user' => $row->user_id ? ['id' => $row->user_id, 'name' => $row->user_name, 'email' => $row->user_email] : null,
            'hire_date' => $row->hire_date,
            'termination_date' => $row->termination_date,
            'termination_reason' => $row->termination_reason,
            'contract_type' => $row->contract_type,
            'contract_label' => Personal::CONTRACT_TYPES[$row->contract_type] ?? null,
            'weekly_hours' => (float) $row->weekly_hours,
            'hourly_cost' => $row->hourly_cost !== null ? (float) $row->hourly_cost : null,
            'currency' => $row->currency,
            'payroll_external_id' => $row->payroll_external_id,
            'source' => $row->source,
            'synced_at' => $row->synced_at,
            'status' => $row->status,
            'status_label' => Personal::EMPLOYEE_STATES[$row->status] ?? $row->status,
            'on_leave' => $onLeave[$row->id] ?? null,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    private function kpis(string $tenantId, string $today): array
    {
        $base = fn () => tap(Employee::query(), fn ($q) => AccessScope::apply($q, 'employees'));

        return [
            'active' => $base()->where('status', 'ACTIVE')->count(),
            'on_leave' => $base()->where('status', 'ACTIVE')->whereExists($this->leaveToday($today))->count(),
            'without_cost' => $base()->where('status', 'ACTIVE')->whereNull('hourly_cost')->count(),
            'without_user' => $base()->where('status', 'ACTIVE')->whereNull('user_id')->count(),
            'average_cost' => round((float) $base()->where('status', 'ACTIVE')->avg('hourly_cost'), 2),
            'pending_leaves' => DB::table('hr_leave_requests')->where('tenant_id', $tenantId)->where('status', 'REQUESTED')->count(),
        ];
    }

    private function leaveToday(string $today): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('hr_leave_requests')->whereColumn('hr_leave_requests.employee_id', 'employees.id')
            ->where('hr_leave_requests.status', 'APPROVED')->where('hr_leave_requests.date_from', '<=', $today)->where('hr_leave_requests.date_to', '>=', $today);
    }

    /** Ausencia de hoy por trabajador, sin pedirla fila por fila. */
    private function onLeave(string $tenantId, array $ids, string $today): array
    {
        if (! $ids) {
            return [];
        }

        return DB::table('hr_leave_requests as r')->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->where('r.tenant_id', $tenantId)->whereIn('r.employee_id', $ids)->where('r.status', 'APPROVED')
            ->where('r.date_from', '<=', $today)->where('r.date_to', '>=', $today)
            ->get(['r.employee_id', 'r.date_from', 'r.date_to', 't.name as type'])
            ->keyBy('employee_id')->map(fn ($r) => ['type' => $r->type, 'date_from' => $r->date_from, 'date_to' => $r->date_to])->all();
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $newPerson = $creating && ! $request->filled('party_id');

        return $request->validate([
            'party_id' => ['nullable', 'uuid'],
            'given_names' => [$newPerson ? 'required' : 'nullable', 'string', 'max:255'],
            'family_name' => [$newPerson ? 'required' : 'nullable', 'string', 'max:255'],
            'document_type' => [$newPerson ? 'required' : 'nullable', Rule::in(['DNI', 'CE', 'PASSPORT'])],
            'document_number' => [$newPerson ? 'required' : 'nullable', 'string', 'max:30',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->input('document_type') === 'DNI' && $value && ! preg_match('/^\d{8}$/', $value)) {
                        $fail('El DNI tiene 8 dígitos.');
                    }
                }],
            'email' => ['nullable', 'email', 'max:250'],
            'phone' => ['nullable', 'string', 'max:30'],
            'position_id' => ['sometimes', 'nullable', 'uuid'],
            'branch_id' => ['sometimes', 'nullable', 'uuid'],
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'hire_date' => [$required, 'date'],
            'contract_type' => [$required, Rule::in(array_keys(Personal::CONTRACT_TYPES))],
            'weekly_hours' => [$required, 'numeric', 'gt:0', 'max:48'],
            'hourly_cost' => [$required, 'numeric', 'gt:0', 'max:99999'],
            'currency' => ['sometimes', Rule::in(['PEN', 'USD'])],
            'payroll_external_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], [
            'given_names.required' => 'Escribe los nombres.',
            'family_name.required' => 'Escribe los apellidos.',
            'document_type.required' => 'Elige el tipo de documento.',
            'document_number.required' => 'Escribe el número de documento.',
            'hire_date.required' => 'Indica la fecha de ingreso.',
            'contract_type.required' => 'Elige el tipo de contrato.',
            'weekly_hours.required' => 'Indica la jornada semanal.',
            'weekly_hours.max' => 'La jornada semanal no pasa de 48 horas.',
            'hourly_cost.required' => 'Indica el costo por hora.',
            'hourly_cost.gt' => 'El costo por hora debe ser mayor que cero.',
        ]);
    }

    /** Columnas del trabajador a partir de lo validado (solo lo que vino). */
    private function fields(string $tenantId, array $data): array
    {
        if (! empty($data['position_id']) && ! DB::table('hr_positions')->where('tenant_id', $tenantId)->where('id', $data['position_id'])->exists()) {
            throw ValidationException::withMessages(['position_id' => ['El puesto no existe.']]);
        }
        if (! empty($data['branch_id']) && ! DB::table('branches')->where('tenant_id', $tenantId)->where('id', $data['branch_id'])->exists()) {
            throw ValidationException::withMessages(['branch_id' => ['La sede no existe.']]);
        }
        if (! empty($data['user_id']) && ! DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $data['user_id'])->exists()) {
            throw ValidationException::withMessages(['user_id' => ['El usuario no es de esta empresa.']]);
        }
        $values = array_intersect_key($data, array_flip(['position_id', 'branch_id', 'user_id', 'hire_date', 'contract_type', 'weekly_hours', 'hourly_cost', 'currency']));
        if (array_key_exists('payroll_external_id', $data)) {
            $values['payroll_external_id'] = trim((string) $data['payroll_external_id']) ?: null;
        }
        if (array_key_exists('position_id', $values)) {
            $values['position'] = $values['position_id'] ? DB::table('hr_positions')->where('id', $values['position_id'])->value('name') : null;
        }
        if (isset($values['hourly_cost'])) {
            $values['hourly_cost'] = round((float) $values['hourly_cost'], 2);
        }

        return $values;
    }

    private function assertUserFree(string $tenantId, ?string $userId, string $employeeId): void
    {
        if ($userId && DB::table('employees')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('id', '<>', $employeeId)->exists()) {
            throw ValidationException::withMessages(['user_id' => ['Ese usuario ya está vinculado con otro trabajador.']]);
        }
    }

    private function positionData(Request $request, string $tenantId, ?string $id): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('hr_positions', 'code')->where('tenant_id', $tenantId)->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'department' => ['nullable', 'string', 'max:150'],
            'default_hourly_cost' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'code.required' => 'Escribe el código del puesto.',
            'code.unique' => 'Ya hay un puesto con ese código.',
            'name.required' => 'Escribe el nombre del puesto.',
            'default_hourly_cost.gt' => 'El costo por hora sugerido debe ser mayor que cero.',
        ]);

        return [
            'code' => strtoupper(trim($data['code'])), 'name' => trim($data['name']), 'department' => trim((string) ($data['department'] ?? '')) ?: null,
            'default_hourly_cost' => isset($data['default_hourly_cost']) ? round((float) $data['default_hourly_cost'], 2) : null,
            'active' => (bool) ($data['active'] ?? true),
        ];
    }

    private function auditData(array $data): array
    {
        return array_filter(array_intersect_key($data, array_flip([
            'given_names', 'family_name', 'document_type', 'document_number', 'position_id', 'branch_id', 'user_id', 'hire_date',
            'contract_type', 'weekly_hours', 'hourly_cost', 'currency', 'payroll_external_id', 'status',
        ])), fn ($value) => $value !== null);
    }
}
