<?php

namespace App\Modules\Subscription\Support;

use Illuminate\Support\Facades\DB;

/**
 * Roles base con los que nace una empresa, además de su Administrador: Gerencia, Ventas,
 * Operaciones, Técnico, Finanzas y Soporte, según la matriz rol × recurso del kit
 * (docs/06-PERMISOS.md). Quedan como roles normales: la empresa los edita o borra en
 * Roles y permisos.
 *
 * Cada celda es `acciones:ALCANCE` con las letras del kit (v ver, c crear, e editar, d eliminar,
 * a aprobar, x exportar, u usar, r revelar). Se traduce a los permisos del sistema (editar es
 * `manage`; crear, `create` si el recurso lo tiene o `manage` si no) y solo se dan los permisos
 * de los módulos que la empresa tiene. El alcance se guarda en role_scopes para los recursos
 * que lo usan.
 */
class BaseRoles
{
    /** Código => [nombre, descripción]. */
    public const ROLES = [
        'MANAGER' => ['Gerencia', 'Visión completa de la empresa; aprueba cotizaciones, contratos, horas, costos y cierres.'],
        'SALES' => ['Ventas', 'Gestiona leads, oportunidades y cotizaciones propias; no aprueba sus cotizaciones.'],
        'OPERATIONS' => ['Operaciones', 'Planifica servicios contratados, órdenes de trabajo y proyectos de su sede.'],
        'TECHNICIAN' => ['Técnico', 'Ejecuta sus órdenes y tareas asignadas; registra horas, evidencias y checklists.'],
        'FINANCE' => ['Finanzas', 'Factura, emite comprobantes y gestiona cuentas por cobrar, cobros y recurrencia.'],
        'SUPPORT' => ['Soporte', 'Atiende los tickets de su equipo, el SLA y los reclamos de garantía.'],
    ];

    /** Recurso del kit => recursos (prefijos de permiso) del sistema. */
    private const RESOURCES = [
        'parties' => ['core'], 'services' => ['services'], 'leads' => ['leads'], 'opportunities' => ['opportunities'],
        'activities' => ['activities'], 'campaigns' => ['campaigns'], 'quotations' => ['quotations'], 'contracts' => ['contracts'],
        'orders' => ['orders'], 'contracted_services' => ['contracted_services'], 'work_orders' => ['work_orders'],
        'projects' => ['projects', 'tasks'], 'time_entries' => ['time_entries'], 'cost_entries' => ['cost_entries'],
        'deliverables' => ['deliverables', 'checklists'], 'agenda' => ['agenda'], 'documents' => ['documents'],
        'credentials' => ['credentials'], 'billing' => ['billing'], 'receivables' => ['receivables'], 'payments' => ['payments'],
        'tickets' => ['tickets'], 'warranty' => ['warranty'], 'recurring' => ['recurring'], 'portal_requests' => ['portal'],
        'integrations' => ['integrations'], 'workflows' => ['workflows'], 'reports' => ['reports'], 'audit' => ['audit'],
        'users' => ['users', 'roles'], 'purchases' => ['purchases'], 'payables' => ['payables'], 'treasury' => ['treasury'],
        'accounting' => ['accounting'], 'budgets' => ['budgets'], 'fiscal' => ['fiscal'], 'portal_users' => ['portal_users'],
        'messaging' => ['messaging'], 'imports' => ['imports'],
    ];

    /** Matriz del kit: recurso => [Gerencia, Ventas, Operaciones, Técnico, Finanzas, Soporte]. */
    private const MATRIX = [
        'parties' => ['vx:TENANT', 'vce:TENANT', 'v:TENANT', 'v:OWN', 've:TENANT', 'v:TENANT'],
        'services' => ['vea:TENANT', 'v:TENANT', 'v:TENANT', 'v:TENANT', 'v:TENANT', 'v:TENANT'],
        'leads' => ['vx:TENANT', 'vce:OWN', '', '', '', ''],
        'opportunities' => ['vex:TENANT', 'vce:OWN', '', '', '', ''],
        'activities' => ['v:TENANT', 'vce:OWN', '', '', '', ''],
        'campaigns' => ['vx:TENANT', 'v:TENANT', '', '', '', ''],
        'quotations' => ['veax:TENANT', 'vce:OWN', '', '', '', ''],
        'contracts' => ['vceax:TENANT', 'vc:OWN', 'v:BRANCH', '', 'v:TENANT', 'v:TENANT'],
        'orders' => ['vcea:TENANT', 'vc:OWN', 've:BRANCH', '', 'v:TENANT', ''],
        'contracted_services' => ['vea:TENANT', 'v:OWN', 'vce:BRANCH', '', 'v:TENANT', 'v:TENANT'],
        'work_orders' => ['vax:TENANT', '', 'vcea:BRANCH', 've:OWN', '', 'vc:TENANT'],
        'projects' => ['vax:TENANT', '', 'vcea:BRANCH', 've:OWN', 'v:TENANT', ''],
        'time_entries' => ['vax:TENANT', '', 'vca:BRANCH', 'vce:OWN', 'v:TENANT', ''],
        'cost_entries' => ['vax:TENANT', '', 'vca:BRANCH', 'vc:OWN', 'va:TENANT', ''],
        'deliverables' => ['va:TENANT', '', 'vcea:BRANCH', 'vce:OWN', '', ''],
        'agenda' => ['v:TENANT', 'vce:OWN', 'vce:BRANCH', 'v:OWN', '', 'v:OWN'],
        'documents' => ['vx:TENANT', 'vc:OWN', 'vce:BRANCH', 'vc:OWN', 'vc:TENANT', 'vc:TENANT'],
        'credentials' => ['v:TENANT', '', 'vu:TENANT', 'vu:TENANT', 'v:TENANT', ''],
        'billing' => ['vax:TENANT', '', 'v:BRANCH', '', 'vceax:TENANT', ''],
        'receivables' => ['vx:TENANT', 'v:OWN', '', '', 'vex:TENANT', ''],
        'payments' => ['vx:TENANT', '', '', '', 'vceax:TENANT', ''],
        'tickets' => ['vx:TENANT', 'v:OWN', 've:BRANCH', 've:OWN', '', 'vcea:TEAM'],
        'warranty' => ['va:TENANT', '', 'v:BRANCH', '', '', 'vcea:TENANT'],
        'recurring' => ['vx:TENANT', 'v:OWN', 'v:BRANCH', '', 'vce:TENANT', 'v:TENANT'],
        'portal_requests' => ['v:TENANT', 've:TENANT', '', '', '', 'v:TENANT'],
        'integrations' => ['v:TENANT', '', '', '', 'v:TENANT', ''],
        'workflows' => ['v:TENANT', '', '', '', '', ''],
        'reports' => ['vx:TENANT', 'v:OWN', 'v:BRANCH', '', 'vx:TENANT', 'v:TEAM'],
        'audit' => ['vx:TENANT', '', '', '', '', ''],
        'users' => ['v:TENANT', '', '', '', '', ''],
        'purchases' => ['vax:TENANT', '', 'vce:BRANCH', '', 'vce:TENANT', ''],
        'payables' => ['vax:TENANT', '', '', '', 'vceax:TENANT', ''],
        'treasury' => ['vx:TENANT', '', '', '', 'vceax:TENANT', ''],
        'accounting' => ['vx:TENANT', '', '', '', 'vceax:TENANT', ''],
        'budgets' => ['vax:TENANT', '', 'v:BRANCH', '', 'vce:TENANT', ''],
        'fiscal' => ['v:TENANT', '', '', '', 'vce:TENANT', ''],
        'portal_users' => ['v:TENANT', '', '', '', '', ''],
        'messaging' => ['v:TENANT', '', '', '', 'v:TENANT', ''],
        'imports' => ['v:TENANT', 'vc:OWN', '', '', 'vc:TENANT', ''],
    ];

    private const LETTERS = ['v' => 'view', 'c' => 'create', 'e' => 'manage', 'd' => 'delete', 'a' => 'approve', 'x' => 'export', 'u' => 'use', 'r' => 'reveal'];

    /**
     * Permisos y alcances de un rol base para esos módulos.
     *
     * @return array{permissions: string[], scopes: array<string, string>}
     */
    public static function definition(string $role, array $modules): array
    {
        $column = array_search($role, array_keys(self::ROLES), true);
        $allowed = array_flip(ModulePermissions::codesFor($modules));
        $existing = array_flip(DB::table('permissions')->pluck('code')->all());
        $scoped = array_keys(\App\Services\AccessScope::RESOURCES);

        $permissions = [];
        $scopes = [];
        foreach (self::MATRIX as $kitResource => $cells) {
            $cell = $cells[$column];
            if ($cell === '') {
                continue;
            }
            [$letters, $scope] = explode(':', $cell);
            foreach (self::RESOURCES[$kitResource] as $resource) {
                foreach (str_split($letters) as $letter) {
                    $code = "{$resource}.".self::LETTERS[$letter];
                    if ($letter === 'c' && ! isset($existing[$code])) {
                        $code = "{$resource}.manage";
                    }
                    if (isset($existing[$code], $allowed[$code])) {
                        $permissions[$code] = true;
                    }
                }
                if (in_array($resource, $scoped, true)) {
                    $scopes[$resource] = $scope;
                }
            }
        }

        return ['permissions' => array_keys($permissions), 'scopes' => $scopes];
    }

    /** Crea los roles base en la empresa (dentro de su contexto y de una transacción). */
    public static function create(string $tenantId, array $modules): void
    {
        $ids = DB::table('permissions')->pluck('id', 'code');
        foreach (self::ROLES as $code => [$name, $description]) {
            if (DB::table('roles')->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                continue;
            }
            $definition = self::definition($code, $modules);
            $roleId = DB::table('roles')->insertGetId([
                'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'description' => $description,
                'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
            ], 'id');
            DB::table('role_permissions')->insert(array_map(
                fn (string $permission) => ['role_id' => $roleId, 'permission_id' => $ids[$permission]],
                $definition['permissions'],
            ));
            foreach ($definition['scopes'] as $resource => $scope) {
                DB::table('role_scopes')->insert(['role_id' => $roleId, 'tenant_id' => $tenantId, 'resource' => $resource, 'scope' => $scope, 'updated_at' => now()]);
            }
        }
    }
}
