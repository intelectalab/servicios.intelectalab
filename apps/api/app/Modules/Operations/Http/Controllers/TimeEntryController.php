<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Http\Requests\StoreTimeEntryRequest;
use App\Modules\Operations\Models\TimeEntry;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TimeEntryController extends Controller
{
    public function index(Request $request)
    {
        $query = TimeEntry::query()
            ->when($request->filled('work_order_id'), fn ($q) => $q->where('work_order_id', $request->query('work_order_id')))
            ->when($request->filled('approved'), fn ($q) => $q->where('approved', $request->boolean('approved')));

        return response()->json($query->orderByDesc('started_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreTimeEntryRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $startedAt = Carbon::parse($request->started_at);
        $endedAt = Carbon::parse($request->ended_at);
        $hours = round($startedAt->floatDiffInHours($endedAt), 4);

        $timeEntry = TimeEntry::create([
            'tenant_id' => $tenantId,
            'user_id' => $request->user()->id,
            'work_order_id' => $request->work_order_id,
            'project_id' => $request->project_id,
            'task_id' => $request->task_id,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'hours' => $hours,
            'billable' => $request->boolean('billable', true),
            'hourly_cost' => $request->hourly_cost ?? 0,
            'approved' => false,
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'time_entry.create',
            entityType: 'TimeEntry',
            entityId: $timeEntry->id,
            metadata: ['hours' => (string) $hours],
        );

        return response()->json($timeEntry, 201);
    }

    public function approve(string $timeEntry, Request $request)
    {
        $timeEntry = TimeEntry::findOrFail($timeEntry);

        if ($timeEntry->approved) {
            throw ValidationException::withMessages([
                'approved' => ['Este registro de horas ya está aprobado.'],
            ]);
        }

        $timeEntry->update(['approved' => true]);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'time_entry.approve',
            entityType: 'TimeEntry',
            entityId: $timeEntry->id,
        );

        return response()->json($timeEntry);
    }
}