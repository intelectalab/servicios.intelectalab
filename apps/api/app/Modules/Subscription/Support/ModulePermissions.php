<?php

namespace App\Modules\Subscription\Support;

use Illuminate\Support\Facades\DB;

class ModulePermissions
{
    /**
     * Permisos de administración del propio tenant; no dependen de ningún módulo. Aprobar sobre
     * el límite de crédito tampoco: el límite vive en la ficha del cliente.
     */
    public const BASE = ['users.view', 'users.manage', 'users.export', 'tenant.manage', 'audit.view', 'audit.export', 'credit.approve'];

    /**
     * Códigos de permiso para el administrador de un tenant con esos módulos:
     * los de administración, más todos los permisos de la tabla `permissions`
     * cuyo prefijo el catálogo (module_catalog.permission_prefixes) asocia a los
     * módulos obligatorios y a los indicados: view, manage y los de acción
     * (p. ej. quotations.approve).
     */
    public static function codesFor(iterable $moduleCodes): array
    {
        $requested = collect($moduleCodes)->map(fn (string $code) => strtoupper($code))->all();

        $prefixes = DB::table('module_catalog')
            ->where('active', true)
            ->where(fn ($query) => $query->where('is_core', true)->orWhereIn('code', $requested))
            ->pluck('permission_prefixes')
            ->flatMap(fn ($json) => json_decode($json, true) ?: []);

        $codes = self::BASE;

        $prefixes = $prefixes->unique()->values();

        if ($prefixes->isNotEmpty()) {
            $codes = array_merge($codes, DB::table('permissions')
                ->where(function ($query) use ($prefixes) {
                    foreach ($prefixes as $prefix) {
                        $query->orWhere('code', 'like', "{$prefix}.%");
                    }
                })
                ->orderBy('code')
                ->pluck('code')
                ->all());
        }

        return array_values(array_unique($codes));
    }
}
