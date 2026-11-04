<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Subscription\Support\ModulePermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pantalla Auditoría: solo lectura, con su permiso, solo de la propia empresa, y con buscar,
 * filtrar, ordenar y exportar resueltos en la API sobre todo el registro.
 */
class AuditScreenTest extends TestCase
{
    use DatabaseTransactions;

    private string $tenantId;

    private function makeTenant(): string
    {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId, 'code' => 'test-'.Str::random(8), 'name' => 'Empresa de Prueba SAC', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SET app.tenant_id = '{$tenantId}'");
        $this->asPlatformFixture(fn () => DB::table('tenant_modules')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'module_code' => 'COTIZACIONES', 'enabled' => true,
        ]));

        return $this->tenantId = $tenantId;
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
            'email' => 'audit-'.Str::random(8).'@erp-saas.test', 'password_hash' => bcrypt('Actual123'), 'name' => $name, 'status' => 'ACTIVE',
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

    /** Un movimiento en la auditoría de la empresa actual. */
    private function log(?User $user, string $action, ?string $entityType, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('audit_logs')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'user_id' => $user?->id, 'action' => $action,
            'entity_type' => $entityType, 'entity_id' => $extra['entity_id'] ?? (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(), 'ip_address' => $extra['ip'] ?? '10.0.0.7', 'user_agent' => 'PHPUnit',
            'before_data' => isset($extra['before']) ? json_encode($extra['before']) : null,
            'after_data' => isset($extra['after']) ? json_encode($extra['after']) : null,
            'metadata' => json_encode($extra['metadata'] ?? []),
            'created_at' => $extra['at'] ?? now(),
        ]);

        return $id;
    }

    private function ids(string $query = ''): array
    {
        return $this->getJson("/api/v1/audit?{$query}")->assertOk()->json('data.*.id');
    }

    public function test_audit_asks_for_its_permission(): void
    {
        $this->makeTenant();
        Sanctum::actingAs($this->makeUser('Sin auditoría', ['users.view', 'quotations.view']));

        foreach (['/api/v1/audit', '/api/v1/audit/options'] as $path) {
            $this->getJson($path)->assertStatus(403)
                ->assertJsonPath('code', 'PERMISSION_DENIED')
                ->assertJsonPath('message', 'No tienes el permiso requerido: audit.view');
        }
    }

    public function test_audit_shows_who_did_what_with_before_and_after(): void
    {
        $this->makeTenant();
        $auditor = $this->makeUser('Auditora', ['audit.view']);
        $seller = $this->makeUser('Vendedor Uno', []);
        $entityId = (string) Str::uuid();
        $id = $this->log($seller, 'quotation.approve', 'Quotation', [
            'entity_id' => $entityId, 'ip' => '190.12.1.9',
            'before' => ['status' => 'SUBMITTED'], 'after' => ['status' => 'APPROVED'],
            'metadata' => ['quotation_number' => 'COT-2026-0001'],
        ]);
        $this->log(null, 'auth.account_locked', 'User');
        Sanctum::actingAs($auditor);

        $response = $this->getJson('/api/v1/audit')->assertOk()->assertJsonPath('total', 2);
        $row = collect($response->json('data'))->firstWhere('id', $id);

        $this->assertSame('quotation.approve', $row['action']);
        $this->assertSame('Aprobación', $row['action_label']);
        $this->assertSame(['Quotation', 'Cotización', $entityId], [$row['entity_type'], $row['entity_label'], $row['entity_id']]);
        $this->assertSame(['id' => $seller->id, 'name' => 'Vendedor Uno', 'email' => $seller->email], $row['user']);
        $this->assertSame('190.12.1.9', $row['ip_address']);
        $this->assertSame(['status' => 'SUBMITTED'], $row['before']);
        $this->assertSame(['status' => 'APPROVED'], $row['after']);
        $this->assertSame(['quotation_number' => 'COT-2026-0001'], $row['metadata']);
        $this->assertTrue(Str::isUuid($row['correlation_id']));

        // Un movimiento sin persona (lo hizo el sistema) y sin detalle sale igual, con sus vacíos.
        $system = collect($response->json('data'))->firstWhere('action', 'auth.account_locked');
        $this->assertNull($system['user']);
        $this->assertNull($system['before']);
        $this->assertNull($system['metadata']);
        $this->assertSame('Cuenta bloqueada por intentos fallidos', $system['action_label']);
    }

    public function test_audit_never_shows_another_company(): void
    {
        $this->makeTenant();
        $other = $this->tenantId;
        $stranger = $this->makeUser('De otra empresa', ['audit.view']);
        $foreign = $this->log($stranger, 'quotation.create', 'Quotation');

        $this->makeTenant();
        $auditor = $this->makeUser('Auditora', ['audit.view']);
        $own = $this->log($auditor, 'service.create', 'Service');
        Sanctum::actingAs($auditor);

        $this->assertSame([$own], $this->ids());
        // Ni buscándolo por su identificador, ni filtrando por la persona de la otra empresa.
        $this->assertSame([], $this->ids("search={$foreign}"));
        $this->assertSame([], $this->ids("user_id={$stranger->id}"));

        $options = $this->getJson('/api/v1/audit/options')->assertOk();
        $this->assertSame(['Service'], $options->json('entities.*.code'));
        $this->assertSame([$auditor->id], $options->json('users.*.id'));
        $this->assertNotSame($other, $this->tenantId);
    }

    public function test_audit_hides_secrets_that_an_old_entry_may_hold(): void
    {
        $this->makeTenant();
        $auditor = $this->makeUser('Auditora', ['audit.view']);
        $this->log($auditor, 'user.update', 'User', [
            'before' => ['name' => 'Ana', 'password_hash' => 'x1'],
            'after' => ['name' => 'Ana María', 'nested' => ['api_token' => 'x2', 'role' => 'Ventas']],
            'metadata' => ['client_secret' => 'x3', 'mfa' => true],
        ]);
        Sanctum::actingAs($auditor);

        $row = $this->getJson('/api/v1/audit')->assertOk()->json('data.0');

        $this->assertSame(['name' => 'Ana', 'password_hash' => '[oculto]'], $row['before']);
        $this->assertEquals(['name' => 'Ana María', 'nested' => ['api_token' => '[oculto]', 'role' => 'Ventas']], $row['after']);
        $this->assertEquals(['client_secret' => '[oculto]', 'mfa' => true], $row['metadata']);
    }

    public function test_audit_searches_filters_and_sorts_over_the_whole_log(): void
    {
        $this->makeTenant();
        $auditor = $this->makeUser('Auditora', ['audit.view']);
        $zoe = $this->makeUser('Zoe Vargas', []);
        $beto = $this->makeUser('Beto Arce', []);

        $approve = $this->log($zoe, 'quotation.approve', 'Quotation', ['at' => now()->subDays(3), 'ip' => '10.0.0.1']);
        $login = $this->log($beto, 'auth.login', 'User', ['at' => now()->subDays(2), 'ip' => '10.0.0.2']);
        $scopes = $this->log($zoe, 'role.scopes.update', 'Role', ['at' => now()->subDay(), 'ip' => '10.0.0.3']);
        $export = $this->log($beto, 'quotations.export', 'Export', ['at' => now(), 'ip' => '10.0.0.4']);
        Sanctum::actingAs($auditor);

        // Sin pedir orden: lo más reciente primero.
        $this->assertSame([$export, $scopes, $login, $approve], $this->ids());

        // El buscador encuentra por el código, por el texto que se lee, por la persona y por la dirección.
        $this->assertSame([$approve], $this->ids('search=quotation.approve'));
        $this->assertSame([$approve], $this->ids('search='.urlencode('cotizacion')));
        $this->assertSame([$approve], $this->ids('search='.urlencode('Aprobación')));
        $this->assertSame([$login], $this->ids('search='.urlencode('inicio de sesion')));
        $this->assertSame([$export, $login], $this->ids('search=beto'));
        $this->assertSame([$scopes], $this->ids('search=10.0.0.3'));
        // "Edición" es el texto de los "update" corrientes, no el de un cambio de alcance.
        $this->assertSame([], $this->ids('search='.urlencode('edición')));
        $this->assertSame([], $this->ids('search='.urlencode('100%')));

        // Filtros de arriba.
        $this->assertSame([$scopes], $this->ids('entity=Role'));
        $this->assertSame([$scopes, $approve], $this->ids("user_id={$zoe->id}"));
        $this->assertSame([$approve], $this->ids("user_id={$zoe->id}&entity=Quotation"));

        // Filtros por columna.
        $filter = fn (array $filter) => 'filters='.urlencode(json_encode([$filter]));
        $this->assertSame([$export], $this->ids($filter(['id' => 'at', 'type' => 'date', 'operator' => 'today', 'value' => ''])));
        $this->assertSame([$scopes, $login], $this->ids($filter([
            'id' => 'at', 'type' => 'date', 'operator' => 'range',
            'value' => now()->subDays(2)->format('Y-m-d').'|'.now()->subDay()->format('Y-m-d'),
        ])));
        $this->assertSame([$scopes], $this->ids($filter(['id' => 'action', 'type' => 'text', 'operator' => 'contains', 'value' => 'alcance'])));
        $this->assertSame([$export], $this->ids($filter(['id' => 'action', 'type' => 'text', 'operator' => 'contains', 'value' => 'quotations.export'])));
        $this->assertSame([$login], $this->ids($filter(['id' => 'ip', 'type' => 'text', 'operator' => 'equals', 'value' => '10.0.0.2'])));

        // Orden pedido desde la cabecera.
        $this->assertSame([$approve, $login, $scopes, $export], $this->ids('sort=at&dir=asc'));
        // Beto antes que Zoe (entre los dos de una misma persona desempata el identificador).
        $this->assertEqualsCanonicalizing([$login, $export], array_slice($this->ids('sort=actor&dir=asc'), 0, 2));
        $this->assertEqualsCanonicalizing([$login, $export], array_slice($this->ids('sort=actor&dir=desc'), 2, 2));
        // Por el texto de la entidad: Cotización, Exportación de listado, Rol, Usuario.
        $this->assertSame([$approve, $export, $scopes, $login], $this->ids('sort=entity&dir=asc'));
        // Por el texto de la acción: Aprobación, Cambio de alcance…, Exportación, Inicio de sesión.
        $this->assertSame([$approve, $scopes, $export, $login], $this->ids('sort=action&dir=asc'));

        // Paginado en el servidor, con el tope de filas por página.
        $this->getJson('/api/v1/audit?per_page=3&page=2')->assertOk()
            ->assertJsonPath('total', 4)->assertJsonPath('last_page', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/audit?per_page=100000')->assertOk()->assertJsonPath('per_page', 200);
    }

    public function test_exporting_the_audit_asks_for_its_permission_and_is_audited(): void
    {
        $this->makeTenant();
        $viewer = $this->makeUser('Solo ve', ['audit.view']);
        $this->log($viewer, 'auth.login', 'User');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/audit?export=1&per_page=200')->assertStatus(403)
            ->assertJsonPath('message', 'No tienes el permiso requerido: audit.export');
        $this->postJson('/api/v1/exports', ['resource' => 'audit', 'format' => 'xlsx', 'rows' => 1])->assertStatus(403);
        $this->assertSame(0, DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('entity_type', 'Export')->count());

        $exporter = $this->makeUser('Exporta', ['audit.view', 'audit.export']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($exporter);

        $this->getJson('/api/v1/audit?export=1&per_page=200')->assertOk();
        $this->postJson('/api/v1/exports', ['resource' => 'audit', 'format' => 'csv', 'rows' => 1, 'filters' => 'entity=User'])
            ->assertOk()->assertJsonPath('recorded', true);

        // Llevarse la auditoría también queda en la auditoría, y se ve en la pantalla.
        $row = $this->getJson('/api/v1/audit?entity=Export')->assertOk()->assertJsonPath('total', 1)->json('data.0');
        $this->assertSame(['audit.export', 'Exportación', 'Exportación de listado', $exporter->id], [$row['action'], $row['action_label'], $row['entity_label'], $row['user']['id']]);
        $this->assertSame('entity=User', $row['metadata']['filters']);
    }

    public function test_audit_is_read_only_for_the_application(): void
    {
        $this->makeTenant();
        $auditor = $this->makeUser('Auditora', ['audit.view', 'audit.export']);
        $id = $this->log($auditor, 'auth.login', 'User');
        Sanctum::actingAs($auditor);

        // No hay ruta para cambiar ni borrar un movimiento…
        foreach (['putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->assertContains($this->{$verb}("/api/v1/audit/{$id}")->status(), [404, 405]);
        }
        $this->postJson('/api/v1/audit', ['action' => 'falso'])->assertStatus(405);
        $this->assertSame(1, DB::table('audit_logs')->where('id', $id)->count());
    }

    public function test_the_roles_matrix_offers_the_audit_permissions(): void
    {
        $this->makeTenant();
        Sanctum::actingAs($this->makeUser('Administra usuarios', ['users.view']));

        $offered = $this->getJson('/api/v1/roles')->assertOk()->json('permissions.*.code');

        $this->assertContains('audit.view', $offered);
        $this->assertContains('audit.export', $offered);
        // El administrador de una empresa nueva los recibe: no dependen de ningún módulo.
        $this->assertContains('audit.view', ModulePermissions::BASE);
        $this->assertContains('audit.export', ModulePermissions::BASE);
    }
}
