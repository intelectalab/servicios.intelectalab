<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckPermission;
use App\Modules\Core\Http\Controllers\TenantSettingsController;
use App\Modules\Operations\Models\Checklist;
use App\Modules\Operations\Models\ContractedService;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Operations\Models\WorkOrderChecklistItem;
use App\Modules\Documents\Support\DocumentStore;
use App\Modules\Finance\Support\Billing;
use App\Modules\Operations\Support\AgendaClash;
use App\Modules\Operations\Support\Technicians;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Órdenes de trabajo: crear → asignar → ejecutar → evidenciar (checklist) → cerrar.
 * Nacen de un servicio contratado activo. Completar exige los ítems obligatorios del checklist.
 */
class WorkOrderController extends Controller
{
    /** Duración que ocupa en la agenda del técnico una orden sin hora de fin. */
    private const DEFAULT_HOURS = 2;

    /** Servicios contratados que se ofrecen al crear; con más, se acota con el buscador del desplegable. */
    private const OPTIONS_LIMIT = 300;

    /** Estados de la orden en los que se marca su checklist. */
    private const CHECKABLE = ['ASSIGNED', 'IN_PROGRESS', 'BLOCKED'];

    private const TECHNICIAN_NAME = '(SELECT u.name FROM users u WHERE u.id = work_orders.assigned_user_id)';

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = $this->listing()
            ->when(isset(WorkOrder::STATES[$request->query('status')]), fn ($q) => $q->where('work_orders.status', $request->query('status')))
            ->when(isset(WorkOrder::PRIORITIES[$request->query('priority')]), fn ($q) => $q->where('work_orders.priority', $request->query('priority')))
            ->when(Str::isUuid((string) $request->query('technician')), fn ($q) => $q->where('work_orders.assigned_user_id', $request->query('technician')))
            ->when($request->query('technician') === 'none', fn ($q) => $q->whereNull('work_orders.assigned_user_id'))
            ->when($search !== '', function ($q) use ($search) {
                // Por número, título o nombre del cliente.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('work_orders.code', 'ILIKE', $like)
                    ->orWhere('work_orders.title', 'ILIKE', $like)
                    ->orWhereRaw(ListSort::customerName('work_orders').' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'order' => ColumnFilters::text("concat_ws(' ', work_orders.code, work_orders.title)"),
            'customer' => ColumnFilters::text(ListSort::customerName('work_orders')),
            'scheduled' => ColumnFilters::date('work_orders.scheduled_start'),
        ]);

        ListSort::apply($query, $request, [
            'order' => 'work_orders.code',
            'customer' => ListSort::customerName('work_orders'),
            'scheduled' => 'work_orders.scheduled_start',
            // De la más urgente a la menos, no por orden alfabético.
            'priority' => "CASE work_orders.priority WHEN 'HIGH' THEN 1 WHEN 'NORMAL' THEN 2 ELSE 3 END",
            'technician' => 'lower('.self::TECHNICIAN_NAME.')',
            'status' => ListSort::labels('work_orders.status', WorkOrder::STATES),
        ], fn ($q) => $q->orderByDesc('work_orders.created_at')->orderByDesc('work_orders.id'), 'work_orders.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (WorkOrder $order) => $this->row($order));

        return response()->json($page);
    }

    /** Listas de los filtros y del formulario: técnicos, plantillas de checklist y servicios activos. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'technicians' => Technicians::list($tenantId),
            // Con su servicio: al elegir el servicio contratado, el formulario propone su plantilla.
            'templates' => Checklist::query()->where('active', true)->withCount('items')->orderBy('name')->limit(200)->get(['id', 'name', 'service_id'])
                ->map(fn (Checklist $template) => ['id' => $template->id, 'name' => $template->name, 'items' => (int) $template->items_count, 'service_id' => $template->service_id]),
            // Por su modelo: solo los servicios contratados que la persona alcanza.
            'contracted_services' => ContractedService::query()
                ->with('customer.party.person', 'customer.party.organization', 'service:id,name')
                ->where('status', 'ACTIVE')
                ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)->get()
                ->map(fn (ContractedService $service) => [
                    'id' => $service->id,
                    'service' => $service->service?->name,
                    'service_id' => $service->service_id,
                    'customer' => $this->customerName($service->customer),
                ]),
        ]);
    }

    public function show(string $workOrder)
    {
        return response()->json($this->detail($this->find($workOrder)));
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'contracted_service_id' => ['required', 'uuid'],
            'checklist_id' => ['nullable', 'uuid'],
            'assigned_user_id' => ['nullable', 'uuid'],
            ...$this->rules(),
        ], $this->messages());

        // Asignar al crear es asignar: pide el mismo permiso que el botón "Asignar técnico".
        if (! empty($data['assigned_user_id']) && ! CheckPermission::granted($request, 'work_orders.approve')) {
            return response()->json(['message' => 'No tienes el permiso requerido: work_orders.approve', 'code' => 'PERMISSION_DENIED'], 403);
        }

        // Por su modelo: un servicio contratado fuera del alcance de la persona "no existe".
        $service = ContractedService::find($data['contracted_service_id']);
        if (! $service) {
            throw ValidationException::withMessages(['contracted_service_id' => ['El servicio contratado indicado no existe.']]);
        }
        if ($service->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['contracted_service_id' => ['El servicio contratado no está activo.']]);
        }

        $template = null;
        if (! empty($data['checklist_id'])) {
            $template = Checklist::with('items')->where('active', true)->find($data['checklist_id']);
            if (! $template) {
                throw ValidationException::withMessages(['checklist_id' => ['La plantilla de checklist indicada no existe.']]);
            }
        }

        [$start, $end] = $this->schedule($data);
        $assignee = $data['assigned_user_id'] ?? null;
        if ($assignee) {
            $this->assertAssignable($tenantId, $assignee, null, $start, $end);
        }

        $order = DB::transaction(function () use ($request, $tenantId, $data, $service, $template, $start, $end, $assignee) {
            $order = WorkOrder::create([
                'tenant_id' => $tenantId,
                'customer_id' => $service->customer_id,
                'contracted_service_id' => $service->id,
                'contract_id' => $service->contract_id,
                'service_id' => $service->service_id,
                'code' => SequentialNumberGenerator::next($tenantId, 'work_orders', 'code', 'OT'),
                'title' => trim($data['title']),
                'status' => 'PLANNED',
                'priority' => $data['priority'] ?? 'NORMAL',
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'location' => $this->text($data['location'] ?? null),
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'description' => $this->text($data['description'] ?? null),
                'created_by' => $request->user()->id,
                'checklist_id' => $template?->id,
                'checklist_name' => $template?->name,
                'checklist_status' => $template ? 'PENDING' : null,
                // La orden nace en la sede de su servicio; si no tiene, en la de quien la crea.
                ...($service->branch_id ? ['branch_id' => $service->branch_id] : []),
            ]);

            foreach ($template?->items ?? [] as $item) {
                WorkOrderChecklistItem::create([
                    'tenant_id' => $tenantId, 'work_order_id' => $order->id,
                    'position' => $item->position, 'label' => $item->label, 'required' => $item->required,
                    'evidence_required' => $item->evidence_required,
                ]);
            }

            $this->audit($request, $order, 'work_order.create', ['to' => 'PLANNED', 'code' => $order->code, 'title' => $order->title]);

            if ($assignee) {
                $order->update(['assigned_user_id' => $assignee, 'status' => 'ASSIGNED']);
                $this->audit($request, $order, 'work_order.assign', [
                    'from' => 'PLANNED', 'to' => 'ASSIGNED', 'assigned_user_id' => $assignee, 'note' => 'Asignada a '.$this->userName($assignee),
                ]);
            }

            return $order;
        });

        return response()->json($this->detail($order), 201);
    }

    /** Datos de la orden; solo mientras sigue abierta. */
    public function update(string $workOrder, Request $request)
    {
        $order = $this->find($workOrder);
        $this->assertOpen($order, 'editar');
        $data = $request->validate($this->rules(), $this->messages());
        [$start, $end] = $this->schedule($data);

        // Mover la fecha de una orden ya asignada también ocupa la agenda de su técnico.
        if ($order->assigned_user_id && in_array($order->status, WorkOrder::BUSY, true)) {
            $this->assertFree($order->assigned_user_id, $order, $start, $end);
        }

        DB::transaction(function () use ($request, $order, $data, $start, $end) {
            $fields = ['title', 'priority', 'scheduled_start', 'scheduled_end', 'location', 'latitude', 'longitude', 'description'];
            $before = $order->only($fields);
            $order->update([
                'title' => trim($data['title']),
                'priority' => $data['priority'] ?? $order->priority,
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'location' => $this->text($data['location'] ?? null),
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'description' => $this->text($data['description'] ?? null),
            ]);

            AuditLogger::log(
                tenantId: $request->attributes->get('tenant_id'),
                userId: $request->user()->id,
                action: 'work_order.update',
                entityType: 'WorkOrder',
                entityId: $order->id,
                beforeData: $before,
                afterData: $order->only($fields),
            );
        });

        return response()->json($this->detail($order));
    }

    /** Asigna o reasigna el técnico (y, si se indica, la fecha). Valida que tenga libre ese horario. */
    public function assign(string $workOrder, Request $request)
    {
        $order = $this->find($workOrder);
        $this->assertOpen($order, 'reasignar');
        $data = $request->validate([
            'assigned_user_id' => ['required', 'uuid'],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date', 'after_or_equal:scheduled_start'],
        ], ['assigned_user_id.required' => 'Elige el técnico.', ...$this->messages()]);

        // Sin fecha nueva se conserva la programada.
        [$start, $end] = empty($data['scheduled_start']) ? [$order->scheduled_start, $order->scheduled_end] : $this->schedule($data);
        $this->assertAssignable($request->attributes->get('tenant_id'), $data['assigned_user_id'], $order, $start, $end);

        DB::transaction(function () use ($request, $order, $data, $start, $end) {
            $previous = $order->status;
            $reassigned = $order->assigned_user_id && $order->assigned_user_id !== $data['assigned_user_id'];
            $to = $previous === 'PLANNED' ? 'ASSIGNED' : $previous;

            $order->update(['assigned_user_id' => $data['assigned_user_id'], 'scheduled_start' => $start, 'scheduled_end' => $end, 'status' => $to]);
            $this->audit($request, $order, 'work_order.assign', array_filter([
                'from' => $previous === $to ? null : $previous,
                'to' => $previous === $to ? null : $to,
                'assigned_user_id' => $data['assigned_user_id'],
                'note' => ($reassigned ? 'Reasignada a ' : 'Asignada a ').$this->userName($data['assigned_user_id']),
            ]));
        });

        return response()->json($this->detail($order));
    }

    public function start(string $workOrder, Request $request)
    {
        return $this->transition($request, $this->find($workOrder), ['ASSIGNED'], 'IN_PROGRESS', 'iniciar', 'work_order.start');
    }

    public function block(string $workOrder, Request $request)
    {
        return $this->transition($request, $this->find($workOrder), ['IN_PROGRESS'], 'BLOCKED', 'bloquear', 'work_order.block', 'blocked_reason');
    }

    public function unblock(string $workOrder, Request $request)
    {
        return $this->transition($request, $this->find($workOrder), ['BLOCKED'], 'IN_PROGRESS', 'desbloquear', 'work_order.unblock');
    }

    /** Regla dura: no se completa con ítems OBLIGATORIOS del checklist pendientes. */
    public function complete(string $workOrder, Request $request)
    {
        $order = $this->find($workOrder);

        if ($order->status === 'IN_PROGRESS') {
            $pending = $order->checklistItems()->where('required', true)->where('done', false)->pluck('label')->all();

            // Checklists de las tareas de la orden (las que se crearon por la API anterior).
            $order->load('tasks.taskChecklists.items.checklistItem');
            foreach ($order->tasks as $task) {
                foreach ($task->taskChecklists as $taskChecklist) {
                    foreach ($taskChecklist->items as $item) {
                        if ($item->checklistItem->required && ! $item->completed) {
                            $pending[] = $item->checklistItem->label;
                        }
                    }
                }
            }

            if ($pending) {
                throw ValidationException::withMessages([
                    'checklist' => ['No se puede completar la orden: hay ítems obligatorios pendientes en el checklist.', ...$pending],
                ]);
            }
        }

        return $this->transition($request, $order, ['IN_PROGRESS'], 'COMPLETED', 'completar', 'work_order.complete');
    }

    public function cancel(string $workOrder, Request $request)
    {
        return $this->transition($request, $this->find($workOrder), ['PLANNED', 'ASSIGNED', 'BLOCKED'], 'CANCELLED', 'cancelar', 'work_order.cancel', 'cancel_reason');
    }

    /** Marca o desmarca un ítem del checklist, con su nota de evidencia. */
    public function checkItem(string $workOrder, string $item, Request $request)
    {
        $order = $this->find($workOrder);
        abort_unless(Str::isUuid($item), 404);
        $row = $order->checklistItems()->whereKey($item)->firstOrFail();

        $data = $request->validate([
            'done' => ['required', 'boolean'],
            'evidence_note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! in_array($order->status, self::CHECKABLE, true)) {
            throw ValidationException::withMessages([
                'status' => [$order->status === 'PLANNED' ? 'Asigna la orden para marcar su checklist.' : 'El checklist de una orden cerrada ya no se cambia.'],
            ]);
        }
        if ($order->checklist_status === 'APPROVED') {
            throw ValidationException::withMessages(['checklist' => ['El checklist ya fue aprobado.']]);
        }
        // Un ítem con evidencia obligatoria se da por hecho al adjuntar su archivo (lo exige también la base).
        if ($data['done'] && $row->evidence_required && ! $row->evidence_document_id) {
            throw ValidationException::withMessages(['evidence' => ["\"{$row->label}\" pide evidencia: adjunta la foto para marcarlo."]]);
        }

        DB::transaction(function () use ($request, $order, $row, $data) {
            $done = (bool) $data['done'];
            $row->update([
                'done' => $done,
                'done_by' => $done ? $request->user()->id : null,
                'done_at' => $done ? now() : null,
                // Sin nota en la petición se conserva la que tenía; con nota vacía, se borra.
                'evidence_note' => $done ? $this->text(array_key_exists('evidence_note', $data) ? $data['evidence_note'] : $row->evidence_note) : null,
            ]);
            $order->update(['checklist_status' => $this->checklistStatus($order)]);

            $this->audit($request, $order, $done ? 'work_order.checklist.check' : 'work_order.checklist.uncheck', ['note' => $row->label]);
        });

        return response()->json($this->detail($order));
    }

    /** Aprueba el checklist completado. Quien ejecuta la orden no aprueba su propio checklist. */
    /**
     * Foto de evidencia de un ítem del checklist: sube la imagen, la deja como documento del
     * ítem (otra foto es una versión nueva) y lo marca como hecho.
     */
    public function attachEvidence(string $workOrder, string $item, Request $request)
    {
        $order = $this->find($workOrder);
        abort_unless(Str::isUuid($item), 404);
        $row = $order->checklistItems()->whereKey($item)->firstOrFail();

        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'max:'.DocumentStore::MAX_KB],
            'sha256' => ['nullable', 'regex:/^[0-9a-fA-F]{64}$/'],
        ], [...DocumentStore::fileMessages(), 'file.image' => 'La evidencia debe ser una foto (JPG, PNG o WebP).']);

        if (! in_array($order->status, self::CHECKABLE, true)) {
            throw ValidationException::withMessages([
                'status' => [$order->status === 'PLANNED' ? 'Asigna la orden para marcar su checklist.' : 'El checklist de una orden cerrada ya no se cambia.'],
            ]);
        }
        if ($order->checklist_status === 'APPROVED') {
            throw ValidationException::withMessages(['checklist' => ['El checklist ya fue aprobado.']]);
        }

        $version = DocumentStore::store(
            $order->tenant_id, $request->user()->id, $request->file('file'),
            ['title' => Str::limit("{$order->code} · {$row->label}", 250, ''), 'category' => 'Evidencia', 'visibility' => 'INTERNAL'],
            $row->evidence, $data['sha256'] ?? null,
        );

        DB::transaction(function () use ($request, $order, $row, $version) {
            DocumentStore::link($version->document, 'WorkOrderChecklistItem', $row->id);
            $row->update([
                'done' => true,
                'done_by' => $request->user()->id,
                'done_at' => now(),
                'evidence_document_id' => $version->document_id,
                'evidence_note' => 'Foto: '.$version->fileObject->original_name,
            ]);
            $order->update(['checklist_status' => $this->checklistStatus($order)]);

            $this->audit($request, $order, 'work_order.checklist.evidence', [
                'document_id' => $version->document_id, 'note' => "{$row->label}: {$version->fileObject->original_name}",
            ]);
        });

        return response()->json($this->detail($order), 201);
    }

    /** Enlace temporal para ver la foto de evidencia de un ítem. */
    public function evidenceUrl(string $workOrder, string $item, Request $request)
    {
        $order = $this->find($workOrder);
        abort_unless(Str::isUuid($item), 404);
        $row = $order->checklistItems()->whereKey($item)->firstOrFail();
        $url = $row->evidence ? DocumentStore::downloadUrl($row->evidence) : null;
        abort_unless($url, 404);

        $this->audit($request, $order, 'work_order.checklist.evidence_download', ['document_id' => $row->evidence_document_id, 'note' => $row->label]);

        return response()->json(['url' => $url, 'expires_in_minutes' => 5]);
    }

    public function approveChecklist(string $workOrder, Request $request)
    {
        $order = $this->find($workOrder);
        $this->assertChecklistCompleted($order);

        if ($order->status === 'CANCELLED') {
            throw ValidationException::withMessages(['status' => ['No se aprueba el checklist de una orden cancelada.']]);
        }
        if ($order->assigned_user_id === $request->user()->id && TenantSettingsController::settings($request->attributes->get('tenant_id'))['segregation']) {
            throw ValidationException::withMessages(['checklist' => ['No puedes aprobar el checklist de una orden que ejecutas tú: lo aprueba otra persona.']]);
        }

        DB::transaction(function () use ($request, $order) {
            $order->update([
                'checklist_status' => 'APPROVED', 'checklist_reviewed_by' => $request->user()->id,
                'checklist_reviewed_at' => now(), 'checklist_review_note' => null,
            ]);
            $this->audit($request, $order, 'work_order.checklist.approve');
        });

        return response()->json($this->detail($order));
    }

    /** Devuelve el checklist al técnico, con motivo: vuelve a "En curso". */
    public function returnChecklist(string $workOrder, Request $request)
    {
        $order = $this->find($workOrder);
        $this->assertChecklistCompleted($order);
        $this->assertOpen($order, 'devolver el checklist de');

        $reason = trim($request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Debes indicar un motivo.'],
        )['reason']);

        DB::transaction(function () use ($request, $order, $reason) {
            $order->update([
                'checklist_status' => 'IN_PROGRESS', 'checklist_reviewed_by' => $request->user()->id,
                'checklist_reviewed_at' => now(), 'checklist_review_note' => $reason,
            ]);
            $this->audit($request, $order, 'work_order.checklist.return', ['note' => $reason]);
        });

        return response()->json($this->detail($order));
    }

    private function find(string $workOrder): WorkOrder
    {
        abort_unless(Str::isUuid($workOrder), 404);

        return WorkOrder::findOrFail($workOrder);
    }

    /** Con lo que la tabla y el tablero muestran en cada fila, para no pedir nada fila por fila. */
    private function listing()
    {
        $items = fn () => WorkOrderChecklistItem::query()->selectRaw('count(*)')->whereColumn('work_order_checklist_items.work_order_id', 'work_orders.id');

        return WorkOrder::query()
            ->select('work_orders.*')
            ->addSelect([
                'checklist_total' => $items(),
                'checklist_done' => $items()->where('done', true),
                'checklist_required_pending' => $items()->where('required', true)->where('done', false),
            ])
            ->with('customer.party.person', 'customer.party.organization', 'assignee:id,name');
    }

    private function row(WorkOrder $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->code,
            'title' => $order->title,
            'status' => $order->status,
            'priority' => $order->priority,
            'scheduled_start' => $order->scheduled_start?->toIso8601String(),
            'scheduled_end' => $order->scheduled_end?->toIso8601String(),
            'location' => $order->location,
            'latitude' => $order->latitude !== null ? (float) $order->latitude : null,
            'longitude' => $order->longitude !== null ? (float) $order->longitude : null,
            'customer' => $order->customer,
            'assignee' => $order->assignee ? ['id' => $order->assignee->id, 'name' => $order->assignee->name] : null,
            'checklist' => $order->checklist_status ? [
                'name' => $order->checklist_name,
                'status' => $order->checklist_status,
                'total' => (int) $order->checklist_total,
                'done' => (int) $order->checklist_done,
                'required_pending' => (int) $order->checklist_required_pending,
            ] : null,
            'blocked_reason' => $order->blocked_reason,
            'cancel_reason' => $order->cancel_reason,
            'created_at' => $order->created_at?->toIso8601String(),
            'lock_version' => (int) $order->lock_version,
        ];
    }

    private function detail(WorkOrder $order): array
    {
        $order = $this->listing()
            ->with(
                'service:id,code,name,billing_mode',
                'contract:id,contract_number',
                'contractedService:id,status',
                'creator:id,name',
                'checklistItems.doneBy:id,name',
                'checklistItems.evidence.latestVersion.fileObject',
            )
            ->findOrFail($order->id);

        // Dirección del cliente (su contacto), para el informe en PDF.
        $address = $order->customer
            ? DB::table('contacts')->where('party_id', $order->customer->party_id)->where('contact_type', 'ADDRESS')
                ->orderByDesc('is_primary')->orderBy('created_at')->value('value')
            : null;

        return [
            ...$this->row($order),
            'description' => $order->description,
            'started_at' => $order->started_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'service' => $order->service ? [
                'id' => $order->service->id, 'code' => $order->service->code, 'name' => $order->service->name, 'billing_mode' => $order->service->billing_mode,
            ] : null,
            'contract' => $order->contract ? ['id' => $order->contract->id, 'contract_number' => $order->contract->contract_number] : null,
            'contracted_service' => $order->contractedService ? ['id' => $order->contractedService->id, 'status' => $order->contractedService->status] : null,
            'creator' => $order->creator ? ['id' => $order->creator->id, 'name' => $order->creator->name] : null,
            'checklist_items' => $order->checklistItems->map(fn (WorkOrderChecklistItem $item) => [
                'id' => $item->id,
                'position' => $item->position,
                'label' => $item->label,
                'required' => (bool) $item->required,
                'evidence_required' => (bool) $item->evidence_required,
                'done' => (bool) $item->done,
                'done_by' => $item->doneBy?->name,
                'done_at' => $item->done_at?->toIso8601String(),
                'evidence_note' => $item->evidence_note,
                'evidence' => $item->evidenceSummary(),
            ])->values(),
            'checklist_review' => $order->checklist_reviewed_at ? [
                'by' => $this->userName($order->checklist_reviewed_by),
                'at' => $order->checklist_reviewed_at->toIso8601String(),
                'note' => $order->checklist_review_note,
            ] : null,
            'hours' => (float) DB::table('time_entries')->where('work_order_id', $order->id)->sum('hours'),
            // Es un dato de la orden: quien la ve sabe si ya dejó algo por facturar.
            'billable_event' => ($event = DB::table('billable_events')->where('source_type', 'WORK_ORDER')->where('source_id', $order->id)->first(['id', 'status', 'amount', 'currency']))
                ? ['id' => $event->id, 'status' => $event->status, 'amount' => (float) $event->amount, 'currency' => $event->currency]
                : null,
            'customer_contact' => ['address' => $address],
            'history' => AuditHistory::for('WorkOrder', $order->id),
        ];
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'in:'.implode(',', array_keys(WorkOrder::PRIORITIES))],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date', 'after_or_equal:scheduled_start'],
            'location' => ['nullable', 'string', 'max:255'],
            // Ubicación de la visita, para el mapa y la ruta del técnico: van las dos o ninguna.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function messages(): array
    {
        return [
            'contracted_service_id.required' => 'Elige el servicio contratado.',
            'title.required' => 'El título es obligatorio.',
            'scheduled_end.after_or_equal' => 'El fin no puede ser anterior al inicio.',
        ];
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} inicio y fin programados */
    private function schedule(array $data): array
    {
        $start = ! empty($data['scheduled_start']) ? Carbon::parse($data['scheduled_start']) : null;
        $end = $start && ! empty($data['scheduled_end']) ? Carbon::parse($data['scheduled_end']) : null;

        return [$start, $end];
    }

    private function text(?string $value): ?string
    {
        return trim((string) $value) ?: null;
    }

    private function customerName(?object $customer): ?string
    {
        $party = $customer?->party;

        return $party?->organization?->trade_name ?: $party?->organization?->legal_name
            ?: (trim(($party?->person?->given_names ?? '').' '.($party?->person?->family_name ?? '')) ?: null);
    }

    private function userName(?string $userId): ?string
    {
        return $userId ? DB::table('users')->where('id', $userId)->value('name') : null;
    }

    /** El técnico puede ejecutar órdenes de trabajo en esta empresa y tiene libre ese horario. */
    private function assertAssignable(string $tenantId, string $userId, ?WorkOrder $order, ?Carbon $start, ?Carbon $end): void
    {
        if (! Technicians::includes($tenantId, $userId)) {
            throw ValidationException::withMessages([
                'assigned_user_id' => ['La persona indicada no puede ejecutar órdenes de trabajo en esta empresa.'],
            ]);
        }
        $this->assertFree($userId, $order, $start, $end);
    }

    /**
     * Choque de agenda: el técnico no tiene otra orden asignada o en ejecución que se cruce con
     * este horario. Se mira toda la empresa, no solo lo que alcanza quien asigna.
     */
    private function assertFree(string $userId, ?WorkOrder $order, ?Carbon $start, ?Carbon $end): void
    {
        if (! $start) {
            return;
        }
        $end ??= $start->copy()->addHours(self::DEFAULT_HOURS);

        $clash = WorkOrder::ignoringAccessScope()
            ->when($order, fn ($q) => $q->whereKeyNot($order->id))
            ->where('assigned_user_id', $userId)
            ->whereIn('status', WorkOrder::BUSY)
            ->whereNotNull('scheduled_start')
            ->where('scheduled_start', '<', $end)
            ->whereRaw('COALESCE(scheduled_end, scheduled_start + interval \''.self::DEFAULT_HOURS.' hours\') > ?', [$start])
            ->orderBy('scheduled_start')
            ->first(['id', 'code']);

        if ($clash) {
            // El número de la otra orden solo se dice si quien asigna también la alcanza.
            $which = WorkOrder::whereKey($clash->id)->exists() ? " ({$clash->code})" : '';

            throw ValidationException::withMessages([
                'scheduled_start' => ["El técnico ya tiene otra orden de trabajo en ese horario{$which}."],
            ]);
        }

        // También ocupan su horario los eventos que tiene programados en la agenda.
        if ($event = AgendaClash::event($userId, $start, $end)) {
            throw ValidationException::withMessages([
                'scheduled_start' => ["El técnico ya tiene un evento de agenda en ese horario: {$event}."],
            ]);
        }
    }

    private function assertOpen(WorkOrder $order, string $verb): void
    {
        if (! in_array($order->status, WorkOrder::OPEN, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} una orden de trabajo ".mb_strtolower(WorkOrder::STATES[$order->status] ?? $order->status).'.'],
            ]);
        }
    }

    private function assertChecklistCompleted(WorkOrder $order): void
    {
        if ($order->checklist_status !== 'COMPLETED') {
            throw ValidationException::withMessages([
                'checklist' => [$order->checklist_status === 'APPROVED' ? 'El checklist ya fue aprobado.' : 'El checklist aún no está completado.'],
            ]);
        }
    }

    /** Estado del checklist según sus ítems: completado cuando no queda ningún obligatorio pendiente. */
    private function checklistStatus(WorkOrder $order): string
    {
        $items = $order->checklistItems()->get(['required', 'done']);
        $required = $items->where('required', true);
        $complete = $required->isNotEmpty() ? $required->every('done', true) : $items->every('done', true);

        return match (true) {
            $items->isNotEmpty() && $complete => 'COMPLETED',
            $items->contains('done', true) => 'IN_PROGRESS',
            default => 'PENDING',
        };
    }

    private function transition(Request $request, WorkOrder $order, array $from, string $to, string $verb, string $action, ?string $reasonColumn = null)
    {
        if (! in_array($order->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} una orden de trabajo ".mb_strtolower(WorkOrder::STATES[$order->status] ?? $order->status).'.'],
            ]);
        }

        $reason = null;
        if ($reasonColumn) {
            $reason = trim($request->validate(
                ['reason' => ['required', 'string', 'max:1000']],
                ['reason.required' => 'Debes indicar un motivo.'],
            )['reason']);
        }

        DB::transaction(function () use ($request, $order, $to, $action, $reason, $reasonColumn) {
            $previous = $order->status;
            $order->update([
                'status' => $to,
                ...($reasonColumn ? [$reasonColumn => $reason] : []),
                ...($to === 'IN_PROGRESS' ? ['blocked_reason' => null, 'started_at' => $order->started_at ?? now()] : []),
                ...($to === 'COMPLETED' ? ['completed_at' => now()] : []),
            ]);
            $this->audit($request, $order, $action, array_filter(['from' => $previous, 'to' => $to, 'note' => $reason]));

            // Un servicio que se cobra por evento deja lo ejecutado listo para facturar.
            if ($to === 'COMPLETED') {
                Billing::eventFromWorkOrder($order, $request);
            }
        });

        return response()->json($this->detail($order));
    }

    private function audit(Request $request, WorkOrder $order, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'WorkOrder',
            entityId: $order->id,
            metadata: $metadata,
        );
    }
}
