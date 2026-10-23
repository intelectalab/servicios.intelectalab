<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Subscription\Support\ModulePermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use DatabaseTransactions;

    /** Usuario miembro de un tenant nuevo con esos módulos encendidos y esos permisos. */
    private function makeTenantUser(array $modules = [], array $permissions = []): User
    {
        $user = User::create([
            'email' => 'mod-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('password123'),
            'name' => 'Test Módulos',
            'status' => 'ACTIVE',
        ]);

        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'code' => 'test-'.Str::random(8),
            'name' => 'Test Tenant',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement("SET app.tenant_id = '{$tenantId}'");

        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($modules as $code) {
            DB::table('tenant_modules')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'module_code' => $code,
                'enabled' => true,
                'configuration' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($permissions) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId,
                'tenant_id' => $tenantId,
                'code' => 'VENTAS',
                'name' => 'Ventas',
                'is_system' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_roles')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'role_id' => $roleId,
                'scope' => 'TENANT',
                'created_at' => now(),
            ]);
            foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        return $user;
    }

    public function test_access_returns_modules_and_permissions_separately(): void
    {
        Sanctum::actingAs($this->makeTenantUser(['SERVICIOS'], ['core.view']));

        $access = $this->getJson('/api/v1/me/access')->assertOk()->json();

        // Obligatorios siempre, más el contratado; no los que el plan no incluye.
        foreach (['CLIENTES', 'SERVICIOS'] as $code) {
            $this->assertContains($code, $access['modules']);
        }
        $this->assertNotContains('COTIZACIONES', $access['modules']);
        $this->assertNotContains('CRM', $access['modules']);

        // Tener el módulo no da el permiso: eso depende del rol.
        $this->assertSame(['core.view'], $access['permissions']);
    }

    public function test_purchasing_and_accounting_are_their_own_modules(): void
    {
        // Con solo Finanzas: cobra y factura, pero Compras y Contabilidad quedan fuera del plan.
        Sanctum::actingAs($this->makeTenantUser(['FINANZAS'], ['billing.view', 'purchases.view', 'accounting.view']));
        $this->getJson('/api/v1/invoices')->assertOk();
        $this->getJson('/api/v1/purchase-orders')->assertStatus(403);
        $this->getJson('/api/v1/journal-entries')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->makeTenantUser(['FINANZAS', 'COMPRAS'], ['purchases.view', 'accounting.view']));
        $this->getJson('/api/v1/purchase-orders')->assertOk();
        $this->getJson('/api/v1/journal-entries')->assertStatus(403);
    }

    public function test_module_codes_stored_in_lowercase_still_count(): void
    {
        Sanctum::actingAs($this->makeTenantUser(['crm'], ['leads.view']));

        $this->assertContains('CRM', $this->getJson('/api/v1/me/access')->json('modules'));
        $this->getJson('/api/v1/crm/leads')->assertOk();
    }

    public function test_module_route_is_closed_when_the_tenant_does_not_have_it(): void
    {
        Sanctum::actingAs($this->makeTenantUser([], ['leads.view']));

        $this->getJson('/api/v1/crm/leads')->assertStatus(403);
    }

    public function test_catalog_defines_the_permissions_of_each_module(): void
    {
        $base = ModulePermissions::codesFor([]);
        $this->assertContains('core.view', $base);
        $this->assertContains('core.manage', $base);
        $this->assertContains('tenant.manage', $base);
        $this->assertNotContains('billing.view', $base);

        $withFinance = ModulePermissions::codesFor(['finanzas', 'CRM']);
        foreach (['billing.view', 'billing.manage', 'finance.view', 'leads.manage', 'opportunities.manage'] as $code) {
            $this->assertContains($code, $withFinance);
        }
    }
}
