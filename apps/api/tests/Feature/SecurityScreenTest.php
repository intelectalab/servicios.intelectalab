<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Seguridad y aislamiento: la prueba de aislamiento intenta de verdad leer registros de otra
 * empresa y la base los niega; los controles se comprueban; la política de la empresa se aplica
 * al ingresar (código para todos, duración, "Recordarme") y en cada petición (inactividad); los
 * intentos fallidos quedan en la actividad de acceso.
 */
class SecurityScreenTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private function quotationIn(string $tenantId): string
    {
        $id = (string) Str::uuid();
        DB::statement("SET app.tenant_id = '{$tenantId}'");
        DB::table('quotations')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'quotation_number' => 'COT-T-'.Str::random(6), 'customer_id' => $this->makeCustomer(),
            'status' => 'DRAFT', 'currency' => 'PEN', 'subtotal' => 100, 'tax_total' => 18, 'discount_total' => 0, 'total' => 118,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function login(User $user, bool $remember = false)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Actual123', 'remember' => $remember]);
    }

    public function test_the_isolation_probe_tries_real_records_of_another_company_and_the_database_blocks_them(): void
    {
        $this->makeTenant(['COTIZACIONES']);
        $other = $this->tenantId;
        $foreign = $this->quotationIn($other);

        $this->makeTenant(['COTIZACIONES']);
        $this->as($this->makeUser('Gerencia', ['audit.view']));
        $response = $this->postJson('/api/v1/security/isolation-probe')->assertOk();

        $results = collect($response->json('results'))->keyBy('resource');
        $this->assertSame('BLOCKED', $results['quotations']['result']);
        $this->assertNotContains('LEAK', $results->pluck('result')->all());
        // Nunca devuelve el identificador del registro ajeno.
        $this->assertStringNotContainsString($foreign, $response->getContent());
        $this->assertSame(1, DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'security.isolation_probe')->count());

        $controls = collect($this->getJson('/api/v1/security')->assertOk()->json('controls'))->keyBy('key');
        foreach (['rls', 'audit', 'platform', 'files', 'passwords'] as $key) {
            $this->assertTrue($controls[$key]['ok'], $key.': '.$controls[$key]['detail']);
        }
    }

    public function test_the_company_policy_applies_when_signing_in_and_closes_idle_sessions(): void
    {
        Mail::fake();
        $this->makeTenant(['COTIZACIONES']);
        $admin = $this->makeUser('Admin', ['audit.view', 'tenant.manage']);
        $seller = $this->makeUser('Ventas', ['quotations.view']);

        // Sin política: Ventas entra sin código, con sesión de 8 h y 30 días con "Recordarme".
        $this->login($seller)->assertOk()->assertJsonMissingPath('mfa_required');
        $this->assertEqualsWithDelta(8 * 60, now()->diffInMinutes(DB::table('personal_access_tokens')->where('tokenable_id', $seller->id)->latest('id')->value('expires_at')), 2);

        $this->as($admin);
        $this->putJson('/api/v1/security/policy', ['require_mfa_all' => false, 'session_hours' => 2, 'allow_remember' => false, 'idle_minutes' => 15])
            ->assertOk()->assertJsonPath('policy.session_hours', 2);
        $this->putJson('/api/v1/security/policy', ['require_mfa_all' => false, 'session_hours' => 20, 'allow_remember' => true, 'idle_minutes' => 7])
            ->assertStatus(422)->assertJsonValidationErrors(['session_hours', 'idle_minutes']);

        // Sesión de 2 h, también con "Recordarme" (no permitido).
        $token = $this->login($seller, true)->assertOk()->json('token');
        $this->assertEqualsWithDelta(120, now()->diffInMinutes(DB::table('personal_access_tokens')->where('tokenable_id', $seller->id)->latest('id')->value('expires_at')), 2);

        // Con 15 min sin uso, la sesión se cierra.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/quotations')->assertOk();
        $id = DB::table('personal_access_tokens')->where('tokenable_id', $seller->id)->latest('id')->value('id');
        DB::table('personal_access_tokens')->where('id', $id)->update(['last_activity_at' => now()->subMinutes(20)]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/quotations')->assertStatus(401)->assertJsonPath('code', 'SESSION_IDLE');
        $this->assertFalse(DB::table('personal_access_tokens')->where('id', $id)->exists());

        // Código para todos: Ventas ya no entra sin él.
        $this->as($admin);
        $this->putJson('/api/v1/security/policy', ['require_mfa_all' => true, 'session_hours' => 8, 'allow_remember' => true, 'idle_minutes' => 0])->assertOk();
        $this->login($seller)->assertOk()->assertJsonPath('mfa_required', true);
    }

    public function test_failed_attempts_show_in_the_access_activity_and_permissions_apply(): void
    {
        $this->makeTenant(['COTIZACIONES']);
        $seller = $this->makeUser('Ventas', ['quotations.view']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['email' => $seller->email, 'password' => 'otra-clave'])->assertStatus(422);

        $this->as($this->makeUser('Gerencia', ['audit.view']));
        $activity = $this->getJson('/api/v1/security')->assertOk()->json('activity');
        $this->assertSame(1, $activity['failed']);
        $this->assertSame('Contraseña incorrecta', $activity['recent'][0]['label']);
        // Quien solo ve no cambia la política.
        $this->putJson('/api/v1/security/policy', ['require_mfa_all' => true, 'session_hours' => 8, 'allow_remember' => true, 'idle_minutes' => 0])->assertForbidden();

        $this->as($seller);
        $this->getJson('/api/v1/security')->assertForbidden();
        $this->postJson('/api/v1/security/isolation-probe')->assertForbidden();
    }
}
