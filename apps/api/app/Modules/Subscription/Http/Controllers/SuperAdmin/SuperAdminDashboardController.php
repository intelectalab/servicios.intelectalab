<?php

namespace App\Modules\Subscription\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Modules\Subscription\Models\SaasPlan;
use Illuminate\Http\Request;

class SuperAdminDashboardController extends Controller
{
    public function metrics()
    {
        $activePlansCount = SaasPlan::where('active', true)->count();
        $totalTenants = Tenant::count();
        
        // Tenants en prueba (trial) activos
        $inTrialCount = TenantSubscription::where('status', 'ACTIVE')
            ->whereJsonContains('configuration->is_trial', true)
            ->count();

        // Cálculo estimado de ingresos mensuales (suma de precios de suscripciones activas)
        // Puedes adaptarlo a tu lógica de montos reales por plan
        $monthlyRevenue = TenantSubscription::where('status', 'ACTIVE')
            ->join('saas_plans', 'tenant_subscriptions.plan_id', '=', 'saas_plans.id')
            ->sum('saas_plans.monthly_price');

        return response()->json([
            'active_plans' => $activePlansCount,
            'active_tenants' => $totalTenants,
            'in_trial' => $inTrialCount,
            'monthly_revenue' => $monthlyRevenue,
        ]);
    }

    public function tenants()
    {
        $tenants = Tenant::with(['subscriptions.plan', 'companies'])->get();
        return response()->json($tenants);
    }
}