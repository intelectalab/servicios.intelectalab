<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckPermission;
use App\Modules\Documents\Support\DocumentStore;
use App\Modules\Operations\Models\ContractedService;
use App\Modules\Operations\Models\Deliverable;
use App\Modules\Operations\Models\Project;
use App\Modules\Operations\Models\Task;
use App\Modules\Operations\Support\Technicians;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Proyectos: trabajo de varias tareas con plan, horas, costos y entregables. Nacen de un servicio
 * contratado activo. El margen es el ingreso atribuible menos el costo real (horas y costos aprobados).
 */
class ProjectController extends Controller
{
    /** Servicios contratados que se ofrecen al crear; con más, se acota con el buscador del desplegable. */
    private const OPTIONS_LIMIT = 300;

    /** Quién puede llevar un proyecto o una de sus tareas. */
    private const PEOPLE_PERMISSION = 'projects.view';

    private const MANAGER_NAME = '(SELECT u.name FROM users u WHERE u.id = projects.manager_user_id)';

    /** Cambios de estado de una tarea: destino => estados de origen. */
    private const TASK_MOVES = [
        'IN_PROGRESS' => ['TODO', 'BLOCKED'],
        'BLOCKED' => ['IN_PROGRESS'],
        'DONE' => ['IN_PROGRESS'],
        'CANCELLED' => ['TODO', 'IN_PROGRESS', 'BLOCKED'],
    ];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = $this->listing()
            ->when(isset(Project::STATES[$request->query('status')]), fn ($q) => $q->where('projects.status', $request->query('status')))
            ->when($search !== '', function ($q) use ($search) {
                // Por número, nombre o cliente.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('projects.code', 'ILIKE', $like)
                    ->orWhere('projects.name', 'ILIKE', $like)
                    ->orWhereRaw(ListSort::customerName('projects').' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'project' => ColumnFilters::text("concat_ws(' ', projects.code, projects.name)"),
            'customer' => ColumnFilters::text(ListSort::customerName('projects')),
            'manager' => ColumnFilters::text(self::MANAGER_NAME),
            'start' => ColumnFilters::date('projects.start_date'),
        ]);

        $t = self::totals();
        ListSort::apply($query, $request, [
            'project' => 'lower(projects.name)',
            'customer' => ListSort::customerName('projects'),
            'manager' => 'lower('.self::MANAGER_NAME.')',
            'start' => 'projects.start_date',
            'progress' => "CASE WHEN ({$t['estimate_total']}) > 0 THEN ({$t['estimate_done']}) / ({$t['estimate_total']}) WHEN ({$t['tasks_total']}) > 0 THEN ({$t['tasks_done']})::numeric / ({$t['tasks_total']}) ELSE 0 END",
            'margin' => "projects.budget_revenue - ({$t['labor_cost']}) - ({$t['other_cost']})",
            'status' => ListSort::labels('projects.status', Project::STATES),
        ], fn ($q) => $q->orderByDesc('projects.created_at')->orderByDesc('projects.id'), 'projects.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (Project $project) => $this->row($project));

        return response()->json($page);
    }

    /** Listas del formulario: servicios contratados activos y personas que pueden llevar el proyecto o una tarea. */
    public function options(Request $request)
    {
        return response()->json([
            'people' => Technicians::list($request->attributes->get('tenant_id'), self::PEOPLE_PERMISSION),
            // Por su modelo: solo los servicios contratados que la persona alcanza.
            'contracted_services' => ContractedService::query()
                ->with('customer.party.person', 'customer.party.organization', 'service:id,name')
                ->where('status', 'ACTIVE')
                ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)->get()
                ->map(fn (ContractedService $service) => [
                    'id' => $service->id,
                    'service' => $service->service?->name,
                    'customer' => $this->customerName($service->customer),
                ]),
        ]);
    }

    public function show(string $project, Request $request)
    {
        return response()->json($this->detail($this->find($project), $request));
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate(['contracted_service_id' => ['required', 'uuid'], ...$this->rules()], $this->messages());

        // Por su modelo: un servicio contratado fuera del alcance de la persona "no existe".
        $service = ContractedService::find($data['contracted_service_id']);
        if (! $service) {
            throw ValidationException::withMessages(['contracted_service_id' => ['El servicio contratado indicado no existe.']]);
        }
        if ($service->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['contracted_service_id' => ['El servicio contratado no está activo.']]);
        }
        $manager = $this->manager($tenantId, $data['manager_user_id'] ?? null) ?? $request->user()->id;

        $project = DB::transaction(function () use ($request, $tenantId, $data, $service, $manager) {
            $project = Project::create([
                ...$this->fields($data),
                'tenant_id' => $tenantId,
                'customer_id' => $service->customer_id,
                'contract_id' => $service->contract_id,
                'contracted_service_id' => $service->id,
                'code' => SequentialNumberGenerator::next($tenantId, 'projects', 'code', 'PRY'),
                'status' => 'DRAFT',
                // Ingreso atribuible: lo pactado en la línea del contrato.
                'budget_revenue' => round((float) $service->price * (float) $service->quantity, 2),
                'manager_user_id' => $manager,
                'created_by' => $request->user()->id,
                // El proyecto nace en la sede de su servicio; si no tiene, en la de quien lo crea.
                ...($service->branch_id ? ['branch_id' => $service->branch_id] : []),
            ]);
            $this->audit($request, $project, 'project.create', ['to' => 'DRAFT', 'code' => $project->code, 'name' => $project->name]);

            return $project;
        });

        return response()->json($this->detail($project, $request), 201);
    }

    /** Datos del proyecto; solo mientras sigue abierto. */
    public function update(string $project, Request $request)
    {
        $project = $this->find($project);
        $this->assertOpen($project, 'editar');
        $data = $request->validate($this->rules(), $this->messages());
        $manager = $this->manager($request->attributes->get('tenant_id'), $data['manager_user_id'] ?? null) ?? $project->manager_user_id;

        DB::transaction(function () use ($request, $project, $data, $manager) {
            $fields = ['name', 'start_date', 'end_date', 'budget_hours', 'budget_cost', 'manager_user_id'];
            $before = $project->only($fields);
            $project->update([...$this->fields($data), 'manager_user_id' => $manager]);

            AuditLogger::log(
                tenantId: $request->attributes->get('tenant_id'),
                userId: $request->user()->id,
                action: 'project.update',
                entityType: 'Project',
                entityId: $project->id,
                beforeData: $before,
                afterData: $project->only($fields),
            );
        });

        return response()->json($this->detail($project, $request));
    }

    public function plan(string $project, Request $request)
    {
        return $this->transition($request, $this->find($project), ['DRAFT'], 'PLANNED', 'planificar', 'project.plan');
    }

    public function start(string $project, Request $request)
    {
        return $this->transition($request, $this->find($project), ['PLANNED'], 'IN_PROGRESS', 'iniciar', 'project.start');
    }

    public function hold(string $project, Request $request)
    {
        return $this->transition($request, $this->find($project), ['IN_PROGRESS'], 'ON_HOLD', 'pausar', 'project.hold', 'hold_reason');
    }

    public function resume(string $project, Request $request)
    {
        return $this->transition($request, $this->find($project), ['ON_HOLD'], 'IN_PROGRESS', 'reanudar', 'project.resume');
    }

    /** Cerrar exige las tareas cerradas y los entregables aprobados. */
    public function complete(string $project, Request $request)
    {
        $project = $this->find($project);

        if ($project->status === 'IN_PROGRESS') {
            $openTasks = $project->tasks()->whereNotIn('status', Task::CLOSED)->count();
            $pendingDeliverables = $project->deliverables()->whereNotIn('status', ['APPROVED', 'DELIVERED'])->count();
            $pending = array_filter([
                $openTasks ? ($openTasks === 1 ? '1 tarea abierta' : "{$openTasks} tareas abiertas") : null,
                $pendingDeliverables ? ($pendingDeliverables === 1 ? '1 entregable sin aprobar' : "{$pendingDeliverables} entregables sin aprobar") : null,
            ]);
            if ($pending) {
                throw ValidationException::withMessages(['status' => ['No se puede cerrar el proyecto: tiene '.implode(' y ', $pending).'.']]);
            }
        }

        return $this->transition($request, $project, ['IN_PROGRESS'], 'COMPLETED', 'cerrar', 'project.complete');
    }

    public function cancel(string $project, Request $request)
    {
        return $this->transition($request, $this->find($project), ['DRAFT', 'PLANNED', 'ON_HOLD'], 'CANCELLED', 'cancelar', 'project.cancel', 'cancel_reason');
    }

    // ------------------------------------------------------------------ Tareas

    public function storeTask(string $project, Request $request)
    {
        $project = $this->find($project);
        $this->assertOpen($project, 'agregar tareas a');
        $data = $this->taskData($request);

        DB::transaction(function () use ($request, $project, $data) {
            $task = Task::create([
                ...$data,
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'status' => 'TODO',
                'created_by' => $request->user()->id,
            ]);
            $this->audit($request, $project, 'project.task.create', ['task_id' => $task->id, 'note' => $task->title]);
        });

        return response()->json($this->detail($project, $request), 201);
    }

    /** Datos de la tarea; solo mientras no está cerrada. */
    public function updateTask(string $project, string $task, Request $request)
    {
        $project = $this->find($project);
        $this->assertOpen($project, 'cambiar las tareas de');
        $task = $this->task($project, $task);
        $this->assertTaskOpen($task);
        $data = $this->taskData($request);

        DB::transaction(function () use ($request, $project, $task, $data) {
            // Cambiar el control obligatorio lo deja pendiente otra vez.
            $task->update([...$data, ...($data['required_check'] !== $task->required_check ? ['required_check_done' => false] : [])]);
            $this->audit($request, $project, 'project.task.update', ['task_id' => $task->id, 'note' => $task->title]);
        });

        return response()->json($this->detail($project, $request));
    }

    /** Mueve una tarea de estado. Solo con el proyecto en ejecución; hecha exige su control obligatorio. */
    public function moveTask(string $project, string $task, Request $request)
    {
        $project = $this->find($project);
        $task = $this->task($project, $task);
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(self::TASK_MOVES))],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $to = $data['status'];

        if ($project->status !== 'IN_PROGRESS') {
            throw ValidationException::withMessages(['status' => ['Las tareas se mueven con el proyecto en ejecución.']]);
        }
        if (! in_array($task->status, self::TASK_MOVES[$to], true)) {
            throw ValidationException::withMessages([
                'status' => ['Transición no permitida: '.Task::STATES[$task->status].' → '.Task::STATES[$to].'.'],
            ]);
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($to === 'BLOCKED' && $reason === '') {
            throw ValidationException::withMessages(['reason' => ['Debes indicar un motivo.']]);
        }
        if ($to === 'DONE' && $task->required_check && ! $task->required_check_done) {
            throw ValidationException::withMessages(['status' => ["Falta el control obligatorio: {$task->required_check}."]]);
        }

        DB::transaction(function () use ($request, $project, $task, $to, $reason) {
            $previous = $task->status;
            $task->update(['status' => $to, 'blocked_reason' => $to === 'BLOCKED' ? $reason : null]);
            $this->audit($request, $project, 'project.task.move', array_filter([
                'task_id' => $task->id, 'task_from' => $previous, 'task_to' => $to,
                'note' => $task->title.': '.Task::STATES[$to].($reason !== '' ? " ({$reason})" : ''),
            ]));
        });

        return response()->json($this->detail($project, $request));
    }

    /** Marca o desmarca el control obligatorio de una tarea. */
    public function checkTask(string $project, string $task, Request $request)
    {
        $project = $this->find($project);
        $this->assertOpen($project, 'cambiar las tareas de');
        $task = $this->task($project, $task);
        $this->assertTaskOpen($task);
        $done = (bool) $request->validate(['done' => ['required', 'boolean']])['done'];

        if (! $task->required_check) {
            throw ValidationException::withMessages(['done' => ['La tarea no tiene control obligatorio.']]);
        }

        DB::transaction(function () use ($request, $project, $task, $done) {
            $task->update(['required_check_done' => $done]);
            $this->audit($request, $project, $done ? 'project.task.check' : 'project.task.uncheck', ['task_id' => $task->id, 'note' => "{$task->title}: {$task->required_check}"]);
        });

        return response()->json($this->detail($project, $request));
    }

    // ------------------------------------------------------------------ Entregables

    public function storeDeliverable(string $project, Request $request)
    {
        $project = $this->find($project);
        $this->assertOpen($project, 'agregar entregables a');
        $data = $this->deliverableData($request);

        DB::transaction(function () use ($request, $project, $data) {
            $deliverable = Deliverable::create([
                ...$data, 'tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'status' => 'DRAFT', 'created_by' => $request->user()->id,
            ]);
            $this->audit($request, $project, 'project.deliverable.create', ['deliverable_id' => $deliverable->id, 'note' => $deliverable->name]);
        });

        return response()->json($this->detail($project, $request), 201);
    }

    /** Datos y documento del entregable; solo mientras está en preparación o devuelto. */
    public function updateDeliverable(string $project, string $deliverable, Request $request)
    {
        $project = $this->find($project);
        $this->assertOpen($project, 'cambiar los entregables de');
        $deliverable = $this->deliverable($project, $deliverable);
        $this->assertDeliverable($deliverable, ['DRAFT', 'RETURNED'], 'editar');
        $data = $this->deliverableData($request);
        // Con un archivo adjunto, el documento solo cambia subiendo otra versión.
        if ($deliverable->document_id) {
            unset($data['document_ref']);
        }

        DB::transaction(function () use ($request, $project, $deliverable, $data) {
            $deliverable->update($data);
            $this->audit($request, $project, 'project.deliverable.update', ['deliverable_id' => $deliverable->id, 'note' => $deliverable->name]);
        });

        return response()->json($this->detail($project, $request));
    }

    /**
     * Adjunta el archivo del entregable. Si ya tenía uno, queda como versión nueva del mismo
     * documento: las versiones anteriores no se destruyen.
     */
    public function attachDeliverableDocument(string $project, string $deliverable, Request $request)
    {
        $project = $this->find($project);
        $this->assertOpen($project, 'cambiar los entregables de');
        $deliverable = $this->deliverable($project, $deliverable);
        $this->assertDeliverable($deliverable, ['DRAFT', 'RETURNED'], 'cambiar el documento de');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(DocumentStore::CATEGORIES)],
            'visibility' => ['nullable', Rule::in(DocumentStore::VISIBILITIES)],
            'sha256' => ['nullable', 'regex:/^[0-9a-fA-F]{64}$/'],
            'file' => DocumentStore::fileRules(),
        ], ['title.required' => 'El título es obligatorio.', ...DocumentStore::fileMessages()]);

        $version = DocumentStore::store(
            $project->tenant_id, $request->user()->id, $request->file('file'),
            ['title' => trim($data['title']), 'category' => $data['category'] ?? 'Entregable', 'visibility' => $data['visibility'] ?? 'INTERNAL'],
            $deliverable->document, $data['sha256'] ?? null,
        );

        DB::transaction(function () use ($request, $project, $deliverable, $version) {
            DocumentStore::link($version->document, 'Deliverable', $deliverable->id);
            $deliverable->update(['document_id' => $version->document_id, 'document_ref' => $version->fileObject->original_name]);
            $this->audit($request, $project, 'project.deliverable.attach', [
                'deliverable_id' => $deliverable->id, 'document_id' => $version->document_id,
                'note' => "{$deliverable->name}: {$version->fileObject->original_name} (v{$version->version})",
            ]);
        });

        return response()->json($this->detail($project, $request), 201);
    }

    /** Enlace temporal para descargar el archivo del entregable. */
    public function deliverableDocumentUrl(string $project, string $deliverable, Request $request)
    {
        $project = $this->find($project);
        $deliverable = $this->deliverable($project, $deliverable);
        $url = $deliverable->document ? DocumentStore::downloadUrl($deliverable->document) : null;
        abort_unless($url, 404);

        $this->audit($request, $project, 'project.deliverable.download', [
            'deliverable_id' => $deliverable->id, 'document_id' => $deliverable->document_id, 'note' => "{$deliverable->name}: {$deliverable->document_ref}",
        ]);

        return response()->json(['url' => $url, 'expires_in_minutes' => 5]);
    }

    /** Pasa a revisión. Exige el documento del entregable. */
    public function submitDeliverable(string $project, string $deliverable, Request $request)
    {
        $project = $this->find($project);
        $this->assertOpen($project, 'cambiar los entregables de');
        $deliverable = $this->deliverable($project, $deliverable);
        $this->assertDeliverable($deliverable, ['DRAFT', 'RETURNED'], 'enviar a revisión');

        if (trim((string) $deliverable->document_ref) === '') {
            throw ValidationException::withMessages(['document_ref' => ['Indica el documento del entregable antes de enviarlo a revisión.']]);
        }

        return $this->moveDeliverable($request, $project, $deliverable, 'IN_REVIEW', 'project.deliverable.submit', ['review_note' => null]);
    }

    public function approveDeliverable(string $project, string $deliverable, Request $request)
    {
        $project = $this->find($project);
        $deliverable = $this->deliverable($project, $deliverable);
        $this->assertDeliverable($deliverable, ['IN_REVIEW'], 'aprobar');

        return $this->moveDeliverable($request, $project, $deliverable, 'APPROVED', 'project.deliverable.approve', [
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'accepted_at' => now(),
        ]);
    }

    public function returnDeliverable(string $project, string $deliverable, Request $request)
    {
        $project = $this->find($project);
        $deliverable = $this->deliverable($project, $deliverable);
        $this->assertDeliverable($deliverable, ['IN_REVIEW'], 'devolver');
        $reason = trim($request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Debes indicar un motivo.'],
        )['reason']);

        return $this->moveDeliverable($request, $project, $deliverable, 'RETURNED', 'project.deliverable.return', [
            'review_note' => $reason, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ], $reason);
    }

    public function deliverDeliverable(string $project, string $deliverable, Request $request)
    {
        $project = $this->find($project);
        $deliverable = $this->deliverable($project, $deliverable);
        $this->assertDeliverable($deliverable, ['APPROVED'], 'entregar');

        return $this->moveDeliverable($request, $project, $deliverable, 'DELIVERED', 'project.deliverable.deliver', ['delivered_at' => now()]);
    }

    // ------------------------------------------------------------------ Apoyo

    private function find(string $project): Project
    {
        abort_unless(Str::isUuid($project), 404);

        return Project::findOrFail($project);
    }

    private function task(Project $project, string $task): Task
    {
        abort_unless(Str::isUuid($task), 404);

        return $project->tasks()->whereKey($task)->firstOrFail();
    }

    private function deliverable(Project $project, string $deliverable): Deliverable
    {
        abort_unless(Str::isUuid($deliverable), 404);

        return $project->deliverables()->whereKey($deliverable)->firstOrFail();
    }

    /**
     * Con lo que la tabla y el tablero muestran en cada fila. El avance, las horas y el costo son
     * datos del proyecto: quien lo ve, ve sus totales, aunque no alcance cada registro de horas.
     */
    private function listing()
    {
        $query = Project::query()->select('projects.*');
        foreach (self::totals() as $alias => $sql) {
            $query->selectSub($sql, $alias);
        }

        return $query
            ->with('customer.party.person', 'customer.party.organization', 'manager:id,name');
    }

    /** Totales de cada proyecto (alias => subconsulta), para mostrarlos y para ordenar por ellos. */
    public static function totals(): array
    {
        $tasks = fn (string $what, string $extra = '') => "SELECT {$what} FROM tasks t WHERE t.project_id = projects.id AND t.status <> 'CANCELLED' {$extra}";
        $hours = fn (string $what, string $status) => "SELECT COALESCE({$what}, 0) FROM time_entries h WHERE h.project_id = projects.id AND h.status = '{$status}'";

        return [
            'tasks_total' => $tasks('count(*)'),
            'tasks_done' => $tasks('count(*)', "AND t.status = 'DONE'"),
            'estimate_total' => $tasks('COALESCE(sum(t.estimate_hours), 0)'),
            'estimate_done' => $tasks('COALESCE(sum(t.estimate_hours), 0)', "AND t.status = 'DONE'"),
            'hours_approved' => $hours('sum(h.hours)', 'APPROVED'),
            'hours_pending' => $hours('sum(h.hours)', 'SUBMITTED'),
            'labor_cost' => $hours('sum(h.hours * h.hourly_cost)', 'APPROVED'),
            'other_cost' => "SELECT COALESCE(sum(c.amount), 0) FROM cost_entries c WHERE c.project_id = projects.id AND c.status = 'APPROVED'",
        ];
    }

    private function row(Project $project): array
    {
        $estimate = (float) $project->estimate_total;
        $tasks = (int) $project->tasks_total;
        // Avance ponderado por horas estimadas; sin estimaciones, por número de tareas.
        $progress = $estimate > 0 ? (float) $project->estimate_done / $estimate : ($tasks > 0 ? (int) $project->tasks_done / $tasks : 0);
        $revenue = (float) $project->budget_revenue;
        $labor = round((float) $project->labor_cost, 2);
        $other = round((float) $project->other_cost, 2);
        $margin = round($revenue - $labor - $other, 2);

        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'status' => $project->status,
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'customer' => $project->customer,
            'manager' => $project->manager ? ['id' => $project->manager->id, 'name' => $project->manager->name] : null,
            'budget_revenue' => $revenue,
            'budget_cost' => (float) $project->budget_cost,
            'budget_hours' => (float) $project->budget_hours,
            'metrics' => [
                'progress' => (int) round($progress * 100),
                'tasks' => $tasks,
                'done' => (int) $project->tasks_done,
                'hours' => round((float) $project->hours_approved, 1),
                'pending_hours' => round((float) $project->hours_pending, 1),
                'labor_cost' => $labor,
                'other_cost' => $other,
                'cost' => round($labor + $other, 2),
                'margin' => $margin,
                'margin_pct' => $revenue > 0 ? (int) round($margin / $revenue * 100) : 0,
            ],
            'hold_reason' => $project->hold_reason,
            'cancel_reason' => $project->cancel_reason,
            'created_at' => $project->created_at?->toIso8601String(),
            'lock_version' => (int) $project->lock_version,
        ];
    }

    private function detail(Project $project, Request $request): array
    {
        $project = $this->listing()
            ->with('contract:id,contract_number', 'tasks.assignee:id,name')
            ->findOrFail($project->id);

        return [
            ...$this->row($project),
            'contract' => $project->contract ? ['id' => $project->contract->id, 'contract_number' => $project->contract->contract_number] : null,
            'tasks' => $project->tasks->sortBy('created_at')->values()->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'priority' => $task->priority,
                'estimate_hours' => (float) $task->estimate_hours,
                'due_at' => $task->due_at?->toDateString(),
                'assignee' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null,
                'required_check' => $task->required_check,
                'required_check_done' => (bool) $task->required_check_done,
                'blocked_reason' => $task->blocked_reason,
            ]),
            // Los entregables se entregan solo con su permiso; sin él la ficha muestra el candado.
            'deliverables' => CheckPermission::granted($request, 'deliverables.view')
                ? $project->deliverables()->with('document.latestVersion.fileObject')->orderBy('created_at')->get()->map(fn (Deliverable $deliverable) => [
                    'id' => $deliverable->id,
                    'name' => $deliverable->name,
                    'status' => $deliverable->status,
                    'due_date' => $deliverable->due_date?->toDateString(),
                    'document_ref' => $deliverable->document_ref,
                    'document' => $this->documentOf($deliverable),
                    'review_note' => $deliverable->review_note,
                    'delivered_at' => $deliverable->delivered_at?->toIso8601String(),
                ])
                : null,
            'history' => AuditHistory::for('Project', $project->id),
        ];
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget_hours' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'budget_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'manager_user_id' => ['nullable', 'uuid'],
        ];
    }

    private function messages(): array
    {
        return [
            'contracted_service_id.required' => 'Elige el servicio contratado.',
            'name.required' => 'El nombre es obligatorio.',
            'end_date.after_or_equal' => 'El fin no puede ser anterior al inicio.',
        ];
    }

    private function fields(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'budget_hours' => $data['budget_hours'] ?? 0,
            'budget_cost' => $data['budget_cost'] ?? 0,
        ];
    }

    /** Responsable indicado, si puede ver proyectos en esta empresa. */
    private function manager(string $tenantId, ?string $userId): ?string
    {
        if ($userId && ! Technicians::includes($tenantId, $userId, self::PEOPLE_PERMISSION)) {
            throw ValidationException::withMessages(['manager_user_id' => ['La persona indicada no puede llevar proyectos en esta empresa.']]);
        }

        return $userId;
    }

    private function taskData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH'],
            'assigned_user_id' => ['nullable', 'uuid'],
            'estimate_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'due_at' => ['nullable', 'date'],
            'required_check' => ['nullable', 'string', 'max:255'],
        ], ['title.required' => 'El título es obligatorio.']);

        $assignee = $data['assigned_user_id'] ?? null;
        if ($assignee && ! Technicians::includes($request->attributes->get('tenant_id'), $assignee, self::PEOPLE_PERMISSION)) {
            throw ValidationException::withMessages(['assigned_user_id' => ['La persona indicada no puede ver proyectos en esta empresa.']]);
        }

        return [
            'title' => trim($data['title']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'priority' => $data['priority'] ?? 'NORMAL',
            'assigned_user_id' => $assignee,
            'estimate_hours' => $data['estimate_hours'] ?? 0,
            'due_at' => $data['due_at'] ?? null,
            'required_check' => trim((string) ($data['required_check'] ?? '')) ?: null,
        ];
    }

    /** Archivo adjunto del entregable, con lo que la ficha muestra de su última versión. */
    private function documentOf(Deliverable $deliverable): ?array
    {
        $document = $deliverable->document;
        $version = $document?->latestVersion;
        if (! $document || ! $version?->fileObject) {
            return null;
        }

        return [
            'id' => $document->id,
            'title' => $document->title,
            'category' => $document->document_type,
            'visibility' => $document->visibility,
            'version' => (int) $version->version,
            'file_name' => $version->fileObject->original_name,
            'size_bytes' => (int) $version->fileObject->size_bytes,
            'sha256' => trim((string) $version->fileObject->sha256),
            'antivirus_status' => $version->fileObject->antivirus_status,
            'antivirus_engine' => $version->fileObject->antivirus_engine,
        ];
    }

    private function deliverableData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'document_ref' => ['nullable', 'string', 'max:1000'],
        ], ['name.required' => 'El nombre es obligatorio.']);

        return [
            'name' => trim($data['name']),
            'due_date' => $data['due_date'] ?? null,
            'document_ref' => trim((string) ($data['document_ref'] ?? '')) ?: null,
        ];
    }

    private function customerName(?object $customer): ?string
    {
        $party = $customer?->party;

        return $party?->organization?->trade_name ?: $party?->organization?->legal_name
            ?: (trim(($party?->person?->given_names ?? '').' '.($party?->person?->family_name ?? '')) ?: null);
    }

    private function assertOpen(Project $project, string $verb): void
    {
        if (! in_array($project->status, Project::OPEN, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un proyecto ".mb_strtolower(Project::STATES[$project->status] ?? $project->status).'.'],
            ]);
        }
    }

    private function assertTaskOpen(Task $task): void
    {
        if (in_array($task->status, Task::CLOSED, true)) {
            throw ValidationException::withMessages(['status' => ['Una tarea '.mb_strtolower(Task::STATES[$task->status]).' ya no se cambia.']]);
        }
    }

    private function assertDeliverable(Deliverable $deliverable, array $from, string $verb): void
    {
        if (! in_array($deliverable->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un entregable ".mb_strtolower(Deliverable::STATES[$deliverable->status] ?? $deliverable->status).'.'],
            ]);
        }
    }

    private function moveDeliverable(Request $request, Project $project, Deliverable $deliverable, string $to, string $action, array $extra = [], ?string $reason = null)
    {
        DB::transaction(function () use ($request, $project, $deliverable, $to, $action, $extra, $reason) {
            $deliverable->update(['status' => $to, ...$extra]);
            $this->audit($request, $project, $action, [
                'deliverable_id' => $deliverable->id,
                'note' => $deliverable->name.': '.Deliverable::STATES[$to].($reason ? " ({$reason})" : ''),
            ]);
        });

        return response()->json($this->detail($project, $request));
    }

    private function transition(Request $request, Project $project, array $from, string $to, string $verb, string $action, ?string $reasonColumn = null)
    {
        if (! in_array($project->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un proyecto ".mb_strtolower(Project::STATES[$project->status] ?? $project->status).'.'],
            ]);
        }

        $reason = null;
        if ($reasonColumn) {
            $reason = trim($request->validate(
                ['reason' => ['required', 'string', 'max:1000']],
                ['reason.required' => 'Debes indicar un motivo.'],
            )['reason']);
        }

        DB::transaction(function () use ($request, $project, $to, $action, $reason, $reasonColumn) {
            $previous = $project->status;
            $project->update([
                'status' => $to,
                ...($reasonColumn ? [$reasonColumn => $reason] : []),
                ...($to === 'IN_PROGRESS' ? ['hold_reason' => null] : []),
            ]);
            $this->audit($request, $project, $action, array_filter(['from' => $previous, 'to' => $to, 'note' => $reason]));
        });

        return response()->json($this->detail($project, $request));
    }

    private function audit(Request $request, Project $project, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'Project',
            entityId: $project->id,
            metadata: $metadata,
        );
    }
}
