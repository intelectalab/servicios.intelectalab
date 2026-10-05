<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComercialTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = [
        'core.view', 'services.view', 'services.manage', 'quotations.view', 'quotations.manage',
    ];

    /** Usuario con rol "Ventas" (y esos permisos) en un tenant nuevo. Devuelve [usuario, tenantId]. */
    private function makeTenantUser(array $permissions = self::PERMISSIONS): array
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

        $this->getJson("/api/v1/quotations/{$first['id']}")->assertOk()
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
        [$user, $tenantId] = $this->makeTenantUser();
        $customerId = $this->makeOrganizationCustomer($tenantId, 'Minera Los Andes SAC', '20123456789');
        Sanctum::actingAs($user);
        $payload = ['customer_id' => $customerId, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 10]]];

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

        $approved = $this->postJson('/api/v1/quotations', [...$payload, 'valid_until' => now()->toDateString()])->json('id');
        $this->postJson("/api/v1/quotations/{$approved}/submit")->assertOk();
        $this->postJson("/api/v1/quotations/{$approved}/approve", ['comment' => 'Conforme'])
            ->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->getJson("/api/v1/quotations/{$approved}")->assertJsonPath('approvals.0.comments', 'Conforme');

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
            ->assertJsonPath('previous.quotation_number', $original['quotation_number']);

        $this->postJson("/api/v1/quotations/{$original['id']}/versions")->assertStatus(422);
    }
}
