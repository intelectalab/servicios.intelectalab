<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HeaderFeaturesTest extends TestCase
{
    use DatabaseTransactions;

    /** Usuario con rol "Ventas" (y esos permisos) en un tenant nuevo. Devuelve [usuario, tenantId]. */
    private function makeTenantUser(array $permissions = ['core.view']): array
    {
        $user = User::create([
            'email' => 'hdr-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('Actual123'),
            'name' => 'Test Cabecera',
            'status' => 'ACTIVE',
        ]);

        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'code' => 'test-'.Str::random(8),
            'name' => 'Empresa de Prueba SAC',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement("SET app.tenant_id = '{$tenantId}'");

        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId, 'tenant_id' => $tenantId, 'code' => 'VENTAS', 'name' => 'Ventas',
            'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $roleId,
            'scope' => 'TENANT', 'created_at' => now(),
        ]);
        foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        return [$user, $tenantId];
    }

    private function makeOrganizationCustomer(string $tenantId, string $legalName, string $ruc): string
    {
        $partyId = (string) Str::uuid();
        DB::table('parties')->insert([
            'id' => $partyId, 'tenant_id' => $tenantId, 'party_type' => 'ORGANIZATION',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('organizations')->insert([
            'party_id' => $partyId, 'legal_name' => $legalName, 'tax_identifier' => $ruc,
        ]);

        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId, 'tenant_id' => $tenantId, 'party_id' => $partyId,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $customerId;
    }

    public function test_access_tells_the_header_the_tenant_and_the_role(): void
    {
        [$user] = $this->makeTenantUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/access')->assertOk()
            ->assertJsonPath('tenant.name', 'Empresa de Prueba SAC')
            ->assertJsonPath('roles', ['Ventas']);
    }

    public function test_search_finds_customers_by_name_and_document(): void
    {
        [$user, $tenantId] = $this->makeTenantUser();
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/search?q=los andes')->assertOk()
            ->assertJsonFragment(['type' => 'customer', 'id' => $customerId, 'title' => 'Minera Los Andes SAC', 'subtitle' => 'RUC 20123456789']);

        $this->getJson('/api/v1/search?q=2012345')->assertOk()->assertJsonFragment(['id' => $customerId]);

        // Menos de 2 caracteres o comodines sueltos no devuelven todo.
        $this->getJson('/api/v1/search?q=m')->assertOk()->assertJsonPath('results', []);
        $this->getJson('/api/v1/search?q=%25%25')->assertOk()->assertJsonPath('results', []);
    }

    public function test_search_does_not_cross_tenants(): void
    {
        [, $otherTenantId] = $this->makeTenantUser();
        $this->makeOrganizationCustomer($otherTenantId, 'Cliente Ajeno SAC', '20999999991');

        [$user] = $this->makeTenantUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/search?q=Cliente Ajeno')->assertOk()->assertJsonPath('results', []);
    }

    public function test_search_needs_the_core_permission(): void
    {
        [$user] = $this->makeTenantUser([]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/search?q=andes')->assertStatus(403);
    }

    public function test_user_can_change_the_password_with_the_current_one(): void
    {
        [$user] = $this->makeTenantUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/password', ['current_password' => 'incorrecta', 'password' => 'NuevaClave1'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->postJson('/api/v1/me/password', ['current_password' => 'Actual123', 'password' => 'debil'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/me/password', ['current_password' => 'Actual123', 'password' => 'Actual123'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('Actual123', $user->fresh()->password_hash));

        $this->postJson('/api/v1/me/password', ['current_password' => 'Actual123', 'password' => 'NuevaClave1'])
            ->assertOk();

        $this->assertTrue(Hash::check('NuevaClave1', $user->fresh()->password_hash));
    }

    private const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    /** Inicia sesión de verdad (la cuenta de prueba no pide código) y devuelve el token. */
    private function signIn(User $user, string $userAgent): string
    {
        return $this->withHeaders(['User-Agent' => $userAgent])
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Actual123'])
            ->assertOk()->json('token');
    }

    /** Las siguientes peticiones salen con ese token (la sesión anterior no se queda en memoria). */
    private function using(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_profile_shows_the_account_and_its_open_sessions(): void
    {
        [$user, $tenantId] = $this->makeTenantUser();
        [$other] = $this->makeTenantUser();
        DB::statement("SET app.tenant_id = '{$tenantId}'");
        $desktop = $this->signIn($user, self::DESKTOP);
        $phone = $this->signIn($user, self::PHONE);
        $this->signIn($other, self::DESKTOP);

        $profile = $this->using($phone)->getJson('/api/v1/me/profile')->assertOk()
            ->assertJsonPath('name', 'Test Cabecera')
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('tenant', 'Empresa de Prueba SAC')
            ->assertJsonPath('roles', ['Ventas'])
            ->assertJsonPath('branch', null)
            ->assertJsonPath('mfa', false)
            ->assertJsonPath('is_super_admin', false)
            ->assertJsonPath('notification_preferences', ['in_app' => true, 'email' => true, 'whatsapp' => true])
            ->json();

        // Solo sus sesiones, la más reciente primero, con la actual marcada y desde dónde se abrió.
        $this->assertCount(2, $profile['sessions']);
        $this->assertSame(
            [[true, true, 'Safari en iOS'], [false, false, 'Chrome en Windows']],
            array_map(fn ($session) => [$session['current'], $session['mobile'], $session['browser']], $profile['sessions']),
        );
        // Nada del token viaja a la pantalla.
        $this->assertArrayNotHasKey('token', $profile['sessions'][0]);

        $this->using($desktop)->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('sessions.1.current', true);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/me/profile')->assertStatus(401);
    }

    public function test_user_closes_the_other_sessions_and_keeps_the_current_one(): void
    {
        [$user, $tenantId] = $this->makeTenantUser();
        [$other] = $this->makeTenantUser();
        DB::statement("SET app.tenant_id = '{$tenantId}'");
        $desktop = $this->signIn($user, self::DESKTOP);
        $phone = $this->signIn($user, self::PHONE);
        $tablet = $this->signIn($user, self::PHONE);
        $foreign = $this->signIn($other, self::DESKTOP);

        $this->using($desktop)->postJson('/api/v1/me/sessions/revoke-others')->assertOk()->assertJsonPath('closed', 2);

        // Las otras dos dejaron de valer; la actual sigue, y la de otra persona no se tocó.
        $this->using($phone)->getJson('/api/v1/me')->assertStatus(401);
        $this->using($tablet)->getJson('/api/v1/me')->assertStatus(401);
        $this->using($foreign)->getJson('/api/v1/me')->assertOk();
        $this->using($desktop)->getJson('/api/v1/me/profile')->assertOk()->assertJsonCount(1, 'sessions');

        DB::statement("SET app.tenant_id = '{$tenantId}'");
        $log = DB::table('audit_logs')->where('tenant_id', $tenantId)->where('action', 'auth.sessions_revoked')->first();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(2, json_decode($log->metadata, true)['sessions']);

        // Sin más sesiones que cerrar no pasa nada.
        $this->using($desktop)->postJson('/api/v1/me/sessions/revoke-others')->assertOk()->assertJsonPath('closed', 0);
    }

    public function test_user_chooses_the_notification_channels(): void
    {
        [$user, $tenantId] = $this->makeTenantUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/me/notification-preferences', ['in_app' => true, 'email' => false])
            ->assertStatus(422)->assertJsonValidationErrors('whatsapp');
        $this->putJson('/api/v1/me/notification-preferences', ['in_app' => true, 'email' => 'tal vez', 'whatsapp' => false])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->putJson('/api/v1/me/notification-preferences', ['in_app' => true, 'email' => false, 'whatsapp' => false])
            ->assertOk()->assertExactJson(['in_app' => true, 'email' => false, 'whatsapp' => false]);
        // Guardar otra vez corrige la misma fila.
        $this->putJson('/api/v1/me/notification-preferences', ['in_app' => false, 'email' => false, 'whatsapp' => true])->assertOk();
        $this->assertSame(1, DB::table('notification_preferences')->where('user_id', $user->id)->count());
        $this->getJson('/api/v1/me/profile')->assertOk()
            ->assertJsonPath('notification_preferences', ['in_app' => false, 'email' => false, 'whatsapp' => true]);

        $log = DB::table('audit_logs')->where('tenant_id', $tenantId)->where('action', 'user.notification_preferences')->orderByDesc('id')->get();
        $this->assertCount(2, $log);

        // Las de otra persona no cambian.
        [$other] = $this->makeTenantUser();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/me/profile')->assertOk()
            ->assertJsonPath('notification_preferences', ['in_app' => true, 'email' => true, 'whatsapp' => true]);
    }
}
