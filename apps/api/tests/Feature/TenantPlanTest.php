<?php

namespace Tests\Feature;

use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Módulos y plan: el administrador ve su plan, vigencia, módulos, uso y pagos; apaga o enciende
 * los módulos que su plan incluye (sin borrar nada, con motivo y en auditoría); no agrega módulos
 * fuera del plan ni toca los obligatorios.
 */
class TenantPlanTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private function subscribe(string $planCode, array $config = []): object
    {
        $plan = DB::table('saas_plans')->where('code', $planCode)->first();
        DB::table('tenant_subscriptions')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'plan_id' => $plan->id, 'status' => 'ACTIVE',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20), 'configuration' => json_encode($config),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $plan;
    }

    public function test_the_admin_sees_the_plan_and_turns_plan_modules_off_and_on(): void
    {
        $this->makeTenant(['CRM', 'COTIZACIONES', 'CONTRATOS', 'SERVICIOS', 'DOCUMENTOS']);
        $this->subscribe('PRO');
        $admin = $this->makeUser('Admin', ['tenant.manage', 'leads.view', 'leads.create']);
        $this->as($admin);
        DB::table('crm_leads')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'lead_number' => 'LD-1', 'organization_name' => 'Minera', 'status' => 'NEW', 'owner_user_id' => $admin->id]);

        $plan = $this->getJson('/api/v1/tenant/plan')->assertOk();
        $this->assertSame(['Plan Profesional', false], [$plan->json('plan.name'), $plan->json('plan.is_trial')]);
        $modules = collect($plan->json('modules'))->keyBy('code');
        $this->assertSame([true, true, '1 lead · 0 oportunidades'], [$modules['CRM']['in_plan'], $modules['CRM']['enabled'], $modules['CRM']['data']]);
        // PRO no trae Soporte: no incluido. Los obligatorios van aparte.
        $this->assertSame([false, false], [$modules['SOPORTE']['in_plan'], $modules['SOPORTE']['enabled']]);
        $this->assertContains('Clientes y actores', $plan->json('core'));
        $this->assertFalse($modules->has('CLIENTES'));

        // Apagar pide motivo; apagado, la API del módulo se bloquea y los datos siguen ahí.
        $this->putJson('/api/v1/tenant/modules/CRM', ['enabled' => false])->assertStatus(422)->assertJsonValidationErrors('reason');
        $off = $this->putJson('/api/v1/tenant/modules/CRM', ['enabled' => false, 'reason' => 'No usamos el CRM por ahora'])->assertOk();
        $crm = collect($off->json('modules'))->firstWhere('code', 'CRM');
        $this->assertSame([false, 'tenant', '1 lead · 0 oportunidades'], [$crm['enabled'], $crm['disabled_by'], $crm['data']]);
        $this->getJson('/api/v1/crm/leads')->assertForbidden();
        $this->assertSame(1, DB::table('crm_leads')->where('tenant_id', $this->tenantId)->count());

        // Encender de nuevo: vuelve con sus datos.
        $this->putJson('/api/v1/tenant/modules/CRM', ['enabled' => true])->assertOk();
        $this->getJson('/api/v1/crm/leads')->assertOk()->assertJsonPath('total', 1);

        // Fuera del plan u obligatorio: no.
        $this->putJson('/api/v1/tenant/modules/SOPORTE', ['enabled' => true])->assertStatus(422)->assertJsonValidationErrors('module');
        $this->putJson('/api/v1/tenant/modules/CLIENTES', ['enabled' => false, 'reason' => 'x'])->assertStatus(422);
        $this->putJson('/api/v1/tenant/modules/INVENTADO', ['enabled' => true])->assertNotFound();

        // Historial y auditoría con su texto.
        $history = $this->getJson('/api/v1/tenant/plan')->json('history');
        $this->assertEqualsCanonicalizing([['module.enable', 'CRM', null], ['module.disable', 'CRM', 'No usamos el CRM por ahora']], array_map(fn ($h) => [$h['action'], $h['module'], $h['reason']], $history));
        foreach (['module.enable', 'module.disable', 'plan.change_requested'] as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }

        // Pedir un cambio de plan queda registrado (la auditoría guarda la hora al segundo).
        $this->travel(2)->seconds();
        $this->postJson('/api/v1/tenant/plan/request', ['detail' => 'Pasar al plan Enterprise'])->assertOk();
        $this->assertSame('Pasar al plan Enterprise', $this->getJson('/api/v1/tenant/plan')->json('history.0.detail'));
    }

    public function test_usage_against_limits_and_payments(): void
    {
        $this->makeTenant(['SERVICIOS']);
        $plan = $this->subscribe('BASE', ['is_trial' => true]);
        $this->as($this->makeUser('Admin', ['tenant.manage']));
        $this->makeUser('Otra persona', []);
        $subscriptionId = DB::table('tenant_subscriptions')->where('tenant_id', $this->tenantId)->value('id');
        DB::table('tenant_subscription_payments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'subscription_id' => $subscriptionId, 'amount' => 297, 'currency' => 'PEN',
            'payment_method' => 'TRANSFER', 'months' => 3, 'previous_ends_at' => now(), 'new_ends_at' => now()->addMonths(3), 'created_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/tenant/plan')->assertOk();
        $this->assertTrue($response->json('plan.is_trial'));
        $usage = collect($response->json('usage'))->keyBy('key');
        $limits = json_decode($plan->limits, true);
        $this->assertSame([2, (int) $limits['users'], true], [$usage['users']['used'], $usage['users']['limit'], $usage['users']['enforced']]);
        // Desde 2026-11-03 todos los límites del plan bloquean el alta siguiente (PlanLimits).
        $this->assertTrue($usage['clients']['enforced']);
        $this->assertEquals([297, 3], [$response->json('payments.0.amount'), $response->json('payments.0.months')]);
    }

    public function test_only_the_admin_and_only_its_own_company(): void
    {
        $this->makeTenant(['CRM']);
        $this->subscribe('PRO');
        $this->as($this->makeUser('Gerencia', ['leads.view']));
        $this->getJson('/api/v1/tenant/plan')->assertForbidden();
        $this->putJson('/api/v1/tenant/modules/CRM', ['enabled' => false, 'reason' => 'x'])->assertForbidden();

        // Otra empresa no ve ni toca los módulos de esta.
        $first = $this->tenantId;
        $this->makeTenant(['CRM']);
        $this->as($this->makeUser('Admin B', ['tenant.manage']));
        $this->putJson('/api/v1/tenant/modules/CRM', ['enabled' => false, 'reason' => 'x'])->assertOk();
        DB::statement("SET app.tenant_id = '{$first}'");
        $this->assertTrue((bool) DB::table('tenant_modules')->where('tenant_id', $first)->where('module_code', 'CRM')->value('enabled'));
    }
}
