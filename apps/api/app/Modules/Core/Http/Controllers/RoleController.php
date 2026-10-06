<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscription\Support\ModulePermissions;
use App\Modules\Subscription\Support\TenantModules;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Roles de la empresa y su matriz de permisos (tablas roles, permissions y
 * role_permissions). El aislamiento de los roles por empresa lo da RLS.
 */
class RoleController extends Controller
{
    /** Roles de la empresa, con sus permisos, y el catálogo de permisos que la empresa puede asignar. */
    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $roles = DB::table('roles')->where('tenant_id', $tenantId)->orderBy('created_at')->get();
        $granted = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereIn('role_permissions.role_id', $roles->pluck('id'))
            ->get(['role_permissions.role_id', 'permissions.code'])
            ->groupBy('role_id');
        $users = DB::table('user_roles')
            ->where('tenant_id', $tenantId)
            ->selectRaw('role_id, count(distinct user_id) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        return response()->json([
            'roles' => $roles->map(fn ($role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'is_admin' => $this->isAdmin($role),
                'users' => (int) ($users[$role->id] ?? 0),
                'permissions' => ($granted[$role->id] ?? collect())->pluck('code')->sort()->values(),
            ]),
            'permissions' => DB::table('permissions')
                ->whereIn('code', $this->assignable($tenantId))
                ->orderBy('code')
                ->get(['code', 'name', 'description']),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ], ['name.required' => 'Indica el nombre del rol.']);

        $code = Str::upper(Str::slug($data['name'], '_'));
        if ($code === '' || DB::table('roles')->where('tenant_id', $tenantId)->whereRaw('upper(code) = ?', [$code])->exists()) {
            throw ValidationException::withMessages(['name' => ['Ya existe un rol con ese nombre.']]);
        }

        $id = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $data['name'],
            'description' => $data['description'] ?? null, 'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'role.create',
            entityType: 'Role',
            entityId: $id,
            metadata: ['code' => $code, 'name' => $data['name']],
        );

        return response()->json(['id' => $id, 'code' => $code], 201);
    }

    /** Reemplaza los permisos de un rol. El rol Administrador no se recorta. */
    public function updatePermissions(string $role, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $role = DB::table('roles')->where('tenant_id', $tenantId)->where('id', $role)->first();
        abort_if(! $role, 404);

        if ($this->isAdmin($role)) {
            throw ValidationException::withMessages([
                'permissions' => ['El rol Administrador no se recorta desde aquí para no dejar la empresa sin administrador.'],
            ]);
        }

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string'],
        ]);

        $assignable = DB::table('permissions')->whereIn('code', $this->assignable($tenantId))->pluck('id', 'code');
        $wanted = collect($data['permissions'])->unique()->values();

        if ($wanted->diff($assignable->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['permissions' => ['Hay permisos que esta empresa no puede asignar.']]);
        }

        $before = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $role->id)
            ->whereIn('permissions.code', $assignable->keys())
            ->pluck('permissions.code');

        DB::transaction(function () use ($role, $assignable, $wanted, $before) {
            // Solo se tocan los permisos que la empresa puede asignar; cualquier otro que el rol tuviera se conserva.
            $remove = $before->diff($wanted)->map(fn (string $code) => $assignable[$code]);
            if ($remove->isNotEmpty()) {
                DB::table('role_permissions')->where('role_id', $role->id)->whereIn('permission_id', $remove)->delete();
            }

            foreach ($wanted->diff($before) as $code) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $assignable[$code]]);
            }
        });

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'role.permissions.update',
            entityType: 'Role',
            entityId: $role->id,
            metadata: [
                'role' => $role->name,
                'added' => $wanted->diff($before)->values()->all(),
                'removed' => $before->diff($wanted)->values()->all(),
            ],
        );

        return response()->json(['permissions' => $wanted->sort()->values()]);
    }

    /** Permisos que la empresa puede repartir entre sus roles: los de administración y los de sus módulos. */
    private function assignable(string $tenantId): array
    {
        return ModulePermissions::codesFor(TenantModules::enabledCodes($tenantId));
    }

    private function isAdmin(object $role): bool
    {
        return $role->is_system || strtoupper($role->code) === 'ADMIN';
    }
}
