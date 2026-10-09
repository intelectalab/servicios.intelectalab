<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exportar es un permiso aparte de ver: quien ve un listado no por eso se lo lleva entero
 * en un archivo. Lo comprueba la API, no solo el botón, y cada exportación queda en auditoría.
 */
class ExportPermissionTest extends TestCase
{
    use DatabaseTransactions;

    /** Listado => recurso de su permiso de exportar. */
    private const LISTS = [
        '/api/v1/parties' => 'core',
        '/api/v1/services' => 'services',
        '/api/v1/quotations' => 'quotations',
        '/api/v1/contracts' => 'contracts',
        '/api/v1/orders' => 'orders',
        '/api/v1/portal-requests' => 'portal',
        '/api/v1/crm/leads' => 'leads',
        '/api/v1/crm/opportunities' => 'opportunities',
        '/api/v1/users' => 'users',
    ];

    private string $tenantId;

    private function makeTenant(array $modules = ['SERVICIOS', 'COTIZACIONES', 'CONTRATOS', 'CRM']): void
    {
        $this->tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $this->tenantId, 'code' => 'test-'.Str::random(8), 'name' => 'Empresa de Prueba SAC', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SET app.tenant_id = '{$this->tenantId}'");
        foreach ($modules as $module) {
            DB::table('tenant_modules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'module_code' => $module, 'enabled' => true,
            ]);
        }
    }

    private function makeUser(string $name, array $permissions): User
    {
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId, 'tenant_id' => $this->tenantId, 'code' => Str::upper(Str::random(8)), 'name' => $name,
            'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        $user = User::create([
            'email' => 'export-'.Str::random(8).'@erp-saas.test', 'password_hash' => bcrypt('Actual123'), 'name' => $name, 'status' => 'ACTIVE',
        ]);
        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $user->id,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'role_id' => $roleId, 'scope' => 'TENANT', 'created_at' => now(),
        ]);

        return $user;
    }

    private function views(): array
    {
        return array_map(fn (string $resource) => "{$resource}.view", array_values(self::LISTS));
    }

    private function exports(): int
    {
        return DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('entity_type', 'Export')->count();
    }

    public function test_seeing_a_list_does_not_allow_exporting_it(): void
    {
        $this->makeTenant();
        Sanctum::actingAs($this->makeUser('Solo ve', $this->views()));

        foreach (self::LISTS as $path => $resource) {
            // El listado de la pantalla sigue funcionando…
            $this->getJson($path)->assertOk();
            // …pero las páginas para armar el archivo, no.
            $this->getJson("{$path}?export=1&per_page=200")->assertStatus(403)
                ->assertJsonPath('code', 'PERMISSION_DENIED')
                ->assertJsonPath('message', "No tienes el permiso requerido: {$resource}.export");
            // Ni el registro de la exportación (las filas marcadas en pantalla tampoco salen).
            $this->postJson('/api/v1/exports', ['resource' => $resource, 'format' => 'xlsx', 'rows' => 3, 'selection' => true])
                ->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        }
        $this->assertSame(0, $this->exports());
    }

    public function test_exporting_asks_for_its_permission_and_is_audited(): void
    {
        $this->makeTenant();
        $user = $this->makeUser('Exporta cotizaciones', [...$this->views(), 'quotations.export']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/quotations?export=1&per_page=200')->assertOk();
        $this->postJson('/api/v1/exports', ['resource' => 'quotations', 'format' => 'csv', 'rows' => 12, 'filters' => 'status=APPROVED'])
            ->assertOk()->assertJsonPath('recorded', true);

        // Queda quién, qué listado, en qué formato, cuántas filas y con qué filtros.
        $log = DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('entity_type', 'Export')->first();
        $this->assertSame([$user->id, 'quotations.export'], [$log->user_id, $log->action]);
        $this->assertEquals(
            ['resource' => 'quotations', 'format' => 'csv', 'rows' => 12, 'selection' => false, 'filters' => 'status=APPROVED'],
            json_decode($log->metadata, true),
        );

        // El permiso es por listado: exportar cotizaciones no deja exportar clientes.
        $this->getJson('/api/v1/parties?export=1')->assertStatus(403);
        $this->postJson('/api/v1/exports', ['resource' => 'core', 'format' => 'csv', 'rows' => 1])->assertStatus(403);
        // Un listado que no existe o un formato desconocido no se registra.
        $this->postJson('/api/v1/exports', ['resource' => 'audit', 'format' => 'csv', 'rows' => 1])->assertStatus(422);
        $this->postJson('/api/v1/exports', ['resource' => 'quotations', 'format' => 'pdf', 'rows' => 1])->assertStatus(422);
        $this->assertSame(1, $this->exports());
    }

    public function test_export_permission_is_worthless_without_the_module(): void
    {
        $this->makeTenant(['COTIZACIONES']);
        Sanctum::actingAs($this->makeUser('Sin CRM', ['leads.view', 'leads.export', 'quotations.view', 'quotations.export']));

        $this->postJson('/api/v1/exports', ['resource' => 'leads', 'format' => 'xlsx', 'rows' => 1])->assertStatus(403);
        $this->postJson('/api/v1/exports', ['resource' => 'quotations', 'format' => 'xlsx', 'rows' => 1])->assertOk();
    }

    public function test_no_list_hands_over_more_than_the_page_limit(): void
    {
        $this->makeTenant();
        Sanctum::actingAs($this->makeUser('Curiosa', $this->views()));

        foreach (array_keys(self::LISTS) as $path) {
            if ($path === '/api/v1/users') {
                continue; // no se pagina: son las personas de la empresa
            }
            $this->getJson("{$path}?per_page=100000")->assertOk()->assertJsonPath('per_page', 200);
        }
        $this->getJson('/api/v1/quotations?per_page=50')->assertOk()->assertJsonPath('per_page', 50);
    }

    public function test_roles_matrix_offers_the_export_permission(): void
    {
        $this->makeTenant();
        Sanctum::actingAs($this->makeUser('Admin', [...$this->views(), 'users.manage']));

        $codes = collect($this->getJson('/api/v1/roles')->assertOk()->json('permissions'))->pluck('code');
        foreach (array_values(self::LISTS) as $resource) {
            $this->assertTrue($codes->contains("{$resource}.export"), "{$resource}.export");
        }
    }
}
