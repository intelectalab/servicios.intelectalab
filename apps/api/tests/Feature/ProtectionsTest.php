<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Protecciones del sistema que no dependen de una pantalla: aislamiento en la base,
 * auditoría inalterable, módulos del plan, acceso y operaciones que no se duplican.
 */
class ProtectionsTest extends TestCase
{
    use DatabaseTransactions;

    /** Empresa nueva con un usuario que tiene esos permisos y esos módulos. Devuelve [usuario, tenantId]. */
    private function makeTenantUser(array $permissions, array $modules = []): array
    {
        $user = User::create([
            'email' => 'prot-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('Actual123'),
            'name' => 'Usuario de Prueba',
            'status' => 'ACTIVE',
        ]);

        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId, 'code' => 'test-'.Str::random(8), 'name' => 'Empresa de Prueba SAC', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
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
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $roleId, 'scope' => 'TENANT', 'created_at' => now(),
        ]);
        foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        foreach ($modules as $module) {
            DB::table('tenant_modules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'module_code' => $module, 'enabled' => true,
            ]);
        }

        return [$user, $tenantId];
    }

    /** Ejecuta algo que la base debe rechazar, sin dejar rota la transacción de la prueba. */
    private function assertDatabaseRejects(callable $attempt, string $message): void
    {
        try {
            DB::transaction($attempt);
            $this->fail($message);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_people_and_organizations_are_isolated_by_the_database_itself(): void
    {
        [$user, $tenantA] = $this->makeTenantUser(['core.view', 'core.manage']);
        Sanctum::actingAs($user);
        $org = $this->postJson('/api/v1/parties', [
            'kind' => 'ORGANIZATION', 'legal_name' => 'Aislada SAC', 'tax_identifier' => '20999000111', 'roles' => ['CUSTOMER'],
        ])->assertCreated()->json();
        $person = $this->postJson('/api/v1/parties', [
            'kind' => 'PERSON', 'given_names' => 'Persona', 'family_name' => 'Aislada', 'document_type' => 'DNI',
            'document_number' => '70999111', 'roles' => ['CONTACT'],
        ])->assertCreated()->json();

        // Desde su empresa se ven.
        $this->assertSame(1, DB::table('organizations')->where('party_id', $org['id'])->count());
        $this->assertSame(1, DB::table('persons')->where('party_id', $person['id'])->count());

        // Desde otra empresa, consultando las tablas directamente (sin pasar por parties), no.
        [, $tenantB] = $this->makeTenantUser(['core.view']);
        $this->assertNotSame($tenantA, $tenantB);
        $this->assertSame(0, DB::table('organizations')->where('party_id', $org['id'])->count());
        $this->assertSame(0, DB::table('persons')->where('party_id', $person['id'])->count());
        $this->assertSame(0, DB::table('organizations')->where('tax_identifier', '20999000111')->count());

        // Ni se puede colgar una persona de una identidad de otra empresa.
        $this->assertDatabaseRejects(fn () => DB::table('persons')->insert([
            'party_id' => $org['id'], 'given_names' => 'Intrusa', 'family_name' => 'Ajena',
        ]), 'Se pudo escribir una persona en una identidad de otra empresa.');

        // Sin empresa en curso no se ve ninguna.
        DB::statement("SELECT set_config('app.tenant_id', '', false)");
        $this->assertSame(0, DB::table('organizations')->count());
        $this->assertSame(0, DB::table('persons')->count());
    }

    public function test_audit_log_cannot_be_changed_or_deleted_by_the_application(): void
    {
        [$user] = $this->makeTenantUser(['core.view', 'core.manage']);
        Sanctum::actingAs($user);
        $org = $this->postJson('/api/v1/parties', [
            'kind' => 'ORGANIZATION', 'legal_name' => 'Auditada SAC', 'tax_identifier' => '20999000222', 'roles' => ['CUSTOMER'],
        ])->assertCreated()->json();

        $log = DB::table('audit_logs')->where('entity_id', $org['id'])->first();
        $this->assertNotNull($log, 'El alta no dejó registro de auditoría.');

        $this->assertDatabaseRejects(
            fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'alterado']),
            'Se pudo modificar un registro de auditoría.',
        );
        $this->assertDatabaseRejects(
            fn () => DB::table('audit_logs')->where('id', $log->id)->delete(),
            'Se pudo borrar un registro de auditoría.',
        );
        $this->assertSame($log->action, DB::table('audit_logs')->where('id', $log->id)->value('action'));
    }

    public function test_permissions_catalog_is_read_only_for_the_application(): void
    {
        $this->makeTenantUser(['core.view']);

        $this->assertDatabaseRejects(fn () => DB::table('permissions')->insert([
            'id' => (string) Str::uuid(), 'code' => 'intruso.total', 'name' => 'Permiso inventado',
        ]), 'La aplicación pudo crear un permiso.');
    }

    public function test_a_permission_only_works_if_the_company_has_its_module(): void
    {
        // Tiene el permiso de cotizaciones, pero su plan no incluye el módulo.
        [$user, $tenantId] = $this->makeTenantUser(['core.view', 'quotations.view', 'services.view'], ['SERVICIOS']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/quotations')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
        // El módulo que sí tiene y los obligatorios (Clientes y actores) funcionan.
        $this->getJson('/api/v1/services')->assertOk();
        $this->getJson('/api/v1/parties')->assertOk();
        // Sin el permiso sigue siendo "sin permiso", no "sin módulo".
        $this->getJson('/api/v1/contracts')->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');

        // Al activarle el módulo, entra.
        DB::table('tenant_modules')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'module_code' => 'COTIZACIONES', 'enabled' => true,
        ]);
        $this->getJson('/api/v1/quotations')->assertOk();
    }

    public function test_user_removed_from_its_company_gets_no_session(): void
    {
        [$user, $tenantId] = $this->makeTenantUser(['core.view']);
        $login = fn () => $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Actual123']);

        $login()->assertOk()->assertJsonStructure(['token']);

        DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $user->id)->update(['status' => 'INACTIVE']);

        $login()->assertStatus(422)->assertJsonMissingPath('token')
            ->assertJsonPath('errors.email.0', 'Este usuario no está activo.');
    }

    public function test_changing_the_password_closes_the_other_sessions(): void
    {
        [$user] = $this->makeTenantUser(['core.view']);
        $login = fn () => $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Actual123'])->assertOk()->json('token');

        $other = $login();
        $current = $login();
        $this->assertSame(2, $user->tokens()->count());

        $this->withToken($current)->postJson('/api/v1/me/password', [
            'current_password' => 'Actual123', 'password' => 'NuevaClave9',
        ])->assertOk();

        // Queda solo la sesión desde la que se cambió.
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame((int) explode('|', $current)[0], (int) $user->tokens()->value('id'));
        $this->assertNotSame($other, $current);
    }

    public function test_old_password_hashes_are_upgraded_when_the_user_logs_in(): void
    {
        [$user] = $this->makeTenantUser(['core.view']);
        $this->assertStringStartsWith('$2y$', $user->password_hash);

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Actual123'])->assertOk();

        $hash = $user->fresh()->password_hash;
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue(Hash::check('Actual123', $hash));
    }

    public function test_a_quotation_cannot_produce_two_contracts_or_two_orders(): void
    {
        [$user, $tenantId] = $this->makeTenantUser(['core.view', 'core.manage']);
        Sanctum::actingAs($user);
        $customerId = $this->postJson('/api/v1/parties', [
            'kind' => 'ORGANIZATION', 'legal_name' => 'Cliente Único SAC', 'tax_identifier' => '20999000333', 'roles' => ['CUSTOMER'],
        ])->assertCreated()->json('customer_id');

        $quotationId = (string) Str::uuid();
        DB::table('quotations')->insert([
            'id' => $quotationId, 'tenant_id' => $tenantId, 'customer_id' => $customerId, 'quotation_number' => 'COT-TEST-0001',
            'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contract = fn (string $number) => [
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'customer_id' => $customerId, 'quotation_id' => $quotationId,
            'contract_number' => $number, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
        ];
        $order = fn (string $number, ?string $contractId = null) => [
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'customer_id' => $customerId, 'quotation_id' => $quotationId,
            'contract_id' => $contractId, 'order_number' => $number, 'status' => 'DRAFT', 'order_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ];

        // Aunque dos peticiones pasaran a la vez la comprobación previa, la base acepta solo una.
        $first = $contract('CTR-TEST-0001');
        DB::table('contracts')->insert($first);
        $this->assertDatabaseRejects(
            fn () => DB::table('contracts')->insert($contract('CTR-TEST-0002')),
            'La cotización quedó con dos contratos.',
        );

        DB::table('orders')->insert($order('ORD-TEST-0001', $first['id']));
        $this->assertDatabaseRejects(
            fn () => DB::table('orders')->insert($order('ORD-TEST-0002')),
            'La cotización quedó con dos órdenes.',
        );
    }

    /** Una petición por prueba: el error de la base deja inservible la transacción que envuelve cada test. */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedIdRoutes')]
    public function test_malformed_ids_are_not_found_instead_of_failing(string $path): void
    {
        [$user] = $this->makeTenantUser(['core.view', 'quotations.view', 'services.view'], ['SERVICIOS', 'COTIZACIONES']);
        Sanctum::actingAs($user);

        $this->getJson($path)->assertStatus(404);
    }

    public static function malformedIdRoutes(): array
    {
        return [
            'cotización' => ['/api/v1/quotations/no-es-un-id'],
            'servicio' => ['/api/v1/services/123'],
            'actor' => ['/api/v1/parties/abc'],
        ];
    }

    public function test_disconnected_modules_can_no_longer_create_identities(): void
    {
        [$user] = $this->makeTenantUser(['core.view', 'core.manage']);
        Sanctum::actingAs($user);

        foreach (['customers', 'suppliers', 'employees'] as $resource) {
            $status = $this->postJson("/api/v1/{$resource}", [])->status();
            $this->assertContains($status, [404, 405], "POST /{$resource} sigue creando registros.");
            $this->assertContains($this->postJson("/api/v1/{$resource}/import", [])->status(), [404, 405]);
        }
        // El único punto de alta sigue en pie.
        $this->postJson('/api/v1/parties', [])->assertStatus(422);
    }

    public function test_two_people_editing_the_same_record_do_not_overwrite_each_other(): void
    {
        [$user] = $this->makeTenantUser(['core.view', 'core.manage']);
        Sanctum::actingAs($user);
        $party = $this->postJson('/api/v1/parties', [
            'kind' => 'ORGANIZATION', 'legal_name' => 'Versionada SAC', 'tax_identifier' => '20999000444', 'roles' => ['CUSTOMER'],
        ])->assertCreated()->json();
        $url = '/api/v1/parties/'.$party['id'];
        $this->assertSame(0, $party['lock_version']);

        // Ana y Luis abren la ficha con la versión 0. Ana guarda primero.
        $this->putJson($url, ['sector' => 'Minería'], ['If-Match' => '0'])
            ->assertOk()->assertJsonPath('sector', 'Minería')->assertJsonPath('lock_version', 1);

        // Luis guarda después, todavía con la versión 0: se rechaza y no pisa el cambio de Ana.
        $this->putJson($url, ['sector' => 'Pesca'], ['If-Match' => '0'])
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
        $this->getJson($url)->assertOk()->assertJsonPath('sector', 'Minería')->assertJsonPath('lock_version', 1);

        // Con la versión vigente sí guarda. Cambiar de estado también cuenta como cambio.
        $this->putJson($url, ['sector' => 'Pesca'], ['If-Match' => '1'])->assertOk()->assertJsonPath('lock_version', 2);
        $this->postJson($url.'/deactivate', [], ['If-Match' => '1'])->assertStatus(409);
        $this->postJson($url.'/deactivate', [], ['If-Match' => '2'])->assertOk()->assertJsonPath('lock_version', 3);

        // Un guardado que falla no gasta la versión: se puede corregir y reintentar con la misma.
        $this->putJson($url, ['tax_identifier' => 'no-es-ruc'], ['If-Match' => '3'])->assertStatus(422);
        $this->putJson($url, ['sector' => 'Agro'], ['If-Match' => '3'])->assertOk()->assertJsonPath('lock_version', 4);
    }

    public function test_a_creation_sent_twice_is_registered_once(): void
    {
        [$user] = $this->makeTenantUser(['core.view', 'core.manage']);
        Sanctum::actingAs($user);
        $body = ['kind' => 'ORGANIZATION', 'legal_name' => 'Doble Clic SAC', 'tax_identifier' => '20999000555', 'roles' => ['CUSTOMER']];
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $first = $this->postJson('/api/v1/parties', $body, $key)->assertCreated();
        // El mismo envío otra vez: devuelve la misma respuesta, sin crear nada ni avisar de duplicado.
        $second = $this->postJson('/api/v1/parties', $body, $key)->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, DB::table('organizations')->where('tax_identifier', '20999000555')->count());

        // La misma clave con otros datos no se acepta.
        $this->postJson('/api/v1/parties', ['legal_name' => 'Otra SAC'] + $body, $key)
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        // Sin clave, repetir el alta sigue cayendo en el aviso de duplicado de siempre.
        $this->postJson('/api/v1/parties', $body)->assertStatus(409)->assertJsonStructure(['duplicates']);

        // Un envío que falló no queda recordado: se corrige y se reintenta con la misma clave.
        $retry = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson('/api/v1/parties', ['kind' => 'ORGANIZATION', 'roles' => ['CUSTOMER']], $retry)->assertStatus(422);
        $this->postJson('/api/v1/parties', ['kind' => 'ORGANIZATION', 'legal_name' => 'Corregida SAC', 'tax_identifier' => '20999000666', 'roles' => ['CUSTOMER']], $retry)
            ->assertCreated();
    }

    public function test_login_and_public_registration_are_rate_limited_by_address(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/login', ['email' => "nadie{$i}@erp-saas.test", 'password' => 'x'])->assertStatus(422);
        }
        $this->postJson('/api/v1/login', ['email' => 'otro@erp-saas.test', 'password' => 'x'])->assertStatus(429);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/tenants/register', [])->assertStatus(422);
        }
        $this->postJson('/api/v1/tenants/register', [])->assertStatus(429);
    }
}
