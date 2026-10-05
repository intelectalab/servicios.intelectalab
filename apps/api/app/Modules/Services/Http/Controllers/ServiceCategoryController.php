<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Services\Models\ServiceCategory;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceCategoryController extends Controller
{
    public function index()
    {
        return response()->json(
            ServiceCategory::where('active', true)->orderBy('name')->get(['id', 'name'])
        );
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);
        $name = trim($data['name']);

        if (ServiceCategory::whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages([
                'name' => ['Ya existe una categoría con este nombre.'],
            ]);
        }

        $category = ServiceCategory::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'active' => true,
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'service_category.create',
            entityType: 'ServiceCategory',
            entityId: $category->id,
        );

        return response()->json($category->only(['id', 'name']), 201);
    }
}
