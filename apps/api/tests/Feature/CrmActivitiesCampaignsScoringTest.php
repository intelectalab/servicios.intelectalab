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
 * Resto del CRM: Actividades (con alcance), Campañas (miembros con consentimiento, resultados
 * dentro del alcance) y Puntuación automática de leads.
 */
class CrmActivitiesCampaignsScoringTest extends TestCase
{
    use DatabaseTransactions;

    private const SALES = [
        'leads.view', 'leads.manage', 'opportunities.view', 'opportunities.manage', 'activities.view', 'activities.manage', 'campaigns.view',
    ];

    private const MANAGEMENT = [
        'leads.view', 'opportunities.view', 'opportunities.manage', 'activities.view', 'campaigns.view', 'campaigns.export',
    ];

    private const ADMIN = [
        'leads.view', 'leads.manage', 'leads.export', 'opportunities.view', 'opportunities.manage',
        'activities.view', 'activities.manage', 'activities.export', 'campaigns.view', 'campaigns.manage', 'campaigns.export', 'scoring.manage',
    ];

    private string $tenantId;

    private function makeTenant(array $modules = ['CRM']): string
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

        return $this->tenantId;
    }

    /** Usuario con un rol propio, esos permisos y ese alcance en el CRM (null = sin definir: lo propio). */
    private function makeUser(string $name, array $permissions, ?string $scope = 'TENANT'): User
    {
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId, 'tenant_id' => $this->tenantId, 'code' => Str::upper(Str::random(8)), 'name' => $name,
            'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        foreach ($scope ? ['leads', 'opportunities', 'activities'] : [] as $resource) {
            DB::table('role_scopes')->insert(['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'resource' => $resource, 'scope' => $scope]);
        }

        $user = User::create([
            'email' => 'crm-'.Str::random(8).'@erp-saas.test', 'password_hash' => bcrypt('Actual123'), 'name' => $name, 'status' => 'ACTIVE',
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

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function sourceId(string $name = 'Web'): string
    {
        return collect($this->getJson('/api/v1/crm/leads/options')->assertOk()->json('sources'))->firstWhere('name', $name)['id'];
    }

    private function lead(User $as, array $data = []): string
    {
        $this->as($as);

        return $this->postJson('/api/v1/crm/leads', [
            'organization_name' => 'Agroindustrias del Sur SAC',
            'person_name' => 'Rosa Quispe Mamani',
            'position' => 'Asistente',
            'email' => 'rosa-'.Str::random(6).'@agrosur.test',
            'source_id' => $this->sourceId($data['source'] ?? 'LinkedIn Ads'),
            ...array_diff_key($data, ['source' => 1]),
        ])->assertCreated()->json('id');
    }

    private function opportunityFrom(string $leadId, float $amount = 12000): string
    {
        return $this->postJson("/api/v1/crm/leads/{$leadId}/opportunity", ['amount' => $amount])->assertCreated()->json('id');
    }

    private function activity(User $as, array $data = []): string
    {
        $this->as($as);

        return $this->postJson('/api/v1/crm/activities', [
            'subject' => 'Llamar para agendar visita', 'activity_type' => 'CALL', 'due_at' => now()->addDay()->toIso8601String(), ...$data,
        ])->assertCreated()->json('id');
    }

    private function campaign(User $as, array $data = []): string
    {
        $this->as($as);

        return $this->postJson('/api/v1/crm/campaigns', ['name' => 'Feria industrial 2026', 'channel' => 'Evento', 'budget' => 5000, ...$data])
            ->assertCreated()->json('id');
    }

    private function assertDatabaseRejects(callable $attempt): void
    {
        try {
            DB::transaction($attempt);
            $this->fail('La base aceptó un dato que debía rechazar.');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }
    }

    // ------------------------------------------------------------------ Actividades

    public function test_activity_is_registered_edited_completed_and_reopened(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Vendedora', self::SALES, 'OWN');
        $leadId = $this->lead($seller);
        $opportunityId = $this->opportunityFrom($this->lead($seller));

        // Datos obligatorios y tipo conocido.
        $this->postJson('/api/v1/crm/activities', [])->assertStatus(422)->assertJsonValidationErrors(['subject', 'activity_type', 'due_at']);
        $this->postJson('/api/v1/crm/activities', ['subject' => 'x', 'activity_type' => 'FAX', 'due_at' => now()->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('errors.activity_type.0', 'El tipo de actividad no existe.');
        // A un lead o a una oportunidad, no a los dos; y tienen que existir.
        $this->postJson('/api/v1/crm/activities', ['subject' => 'x', 'activity_type' => 'CALL', 'due_at' => now()->toIso8601String(), 'lead_id' => $leadId, 'opportunity_id' => $opportunityId])
            ->assertStatus(422)->assertJsonValidationErrors(['opportunity_id']);
        $this->postJson('/api/v1/crm/activities', ['subject' => 'x', 'activity_type' => 'CALL', 'due_at' => now()->toIso8601String(), 'opportunity_id' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('errors.opportunity_id.0', 'La oportunidad indicada no existe.');

        $due = now()->addDays(2)->startOfHour();
        $created = $this->postJson('/api/v1/crm/activities', [
            'subject' => '  Demo del servicio  ', 'activity_type' => 'DEMO', 'due_at' => $due->toIso8601String(),
            'description' => 'Llevar equipo', 'opportunity_id' => $opportunityId,
        ])->assertCreated()
            ->assertJsonPath('subject', 'Demo del servicio')
            ->assertJsonPath('done', false)
            ->assertJsonPath('related.type', 'opportunity')
            ->assertJsonPath('related.id', $opportunityId)
            ->assertJsonPath('owner.id', $seller->id);
        $id = $created->json('id');
        $this->assertTrue($due->equalTo($created->json('due_at')));

        // Editar mientras está pendiente.
        $this->putJson("/api/v1/crm/activities/{$id}", ['subject' => 'Demo en planta', 'activity_type' => 'VISIT', 'due_at' => $due->toIso8601String(), 'lead_id' => $leadId])
            ->assertOk()->assertJsonPath('subject', 'Demo en planta')->assertJsonPath('activity_type', 'VISIT')
            ->assertJsonPath('related.type', 'lead')->assertJsonPath('related.id', $leadId)->assertJsonPath('opportunity_id', null);

        // Realizada: queda con su fecha, y ya no se edita hasta reabrirla.
        $this->postJson("/api/v1/crm/activities/{$id}/toggle")->assertOk()->assertJsonPath('done', true);
        $this->assertSame($seller->id, DB::table('crm_activities')->where('id', $id)->value('completed_by'));
        $this->putJson("/api/v1/crm/activities/{$id}", ['subject' => 'Otra', 'activity_type' => 'CALL', 'due_at' => $due->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('errors.status.0', 'La actividad ya está realizada. Reábrela para editarla.');
        $this->postJson("/api/v1/crm/activities/{$id}/toggle")->assertOk()->assertJsonPath('done', false)->assertJsonPath('completed_at', null);

        // Todo queda en auditoría.
        $this->assertEqualsCanonicalizing(
            ['crm_activity.create', 'crm_activity.update', 'crm_activity.complete', 'crm_activity.reopen'],
            DB::table('audit_logs')->where('entity_type', 'CrmActivity')->where('entity_id', $id)->pluck('action')->all(),
        );
    }

    public function test_database_refuses_an_activity_of_unknown_type_or_with_two_parents(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Vendedora', self::SALES);
        $leadId = $this->lead($seller);
        $opportunityId = $this->opportunityFrom($this->lead($seller));
        $row = fn (array $extra) => [
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'activity_type' => 'CALL', 'subject' => 'x', ...$extra,
        ];

        $this->assertDatabaseRejects(fn () => DB::table('crm_activities')->insert($row(['activity_type' => 'FAX'])));
        $this->assertDatabaseRejects(fn () => DB::table('crm_activities')->insert($row(['lead_id' => $leadId, 'opportunity_id' => $opportunityId])));
    }

    public function test_activities_list_searches_filters_sorts_and_counts(): void
    {
        $this->makeTenant();
        $seller = $this->makeUser('Vendedora', self::SALES);
        $opportunityId = $this->opportunityFrom($this->lead($seller, ['organization_name' => 'Minera Andina SAC']));
        $today = now()->startOfDay();

        $late = $this->activity($seller, ['subject' => 'Enviar propuesta', 'activity_type' => 'EMAIL', 'due_at' => $today->copy()->subDays(2)->toIso8601String()]);
        $forToday = $this->activity($seller, ['subject' => 'Llamada de seguimiento', 'due_at' => $today->copy()->addHours(10)->toIso8601String(), 'opportunity_id' => $opportunityId]);
        $next = $this->activity($seller, ['subject' => 'Visita técnica', 'activity_type' => 'VISIT', 'due_at' => $today->copy()->addDays(3)->toIso8601String()]);
        $done = $this->activity($seller, ['subject' => 'Reunión inicial', 'activity_type' => 'MEETING', 'due_at' => $today->copy()->subDays(5)->toIso8601String()]);
        $this->postJson("/api/v1/crm/activities/{$done}/toggle")->assertOk();

        $ids = fn (string $query = '') => $this->getJson("/api/v1/crm/activities?{$query}")->assertOk()->json('data.*.id');

        // Orden habitual: por fecha, lo más antiguo primero.
        $this->assertSame([$done, $late, $forToday, $next], $ids());
        $this->assertSame([$next, $forToday, $late, $done], $ids('sort=due&dir=desc'));
        // Por el texto del tipo: Email, Llamada, Reunión, Visita.
        $this->assertSame([$late, $forToday, $done, $next], $ids('sort=type&dir=asc'));

        $this->assertSame([$done], $ids('status=done'));
        $this->assertSame([$late, $forToday, $next], $ids('status=pending'));
        $this->assertSame([$next], $ids('type=VISIT'));
        $this->assertSame([$forToday], $ids("opportunity_id={$opportunityId}"));
        $this->assertSame([$late], $ids('search=propuesta'));
        // El buscador también encuentra por el nombre de la oportunidad a la que pertenece.
        $this->assertSame([$forToday], $ids('search=minera'));
        $this->assertSame([$forToday], $ids('filters='.urlencode(json_encode([['id' => 'related', 'type' => 'text', 'operator' => 'contains', 'value' => 'andina']]))));
        $this->assertSame([$next], $ids('filters='.urlencode(json_encode([['id' => 'activity', 'type' => 'text', 'operator' => 'contains', 'value' => 'técnica']]))));

        // "Hoy" es el día de quien mira: la pantalla dice dónde empieza y dónde termina.
        $this->getJson('/api/v1/crm/activities/summary?day_start='.urlencode($today->toIso8601String()).'&day_end='.urlencode($today->copy()->addDay()->toIso8601String()))
            ->assertOk()->assertExactJson(['late' => 1, 'today' => 1, 'upcoming' => 1, 'done' => 1]);

        // El formulario ofrece las oportunidades y los leads abiertos, y los tipos.
        $options = $this->getJson('/api/v1/crm/activities/options')->assertOk();
        $this->assertSame([$opportunityId], $options->json('opportunities.*.id'));
        $this->assertSame(['CALL', 'MEETING', 'EMAIL', 'DEMO', 'VISIT'], $options->json('types.*.code'));

        $this->getJson('/api/v1/crm/activities?per_page=100000')->assertOk()->assertJsonPath('per_page', 200);
    }

    public function test_sales_only_reaches_its_own_activities_and_those_of_what_it_owns(): void
    {
        $this->makeTenant();
        $ana = $this->makeUser('Ana', self::SALES, 'OWN');
        $beto = $this->makeUser('Beto', self::SALES, 'OWN');
        $boss = $this->makeUser('Gerencia', [...self::MANAGEMENT, 'activities.manage'], 'TENANT');

        $anaLead = $this->lead($ana);
        $anaOpportunity = $this->opportunityFrom($this->lead($ana));
        $ofAna = $this->activity($ana, ['lead_id' => $anaLead]);
        $ofBeto = $this->activity($beto);
        // Gerencia registra una actividad en la oportunidad de Ana: Ana la ve, porque la oportunidad es suya.
        $byBoss = $this->activity($boss, ['subject' => 'Revisión con gerencia', 'opportunity_id' => $anaOpportunity]);

        $this->as($ana);
        $this->assertEqualsCanonicalizing([$ofAna, $byBoss], $this->getJson('/api/v1/crm/activities')->assertOk()->json('data.*.id'));
        $this->getJson('/api/v1/crm/activities/summary')->assertOk()->assertJsonPath('upcoming', 2);
        $this->postJson("/api/v1/crm/activities/{$byBoss}/toggle")->assertOk()->assertJsonPath('done', true);
        // Lo ajeno no existe: ni para completarlo ni para editarlo.
        $this->postJson("/api/v1/crm/activities/{$ofBeto}/toggle")->assertNotFound();
        $this->putJson("/api/v1/crm/activities/{$ofBeto}", ['subject' => 'x', 'activity_type' => 'CALL', 'due_at' => now()->toIso8601String()])->assertNotFound();
        $this->assertNull(DB::table('crm_activities')->where('id', $ofBeto)->value('completed_at'));

        // Beto no puede colgar una actividad del lead ni de la oportunidad de Ana.
        $this->as($beto);
        $this->assertSame([$ofBeto], $this->getJson('/api/v1/crm/activities')->assertOk()->json('data.*.id'));
        $this->postJson('/api/v1/crm/activities', ['subject' => 'x', 'activity_type' => 'CALL', 'due_at' => now()->toIso8601String(), 'lead_id' => $anaLead])
            ->assertStatus(422)->assertJsonPath('errors.lead_id.0', 'El lead indicado no existe.');
        $this->postJson('/api/v1/crm/activities', ['subject' => 'x', 'activity_type' => 'CALL', 'due_at' => now()->toIso8601String(), 'opportunity_id' => $anaOpportunity])
            ->assertStatus(422)->assertJsonPath('errors.opportunity_id.0', 'La oportunidad indicada no existe.');
        $this->assertSame([], $this->getJson("/api/v1/crm/activities?opportunity_id={$anaOpportunity}")->assertOk()->json('data.*.id'));

        // Gerencia, con toda la empresa, ve las tres.
        $this->as($boss);
        $this->assertCount(3, $this->getJson('/api/v1/crm/activities')->assertOk()->json('data'));

        // Un rol sin alcance definido alcanza solo lo propio.
        $newcomer = $this->makeUser('Nuevo', self::SALES, null);
        $this->as($newcomer);
        $this->assertSame([], $this->getJson('/api/v1/crm/activities')->assertOk()->json('data'));
    }

    public function test_activities_ask_for_their_permissions_the_module_and_the_company(): void
    {
        $this->makeTenant();
        $viewer = $this->makeUser('Solo ve', ['activities.view']);
        $seller = $this->makeUser('Vendedora', self::SALES);
        $id = $this->activity($seller);

        $this->as($viewer);
        $this->getJson('/api/v1/crm/activities')->assertOk();
        $this->postJson('/api/v1/crm/activities', ['subject' => 'x', 'activity_type' => 'CALL', 'due_at' => now()->toIso8601String()])
            ->assertStatus(403)->assertJsonPath('message', 'No tienes el permiso requerido: activities.manage');
        $this->postJson("/api/v1/crm/activities/{$id}/toggle")->assertStatus(403);
        $this->getJson('/api/v1/crm/activities?export=1')->assertStatus(403)->assertJsonPath('message', 'No tienes el permiso requerido: activities.export');
        $this->postJson('/api/v1/exports', ['resource' => 'activities', 'format' => 'csv', 'rows' => 1])->assertStatus(403);

        $this->as($this->makeUser('Sin actividades', ['leads.view']));
        $this->getJson('/api/v1/crm/activities')->assertStatus(403)->assertJsonPath('message', 'No tienes el permiso requerido: activities.view');

        // Ediciones simultáneas: la segunda pestaña no pisa a la primera.
        $this->as($seller);
        $this->withHeaders(['If-Match' => '0'])->postJson("/api/v1/crm/activities/{$id}/toggle")->assertOk()->assertJsonPath('lock_version', 1);
        $this->withHeaders(['If-Match' => '0'])->postJson("/api/v1/crm/activities/{$id}/toggle")->assertStatus(409);
        $this->assertNotNull(DB::table('crm_activities')->where('id', $id)->value('completed_at'));
        $this->flushHeaders();

        // Otra empresa: no existe.
        $this->makeTenant();
        $stranger = $this->makeUser('De otra empresa', self::ADMIN);
        $this->as($stranger);
        $this->assertSame([], $this->getJson('/api/v1/crm/activities')->assertOk()->json('data'));
        $this->postJson("/api/v1/crm/activities/{$id}/toggle")->assertNotFound();

        // Sin el módulo CRM, todo cerrado.
        $this->makeTenant([]);
        $this->as($this->makeUser('Sin CRM', self::ADMIN));
        $this->getJson('/api/v1/crm/activities')->assertStatus(403);
        $this->getJson('/api/v1/crm/campaigns')->assertStatus(403);
        $this->getJson('/api/v1/crm/scoring')->assertStatus(403);
    }

    // ------------------------------------------------------------------ Campañas

    public function test_campaign_is_created_and_follows_its_states(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administradora', self::ADMIN);
        $this->as($admin);

        $this->postJson('/api/v1/crm/campaigns', [])->assertStatus(422)->assertJsonPath('errors.name.0', 'El nombre es obligatorio.');
        $this->postJson('/api/v1/crm/campaigns', ['name' => 'x', 'starts_at' => '2026-11-10', 'ends_at' => '2026-11-01'])
            ->assertStatus(422)->assertJsonPath('errors.ends_at.0', 'El fin no puede ser anterior al inicio.');
        $this->postJson('/api/v1/crm/campaigns', ['name' => 'x', 'budget' => -1])->assertStatus(422)->assertJsonValidationErrors(['budget']);

        $created = $this->postJson('/api/v1/crm/campaigns', [
            'name' => 'Feria industrial 2026', 'channel' => 'Evento', 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-30', 'budget' => 5000,
        ])->assertCreated()
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('code', 'CMP-'.date('Y').'-0001')
            ->assertJsonPath('starts_at', '2026-11-01')
            ->assertJsonPath('consent_required', true)
            ->assertJsonPath('owner.id', $admin->id)
            ->assertJsonPath('metrics.members', 0);
        $id = $created->json('id');
        $this->assertEquals(5000, $created->json('budget'));
        $this->assertSame('CMP-'.date('Y').'-0002', $this->postJson('/api/v1/crm/campaigns', ['name' => 'Otra'])->assertCreated()->json('code'));

        $this->putJson("/api/v1/crm/campaigns/{$id}", ['name' => 'Feria industrial Lima', 'channel' => 'Evento', 'budget' => 6500, 'consent_required' => false])
            ->assertOk()->assertJsonPath('name', 'Feria industrial Lima')->assertJsonPath('consent_required', false);

        // Estados en orden: no se finaliza un borrador; cancelar pide motivo.
        $this->postJson("/api/v1/crm/campaigns/{$id}/complete")->assertStatus(422)->assertJsonPath('errors.status.0', 'No se puede finalizar una campaña borrador.');
        $this->postJson("/api/v1/crm/campaigns/{$id}/launch")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson("/api/v1/crm/campaigns/{$id}/launch")->assertStatus(422);
        $this->postJson("/api/v1/crm/campaigns/{$id}/cancel")->assertStatus(422)->assertJsonPath('errors.reason.0', 'Debes indicar un motivo.');
        $done = $this->postJson("/api/v1/crm/campaigns/{$id}/complete")->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->assertEqualsCanonicalizing(['crm_campaign.complete', 'crm_campaign.launch', 'crm_campaign.update', 'crm_campaign.create'], collect($done->json('history'))->pluck('action')->all());

        // Cerrada, no se edita ni se cancela.
        $this->putJson("/api/v1/crm/campaigns/{$id}", ['name' => 'Tarde'])->assertStatus(422)->assertJsonPath('errors.status.0', 'No se puede editar una campaña finalizada.');
        $this->postJson("/api/v1/crm/campaigns/{$id}/cancel", ['reason' => 'x'])->assertStatus(422);

        $other = $this->campaign($admin, ['name' => 'Se cancela']);
        $this->postJson("/api/v1/crm/campaigns/{$other}/cancel", ['reason' => 'Sin presupuesto'])
            ->assertOk()->assertJsonPath('status', 'CANCELLED')->assertJsonPath('cancel_reason', 'Sin presupuesto');
        // La base tampoco admite una cancelada sin motivo.
        $this->assertDatabaseRejects(fn () => DB::table('crm_campaigns')->where('id', $id)->update(['status' => 'CANCELLED']));
        $this->assertDatabaseRejects(fn () => DB::table('crm_campaigns')->where('id', $id)->update(['status' => 'PAUSED']));
    }

    public function test_campaign_members_need_consent_and_are_excluded_without_being_deleted(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administradora', self::ADMIN);
        $leadId = $this->lead($admin);
        $discarded = $this->lead($admin);
        $this->postJson("/api/v1/crm/leads/{$discarded}/disqualify", ['reason' => 'No aplica'])->assertOk();
        $id = $this->campaign($admin);

        // Se ofrecen los leads abiertos que aún no son miembros.
        $this->assertSame([$leadId], $this->getJson("/api/v1/crm/campaigns/{$id}")->assertOk()->json('available_leads.*.id'));

        // Sin consentimiento registrado no entra; un descartado tampoco.
        $this->postJson("/api/v1/crm/campaigns/{$id}/members", ['lead_id' => $leadId])
            ->assertStatus(422)->assertJsonPath('errors.consent.0', 'Esta campaña exige el consentimiento registrado de cada miembro.');
        $this->postJson("/api/v1/crm/campaigns/{$id}/members", ['lead_id' => $discarded, 'consent' => true])
            ->assertStatus(422)->assertJsonPath('errors.lead_id.0', 'Un lead descartado no se agrega a una campaña.');
        $this->postJson("/api/v1/crm/campaigns/{$id}/members", ['lead_id' => (string) Str::uuid(), 'consent' => true])
            ->assertStatus(422)->assertJsonPath('errors.lead_id.0', 'El lead indicado no existe.');

        $added = $this->postJson("/api/v1/crm/campaigns/{$id}/members", ['lead_id' => $leadId, 'consent' => true])->assertCreated()
            ->assertJsonPath('metrics.members', 1)
            ->assertJsonPath('members.0.lead.id', $leadId)
            ->assertJsonPath('members.0.consent', true)
            ->assertJsonPath('members.0.status', 'ADDED')
            ->assertJsonPath('available_leads', []);
        $memberId = $added->json('members.0.id');
        // El lead queda asociado a la campaña (su campaña de origen).
        $this->assertSame($id, DB::table('crm_leads')->where('id', $leadId)->value('campaign_id'));

        $this->postJson("/api/v1/crm/campaigns/{$id}/members", ['lead_id' => $leadId, 'consent' => true])
            ->assertStatus(422)->assertJsonPath('errors.lead_id.0', 'El lead ya es miembro de la campaña.');

        // Excluir no borra: la fila queda, deja de contar y el lead vuelve a estar disponible.
        $this->postJson("/api/v1/crm/campaigns/{$id}/members/{$memberId}/exclude")->assertOk()
            ->assertJsonPath('metrics.members', 0)
            ->assertJsonPath('members.0.status', 'EXCLUDED')
            ->assertJsonPath('available_leads.0.id', $leadId);
        $this->assertSame(1, DB::table('crm_campaign_members')->where('campaign_id', $id)->count());
        $this->assertNull(DB::table('crm_leads')->where('id', $leadId)->value('campaign_id'));
        $this->postJson("/api/v1/crm/campaigns/{$id}/members/{$memberId}/exclude")->assertStatus(422);

        // Volver a agregarlo reactiva la misma fila; la base no admite dos del mismo lead.
        $this->postJson("/api/v1/crm/campaigns/{$id}/members", ['lead_id' => $leadId, 'consent' => true])->assertCreated()->assertJsonPath('metrics.members', 1);
        $this->assertSame(1, DB::table('crm_campaign_members')->where('campaign_id', $id)->count());
        $this->assertDatabaseRejects(fn () => DB::table('crm_campaign_members')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'campaign_id' => $id, 'lead_id' => $leadId,
        ]));
        // La aplicación no puede borrar miembros.
        $this->assertDatabaseRejects(fn () => DB::table('crm_campaign_members')->where('campaign_id', $id)->delete());

        // Una campaña que no exige consentimiento admite miembros sin él.
        $free = $this->campaign($admin, ['name' => 'Sin consentimiento', 'consent_required' => false]);
        $this->postJson("/api/v1/crm/campaigns/{$free}/members", ['lead_id' => $leadId])->assertCreated()->assertJsonPath('members.0.consent', false);

        // Cerrada, ya no cambia sus miembros.
        $this->postJson("/api/v1/crm/campaigns/{$id}/cancel", ['reason' => 'Se pospone'])->assertOk();
        $this->postJson("/api/v1/crm/campaigns/{$id}/members/{$memberId}/exclude")->assertStatus(422);
        $this->postJson("/api/v1/crm/campaigns/{$id}/members", ['lead_id' => $this->lead($admin), 'consent' => true])
            ->assertStatus(422)->assertJsonPath('errors.lead_id.0', 'La campaña está cancelada: ya no admite miembros.');

        $actions = DB::table('audit_logs')->where('entity_type', 'CrmCampaign')->where('entity_id', $id)->pluck('action');
        $this->assertSame(2, $actions->filter(fn ($action) => $action === 'crm_campaign.member.add')->count());
        $this->assertSame(1, $actions->filter(fn ($action) => $action === 'crm_campaign.member.exclude')->count());
    }

    public function test_campaign_results_count_only_what_the_role_reaches(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administradora', self::ADMIN);
        $ana = $this->makeUser('Ana', self::SALES, 'OWN');
        $beto = $this->makeUser('Beto', self::SALES, 'OWN');

        $id = $this->campaign($admin, ['budget' => 9000]);
        $this->postJson("/api/v1/crm/campaigns/{$id}/launch")->assertOk();
        $anaLead = $this->lead($ana);
        $betoLead = $this->lead($beto);
        $this->as($admin);
        foreach ([$anaLead, $betoLead] as $leadId) {
            $this->postJson("/api/v1/crm/campaigns/{$id}/members", ['lead_id' => $leadId, 'consent' => true])->assertCreated();
        }
        // El lead de Ana se vuelve oportunidad ganada por 20 000; el de Beto, oportunidad abierta.
        $this->as($ana);
        $anaOpportunity = $this->opportunityFrom($anaLead, 20000);
        $this->postJson("/api/v1/crm/opportunities/{$anaOpportunity}/win", ['evidence' => 'Orden de compra 123'])->assertOk();
        $this->as($beto);
        $this->opportunityFrom($betoLead, 7000);

        // Administración ve toda la campaña.
        $this->as($admin);
        $detail = $this->getJson("/api/v1/crm/campaigns/{$id}")->assertOk()->assertJsonPath('hidden_members', 0);
        $this->assertEquals(['members' => 2, 'qualified' => 2, 'opportunities' => 2, 'won' => 1, 'revenue' => 20000.0], $detail->json('metrics'));
        $this->assertCount(2, $detail->json('members'));
        $this->getJson('/api/v1/crm/campaigns/summary')->assertOk()
            ->assertJsonPath('active', 1)->assertJsonPath('members', 2)->assertJsonPath('opportunities', 2);
        $this->assertEquals(20000, $this->getJson('/api/v1/crm/campaigns/summary')->json('revenue'));
        $row = $this->getJson('/api/v1/crm/campaigns?sort=members&dir=desc')->assertOk()->json('data.0');
        $this->assertSame([$id, 2, 1], [$row['id'], $row['metrics']['members'], $row['metrics']['won']]);

        // Beto ve la campaña, pero solo su lead y su oportunidad: lo de Ana no se le abre por aquí.
        $this->as($beto);
        $mine = $this->getJson("/api/v1/crm/campaigns/{$id}")->assertOk()->assertJsonPath('hidden_members', 1);
        $this->assertEquals(['members' => 1, 'qualified' => 1, 'opportunities' => 1, 'won' => 0, 'revenue' => 0.0], $mine->json('metrics'));
        $this->assertSame([$betoLead], $mine->json('members.*.lead.id'));
        $this->getJson('/api/v1/crm/campaigns/summary')->assertOk()->assertJsonPath('members', 1)->assertJsonPath('opportunities', 1);
        $this->assertEquals(0, $this->getJson('/api/v1/crm/campaigns/summary')->json('revenue'));
        $this->assertSame(1, $this->getJson('/api/v1/crm/campaigns')->assertOk()->json('data.0.metrics.members'));
    }

    public function test_campaigns_list_and_permissions(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administradora', self::ADMIN);
        $seller = $this->makeUser('Vendedora', self::SALES, 'OWN');

        $fair = $this->campaign($admin, ['name' => 'Feria industrial', 'channel' => 'Evento', 'starts_at' => '2026-11-01', 'budget' => 5000]);
        $mail = $this->campaign($admin, ['name' => 'Boletín de noviembre', 'channel' => 'Email', 'starts_at' => '2026-11-15', 'budget' => 800]);
        $this->postJson("/api/v1/crm/campaigns/{$mail}/launch")->assertOk();
        // Creadas en el mismo segundo empatarían en el orden habitual (lo más reciente primero).
        DB::table('crm_campaigns')->where('id', $fair)->update(['created_at' => now()->subMinute()]);
        $ids = fn (string $query = '') => $this->getJson("/api/v1/crm/campaigns?{$query}")->assertOk()->json('data.*.id');

        $this->assertSame([$mail, $fair], $ids());
        $this->assertSame([$mail, $fair], $ids('sort=campaign&dir=asc'));
        $this->assertSame([$fair, $mail], $ids('sort=budget&dir=desc'));
        // Por el texto del estado: Activa antes que Borrador.
        $this->assertSame([$mail, $fair], $ids('sort=status&dir=asc'));
        $this->assertSame([$mail], $ids('status=ACTIVE'));
        $this->assertSame([$fair], $ids('search=evento'));
        $this->assertSame([$fair], $ids('filters='.urlencode(json_encode([['id' => 'budget', 'type' => 'number', 'operator' => 'greater', 'value' => '1000']]))));
        $this->getJson('/api/v1/crm/campaigns?export=1')->assertOk();

        // Ventas ve las campañas (como en el prototipo), pero no las gestiona ni las exporta.
        $this->as($seller);
        $this->assertCount(2, $ids());
        $this->getJson("/api/v1/crm/campaigns/{$fair}")->assertOk();
        foreach ([
            ['postJson', '/api/v1/crm/campaigns', ['name' => 'x']],
            ['putJson', "/api/v1/crm/campaigns/{$fair}", ['name' => 'x']],
            ['postJson', "/api/v1/crm/campaigns/{$fair}/launch", []],
            ['postJson', "/api/v1/crm/campaigns/{$fair}/cancel", ['reason' => 'x']],
            ['postJson', "/api/v1/crm/campaigns/{$fair}/members", ['lead_id' => (string) Str::uuid()]],
        ] as [$verb, $path, $body]) {
            $this->{$verb}($path, $body)->assertStatus(403)->assertJsonPath('message', 'No tienes el permiso requerido: campaigns.manage');
        }
        $this->getJson('/api/v1/crm/campaigns?export=1')->assertStatus(403);
        $this->assertSame('DRAFT', DB::table('crm_campaigns')->where('id', $fair)->value('status'));

        // Ediciones simultáneas.
        $this->as($admin);
        $this->withHeaders(['If-Match' => '0'])->putJson("/api/v1/crm/campaigns/{$fair}", ['name' => 'Feria A'])->assertOk();
        $this->withHeaders(['If-Match' => '0'])->putJson("/api/v1/crm/campaigns/{$fair}", ['name' => 'Feria B'])->assertStatus(409);
        $this->assertSame('Feria A', DB::table('crm_campaigns')->where('id', $fair)->value('name'));
        $this->flushHeaders();

        // Otra empresa: no existe, y su numeración empieza de nuevo.
        $this->makeTenant();
        $this->as($this->makeUser('De otra empresa', self::ADMIN));
        $this->assertSame([], $ids());
        $this->getJson("/api/v1/crm/campaigns/{$fair}")->assertNotFound();
        $this->postJson("/api/v1/crm/campaigns/{$fair}/launch")->assertNotFound();
        $this->assertSame('CMP-'.date('Y').'-0001', $this->postJson('/api/v1/crm/campaigns', ['name' => 'Propia'])->assertCreated()->json('code'));
    }

    public function test_lead_form_associates_the_lead_to_an_active_campaign(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administradora', self::ADMIN);
        $seller = $this->makeUser('Vendedora', self::SALES, 'OWN');
        $active = $this->campaign($admin, ['name' => 'Activa']);
        $this->postJson("/api/v1/crm/campaigns/{$active}/launch")->assertOk();
        $draft = $this->campaign($admin, ['name' => 'Borrador']);

        $this->as($seller);
        // El formulario ofrece solo las campañas activas.
        $this->assertSame([$active], $this->getJson('/api/v1/crm/leads/options')->assertOk()->json('campaigns.*.id'));

        $base = ['organization_name' => 'Textiles Lima EIRL', 'email' => 'ventas@textiles.test', 'source_id' => $this->sourceId('LinkedIn Ads')];
        $this->postJson('/api/v1/crm/leads', [...$base, 'campaign_id' => $draft, 'campaign_consent' => true])
            ->assertStatus(422)->assertJsonPath('errors.campaign_id.0', 'La campaña indicada no existe o no está activa.');
        $this->postJson('/api/v1/crm/leads', [...$base, 'campaign_id' => $active])
            ->assertStatus(422)->assertJsonPath('errors.consent.0', 'Esta campaña exige el consentimiento registrado de cada miembro.');
        // Nada quedó a medias.
        $this->assertSame(0, DB::table('crm_leads')->where('tenant_id', $this->tenantId)->count());

        $lead = $this->postJson('/api/v1/crm/leads', [...$base, 'campaign_id' => $active, 'campaign_consent' => true])->assertCreated()
            ->assertJsonPath('campaign.id', $active)->assertJsonPath('campaign.name', 'Activa');
        $this->assertSame(1, DB::table('crm_campaign_members')->where('campaign_id', $active)->where('lead_id', $lead->json('id'))->where('consent', true)->count());
        // Asociado a una campaña (+5) y con correo (+5).
        $this->assertSame(10, $lead->json('score'));

        // Quien no puede ver campañas no las recibe ni puede asociar a ciegas.
        $this->as($this->makeUser('Sin campañas', ['leads.view', 'leads.manage']));
        $this->assertSame([], $this->getJson('/api/v1/crm/leads/options')->assertOk()->json('campaigns'));
        $this->postJson('/api/v1/crm/leads', [...$base, 'email' => 'otro@textiles.test', 'campaign_id' => $active, 'campaign_consent' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['campaign_id']);
    }

    // ------------------------------------------------------------------ Puntuación

    public function test_lead_score_follows_the_rules_and_is_recalculated(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administradora', self::ADMIN);

        // Referido (+25) + cargo con poder de decisión (+20) + correo (+5) = 50 → B.
        $id = $this->lead($admin, ['source' => 'Referido', 'position' => 'Jefe de planta']);
        $lead = $this->getJson("/api/v1/crm/leads/{$id}")->assertOk()->assertJsonPath('score', 50)->assertJsonPath('score_grade', 'B');
        $this->assertSame(['Viene por referido (+25)', 'Cargo con poder de decisión (+20)', 'Tiene correo de contacto (+5)'], $lead->json('score_hits'));

        // Sin nada que sume: 0 → D.
        $cold = $this->lead($admin, ['email' => null, 'person_name' => 'Sin Datos']);
        $this->getJson("/api/v1/crm/leads/{$cold}")->assertOk()->assertJsonPath('score', 0)->assertJsonPath('score_grade', 'D')->assertJsonPath('score_hits', []);

        // Editar el lead lo recalcula: deja de ser referido y pasa a evento (+15).
        $this->putJson("/api/v1/crm/leads/{$id}", ['organization_name' => 'Agroindustrias del Sur SAC', 'position' => 'Jefe de planta', 'email' => 'jefe@agrosur.test', 'source_id' => $this->sourceId('Evento')])
            ->assertOk()->assertJsonPath('score', 40)->assertJsonPath('score_grade', 'C');

        // Dos interacciones registradas suman (+15); una sola, no.
        $this->activity($admin, ['lead_id' => $id]);
        $this->getJson("/api/v1/crm/leads/{$id}")->assertJsonPath('score', 40);
        $this->activity($admin, ['lead_id' => $id]);
        $this->getJson("/api/v1/crm/leads/{$id}")->assertJsonPath('score', 55)->assertJsonPath('score_grade', 'B');
        // El puntaje no cuenta como una edición del lead: su versión no se movió por recalcular.
        $this->assertSame(1, (int) DB::table('crm_leads')->where('id', $id)->value('lock_version'));

        // El listado ordena y filtra por puntaje sobre toda la tabla.
        $this->assertSame([$id, $cold], $this->getJson('/api/v1/crm/leads?sort=score&dir=desc')->assertOk()->json('data.*.id'));
        $this->assertSame([$id], $this->getJson('/api/v1/crm/leads?filters='.urlencode(json_encode([['id' => 'score', 'type' => 'number', 'operator' => 'greater', 'value' => '50']])))->assertOk()->json('data.*.id'));
    }

    public function test_scoring_rules_are_changed_with_their_own_permission_and_rescore_every_lead(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Administradora', self::ADMIN);
        $seller = $this->makeUser('Vendedora', self::SALES, 'OWN');
        $hers = $this->lead($seller, ['source' => 'Web']);                              // Web +10, correo +5 = 15 → D
        $his = $this->lead($admin, ['source' => 'Referido', 'position' => 'Gerente']);  // 25 + 20 + 5 = 50 → B

        // Ventas ve la pantalla (sobre sus leads), pero no cambia las reglas de toda la empresa.
        $this->as($seller);
        $screen = $this->getJson('/api/v1/crm/scoring')->assertOk()->assertJsonCount(8, 'rules');
        $this->assertSame(['id' => 'source_referral', 'label' => 'Viene por referido', 'condition' => 'Fuente es Referido', 'points' => 25, 'default_points' => 25], $screen->json('rules.0'));
        $this->assertSame([['grade' => 'A', 'total' => 0], ['grade' => 'B', 'total' => 0], ['grade' => 'C', 'total' => 0], ['grade' => 'D', 'total' => 1]], $screen->json('distribution'));
        $this->assertSame([$hers], $screen->json('priority.*.id'));
        $this->putJson('/api/v1/crm/scoring/rules', ['points' => ['source_web' => 50]])
            ->assertStatus(403)->assertJsonPath('message', 'No tienes el permiso requerido: scoring.manage');
        $this->postJson('/api/v1/crm/scoring/rescore')->assertStatus(403);

        $this->as($admin);
        $this->assertSame([$his, $hers], $this->getJson('/api/v1/crm/scoring')->assertOk()->json('priority.*.id'));

        // Los puntos van de −50 a 50, enteros, y solo de criterios que existen.
        $this->putJson('/api/v1/crm/scoring/rules', ['points' => ['source_web' => 51]])->assertStatus(422)->assertJsonValidationErrors(['points.source_web']);
        $this->putJson('/api/v1/crm/scoring/rules', ['points' => ['source_web' => 1.5]])->assertStatus(422);
        $this->putJson('/api/v1/crm/scoring/rules', ['points' => ['inventado' => 5]])->assertStatus(422)->assertJsonPath('errors.points.0', 'Hay un criterio que no existe.');

        // Web pasa a valer 50 y el correo resta 5: se recalculan todos, también los de otras personas.
        $saved = $this->putJson('/api/v1/crm/scoring/rules', ['points' => ['source_web' => 50, 'has_email' => -5]])->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('changed', 2);
        $this->assertSame(50, collect($saved->json('rules'))->firstWhere('id', 'source_web')['points']);
        $this->assertSame(45, (int) DB::table('crm_leads')->where('id', $hers)->value('score'));
        $this->assertSame('B', DB::table('crm_leads')->where('id', $hers)->value('score_grade'));
        $this->getJson("/api/v1/crm/leads/{$his}")->assertJsonPath('score', 40)->assertJsonPath('score_hits.2', 'Tiene correo de contacto (−5)');

        // Queda en auditoría con los puntos de antes y de después.
        $log = DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'crm_scoring.update')->first();
        $this->assertSame([10, 50], [json_decode($log->before_data, true)['source_web'], json_decode($log->after_data, true)['source_web']]);

        // "Recalcular todos": si nada cambió, no toca ninguno.
        $this->postJson('/api/v1/crm/scoring/rescore')->assertOk()->assertExactJson(['total' => 2, 'changed' => 0]);
        DB::table('crm_leads')->where('id', $hers)->update(['score' => 99]);
        $this->postJson('/api/v1/crm/scoring/rescore')->assertOk()->assertExactJson(['total' => 2, 'changed' => 1]);

        // Las reglas son de cada empresa.
        $this->makeTenant();
        $this->as($this->makeUser('De otra empresa', self::ADMIN));
        $this->assertSame(10, collect($this->getJson('/api/v1/crm/scoring')->assertOk()->json('rules'))->firstWhere('id', 'source_web')['points']);
    }

    public function test_roles_matrix_offers_the_new_crm_permissions_and_the_activities_scope(): void
    {
        $this->makeTenant();
        $this->as($this->makeUser('Administra usuarios', ['users.view']));

        $roles = $this->getJson('/api/v1/roles')->assertOk();
        foreach (['activities.view', 'activities.manage', 'activities.export', 'campaigns.view', 'campaigns.manage', 'campaigns.export', 'scoring.manage'] as $code) {
            $this->assertContains($code, $roles->json('permissions.*.code'));
        }
        $this->assertContains('activities', $roles->json('scope_resources.*.code'));
    }
}
