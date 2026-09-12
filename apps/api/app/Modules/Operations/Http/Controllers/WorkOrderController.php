<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Http\Requests\StoreWorkOrderRequest;
use App\Modules\Operations\Models\TaskChecklistItem;
use App\Modules\Operations\Models\WorkOrder;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = WorkOrder::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $workOrder)
    {
        $workOrder = WorkOrder::with('tasks')->findOrFail($workOrder);

        return response()->json($workOrder);
    }

    public function store(StoreWorkOrderRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $workOrder = DB::transaction(function () use ($request, $tenantId) {
            $code = SequentialNumberGenerator::next($tenantId, 'work_orders', 'code', 'OT');

            $workOrder = WorkOrder::create([
                'tenant_id' => $tenantId,
                'customer_id' => $request->customer_id,
                'contracted_service_id' => $request->contracted_service_id,
                'code' => $code,
                'status' => 'PLANNED',
                'priority' => $request->priority ?? 'NORMAL',
                'scheduled_start' => $request->scheduled_start,
                'scheduled_end' => $request->scheduled_end,
                'description' => $request->description,
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'work_order.create',
                entityType: 'WorkOrder',
                entityId: $workOrder->id,
                metadata: ['code' => $code],
            );

            return $workOrder;
        });

        return response()->json($workOrder, 201);
    }

    public function assign(string $workOrder, Request $request)
    {
        $request->validate(['assigned_user_id' => ['required', 'uuid']]);

        $workOrder = WorkOrder::findOrFail($workOrder);

        $workOrder->update([
            'assigned_user_id' => $request->assigned_user_id,
            'status' => 'ASSIGNED',
        ]);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'work_order.assign',
            entityType: 'WorkOrder',
            entityId: $workOrder->id,
            metadata: ['assigned_user_id' => $request->assigned_user_id],
        );

        return response()->json($workOrder);
    }

    public function start(string $workOrder, Request $request)
    {
        $workOrder = WorkOrder::findOrFail($workOrder);

        if ($workOrder->status !== 'ASSIGNED') {
            throw ValidationException::withMessages([
                'status' => ["No se puede iniciar una orden en estado {$workOrder->status}. Debe estar ASSIGNED."],
            ]);
        }

        $workOrder->update(['status' => 'IN_PROGRESS']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'work_order.start',
            entityType: 'WorkOrder',
            entityId: $workOrder->id,
        );

        return response()->json($workOrder);
    }

    /**
     * Regla dura: no se puede completar la orden si hay ítems de checklist
     * OBLIGATORIOS pendientes en cualquiera de sus tareas.
     */
    public function complete(string $workOrder, Request $request)
    {
        $workOrder = WorkOrder::with('tasks.taskChecklists.items.checklistItem')->findOrFail($workOrder);

        if ($workOrder->status !== 'IN_PROGRESS') {
            throw ValidationException::withMessages([
                'status' => ["No se puede completar una orden en estado {$workOrder->status}. Debe estar IN_PROGRESS."],
            ]);
        }

        $pendingRequired = [];

        foreach ($workOrder->tasks as $task) {
            foreach ($task->taskChecklists as $taskChecklist) {
                foreach ($taskChecklist->items as $item) {
                    if ($item->checklistItem->required && ! $item->completed) {
                        $pendingRequired[] = $item->checklistItem->label;
                    }
                }
            }
        }

        if (count($pendingRequired) > 0) {
            throw ValidationException::withMessages([
                'checklist' => [
                    'No se puede cerrar la orden: hay ítems obligatorios pendientes.',
                    ...$pendingRequired,
                ],
            ]);
        }

        $workOrder->update(['status' => 'COMPLETED']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'work_order.complete',
            entityType: 'WorkOrder',
            entityId: $workOrder->id,
        );

        return response()->json($workOrder);
    }
}