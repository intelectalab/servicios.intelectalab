<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Services\Http\Requests\StoreServiceRequest;
use App\Modules\Services\Models\Service;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $service)
    {
        $service = Service::findOrFail($service);

        return response()->json($service);
    }

    public function store(StoreServiceRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $service = Service::create([
            'tenant_id' => $tenantId,
            'category_id' => $request->category_id,
            'code' => $request->code,
            'name' => $request->name,
            'description' => $request->description,
            'unit' => $request->unit,
            'status' => 'DRAFT',
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'service.create',
            entityType: 'Service',
            entityId: $service->id,
        );

        return response()->json($service, 201);
    }

    public function activate(string $service, Request $request)
    {
        $service = Service::findOrFail($service);

        $service->update(['status' => 'ACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'service.activate',
            entityType: 'Service',
            entityId: $service->id,
        );

        return response()->json($service);
    }
}