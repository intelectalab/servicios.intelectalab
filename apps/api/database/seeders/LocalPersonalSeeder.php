<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solo para desarrollo local: deja lista la empresa de prueba "tenant-a" para probar Personal.
 * Da a sus roles de prueba (Gerencia, Ventas, Operaciones, Técnico, Finanzas y Soporte) los
 * permisos de Personal de la matriz del kit, y crea el rol "Personal (RRHH)" —quien lleva el
 * personal: todo Personal, más ver la agenda y las órdenes de trabajo— con una cuenta de prueba.
 *
 *   php artisan db:seed --class=LocalPersonalSeeder
 */
class LocalPersonalSeeder extends Seeder
{
    private const TENANT_CODE = 'tenant-a';

    // La contraseña sale de TestPassword (SEED_TEST_PASSWORD o al azar, mostrada una sola vez).
    private const ACCOUNT = ['personal@erp-saas.test', 'Paola Ríos (Personal)'];

    private const HR_ROLE = [
        'employees.view', 'employees.create', 'employees.manage', 'employees.export',
        'leaves.view', 'leaves.create', 'leaves.approve', 'leaves.manage', 'leaves.export',
        'hr_sync.view', 'hr_sync.create', 'hr_sync.manage', 'hr_sync.approve', 'hr_sync.export',
        'agenda.view', 'work_orders.view', 'time_entries.view', 'core.view',
    ];

    /** Rol de prueba => [permisos de Personal, alcance en trabajadores, alcance en ausencias] (matriz del kit). */
    private const MATRIX = [
        'GERENCIA' => [['employees.view', 'employees.export', 'leaves.view', 'leaves.create', 'leaves.approve', 'leaves.export', 'hr_sync.view', 'hr_sync.approve', 'hr_sync.export'], 'TENANT', 'TENANT'],
        'VENTAS' => [['leaves.view', 'leaves.create'], null, 'OWN'],
        'OPERACIONES' => [['employees.view', 'leaves.view', 'leaves.create', 'leaves.approve'], 'BRANCH', 'BRANCH'],
        'TECNICO' => [['leaves.view', 'leaves.create'], null, 'OWN'],
        'FINANZAS' => [['employees.view', 'leaves.view', 'leaves.create', 'hr_sync.view', 'hr_sync.create', 'hr_sync.manage', 'hr_sync.approve', 'hr_sync.export'], 'TENANT', 'OWN'],
        'SOPORTE' => [['leaves.view', 'leaves.create'], null, 'OWN'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalPersonalSeeder solo corre en el entorno local.');

            return;
        }
        $tenantId = DB::table('tenants')->where('code', self::TENANT_CODE)->value('id');
        if (! $tenantId) {
            $this->command?->warn('No existe la empresa de prueba '.self::TENANT_CODE.'.');

            return;
        }
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
        $permissions = DB::table('permissions')->pluck('id', 'code');

        foreach (self::MATRIX as $code => [$codes, $employeesScope, $leavesScope]) {
            $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', $code)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($codes as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissions[$permission]]);
            }
            foreach (['employees' => $employeesScope, 'leaves' => $leavesScope] as $resource => $scope) {
                if ($scope) {
                    DB::table('role_scopes')->insertOrIgnore(['role_id' => $roleId, 'tenant_id' => $tenantId, 'resource' => $resource, 'scope' => $scope]);
                }
            }
        }

        $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', 'PERSONAL')->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'code' => 'PERSONAL', 'name' => 'Personal (RRHH)',
                'description' => 'Lleva trabajadores, puestos, ausencias, el envío de horas a planilla y la conexión con el SaaS de RRHH.',
                'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (self::HR_ROLE as $permission) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissions[$permission]]);
        }
        foreach (['employees', 'leaves', 'agenda', 'work_orders', 'time_entries'] as $resource) {
            DB::table('role_scopes')->insertOrIgnore(['role_id' => $roleId, 'tenant_id' => $tenantId, 'resource' => $resource, 'scope' => 'TENANT']);
        }

        [$email, $name] = self::ACCOUNT;
        $user = User::where('email', $email)->first()
            ?? User::create(['email' => $email, 'password_hash' => bcrypt(TestPassword::for($email, $this->command)), 'name' => $name, 'status' => 'ACTIVE']);
        $branchId = DB::table('branches')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('created_at')->value('id');
        if (! DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $user->id)->exists()) {
            DB::table('tenant_users')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id, 'default_branch_id' => $branchId,
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (! DB::table('user_roles')->where('tenant_id', $tenantId)->where('user_id', $user->id)->exists()) {
            DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $roleId, 'scope' => 'TENANT', 'created_at' => now()]);
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");
        $this->command?->info('Personal listo en '.self::TENANT_CODE.': permisos de la matriz en sus roles y cuenta '.$email.'.');
    }
}
