<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckPermission;
use App\Modules\Operations\Models\CostEntry;
use App\Modules\Operations\Models\Project;
use App\Modules\Operations\Models\Task;
use App\Modules\Operations\Models\TimeEntry;
use App\Modules\Operations\Models\WorkOrder;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Horas: cada persona registra las suyas en un proyecto o en una orden de trabajo, sin solaparse;
 * otra persona las aprueba o las rechaza. Solo las aprobadas cuentan para el costo.
 */
class TimeEntryController extends Controller
{
    private const OPTIONS_LIMIT = 300;

    private const PERSON = '(SELECT u.name FROM users u WHERE u.id = time_entries.user_id)';

    private const CONTEXT = "COALESCE((SELECT concat_ws(' ', p.code, p.name) FROM projects p WHERE p.id = time_entries.project_id),
        (SELECT concat_ws(' ', w.code, w.title) FROM work_orders w WHERE w.id = time_entries.work_order_id))";

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = TimeEntry::query()
            ->with('user:id,name', 'project:id,code,name', 'workOrder:id,code,title', 'task:id,title')
            ->when($request->boolean('mine'), fn ($q) => $q->where('time_entries.user_id', $request->user()->id))
            // Por aprobar: las de otras personas (nadie aprueba las suyas).
            ->when($request->boolean('others'), fn ($q) => $q->where('time_entries.user_id', '<>', $request->user()->id))
            ->when(isset(TimeEntry::STATES[$request->query('status')]), fn ($q) => $q->where('time_entries.status', $request->query('status')))
            ->when(Str::isUuid((string) $request->query('project_id')), fn ($q) => $q->where('time_entries.project_id', $request->query('project_id')))
            ->when(Str::isUuid((string) $request->query('work_order_id')), fn ($q) => $q->where('time_entries.work_order_id', $request->query('work_order_id')))
            ->when($search !== '', function ($q) use ($search) {
                // Por persona, proyecto, orden de trabajo o descripción.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->whereRaw(self::PERSON.' ILIKE ?', [$like])
                    ->orWhereRaw(self::CONTEXT.' ILIKE ?', [$like])
                    ->orWhere('time_entries.description', 'ILIKE', $like));
            });

        ColumnFilters::apply($query, $request, [
            'person' => ColumnFilters::text(self::PERSON),
            'context' => ColumnFilters::text(self::CONTEXT),
            'date' => ColumnFilters::date('time_entries.started_at'),
            'hours' => ColumnFilters::number('time_entries.hours'),
        ]);

        ListSort::apply($query, $request, [
            'person' => 'lower('.self::PERSON.')',
            'context' => 'lower('.self::CONTEXT.')',
            'date' => 'time_entries.started_at',
            'hours' => 'time_entries.hours',
            'status' => ListSort::labels('time_entries.status', TimeEntry::STATES),
        ], fn ($q) => $q->orderByDesc('time_entries.started_at')->orderByDesc('time_entries.id'), 'time_entries.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $userId = $request->user()->id;
        $page->getCollection()->transform(fn (TimeEntry $entry) => $this->row($entry, $userId));

        return response()->json($page);
    }

    /** Indicadores de la pantalla Horas y costos, sobre lo que la persona alcanza. */
    public function summary(Request $request)
    {
        $userId = $request->user()->id;

        return response()->json([
            'mine' => TimeEntry::where('user_id', $userId)->count(),
            'week_hours' => round((float) TimeEntry::where('user_id', $userId)->where('status', '<>', 'REJECTED')
                ->where('started_at', '>=', now()->subDays(7))->sum('hours'), 1),
            'to_approve' => TimeEntry::where('status', 'SUBMITTED')->where('user_id', '<>', $userId)->count(),
            'costs_to_approve' => CheckPermission::granted($request, 'cost_entries.view') ? CostEntry::where('status', 'SUBMITTED')->count() : null,
        ]);
    }

    /** Dónde se pueden registrar horas: proyectos y órdenes de trabajo abiertos que la persona alcanza. */
    public function options()
    {
        return response()->json([
            'projects' => Project::query()->whereIn('status', Project::OPEN)->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)
                ->with(['tasks' => fn ($tasks) => $tasks->whereNotIn('status', Task::CLOSED)->orderBy('created_at')->select('id', 'project_id', 'title')])
                ->get(['id', 'code', 'name'])
                ->map(fn (Project $project) => [
                    'id' => $project->id, 'code' => $project->code, 'name' => $project->name,
                    'tasks' => $project->tasks->map(fn (Task $task) => ['id' => $task->id, 'title' => $task->title])->values(),
                ]),
            'work_orders' => WorkOrder::query()->where('status', '<>', 'CANCELLED')->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)
                ->get(['id', 'code', 'title'])
                ->map(fn (WorkOrder $order) => ['id' => $order->id, 'code' => $order->code, 'title' => $order->title]),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $userId = $request->user()->id;
        $data = $request->validate([
            'project_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'work_order_id' => ['nullable', 'uuid'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at'],
            'description' => ['nullable', 'string', 'max:1000'],
            'billable' => ['sometimes', 'boolean'],
        ], [
            'started_at.required' => 'Indica el inicio.',
            'ended_at.required' => 'Indica el fin.',
            'ended_at.after' => 'La hora de fin debe ser posterior al inicio.',
        ]);

        if (empty($data['project_id']) === empty($data['work_order_id'])) {
            throw ValidationException::withMessages(['project_id' => ['Asocia las horas a un proyecto o a una orden de trabajo (solo uno).']]);
        }

        $start = Carbon::parse($data['started_at']);
        $end = Carbon::parse($data['ended_at']);
        if ($start->diffInMinutes($end) > TimeEntry::MAX_HOURS * 60) {
            throw ValidationException::withMessages(['ended_at' => ['Duración inválida: un registro dura como mucho '.TimeEntry::MAX_HOURS.' horas.']]);
        }

        // Por sus modelos: un proyecto o una orden fuera del alcance de la persona "no existe".
        $project = ! empty($data['project_id']) ? Project::find($data['project_id']) : null;
        $order = ! empty($data['work_order_id']) ? WorkOrder::find($data['work_order_id']) : null;
        if (! empty($data['project_id']) && (! $project || ! in_array($project->status, Project::OPEN, true))) {
            throw ValidationException::withMessages(['project_id' => [$project ? 'El proyecto ya está cerrado.' : 'El proyecto indicado no existe.']]);
        }
        if (! empty($data['work_order_id']) && (! $order || $order->status === 'CANCELLED')) {
            throw ValidationException::withMessages(['work_order_id' => [$order ? 'La orden de trabajo está cancelada.' : 'La orden de trabajo indicada no existe.']]);
        }
        $taskId = $data['task_id'] ?? null;
        if ($taskId && ! ($project && $project->tasks()->whereKey($taskId)->exists())) {
            throw ValidationException::withMessages(['task_id' => ['La tarea no pertenece al proyecto.']]);
        }

        // Se mira toda la empresa: las horas de la persona no se solapan, las vea o no.
        $overlap = TimeEntry::ignoringAccessScope()->where('user_id', $userId)->where('status', '<>', 'REJECTED')
            ->where('started_at', '<', $end)->where('ended_at', '>', $start)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['started_at' => ['Se solapa con otro registro de horas tuyo.']]);
        }

        $branchId = $project?->branch_id ?? $order?->branch_id;
        $entry = DB::transaction(function () use ($request, $tenantId, $userId, $data, $start, $end, $project, $order, $taskId, $branchId) {
            $entry = TimeEntry::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $project?->id,
                'task_id' => $taskId,
                'work_order_id' => $order?->id,
                'started_at' => $start,
                'ended_at' => $end,
                'hours' => round($start->diffInMinutes($end) / 60, 4),
                'billable' => (bool) ($data['billable'] ?? true),
                // El costo por hora de la persona en el momento de registrar.
                'hourly_cost' => (float) DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $userId)->value('hourly_cost'),
                'approved' => false,
                'status' => 'SUBMITTED',
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                // Las horas son de la sede del proyecto u orden; si no tiene, de la de quien las registra.
                ...($branchId ? ['branch_id' => $branchId] : []),
            ]);
            $this->audit($request, $entry, 'time_entry.create', ['to' => 'SUBMITTED', 'hours' => (float) $entry->hours]);

            return $entry;
        });

        return response()->json($this->row($entry->load('user:id,name', 'project:id,code,name', 'workOrder:id,code,title', 'task:id,title'), $userId), 201);
    }

    /** Aprueba unas horas enviadas. Nadie aprueba las suyas. */
    public function approve(string $timeEntry, Request $request)
    {
        $entry = $this->find($timeEntry);
        $this->assertReviewable($entry, $request, 'aprobar');

        DB::transaction(function () use ($request, $entry) {
            $entry->update(['status' => 'APPROVED', 'approved' => true, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'reject_reason' => null]);
            $this->audit($request, $entry, 'time_entry.approve', ['from' => 'SUBMITTED', 'to' => 'APPROVED']);
        });

        return response()->json($this->row($entry->load('user:id,name', 'project:id,code,name', 'workOrder:id,code,title', 'task:id,title'), $request->user()->id));
    }

    public function reject(string $timeEntry, Request $request)
    {
        $entry = $this->find($timeEntry);
        $this->assertReviewable($entry, $request, 'rechazar');
        $reason = trim($request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Debes indicar un motivo.'],
        )['reason']);

        DB::transaction(function () use ($request, $entry, $reason) {
            $entry->update(['status' => 'REJECTED', 'approved' => false, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'reject_reason' => $reason]);
            $this->audit($request, $entry, 'time_entry.reject', ['from' => 'SUBMITTED', 'to' => 'REJECTED', 'note' => $reason]);
        });

        return response()->json($this->row($entry->load('user:id,name', 'project:id,code,name', 'workOrder:id,code,title', 'task:id,title'), $request->user()->id));
    }

    private function find(string $timeEntry): TimeEntry
    {
        abort_unless(Str::isUuid($timeEntry), 404);

        return TimeEntry::findOrFail($timeEntry);
    }

    private function assertReviewable(TimeEntry $entry, Request $request, string $verb): void
    {
        if ($entry->status !== 'SUBMITTED') {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} unas horas en estado ".mb_strtolower(TimeEntry::STATES[$entry->status] ?? $entry->status).'.'],
            ]);
        }
        if ($entry->user_id === $request->user()->id) {
            throw ValidationException::withMessages(['status' => ["No puedes {$verb} tus propias horas: las revisa otra persona."]]);
        }
    }

    private function row(TimeEntry $entry, string $viewerId): array
    {
        return [
            'id' => $entry->id,
            'user' => $entry->user ? ['id' => $entry->user->id, 'name' => $entry->user->name] : null,
            'own' => $entry->user_id === $viewerId,
            // null si el proyecto o la orden quedan fuera del alcance de quien mira.
            'project' => $entry->project ? ['id' => $entry->project->id, 'code' => $entry->project->code, 'name' => $entry->project->name] : null,
            'work_order' => $entry->workOrder ? ['id' => $entry->workOrder->id, 'code' => $entry->workOrder->code, 'title' => $entry->workOrder->title] : null,
            'task' => $entry->task?->title,
            'started_at' => $entry->started_at?->toIso8601String(),
            'ended_at' => $entry->ended_at?->toIso8601String(),
            'hours' => round((float) $entry->hours, 2),
            'billable' => (bool) $entry->billable,
            'cost' => round((float) $entry->hours * (float) $entry->hourly_cost, 2),
            'status' => $entry->status,
            'description' => $entry->description,
            'reject_reason' => $entry->reject_reason,
            'lock_version' => (int) $entry->lock_version,
        ];
    }

    private function audit(Request $request, TimeEntry $entry, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'TimeEntry',
            entityId: $entry->id,
            metadata: $metadata,
        );
    }
}
