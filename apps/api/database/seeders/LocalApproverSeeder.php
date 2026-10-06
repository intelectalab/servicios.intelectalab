<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solo para desarrollo local: crea en la empresa de prueba "tenant-a" el rol
 * Gerencia y un usuario con ese rol, para probar la segregación de funciones
 * (quien crea una cotización no la aprueba; la aprueba otra persona).
 *
 *   php artisan db:seed --class=LocalApproverSeeder
 */
class LocalApproverSeeder extends Seeder
{
    private const TENANT_CODE = 'tenant-a';

    private const EMAIL = 'gerencia@erp-saas.test';

    // Contraseña de prueba, solo para la base local. Solo se usa al crear la cuenta:
    // si después se cambia desde la aplicación, el seeder no la pisa.
    private const PASSWORD = 'contrasena-de-prueba';

    // Usuario del rol Ventas (cotiza pero no aprueba), para probar botones sin permiso.
    private const SALES_EMAIL = 'ventas@erp-saas.test';

    private const SALES_PASSWORD = 'contrasena-de-prueba';

    // Gerencia en la matriz del prototipo: ve y edita cotizaciones y las aprueba; gestiona contratos.
    private const PERMISSIONS = [
        'core.view', 'services.view',
        'quotations.view', 'quotations.manage', 'quotations.approve',
        'contracts.view', 'contracts.manage',
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalApproverSeeder solo corre en el entorno local.');

            return;
        }

        $tenantId = DB::table('tenants')->where('code', self::TENANT_CODE)->value('id');
        if (! $tenantId) {
            $this->command?->warn('No existe la empresa de prueba '.self::TENANT_CODE.'.');

            return;
        }

        // roles, tenant_users y user_roles están bajo RLS: hay que entrar al contexto del tenant.
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);

        $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', 'GERENCIA')->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'code' => 'GERENCIA', 'name' => 'Gerencia',
                'description' => 'Aprueba cotizaciones y gestiona contratos.',
                'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            ['password_hash' => bcrypt(self::PASSWORD), 'name' => 'Gerencia de Prueba', 'status' => 'ACTIVE'],
        );

        DB::table('tenant_users')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        if (! DB::table('user_roles')->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('role_id', $roleId)->exists()) {
            DB::table('user_roles')->insert([
                'tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $roleId,
                'scope' => 'TENANT', 'created_at' => now(),
            ]);
        }

        // La cuenta de Ventas se crea desde la pantalla Usuarios (nace sin contraseña conocida);
        // aquí solo se le pone la de prueba mientras nunca haya iniciado sesión.
        User::where('email', self::SALES_EMAIL)->whereNull('last_login_at')
            ->update(['password_hash' => bcrypt(self::SALES_PASSWORD)]);

        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        $this->command?->info('Usuario '.self::EMAIL.' con rol Gerencia listo en '.self::TENANT_CODE.'.');
    }
}
