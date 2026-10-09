<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComercialTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = [
        'core.view', 'services.view', 'services.manage', 'quotations.view', 'quotations.manage',
    ];

    /** Módulos del plan de la empresa de prueba: sin ellos la API rechaza sus permisos (MODULE_DISABLED). */
    private const MODULES = ['SERVICIOS', 'COTIZACIONES', 'CONTRATOS', 'FINANZAS'];

    /**
     * Estas pruebas son de los flujos comerciales, no del alcance: sus roles ven toda la
     * empresa. El alcance restringido (lo propio, equipo, sede) se prueba en AccessScopeTest.
     */
    private function grantWholeCompany(string $tenantId, string $roleId): void
    {
        foreach (['quotations', 'contracts', 'orders', 'portal'] as $resource) {
            DB::table('role_scopes')->insert(['role_id' => $roleId, 'tenant_id' => $tenantId, 'resource' => $resource, 'scope' => 'TENANT']);
        }
    }

    /** Otro usuario del mismo tenant, con su propio rol y permisos (p. ej. Gerencia, que aprueba). */
    private function makeColleague(string $tenantId, string $name, array $permissions): User
    {
        $user = User::create([
            'email' => 'col-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('Actual123'),
            'name' => $name,
            'status' => 'ACTIVE',
        ]);
        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId, 'tenant_id' => $tenantId, 'code' => 'ROL-'.Str::random(6), 'name' => $name,
            'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $roleId,
            'scope' => 'TENANT', 'created_at' => now(),
        ]);
        foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        $this->grantWholeCompany($tenantId, $roleId);

        return $user;
    }

    /** Usuario con rol "Ventas" (y esos permisos) en un tenant nuevo. Devuelve [usuario, tenantId]. */
    private function makeTenantUser(array $permissions = self::PERMISSIONS, array $modules = self::MODULES): array
    {
        $user = User::create([
            'email' => 'com-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('Actual123'),
            'name' => 'Vendedora de Prueba',
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
        $this->grantWholeCompany($tenantId, $roleId);
        foreach ($modules as $module) {
            DB::table('tenant_modules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'module_code' => $module, 'enabled' => true,
            ]);
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

    private function makeCategory(string $name = 'General'): string
    {
        return $this->postJson('/api/v1/service-categories', ['name' => $name])->assertCreated()->json('id');
    }

    /** Servicio activo con precio, listo para cotizar. Devuelve su id. */
    private function makeActiveService(string $categoryId, string $code = 'SRV-100', float $price = 100): string
    {
        $id = $this->postJson('/api/v1/services', [
            'code' => $code, 'name' => "Servicio {$code}", 'category_id' => $categoryId, 'price' => $price,
        ])->assertCreated()->json('id');
        $this->postJson("/api/v1/services/{$id}/activate")->assertOk();

        return $id;
    }

    public function test_service_is_created_with_category_and_initial_price(): void
    {
        [$user] = $this->makeTenantUser();
        Sanctum::actingAs($user);

        $categoryId = $this->postJson('/api/v1/service-categories', ['name' => 'Mantenimiento'])
            ->assertCreated()->json('id');

        $this->postJson('/api/v1/service-categories', ['name' => 'mantenimiento'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->getJson('/api/v1/service-categories')->assertOk()
            ->assertJsonFragment(['id' => $categoryId, 'name' => 'Mantenimiento']);

        $this->postJson('/api/v1/services', [
            'code' => 'SRV-001', 'name' => 'Mantenimiento preventivo', 'unit' => 'visita',
            'category_id' => $categoryId, 'price' => 350,
        ])->assertCreated()
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('category.name', 'Mantenimiento')
            ->assertJsonPath('current_price.amount', '350.0000');

        $this->postJson('/api/v1/services', ['code' => 'SRV-001', 'name' => 'Otro', 'category_id' => $categoryId])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/services', ['code' => 'SRV-009', 'name' => 'Otro', 'category_id' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_services_can_be_searched_and_filtered(): void
    {
        [$user] = $this->makeTenantUser();
        Sanctum::actingAs($user);

        $categoryId = $this->postJson('/api/v1/service-categories', ['name' => 'Soporte'])->json('id');
        $this->postJson('/api/v1/services', ['code' => 'SRV-001', 'name' => 'Mesa de ayuda', 'category_id' => $categoryId])->assertCreated();
        $otherCategoryId = $this->makeCategory('Instalaciones');
        $this->postJson('/api/v1/services', ['code' => 'SRV-002', 'name' => 'Instalación de equipos', 'category_id' => $otherCategoryId])->assertCreated();

        $this->getJson('/api/v1/services')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/services?search=ayuda')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.code', 'SRV-001')
            ->assertJsonPath('data.0.current_price', null);
        $this->getJson('/api/v1/services?search=srv-002')->assertOk()->assertJsonPath('data.0.name', 'Instalación de equipos');
        $this->getJson("/api/v1/services?category_id={$categoryId}")->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/services?search=%25')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_new_price_version_closes_the_current_one(): void
    {
        [$user] = $this->makeTenantUser();
        Sanctum::actingAs($user);

        $serviceId = $this->postJson('/api/v1/services', ['code' => 'SRV-001', 'name' => 'Auditoría', 'category_id' => $this->makeCategory(), 'price' => 100])
            ->assertCreated()->json('id');

        $this->postJson("/api/v1/services/{$serviceId}/prices", ['amount' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->postJson("/api/v1/services/{$serviceId}/prices", ['amount' => 120])->assertCreated();

        $service = $this->getJson("/api/v1/services/{$serviceId}")->assertOk()
            ->assertJsonPath('current_price.amount', '120.0000')
            ->assertJsonCount(2, 'prices')
            ->json();

        $closed = collect($service['prices'])->firstWhere('amount', '100.0000');
        $this->assertFalse($closed['active']);
        $this->assertNotNull($closed['valid_to']);
    }

    public function test_services_need_their_permissions(): void
    {
        [$user] = $this->makeTenantUser(['services.view']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/services')->assertOk();
        $this->getJson('/api/v1/service-categories')->assertOk();
        $this->postJson('/api/v1/service-categories', ['name' => 'X'])->assertStatus(403);
        $this->postJson('/api/v1/services', ['code' => 'A', 'name' => 'B'])->assertStatus(403);
        $this->putJson('/api/v1/services/'.Str::uuid(), ['code' => 'A', 'name' => 'B'])->assertStatus(403);
        $this->postJson('/api/v1/services/'.Str::uuid().'/archive')->assertStatus(403);
    }

    public function test_quotation_list_shows_customer_and_summary_counts_by_status(): void
    {
        [$user, $tenantId] = $this->makeTenantUser();
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        $otherCustomerId = $this->makeOrganizationCustomer($tenantId, 'Textiles del Sur SAC', '20999999991');
        Sanctum::actingAs($user);

        $line = ['description' => 'Auditoría', 'quantity' => 2, 'unit_price' => 100, 'discount' => 20, 'tax_rate' => 18];

        $first = $this->postJson('/api/v1/quotations', ['customer_id' => $customerId, 'items' => [$line]])
            ->assertCreated()
            ->assertJsonPath('subtotal', '180.0000')
            ->assertJsonPath('tax_total', '32.4000')
            ->assertJsonPath('total', '212.4000')
            ->json();
        $this->postJson('/api/v1/quotations', ['customer_id' => $otherCustomerId, 'items' => [$line]])->assertCreated();

        $this->postJson("/api/v1/quotations/{$first['id']}/submit")->assertOk();

        $this->getJson('/api/v1/quotations/summary')->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('by_status.SUBMITTED.count', 1)
            ->assertJsonPath('by_status.SUBMITTED.amounts.PEN', 212.4)
            ->assertJsonPath('by_status.DRAFT.count', 1);

        $this->getJson('/api/v1/quotations?search=los andes')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $first['id'])
            ->assertJsonPath('data.0.customer.party.organization.legal_name', 'Minera Los Andes SAC')
            ->assertJsonPath('data.0.creator.name', 'Vendedora de Prueba');

        $this->getJson('/api/v1/quotations?search='.$first['quotation_number'])->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/quotations?status=DRAFT')->assertOk()->assertJsonPath('total', 1);

        // Sin origen registrado cuenta como directa.
        $this->getJson('/api/v1/quotations?source=DIRECT')->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.source_type', null);
        $this->getJson('/api/v1/quotations?source=PORTAL')->assertOk()->assertJsonPath('total', 0);

        // La ficha trae la dirección y el correo del cliente (sus contactos), para el PDF.
        $partyId = DB::table('customers')->where('id', $customerId)->value('party_id');
        foreach ([['ADDRESS', 'Av. Ejército 812, Arequipa'], ['EMAIL', 'contacto@minera.example'], ['PHONE', '999888777']] as [$type, $value]) {
            DB::table('contacts')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'party_id' => $partyId,
                'contact_type' => $type, 'value' => $value, 'is_primary' => true, 'created_at' => now(),
            ]);
        }

        $this->getJson("/api/v1/quotations/{$first['id']}")->assertOk()
            ->assertJsonPath('customer_contact.address', 'Av. Ejército 812, Arequipa')
            ->assertJsonPath('customer_contact.email', 'contacto@minera.example')
            ->assertJsonPath('customer.party.organization.legal_name', 'Minera Los Andes SAC')
            ->assertJsonPath('creator.name', 'Vendedora de Prueba')
            ->assertJsonPath('contract', null)
            ->assertJsonCount(1, 'items');
    }

    public function test_commercial_data_does_not_cross_tenants(): void
    {
        [$other, $otherTenantId] = $this->makeTenantUser();
        $otherCustomerId = $this->makeOrganizationCustomer($otherTenantId, 'Cliente Ajeno SAC', '20999999992');
        Sanctum::actingAs($other);
        $otherCategoryId = $this->makeCategory('Categoría ajena');
        $otherServiceId = $this->postJson('/api/v1/services', ['code' => 'SRV-001', 'name' => 'Servicio ajeno', 'category_id' => $otherCategoryId, 'price' => 10])->assertCreated()->json('id');
        $this->postJson('/api/v1/quotations', [
            'customer_id' => $otherCustomerId,
            'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 10]],
        ])->assertCreated();

        [$user] = $this->makeTenantUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/services')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/service-categories')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/v1/quotations')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/quotations/summary')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/services/{$otherServiceId}")->assertNotFound();
        // Ni la categoría de otra empresa sirve, pero el mismo código sí puede repetirse.
        $this->postJson('/api/v1/services', ['code' => 'SRV-001', 'name' => 'Servicio propio', 'category_id' => $otherCategoryId])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');
        $this->postJson('/api/v1/services', ['code' => 'SRV-001', 'name' => 'Servicio propio', 'category_id' => $this->makeCategory()])->assertCreated();
    }

    public function test_service_keeps_billing_mode_recurrence_and_warranty_and_can_be_edited(): void
    {
        [$user] = $this->makeTenantUser();
        Sanctum::actingAs($user);
        $categoryId = $this->makeCategory();

        $this->postJson('/api/v1/services', ['code' => 'SRV-001', 'name' => 'Sin categoría'])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');
        $this->postJson('/api/v1/services', [
            'code' => 'SRV-001', 'name' => 'Soporte mensual', 'category_id' => $categoryId, 'billing_mode' => 'RECURRING',
        ])->assertStatus(422)->assertJsonValidationErrors('frequency');

        $id = $this->postJson('/api/v1/services', [
            'code' => 'SRV-001', 'name' => 'Soporte mensual', 'category_id' => $categoryId,
            'billing_mode' => 'RECURRING', 'frequency' => 'MONTHLY', 'warranty_months' => 6,
        ])->assertCreated()
            ->assertJsonPath('billing_mode', 'RECURRING')
            ->assertJsonPath('recurrence.frequency', 'MONTHLY')
            ->assertJsonPath('warranty_months', 6)
            ->json('id');

        $this->getJson('/api/v1/services?billing_mode=RECURRING')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/services?billing_mode=PER_EVENT')->assertOk()->assertJsonPath('total', 0);

        $this->putJson("/api/v1/services/{$id}", [
            'code' => 'SRV-001', 'name' => 'Soporte por visita', 'category_id' => $categoryId,
            'billing_mode' => 'PER_EVENT', 'warranty_months' => 0, 'unit' => 'visita',
        ])->assertOk()
            ->assertJsonPath('name', 'Soporte por visita')
            ->assertJsonPath('billing_mode', 'PER_EVENT')
            ->assertJsonPath('recurrence', null)
            ->assertJsonPath('unit', 'visita');

        $this->postJson('/api/v1/services', ['code' => 'SRV-002', 'name' => 'Otro', 'category_id' => $categoryId])->assertCreated();
        $this->putJson("/api/v1/services/{$id}", ['code' => 'SRV-002', 'name' => 'Choca', 'category_id' => $categoryId])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $history = collect($this->getJson("/api/v1/services/{$id}")->assertOk()->json('history'));
        $this->assertSame(['service.update', 'service.create'], $history->pluck('action')->all());
        $this->assertSame('Vendedora de Prueba', $history->first()['user']);
    }

    public function test_service_lifecycle_follows_the_state_machine(): void
    {
        [$user] = $this->makeTenantUser();
        Sanctum::actingAs($user);
        $categoryId = $this->makeCategory();

        $id = $this->postJson('/api/v1/services', ['code' => 'SRV-001', 'name' => 'Sin precio', 'category_id' => $categoryId])
            ->assertCreated()->json('id');

        // Sin precio vigente no se activa.
        $this->postJson("/api/v1/services/{$id}/activate")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/services/{$id}/prices", ['amount' => 50])->assertCreated();

        $this->postJson("/api/v1/services/{$id}/archive")->assertStatus(422);
        $this->postJson("/api/v1/services/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson("/api/v1/services/{$id}/activate")->assertStatus(422);
        $this->postJson("/api/v1/services/{$id}/deactivate")->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->postJson("/api/v1/services/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson("/api/v1/services/{$id}/deactivate")->assertOk();
        $this->postJson("/api/v1/services/{$id}/archive")->assertOk()->assertJsonPath('status', 'ARCHIVED');

        $this->postJson("/api/v1/services/{$id}/activate")->assertStatus(422);
        $this->putJson("/api/v1/services/{$id}", ['code' => 'SRV-001', 'name' => 'X', 'category_id' => $categoryId])->assertStatus(422);
    }

    public function test_draft_quotation_can_be_edited_and_only_sells_active_services(): void
    {
        [$user, $tenantId] = $this->makeTenantUser();
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        Sanctum::actingAs($user);
        $categoryId = $this->makeCategory();
        $serviceId = $this->makeActiveService($categoryId, 'SRV-100', 100);
        $draftServiceId = $this->postJson('/api/v1/services', ['code' => 'SRV-200', 'name' => 'En borrador', 'category_id' => $categoryId, 'price' => 10])->json('id');

        $line = fn (string $service, float $qty) => [
            'service_id' => $service, 'description' => 'Línea', 'quantity' => $qty, 'unit_price' => 100, 'tax_rate' => 18,
        ];

        $this->postJson('/api/v1/quotations', ['customer_id' => $customerId, 'items' => [$line($draftServiceId, 1)]])
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson('/api/v1/quotations', ['customer_id' => (string) Str::uuid(), 'items' => [$line($serviceId, 1)]])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');

        $quotation = $this->postJson('/api/v1/quotations', ['customer_id' => $customerId, 'items' => [$line($serviceId, 1)]])
            ->assertCreated()
            ->assertJsonPath('total', '118.0000')
            ->assertJsonPath('items.0.service_snapshot.billing_mode', 'ONE_TIME')
            ->json();

        $this->putJson("/api/v1/quotations/{$quotation['id']}", [
            'customer_id' => $customerId, 'notes' => 'Pago a 15 días.', 'items' => [$line($serviceId, 3)],
        ])->assertOk()
            ->assertJsonPath('total', '354.0000')
            ->assertJsonPath('notes', 'Pago a 15 días.')
            ->assertJsonPath('quotation_number', $quotation['quotation_number'])
            ->assertJsonCount(1, 'items');

        $this->postJson("/api/v1/quotations/{$quotation['id']}/submit")->assertOk();
        $this->putJson("/api/v1/quotations/{$quotation['id']}", ['customer_id' => $customerId, 'items' => [$line($serviceId, 1)]])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        // Servicio usado: su ficha lista la cotización con el precio que guardó.
        $this->getJson("/api/v1/services/{$serviceId}")->assertOk()
            ->assertJsonPath('quotations.0.quotation_number', $quotation['quotation_number'])
            ->assertJsonPath('quotations.0.unit_price', '100.0000');
    }

    public function test_quotation_decisions_keep_who_decided_and_why(): void
    {
        [$seller, $tenantId] = $this->makeTenantUser();
        $manager = $this->makeColleague($tenantId, 'Gerente de Prueba', ['quotations.view', 'quotations.approve']);
        // Ella misma con el permiso de aprobar: la segregación le impide aprobar lo suyo.
        $user = $this->makeColleague($tenantId, 'Vendedora de Prueba', [...self::PERMISSIONS, 'quotations.approve']);
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        $payload = ['customer_id' => $customerId, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 10]]];

        // Sin el permiso quotations.approve no se aprueba ni se rechaza.
        Sanctum::actingAs($seller);
        $own = $this->postJson('/api/v1/quotations', $payload)->json('id');
        $this->postJson("/api/v1/quotations/{$own}/submit")->assertOk();
        $this->postJson("/api/v1/quotations/{$own}/approve")->assertStatus(403);
        $this->postJson("/api/v1/quotations/{$own}/reject", ['reason' => 'No'])->assertStatus(403);
        $this->postJson("/api/v1/quotations/{$own}/expire")->assertStatus(403);
        // Gerencia aprueba, pero no cotiza.
        Sanctum::actingAs($manager);
        $this->postJson('/api/v1/quotations', $payload)->assertStatus(403);
        $this->getJson("/api/v1/quotations/{$own}")->assertOk()->assertJsonPath('own_approval_blocked', false);
        $this->postJson("/api/v1/quotations/{$own}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');

        Sanctum::actingAs($user);
        $rejected = $this->postJson('/api/v1/quotations', $payload)->json('id');
        $this->postJson("/api/v1/quotations/{$rejected}/submit")->assertOk();
        $this->postJson("/api/v1/quotations/{$rejected}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/quotations/{$rejected}/reject", ['reason' => 'Precio fuera de política'])
            ->assertOk()->assertJsonPath('status', 'REJECTED');

        $detail = $this->getJson("/api/v1/quotations/{$rejected}")->assertOk()
            ->assertJsonPath('approvals.0.decision', 'REJECTED')
            ->assertJsonPath('approvals.0.comments', 'Precio fuera de política')
            ->assertJsonPath('approvals.0.approver.name', 'Vendedora de Prueba')
            ->json();
        // Dentro del test todo ocurre en el mismo instante: solo el alta tiene posición fija (al final).
        $actions = collect($detail['history'])->pluck('action');
        $this->assertEqualsCanonicalizing(['quotation.reject', 'quotation.submit', 'quotation.create'], $actions->all());
        $this->assertSame('quotation.create', $actions->last());

        // Vencida: no se aprueba; se puede marcar vencida.
        $expired = $this->postJson('/api/v1/quotations', [...$payload, 'valid_until' => now()->subDay()->toDateString()])->json('id');
        $this->postJson("/api/v1/quotations/{$expired}/submit")->assertOk();
        $this->postJson("/api/v1/quotations/{$expired}/approve")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/quotations/{$expired}/expire")->assertOk()->assertJsonPath('status', 'EXPIRED');
        $this->postJson("/api/v1/quotations/{$expired}/cancel")->assertStatus(422);

        // Segregación de funciones: quien la creó no la aprueba; otra persona con el permiso, sí.
        $approved = $this->postJson('/api/v1/quotations', [...$payload, 'valid_until' => now()->toDateString()])->json('id');
        $this->postJson("/api/v1/quotations/{$approved}/submit")->assertOk();
        $this->getJson("/api/v1/quotations/{$approved}")->assertOk()->assertJsonPath('own_approval_blocked', true);
        $this->postJson("/api/v1/quotations/{$approved}/approve", ['comment' => 'Conforme'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/quotations/{$approved}/approve", ['comment' => 'Conforme'])
            ->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->getJson("/api/v1/quotations/{$approved}")
            ->assertJsonPath('approvals.0.comments', 'Conforme')
            ->assertJsonPath('approvals.0.approver.name', 'Gerente de Prueba');

        // La empresa puede desactivar la segregación en su configuración.
        Sanctum::actingAs($user);
        $relaxed = $this->postJson('/api/v1/quotations', $payload)->json('id');
        $this->postJson("/api/v1/quotations/{$relaxed}/submit")->assertOk();
        DB::table('tenants')->where('id', $tenantId)->update(['configuration' => json_encode(['segregation' => false])]);
        $this->postJson("/api/v1/quotations/{$relaxed}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');

        $cancelled = $this->postJson('/api/v1/quotations', $payload)->json('id');
        $this->postJson("/api/v1/quotations/{$cancelled}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->postJson("/api/v1/quotations/{$cancelled}/submit")->assertStatus(422);
    }

    public function test_new_version_copies_lines_with_the_current_catalog_price(): void
    {
        [$user, $tenantId] = $this->makeTenantUser();
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        Sanctum::actingAs($user);
        $serviceId = $this->makeActiveService($this->makeCategory(), 'SRV-100', 100);

        $original = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'items' => [['service_id' => $serviceId, 'description' => 'Servicio', 'quantity' => 2, 'unit_price' => 100, 'discount' => 20, 'tax_rate' => 18]],
        ])->assertCreated()->json();

        // En borrador se edita, no se versiona.
        $this->postJson("/api/v1/quotations/{$original['id']}/versions")->assertStatus(422);
        $this->postJson("/api/v1/quotations/{$original['id']}/submit")->assertOk();

        $this->postJson("/api/v1/services/{$serviceId}/prices", ['amount' => 150])->assertCreated();

        $version = $this->postJson("/api/v1/quotations/{$original['id']}/versions")->assertCreated()
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('version', 2)
            ->assertJsonPath('previous_id', $original['id'])
            ->assertJsonPath('items.0.unit_price', '150.0000')
            // 10 % de descuento, igual que la original: 300 − 30 = 270 + IGV.
            ->assertJsonPath('items.0.discount', '30.0000')
            ->assertJsonPath('total', '318.6000')
            ->json();
        $this->assertNotSame($original['quotation_number'], $version['quotation_number']);

        // La original queda cancelada, intacta y enlazada a la que la reemplaza.
        $this->getJson("/api/v1/quotations/{$original['id']}")->assertOk()
            ->assertJsonPath('status', 'CANCELLED')
            ->assertJsonPath('total', '212.4000')
            ->assertJsonPath('superseded_by.id', $version['id']);
        $this->getJson("/api/v1/quotations/{$version['id']}")->assertOk()
            ->assertJsonPath('previous.quotation_number', $original['quotation_number'])
            // La nueva versión se cotizó con la segunda versión del precio.
            ->assertJsonPath('items.0.price_version', 2);

        $this->postJson("/api/v1/quotations/{$original['id']}/versions")->assertStatus(422);
    }

    public function test_quotation_in_dollars_needs_and_keeps_the_exchange_rate(): void
    {
        [$user, $tenantId] = $this->makeTenantUser([...self::PERMISSIONS, 'billing.view', 'billing.manage']);
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        Sanctum::actingAs($user);
        $serviceId = $this->makeActiveService($this->makeCategory(), 'SRV-100', 375);

        $payload = [
            'customer_id' => $customerId,
            'currency' => 'USD',
            'items' => [['service_id' => $serviceId, 'description' => 'Servicio', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'tax_rate' => 18]],
        ];

        // Sin tipo de cambio registrado no se cotiza en dólares; otras monedas no existen.
        $this->getJson('/api/v1/exchange-rates/current')->assertOk()->assertContent('{}');
        $this->postJson('/api/v1/quotations', $payload)->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->postJson('/api/v1/quotations', [...$payload, 'currency' => 'EUR'])->assertStatus(422);

        $this->postJson('/api/v1/exchange-rates', ['date' => now()->addDay()->toDateString(), 'buy' => 3.7, 'sell' => 3.75])->assertStatus(422);
        $this->postJson('/api/v1/exchange-rates', ['date' => now()->subDay()->toDateString(), 'buy' => 3.69, 'sell' => 3.70])->assertCreated();
        $this->postJson('/api/v1/exchange-rates', ['date' => now()->toDateString(), 'buy' => 3.74, 'sell' => 3.80])->assertCreated();
        // Registrar otra vez el mismo día corrige el valor, no lo duplica.
        $this->postJson('/api/v1/exchange-rates', ['date' => now()->toDateString(), 'buy' => 3.74, 'sell' => 3.75])
            ->assertOk()->assertJsonPath('source', 'Manual');

        $this->getJson('/api/v1/exchange-rates')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/exchange-rates/current')->assertOk()->assertJsonPath('sell', '3.7500');

        $quotation = $this->postJson('/api/v1/quotations', $payload)->assertCreated()
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('exchange_rate', '3.7500')
            ->assertJsonPath('total', '118.0000')
            ->json();

        $this->getJson('/api/v1/exchange-rates/summary')->assertOk()
            ->assertJsonPath('current.sell', '3.7500')
            ->assertJsonPath('quotations_usd', 1);

        // Volver a soles quita el tipo de cambio.
        $this->putJson("/api/v1/quotations/{$quotation['id']}", [...$payload, 'currency' => 'PEN'])->assertOk()
            ->assertJsonPath('currency', 'PEN')
            ->assertJsonPath('exchange_rate', null);
        $this->putJson("/api/v1/quotations/{$quotation['id']}", $payload)->assertOk()->assertJsonPath('exchange_rate', '3.7500');

        // La nueva versión toma el precio del catálogo (S/ 375) convertido: 375 / 3.75 = US$ 100.
        $this->postJson("/api/v1/quotations/{$quotation['id']}/submit")->assertOk();
        $this->postJson("/api/v1/services/{$serviceId}/prices", ['amount' => 750])->assertCreated();
        $this->postJson("/api/v1/quotations/{$quotation['id']}/versions")->assertCreated()
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('exchange_rate', '3.7500')
            ->assertJsonPath('items.0.unit_price', '200.0000');
    }

    public function test_registering_exchange_rates_needs_permission(): void
    {
        [$user] = $this->makeTenantUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/exchange-rates/current')->assertOk();
        $this->getJson('/api/v1/exchange-rates')->assertStatus(403);
        $this->postJson('/api/v1/exchange-rates', ['date' => now()->toDateString(), 'buy' => 3.7, 'sell' => 3.75])->assertStatus(403);
    }

    public function test_contract_lifecycle_from_an_approved_quotation(): void
    {
        [$seller, $tenantId] = $this->makeTenantUser([...self::PERMISSIONS, 'contracts.view', 'contracts.create', 'contracts.manage']);
        $manager = $this->makeColleague($tenantId, 'Gerente', [
            'quotations.view', 'quotations.approve', 'contracts.view', 'contracts.create', 'contracts.manage', 'contracts.approve', 'orders.create', 'orders.manage',
        ]);
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        Sanctum::actingAs($seller);
        $serviceId = $this->makeActiveService($this->makeCategory(), 'SRV-100', 100);

        $quotation = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'notes' => 'Pago a 30 días.',
            'items' => [
                ['service_id' => $serviceId, 'description' => 'Servicio SRV-100', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 18],
                ['description' => 'Línea libre', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 18],
            ],
        ])->json('id');
        $this->postJson("/api/v1/quotations/{$quotation}/submit")->assertOk();
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/quotations/{$quotation}/approve")->assertOk();

        // Nace en borrador, vigente desde mañana por un año, con las condiciones de la cotización.
        Sanctum::actingAs($seller);
        $contract = $this->postJson("/api/v1/quotations/{$quotation}/contract")->assertCreated()->json();
        $id = $contract['id'];
        $this->getJson("/api/v1/contracts/{$id}")->assertOk()
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('sla_level', 'ESTANDAR')
            ->assertJsonPath('terms', 'Pago a 30 días.')
            ->assertJsonPath('total_value', '295.0000')
            ->assertJsonPath('quotation.id', $quotation)
            ->assertJsonPath('customer.party.organization.legal_name', 'Minera Los Andes SAC')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('history.0.action', 'contract.generate');
        $this->assertSame(now()->addDay()->toDateString(), substr($this->getJson("/api/v1/contracts/{$id}")->json('start_date'), 0, 10));

        // Listado: por número, por cliente y por estado.
        $this->getJson('/api/v1/contracts?search=los andes')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.quotation.id', $quotation);
        $this->getJson('/api/v1/contracts?search='.$contract['contract_number'])->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/contracts?status=ACTIVE')->assertOk()->assertJsonPath('total', 0);

        // En borrador se ajustan vigencia, SLA y condiciones.
        $this->putJson("/api/v1/contracts/{$id}", ['start_date' => '2027-01-01', 'end_date' => '2026-12-31', 'sla_level' => 'PREMIUM'])
            ->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->putJson("/api/v1/contracts/{$id}", ['start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'sla_level' => 'PREMIUM', 'terms' => 'Nuevas'])
            ->assertOk()->assertJsonPath('sla_level', 'PREMIUM')->assertJsonPath('terms', 'Nuevas');

        // Activar pide su propio permiso; genera un servicio contratado por cada línea con servicio.
        $this->postJson("/api/v1/contracts/{$id}/activate")->assertStatus(403);
        $this->postJson("/api/v1/contracts/{$id}/orders")->assertStatus(403);
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/contracts/{$id}/orders")->assertStatus(422);
        $this->postJson("/api/v1/contracts/{$id}/activate", ['generate_services' => true])->assertOk()
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonCount(1, 'contracted_services')
            ->assertJsonPath('contracted_services.0.status', 'PENDING')
            ->assertJsonPath('contracted_services.0.service_code', 'SRV-100');
        $this->putJson("/api/v1/contracts/{$id}", ['start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'sla_level' => 'ESTANDAR'])->assertStatus(422);

        // Orden desde el contrato vigente, una sola.
        $order = $this->postJson("/api/v1/contracts/{$id}/orders")->assertCreated()
            ->assertJsonPath('status', 'DRAFT')->assertJsonCount(2, 'items')->json();
        $this->postJson("/api/v1/contracts/{$id}/orders")->assertStatus(422);
        $this->getJson("/api/v1/contracts/{$id}")->assertJsonPath('orders.0.order_number', $order['order_number']);

        // Suspender y terminar piden motivo; el historial guarda cada paso.
        $this->postJson("/api/v1/contracts/{$id}/suspend")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/contracts/{$id}/suspend", ['reason' => 'Falta de pago'])->assertOk()->assertJsonPath('status', 'SUSPENDED');
        $this->postJson("/api/v1/contracts/{$id}/expire")->assertStatus(422);
        $this->postJson("/api/v1/contracts/{$id}/reactivate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $detail = $this->postJson("/api/v1/contracts/{$id}/terminate", ['reason' => 'Fin anticipado'])->assertOk()
            ->assertJsonPath('status', 'TERMINATED')->json();
        $this->assertEqualsCanonicalizing(
            ['contract.terminate', 'contract.reactivate', 'contract.suspend', 'contract.activate', 'contract.update', 'contract.generate'],
            collect($detail['history'])->pluck('action')->all(),
        );
        $this->postJson("/api/v1/contracts/{$id}/reactivate")->assertStatus(422);

        // Un contrato inexistente o de otra empresa no existe.
        $this->getJson('/api/v1/contracts/'.Str::uuid())->assertStatus(404);
        $this->getJson('/api/v1/contracts/no-es-uuid')->assertStatus(404);
    }

    public function test_approved_quotation_becomes_a_direct_order_once(): void
    {
        [$seller, $tenantId] = $this->makeTenantUser([...self::PERMISSIONS, 'tenant.manage']);
        $manager = $this->makeColleague($tenantId, 'Gerente', ['quotations.view', 'quotations.approve', 'orders.create', 'orders.manage']);
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        Sanctum::actingAs($seller);

        $quotation = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'items' => [['description' => 'Auditoría', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 18]],
        ])->json('id');
        $this->postJson("/api/v1/quotations/{$quotation}/submit")->assertOk();
        // Sin el permiso orders.create no se convierte.
        $this->postJson("/api/v1/quotations/{$quotation}/order")->assertStatus(403);

        Sanctum::actingAs($manager);
        // Solo desde una cotización aprobada.
        $this->postJson("/api/v1/quotations/{$quotation}/order")->assertStatus(422);
        $this->postJson("/api/v1/quotations/{$quotation}/approve")->assertOk();
        $this->getJson("/api/v1/quotations/{$quotation}")->assertOk()
            ->assertJsonPath('direct_order_enabled', true)
            ->assertJsonPath('order', null);

        // La empresa puede no permitir órdenes directas.
        Sanctum::actingAs($seller);
        $this->putJson('/api/v1/tenant/settings', ['direct_order' => false])->assertOk()
            ->assertJsonPath('direct_order', false)
            ->assertJsonPath('segregation', true);
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/quotations/{$quotation}/order")->assertStatus(422);
        Sanctum::actingAs($seller);
        $this->putJson('/api/v1/tenant/settings', ['direct_order' => true])->assertOk();

        Sanctum::actingAs($manager);
        $order = $this->postJson("/api/v1/quotations/{$quotation}/order")->assertCreated()
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('total', '236.0000')
            ->assertJsonPath('items.0.description', 'Auditoría')
            ->json();
        $this->assertStringStartsWith('ORD-', $order['order_number']);

        // Una sola orden por cotización; la ficha la muestra.
        $this->postJson("/api/v1/quotations/{$quotation}/order")->assertStatus(422);
        $this->getJson("/api/v1/quotations/{$quotation}")->assertOk()->assertJsonPath('order.order_number', $order['order_number']);
    }

    public function test_order_lifecycle_follows_the_linear_sequence(): void
    {
        [$seller, $tenantId] = $this->makeTenantUser([...self::PERMISSIONS, 'orders.view']);
        $manager = $this->makeColleague($tenantId, 'Gerente', ['quotations.view', 'quotations.approve', 'orders.view', 'orders.create', 'orders.manage']);
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        Sanctum::actingAs($seller);

        $newOrder = function () use ($customerId, $seller, $manager) {
            Sanctum::actingAs($seller);
            $quotation = $this->postJson('/api/v1/quotations', [
                'customer_id' => $customerId,
                'items' => [['description' => 'Auditoría', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 18]],
            ])->json();
            $this->postJson("/api/v1/quotations/{$quotation['id']}/submit")->assertOk();
            Sanctum::actingAs($manager);
            $this->postJson("/api/v1/quotations/{$quotation['id']}/approve")->assertOk();

            return [$this->postJson("/api/v1/quotations/{$quotation['id']}/order")->assertCreated()->json(), $quotation];
        };

        [$order, $quotation] = $newOrder();
        $id = $order['id'];

        // Ficha: líneas, cliente, origen, notas, responsable e historial.
        $this->getJson("/api/v1/orders/{$id}")->assertOk()
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('notes', "Orden directa desde {$quotation['quotation_number']}")
            ->assertJsonPath('creator.name', 'Gerente')
            ->assertJsonPath('quotation.quotation_number', $quotation['quotation_number'])
            ->assertJsonPath('contract', null)
            ->assertJsonPath('customer.party.organization.legal_name', 'Minera Los Andes SAC')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('history.0.action', 'order.generate')
            ->assertJsonPath('history.0.metadata.to', 'DRAFT');

        // Listado: por número, por cliente y por estado.
        $this->getJson('/api/v1/orders?search=los andes')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.quotation.id', $quotation['id']);
        $this->getJson('/api/v1/orders?search='.$order['order_number'])->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/orders?search=no-existe')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/orders?status=CONFIRMED')->assertOk()->assertJsonPath('total', 0);

        // Ver no alcanza para cambiar el estado.
        Sanctum::actingAs($seller);
        $this->getJson("/api/v1/orders/{$id}")->assertOk();
        $this->postJson("/api/v1/orders/{$id}/confirm")->assertStatus(403);

        // No se salta pasos: ni iniciar ni completar un borrador.
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/orders/{$id}/start")->assertStatus(422);
        $this->postJson("/api/v1/orders/{$id}/complete")->assertStatus(422);
        $this->postJson("/api/v1/orders/{$id}/confirm")->assertOk()->assertJsonPath('status', 'CONFIRMED');
        $this->getJson('/api/v1/orders?status=CONFIRMED')->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/v1/orders/{$id}/complete")->assertStatus(422);
        $this->postJson("/api/v1/orders/{$id}/start")->assertOk()->assertJsonPath('status', 'IN_PROGRESS');

        // En ejecución ya no se cancela.
        $this->postJson("/api/v1/orders/{$id}/cancel", ['reason' => 'Tarde'])->assertStatus(422);
        $detail = $this->postJson("/api/v1/orders/{$id}/complete")->assertOk()->assertJsonPath('status', 'COMPLETED')->json();
        $this->assertEqualsCanonicalizing(
            ['order.complete', 'order.start', 'order.confirm', 'order.generate'],
            collect($detail['history'])->pluck('action')->all(),
        );
        $this->postJson("/api/v1/orders/{$id}/confirm")->assertStatus(422);

        // Cancelar pide motivo y vale desde borrador o confirmada.
        [$second] = $newOrder();
        $this->postJson("/api/v1/orders/{$second['id']}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/orders/{$second['id']}/confirm")->assertOk();
        $cancelled = $this->postJson("/api/v1/orders/{$second['id']}/cancel", ['reason' => 'El cliente desistió'])->assertOk()
            ->assertJsonPath('status', 'CANCELLED')->json();
        $this->assertEquals(
            ['from' => 'CONFIRMED', 'to' => 'CANCELLED', 'note' => 'El cliente desistió'],
            collect($cancelled['history'])->firstWhere('action', 'order.cancel')['metadata'],
        );
        $this->postJson("/api/v1/orders/{$second['id']}/confirm")->assertStatus(422);

        // Una orden inexistente o de otra empresa no existe.
        $this->getJson('/api/v1/orders/'.Str::uuid())->assertStatus(404);
        $this->getJson('/api/v1/orders/no-es-uuid')->assertStatus(404);

        [$other] = $this->makeTenantUser([...self::PERMISSIONS, 'orders.view']);
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/orders/{$id}")->assertStatus(404);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_portal_requests_are_taken_quoted_or_rejected(): void
    {
        [$seller, $tenantId] = $this->makeTenantUser([...self::PERMISSIONS, 'portal.view']);
        $manager = $this->makeColleague($tenantId, 'Gerente', ['portal.view', 'portal.manage']);
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        $otherCustomerId = $this->makeOrganizationCustomer($tenantId, 'Clínica del Sur SAC', '20987654321');
        Sanctum::actingAs($seller);
        $serviceId = $this->makeActiveService($this->makeCategory(), 'SRV-100', 100);

        $newRequest = fn (string $number, string $subject) => DB::table('portal_requests')->insertGetId([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'customer_id' => $customerId,
            'request_number' => $number, 'request_type' => 'NEW_SERVICE', 'subject' => $subject,
            'description' => 'Necesitamos mantenimiento para 6 servidores.', 'service_id' => $serviceId,
            'status' => 'SUBMITTED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $newRequest('SOL-2026-0001', 'Mantenimiento de servidores');
        $second = $newRequest('SOL-2026-0002', 'Auditoría anual');

        // Bandeja: con cliente y servicio; se busca por número, cliente o texto y se filtra por estado.
        $this->getJson('/api/v1/portal-requests')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/portal-requests?search=SOL-2026-0001')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.service.code', 'SRV-100')
            ->assertJsonPath('data.0.customer.party.organization.legal_name', 'Minera Los Andes SAC');
        $this->getJson('/api/v1/portal-requests?search=los andes')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/portal-requests?search=auditor')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/portal-requests?status=QUOTED')->assertOk()->assertJsonPath('total', 0);

        // Tomarla la deja en revisión; no se toma dos veces.
        $this->postJson("/api/v1/portal-requests/{$first}/take")->assertOk()->assertJsonPath('status', 'IN_REVIEW');
        $this->postJson("/api/v1/portal-requests/{$first}/take")->assertStatus(422);

        // Cotizarla: la cotización nace con origen Portal y la solicitud queda cotizada y enlazada.
        $line = ['service_id' => $serviceId, 'description' => 'Servicio SRV-100', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 18];
        $this->postJson('/api/v1/quotations', ['customer_id' => $otherCustomerId, 'portal_request_id' => $first, 'items' => [$line]])
            ->assertStatus(422)->assertJsonValidationErrors('portal_request_id');
        $quotation = $this->postJson('/api/v1/quotations', ['customer_id' => $customerId, 'portal_request_id' => $first, 'items' => [$line]])
            ->assertCreated()->assertJsonPath('source_type', 'PORTAL')->json();
        $this->getJson('/api/v1/portal-requests?status=QUOTED')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.quotation.quotation_number', $quotation['quotation_number']);
        $this->getJson('/api/v1/quotations?source=PORTAL')->assertOk()->assertJsonPath('total', 1);
        // Una solicitud ya cotizada no se cotiza ni se rechaza otra vez.
        $this->postJson('/api/v1/quotations', ['customer_id' => $customerId, 'portal_request_id' => $first, 'items' => [$line]])
            ->assertStatus(422)->assertJsonValidationErrors('portal_request_id');

        // Rechazar pide su permiso y un motivo.
        $this->postJson("/api/v1/portal-requests/{$second}/reject", ['reason' => 'Fuera de cobertura'])->assertStatus(403);
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/portal-requests/{$second}/take")->assertStatus(403);
        $this->postJson("/api/v1/portal-requests/{$second}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/portal-requests/{$second}/reject", ['reason' => 'Fuera de cobertura'])->assertOk()
            ->assertJsonPath('status', 'REJECTED')->assertJsonPath('resolution_note', 'Fuera de cobertura');
        $this->postJson("/api/v1/portal-requests/{$first}/reject", ['reason' => 'Tarde'])->assertStatus(422);
        $this->postJson('/api/v1/portal-requests/no-es-uuid/reject', ['reason' => 'x'])->assertStatus(404);

        // Sin portal.view no se ve la bandeja; otra empresa no ve estas solicitudes.
        [$outsider] = $this->makeTenantUser([...self::PERMISSIONS, 'portal.view']);
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/portal-requests')->assertOk()->assertJsonPath('total', 0);
        [$noAccess] = $this->makeTenantUser();
        Sanctum::actingAs($noAccess);
        $this->getJson('/api/v1/portal-requests')->assertStatus(403);
    }

    public function test_actors_are_one_identity_with_several_roles(): void
    {
        [$user, $tenantId] = $this->makeTenantUser(['core.view', 'core.manage']);
        Sanctum::actingAs($user);

        // Una organización nace con sus roles, su sector y sus contactos principales.
        $this->postJson('/api/v1/parties', ['kind' => 'ORGANIZATION', 'legal_name' => 'Minera Los Andes SAC', 'roles' => ['CUSTOMER']])
            ->assertStatus(422)->assertJsonValidationErrors('tax_identifier');
        $this->postJson('/api/v1/parties', ['kind' => 'ORGANIZATION', 'legal_name' => 'X SAC', 'tax_identifier' => '20123456789', 'roles' => ['EMPLOYEE']])
            ->assertStatus(422)->assertJsonValidationErrors('roles');
        $org = $this->postJson('/api/v1/parties', [
            'kind' => 'ORGANIZATION', 'legal_name' => 'Minera Los Andes SAC', 'trade_name' => 'Los Andes',
            'tax_identifier' => '20123456789', 'sector' => 'Minería', 'sla_level' => 'PREMIUM',
            'email' => 'compras@losandes.pe', 'phone' => '999111222', 'address' => 'Av. Principal 123',
            'roles' => ['CUSTOMER', 'SUPPLIER'],
        ])->assertCreated()
            ->assertJsonPath('name', 'Los Andes')
            ->assertJsonPath('roles', ['CUSTOMER', 'SUPPLIER'])
            ->assertJsonPath('sector', 'Minería')
            ->assertJsonPath('sla_level', 'PREMIUM')
            ->assertJsonPath('email', 'compras@losandes.pe')
            ->assertJsonPath('address', 'Av. Principal 123')
            ->assertJsonPath('history.0.action', 'party.create')
            ->json();
        // Son los mismos clientes y proveedores de los módulos de siempre.
        $this->getJson('/api/v1/customers/'.$org['customer_id'])->assertOk()->assertJsonPath('party.organization.legal_name', 'Minera Los Andes SAC');
        $this->getJson('/api/v1/suppliers/'.$org['supplier_id'])->assertOk();

        // El mismo RUC no se duplica: se ofrece la identidad existente.
        $this->postJson('/api/v1/parties', ['kind' => 'ORGANIZATION', 'legal_name' => 'Otra razón SAC', 'tax_identifier' => '20123456789', 'roles' => ['SUPPLIER']])
            ->assertStatus(409)->assertJsonPath('duplicates.0.id', $org['id'])->assertJsonPath('duplicates.0.roles', ['CUSTOMER', 'SUPPLIER']);

        // Una persona: empleado con su código; luego se le agrega el rol de cliente sin duplicarla.
        $person = $this->postJson('/api/v1/parties', [
            'kind' => 'PERSON', 'given_names' => 'Lucía', 'family_name' => 'Paredes', 'document_type' => 'DNI',
            'document_number' => '44556677', 'position' => 'Técnica', 'roles' => ['EMPLOYEE'],
        ])->assertCreated()->assertJsonPath('roles', ['EMPLOYEE'])->assertJsonPath('position', 'Técnica')->json();
        $this->assertStringStartsWith('EMP-', $person['employee_code']);
        $this->postJson("/api/v1/parties/{$person['id']}/roles", ['role' => 'CUSTOMER'])->assertOk()->assertJsonPath('roles', ['CUSTOMER', 'EMPLOYEE']);
        $this->postJson("/api/v1/parties/{$person['id']}/roles", ['role' => 'CUSTOMER'])->assertStatus(422);
        $this->postJson("/api/v1/parties/{$org['id']}/roles", ['role' => 'EMPLOYEE'])->assertStatus(422);

        // Rol Contacto: una persona enlazada a la organización, con su teléfono y el prefijo del país.
        $this->postJson('/api/v1/parties', ['kind' => 'ORGANIZATION', 'legal_name' => 'Y SAC', 'tax_identifier' => '20111111111', 'roles' => ['CONTACT']])
            ->assertStatus(422)->assertJsonValidationErrors('roles');
        $this->postJson('/api/v1/parties', ['kind' => 'PERSON', 'given_names' => 'Raúl', 'family_name' => 'Cárdenas', 'roles' => ['CONTACT'], 'contact_of' => $person['id']])
            ->assertStatus(422)->assertJsonValidationErrors('contact_of');
        $contactPerson = $this->postJson('/api/v1/parties', [
            'kind' => 'PERSON', 'given_names' => 'Raúl', 'family_name' => 'Cárdenas', 'position' => 'Jefe de compras',
            'phone' => '501234567', 'phone_country_code' => '+971', 'roles' => ['CONTACT'], 'contact_of' => $org['id'],
        ])->assertCreated()
            ->assertJsonPath('roles', ['CONTACT'])
            ->assertJsonPath('position', 'Jefe de compras')
            ->assertJsonPath('contact_of.name', 'Los Andes')
            ->assertJsonPath('phone', '501234567')
            ->assertJsonPath('phone_country_code', '+971')
            ->assertJsonPath('employee_id', null)
            ->json();
        $this->getJson("/api/v1/parties/{$org['id']}")->assertOk()
            ->assertJsonCount(1, 'contact_people')
            ->assertJsonPath('contact_people.0.name', 'Raúl Cárdenas')
            ->assertJsonPath('contact_people.0.position', 'Jefe de compras')
            ->assertJsonPath('contact_people.0.phone_country_code', '+971');
        $this->getJson('/api/v1/parties?role=CONTACT')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $contactPerson['id']);
        // A una persona que ya existe se le agrega el rol y se enlaza después.
        $this->postJson("/api/v1/parties/{$person['id']}/roles", ['role' => 'CONTACT'])->assertOk()->assertJsonPath('roles', ['CUSTOMER', 'CONTACT', 'EMPLOYEE']);
        $this->putJson("/api/v1/parties/{$person['id']}", ['contact_of' => $org['id']])->assertOk()->assertJsonPath('contact_of.id', $org['id']);
        $this->getJson("/api/v1/parties/{$org['id']}")->assertOk()->assertJsonCount(2, 'contact_people');
        $this->putJson("/api/v1/parties/{$person['id']}", ['contact_of' => null])->assertOk()->assertJsonPath('contact_of', null);
        $this->postJson("/api/v1/parties/{$org['id']}/roles", ['role' => 'CONTACT'])->assertStatus(422);

        // Listado: buscar por nombre, RUC, documento o correo, y filtrar por rol, tipo y estado.
        $this->getJson('/api/v1/parties')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/v1/parties?search=los andes')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $org['id']);
        $this->getJson('/api/v1/parties?search=44556677')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/parties?search=compras@')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/parties?role=SUPPLIER')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/parties?role=CUSTOMER')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/parties?kind=PERSON')->assertOk()->assertJsonPath('total', 2);

        // Editar solo lo que llega (así guarda la edición en celda); el documento no puede chocar con otro.
        $this->putJson("/api/v1/parties/{$org['id']}", ['sector' => 'Energía', 'email' => ''])->assertOk()
            ->assertJsonPath('sector', 'Energía')->assertJsonPath('email', null)->assertJsonPath('organization.legal_name', 'Minera Los Andes SAC');
        $this->putJson("/api/v1/parties/{$person['id']}", ['family_name' => 'Paredes Ríos'])->assertOk()->assertJsonPath('name', 'Lucía Paredes Ríos');
        $second = $this->postJson('/api/v1/parties', ['kind' => 'PERSON', 'given_names' => 'Ana', 'family_name' => 'Torres', 'document_number' => '11223344', 'roles' => ['CUSTOMER']])
            ->assertCreated()->json();
        $this->putJson("/api/v1/parties/{$second['id']}", ['document_number' => '44556677'])->assertStatus(422)->assertJsonValidationErrors('document_number');

        // Contactos de la identidad: agregar, editar (uno solo es el principal de su tipo) y quitar.
        $contact = $this->postJson('/api/v1/contacts', [
            'party_id' => $org['id'], 'contact_name' => 'María López', 'contact_type' => 'PHONE', 'value' => '987654321', 'country_code' => '+51', 'is_primary' => true,
        ])->assertCreated()->json();
        $this->putJson("/api/v1/contacts/{$contact['id']}", ['contact_type' => 'PHONE'])->assertStatus(422)->assertJsonValidationErrors('value');
        $this->putJson("/api/v1/contacts/{$contact['id']}", ['contact_name' => 'María López (Compras)', 'contact_type' => 'PHONE', 'value' => '911222333', 'is_primary' => true])
            ->assertOk()->assertJsonPath('value', '911222333');
        $contacts = collect($this->getJson("/api/v1/parties/{$org['id']}")->json('contacts'))->where('contact_type', 'PHONE');
        $this->assertCount(2, $contacts);
        $this->assertSame(['María López (Compras)'], $contacts->where('is_primary', true)->pluck('contact_name')->all());
        $this->deleteJson("/api/v1/contacts/{$contact['id']}")->assertOk();
        // Las condiciones comerciales se editan en el cliente de esa identidad.
        $this->putJson('/api/v1/customers/'.$org['customer_id'], ['payment_terms_days' => 45, 'credit_limit' => 5000, 'preferred_currency' => 'USD'])->assertOk();
        $this->getJson("/api/v1/parties/{$org['id']}")->assertOk()
            ->assertJsonPath('customer.payment_terms_days', 45)->assertJsonPath('customer.preferred_currency', 'USD')
            ->assertJsonPath('organization.legal_name', 'Minera Los Andes SAC');

        // Baja y alta lógica: la identidad y todos sus roles cambian juntos.
        $this->postJson("/api/v1/parties/{$org['id']}/deactivate")->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->postJson("/api/v1/parties/{$org['id']}/deactivate")->assertStatus(422);
        $this->getJson('/api/v1/customers/'.$org['customer_id'])->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->getJson('/api/v1/parties?status=INACTIVE')->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/api/v1/parties/bulk-activate', ['ids' => [$org['id'], $person['id']]])->assertOk()->assertJsonPath('changed', 1);
        $this->getJson("/api/v1/parties/{$org['id']}")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson('/api/v1/parties/bulk-deactivate', ['ids' => [$org['id'], $person['id']]])->assertOk()->assertJsonPath('changed', 2);
        // Una persona que solo es contacto no tiene roles con tabla: su estado es el de la identidad.
        $this->postJson("/api/v1/parties/{$contactPerson['id']}/deactivate")->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->postJson("/api/v1/parties/{$contactPerson['id']}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');

        // Un cliente dado de baja desde el módulo Clientes se ve inactivo aquí.
        $this->postJson('/api/v1/customers/'.$second['customer_id'].'/deactivate')->assertOk();
        $this->getJson("/api/v1/parties/{$second['id']}")->assertOk()->assertJsonPath('status', 'INACTIVE')->assertJsonPath('inactive_roles', ['CUSTOMER']);

        // Sin core.manage solo se consulta; otra empresa no ve nada.
        [$reader] = $this->makeTenantUser(['core.view']);
        Sanctum::actingAs($reader);
        $this->getJson('/api/v1/parties')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/parties/{$org['id']}")->assertStatus(404);
        $this->postJson('/api/v1/parties', ['kind' => 'PERSON', 'given_names' => 'A', 'family_name' => 'B', 'roles' => ['CUSTOMER']])->assertStatus(403);
    }

    public function test_actors_and_services_are_imported_from_a_file(): void
    {
        [$user, $tenantId] = $this->makeTenantUser(['core.view', 'core.manage', 'services.view', 'services.manage']);
        Sanctum::actingAs($user);
        $upload = fn (string $name, string $csv) => ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent($name, $csv)];

        // Actores: instrucciones con "#", títulos con tildes y espacios, y una columna extra.
        $csv = implode("\n", [
            '# Plantilla de importación',
            'Tipo;Razón social;Nombres;Apellidos;Tipo documento;Número documento;Roles;Sector;Correo;Prefijo;Teléfono;Cargo;Contacto de;Días crédito;Límite crédito;Moneda;Zona Asignada',
            'ORGANIZACION;Minera Los Andes SAC;;;RUC;20123456789;Cliente, Proveedor;Minería;compras@losandes.pe;+51;999111222;;;45;5000;USD;Norte',
            'PERSONA;;Raúl;Cárdenas;DNI;44556677;Contacto;;;+57;3001234567;Jefe de compras;20123456789;;;;Sur',
            'PERSONA;;Ana;Torres;DNI;11223344;Empleado;;;;;Técnica;;;;;',
            'ORGANIZACION;Sin RUC SAC;;;;;Cliente;;;;;;;;;;',
            'PERSONA;;Luis;Rojas;DNI;99887766;Gerente;;;;;;;;;;',
            'PERSONA;;Eva;Paz;DNI;55667788;Contacto;;;;;;20999999999;;;;',
        ]);
        $result = $this->post('/api/v1/parties/import', $upload('actores.csv', $csv), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 3)->assertJsonPath('updated', 0)->json();
        $this->assertCount(3, $result['warnings']);
        $this->assertStringContainsString('Fila 6', $result['warnings'][0]);
        $this->assertStringContainsString('Rol desconocido', $result['warnings'][1]);
        $this->assertStringContainsString('no existe la organización', $result['warnings'][2]);

        $org = $this->getJson('/api/v1/parties?search=20123456789')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.roles', ['CUSTOMER', 'SUPPLIER'])->assertJsonPath('data.0.sector', 'Minería')
            ->assertJsonPath('data.0.phone_country_code', '+51')->json('data.0');
        $this->getJson("/api/v1/parties/{$org['id']}")->assertOk()
            ->assertJsonPath('customer.payment_terms_days', 45)->assertJsonPath('customer.preferred_currency', 'USD')
            ->assertJsonPath('contact_people.0.name', 'Raúl Cárdenas')->assertJsonPath('contact_people.0.position', 'Jefe de compras');
        $this->getJson('/api/v1/parties?role=EMPLOYEE')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.position', 'Técnica');
        // La columna extra quedó como columna personalizada de la tabla, con su valor.
        $field = $this->getJson('/api/v1/custom-fields/definitions?entity_type=Party')->assertOk()->assertJsonPath('0.label', 'Zona asignada')->json('0');
        $this->getJson("/api/v1/custom-fields/values?entity_type=Party&entity_id={$org['id']}")->assertOk()
            ->assertJsonPath('0.field_id', $field['id'])->assertJsonPath('0.value', 'Norte');

        // Volver a importar no duplica: a quien ya existe solo se le agrega el rol que le falte.
        $again = "tipo;razon_social;nombres;apellidos;numero_documento;roles\nORGANIZACION;Minera Los Andes SAC;;;20123456789;Cliente\nPERSONA;;Ana;Torres;11223344;Cliente";
        $this->post('/api/v1/parties/import', $upload('otra.csv', $again), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 0)->assertJsonPath('updated', 1);
        $this->getJson('/api/v1/parties')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/v1/parties?search=11223344')->assertOk()->assertJsonPath('data.0.roles', ['CUSTOMER', 'EMPLOYEE']);

        // Un archivo sin cabeceras reconocibles, o sin ninguna fila válida, se rechaza.
        $this->post('/api/v1/parties/import', $upload('mal.csv', "a;b;c\n1;2;3"), ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/v1/parties/import', $upload('mal.csv', "tipo;razon_social;numero_documento\nORGANIZACION;X SAC;123"), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'No se pudo importar ningún registro.');
        $this->get('/api/v1/parties/template')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Servicios: entran en borrador, con su categoría (se crea si falta), recurrencia y precio.
        $services = implode("\n", [
            'Código,Nombre,Categoría,Unidad,Modalidad,Frecuencia,Garantía meses,Precio,Descripción',
            'SRV-101,Mantenimiento preventivo,Mantenimiento,visita,Único,,3,380,Revisión general',
            'SRV-102,Soporte mensual,Soporte,mes,Recurrente,Mensual,0,1200,',
            'SRV-103,Sin frecuencia,Soporte,mes,Recurrente,,0,100,',
            'SRV-101,Repetido,Soporte,,,,,,',
            ',Sin código,Soporte,,,,,,',
        ]);
        $result = $this->post('/api/v1/services/import', $upload('servicios.csv', $services), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 2)->json();
        $this->assertCount(3, $result['warnings']);
        $this->assertStringContainsString('frecuencia', $result['warnings'][0]);
        $this->assertStringContainsString('único', $result['warnings'][1]);
        $this->assertStringContainsString('son obligatorios', $result['warnings'][2]);
        $this->getJson('/api/v1/services?search=SRV-101')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'DRAFT')->assertJsonPath('data.0.category.name', 'Mantenimiento')
            ->assertJsonPath('data.0.warranty_months', 3)->assertJsonPath('data.0.current_price.amount', '380.0000');
        $this->getJson('/api/v1/services?search=SRV-102')->assertOk()
            ->assertJsonPath('data.0.billing_mode', 'RECURRING')->assertJsonPath('data.0.recurrence.frequency', 'MONTHLY');
        $this->assertSame(2, DB::table('service_categories')->where('tenant_id', $tenantId)->count());
        $this->get('/api/v1/services/template')->assertOk();

        // La plantilla Excel que se descarga se puede importar tal cual, con sus filas de ejemplo.
        [$other] = $this->makeTenantUser(['core.view', 'core.manage', 'services.view', 'services.manage']);
        Sanctum::actingAs($other);
        $template = fn (string $path) => ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('plantilla.xlsx', $this->get($path)->streamedContent())];
        $this->post('/api/v1/parties/import', $template('/api/v1/parties/template'), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 3)->assertJsonPath('warnings', []);
        $this->getJson('/api/v1/parties?search=20123456789')->assertOk()
            ->assertJsonPath('data.0.roles', ['CUSTOMER', 'SUPPLIER'])->assertJsonPath('data.0.sla_level', 'PREMIUM')->assertJsonPath('data.0.phone', '999888777');
        $this->getJson('/api/v1/parties?role=CONTACT')->assertOk()->assertJsonPath('data.0.contact_of.name', 'Ejemplo')->assertJsonPath('data.0.person.document_number', '12345678');
        $this->post('/api/v1/services/import', $template('/api/v1/services/template'), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 3)->assertJsonPath('warnings', []);
        $this->getJson('/api/v1/services?search=SRV-103')->assertOk()->assertJsonPath('data.0.billing_mode', 'PER_EVENT')->assertJsonPath('data.0.unit', 'hora');

        // Importar pide el permiso de gestión.
        [$reader] = $this->makeTenantUser(['core.view', 'services.view']);
        Sanctum::actingAs($reader);
        $this->post('/api/v1/parties/import', $upload('actores.csv', $csv), ['Accept' => 'application/json'])->assertStatus(403);
        $this->post('/api/v1/services/import', $upload('servicios.csv', $services), ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_roles_matrix_is_edited_through_the_permission_tables(): void
    {
        [$admin, $tenantId] = $this->makeTenantUser([...self::PERMISSIONS, 'users.view', 'users.manage', 'tenant.manage'], ['SERVICIOS']);
        DB::table('tenant_modules')->insert([
            ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'module_code' => 'COTIZACIONES', 'enabled' => true],
        ]);
        $seller = $this->makeColleague($tenantId, 'Vendedor', ['quotations.view', 'quotations.manage']);
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        Sanctum::actingAs($admin);

        // El catálogo solo ofrece los permisos de los módulos de la empresa (sin CRM, sin contratos).
        $list = $this->getJson('/api/v1/roles')->assertOk()->json();
        $codes = collect($list['permissions'])->pluck('code');
        $this->assertTrue($codes->contains('quotations.approve'));
        $this->assertTrue($codes->contains('core.view'));
        $this->assertFalse($codes->contains('leads.view'));

        // Rol nuevo, sin permisos; no se repite el nombre.
        $roleId = $this->postJson('/api/v1/roles', ['name' => 'Gerencia comercial', 'description' => 'Aprueba'])
            ->assertCreated()->assertJsonPath('code', 'GERENCIA_COMERCIAL')->json('id');
        $this->postJson('/api/v1/roles', ['name' => 'Gerencia Comercial'])->assertStatus(422);

        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['quotations.view', 'leads.view']])->assertStatus(422);
        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['quotations.view', 'quotations.approve']])
            ->assertOk()->assertJsonPath('permissions', ['quotations.approve', 'quotations.view']);
        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['quotations.view']])
            ->assertOk()->assertJsonPath('permissions', ['quotations.view']);

        // Darle "Aprobar" al rol del vendedor surte efecto de inmediato.
        $sellerRole = collect($this->getJson('/api/v1/roles')->json('roles'))->firstWhere('name', 'Vendedor');
        $this->assertSame(1, $sellerRole['users']);
        $quotation = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 10]],
        ])->json('id');
        $this->postJson("/api/v1/quotations/{$quotation}/submit")->assertOk();

        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/quotations/{$quotation}/approve")->assertStatus(403);
        $this->putJson("/api/v1/roles/{$sellerRole['id']}/permissions", ['permissions' => []])->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/roles/{$sellerRole['id']}/permissions", [
            'permissions' => ['quotations.view', 'quotations.manage', 'quotations.approve'],
        ])->assertOk();
        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/quotations/{$quotation}/approve")->assertOk();

        // El rol Administrador no se recorta; un rol de otra empresa no existe.
        Sanctum::actingAs($admin);
        DB::table('roles')->where('tenant_id', $tenantId)->where('code', 'VENTAS')->update(['code' => 'ADMIN']);
        $adminRole = collect($this->getJson('/api/v1/roles')->json('roles'))->firstWhere('is_admin', true);
        $this->putJson("/api/v1/roles/{$adminRole['id']}/permissions", ['permissions' => []])->assertStatus(422);
        $this->putJson('/api/v1/roles/'.Str::uuid().'/permissions', ['permissions' => []])->assertStatus(404);

        // Ajuste de segregación de la empresa.
        $this->getJson('/api/v1/tenant/settings')->assertOk()->assertJsonPath('segregation', true);
        $this->putJson('/api/v1/tenant/settings', ['segregation' => false])->assertOk()->assertJsonPath('segregation', false);
        Sanctum::actingAs($seller);
        $this->putJson('/api/v1/tenant/settings', ['segregation' => true])->assertStatus(403);
    }

    public function test_company_users_are_managed_with_their_role(): void
    {
        Mail::fake();
        [$admin, $tenantId] = $this->makeTenantUser(['users.view', 'users.manage', 'quotations.view']);
        DB::table('roles')->where('tenant_id', $tenantId)->where('code', 'VENTAS')->update(['code' => 'ADMIN', 'name' => 'Administrador']);
        $adminRoleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', 'ADMIN')->value('id');
        Sanctum::actingAs($admin);

        $salesRoleId = $this->postJson('/api/v1/roles', ['name' => 'Ventas'])->assertCreated()->json('id');
        $this->putJson("/api/v1/roles/{$salesRoleId}/permissions", ['permissions' => ['users.view']])->assertOk();

        // Alta: queda activa, con su rol, y recibe el código para crear su contraseña.
        $email = 'nueva-'.strtolower(Str::random(6)).'@erp-saas.test';
        $this->postJson('/api/v1/users', ['name' => 'Lucía Paredes', 'email' => $email])->assertStatus(422);
        $userId = $this->postJson('/api/v1/users', ['name' => 'Lucía Paredes', 'email' => strtoupper($email), 'role_id' => $salesRoleId])
            ->assertCreated()->json('id');
        Mail::assertSent(PasswordResetCodeMail::class, fn ($mail) => $mail->hasTo($email));
        $this->postJson('/api/v1/users', ['name' => 'Otra', 'email' => $email, 'role_id' => $salesRoleId])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/users', ['name' => 'Otra', 'email' => 'x-'.$email, 'role_id' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors('role_id');

        $list = $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(2, 'users')->json();
        $created = collect($list['users'])->firstWhere('id', $userId);
        $this->assertSame('Ventas', $created['role']['name']);
        $this->assertSame('ACTIVE', $created['status']);
        $this->assertFalse($created['mfa']);
        $this->assertTrue(collect($list['users'])->firstWhere('id', $admin->id)['mfa']);
        $this->assertNull($list['limit']);

        // Nadie cambia su propio rol ni se desactiva; la empresa no se queda sin administrador.
        $own = ['name' => $admin->name, 'email' => $admin->email, 'role_id' => $salesRoleId];
        $this->putJson("/api/v1/users/{$admin->id}", $own)->assertStatus(422)->assertJsonValidationErrors('role_id');
        $this->postJson("/api/v1/users/{$admin->id}/status", ['status' => 'INACTIVE'])->assertStatus(422);

        // Cambiar el rol y exigir verificación en dos pasos.
        $this->putJson("/api/v1/users/{$userId}", ['name' => 'Lucía P.', 'email' => $email, 'role_id' => $adminRoleId, 'mfa' => true])->assertOk();
        $updated = collect($this->getJson('/api/v1/users')->json('users'))->firstWhere('id', $userId);
        $this->assertSame('Lucía P.', $updated['name']);
        $this->assertTrue($updated['role']['is_admin']);
        $this->assertSame(1, DB::table('user_roles')->where('user_id', $userId)->count());

        // Desactivada no entra a la empresa; se puede reactivar.
        $this->postJson("/api/v1/users/{$userId}/status", ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->assertNull(DB::selectOne('SELECT resolve_active_tenant_for_user(?) AS id', [$userId])->id);
        $this->postJson("/api/v1/users/{$userId}/status", ['status' => 'ACTIVE'])->assertOk();

        // Un usuario de otra empresa no existe; sin permiso no se gestiona.
        [$outsider] = $this->makeTenantUser(['users.view']);
        DB::statement("SET app.tenant_id = '{$tenantId}'");
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/users/{$outsider->id}/status", ['status' => 'INACTIVE'])->assertStatus(404);

        $viewer = $this->makeColleague($tenantId, 'Solo lectura', ['users.view']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/users')->assertOk();
        $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'y-'.$email, 'role_id' => $salesRoleId])->assertStatus(403);
    }
}
