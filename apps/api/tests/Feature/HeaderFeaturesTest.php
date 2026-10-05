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
}
