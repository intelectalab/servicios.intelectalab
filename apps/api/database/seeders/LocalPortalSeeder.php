<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Solo para desarrollo local: deja lista la empresa de prueba "tenant-a" para probar el portal de
 * clientes. Activa el módulo "Portal de clientes" y crea una cuenta del portal, ya activa y con el
 * rol Portal Admin, para el cliente "Minera Prueba CRM S.A.C." (el que tiene más datos: facturas,
 * pagos, tickets, proyectos y órdenes de trabajo).
 *
 * El portal siempre pide la verificación en dos pasos: el código llega al correo (con
 * MAIL_MAILER=log, en storage/logs/laravel.log).
 *
 *   php artisan db:seed --class=LocalPortalSeeder
 */
class LocalPortalSeeder extends Seeder
{
    private const TENANT_CODE = 'tenant-a';

    private const MODULE = 'PORTAL';

    /** RUC del cliente de prueba (Minera Prueba CRM S.A.C.). */
    private const CUSTOMER_TAX_ID = '20601234561';

    // La contraseña de prueba sale de TestPassword (SEED_TEST_PASSWORD o al azar). Solo se usa al
    // crear la cuenta: si después se cambia desde el portal, el seeder no la pisa.
    private const ACCOUNT = ['rosa.portal@minera-prueba.test', 'Rosa Quispe (prueba portal)'];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalPortalSeeder solo corre en el entorno local.');

            return;
        }

        $tenantId = DB::table('tenants')->where('code', self::TENANT_CODE)->value('id');
        if (! $tenantId) {
            $this->command?->warn('No existe la empresa de prueba '.self::TENANT_CODE.'.');

            return;
        }

        // tenant_modules, customers y portal_users están bajo RLS: hay que entrar al contexto del tenant.
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);

        DB::table('tenant_modules')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'module_code' => self::MODULE, 'enabled' => true, 'activated_at' => now(),
        ]);

        $customerId = DB::table('customers')
            ->join('organizations', 'organizations.party_id', '=', 'customers.party_id')
            ->where('customers.tenant_id', $tenantId)->where('organizations.tax_identifier', self::CUSTOMER_TAX_ID)
            ->value('customers.id');
        if (! $customerId) {
            $this->command?->warn('No existe el cliente de prueba con RUC '.self::CUSTOMER_TAX_ID.'.');

            return;
        }

        [$email, $name] = self::ACCOUNT;
        $user = User::where('email', $email)->first() ?? User::create([
            'email' => $email, 'name' => $name, 'password_hash' => Hash::make(TestPassword::for($email, $this->command)), 'status' => 'ACTIVE', 'mfa_enabled' => true,
        ]);

        if (! DB::table('portal_users')->where('user_id', $user->id)->exists()) {
            DB::table('portal_users')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id, 'customer_id' => $customerId,
                'role' => 'PORTAL_ADMIN', 'status' => 'ACTIVE', 'invited_by_type' => 'STAFF', 'invited_at' => now(), 'activated_at' => now(), 'created_at' => now(),
            ]);
        }

        $this->command?->info("Cuenta del portal lista: {$email} (Portal Admin de Minera Prueba CRM S.A.C.).");
    }
}
