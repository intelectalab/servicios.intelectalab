<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\Feature\Concerns\PlatformSession;
use Tests\TestCase;
use Throwable;

/**
 * Tablas de plataforma frente a la app de las empresas (erp_app): el catálogo de módulos es de
 * solo lectura; planes y suscripciones no se borran ni se vacían y solo cambian con una sesión
 * de superadmin vigente y verificada. Sin ella, solo entra la primera suscripción de una empresa
 * nueva con un plan activo y público (el registro).
 */
class PlatformTablesGuardTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;
    use PlatformSession;

    protected $connectionsToTransact = [null, 'pgsql_platform'];

    private function subscription(string $planCode = 'PRO', array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $plan = DB::table('saas_plans')->where('code', $planCode)->value('id');
        DB::table('tenant_subscriptions')->insert($overrides + [
            'id' => $id, 'tenant_id' => $this->tenantId, 'plan_id' => $plan, 'status' => 'ACTIVE',
            'starts_at' => now(), 'ends_at' => now()->addMonth(), 'configuration' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function token(bool $mfa = true, string $status = 'ACTIVE', ?\DateTimeInterface $expires = null): string
    {
        $token = $this->superAdmin($status)->createToken('prueba', ['*'], $expires ?? now()->addHour())->accessToken;
        $token->forceFill(['mfa' => $mfa])->save();

        return (string) $token->getKey();
    }

    private function usePlatformToken(string $token): void
    {
        DB::statement("SELECT set_config('app.platform_token', ?, false)", [$token]);
    }

    public function test_the_module_catalog_is_read_only_for_the_app(): void
    {
        $this->assertNotEmpty(DB::table('module_catalog')->pluck('code'));
        $this->usePlatformToken($this->token());
        $this->assertRejected(fn () => DB::table('module_catalog')->where('code', 'CRM')->update(['name' => 'Otro']), 'permission denied');
        $this->assertRejected(fn () => DB::table('module_catalog')->insert(['code' => 'X', 'name' => 'X']), 'permission denied');
        $this->assertRejected(fn () => DB::table('module_catalog')->where('code', 'CRM')->delete(), 'permission denied');
    }

    public function test_plans_and_subscriptions_are_never_deleted_by_the_app(): void
    {
        $this->makeTenant();
        $this->subscription();
        $this->usePlatformToken($this->token());
        $this->assertRejected(fn () => DB::table('saas_plans')->where('code', 'BASE')->delete(), 'permission denied');
        $this->assertRejected(fn () => DB::statement('TRUNCATE saas_plans CASCADE'), 'permission denied');
        $this->assertRejected(fn () => DB::table('tenant_subscriptions')->where('tenant_id', $this->tenantId)->delete(), 'permission denied');
    }

    public function test_without_a_platform_session_plans_and_subscriptions_do_not_change(): void
    {
        $this->makeTenant();
        $sub = $this->subscription();

        $this->assertRejected(fn () => DB::table('saas_plans')->where('code', 'BASE')->update(['monthly_price' => 0]));
        $this->assertRejected(fn () => DB::table('saas_plans')->insert(['code' => 'GRATIS-'.Str::random(4), 'name' => 'Gratis']));
        // Una empresa no se alarga la vigencia ni se sube de plan.
        $this->assertRejected(fn () => DB::table('tenant_subscriptions')->where('id', $sub)->update(['ends_at' => now()->addYears(10)]));
        $enterprise = DB::table('saas_plans')->where('code', 'ENTERPRISE')->value('id');
        $this->assertRejected(fn () => DB::table('tenant_subscriptions')->where('id', $sub)->update(['plan_id' => $enterprise]));
        // Ni se crea una segunda suscripción.
        $this->assertRejected(fn () => $this->subscription('ENTERPRISE'), 'ya tiene suscripción');
    }

    public function test_only_a_live_verified_super_admin_session_opens_the_tables(): void
    {
        $this->makeTenant();
        $sub = $this->subscription();
        $extend = fn () => DB::table('tenant_subscriptions')->where('id', $sub)->update(['ends_at' => now()->addYear()]);

        foreach ([
            'sin verificación' => $this->token(mfa: false),
            'superadmin inactivo' => $this->token(status: 'DISABLED'),
            'sesión vencida' => $this->token(expires: now()->subMinute()),
            'sesión inexistente' => '999999999999',
            'texto cualquiera' => "1'; --",
        ] as $case => $token) {
            $this->usePlatformToken($token);
            $this->assertRejected($extend, 'Solo la consola', $case);
        }

        // Una sesión de una cuenta de empresa tampoco.
        $user = $this->makeUser('Admin', ['tenant.manage']);
        $tenantToken = $user->createToken('empresa', ['*'], now()->addHour())->accessToken;
        $tenantToken->forceFill(['mfa' => true])->save();
        $this->usePlatformToken((string) $tenantToken->getKey());
        $this->assertRejected($extend, 'Solo la consola', 'cuenta de empresa');

        $this->usePlatformToken($this->token());
        $this->assertSame(1, $extend());
        $this->assertRejected(fn () => DB::table('tenant_subscriptions')->where('id', $sub)->update(['tenant_id' => (string) Str::uuid()]), 'no cambia de empresa');
    }

    public function test_the_first_subscription_needs_an_active_public_plan_and_a_bounded_term(): void
    {
        $this->makeTenant();
        $private = $this->asPlatform(fn () => DB::table('saas_plans')->insertGetId([
            'code' => 'PRIV-'.Str::random(5), 'name' => 'Privado', 'is_public' => false, 'active' => true,
        ], 'id'));
        $inactive = $this->asPlatform(fn () => DB::table('saas_plans')->insertGetId([
            'code' => 'OFF-'.Str::random(5), 'name' => 'Apagado', 'is_public' => true, 'active' => false,
        ], 'id'));

        foreach ([$private, $inactive] as $plan) {
            $this->assertRejected(fn () => $this->subscription('PRO', ['plan_id' => $plan]), 'no está disponible');
        }
        $this->assertRejected(fn () => $this->subscription('PRO', ['ends_at' => now()->addYears(5)]), 'Vigencia');
        $this->assertRejected(fn () => $this->subscription('PRO', ['status' => 'CANCELLED']), 'Vigencia');

        $this->subscription('PRO', ['ends_at' => now()->addYear()]);
        $this->assertSame(1, DB::table('tenant_subscriptions')->where('tenant_id', $this->tenantId)->count());

        // El registro público tampoco acepta un plan que la página no ofrece.
        $this->postJson('/api/v1/tenants/register', [
            'plan_id' => $private, 'billing_period' => 'MONTHLY', 'ruc' => '20'.random_int(100000000, 999999999),
            'razon_social' => 'Privada SAC', 'nombres' => 'Ana', 'apellidos' => 'Prueba',
            'email' => 'priv-'.Str::lower(Str::random(8)).'@erp-saas.test', 'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('plan_id');
    }

    private function assertRejected(callable $attempt, string $message = 'Solo la consola', string $case = ''): void
    {
        try {
            DB::transaction($attempt);
            $this->fail("La base aceptó un cambio que debía rechazar. {$case}");
        } catch (Throwable $e) {
            $this->assertInstanceOf(QueryException::class, $e, $case.' '.$e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage(), $case);
        }
    }
}
