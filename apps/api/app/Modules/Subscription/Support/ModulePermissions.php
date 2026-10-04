<?php

namespace App\Modules\Subscription\Support;

class ModulePermissions
{
    /**
     * Permisos base para que el administrador del tenant pueda ver el core
     * (clientes, proveedores, empleados) y gestionar su tenant.
     */
    public const BASE = ['core.view', 'core.manage', 'users.view', 'users.manage', 'tenant.manage'];

    /**
     * Relaciona cada módulo del plan con los prefijos de permisos que exigen
     * las rutas (tabla 'permissions').
     */
    public const MAP = [
        'SERVICIOS' => ['services'],
        'COTIZACIONES' => ['quotations'],
        'CONTRATOS' => ['contracts'],
        'ORDENES_TRABAJO' => ['operations'],
        'TAREAS' => ['operations'],
        'CHECKLISTS' => ['operations'],
        'RECURSOS' => ['operations'],
        'TIMESHEETS' => ['operations'],
        'DOCUMENTOS' => ['documents'],
        'FINANZAS' => ['billing', 'finance'],
        'FACTURACION_ELECTRONICA' => ['billing'],
        'PAGOS_COBRANZAS' => ['billing'],
        'SOPORTE' => ['support'],
        'TICKETS' => ['support'],
        'CRM' => ['crm'],
    ];

    /**
     * Códigos de permiso (base + view/manage de cada módulo habilitado).
     */
    public static function codesFor(iterable $moduleCodes): array
    {
        $codes = self::BASE;

        foreach ($moduleCodes as $moduleCode) {
            foreach (self::MAP[strtoupper($moduleCode)] ?? [] as $prefix) {
                $codes[] = "{$prefix}.view";
                $codes[] = "{$prefix}.manage";
            }
        }

        return array_values(array_unique($codes));
    }
}
