<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Http\Requests\StoreTaskRequest;
use App\Modules\Operations\Models\Checklist;
use App\Modules\Operations\Models\Task;
use App\Modules\Operations\Models\TaskChecklist;
use App\Modules\Operations\Models\TaskChecklistItem;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $query = Task::query()
            ->when($request->filled('work_order_id'), fn ($q) => $q->where('work_order_id', $request->query('work_order_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $task)
    {
        $task = Task::with('taskChecklists.items.checklistItem', 'taskChecklists.checklist')->findOrFail($task);

        return response()->json($task);
    }

    public function store(StoreTaskRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $task = DB::transaction(function () use ($request, $tenantId) {
            $task = Task::create([
                'tenant_id' => $tenantId,
                'work_order_id' => $request->work_order_id,
                'project_id' => $request->project_id,
                'title' => $request->title,
                'description' => $request->description,
                'status' => 'TODO',
                'priority' => $request->priority ?? 'NORMAL',
                'assigned_user_id' => $request->assigned_user_id,
                'due_at' => $request->due_at,
            ]);

            // Si se indicó un checklist plantilla, instanciarlo para esta tarea.
            if ($request->filled('checklist_id')) {
                $checklist = Checklist::with('items')->findOrFail($request->checklist_id);

                $taskChecklist = TaskChecklist::create([
                    'tenant_id' => $tenantId,
                    'task_id' => $task->id,
                    'checklist_id' => $checklist->id,
                    'status' => 'PENDING',
                ]);

                foreach ($checklist->items as $checklistItem) {
                    TaskChecklistItem::create([
                        'tenant_id' => $tenantId,
                        'task_checklist_id' => $taskChecklist->id,
                        'checklist_item_id' => $checklistItem->id,
                        'completed' => false,
                    ]);
                }
            }

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'task.create',
                entityType: 'Task',
                entityId: $task->id,
            );

            return $task;
        });

        return response()->json($task->load('taskChecklists.items.checklistItem'), 201);
    }

    /**
     * Completa (o descompleta) un ítem específico del checklist de una tarea.
     */
    public function completeChecklistItem(string $task, string $item, Request $request)
    {
        $request->validate(['completed' => ['required', 'boolean']]);

        $taskChecklistItem = TaskChecklistItem::whereHas('taskChecklist', function ($q) use ($task) {
                $q->where('task_id', $task);
            })
            ->findOrFail($item);

        $taskChecklistItem->update([
            'completed' => $request->boolean('completed'),
            'completed_by' => $request->boolean('completed') ? $request->user()->id : null,
            'completed_at' => $request->boolean('completed') ? now() : null,
        ]);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $request->boolean('completed') ? 'checklist_item.complete' : 'checklist_item.uncomplete',
            entityType: 'TaskChecklistItem',
            entityId: $taskChecklistItem->id,
        );

        return response()->json($taskChecklistItem->load('checklistItem'));
    }
}