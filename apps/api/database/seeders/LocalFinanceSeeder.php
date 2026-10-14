<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solo para desarrollo local: deja lista la empresa de prueba "tenant-a" para probar Finanzas.
 * Crea el rol Finanzas (reparto de la matriz del prototipo) con una cuenta de prueba, en la sede
 * principal. El módulo Finanzas ya está activo en esa empresa.
 *
 *   php artisan db:seed --class=LocalFinanceSeeder
 */
class LocalFinanceSeeder extends Seeder
{
    private const TENANT_CODE = 'tenant-a';

    private const ROLE = 'FINANZAS';

    // Contraseña de prueba, solo para la base local. Solo se usa al crear la cuenta:
    // si después se cambia desde la aplicación, el seeder no la pisa.
    private const ACCOUNT = ['finanzas@erp-saas.test', 'Fabiola Núñez (Finanzas)', 'contrasena-de-prueba'];

    // Finanzas: factura eventos, emite comprobantes y gestiona cuentas por cobrar, cobros, reembolsos, recurrencia, bancos y caja.
    private const PERMISSIONS = [
        'core.view', 'services.view', 'contracts.view', 'orders.view', 'contracted_services.view', 'projects.view',
        'time_entries.view', 'cost_entries.view', 'cost_entries.approve',
        'billing.view', 'billing.create', 'billing.manage', 'billing.approve', 'billing.export',
        'receivables.view', 'receivables.manage', 'receivables.export',
        'payments.view', 'payments.create', 'payments.manage', 'payments.approve', 'payments.export',
        'fiscal.view', 'fiscal.manage', 'fiscal.configure',
        // Como en la matriz del prototipo: en Recurrencia procesa ciclos y renueva, pero no exporta.
        'recurring.view', 'recurring.create', 'recurring.manage',
        'treasury.view', 'treasury.create', 'treasury.manage', 'treasury.export',
    ];

    /** Recursos que el rol alcanza en toda la empresa. */
    private const TENANT_SCOPE = ['contracts', 'orders', 'contracted_services', 'projects', 'time_entries', 'cost_entries', 'billing', 'receivables', 'payments', 'recurring'];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalFinanceSeeder solo corre en el entorno local.');

            return;
        }

        $tenantId = DB::table('tenants')->where('code', self::TENANT_CODE)->value('id');
        if (! $tenantId) {
            $this->command?->warn('No existe la empresa de prueba '.self::TENANT_CODE.'.');

            return;
        }

        // roles, tenant_users y user_roles están bajo RLS: hay que entrar al contexto del tenant.
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);

        $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', self::ROLE)->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'code' => self::ROLE, 'name' => 'Finanzas',
                'description' => 'Factura eventos facturables, emite comprobantes y gestiona cuentas por cobrar, cobros y reembolsos.',
                'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        foreach (self::TENANT_SCOPE as $resource) {
            DB::table('role_scopes')->insertOrIgnore(['role_id' => $roleId, 'tenant_id' => $tenantId, 'resource' => $resource, 'scope' => 'TENANT']);
        }

        [$email, $userName, $password] = self::ACCOUNT;
        $user = User::firstOrCreate(
            ['email' => $email],
            ['password_hash' => bcrypt($password), 'name' => $userName, 'status' => 'ACTIVE'],
        );

        if (! DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $user->id)->exists()) {
            DB::table('tenant_users')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
                'default_branch_id' => DB::table('branches')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('created_at')->value('id'),
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (! DB::table('user_roles')->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('role_id', $roleId)->exists()) {
            DB::table('user_roles')->insert([
                'tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $roleId, 'scope' => 'TENANT', 'created_at' => now(),
            ]);
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        $this->command?->info('Rol y cuenta de Finanzas listos en '.self::TENANT_CODE.'.');
    }
}
