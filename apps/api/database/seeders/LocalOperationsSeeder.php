<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solo para desarrollo local: deja lista la empresa de prueba "tenant-a" para probar
 * Operaciones. Activa el módulo Órdenes de trabajo y crea los roles Operaciones y Técnico
 * (reparto de la matriz del prototipo) con una cuenta de prueba cada uno, en la sede principal.
 *
 *   php artisan db:seed --class=LocalOperationsSeeder
 */
class LocalOperationsSeeder extends Seeder
{
    private const TENANT_CODE = 'tenant-a';

    private const MODULE = 'ORDENES_TRABAJO';

    // Contraseñas de prueba, solo para la base local. Solo se usan al crear la cuenta:
    // si después se cambian desde la aplicación, el seeder no las pisa.
    private const ACCOUNTS = [
        'OPERACIONES' => ['operaciones@erp-saas.test', 'Óscar Ramos (Operaciones)', 'contrasena-de-prueba'],
        'TECNICO' => ['tecnico@erp-saas.test', 'Tomás Quispe (Técnico)', 'contrasena-de-prueba'],
    ];

    /** Rol => [nombre, descripción, permisos, alcance, recursos con ese alcance]. */
    private const ROLES = [
        // Operaciones / PM: planifica y controla servicios contratados y órdenes de trabajo de su sede.
        'OPERACIONES' => [
            'Operaciones',
            'Planifica y controla servicios contratados y órdenes de trabajo de su sede.',
            [
                'core.view', 'services.view', 'contracts.view', 'orders.view', 'orders.manage',
                'contracted_services.view', 'contracted_services.manage',
                'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve',
            ],
            'BRANCH',
            ['contracts', 'orders', 'contracted_services', 'work_orders'],
        ],
        // Técnico: ejecuta las órdenes de trabajo que tiene asignadas.
        'TECNICO' => [
            'Técnico',
            'Ejecuta sus órdenes de trabajo asignadas y marca su checklist.',
            ['services.view', 'work_orders.view', 'work_orders.manage'],
            'OWN',
            ['work_orders'],
        ],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalOperationsSeeder solo corre en el entorno local.');

            return;
        }

        $tenantId = DB::table('tenants')->where('code', self::TENANT_CODE)->value('id');
        if (! $tenantId) {
            $this->command?->warn('No existe la empresa de prueba '.self::TENANT_CODE.'.');

            return;
        }

        // roles, tenant_users, tenant_modules y user_roles están bajo RLS: hay que entrar al contexto del tenant.
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);

        if (! DB::table('tenant_modules')->where('tenant_id', $tenantId)->whereRaw('upper(module_code) = ?', [self::MODULE])->exists()) {
            DB::table('tenant_modules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'module_code' => self::MODULE, 'enabled' => true,
            ]);
        }

        $branchId = DB::table('branches')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('created_at')->value('id');

        foreach (self::ROLES as $code => [$name, $description, $permissions, $scope, $resources]) {
            $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', $code)->value('id');
            if (! $roleId) {
                $roleId = (string) Str::uuid();
                DB::table('roles')->insert([
                    'id' => $roleId, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'description' => $description,
                    'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
            foreach ($resources as $resource) {
                DB::table('role_scopes')->insertOrIgnore(['role_id' => $roleId, 'tenant_id' => $tenantId, 'resource' => $resource, 'scope' => $scope]);
            }

            [$email, $userName, $password] = self::ACCOUNTS[$code];
            $user = User::firstOrCreate(
                ['email' => $email],
                ['password_hash' => bcrypt($password), 'name' => $userName, 'status' => 'ACTIVE'],
            );

            if (! DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $user->id)->exists()) {
                DB::table('tenant_users')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id, 'default_branch_id' => $branchId,
                    'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if (! DB::table('user_roles')->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('role_id', $roleId)->exists()) {
                DB::table('user_roles')->insert([
                    'tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $roleId, 'scope' => 'TENANT', 'created_at' => now(),
                ]);
            }
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        $this->command?->info('Módulo '.self::MODULE.' activo y cuentas de Operaciones y Técnico listas en '.self::TENANT_CODE.'.');
    }
}
