<?php

namespace App\Modules\Subscription\Support;

use Illuminate\Support\Facades\DB;

class TenantModules
{
    /**
     * Códigos de los módulos que el tenant puede usar: los obligatorios del
     * catálogo (is_core) más los que tiene encendidos en tenant_modules.
     * Requiere app.tenant_id ya establecido (tenant_modules está bajo RLS).
     *
     * @return string[]
     */
    public static function enabledCodes(string $tenantId): array
    {
        $core = DB::table('module_catalog')
            ->where('active', true)
            ->where('is_core', true)
            ->pluck('code');

        $contracted = DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->pluck('module_code')
            ->map(fn (string $code) => strtoupper($code));

        return $core->merge($contracted)->unique()->values()->all();
    }

    public static function isEnabled(string $tenantId, string $moduleCode): bool
    {
        return in_array(strtoupper($moduleCode), self::enabledCodes($tenantId), true);
    }
}
