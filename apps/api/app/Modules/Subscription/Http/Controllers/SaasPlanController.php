<?php

namespace App\Modules\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscription\Models\SaasPlan;
use Illuminate\Http\JsonResponse;

class SaasPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = SaasPlan::where('active', true)
            ->where('is_public', true)
            ->orderBy('display_order', 'asc')
            ->get();

        return response()->json($plans);
    }

    // Listar TODOS los planes para el panel de SuperAdmin (incluyendo inactivos)
    public function adminIndex(): JsonResponse
    {
        $plans = SaasPlan::orderBy('display_order', 'asc')->get();
        return response()->json($plans);
    }

    // Crear un nuevo plan SaaS
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:saas_plans,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'monthly_price' => 'required|numeric|min:0',
            'annual_price' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'has_trial' => 'boolean',
            'trial_days' => 'nullable|integer|min:0',
            'active' => 'boolean',
            'is_public' => 'boolean',
            'display_order' => 'integer',
            'limits' => 'nullable|array',
            'modules' => 'nullable|array',
        ]);

        $plan = SaasPlan::create($validated);

        return response()->json([
            'message' => 'Plan SaaS creado exitosamente.',
            'plan' => $plan,
        ], 201);
    }

    // Actualizar un plan SaaS existente
    public function update(Request $request, $id): JsonResponse
    {
        $plan = SaasPlan::findOrFail($id);

        $validated = $request->validate([
            'code' => 'sometimes|string|unique:saas_plans,code,' . $id,
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'monthly_price' => 'sometimes|numeric|min:0',
            'annual_price' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'has_trial' => 'boolean',
            'trial_days' => 'nullable|integer|min:0',
            'active' => 'boolean',
            'is_public' => 'boolean',
            'display_order' => 'integer',
            'limits' => 'nullable|array',
            'modules' => 'nullable|array',
        ]);

        $plan->update($validated);

        return response()->json([
            'message' => 'Plan SaaS actualizado exitosamente.',
            'plan' => $plan,
        ]);
    }

    // Eliminar o desactivar un plan
    public function destroy($id): JsonResponse
    {
        $plan = SaasPlan::findOrFail($id);
        
        $plan->update(['active' => false]);

        return response()->json([
            'message' => 'Plan SaaS eliminado correctamente.',
        ]);
    }
}