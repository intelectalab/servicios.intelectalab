<?php

namespace App\Modules\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscription\Models\SaasPlan;
use App\Modules\Subscription\Support\ModuleCatalog;
use App\Services\PlatformAuditLogger;
use Illuminate\Http\Request;
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

    public function adminIndex(): JsonResponse
    {
        $plans = SaasPlan::orderBy('display_order', 'asc')->get();
        return response()->json($plans);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:saas_plans,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'monthly_price' => 'nullable|numeric|min:0',
            'annual_price' => 'nullable|numeric|min:0',
            'currency' => 'required|string|size:3',
            'has_trial' => 'boolean',
            'trial_days' => 'nullable|integer|min:0',
            'requires_payment_upfront' => 'boolean',
            'auto_renew_default' => 'boolean',
            'default_billing_period' => 'string|in:MONTHLY,ANNUAL',
            'active' => 'boolean',
            'is_public' => 'boolean',
            'display_order' => 'integer',
            'limits' => 'nullable|array',
            'modules' => 'nullable|array',
            'consumption' => 'nullable|array',
            'features' => 'nullable|array',
            'support_level' => 'string',
            'support_tickets_month' => 'nullable|integer',
            'support_sla_hours' => 'nullable|integer',
            'support_channels' => 'nullable|array',
        ]);

        // Solo módulos del catálogo, con los obligatorios siempre incluidos.
        $validated['modules'] = ModuleCatalog::normalizePlanModules($validated['modules'] ?? []);
        $plan = SaasPlan::create($validated);

        PlatformAuditLogger::log($request->user()->id, 'plan.created', 'SaasPlan', $plan->id, afterData: $plan->toArray());

        return response()->json([
            'message' => 'Plan SaaS creado exitosamente.',
            'plan' => $plan,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $plan = SaasPlan::findOrFail($id);

        $validated = $request->validate([
            'code' => 'sometimes|string|unique:saas_plans,code,' . $id,
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'monthly_price' => 'sometimes|nullable|numeric|min:0',
            'annual_price' => 'sometimes|nullable|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'has_trial' => 'boolean',
            'trial_days' => 'nullable|integer|min:0',
            'requires_payment_upfront' => 'boolean',
            'auto_renew_default' => 'boolean',
            'default_billing_period' => 'string|in:MONTHLY,ANNUAL',
            'active' => 'boolean',
            'is_public' => 'boolean',
            'display_order' => 'integer',
            'limits' => 'nullable|array',
            'modules' => 'nullable|array',
            'consumption' => 'nullable|array',
            'features' => 'nullable|array',
            'support_level' => 'string',
            'support_tickets_month' => 'nullable|integer',
            'support_sla_hours' => 'nullable|integer',
            'support_channels' => 'nullable|array',
        ]);

        if (array_key_exists('modules', $validated)) {
            $validated['modules'] = ModuleCatalog::normalizePlanModules($validated['modules']);
        }

        $before = $plan->toArray();
        $plan->update($validated);

        PlatformAuditLogger::log($request->user()->id, 'plan.updated', 'SaasPlan', $plan->id, $before, $plan->fresh()->toArray());

        return response()->json([
            'message' => 'Plan SaaS actualizado exitosamente.',
            'plan' => $plan,
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $plan = SaasPlan::findOrFail($id);
        $plan->update(['active' => false]);

        PlatformAuditLogger::log($request->user()->id, 'plan.deactivated', 'SaasPlan', $plan->id);

        return response()->json([
            'message' => 'Plan SaaS desactivado correctamente.',
        ]);
    }
}