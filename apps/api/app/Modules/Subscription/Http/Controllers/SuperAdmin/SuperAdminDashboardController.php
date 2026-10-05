<?php

namespace App\Modules\Subscription\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Tenant;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Modules\Subscription\Models\SaasPlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminDashboardController extends Controller
{
    // tenant_subscriptions y companies están bajo RLS; el superadmin no tiene
    // tenant, así que se leen con las funciones superadmin_* (SECURITY DEFINER).

    public function metrics(Request $request)
    {
        $userId = $request->user()->id;

        $activePlansCount = SaasPlan::where('active', true)->count();
        $totalTenants = Tenant::count();

        // Tenants en prueba (trial) activos
        $inTrialCount = DB::selectOne(
            "SELECT count(*) AS total
             FROM superadmin_tenant_subscriptions(?)
             WHERE status = 'ACTIVE' AND configuration @> '{\"is_trial\": true}'",
            [$userId]
        )->total;

        // Cálculo estimado de ingresos mensuales (suma de precios de suscripciones activas)
        // Puedes adaptarlo a tu lógica de montos reales por plan
        $monthlyRevenue = DB::selectOne(
            "SELECT COALESCE(SUM(p.monthly_price), 0) AS total
             FROM superadmin_tenant_subscriptions(?) ts
             JOIN saas_plans p ON p.id = ts.plan_id
             WHERE ts.status = 'ACTIVE'",
            [$userId]
        )->total;

        return response()->json([
            'active_plans' => $activePlansCount,
            'active_tenants' => $totalTenants,
            'in_trial' => (int) $inTrialCount,
            'monthly_revenue' => (float) $monthlyRevenue,
        ]);
    }

    public function tenants(Request $request)
    {
        $userId = $request->user()->id;

        $subscriptions = TenantSubscription::hydrate(
            DB::select('SELECT * FROM superadmin_tenant_subscriptions(?)', [$userId])
        )->load('plan')->groupBy('tenant_id');

        $companies = Company::hydrate(
            DB::select('SELECT * FROM superadmin_companies(?)', [$userId])
        )->groupBy('tenant_id');

        $tenants = Tenant::orderByDesc('created_at')->get()->each(function (Tenant $tenant) use ($subscriptions, $companies) {
            $tenant->setRelation('subscriptions', $subscriptions->get($tenant->id, new Collection()));
            $tenant->setRelation('companies', $companies->get($tenant->id, new Collection()));
        });

        return response()->json($tenants);
    }

    public function audit(Request $request)
    {
        $logs = DB::table('platform_audit_logs')
            ->leftJoin('users', 'users.id', '=', 'platform_audit_logs.user_id')
            ->orderByDesc('platform_audit_logs.created_at')
            ->select('platform_audit_logs.*', 'users.name as user_name', 'users.email as user_email')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return response()->json($logs);
    }
}
