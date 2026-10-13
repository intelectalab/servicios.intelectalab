<?php

namespace App\Modules\Operations\Support;

use Illuminate\Support\Facades\DB;

/**
 * A quién se le puede asignar trabajo: personas con acceso vigente a la empresa cuyo rol tiene
 * el permiso que ese trabajo pide. No hay un rol "técnico" fijo: lo decide la matriz de permisos
 * de cada empresa.
 *
 *   - una orden de trabajo: quien puede ejecutarlas (`work_orders.manage`);
 *   - un proyecto o una de sus tareas: quien puede verlos (`projects.view`).
 */
class Technicians
{
    private const PERMISSION = 'work_orders.manage';

    /** @return array<int, array{id: string, name: string}> */
    public static function list(string $tenantId, string $permission = self::PERMISSION): array
    {
        return self::query($tenantId, $permission)->orderBy('users.name')->limit(500)->get(['users.id', 'users.name'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->all();
    }

    public static function includes(string $tenantId, string $userId, string $permission = self::PERMISSION): bool
    {
        return self::query($tenantId, $permission)->where('users.id', $userId)->exists();
    }

    private static function query(string $tenantId, string $permission)
    {
        return DB::table('users')
            ->join('tenant_users', 'tenant_users.user_id', '=', 'users.id')
            ->where('tenant_users.tenant_id', $tenantId)
            ->where('tenant_users.status', 'ACTIVE')
            ->where('users.status', 'ACTIVE')
            ->whereExists(fn ($roles) => $roles->selectRaw('1')
                ->from('user_roles')
                ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->whereColumn('user_roles.user_id', 'users.id')
                ->where('user_roles.tenant_id', $tenantId)
                ->where('permissions.code', $permission));
    }
}
