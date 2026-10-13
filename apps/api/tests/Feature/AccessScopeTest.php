<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alcance en los permisos: además de qué puede hacer un rol, qué registros alcanza
 * (lo propio, su equipo, su sede o toda la empresa). Un registro fuera de alcance
 * "no existe": no sale en listados ni conteos y su ficha y sus acciones responden 404.
 */
class AccessScopeTest extends TestCase
{
    use DatabaseTransactions;

    private const SALES = ['core.view', 'services.view', 'quotations.view', 'quotations.manage', 'contracts.view', 'orders.view', 'portal.view', 'portal.manage'];

    private const MANAGEMENT = [
        'core.view', 'quotations.view', 'quotations.manage', 'quotations.approve',
        'contracts.view', 'contracts.create', 'contracts.manage', 'contracts.approve', 'orders.view', 'orders.create', 'orders.manage',
    ];

    private string $tenantId;

    /** Empresa nueva con sus módulos y su razón social. */
    private function makeTenant(): string
    {
        $this->tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $this->tenantId, 'code' => 'test-'.Str::random(8), 'name' => 'Empresa de Prueba SAC', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SET app.tenant_id = '{$this->tenantId}'");

        foreach (['SERVICIOS', 'COTIZACIONES', 'CONTRATOS'] as $module) {
            DB::table('tenant_modules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'module_code' => $module, 'enabled' => true,
            ]);
        }
        DB::table('companies')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'code' => 'PRINCIPAL', 'legal_name' => 'Empresa de Prueba SAC',
        ]);

        return $this->tenantId;
    }

    /** Rol con esos permisos y ese alcance en todos los recursos que lo llevan (null = sin definir). */
    private function makeRole(string $code, array $permissions, ?string $scope): string
    {
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId, 'tenant_id' => $this->tenantId, 'code' => $code, 'name' => ucfirst(strtolower($code)),
            'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        if ($scope) {
            $this->setScope($roleId, $scope);
        }

        return $roleId;
    }

    private function setScope(string $roleId, string $scope, array $resources = ['quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities']): void
    {
        foreach ($resources as $resource) {
            DB::table('role_scopes')->updateOrInsert(
                ['role_id' => $roleId, 'resource' => $resource],
                ['tenant_id' => $this->tenantId, 'scope' => $scope],
            );
        }
    }

    private function makeUser(string $name, string $roleId, ?string $branchId = null, ?string $teamId = null): User
    {
        $user = User::create([
            'email' => 'scope-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('Actual123'),
            'name' => $name,
            'status' => 'ACTIVE',
        ]);
        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $user->id,
            'default_branch_id' => $branchId, 'team_id' => $teamId,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'role_id' => $roleId, 'scope' => 'TENANT', 'created_at' => now(),
        ]);

        return $user;
    }

    private function makeBranch(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('branches')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'name' => $name, 'code' => Str::upper($name),
            'company_id' => DB::table('companies')->where('tenant_id', $this->tenantId)->value('id'),
        ]);

        return $id;
    }

    private function makeTeam(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('teams')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'name' => $name]);

        return $id;
    }

    /** @return array{0: string, 1: string} id del cliente y de su identidad */
    private function makeCustomer(string $legalName = 'Minera Los Andes SAC', string $ruc = '20123456789'): array
    {
        $partyId = (string) Str::uuid();
        DB::table('parties')->insert([
            'id' => $partyId, 'tenant_id' => $this->tenantId, 'party_type' => 'ORGANIZATION',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('organizations')->insert(['party_id' => $partyId, 'legal_name' => $legalName, 'tax_identifier' => $ruc]);

        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId, 'tenant_id' => $this->tenantId, 'party_id' => $partyId,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$customerId, $partyId];
    }

    /** La persona crea una cotización y devuelve su id. */
    private function quote(User $as, string $customerId, array $line = ['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 100]): string
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/v1/quotations', ['customer_id' => $customerId, 'items' => [$line]])->assertCreated()->json('id');
    }

    /** Ids de las cotizaciones que la persona ve en su listado. */
    private function visibleQuotations(User $as): array
    {
        Sanctum::actingAs($as);

        return collect($this->getJson('/api/v1/quotations?per_page=100')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function sorted(string ...$ids): array
    {
        return collect($ids)->sort()->values()->all();
    }

    public function test_own_scope_only_reaches_what_the_person_created(): void
    {
        $this->makeTenant();
        $sales = $this->makeRole('VENTAS', self::SALES, 'OWN');
        $ana = $this->makeUser('Ana', $sales);
        $beto = $this->makeUser('Beto', $sales);
        $manager = $this->makeUser('Gerente', $this->makeRole('GERENCIA', self::MANAGEMENT, 'TENANT'));
        $admin = $this->makeUser('Admin', $this->makeRole('ADMIN', self::MANAGEMENT, null));
        [$customerId] = $this->makeCustomer();

        $ofAna = $this->quote($ana, $customerId);
        $ofBeto = $this->quote($beto, $customerId);

        // Cada quien ve lo suyo; Gerencia y el Administrador, todo.
        $this->assertSame([$ofAna], $this->visibleQuotations($ana));
        $this->assertSame([$ofBeto], $this->visibleQuotations($beto));
        $this->assertSame($this->sorted($ofAna, $ofBeto), $this->visibleQuotations($manager));
        $this->assertSame($this->sorted($ofAna, $ofBeto), $this->visibleQuotations($admin));

        // Los indicadores cuentan solo lo que se alcanza.
        Sanctum::actingAs($ana);
        $this->getJson('/api/v1/quotations/summary')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/me/access')->assertOk()->assertJsonPath('scopes.quotations', 'OWN');

        // Lo ajeno "no existe": ni la ficha ni ninguna acción, y no cambia nada.
        $this->getJson("/api/v1/quotations/{$ofAna}")->assertOk();
        $this->getJson("/api/v1/quotations/{$ofBeto}")->assertStatus(404);
        $this->postJson("/api/v1/quotations/{$ofBeto}/submit")->assertStatus(404);
        $this->postJson("/api/v1/quotations/{$ofBeto}/cancel")->assertStatus(404);
        $this->postJson("/api/v1/quotations/{$ofBeto}/versions")->assertStatus(404);
        $this->putJson("/api/v1/quotations/{$ofBeto}", ['customer_id' => $customerId, 'items' => [['description' => 'Pisada', 'quantity' => 1, 'unit_price' => 1]]])
            ->assertStatus(404);
        // Tampoco se delata con el aviso de "otra persona lo modificó".
        $this->withHeaders(['If-Match' => '99'])->postJson("/api/v1/quotations/{$ofBeto}/submit")->assertStatus(404);
        $this->flushHeaders();

        $untouched = DB::table('quotations')->where('id', $ofBeto)->first();
        $this->assertSame('DRAFT', $untouched->status);
        $this->assertSame(0, (int) $untouched->lock_version);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/me/access')->assertOk()->assertJsonPath('scopes.quotations', 'TENANT');
    }

    public function test_a_role_without_a_defined_scope_reaches_only_its_own_records(): void
    {
        $this->makeTenant();
        $role = $this->makeRole('NUEVO', self::SALES, null);
        $ana = $this->makeUser('Ana', $role);
        $beto = $this->makeUser('Beto', $role);
        [$customerId] = $this->makeCustomer();

        $ofAna = $this->quote($ana, $customerId);
        $this->quote($beto, $customerId);

        $this->assertSame([$ofAna], $this->visibleQuotations($ana));
    }

    public function test_team_scope_reaches_the_records_of_the_team(): void
    {
        $this->makeTenant();
        $sales = $this->makeRole('VENTAS', self::SALES, 'TEAM');
        $north = $this->makeTeam('Norte');
        $south = $this->makeTeam('Sur');
        $ana = $this->makeUser('Ana', $sales, teamId: $north);
        $beto = $this->makeUser('Beto', $sales, teamId: $north);
        $carla = $this->makeUser('Carla', $sales, teamId: $south);
        $dario = $this->makeUser('Darío', $sales);
        [$customerId] = $this->makeCustomer();

        $ofAna = $this->quote($ana, $customerId);
        $ofBeto = $this->quote($beto, $customerId);
        $ofCarla = $this->quote($carla, $customerId);
        $ofDario = $this->quote($dario, $customerId);

        $this->assertSame($this->sorted($ofAna, $ofBeto), $this->visibleQuotations($ana));
        $this->assertSame([$ofCarla], $this->visibleQuotations($carla));
        // Sin equipo, el alcance "Equipo" se queda en lo propio.
        $this->assertSame([$ofDario], $this->visibleQuotations($dario));

        Sanctum::actingAs($ana);
        $this->getJson("/api/v1/quotations/{$ofBeto}")->assertOk();
        $this->postJson("/api/v1/quotations/{$ofBeto}/submit")->assertOk();
        $this->getJson("/api/v1/quotations/{$ofCarla}")->assertStatus(404);
        $this->postJson("/api/v1/quotations/{$ofCarla}/submit")->assertStatus(404);
    }

    public function test_branch_scope_reaches_the_records_of_the_branch(): void
    {
        $this->makeTenant();
        $sales = $this->makeRole('VENTAS', self::SALES, 'BRANCH');
        $lima = $this->makeBranch('Lima');
        $arequipa = $this->makeBranch('Arequipa');
        $ana = $this->makeUser('Ana', $sales, branchId: $lima);
        $beto = $this->makeUser('Beto', $sales, branchId: $lima);
        $carla = $this->makeUser('Carla', $sales, branchId: $arequipa);
        $dario = $this->makeUser('Darío', $sales);
        [$customerId] = $this->makeCustomer();

        $ofAna = $this->quote($ana, $customerId);
        $ofBeto = $this->quote($beto, $customerId);
        $ofCarla = $this->quote($carla, $customerId);
        $ofDario = $this->quote($dario, $customerId);

        // Cada documento nace en la sede de quien lo crea.
        $this->assertSame($lima, DB::table('quotations')->where('id', $ofBeto)->value('branch_id'));
        $this->assertSame($arequipa, DB::table('quotations')->where('id', $ofCarla)->value('branch_id'));
        $this->assertNull(DB::table('quotations')->where('id', $ofDario)->value('branch_id'));

        $this->assertSame($this->sorted($ofAna, $ofBeto), $this->visibleQuotations($ana));
        $this->assertSame([$ofCarla], $this->visibleQuotations($carla));
        // Un documento sin sede no se abre a todas las sedes: lo ve quien lo creó.
        $this->assertSame([$ofDario], $this->visibleQuotations($dario));

        Sanctum::actingAs($ana);
        $this->getJson("/api/v1/quotations/{$ofCarla}")->assertStatus(404);

        // Si Ana pasa a Arequipa, alcanza lo de Arequipa y conserva lo que ella creó; lo de Beto ya no.
        DB::table('tenant_users')->where('user_id', $ana->id)->update(['default_branch_id' => $arequipa]);
        $this->assertSame($this->sorted($ofAna, $ofCarla), $this->visibleQuotations($ana));
    }

    public function test_additional_branches_widen_what_is_seen_but_not_where_things_are_born(): void
    {
        $this->makeTenant();
        $sales = $this->makeRole('VENTAS', self::SALES, 'BRANCH');
        $admin = $this->makeUser('Admin', $this->makeRole('ADMIN', ['users.view', 'users.manage'], null));
        $lima = $this->makeBranch('Lima');
        $arequipa = $this->makeBranch('Arequipa');
        $cusco = $this->makeBranch('Cusco');
        $zonal = $this->makeUser('Zonal', $sales, branchId: $lima);
        $inArequipa = $this->makeUser('Carla', $sales, branchId: $arequipa);
        $inCusco = $this->makeUser('Dante', $sales, branchId: $cusco);
        [$customerId] = $this->makeCustomer();
        $ofArequipa = $this->quote($inArequipa, $customerId);
        $ofCusco = $this->quote($inCusco, $customerId);
        $form = fn (array $extra) => [
            'name' => 'Zonal', 'email' => $zonal->email, 'role_id' => $sales, 'branch_id' => $lima, ...$extra,
        ];

        // Con solo su sede no alcanza las otras.
        $this->assertSame([], $this->visibleQuotations($zonal));

        // Se le asigna Arequipa como sede adicional (la principal repetida se ignora).
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/users/{$zonal->id}", $form(['extra_branch_ids' => [$arequipa, $lima]]))->assertOk();
        $this->assertSame([$arequipa], DB::table('tenant_user_branches')->where('user_id', $zonal->id)->pluck('branch_id')->all());
        $listed = collect($this->getJson('/api/v1/users')->assertOk()->json('users'))->firstWhere('id', $zonal->id);
        $this->assertSame(['Arequipa'], array_column($listed['extra_branches'], 'name'));
        $log = DB::table('audit_logs')->where('action', 'user.update')->where('entity_id', $zonal->id)->first();
        $this->assertSame([[], [$arequipa]], [json_decode($log->before_data, true)['extra_branch_ids'], json_decode($log->after_data, true)['extra_branch_ids']]);
        // En el conteo de la sede figura también quien la tiene como adicional.
        $branches = collect($this->getJson('/api/v1/org-units')->json('branches'))->pluck('users', 'name');
        $this->assertSame([2, 1, 1], [$branches['Arequipa'], $branches['Cusco'], $branches['Lima']]);

        // Ve lo de Arequipa (y actúa sobre ello), no lo de Cusco; lo que crea nace en su sede principal.
        AccessScope::forget();
        $this->assertSame([$ofArequipa], $this->visibleQuotations($zonal));
        $this->getJson("/api/v1/quotations/{$ofArequipa}")->assertOk();
        $this->getJson("/api/v1/quotations/{$ofCusco}")->assertStatus(404);
        $own = $this->quote($zonal, $customerId);
        $this->assertSame($lima, DB::table('quotations')->where('id', $own)->value('branch_id'));
        $this->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('branch', 'Lima')->assertJsonPath('extra_branches', ['Arequipa']);

        // Reglas: hace falta sede principal, y las sedes tienen que ser de la empresa.
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/users/{$zonal->id}", [...$form(['extra_branch_ids' => [$cusco]]), 'branch_id' => null])
            ->assertStatus(422)->assertJsonValidationErrors('extra_branch_ids');
        $this->putJson("/api/v1/users/{$zonal->id}", $form(['extra_branch_ids' => [(string) Str::uuid()]]))
            ->assertStatus(422)->assertJsonValidationErrors('extra_branch_ids');
        $this->assertSame([$arequipa], DB::table('tenant_user_branches')->where('user_id', $zonal->id)->pluck('branch_id')->all());

        // Cambiar la lista la reemplaza; quitarla devuelve a la persona a su sola sede.
        $this->putJson("/api/v1/users/{$zonal->id}", $form(['extra_branch_ids' => [$cusco]]))->assertOk();
        AccessScope::forget();
        $this->assertSame($this->sorted($ofCusco, $own), $this->visibleQuotations($zonal));
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/users/{$zonal->id}", $form(['extra_branch_ids' => []]))->assertOk();
        AccessScope::forget();
        $this->assertSame([$own], $this->visibleQuotations($zonal));

        // Con alcance "Propios" las sedes adicionales no amplían nada.
        DB::table('tenant_user_branches')->insert(['tenant_id' => $this->tenantId, 'user_id' => $zonal->id, 'branch_id' => $arequipa]);
        $this->setScope($sales, 'OWN');
        AccessScope::forget();
        $this->assertSame([$own], $this->visibleQuotations($zonal));
    }

    public function test_contracts_and_orders_follow_who_made_the_sale(): void
    {
        $this->makeTenant();
        $sales = $this->makeRole('VENTAS', self::SALES, 'OWN');
        $lima = $this->makeBranch('Lima');
        $ana = $this->makeUser('Ana', $sales, branchId: $lima);
        $beto = $this->makeUser('Beto', $sales);
        $manager = $this->makeUser('Gerente', $this->makeRole('GERENCIA', self::MANAGEMENT, 'TENANT'));
        [$customerId] = $this->makeCustomer();

        $quotation = $this->quote($ana, $customerId);
        $this->postJson("/api/v1/quotations/{$quotation}/submit")->assertOk();

        // Gerencia aprueba y genera el contrato y la orden: los crea otra persona.
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/quotations/{$quotation}/approve")->assertOk();
        $contract = $this->postJson("/api/v1/quotations/{$quotation}/contract")->assertCreated()->json('id');
        $order = $this->postJson("/api/v1/quotations/{$quotation}/order")->assertCreated()->json('id');
        $this->assertSame($manager->id, DB::table('contracts')->where('id', $contract)->value('created_by'));
        // Siguen en la sede de la venta.
        $this->assertSame($lima, DB::table('contracts')->where('id', $contract)->value('branch_id'));
        $this->assertSame($lima, DB::table('orders')->where('id', $order)->value('branch_id'));

        // Quien vendió los sigue viendo.
        Sanctum::actingAs($ana);
        $this->getJson('/api/v1/contracts')->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/v1/contracts/{$contract}")->assertOk();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/v1/orders/{$order}")->assertOk();

        // Su compañero, no.
        Sanctum::actingAs($beto);
        $this->getJson('/api/v1/contracts')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/contracts/{$contract}")->assertStatus(404);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/orders/{$order}")->assertStatus(404);

        // Con permiso de gestionar pero sin alcance, tampoco actúa sobre ellos.
        $outsider = $this->makeUser('Operador', $this->makeRole('OPERACIONES', self::MANAGEMENT, 'OWN'));
        Sanctum::actingAs($outsider);
        $this->postJson("/api/v1/orders/{$order}/confirm")->assertStatus(404);
        $this->postJson("/api/v1/contracts/{$contract}/terminate", ['reason' => 'Intento'])->assertStatus(404);
        $this->postJson("/api/v1/quotations/{$quotation}/contract")->assertStatus(404);
        $this->assertSame('DRAFT', DB::table('orders')->where('id', $order)->value('status'));
        $this->assertSame('DRAFT', DB::table('contracts')->where('id', $contract)->value('status'));
    }

    public function test_portal_inbox_shows_untaken_requests_and_those_the_person_took(): void
    {
        $this->makeTenant();
        $sales = $this->makeRole('VENTAS', self::SALES, 'OWN');
        $lima = $this->makeBranch('Lima');
        $ana = $this->makeUser('Ana', $sales, branchId: $lima);
        $beto = $this->makeUser('Beto', $sales);
        $manager = $this->makeUser('Gerente', $this->makeRole('GERENCIA', [...self::MANAGEMENT, 'portal.view', 'portal.manage'], 'TENANT'));
        [$customerId] = $this->makeCustomer();

        $newRequest = function (string $number) use ($customerId) {
            $id = (string) Str::uuid();
            DB::table('portal_requests')->insert([
                'id' => $id, 'tenant_id' => $this->tenantId, 'customer_id' => $customerId,
                'request_number' => $number, 'request_type' => 'NEW_SERVICE', 'subject' => 'Mantenimiento',
                'description' => 'Necesitamos mantenimiento.', 'status' => 'SUBMITTED', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        };
        $first = $newRequest('SOL-TEST-0001');
        $second = $newRequest('SOL-TEST-0002');

        // Mientras nadie las toma, las ve quien pueda atenderlas.
        Sanctum::actingAs($beto);
        $this->getJson('/api/v1/portal-requests')->assertOk()->assertJsonPath('total', 2);

        // Ana toma una: queda como responsable y la solicitud pasa a su sede.
        Sanctum::actingAs($ana);
        $this->postJson("/api/v1/portal-requests/{$first}/take")->assertOk()->assertJsonPath('status', 'IN_REVIEW');
        $taken = DB::table('portal_requests')->where('id', $first)->first();
        $this->assertSame($ana->id, $taken->taken_by);
        $this->assertSame($lima, $taken->branch_id);
        $this->getJson('/api/v1/portal-requests')->assertOk()->assertJsonPath('total', 2);

        // Beto ya solo ve la que sigue libre, y no puede rechazar ni cotizar la de Ana.
        Sanctum::actingAs($beto);
        $this->getJson('/api/v1/portal-requests')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $second);
        $this->postJson("/api/v1/portal-requests/{$first}/reject", ['reason' => 'No es mía'])->assertStatus(404);
        $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId, 'portal_request_id' => $first,
            'items' => [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors('portal_request_id');
        $this->assertSame('IN_REVIEW', DB::table('portal_requests')->where('id', $first)->value('status'));

        // Rechazar una libre también la deja a nombre de quien la atendió.
        $this->postJson("/api/v1/portal-requests/{$second}/reject", ['reason' => 'Fuera de cobertura'])->assertOk();
        $this->assertSame($beto->id, DB::table('portal_requests')->where('id', $second)->value('taken_by'));

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/portal-requests')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_customer_view_and_service_usage_respect_permission_and_scope(): void
    {
        $this->makeTenant();
        $sales = $this->makeRole('VENTAS', self::SALES, 'OWN');
        $ana = $this->makeUser('Ana', $sales);
        $beto = $this->makeUser('Beto', $sales);
        $admin = $this->makeUser('Admin', $this->makeRole('ADMIN', [...self::MANAGEMENT, 'services.view', 'services.manage'], null));
        $reader = $this->makeUser('Lector', $this->makeRole('LECTOR', ['core.view', 'services.view'], 'TENANT'));
        [$customerId, $partyId] = $this->makeCustomer();

        Sanctum::actingAs($admin);
        $categoryId = $this->postJson('/api/v1/service-categories', ['name' => 'General'])->assertCreated()->json('id');
        $serviceId = $this->postJson('/api/v1/services', ['code' => 'SRV-100', 'name' => 'Servicio SRV-100', 'category_id' => $categoryId, 'price' => 100])
            ->assertCreated()->json('id');
        $this->postJson("/api/v1/services/{$serviceId}/activate")->assertOk();

        $line = ['service_id' => $serviceId, 'description' => 'Servicio SRV-100', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 18];
        $ofAna = $this->quote($ana, $customerId, $line);
        $this->quote($beto, $customerId, $line);

        // La vista del cliente y la ficha del servicio listan solo las cotizaciones que Ana alcanza.
        Sanctum::actingAs($ana);
        $this->assertSame([$ofAna], collect($this->getJson("/api/v1/parties/{$partyId}")->assertOk()->json('quotations'))->pluck('id')->all());
        $this->assertSame([$ofAna], collect($this->getJson("/api/v1/services/{$serviceId}")->assertOk()->json('quotations'))->pluck('quotation_id')->all());

        Sanctum::actingAs($admin);
        $this->assertCount(2, $this->getJson("/api/v1/parties/{$partyId}")->assertOk()->json('quotations'));
        $this->assertCount(2, $this->getJson("/api/v1/services/{$serviceId}")->assertOk()->json('quotations'));

        // Sin permiso de ver cotizaciones no se cuelan por estas pantallas, aunque el rol alcance toda la empresa.
        Sanctum::actingAs($reader);
        $this->assertSame([], $this->getJson("/api/v1/parties/{$partyId}")->assertOk()->json('quotations'));
        $this->assertSame([], $this->getJson("/api/v1/services/{$serviceId}")->assertOk()->json('quotations'));
    }

    public function test_scope_is_set_per_role_and_the_change_is_audited(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', $this->makeRole('ADMIN', ['users.view', 'users.manage', ...self::MANAGEMENT], null));
        $salesId = $this->makeRole('VENTAS', self::SALES, 'TENANT');
        $ana = $this->makeUser('Ana', $salesId);
        $beto = $this->makeUser('Beto', $salesId);
        [$customerId] = $this->makeCustomer();
        $ofAna = $this->quote($ana, $customerId);
        $ofBeto = $this->quote($beto, $customerId);

        Sanctum::actingAs($admin);
        $list = $this->getJson('/api/v1/roles')->assertOk()->json();
        $this->assertSame(['quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities', 'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries'], collect($list['scope_resources'])->pluck('code')->all());
        $this->assertSame('TENANT', collect($list['roles'])->firstWhere('id', $salesId)['scopes']['quotations']);

        // Un rol nuevo nace alcanzando solo lo propio.
        $newRole = $this->postJson('/api/v1/roles', ['name' => 'Preventa'])->assertCreated()->json('id');
        $this->assertSame(
            ['activities' => 'OWN', 'contracted_services' => 'OWN', 'contracts' => 'OWN', 'cost_entries' => 'OWN', 'leads' => 'OWN', 'opportunities' => 'OWN', 'orders' => 'OWN', 'portal' => 'OWN', 'projects' => 'OWN', 'quotations' => 'OWN', 'time_entries' => 'OWN', 'work_orders' => 'OWN'],
            DB::table('role_scopes')->where('role_id', $newRole)->orderBy('resource')->pluck('scope', 'resource')->all(),
        );

        // Alcance inválido o de un recurso que no lo lleva: se rechaza.
        $permissions = self::SALES;
        $this->putJson("/api/v1/roles/{$salesId}/permissions", ['permissions' => $permissions, 'scopes' => ['quotations' => 'TODO']])->assertStatus(422);
        $this->putJson("/api/v1/roles/{$salesId}/permissions", ['permissions' => $permissions, 'scopes' => ['services' => 'OWN']])->assertStatus(422);

        // Recortar Ventas a lo propio surte efecto de inmediato y queda en la auditoría.
        $this->assertSame($this->sorted($ofAna, $ofBeto), $this->visibleQuotations($ana));
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/roles/{$salesId}/permissions", ['permissions' => $permissions, 'scopes' => ['quotations' => 'OWN', 'contracts' => 'TEAM']])
            ->assertOk()->assertJsonPath('scopes.quotations', 'OWN')->assertJsonPath('scopes.contracts', 'TEAM')->assertJsonPath('scopes.orders', 'TENANT');
        $this->assertSame([$ofAna], $this->visibleQuotations($ana));

        $log = DB::table('audit_logs')->where('action', 'role.scopes.update')->where('entity_id', $salesId)->first();
        $this->assertNotNull($log, 'El cambio de alcance no quedó en la auditoría.');
        $this->assertEquals(['quotations' => 'TENANT', 'contracts' => 'TENANT'], json_decode($log->before_data, true));
        $this->assertEquals(['quotations' => 'OWN', 'contracts' => 'TEAM'], json_decode($log->after_data, true));

        // Guardar sin enviar alcance no lo toca.
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/roles/{$salesId}/permissions", ['permissions' => $permissions])->assertOk()->assertJsonPath('scopes.quotations', 'OWN');

        // El Administrador no se recorta, ni por la pantalla ni escribiendo en la tabla.
        $adminRole = collect($this->getJson('/api/v1/roles')->json('roles'))->firstWhere('is_admin', true);
        $this->assertSame('TENANT', $adminRole['scopes']['quotations']);
        $this->putJson("/api/v1/roles/{$adminRole['id']}/permissions", ['permissions' => [], 'scopes' => ['quotations' => 'OWN']])->assertStatus(422);
        $this->setScope($adminRole['id'], 'OWN');
        $this->assertSame($this->sorted($ofAna, $ofBeto), $this->visibleQuotations($admin));

        // Quien no gestiona usuarios no cambia alcances; un rol de otra empresa no existe.
        Sanctum::actingAs($ana);
        $this->putJson("/api/v1/roles/{$salesId}/permissions", ['permissions' => $permissions, 'scopes' => ['quotations' => 'TENANT']])->assertStatus(403);
        $otherTenant = $this->tenantId;
        $this->makeTenant();
        $outsider = $this->makeUser('Otro', $this->makeRole('ADMIN', ['users.view', 'users.manage'], null));
        Sanctum::actingAs($outsider);
        $this->putJson("/api/v1/roles/{$salesId}/permissions", ['permissions' => [], 'scopes' => ['quotations' => 'TENANT']])->assertStatus(404);
        $this->assertNotSame($otherTenant, $this->tenantId);
    }

    public function test_branches_and_teams_are_managed_and_assigned_to_users(): void
    {
        $this->makeTenant();
        $adminRole = $this->makeRole('ADMIN', ['users.view', 'users.manage'], null);
        $admin = $this->makeUser('Admin', $adminRole);
        $viewer = $this->makeUser('Lectora', $this->makeRole('LECTOR', ['users.view'], 'OWN'));
        Sanctum::actingAs($admin);

        $lima = $this->postJson('/api/v1/org-units/branches', ['name' => 'Lima'])->assertCreated()->assertJsonPath('status', 'ACTIVE')->json('id');
        $this->postJson('/api/v1/org-units/branches', ['name' => ' lima '])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/org-units/branches', ['name' => ''])->assertStatus(422);
        $north = $this->postJson('/api/v1/org-units/teams', ['name' => 'Ventas Norte'])->assertCreated()->json('id');
        $this->postJson('/api/v1/org-units/teams', ['name' => 'VENTAS NORTE'])->assertStatus(422);
        $this->postJson('/api/v1/org-units/otros', ['name' => 'X'])->assertStatus(404);

        // Se asignan al usuario desde Usuarios, y el cambio queda en la auditoría.
        $this->putJson("/api/v1/users/{$viewer->id}", [
            'name' => 'Lectora', 'email' => $viewer->email, 'role_id' => DB::table('user_roles')->where('user_id', $viewer->id)->value('role_id'),
            'branch_id' => $lima, 'team_id' => $north,
        ])->assertOk();
        $member = DB::table('tenant_users')->where('user_id', $viewer->id)->first();
        $this->assertSame($lima, $member->default_branch_id);
        $this->assertSame($north, $member->team_id);
        $log = DB::table('audit_logs')->where('action', 'user.update')->where('entity_id', $viewer->id)->first();
        $this->assertSame($north, json_decode($log->after_data, true)['team_id']);
        $this->putJson("/api/v1/users/{$viewer->id}", [
            'name' => 'Lectora', 'email' => $viewer->email, 'role_id' => DB::table('user_roles')->where('user_id', $viewer->id)->value('role_id'),
            'team_id' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonValidationErrors('team_id');

        $units = $this->getJson('/api/v1/org-units')->assertOk()->json();
        $this->assertSame(1, $units['branches'][0]['users']);
        $this->assertSame('Ventas Norte', $units['teams'][0]['name']);
        $listed = collect($this->getJson('/api/v1/users')->assertOk()->json('users'))->firstWhere('id', $viewer->id);
        $this->assertSame('Lima', $listed['branch']['name']);
        $this->assertSame('Ventas Norte', $listed['team']['name']);

        // Se renombran y se desactivan, no se borran; una desactivada deja de ofrecerse.
        $this->putJson("/api/v1/org-units/teams/{$north}", ['name' => 'Ventas Lima'])->assertOk()->assertJsonPath('name', 'Ventas Lima');
        $this->putJson("/api/v1/org-units/teams/{$north}", ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->assertSame([], $this->getJson('/api/v1/users')->json('teams'));
        $this->assertSame($north, DB::table('tenant_users')->where('user_id', $viewer->id)->value('team_id'));
        $this->putJson('/api/v1/org-units/teams/'.Str::uuid(), ['name' => 'X'])->assertStatus(404);

        // Ver sí, gestionar no, sin el permiso.
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/org-units')->assertOk();
        $this->postJson('/api/v1/org-units/teams', ['name' => 'Intruso'])->assertStatus(403);
        $this->putJson("/api/v1/org-units/branches/{$lima}", ['name' => 'Pisada'])->assertStatus(403);

        // Otra empresa no ve ni toca estas sedes y equipos, y no puede asignarlos a su gente.
        $this->makeTenant();
        $outsider = $this->makeUser('Otro', $this->makeRole('ADMIN', ['users.view', 'users.manage'], null));
        Sanctum::actingAs($outsider);
        $this->assertSame(['branches' => [], 'teams' => []], $this->getJson('/api/v1/org-units')->assertOk()->json());
        $this->putJson("/api/v1/org-units/branches/{$lima}", ['name' => 'Ajena'])->assertStatus(404);
        $this->putJson("/api/v1/users/{$outsider->id}", [
            'name' => 'Otro', 'email' => $outsider->email, 'role_id' => DB::table('user_roles')->where('user_id', $outsider->id)->value('role_id'),
            'branch_id' => $lima,
        ])->assertStatus(422)->assertJsonValidationErrors('branch_id');
    }

    public function test_sales_creates_the_contract_and_order_of_its_own_sale_without_managing_them(): void
    {
        $this->makeTenant();
        $sales = $this->makeRole('VENTAS', [...self::SALES, 'contracts.create', 'orders.create'], 'OWN');
        $ana = $this->makeUser('Ana', $sales);
        $beto = $this->makeUser('Beto', $sales);
        $manager = $this->makeUser('Gerente', $this->makeRole('GERENCIA', self::MANAGEMENT, 'TENANT'));
        // Gestiona lo que ya existe, pero no crea: son permisos distintos.
        $keeper = $this->makeUser('Custodia', $this->makeRole('CUSTODIA', ['quotations.view', 'contracts.view', 'contracts.manage', 'orders.view', 'orders.manage'], 'TENANT'));
        [$customerId] = $this->makeCustomer();

        $ofAna = $this->quote($ana, $customerId);
        $this->postJson("/api/v1/quotations/{$ofAna}/submit")->assertOk();
        $ofBeto = $this->quote($beto, $customerId);
        $this->postJson("/api/v1/quotations/{$ofBeto}/submit")->assertOk();
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/quotations/{$ofAna}/approve")->assertOk();
        $this->postJson("/api/v1/quotations/{$ofBeto}/approve")->assertOk();

        Sanctum::actingAs($keeper);
        $this->postJson("/api/v1/quotations/{$ofAna}/contract")->assertStatus(403);
        $this->postJson("/api/v1/quotations/{$ofAna}/order")->assertStatus(403);

        // Ana genera el contrato y la orden de su venta…
        Sanctum::actingAs($ana);
        $contract = $this->postJson("/api/v1/quotations/{$ofAna}/contract")->assertCreated()->json('id');
        $order = $this->postJson("/api/v1/quotations/{$ofAna}/order")->assertCreated()->json('id');
        $this->getJson("/api/v1/contracts/{$contract}")->assertOk();

        // …pero no los edita ni les cambia el estado.
        $this->putJson("/api/v1/contracts/{$contract}", ['terms' => 'Pisado'])->assertStatus(403);
        $this->postJson("/api/v1/contracts/{$contract}/terminate", ['reason' => 'Intento'])->assertStatus(403);
        $this->postJson("/api/v1/contracts/{$contract}/activate")->assertStatus(403);
        $this->postJson("/api/v1/orders/{$order}/confirm")->assertStatus(403);
        $this->assertSame('DRAFT', DB::table('contracts')->where('id', $contract)->value('status'));
        $this->assertSame('DRAFT', DB::table('orders')->where('id', $order)->value('status'));

        // Ni convierte la venta de otra persona: para ella no existe.
        $this->postJson("/api/v1/quotations/{$ofBeto}/contract")->assertStatus(404);
        $this->postJson("/api/v1/quotations/{$ofBeto}/order")->assertStatus(404);
        $this->assertSame(0, DB::table('contracts')->where('quotation_id', $ofBeto)->count());
    }

    public function test_customer_view_asks_for_the_permission_of_each_module(): void
    {
        $this->makeTenant();
        DB::table('tenant_modules')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'module_code' => 'SOPORTE', 'enabled' => true,
        ]);
        [$customerId, $partyId] = $this->makeCustomer();
        DB::table('support_tickets')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'customer_id' => $customerId,
            'ticket_number' => 'TCK-TEST-0001', 'subject' => 'No enciende',
        ]);
        DB::table('crm_leads')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'party_id' => $partyId, 'organization_name' => 'Minera Los Andes SAC',
            'lead_number' => 'LD-TEST-0001',
        ]);

        // Ver clientes no da acceso a sus tickets ni a sus leads.
        Sanctum::actingAs($this->makeUser('Lectora', $this->makeRole('LECTOR', ['core.view'], 'TENANT')));
        $view = $this->getJson("/api/v1/parties/{$partyId}")->assertOk()->json();
        $this->assertSame([], $view['tickets']);
        $this->assertSame([], $view['leads']);
        $this->assertSame([], $view['invoices']);
        $this->assertNull($view['finance']);

        // Con el permiso de Soporte ve los tickets; el de CRM no vale porque la empresa no tiene ese módulo.
        Sanctum::actingAs($this->makeUser('Agente', $this->makeRole('SOPORTE', ['core.view', 'support.view', 'leads.view'], 'TENANT')));
        $view = $this->getJson("/api/v1/parties/{$partyId}")->assertOk()->json();
        $this->assertSame(['TCK-TEST-0001'], collect($view['tickets'])->pluck('ticket_number')->all());
        $this->assertSame([], $view['leads']);

        // Con el módulo activado, el mismo permiso ya muestra los leads.
        DB::table('tenant_modules')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'module_code' => 'CRM', 'enabled' => true,
        ]);
        $this->assertCount(1, $this->getJson("/api/v1/parties/{$partyId}")->assertOk()->json('leads'));
    }
}
