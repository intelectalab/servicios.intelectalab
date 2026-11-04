<?php

namespace App\Modules\Subscription\Support;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Subscription\Models\SaasPlan;
use App\Modules\Subscription\Models\TenantModule;
use App\Modules\Subscription\Models\TenantSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alta de una empresa: tenant, empresa (datos fiscales), sede principal si se
 * indica, administrador con el rol ADMIN de sistema, suscripción, módulos del plan
 * con sus permisos y los roles base (BaseRoles). Lo usan el registro público y "Aprovisionar tenant"
 * de la consola. Debe llamarse dentro de una transacción.
 */
class TenantProvisioner
{
    /**
     * @param  array{name: string, legal_name: string, trade_name?: ?string, ruc: string, address?: array,
     *               admin_name: string, admin_email: string, password_hash: string, plan: SaasPlan,
     *               billing_period: string, trial: bool, status: string, branch?: ?string, modules?: ?array} $data
     * @return array{tenant: Tenant, user: User, plan: SaasPlan, hasTrial: bool, endsAt: Carbon, modules: string[]}
     */
    public static function create(array $data): array
    {
        $tenant = Tenant::create([
            'code' => 'EMP-'.strtoupper(substr(uniqid(), -6)),
            'name' => $data['name'],
            'legal_name' => $data['legal_name'],
            'status' => $data['status'],
            'timezone' => 'America/Lima',
            'locale' => 'es-PE',
            'currency' => 'PEN',
            'configuration' => [],
            // Enlace de ingreso con su marca (/e/…), a partir del nombre; se puede cambiar en Marca.
            'login_slug' => \App\Services\LoginSlug::forName($data['name']),
        ]);

        // Las tablas de la empresa están bajo RLS: se entra a su contexto.
        DB::statement("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenant->id]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'code' => 'COMP-01',
            'legal_name' => $data['legal_name'],
            'trade_name' => $data['trade_name'] ?: $data['name'],
            'tax_identifier' => $data['ruc'],
            'tax_country' => 'PE',
            'status' => 'ACTIVE',
            'address' => $data['address'] ?? [],
            'configuration' => [],
        ]);

        $branchId = null;
        if (! empty($data['branch'])) {
            $branchId = DB::table('branches')->insertGetId([
                'tenant_id' => $tenant->id, 'company_id' => $company->id, 'code' => 'PRINCIPAL', 'name' => $data['branch'],
                'created_at' => now(), 'updated_at' => now(),
            ], 'id');
        }

        $user = User::create([
            'email' => $data['admin_email'],
            'password_hash' => $data['password_hash'],
            'name' => $data['admin_name'],
            'status' => 'ACTIVE',
            'mfa_enabled' => false,
        ]);

        TenantUser::create(array_filter([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'default_branch_id' => $branchId,
        ]));

        $roleId = DB::table('roles')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'ADMIN',
            'name' => 'Administrador',
            'description' => 'Administrador general del tenant según plan',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        DB::table('user_roles')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'scope' => 'TENANT',
            'created_at' => now(),
        ]);

        /** @var SaasPlan $plan */
        $plan = $data['plan'];
        $modules = $data['modules'] ?? collect($plan->modules ?? [])->filter()->keys()->map(fn ($code) => strtoupper($code))->all();

        // Primero la suscripción: la base solo deja encender los módulos que incluye el plan vigente.
        $hasTrial = $data['trial'];
        $trialDays = $hasTrial ? ($plan->trial_days ?? 30) : 0;
        $endsAt = $trialDays > 0 ? now()->addDays($trialDays) : ($data['billing_period'] === 'ANNUAL' ? now()->addYear() : now()->addMonth());

        TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'ACTIVE',
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'configuration' => [
                'billing_period' => $data['billing_period'],
                'is_trial' => $hasTrial,
                'trial_days' => $trialDays,
            ],
        ]);

        foreach ($modules as $code) {
            TenantModule::create([
                'tenant_id' => $tenant->id,
                'module_code' => strtoupper($code),
                'enabled' => true,
                'activated_at' => now(),
                'configuration' => [],
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('code', ModulePermissions::codesFor($modules))->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        // Roles base del kit (Gerencia, Ventas, Operaciones, Técnico, Finanzas, Soporte) con lo de sus módulos.
        BaseRoles::create((string) $tenant->id, $modules);

        return compact('tenant', 'user', 'plan', 'hasTrial', 'endsAt', 'modules');
    }
}
