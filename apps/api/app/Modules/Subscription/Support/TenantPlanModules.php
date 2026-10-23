<?php

namespace App\Modules\Subscription\Support;

use App\Modules\Subscription\Models\SaasPlan;
use Illuminate\Support\Facades\DB;

/**
 * Deja los módulos de una empresa como los de su plan: enciende los que el plan
 * incluye y apaga los demás (los obligatorios no se tocan). Apagar no borra
 * datos: la fila queda con enabled = false. Al rol ADMIN de sistema le agrega
 * los permisos de los módulos encendidos. Requiere estar dentro de una
 * transacción con app.tenant_id de esa empresa.
 */
class TenantPlanModules
{
    /** @return array{enabled: string[], disabled: string[]} */
    public static function apply(string $tenantId, SaasPlan $plan): array
    {
        $core = ModuleCatalog::coreCodes();
        $wanted = collect(ModuleCatalog::normalizePlanModules($plan->modules ?? []))->keys()
            ->reject(fn (string $code) => in_array($code, $core, true))->values();

        $rows = DB::table('tenant_modules')->where('tenant_id', $tenantId)->get(['id', 'module_code', 'enabled'])
            ->keyBy(fn ($row) => strtoupper($row->module_code));

        $enabled = [];
        foreach ($wanted as $code) {
            $row = $rows->get($code);
            if (! $row) {
                DB::table('tenant_modules')->insert([
                    'tenant_id' => $tenantId, 'module_code' => $code, 'enabled' => true, 'activated_at' => now(),
                    'configuration' => '{}', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $enabled[] = $code;
            } elseif (! $row->enabled) {
                DB::table('tenant_modules')->where('id', $row->id)->update(['enabled' => true, 'activated_at' => now(), 'deactivated_at' => null]);
                $enabled[] = $code;
            }
        }

        $disabled = [];
        foreach ($rows as $code => $row) {
            if ($row->enabled && ! $wanted->contains($code) && ! in_array($code, $core, true)) {
                DB::table('tenant_modules')->where('id', $row->id)->update(['enabled' => false, 'deactivated_at' => now()]);
                $disabled[] = $code;
            }
        }

        self::grantAdminPermissions($tenantId, $wanted->all());

        return ['enabled' => $enabled, 'disabled' => $disabled];
    }

    /** Agrega al rol ADMIN de sistema los permisos que le faltan para esos módulos (como tenants:sync-permissions). */
    private static function grantAdminPermissions(string $tenantId, array $modules): void
    {
        $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', 'ADMIN')->where('is_system', true)->value('id');
        if (! $roleId) {
            return;
        }

        $current = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_id')->all();
        $missing = DB::table('permissions')->whereIn('code', ModulePermissions::codesFor($modules))
            ->whereNotIn('id', $current)->pluck('id');

        foreach ($missing as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }
}
