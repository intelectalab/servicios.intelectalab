<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Throwable;

/**
 * CRM, flujo principal: lead → oportunidad → ganada / perdida → conversión a cliente.
 * La conversión crea o reutiliza la identidad de Clientes y actores: nunca un cliente paralelo.
 */
class CrmTest extends TestCase
{
    use DatabaseTransactions;

    private const SALES = [
        'core.view', 'leads.view', 'leads.manage', 'opportunities.view', 'opportunities.manage', 'quotations.view', 'quotations.manage',
    ];

    private const MANAGEMENT = [
        'core.view', 'core.manage', 'leads.view', 'opportunities.view', 'opportunities.manage', 'quotations.view', 'quotations.manage',
    ];

    private string $tenantId;

    /** Empresa nueva con CRM y Cotizaciones. */
    private function makeTenant(array $modules = ['CRM', 'COTIZACIONES', 'SERVICIOS']): string
    {
        $this->tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $this->tenantId, 'code' => 'test-'.Str::random(8), 'name' => 'Empresa de Prueba SAC', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SET app.tenant_id = '{$this->tenantId}'");

        foreach ($modules as $module) {
            $this->asPlatformFixture(fn () => DB::table('tenant_modules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'module_code' => $module, 'enabled' => true,
            ]));
        }

        return $this->tenantId;
    }

    /** Rol con esos permisos y ese alcance en leads y oportunidades (null = sin definir). */
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
        foreach ($scope ? ['leads', 'opportunities', 'quotations'] : [] as $resource) {
            DB::table('role_scopes')->insert(['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'resource' => $resource, 'scope' => $scope]);
        }

        return $roleId;
    }

    private function makeUser(string $name, string $roleId): User
    {
        $user = User::create([
            'email' => 'crm-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('Actual123'),
            'name' => $name,
            'status' => 'ACTIVE',
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

    /** @return array{0: string, 1: ?string} id de la identidad y de su cliente (si lo es) */
    private function makeOrganization(string $legalName, string $ruc, bool $customer = true): array
    {
        $partyId = (string) Str::uuid();
        DB::table('parties')->insert([
            'id' => $partyId, 'tenant_id' => $this->tenantId, 'party_type' => 'ORGANIZATION',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('organizations')->insert(['party_id' => $partyId, 'legal_name' => $legalName, 'tax_identifier' => $ruc]);

        $customerId = null;
        if ($customer) {
            $customerId = (string) Str::uuid();
            DB::table('customers')->insert([
                'id' => $customerId, 'tenant_id' => $this->tenantId, 'party_id' => $partyId,
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$partyId, $customerId];
    }

    private function sourceId(string $name = 'Web'): string
    {
        return collect($this->getJson('/api/v1/crm/opportunities/options')->assertOk()->json('sources'))->firstWhere('name', $name)['id'];
    }

    /** La persona crea un lead y devuelve su id. */
    private function lead(User $as, array $data = []): string
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/v1/crm/leads', [
            'organization_name' => 'Agroindustrias del Sur SAC',
            'person_name' => 'Rosa Quispe Mamani',
            'position' => 'Gerente general',
            'email' => 'rosa-'.Str::random(6).'@agrosur.test',
            'phone' => '987654321',
            'interest' => 'Mantenimiento',
            'source_id' => $this->sourceId(),
            ...$data,
        ])->assertCreated()->json('id');
    }

    /** Califica el lead y devuelve el id de la oportunidad creada. */
    private function opportunityFrom(string $leadId, float $amount = 12000): string
    {
        return $this->postJson("/api/v1/crm/leads/{$leadId}/opportunity", ['amount' => $amount])->assertCreated()->json('id');
    }

    /** Oportunidad ganada, lista para convertir. */
    private function wonOpportunity(User $as, array $leadData = []): string
    {
        $opportunity = $this->opportunityFrom($this->lead($as, $leadData));
        $this->postJson("/api/v1/crm/opportunities/{$opportunity}/win", ['evidence' => 'Orden de compra OC-2291'])->assertOk();

        return $opportunity;
    }

    /** Lo que la base debe rechazar, sin dejar inutilizada la transacción de la prueba. */
    private function assertDatabaseRejects(callable $attempt): void
    {
        try {
            DB::transaction($attempt);
        } catch (Throwable) {
            $this->assertTrue(true);

            return;
        }
        $this->fail('La base aceptó una operación que debía rechazar.');
    }

    public function test_lead_is_created_and_follows_its_states(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));
        Sanctum::actingAs($seller);
        $source = $this->sourceId();

        // Hace falta la empresa o el contacto, y la fuente.
        $this->postJson('/api/v1/crm/leads', ['source_id' => $source])->assertStatus(422)->assertJsonValidationErrors('organization_name');
        $this->postJson('/api/v1/crm/leads', ['organization_name' => 'Agro SAC'])->assertStatus(422)->assertJsonValidationErrors('source_id');
        $this->postJson('/api/v1/crm/leads', ['organization_name' => 'Agro SAC', 'source_id' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors('source_id');

        $lead = $this->postJson('/api/v1/crm/leads', [
            'organization_name' => 'Agroindustrias del Sur SAC', 'person_name' => 'Rosa Quispe', 'position' => 'Gerente',
            'email' => 'rosa@agrosur.test', 'phone' => '987654321', 'interest' => 'Mantenimiento', 'source_id' => $source,
        ])->assertCreated()
            ->assertJsonPath('status', 'NEW')
            ->assertJsonPath('lead_number', 'LD-'.date('Y').'-0001')
            ->assertJsonPath('owner.name', 'Ana')
            ->assertJsonPath('source.name', 'Web')
            ->assertJsonPath('core_party', null)
            ->json();
        $url = "/api/v1/crm/leads/{$lead['id']}";

        // Un mismo correo no tiene dos leads abiertos.
        $this->postJson('/api/v1/crm/leads', ['person_name' => 'Rosa', 'email' => 'ROSA@agrosur.test', 'source_id' => $source])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        // Edición, y el correlativo sigue.
        $this->putJson($url, ['organization_name' => 'Agroindustrias del Sur SAC', 'person_name' => 'Rosa Quispe Mamani', 'email' => 'rosa@agrosur.test', 'interest' => 'Mantenimiento', 'source_id' => $source])
            ->assertOk()->assertJsonPath('person_name', 'Rosa Quispe Mamani')->assertJsonPath('position', null);
        $this->postJson('/api/v1/crm/leads', ['person_name' => 'Luis Paz', 'source_id' => $source])
            ->assertCreated()->assertJsonPath('lead_number', 'LD-'.date('Y').'-0002');

        // Nuevo → Contactado → Calificado; no se salta hacia atrás.
        $this->postJson("{$url}/contact")->assertOk()->assertJsonPath('status', 'CONTACTED');
        $this->postJson("{$url}/contact")->assertStatus(422);
        $this->postJson("{$url}/qualify")->assertOk()->assertJsonPath('status', 'QUALIFIED');

        // Descartar exige motivo.
        $this->postJson("{$url}/disqualify")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('QUALIFIED', DB::table('crm_leads')->where('id', $lead['id'])->value('status'));
        $view = $this->postJson("{$url}/disqualify", ['reason' => 'Sin presupuesto'])
            ->assertOk()->assertJsonPath('status', 'DISQUALIFIED')->assertJsonPath('discard_reason', 'Sin presupuesto')->json();

        // Cerrado: ya no se edita, ni se califica, ni genera oportunidad.
        $this->putJson($url, ['person_name' => 'Otro', 'source_id' => $source])->assertStatus(422);
        $this->postJson("{$url}/qualify")->assertStatus(422);
        $this->postJson("{$url}/opportunity", ['amount' => 100])->assertStatus(422);

        // El historial guarda cada paso con su estado y el motivo (en la prueba todo ocurre en el mismo segundo).
        $history = collect($view['history'])->keyBy('action');
        $this->assertEqualsCanonicalizing(
            ['crm_lead.disqualify', 'crm_lead.qualify', 'crm_lead.contact', 'crm_lead.update', 'crm_lead.create'],
            $history->keys()->all(),
        );
        $this->assertEquals(['from' => 'QUALIFIED', 'to' => 'DISQUALIFIED', 'note' => 'Sin presupuesto'], $history['crm_lead.disqualify']['metadata']);

        // Indicadores y filtros del listado.
        $this->getJson('/api/v1/crm/leads/summary')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('by_status.DISQUALIFIED', 1)->assertJsonPath('by_status.NEW', 1);
        $this->getJson('/api/v1/crm/leads?status=NEW')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.person_name', 'Luis Paz');
        $this->getJson('/api/v1/crm/leads?search=agroindustrias')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/crm/leads?sort=lead&dir=asc')->assertOk()->assertJsonPath('data.0.organization_name', 'Agroindustrias del Sur SAC');
        $filters = json_encode([['id' => 'interest', 'type' => 'text', 'operator' => 'contains', 'value' => 'manten']]);
        $this->getJson('/api/v1/crm/leads?filters='.urlencode($filters))->assertOk()->assertJsonPath('total', 1);
    }

    public function test_database_refuses_a_discarded_lead_without_reason(): void
    {
        $this->makeTenant();
        $lead = $this->lead($this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN')));

        $this->assertDatabaseRejects(fn () => DB::table('crm_leads')->where('id', $lead)->update(['status' => 'DISQUALIFIED']));
    }

    public function test_lead_of_a_known_company_is_linked_instead_of_duplicated(): void
    {
        $this->makeTenant();
        [$partyId] = $this->makeOrganization('Minera Los Andes SAC', '20123456789');
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));

        $lead = $this->lead($seller, ['organization_name' => 'minera los andes sac']);

        $this->getJson("/api/v1/crm/leads/{$lead}")->assertOk()
            ->assertJsonPath('party_id', $partyId)
            ->assertJsonPath('core_party.name', 'Minera Los Andes SAC');
        $this->getJson('/api/v1/crm/leads')->assertOk()->assertJsonPath('data.0.core_party_id', $partyId);

        // La oportunidad nace con ese cliente, y al ganarla se ofrece reutilizarlo.
        $opportunity = $this->opportunityFrom($lead);
        $this->postJson("/api/v1/crm/opportunities/{$opportunity}/win", ['evidence' => 'Correo de aceptación'])->assertOk()
            ->assertJsonPath('customer.party_id', $partyId)
            ->assertJsonPath('conversion_candidates.0.id', $partyId)
            ->assertJsonPath('conversion_candidates.0.is_customer', true);
    }

    public function test_qualifying_a_lead_creates_one_opportunity(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));
        $lead = $this->lead($seller);

        $this->postJson("/api/v1/crm/leads/{$lead}/opportunity", ['amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
        $opportunity = $this->postJson("/api/v1/crm/leads/{$lead}/opportunity", ['amount' => 12000])->assertCreated()
            ->assertJsonPath('opportunity_number', 'OP-'.date('Y').'-0001')
            ->assertJsonPath('lead.status', 'CONVERTED')
            ->assertJsonPath('lead.opportunity.opportunity_number', 'OP-'.date('Y').'-0001')
            ->json('id');

        // Nace abierta, en la primera etapa, con su probabilidad y a nombre de quien llevaba el lead.
        $this->getJson("/api/v1/crm/opportunities/{$opportunity}")->assertOk()
            ->assertJsonPath('status', 'OPEN')
            ->assertJsonPath('name', 'Mantenimiento — Agroindustrias del Sur SAC')
            ->assertJsonPath('stage.name', 'Prospección')
            ->assertJsonPath('owner.name', 'Ana')
            ->assertJsonPath('lead.id', $lead);
        $this->assertEquals(10, DB::table('crm_opportunities')->where('id', $opportunity)->value('probability'));

        // Una sola por lead: ni por la pantalla ni escribiendo en la base.
        $this->postJson("/api/v1/crm/leads/{$lead}/opportunity", ['amount' => 500])->assertStatus(422);
        $this->assertSame(1, DB::table('crm_opportunities')->where('lead_id', $lead)->count());
        $existing = (array) DB::table('crm_opportunities')->where('id', $opportunity)->first();
        $this->assertDatabaseRejects(fn () => DB::table('crm_opportunities')->insert([
            ...$existing, 'id' => (string) Str::uuid(), 'opportunity_number' => 'OP-TEST-0002',
        ]));
    }

    public function test_opportunity_moves_through_the_pipeline_and_closes_with_evidence_or_reason(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));
        Sanctum::actingAs($seller);
        [, $customerId] = $this->makeOrganization('Minera Los Andes SAC', '20123456789');
        $stages = collect($this->getJson('/api/v1/crm/opportunities/options')->assertOk()->json('stages'));
        $this->assertSame(['Prospección', 'Calificación', 'Propuesta', 'Negociación'], $stages->pluck('name')->all());

        $this->postJson('/api/v1/crm/opportunities', ['amount' => 100])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/crm/opportunities', ['name' => 'Renovación', 'amount' => -1])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/crm/opportunities', ['name' => 'Renovación', 'amount' => 100, 'customer_id' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');

        $won = $this->postJson('/api/v1/crm/opportunities', ['name' => 'Renovación anual', 'amount' => 8000, 'customer_id' => $customerId])
            ->assertCreated()->assertJsonPath('status', 'OPEN')->assertJsonPath('source.name', 'Cliente existente')->json('id');
        $lost = $this->postJson('/api/v1/crm/opportunities', ['name' => 'Ampliación', 'amount' => 2000])->assertCreated()->json('id');

        // Cambiar de etapa cambia la probabilidad; una etapa que no existe se rechaza.
        $proposal = $stages->firstWhere('name', 'Propuesta');
        $this->postJson("/api/v1/crm/opportunities/{$won}/stage", ['stage_id' => $proposal['id']])->assertOk()->assertJsonPath('stage.name', 'Propuesta');
        $this->assertEquals(50, DB::table('crm_opportunities')->where('id', $won)->value('probability'));
        $this->postJson("/api/v1/crm/opportunities/{$won}/stage", ['stage_id' => (string) Str::uuid()])->assertStatus(422);

        $this->putJson("/api/v1/crm/opportunities/{$won}", ['name' => 'Renovación anual 2027', 'amount' => 9000, 'customer_id' => $customerId])
            ->assertOk()->assertJsonPath('name', 'Renovación anual 2027');

        // Indicadores: abierto 9 000 + 2 000; ponderado 9 000 × 50 % + 2 000 × 10 %.
        $this->getJson('/api/v1/crm/opportunities/summary')->assertOk()
            ->assertJsonPath('open.count', 2)->assertJsonPath('close_rate', null);
        $this->assertEquals(11000, $this->getJson('/api/v1/crm/opportunities/summary')->json('open.amount'));
        $this->assertEquals(4700, $this->getJson('/api/v1/crm/opportunities/summary')->json('open.weighted'));

        // Ganar exige evidencia y perder exige motivo.
        $this->postJson("/api/v1/crm/opportunities/{$won}/win")->assertStatus(422)->assertJsonValidationErrors('evidence');
        $this->postJson("/api/v1/crm/opportunities/{$lost}/lose", ['reason' => ' '])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(2, DB::table('crm_opportunities')->where('tenant_id', $this->tenantId)->where('status', 'OPEN')->count());

        $this->postJson("/api/v1/crm/opportunities/{$won}/win", ['evidence' => 'Orden de compra OC-2291'])->assertOk()
            ->assertJsonPath('status', 'WON')->assertJsonPath('won_evidence', 'Orden de compra OC-2291');
        $this->postJson("/api/v1/crm/opportunities/{$lost}/lose", ['reason' => 'Precio'])->assertOk()
            ->assertJsonPath('status', 'LOST')->assertJsonPath('loss_reason', 'Precio');
        $this->assertEquals(100, DB::table('crm_opportunities')->where('id', $won)->value('probability'));
        $this->assertEquals(0, DB::table('crm_opportunities')->where('id', $lost)->value('probability'));

        // Cerrada: no cambia de etapa, no se edita, no se vuelve a cerrar.
        $this->postJson("/api/v1/crm/opportunities/{$won}/stage", ['stage_id' => $stages->first()['id']])->assertStatus(422);
        $this->putJson("/api/v1/crm/opportunities/{$won}", ['name' => 'Otro', 'amount' => 1])->assertStatus(422);
        $this->postJson("/api/v1/crm/opportunities/{$lost}/win", ['evidence' => 'x'])->assertStatus(422);

        $this->getJson('/api/v1/crm/opportunities/summary')->assertOk()
            ->assertJsonPath('open.count', 0)->assertJsonPath('won.count', 1)->assertJsonPath('close_rate', 50);
        $this->getJson('/api/v1/crm/opportunities?status=WON')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.converted', false);
        $this->getJson('/api/v1/crm/opportunities?search=minera')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/crm/opportunities?sort=amount&dir=desc')->assertOk()->assertJsonPath('data.0.name', 'Renovación anual 2027');
    }

    public function test_database_refuses_a_closed_opportunity_without_evidence_or_reason(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));
        $opportunity = $this->opportunityFrom($this->lead($seller));

        $this->assertDatabaseRejects(fn () => DB::table('crm_opportunities')->where('id', $opportunity)->update(['status' => 'WON']));
        $this->assertDatabaseRejects(fn () => DB::table('crm_opportunities')->where('id', $opportunity)->update(['status' => 'LOST']));
    }

    public function test_won_opportunity_becomes_a_new_customer_once(): void
    {
        $this->makeTenant();
        $manager = $this->makeUser('Gema', $this->makeRole('GERENCIA', self::MANAGEMENT, 'TENANT'));
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));
        $open = $this->opportunityFrom($this->lead($seller, ['email' => 'otra@agrosur.test', 'organization_name' => 'Otra SAC']));
        $opportunity = $this->wonOpportunity($seller);
        $lead = DB::table('crm_opportunities')->where('id', $opportunity)->value('lead_id');
        $url = "/api/v1/crm/opportunities/{$opportunity}/convert";

        Sanctum::actingAs($manager);
        // Solo las ganadas se convierten.
        $this->postJson("/api/v1/crm/opportunities/{$open}/convert", ['mode' => 'CREATE', 'legal_name' => 'Otra SAC', 'tax_identifier' => '20111111111'])
            ->assertStatus(422);
        // Crear pide razón social y un RUC válido.
        $this->postJson($url, ['mode' => 'CREATE', 'legal_name' => 'Agroindustrias del Sur SAC', 'tax_identifier' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors('tax_identifier');
        $this->assertSame(0, DB::table('parties')->where('tenant_id', $this->tenantId)->count());

        $view = $this->postJson($url, ['mode' => 'CREATE', 'legal_name' => 'Agroindustrias del Sur SAC', 'tax_identifier' => '20555555551', 'sector' => 'Agro'])
            ->assertOk()
            ->assertJsonPath('conversion.mode', 'CREATE')
            ->assertJsonPath('conversion.party_name', 'Agroindustrias del Sur SAC')
            ->assertJsonPath('conversion_candidates', [])
            ->json();

        // Nació la organización con rol Cliente y la persona de contacto del lead, enlazada a ella.
        $partyId = $view['conversion']['party_id'];
        $organization = DB::table('organizations')->where('party_id', $partyId)->first();
        $this->assertSame('20555555551', $organization->tax_identifier);
        $customerId = DB::table('customers')->where('party_id', $partyId)->value('id');
        $this->assertNotNull($customerId);
        $this->assertSame($customerId, $view['customer_id']);
        $contact = DB::table('persons')->join('parties', 'parties.id', '=', 'persons.party_id')
            ->where('parties.tenant_id', $this->tenantId)->first();
        $this->assertSame(['Rosa', 'Quispe Mamani'], [$contact->given_names, $contact->family_name]);
        $this->assertSame($partyId, json_decode($contact->metadata, true)['contact_of']);

        // El lead queda enlazado a la identidad, y la conversión, registrada con quién y cómo.
        $this->assertSame($partyId, DB::table('crm_leads')->where('id', $lead)->value('party_id'));
        $conversion = DB::table('crm_conversions')->where('opportunity_id', $opportunity)->first();
        $this->assertSame([$lead, $customerId, $partyId, 'CREATE', $manager->id], [
            $conversion->lead_id, $conversion->customer_id, $conversion->party_id, $conversion->mode, $conversion->converted_by,
        ]);
        $this->assertContains('crm_opportunity.convert', array_column($view['history'], 'action'));
        $this->assertTrue(DB::table('audit_logs')->where('entity_id', $partyId)->where('action', 'party.create')->exists());

        // Repetir la conversión no crea un segundo cliente.
        $this->postJson($url, ['mode' => 'CREATE', 'legal_name' => 'Agroindustrias del Sur SAC', 'tax_identifier' => '20555555552'])->assertStatus(422);
        $this->postJson($url, ['mode' => 'REUSE', 'party_id' => $partyId])->assertStatus(422);
        $this->assertSame(1, DB::table('crm_conversions')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(1, DB::table('organizations')->join('parties', 'parties.id', '=', 'organizations.party_id')->where('parties.tenant_id', $this->tenantId)->count());

        // El registro de la conversión no se corrige ni se borra desde la aplicación.
        $this->assertDatabaseRejects(fn () => DB::table('crm_conversions')->where('id', $conversion->id)->update(['mode' => 'REUSE']));
        $this->assertDatabaseRejects(fn () => DB::table('crm_conversions')->where('id', $conversion->id)->delete());
    }

    public function test_conversion_reuses_the_identity_that_already_exists(): void
    {
        $this->makeTenant();
        $manager = $this->makeUser('Gema', $this->makeRole('GERENCIA', self::MANAGEMENT, 'TENANT'));
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));
        // Ya está registrada como proveedor, con otro nombre: el lead no la encuentra por nombre.
        [$partyId] = $this->makeOrganization('Agrosur Perú SAC', '20555555551', false);
        $opportunity = $this->wonOpportunity($seller);
        $url = "/api/v1/crm/opportunities/{$opportunity}/convert";

        Sanctum::actingAs($manager);
        // Crear con un RUC que ya existe: no duplica, devuelve la coincidencia para reutilizarla.
        $this->postJson($url, ['mode' => 'CREATE', 'legal_name' => 'Agroindustrias del Sur SAC', 'tax_identifier' => '20555555551'])
            ->assertStatus(409)->assertJsonPath('duplicates.0.id', $partyId);
        $this->assertSame(1, DB::table('parties')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(0, DB::table('crm_conversions')->where('tenant_id', $this->tenantId)->count());

        $this->postJson($url, ['mode' => 'REUSE'])->assertStatus(422)->assertJsonValidationErrors('party_id');
        $this->postJson($url, ['mode' => 'REUSE', 'party_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('party_id');

        // Reutilizar le agrega el rol Cliente a la misma identidad.
        $this->postJson($url, ['mode' => 'REUSE', 'party_id' => $partyId])->assertOk()
            ->assertJsonPath('conversion.mode', 'REUSE')->assertJsonPath('conversion.party_id', $partyId);
        $this->assertSame(1, DB::table('parties')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(1, DB::table('customers')->where('party_id', $partyId)->count());
        $this->assertTrue(DB::table('audit_logs')->where('entity_id', $partyId)->where('action', 'party.role.add')->exists());
    }

    public function test_creating_identities_from_the_crm_asks_for_the_same_permission_as_their_screen(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));
        [$supplierParty] = $this->makeOrganization('Proveedora SAC', '20444444441', false);
        [$customerParty, $customerId] = $this->makeOrganization('Minera Los Andes SAC', '20123456789');
        $opportunity = $this->wonOpportunity($seller);
        $url = "/api/v1/crm/opportunities/{$opportunity}/convert";

        // Sin core.manage no da de alta una organización ni le agrega un rol a otra.
        $this->postJson($url, ['mode' => 'CREATE', 'legal_name' => 'Agroindustrias del Sur SAC', 'tax_identifier' => '20555555551'])
            ->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->postJson($url, ['mode' => 'REUSE', 'party_id' => $supplierParty])->assertStatus(403);
        $this->assertSame(2, DB::table('parties')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(0, DB::table('customers')->where('party_id', $supplierParty)->count());
        $this->assertSame(0, DB::table('crm_conversions')->where('tenant_id', $this->tenantId)->count());

        // Sí puede convertir con un cliente que ya existe.
        $this->postJson($url, ['mode' => 'REUSE', 'party_id' => $customerParty])->assertOk()->assertJsonPath('customer_id', $customerId);
    }

    public function test_sales_only_reaches_its_own_leads_and_opportunities(): void
    {
        $this->makeTenant();
        $sales = $this->makeRole('VENTAS', self::SALES, 'OWN');
        $ana = $this->makeUser('Ana', $sales);
        $beto = $this->makeUser('Beto', $sales);
        $manager = $this->makeUser('Gema', $this->makeRole('GERENCIA', self::MANAGEMENT, 'TENANT'));
        $unscoped = $this->makeUser('Nuevo', $this->makeRole('NUEVO', self::SALES, null));

        $ofAna = $this->lead($ana, ['email' => 'ana@cliente.test']);
        $ofBeto = $this->lead($beto, ['email' => 'beto@cliente.test', 'organization_name' => 'Cliente de Beto SAC']);
        $betoOpportunity = $this->opportunityFrom($this->lead($beto, ['email' => 'beto2@cliente.test']));

        // Cada quien ve lo suyo, también en indicadores y filtros.
        Sanctum::actingAs($ana);
        $this->assertSame([$ofAna], array_column($this->getJson('/api/v1/crm/leads')->assertOk()->json('data'), 'id'));
        $this->getJson('/api/v1/crm/leads/summary')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame(['Ana'], array_column($this->getJson('/api/v1/crm/leads/options')->json('owners'), 'name'));
        $this->getJson('/api/v1/crm/opportunities')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/crm/opportunities/summary')->assertOk()->assertJsonPath('open.count', 0);

        // Lo ajeno no existe: ni su ficha ni sus acciones, y no cambia nada.
        $this->getJson("/api/v1/crm/leads/{$ofBeto}")->assertStatus(404);
        $this->postJson("/api/v1/crm/leads/{$ofBeto}/contact")->assertStatus(404);
        $this->postJson("/api/v1/crm/leads/{$ofBeto}/disqualify", ['reason' => 'x'])->assertStatus(404);
        $this->postJson("/api/v1/crm/leads/{$ofBeto}/opportunity", ['amount' => 100])->assertStatus(404);
        $this->postJson("/api/v1/crm/leads/{$ofBeto}/contact", [], ['If-Match' => '99'])->assertStatus(404);
        $this->getJson("/api/v1/crm/opportunities/{$betoOpportunity}")->assertStatus(404);
        $this->postJson("/api/v1/crm/opportunities/{$betoOpportunity}/win", ['evidence' => 'x'])->assertStatus(404);
        $this->postJson("/api/v1/crm/opportunities/{$betoOpportunity}/lose", ['reason' => 'x'])->assertStatus(404);
        $this->assertSame('NEW', DB::table('crm_leads')->where('id', $ofBeto)->value('status'));
        $this->assertSame('OPEN', DB::table('crm_opportunities')->where('id', $betoOpportunity)->value('status'));

        // Aun así, no puede abrir un segundo lead con el correo de un lead ajeno.
        $this->postJson('/api/v1/crm/leads', ['person_name' => 'Copia', 'email' => 'beto@cliente.test', 'source_id' => $this->sourceId()])->assertStatus(422);

        // Un rol sin alcance definido alcanza solo lo propio.
        Sanctum::actingAs($unscoped);
        $this->getJson('/api/v1/crm/leads')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/crm/leads/{$ofAna}")->assertStatus(404);

        // Gerencia ve todo. La oportunidad que crea desde el lead de Ana sigue siendo de Ana.
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/crm/leads')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/v1/crm/opportunities')->assertOk()->assertJsonPath('total', 1);
        $anaOpportunity = $this->opportunityFrom($ofAna);

        Sanctum::actingAs($ana);
        $this->getJson("/api/v1/crm/opportunities/{$anaOpportunity}")->assertOk()->assertJsonPath('owner.name', 'Ana');
        Sanctum::actingAs($beto);
        $this->getJson("/api/v1/crm/opportunities/{$anaOpportunity}")->assertStatus(404);

        // La vista del cliente tampoco muestra los leads ajenos.
        [$partyId] = $this->makeOrganization('Cliente de Beto SAC', '20333333331');
        DB::table('crm_leads')->where('id', $ofBeto)->update(['party_id' => $partyId]);
        Sanctum::actingAs($ana);
        $this->assertSame([], $this->getJson("/api/v1/parties/{$partyId}")->assertOk()->json('leads'));
        Sanctum::actingAs($beto);
        $this->assertSame([$ofBeto], array_column($this->getJson("/api/v1/parties/{$partyId}")->assertOk()->json('leads'), 'id'));
    }

    public function test_team_and_branch_scopes_reach_the_leads_of_the_team_and_the_branch(): void
    {
        $this->makeTenant();
        DB::table('companies')->insert([
            'id' => $companyId = (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'code' => 'PRINCIPAL', 'legal_name' => 'Empresa de Prueba SAC',
        ]);
        DB::table('branches')->insert(['id' => $north = (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'company_id' => $companyId, 'code' => 'NORTE', 'name' => 'Norte']);
        DB::table('branches')->insert(['id' => $south = (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'company_id' => $companyId, 'code' => 'SUR', 'name' => 'Sur']);
        DB::table('teams')->insert(['id' => $teamId = (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'name' => 'Equipo A']);
        $place = fn (User $user, ?string $branchId, ?string $team) => DB::table('tenant_users')
            ->where('tenant_id', $this->tenantId)->where('user_id', $user->id)->update(['default_branch_id' => $branchId, 'team_id' => $team]);

        $byTeam = $this->makeRole('EQUIPO', self::SALES, 'TEAM');
        $byBranch = $this->makeRole('SEDE', self::SALES, 'BRANCH');
        $ana = $this->makeUser('Ana', $byTeam);
        $beto = $this->makeUser('Beto', $byTeam);
        $carla = $this->makeUser('Carla', $byBranch);
        $dario = $this->makeUser('Darío', $byBranch);
        $place($ana, $north, $teamId);
        $place($beto, $south, $teamId);
        $place($carla, $north, null);
        $place($dario, $south, null);

        $ofAna = $this->lead($ana, ['email' => 'ana@cliente.test']);
        $ofBeto = $this->lead($beto, ['email' => 'beto@cliente.test']);
        $ofDario = $this->lead($dario, ['email' => 'dario@cliente.test']);
        // El lead nace en la sede de quien lo crea, y su oportunidad la hereda.
        $this->assertSame($north, DB::table('crm_leads')->where('id', $ofAna)->value('branch_id'));

        // Equipo: lo propio y lo de su equipo, sea de la sede que sea; no lo de fuera del equipo.
        Sanctum::actingAs($ana);
        $this->assertEqualsCanonicalizing([$ofAna, $ofBeto], array_column($this->getJson('/api/v1/crm/leads')->json('data'), 'id'));
        $this->postJson("/api/v1/crm/leads/{$ofBeto}/contact")->assertOk();
        $this->getJson("/api/v1/crm/leads/{$ofDario}")->assertStatus(404);
        $betoOpportunity = $this->opportunityFrom($ofBeto);
        $this->assertSame($south, DB::table('crm_opportunities')->where('id', $betoOpportunity)->value('branch_id'));

        // Sede: lo propio y lo de su sede.
        Sanctum::actingAs($carla);
        $this->assertSame([$ofAna], array_column($this->getJson('/api/v1/crm/leads')->json('data'), 'id'));
        $this->getJson("/api/v1/crm/opportunities/{$betoOpportunity}")->assertStatus(404);
        Sanctum::actingAs($dario);
        $this->assertEqualsCanonicalizing([$ofBeto, $ofDario], array_column($this->getJson('/api/v1/crm/leads')->json('data'), 'id'));
        $this->getJson("/api/v1/crm/opportunities/{$betoOpportunity}")->assertOk();
    }

    public function test_each_action_asks_for_its_permission_and_the_module(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));
        $lead = $this->lead($seller);
        $opportunity = $this->opportunityFrom($this->lead($seller, ['email' => 'otro@agrosur.test']));

        // Solo ver: no crea, no cambia estados, no genera oportunidades.
        $viewer = $this->makeUser('Lectora', $this->makeRole('LECTOR', ['leads.view', 'opportunities.view'], 'TENANT'));
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/crm/leads')->assertOk()->assertJsonPath('total', 2);
        $this->getJson("/api/v1/crm/opportunities/{$opportunity}")->assertOk();
        $this->postJson('/api/v1/crm/leads', ['person_name' => 'X', 'source_id' => (string) Str::uuid()])->assertStatus(403);
        $this->postJson("/api/v1/crm/leads/{$lead}/contact")->assertStatus(403);
        $this->postJson("/api/v1/crm/leads/{$lead}/opportunity", ['amount' => 100])->assertStatus(403);
        $this->postJson('/api/v1/crm/opportunities', ['name' => 'X', 'amount' => 1])->assertStatus(403);
        $this->postJson("/api/v1/crm/opportunities/{$opportunity}/win", ['evidence' => 'x'])->assertStatus(403);

        // Gestionar leads no alcanza para crear la oportunidad: es el permiso de oportunidades.
        Sanctum::actingAs($this->makeUser('Prospectora', $this->makeRole('PROSPECCION', ['leads.view', 'leads.manage'], 'TENANT')));
        $this->postJson("/api/v1/crm/leads/{$lead}/contact")->assertOk();
        $this->postJson("/api/v1/crm/leads/{$lead}/opportunity", ['amount' => 100])->assertStatus(403);
        $this->getJson('/api/v1/crm/opportunities')->assertStatus(403);

        // Sin ningún permiso del CRM.
        Sanctum::actingAs($this->makeUser('Ajena', $this->makeRole('OTRO', ['core.view'], 'TENANT')));
        $this->getJson('/api/v1/crm/leads')->assertStatus(403);
        $this->getJson("/api/v1/crm/leads/{$lead}")->assertStatus(403);

        // La ruta que convertía un lead directo en cliente ya no existe.
        Sanctum::actingAs($seller);
        $this->assertContains($this->postJson("/api/v1/crm/leads/{$lead}/convert")->getStatusCode(), [404, 405]);
        $this->assertSame(0, DB::table('customers')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_crm_is_closed_when_the_company_does_not_have_the_module(): void
    {
        $this->makeTenant(['COTIZACIONES']);
        Sanctum::actingAs($this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'TENANT')));

        $this->getJson('/api/v1/crm/leads')->assertStatus(403);
        $this->getJson('/api/v1/crm/opportunities')->assertStatus(403);
        $this->postJson('/api/v1/crm/opportunities', ['name' => 'X', 'amount' => 1])->assertStatus(403);
        // Y nada fuera del CRM depende de él.
        $this->getJson('/api/v1/quotations')->assertOk();
    }

    public function test_leads_and_opportunities_of_another_company_do_not_exist(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'TENANT'));
        $lead = $this->lead($seller);
        $opportunity = $this->opportunityFrom($this->lead($seller, ['email' => 'otro@agrosur.test']));

        $this->makeTenant();
        Sanctum::actingAs($this->makeUser('Intrusa', $this->makeRole('VENTAS', self::SALES, 'TENANT')));

        $this->getJson('/api/v1/crm/leads')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/crm/leads/{$lead}")->assertStatus(404);
        $this->postJson("/api/v1/crm/leads/{$lead}/contact")->assertStatus(404);
        $this->getJson("/api/v1/crm/opportunities/{$opportunity}")->assertStatus(404);
        $this->postJson("/api/v1/crm/opportunities/{$opportunity}/lose", ['reason' => 'x'])->assertStatus(404);
        // El correlativo es de cada empresa.
        $this->postJson('/api/v1/crm/leads', ['person_name' => 'Primera', 'source_id' => $this->sourceId()])
            ->assertCreated()->assertJsonPath('lead_number', 'LD-'.date('Y').'-0001');
    }

    public function test_simultaneous_edits_do_not_overwrite_each_other(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));
        $lead = $this->lead($seller);
        $opportunity = $this->opportunityFrom($this->lead($seller, ['email' => 'otro@agrosur.test']));

        $this->postJson("/api/v1/crm/leads/{$lead}/contact", [], ['If-Match' => '0'])->assertOk()->assertJsonPath('lock_version', 1);
        // Otra pestaña que seguía con la versión anterior.
        $this->postJson("/api/v1/crm/leads/{$lead}/disqualify", ['reason' => 'x'], ['If-Match' => '0'])
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
        $this->assertSame('CONTACTED', DB::table('crm_leads')->where('id', $lead)->value('status'));

        $this->postJson("/api/v1/crm/opportunities/{$opportunity}/lose", ['reason' => 'Precio'], ['If-Match' => '0'])->assertOk();
        $this->postJson("/api/v1/crm/opportunities/{$opportunity}/win", ['evidence' => 'OC'], ['If-Match' => '0'])->assertStatus(409);
        $this->assertSame('LOST', DB::table('crm_opportunities')->where('id', $opportunity)->value('status'));
    }

    public function test_quotation_is_issued_from_the_opportunity(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Ana', $this->makeRole('VENTAS', self::SALES, 'OWN'));
        $colleague = $this->makeUser('Beto', $this->makeRole('VENTAS2', self::SALES, 'OWN'));
        Sanctum::actingAs($seller);
        [, $customerId] = $this->makeOrganization('Minera Los Andes SAC', '20123456789');
        [, $otherCustomerId] = $this->makeOrganization('Otra SAC', '20999999991');
        $items = [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 100]];

        $opportunity = $this->postJson('/api/v1/crm/opportunities', ['name' => 'Renovación', 'amount' => 8000, 'customer_id' => $customerId])->assertCreated()->json('id');
        $unlinked = $this->postJson('/api/v1/crm/opportunities', ['name' => 'Sin cliente', 'amount' => 100])->assertCreated()->json('id');

        // La cotización va al cliente de la oportunidad.
        $this->postJson('/api/v1/quotations', ['customer_id' => $otherCustomerId, 'opportunity_id' => $opportunity, 'items' => $items])
            ->assertStatus(422)->assertJsonValidationErrors('opportunity_id');
        $this->postJson('/api/v1/quotations', ['customer_id' => $customerId, 'opportunity_id' => $unlinked, 'items' => $items])
            ->assertStatus(422)->assertJsonValidationErrors('opportunity_id');

        $quotation = $this->postJson('/api/v1/quotations', ['customer_id' => $customerId, 'opportunity_id' => $opportunity, 'items' => $items])
            ->assertCreated()->json('id');
        $this->getJson("/api/v1/quotations/{$quotation}")->assertOk()
            ->assertJsonPath('source_type', 'OPPORTUNITY')->assertJsonPath('source_opportunity.id', $opportunity);
        $this->getJson("/api/v1/crm/opportunities/{$opportunity}")->assertOk()->assertJsonPath('quotations.0.id', $quotation);
        $this->getJson('/api/v1/crm/opportunities?search=Renovación')->assertOk()->assertJsonPath('data.0.quotations_count', 1);

        // Con cotizaciones emitidas, el cliente de la oportunidad ya no se cambia.
        $this->putJson("/api/v1/crm/opportunities/{$opportunity}", ['name' => 'Renovación', 'amount' => 8000, 'customer_id' => $otherCustomerId])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');

        // Otra persona no cotiza desde una oportunidad que no alcanza.
        Sanctum::actingAs($colleague);
        $this->postJson('/api/v1/quotations', ['customer_id' => $customerId, 'opportunity_id' => $opportunity, 'items' => $items])->assertStatus(422);

        // Ni se cotiza desde una perdida.
        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/crm/opportunities/{$opportunity}/lose", ['reason' => 'Precio'])->assertOk();
        $this->postJson('/api/v1/quotations', ['customer_id' => $customerId, 'opportunity_id' => $opportunity, 'items' => $items])->assertStatus(422);
    }

    public function test_roles_matrix_offers_the_crm_permissions_and_scopes(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', $this->makeRole('ADMIN', [...self::MANAGEMENT, 'leads.manage', 'users.view', 'users.manage'], null));
        $sales = $this->makeRole('VENTAS', self::SALES, 'OWN');
        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/v1/roles')->assertOk()->json();
        $codes = collect($list['permissions'])->pluck('code');
        foreach (['leads.view', 'leads.manage', 'opportunities.view', 'opportunities.manage'] as $code) {
            $this->assertTrue($codes->contains($code), $code);
        }
        $this->assertFalse($codes->contains('crm.view'));
        $this->assertSame(['leads', 'opportunities'], array_values(array_intersect(array_column($list['scope_resources'], 'code'), ['leads', 'opportunities'])));
        $this->assertSame('OWN', collect($list['roles'])->firstWhere('id', $sales)['scopes']['leads']);

        // Ampliar el alcance surte efecto de inmediato.
        $seller = $this->makeUser('Ana', $sales);
        $colleague = $this->makeUser('Beto', $sales);
        $ofColleague = $this->lead($colleague);
        Sanctum::actingAs($seller);
        $this->getJson("/api/v1/crm/leads/{$ofColleague}")->assertStatus(404);

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/roles/{$sales}/permissions", ['permissions' => self::SALES, 'scopes' => ['leads' => 'TENANT']])->assertOk();
        Sanctum::actingAs($seller);
        $this->getJson("/api/v1/crm/leads/{$ofColleague}")->assertOk();
    }
}
