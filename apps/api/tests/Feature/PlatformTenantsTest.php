<?php

namespace Tests\Feature;

use App\Mail\TenantInvitationMail;
use App\Models\User;
use App\Modules\Subscription\Models\SaasPlan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\Feature\Concerns\PlatformSession;
use Tests\TestCase;

/**
 * Consola de plataforma: módulos de los planes según el catálogo, aprovisionar una empresa
 * con invitación al administrador, estados con motivo e historial, cambio de plan con
 * límite de usuarios y módulos, auditoría y suscripciones; nada de eso para cuentas de
 * empresa o del portal.
 */
class PlatformTenantsTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;
    use PlatformSession;

    protected $connectionsToTransact = [null, 'pgsql_platform'];

    private function plan(array $modules, int $users = 3, bool $trial = true): SaasPlan
    {
        return SaasPlan::create([
            'code' => 'T-'.Str::upper(Str::random(6)), 'name' => 'Plan prueba '.Str::random(4), 'currency' => 'PEN',
            'monthly_price' => 50, 'annual_price' => 500, 'active' => true, 'is_public' => false, 'display_order' => 99,
            'has_trial' => $trial, 'trial_days' => 14, 'default_billing_period' => 'MONTHLY',
            'modules' => array_fill_keys($modules, true), 'limits' => ['users' => $users],
        ]);
    }

    private function ruc(): string
    {
        return '20'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    private function provision(SaasPlan $plan, array $extra = []): array
    {
        return $this->postJson('/api/v1/superadmin/tenants', $extra + [
            'name' => 'Servicios Prueba', 'ruc' => $this->ruc(), 'legal_name' => 'Servicios Prueba S.A.C.', 'branch' => 'Lima',
            'admin_name' => 'Ana Admin', 'admin_email' => 'ana-'.Str::random(6).'@empresa.test',
            'plan_id' => $plan->id, 'billing_period' => 'MONTHLY', 'trial' => true, 'crm' => false, 'portal' => true,
        ])->assertCreated()->json();
    }

    private function inTenant(string $tenantId): void
    {
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
    }

    public function test_plan_modules_follow_the_catalog_with_mandatory_ones_always_on(): void
    {
        $this->signIn($this->superAdmin());

        $this->postJson('/api/v1/superadmin/plans', [
            'code' => 'X-'.Str::random(5), 'name' => 'Con módulo falso', 'currency' => 'PEN', 'modules' => ['HOSTING' => true],
        ])->assertStatus(422)->assertJsonValidationErrors('modules');

        $plan = $this->postJson('/api/v1/superadmin/plans', [
            'code' => 'X-'.Str::random(5), 'name' => 'Solo CRM', 'currency' => 'PEN', 'modules' => ['crm' => true, 'PERSONAL' => false],
        ])->assertCreated()->json('plan');
        $this->assertEqualsCanonicalizing(['CLIENTES', 'CRM'], array_keys($plan['modules']));

        // Quitar un obligatorio no lo quita.
        $updated = $this->putJson("/api/v1/superadmin/plans/{$plan['id']}", ['modules' => ['CLIENTES' => false, 'PORTAL' => true]])
            ->assertOk()->json('plan.modules');
        $this->assertEqualsCanonicalizing(['CLIENTES', 'PORTAL'], array_keys($updated));

        $catalog = collect($this->getJson('/api/v1/superadmin/modules')->assertOk()->json())->keyBy('code');
        $this->assertTrue($catalog['CLIENTES']['is_core']);
        // Un módulo por grupo de pantallas del menú; Compras y Contabilidad aparte de Finanzas.
        $this->assertSame(['CLIENTES'], $catalog->where('is_core', true)->keys()->all());
        $this->assertTrue($catalog->every(fn ($m) => $m['own_screens']));
        $this->assertSame(['purchases', 'payables'], $catalog['COMPRAS']['prefixes']);
        $this->assertSame(['accounting', 'budgets'], $catalog['CONTABILIDAD']['prefixes']);
        $this->assertNotContains('purchases', $catalog['FINANZAS']['prefixes']);
        foreach (['TICKETS', 'ENTREGABLES', 'PROVEEDORES', 'TAREAS'] as $retired) {
            $this->assertFalse($catalog->has($retired));
        }
    }

    public function test_provisioning_creates_the_company_in_provisioning_and_activation_invites_the_admin(): void
    {
        Mail::fake();
        $actor = $this->superAdmin();
        $this->signIn($actor);
        $plan = $this->plan(['CRM', 'PORTAL', 'COTIZACIONES']);

        $this->postJson('/api/v1/superadmin/tenants', ['name' => 'X', 'ruc' => '123', 'admin_name' => 'A', 'admin_email' => 'no', 'plan_id' => $plan->id, 'billing_period' => 'MONTHLY', 'trial' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['ruc', 'admin_email']);

        $created = $this->provision($plan);
        $tenantId = $created['id'];
        $tenant = DB::table('tenants')->find($tenantId);
        $this->assertSame('PROVISIONING', $tenant->status);

        $this->inTenant($tenantId);
        $modules = DB::table('tenant_modules')->where('tenant_id', $tenantId)->where('enabled', true)->pluck('module_code')->all();
        $this->assertEqualsCanonicalizing(['CLIENTES', 'PORTAL', 'COTIZACIONES'], $modules, 'CRM quedó apagado a pedido.');
        $this->assertSame('Lima', DB::table('branches')->where('tenant_id', $tenantId)->value('name'));
        $admin = User::where('email', 'like', 'ana-%@empresa.test')->latest('created_at')->first();
        $this->assertNotNull(DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $admin->id)->value('default_branch_id'));
        $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', 'ADMIN')->value('id');
        $perms = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('role_id', $roleId)->pluck('code');
        $this->assertTrue($perms->contains('quotations.view'));
        $this->assertFalse($perms->contains('leads.view'));
        $sub = DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->first();
        $this->assertTrue(json_decode($sub->configuration, true)['is_trial']);

        $this->assertSame(1, DB::table('tenant_status_log')->where('tenant_id', $tenantId)->where('to_status', 'PROVISIONING')->count());
        $this->assertSame(1, DB::table('platform_audit_logs')->where('entity_id', $tenantId)->where('action', 'tenant.provisioned')->count());

        // El mismo RUC no se repite; el mismo correo tampoco.
        $this->postJson('/api/v1/superadmin/tenants', [
            'name' => 'Otra', 'ruc' => DB::table('companies')->where('tenant_id', $tenantId)->value('tax_identifier'),
            'admin_name' => 'B', 'admin_email' => $admin->email, 'plan_id' => $plan->id, 'billing_period' => 'MONTHLY', 'trial' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['ruc', 'admin_email']);

        // Activar invita al administrador con un código que sirve para crear su contraseña.
        Mail::assertNothingSent();
        $this->postJson("/api/v1/superadmin/tenants/{$tenantId}/transitions", ['from' => 'PROVISIONING', 'to' => 'ACTIVE'])
            ->assertOk()->assertJsonPath('invited', [$admin->email]);
        $code = null;
        Mail::assertSent(TenantInvitationMail::class, function (TenantInvitationMail $mail) use ($admin, &$code) {
            $code = $mail->code;

            return $mail->hasTo($admin->email) && str_ends_with($mail->resetUrl, '/reset-password');
        });
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/reset-password', ['email' => $admin->email, 'token' => $code, 'password' => 'NuevaClave2026'])->assertOk();
        $this->assertSame('ACTIVE', DB::table('tenants')->where('id', $tenantId)->value('status'));
    }

    public function test_status_changes_need_a_reason_follow_the_rules_and_keep_history(): void
    {
        Mail::fake();
        $this->signIn($this->superAdmin());
        $tenantId = $this->provision($this->plan(['COTIZACIONES']))['id'];
        $url = "/api/v1/superadmin/tenants/{$tenantId}/transitions";

        $this->postJson($url, ['from' => 'PROVISIONING', 'to' => 'SUSPENDED', 'reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->postJson($url, ['from' => 'PROVISIONING', 'to' => 'ACTIVE'])->assertOk();
        $this->postJson($url, ['from' => 'ACTIVE', 'to' => 'SUSPENDED'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($url, ['from' => 'PROVISIONING', 'to' => 'SUSPENDED', 'reason' => 'Falta de pago'])->assertStatus(409)->assertJsonPath('code', 'STALE_STATUS');
        $this->postJson($url, ['from' => 'ACTIVE', 'to' => 'SUSPENDED', 'reason' => 'Falta de pago'])->assertOk();

        $log = DB::table('tenant_status_log')->where('tenant_id', $tenantId)->orderBy('created_at')->get();
        $this->assertSame([[null, 'PROVISIONING'], ['PROVISIONING', 'ACTIVE'], ['ACTIVE', 'SUSPENDED']], $log->map(fn ($l) => [$l->from_status, $l->to_status])->all());
        $this->assertSame('Falta de pago', $log->last()->reason);
        $audit = DB::table('platform_audit_logs')->where('entity_id', $tenantId)->where('action', 'tenant.status_changed')->get();
        $this->assertCount(2, $audit);

        // Suspender no borra nada y el historial no se puede tocar desde la app.
        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'status' => 'SUSPENDED']);
        $this->assertDatabaseRejects(fn () => DB::table('tenant_status_log')->where('tenant_id', $tenantId)->delete());
    }

    public function test_changing_plan_checks_the_user_limit_and_turns_modules_on_and_off_without_deleting(): void
    {
        Mail::fake();
        $this->signIn($this->superAdmin());
        $tenantId = $this->provision($this->plan(['COTIZACIONES', 'FINANZAS'], users: 5))['id'];

        // Bajar a un plan con menos usuarios que los activos se rechaza.
        $this->inTenant($tenantId);
        foreach (range(1, 2) as $n) {
            $extra = User::create(['email' => "u{$n}-".Str::random(6).'@empresa.test', 'password_hash' => bcrypt('x'), 'name' => "U{$n}", 'status' => 'ACTIVE']);
            DB::table('tenant_users')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $extra->id, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        }
        $small = $this->plan(['CRM'], users: 2);
        $this->putJson("/api/v1/superadmin/tenants/{$tenantId}/plan", ['plan_id' => $small->id])
            ->assertStatus(422)->assertJsonPath('code', 'PLAN_LIMIT_REACHED');

        $other = $this->plan(['CRM', 'COTIZACIONES'], users: 10);
        $result = $this->putJson("/api/v1/superadmin/tenants/{$tenantId}/plan", ['plan_id' => $other->id])->assertOk()->json();
        $this->assertSame(['CRM'], $result['enabled']);
        $this->assertSame(['FINANZAS'], $result['disabled']);

        $this->inTenant($tenantId);
        $this->assertFalse((bool) DB::table('tenant_modules')->where('tenant_id', $tenantId)->where('module_code', 'FINANZAS')->value('enabled'));
        $this->assertSame($other->id, DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->value('plan_id'));
        $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', 'ADMIN')->value('id');
        $this->assertTrue(DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_id', $roleId)->where('code', 'leads.view')->exists());
        $this->assertSame(1, DB::table('platform_audit_logs')->where('entity_id', $tenantId)->where('action', 'tenant.plan_changed')->count());

        // Volver a aplicar los módulos del plan cuando ya coinciden no cambia nada, pero queda auditado.
        $again = $this->postJson("/api/v1/superadmin/tenants/{$tenantId}/apply-plan-modules")->assertOk()->json();
        $this->assertSame(['enabled' => [], 'disabled' => []], $again);
        $this->assertSame(1, DB::table('platform_audit_logs')->where('entity_id', $tenantId)->where('action', 'tenant.modules_applied')->count());
    }

    public function test_reads_show_platform_data_only(): void
    {
        $this->signIn($this->superAdmin());

        $list = $this->getJson('/api/v1/superadmin/tenants')->assertOk()->json('data');
        $this->assertCount(DB::table('tenants')->count(), $list);

        $tenant = $this->getJson('/api/v1/superadmin/tenants/'.$list[0]['id'])->assertOk()->json();
        $this->assertEqualsCanonicalizing(['tenant', 'transitions', 'catalog', 'plans'], array_keys($tenant));
        $this->assertEqualsCanonicalizing([
            'id', 'code', 'name', 'legal_name', 'trade_name', 'ruc', 'status', 'created_at', 'subscription', 'modules', 'users', 'users_limit',
            'portal_users', 'portal_users_limit', 'customers', 'customers_limit', 'storage_mb', 'storage_limit_gb', 'subscriptions', 'status_log', 'admins',
        ], array_keys($tenant['tenant']));

        $this->getJson('/api/v1/superadmin/tenants/'.Str::uuid())->assertNotFound();
        $this->getJson('/api/v1/superadmin/tenants/no-es-uuid')->assertNotFound();
        $this->getJson('/api/v1/superadmin/subscriptions')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/v1/superadmin/audit?action=auth.login&per_page=5')->assertOk()->assertJsonStructure(['data', 'total', 'last_page']);
        $this->getJson('/api/v1/superadmin/audit/options')->assertOk()->assertJsonStructure(['actions', 'users']);
    }

    public function test_company_and_portal_accounts_cannot_use_the_console(): void
    {
        $this->makeTenant(['PORTAL']);
        $admin = $this->makeUser('Administrador', ['users.view', 'users.manage', 'audit.view']);
        $customerId = $this->makeCustomer();
        $client = User::create(['email' => 'cli-'.Str::random(6).'@cliente.test', 'password_hash' => bcrypt('x'), 'name' => 'Cliente', 'status' => 'ACTIVE', 'mfa_enabled' => true]);
        DB::table('portal_users')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $client->id, 'customer_id' => $customerId, 'role' => 'PORTAL_ADMIN', 'status' => 'ACTIVE', 'created_at' => now()]);
        $plan = $this->plan(['COTIZACIONES']);

        foreach ([$admin, $client] as $account) {
            $this->signIn($account);
            $this->getJson('/api/v1/superadmin/tenants')->assertStatus(403);
            $this->getJson("/api/v1/superadmin/tenants/{$this->tenantId}")->assertStatus(403);
            $this->postJson('/api/v1/superadmin/tenants', ['name' => 'X', 'plan_id' => $plan->id])->assertStatus(403);
            $this->postJson("/api/v1/superadmin/tenants/{$this->tenantId}/transitions", ['from' => 'ACTIVE', 'to' => 'CLOSED', 'reason' => 'x'])->assertStatus(403);
            $this->putJson("/api/v1/superadmin/tenants/{$this->tenantId}/plan", ['plan_id' => $plan->id])->assertStatus(403);
            $this->getJson('/api/v1/superadmin/audit')->assertStatus(403);
            $this->getJson('/api/v1/superadmin/subscriptions')->assertStatus(403);
            $this->getJson('/api/v1/superadmin/modules')->assertStatus(403);
            $this->putJson("/api/v1/superadmin/plans/{$plan->id}", ['name' => 'X'])->assertStatus(403);
        }
        $this->assertSame('ACTIVE', DB::table('tenants')->where('id', $this->tenantId)->value('status'));
    }
}
