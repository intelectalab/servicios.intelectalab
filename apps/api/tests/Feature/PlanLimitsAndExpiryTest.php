<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSubscriptionWritable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\Feature\Concerns\PlatformSession;
use Tests\TestCase;

/**
 * Límites del plan (avisan al 80 % y bloquean el alta siguiente, también en la base) y
 * vencimiento de la suscripción (7 días de gracia y después solo lectura hasta renovar).
 */
class PlanLimitsAndExpiryTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;
    use PlatformSession;

    private string $subscriptionId;

    private function subscribe(array $limits, string $endsAt): void
    {
        $planId = (string) Str::uuid();
        $this->subscriptionId = (string) Str::uuid();
        $this->asPlatform(function () use ($planId, $limits, $endsAt) {
            DB::table('saas_plans')->insert([
                'id' => $planId, 'code' => 'LIM-'.Str::random(6), 'name' => 'Plan con límites', 'active' => true,
                'modules' => json_encode(['CRM' => true]), 'limits' => json_encode($limits), 'monthly_price' => 10, 'currency' => 'PEN',
            ]);
            DB::table('tenant_subscriptions')->insert([
                'id' => $this->subscriptionId, 'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'ACTIVE',
                'starts_at' => now()->subMonths(2), 'ends_at' => $endsAt, 'configuration' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function insertLead(): void
    {
        DB::table('crm_leads')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'lead_number' => 'LD-'.Str::random(6),
            'organization_name' => 'Minera', 'status' => 'NEW']);
    }

    private function createLead()
    {
        $source = $this->getJson('/api/v1/crm/leads/options')->assertOk()->json('sources.0.id');

        return $this->postJson('/api/v1/crm/leads', [
            'organization_name' => 'Agroindustrias del Sur SAC', 'person_name' => 'Rosa Quispe', 'email' => 'rosa-'.Str::random(6).'@agrosur.test',
            'source_id' => $source,
        ]);
    }

    public function test_a_plan_limit_warns_near_the_end_and_blocks_the_next_record(): void
    {
        $this->makeTenant(['CRM']);
        $this->subscribe(['crm_leads' => 5, 'clients' => 100], now()->addMonth()->toIso8601String());
        $this->as($this->makeUser('Admin', ['tenant.manage', 'leads.view', 'leads.create', 'leads.manage']));

        for ($i = 0; $i < 3; $i++) {
            $this->insertLead();
        }
        // 3 de 5 (60 %): sin aviso.
        $this->assertSame([], $this->getJson('/api/v1/me/access')->assertOk()->json('plan_limits'));

        // 4 de 5 (80 %): aviso "near".
        $this->createLead()->assertCreated();
        $warning = $this->getJson('/api/v1/me/access')->json('plan_limits.0');
        $this->assertSame(['crm_leads', 4, 5, 'near'], [$warning['key'], $warning['used'], $warning['limit'], $warning['level']]);

        // 5 de 5: llega al límite; el siguiente se rechaza con un mensaje claro.
        $this->createLead()->assertCreated();
        $this->assertSame('reached', $this->getJson('/api/v1/me/access')->json('plan_limits.0.level'));
        $this->createLead()->assertStatus(422)
            ->assertJsonPath('code', 'PLAN_LIMIT_REACHED')
            ->assertJsonPath('limit.key', 'crm_leads')
            ->assertJsonPath('message', 'Llegaste al límite de tu plan: 5 leads. Para crear más, pide un plan mayor en Administración → Módulos y plan.');
        $this->assertSame(5, DB::table('crm_leads')->where('tenant_id', $this->tenantId)->count());

        // La base lo hace cumplir por cualquier vía, no solo por la pantalla.
        try {
            DB::transaction(fn () => $this->insertLead());
            $this->fail('La base dejó pasar un lead sobre el límite.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PLAN_LIMIT_REACHED', $e->getMessage());
        }

        // Módulos y plan: el uso marca el límite como alcanzado y controlado.
        $usage = collect($this->getJson('/api/v1/tenant/plan')->assertOk()->json('usage'))->keyBy('key');
        $this->assertSame([5, 5, true, 'reached'], [$usage['crm_leads']['used'], $usage['crm_leads']['limit'], $usage['crm_leads']['enforced'], $usage['crm_leads']['level']]);

        // Quien no administra la empresa no recibe el aviso.
        $this->as($this->makeUser('Ventas', ['leads.view', 'leads.create']));
        $this->assertSame([], $this->getJson('/api/v1/me/access')->json('plan_limits'));
    }

    public function test_an_expired_subscription_has_seven_days_of_grace_then_becomes_read_only(): void
    {
        $this->makeTenant(['CRM']);
        $this->subscribe([], now()->subDays(3)->toIso8601String());
        $this->as($this->makeUser('Admin', ['tenant.manage', 'leads.view', 'leads.create', 'leads.manage']));

        // Gracia: todo sigue funcionando, con el estado para el aviso.
        $this->assertSame('grace', $this->getJson('/api/v1/me/access')->json('subscription.state'));
        $this->createLead()->assertCreated();

        // Pasada la gracia: solo lectura.
        $this->asPlatform(fn () => DB::table('tenant_subscriptions')->where('id', $this->subscriptionId)->update(['ends_at' => now()->subDays(10)]));
        $this->assertSame('read_only', $this->getJson('/api/v1/me/access')->json('subscription.state'));
        $this->getJson('/api/v1/crm/leads')->assertOk()->assertJsonPath('total', 1);
        $blocked = $this->createLead()->assertForbidden()->assertJsonPath('code', EnsureSubscriptionWritable::CODE);
        $this->assertStringContainsString('solo lectura', $blocked->json('message'));
        $this->assertSame(1, DB::table('crm_leads')->where('tenant_id', $this->tenantId)->count());

        // Lo propio de la persona y pedir la renovación siguen permitidos.
        $this->postJson('/api/v1/me/notifications/read-all')->assertOk();
        $this->postJson('/api/v1/tenant/plan/request', ['detail' => 'Renovar el plan'])->assertOk();

        // Al extender la vigencia desde la consola, vuelve sola a la normalidad.
        $this->asPlatform(fn () => DB::table('tenant_subscriptions')->where('id', $this->subscriptionId)->update(['ends_at' => now()->addMonth()]));
        $this->assertSame('active', $this->getJson('/api/v1/me/access')->json('subscription.state'));
        $this->createLead()->assertCreated();
    }

    public function test_a_company_without_subscription_is_not_limited(): void
    {
        $this->makeTenant(['CRM']);
        $this->as($this->makeUser('Admin', ['tenant.manage', 'leads.view', 'leads.create', 'leads.manage']));

        $this->createLead()->assertCreated();
        $this->assertNull($this->getJson('/api/v1/me/access')->json('subscription'));
        $this->assertSame([], $this->getJson('/api/v1/me/access')->json('plan_limits'));
    }
}
