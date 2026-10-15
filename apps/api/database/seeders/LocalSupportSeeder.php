<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solo para desarrollo local: deja lista la empresa de prueba "tenant-a" para probar Soporte.
 * Activa el módulo Soporte y crea el rol Soporte del prototipo ("Atiende tickets de su equipo,
 * SLA, escalamientos y reclamos de garantía") con una cuenta de prueba en el equipo "Mesa de
 * ayuda" de la sede principal.
 *
 *   php artisan db:seed --class=LocalSupportSeeder
 */
class LocalSupportSeeder extends Seeder
{
    private const TENANT_CODE = 'tenant-a';

    private const MODULE = 'SOPORTE';

    private const TEAM = 'Mesa de ayuda';

    // Contraseña de prueba, solo para la base local. Solo se usa al crear la cuenta:
    // si después se cambia desde la aplicación, el seeder no la pisa.
    private const ACCOUNT = ['soporte@erp-saas.test', 'Sofía Vargas (Soporte)', 'contrasena-de-prueba'];

    /** Reparto del rol Soporte en la matriz del prototipo. */
    private const PERMISSIONS = [
        'core.view', 'services.view', 'contracts.view', 'contracted_services.view',
        'tickets.view', 'tickets.create', 'tickets.manage', 'tickets.approve',
        'warranty.view', 'warranty.create', 'warranty.manage', 'warranty.approve',
        'work_orders.view', 'work_orders.create', 'agenda.view', 'recurring.view', 'portal.view',
    ];

    /** Recurso => alcance: los tickets de su equipo; garantías, contratos y servicios de toda la empresa. */
    private const SCOPES = [
        'tickets' => 'TEAM', 'warranty' => 'TENANT', 'contracts' => 'TENANT', 'contracted_services' => 'TENANT',
        'work_orders' => 'TENANT', 'recurring' => 'TENANT', 'portal' => 'TENANT',
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalSupportSeeder solo corre en el entorno local.');

            return;
        }

        $tenantId = DB::table('tenants')->where('code', self::TENANT_CODE)->value('id');
        if (! $tenantId) {
            $this->command?->warn('No existe la empresa de prueba '.self::TENANT_CODE.'.');

            return;
        }

        // roles, tenant_users, tenant_modules, teams y user_roles están bajo RLS: hay que entrar al contexto del tenant.
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);

        if (! DB::table('tenant_modules')->where('tenant_id', $tenantId)->whereRaw('upper(module_code) = ?', [self::MODULE])->exists()) {
            DB::table('tenant_modules')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'module_code' => self::MODULE, 'enabled' => true]);
        }

        $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', 'SOPORTE')->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'code' => 'SOPORTE', 'name' => 'Soporte',
                'description' => 'Atiende tickets de su equipo, SLA, escalamientos y reclamos de garantía.',
                'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        foreach (self::SCOPES as $resource => $scope) {
            DB::table('role_scopes')->insertOrIgnore(['role_id' => $roleId, 'tenant_id' => $tenantId, 'resource' => $resource, 'scope' => $scope]);
        }

        $teamId = DB::table('teams')->where('tenant_id', $tenantId)->whereRaw('lower(name) = ?', [mb_strtolower(self::TEAM)])->value('id');
        if (! $teamId) {
            $teamId = (string) Str::uuid();
            DB::table('teams')->insert(['id' => $teamId, 'tenant_id' => $tenantId, 'name' => self::TEAM, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        }

        [$email, $name, $password] = self::ACCOUNT;
        $user = User::firstOrCreate(['email' => $email], ['password_hash' => bcrypt($password), 'name' => $name, 'status' => 'ACTIVE']);
        $branchId = DB::table('branches')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('created_at')->value('id');

        if (! DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $user->id)->exists()) {
            DB::table('tenant_users')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id, 'default_branch_id' => $branchId,
                'team_id' => $teamId, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (! DB::table('user_roles')->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('role_id', $roleId)->exists()) {
            DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $roleId, 'scope' => 'TENANT', 'created_at' => now()]);
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        $this->command?->info('Módulo Soporte activo y cuenta de Soporte lista en '.self::TENANT_CODE.'.');
    }
}
