<?php

namespace Database\Seeders;

use App\Modules\Subscription\Models\SaasPlan;
use App\Modules\Subscription\Support\TenantPlanModules;
use App\Modules\Subscription\Support\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Datos de las pruebas de punta a punta (apps/web/e2e), en dos empresas propias que no tocan las
 * demás:
 * - "E2E Activa" (e2e-admin@erp-saas.test): plan vigente con 4 de 5 leads (aviso al 80 %).
 * - "E2E Vencida" (e2e-vencida@erp-saas.test): suscripción vencida hace 10 días (solo lectura).
 *
 * La contraseña sale de E2E_PASSWORD (en el .env local, nunca en el repositorio). Se puede volver
 * a correr: deja ambas empresas en ese mismo estado. Corre como el dueño de las tablas (erp_user):
 *   DB_USERNAME=erp_user DB_PASSWORD=… php artisan db:seed --class=E2eSeeder --force
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) env('E2E_PASSWORD', '');
        if (strlen($password) < 12) {
            throw new RuntimeException('Define E2E_PASSWORD (12 caracteres o más) en el .env antes de correr este seeder.');
        }

        DB::transaction(function () use ($password) {
            $plan = SaasPlan::updateOrCreate(['code' => 'E2E'], [
                'name' => 'Plan E2E', 'description' => 'Solo para las pruebas de punta a punta.', 'active' => true, 'is_public' => false,
                'monthly_price' => 1, 'annual_price' => 10, 'currency' => 'PEN', 'has_trial' => false, 'trial_days' => 0,
                'modules' => ['CRM' => true, 'COTIZACIONES' => true, 'ORDENES_TRABAJO' => true], 'limits' => ['crm_leads' => 5, 'users' => 5],
            ]);

            $this->company($plan, 'E2E Activa SAC', 'e2e-admin@erp-saas.test', $password, now()->addDays(30), 4);
            $this->company($plan, 'E2E Vencida SAC', 'e2e-vencida@erp-saas.test', $password, now()->subDays(10), 1);
        });
    }

    private function company(SaasPlan $plan, string $name, string $email, string $password, $endsAt, int $leads): void
    {
        $userId = DB::table('users')->where('email', $email)->value('id');
        if (! $userId) {
            $created = TenantProvisioner::create([
                'name' => $name, 'legal_name' => $name, 'trade_name' => $name, 'ruc' => '20'.random_int(100000000, 999999999),
                'admin_name' => 'Admin '.$name, 'admin_email' => $email, 'password_hash' => Hash::make($password),
                'plan' => $plan, 'billing_period' => 'MONTHLY', 'trial' => false, 'status' => 'ACTIVE',
            ]);
            $userId = $created['user']->id;
        }
        $tenantId = (string) DB::table('tenant_users')->where('user_id', $userId)->value('tenant_id');
        DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);

        DB::table('users')->where('id', $userId)->update(['password_hash' => Hash::make($password), 'status' => 'ACTIVE', 'mfa_enabled' => false]);
        DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->update(['ends_at' => $endsAt, 'status' => 'ACTIVE']);
        // Módulos y permisos del administrador al día con el plan E2E.
        TenantPlanModules::apply($tenantId, $plan);

        DB::table('crm_leads')->where('tenant_id', $tenantId)->delete();
        for ($i = 1; $i <= $leads; $i++) {
            DB::table('crm_leads')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'lead_number' => sprintf('LD-E2E-%04d', $i),
                'organization_name' => "Lead E2E {$i}", 'status' => 'NEW', 'owner_user_id' => $userId,
            ]);
        }
    }
}
