<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Http\Requests\StoreResourceRequest;
use App\Modules\Operations\Models\Resource;
use App\Modules\Operations\Models\ResourceAssignment;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    public function index(Request $request)
    {
        $query = Resource::query()
            ->when($request->filled('resource_type'), fn ($q) => $q->where('resource_type', $request->query('resource_type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreResourceRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $resource = Resource::create([
            'tenant_id' => $tenantId,
            'code' => $request->code,
            'name' => $request->name,
            'resource_type' => $request->resource_type,
            'status' => 'ACTIVE',
            'cost_rate' => $request->cost_rate ?? 0,
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'resource.create',
            entityType: 'Resource',
            entityId: $resource->id,
        );

        return response()->json($resource, 201);
    }

    public function assign(string $resource, Request $request)
    {
        $request->validate([
            'work_order_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $resourceModel = Resource::findOrFail($resource);

        $assignment = ResourceAssignment::create([
            'tenant_id' => $tenantId,
            'resource_id' => $resourceModel->id,
            'work_order_id' => $request->work_order_id,
            'project_id' => $request->project_id,
            'start_at' => $request->start_at,
            'end_at' => $request->end_at,
            'quantity' => $request->quantity ?? 1,
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'resource.assign',
            entityType: 'ResourceAssignment',
            entityId: $assignment->id,
        );

        return response()->json($assignment, 201);
    }
}