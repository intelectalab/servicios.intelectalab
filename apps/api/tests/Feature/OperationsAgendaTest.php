<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Operaciones: Agenda. Eventos con responsable y horario, aviso de cruces (con otros eventos y
 * con órdenes de trabajo), estados, alcance por rol, permisos y módulo.
 */
class OperationsAgendaTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MODULES = ['ORDENES_TRABAJO', 'CONTRATOS', 'AGENDA'];

    private const ADMIN = [
        'agenda.view', 'agenda.create', 'agenda.manage', 'core.view',
        'contracted_services.view', 'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve',
    ];

    /** Alcance del rol de esa persona sobre la agenda (sin fila: lo propio). */
    private function agendaScope(User $user, string $scope): User
    {
        DB::table('role_scopes')->insert([
            'role_id' => DB::table('user_roles')->where('user_id', $user->id)->value('role_id'),
            'tenant_id' => $this->tenantId, 'resource' => 'agenda', 'scope' => $scope,
        ]);

        return $user;
    }

    private function event(array $data = [], int $status = 201)
    {
        return $this->postJson('/api/v1/agenda', ['title' => 'Reunión de avance', 'starts_at' => $this->at(2, 9), ...$data])->assertStatus($status);
    }

    private function week(int $fromDays = 0, int $toDays = 7)
    {
        return $this->getJson('/api/v1/agenda?'.http_build_query([
            'from' => now()->addDays($fromDays)->startOfDay()->toIso8601String(), 'to' => now()->addDays($toDays)->startOfDay()->toIso8601String(),
        ]))->assertOk();
    }

    public function test_events_are_scheduled_edited_done_and_cancelled(): void
    {
        $this->makeTenant(self::MODULES);
        $admin = $this->agendaScope($this->makeUser('Admin', self::ADMIN), 'TENANT');
        $other = $this->makeUser('Vera Ventas', ['agenda.view']);
        $outsider = $this->makeUser('Sin agenda', ['core.view']);
        $customerId = $this->makeCustomer();
        $this->as($admin);

        // Título e inicio obligatorios; tipo conocido; el fin después del inicio y como mucho a 24 horas.
        $this->postJson('/api/v1/agenda', [])->assertStatus(422)->assertJsonValidationErrors(['title', 'starts_at']);
        $this->event(['event_type' => 'FIESTA'], 422)->assertJsonValidationErrors('event_type');
        $this->event(['ends_at' => $this->at(2, 8)], 422)->assertJsonValidationErrors('ends_at');
        $this->event(['ends_at' => $this->at(4, 9)], 422)->assertJsonValidationErrors('ends_at');
        // Solo se agenda a quien tiene acceso a la agenda, y con un cliente de la empresa.
        $this->event(['assigned_user_id' => $outsider->id], 422)->assertJsonValidationErrors('assigned_user_id');
        $this->event(['customer_id' => (string) \Illuminate\Support\Str::uuid()], 422)->assertJsonValidationErrors('customer_id');

        // Sin responsable, es de quien agenda; sin fin, dura una hora.
        $mine = $this->event()->json();
        $this->assertEquals(['MEETING', 'SCHEDULED', $admin->id, true, null], [$mine['event_type'], $mine['status'], $mine['assignee']['id'], $mine['own'], $mine['customer']]);
        $this->assertEquals(60, \Illuminate\Support\Carbon::parse($mine['starts_at'])->diffInMinutes($mine['ends_at']));

        $visit = $this->event([
            'title' => 'Visita técnica', 'event_type' => 'VISIT', 'starts_at' => $this->at(3, 10), 'ends_at' => $this->at(3, 12),
            'assigned_user_id' => $other->id, 'customer_id' => $customerId, 'location' => 'Planta Callao', 'notes' => 'Llevar EPP',
        ])->json();
        $this->assertEquals(['VISIT', 'Vera Ventas', false, 'Planta Callao', 'Llevar EPP'], [$visit['event_type'], $visit['assignee']['name'], $visit['own'], $visit['location'], $visit['notes']]);
        $this->assertNotNull($visit['customer']['name']);

        // La semana entrega lo que cae en el rango, en orden.
        $week = $this->week();
        $this->assertEquals(['Reunión de avance', 'Visita técnica'], $week->json('data.*.title'));
        $this->assertSame([], $week->json('work_orders'));
        $this->assertSame([], $this->week(10, 17)->json('data'));
        $this->getJson('/api/v1/agenda')->assertStatus(422);
        $this->getJson('/api/v1/agenda?'.http_build_query(['from' => now()->toIso8601String(), 'to' => now()->addDays(90)->toIso8601String()]))->assertStatus(422);

        $options = $this->getJson('/api/v1/agenda/options')->assertOk();
        $this->assertEqualsCanonicalizing(['Admin', 'Vera Ventas'], $options->json('people.*.name'));
        $this->assertCount(1, $options->json('customers'));

        // Editar, realizar y cancelar (con motivo); un evento cerrado no cambia.
        $id = $mine['id'];
        $edited = $this->putJson("/api/v1/agenda/{$id}", ['title' => 'Reunión movida', 'starts_at' => $this->at(2, 15), 'event_type' => 'SESSION'])->assertOk()->json();
        $this->assertEquals(['Reunión movida', 'SESSION'], [$edited['title'], $edited['event_type']]);
        $this->postJson("/api/v1/agenda/{$id}/done")->assertOk()->assertJsonPath('status', 'DONE');
        $this->postJson("/api/v1/agenda/{$id}/done")->assertStatus(422);
        $this->putJson("/api/v1/agenda/{$id}", ['title' => 'X', 'starts_at' => $this->at(2, 15)])->assertStatus(422);
        $this->postJson("/api/v1/agenda/{$id}/cancel", ['reason' => 'x'])->assertStatus(422);

        $this->postJson("/api/v1/agenda/{$visit['id']}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
        $cancelled = $this->postJson("/api/v1/agenda/{$visit['id']}/cancel", ['reason' => 'El cliente reprogramó'])->assertOk()->json();
        $this->assertEquals(['CANCELLED', 'El cliente reprogramó'], [$cancelled['status'], $cancelled['cancel_reason']]);

        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'AgendaEvent', 'entity_id' => $id, 'action' => 'agenda.done']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'AgendaEvent', 'entity_id' => $visit['id'], 'action' => 'agenda.cancel']);

        // La base tampoco admite un fin anterior al inicio, una cancelación sin motivo, un estado inventado ni borrar.
        $draft = $this->event(['starts_at' => $this->at(5, 9)])->json('id');
        $this->assertDatabaseRejects(fn () => DB::table('agenda_events')->where('id', $draft)->update(['ends_at' => now()->subYear()]));
        $this->assertDatabaseRejects(fn () => DB::table('agenda_events')->where('id', $draft)->update(['status' => 'CANCELLED']));
        $this->assertDatabaseRejects(fn () => DB::table('agenda_events')->where('id', $draft)->update(['status' => 'PERDIDO']));
        $this->assertDatabaseRejects(fn () => DB::table('agenda_events')->where('id', $draft)->delete());
    }

    public function test_clashes_warn_before_saving_and_block_work_orders(): void
    {
        $this->makeTenant(self::MODULES);
        $admin = $this->agendaScope($this->makeUser('Admin', self::ADMIN), 'TENANT');
        $technician = $this->makeUser('Tito Técnico', ['agenda.view', 'work_orders.view', 'work_orders.manage']);
        $contracted = $this->makeContracted();
        $this->as($admin);

        $first = $this->event(['starts_at' => $this->at(2, 9), 'ends_at' => $this->at(2, 11), 'assigned_user_id' => $technician->id])->json();

        // Otro evento de la misma persona que se cruza: avisa y no guarda; con "force" se guarda y queda anotado.
        $clash = ['title' => 'Capacitación', 'starts_at' => $this->at(2, 10), 'assigned_user_id' => $technician->id];
        $this->event($clash, 422)->assertJsonPath('code', 'AGENDA_CONFLICT')->assertJsonPath('message', 'Conflicto de agenda con "Reunión de avance".');
        $this->assertSame(1, DB::table('agenda_events')->where('tenant_id', $this->tenantId)->count());
        $forced = $this->event([...$clash, 'force' => true])->json();
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $forced['id'], 'action' => 'agenda.create']);
        $this->assertTrue((bool) DB::table('audit_logs')->where('entity_id', $forced['id'])->where('action', 'agenda.create')->value(DB::raw("(metadata->>'forced')::boolean")));

        // Justo después no hay cruce; otra persona a la misma hora, tampoco; un evento cancelado libera el horario.
        $this->event(['starts_at' => $this->at(2, 11), 'ends_at' => $this->at(2, 12), 'assigned_user_id' => $technician->id, 'title' => 'Cierre']);
        $this->event(['starts_at' => $this->at(2, 9)]);
        $this->postJson("/api/v1/agenda/{$forced['id']}/cancel", ['reason' => 'Duplicado'])->assertOk();
        $this->postJson("/api/v1/agenda/{$first['id']}/cancel", ['reason' => 'Se movió'])->assertOk();
        $this->event(['starts_at' => $this->at(2, 9), 'ends_at' => $this->at(2, 10), 'assigned_user_id' => $technician->id, 'title' => 'Nuevo']);

        // Editar un evento no choca consigo mismo.
        $solo = $this->event(['starts_at' => $this->at(4, 9), 'ends_at' => $this->at(4, 11), 'assigned_user_id' => $technician->id, 'title' => 'Inventario'])->json();
        $this->putJson("/api/v1/agenda/{$solo['id']}", ['title' => 'Inventario', 'starts_at' => $this->at(4, 10), 'ends_at' => $this->at(4, 12), 'assigned_user_id' => $technician->id])->assertOk();

        // Una orden de trabajo asignada ocupa la agenda de su técnico: agendar encima avisa.
        $order = $this->order($admin, $contracted, ['assigned_user_id' => $technician->id, 'scheduled_start' => $this->at(6, 9)]);
        $this->event(['starts_at' => $this->at(6, 10), 'assigned_user_id' => $technician->id, 'title' => 'Charla'], 422)
            ->assertJsonPath('message', "Conflicto de agenda con la orden de trabajo {$order['code']}.");

        // Y al revés es una regla dura: no se asigna una orden sobre un evento programado del técnico.
        $this->postJson('/api/v1/work-orders', [
            'contracted_service_id' => $contracted, 'title' => 'Otra', 'assigned_user_id' => $technician->id, 'scheduled_start' => $this->at(4, 11),
        ])->assertStatus(422)->assertJsonPath('errors.scheduled_start.0', 'El técnico ya tiene un evento de agenda en ese horario: "Inventario".');

        // La semana trae también las órdenes de trabajo programadas (no las canceladas).
        $week = $this->week(5, 8);
        $this->assertEquals([[$order['code'], 'ASSIGNED', 'Tito Técnico']], array_map(fn ($row) => [$row['code'], $row['status'], $row['assignee']['name']], $week->json('work_orders')));
        $this->assertEquals(2, \Illuminate\Support\Carbon::parse($week->json('work_orders.0.starts_at'))->diffInHours($week->json('work_orders.0.ends_at')));
    }

    public function test_agenda_respects_scope_permissions_module_and_company(): void
    {
        $this->makeTenant(self::MODULES);
        $lima = $this->makeBranch('Lima');
        $cusco = $this->makeBranch('Cusco');
        $admin = $this->agendaScope($this->makeUser('Admin', self::ADMIN), 'TENANT');
        $sales = $this->makeUser('Vera Ventas', ['agenda.view', 'agenda.create', 'agenda.manage'], null, $lima);
        $technician = $this->makeUser('Tito Técnico', ['agenda.view'], null, $cusco);
        $planner = $this->agendaScope($this->makeUser('Olga Operaciones', ['agenda.view', 'agenda.create', 'agenda.manage'], null, $lima), 'BRANCH');
        $manager = $this->agendaScope($this->makeUser('Gerencia', ['agenda.view']), 'TENANT');

        $this->as($sales);
        $hers = $this->event(['title' => 'Demo comercial'])->json();
        $forTito = $this->event(['title' => 'Entrega al técnico', 'starts_at' => $this->at(3, 9), 'assigned_user_id' => $technician->id])->json();
        $this->as($admin);
        $adminOnly = $this->event(['title' => 'Comité', 'starts_at' => $this->at(4, 9)])->json();

        // Propios: lo que la persona tiene asignado y lo que agendó. Lo ajeno "no existe".
        $this->as($sales);
        $this->assertEqualsCanonicalizing(['Demo comercial', 'Entrega al técnico'], $this->week()->json('data.*.title'));
        $this->getJson("/api/v1/agenda/{$adminOnly['id']}")->assertStatus(404);
        $this->postJson("/api/v1/agenda/{$adminOnly['id']}/cancel", ['reason' => 'x'])->assertStatus(404);
        // El aviso de cruce no revela el título de un evento que no alcanza.
        $this->event(['title' => 'Encima', 'starts_at' => $this->at(4, 9), 'assigned_user_id' => $admin->id], 422)
            ->assertJsonPath('message', 'Conflicto de agenda con otro evento de esa persona.');

        // El técnico ve lo suyo y no agenda ni gestiona.
        $this->as($technician);
        $this->assertEquals(['Entrega al técnico'], $this->week()->json('data.*.title'));
        $this->assertNull($this->week()->json('work_orders'));
        $this->event([], 403);
        $this->postJson("/api/v1/agenda/{$forTito['id']}/done")->assertStatus(403);
        $this->assertNull($this->getJson('/api/v1/agenda/options')->assertOk()->json('customers'));

        // Sede: lo de su sede (los eventos nacen en la sede de quien agenda). Toda la empresa: todo.
        $this->as($planner);
        $this->assertEqualsCanonicalizing(['Demo comercial', 'Entrega al técnico'], $this->week()->json('data.*.title'));
        $this->assertSame($lima, DB::table('agenda_events')->where('id', $hers['id'])->value('branch_id'));
        $this->as($manager);
        $this->assertCount(3, $this->week()->json('data'));
        $this->event([], 403);

        // Edición simultánea: con una versión vieja responde 409.
        $this->as($sales);
        $this->putJson("/api/v1/agenda/{$hers['id']}", ['title' => 'Demo', 'starts_at' => $this->at(2, 9)], ['If-Match' => '0'])->assertOk();
        $this->putJson("/api/v1/agenda/{$hers['id']}", ['title' => 'Demo 2', 'starts_at' => $this->at(2, 9)], ['If-Match' => '0'])->assertStatus(409);

        // Otra empresa no ve nada; sin el módulo del plan, 403.
        $this->makeTenant(self::MODULES);
        $this->as($this->agendaScope($this->makeUser('Otra empresa', self::ADMIN), 'TENANT'));
        $this->assertSame([], $this->week()->json('data'));
        $this->getJson("/api/v1/agenda/{$hers['id']}")->assertStatus(404);
        $this->makeTenant(['ORDENES_TRABAJO', 'CONTRATOS']);
        $this->as($this->makeUser('Sin módulo', self::ADMIN));
        $this->getJson('/api/v1/agenda/options')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_roles_matrix_and_audit_know_the_agenda(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Administra usuarios', ['users.view']));

        $roles = $this->getJson('/api/v1/roles')->assertOk();
        foreach (['agenda.view', 'agenda.create', 'agenda.manage'] as $code) {
            $this->assertContains($code, $roles->json('permissions.*.code'));
        }
        $this->assertContains('agenda', $roles->json('scope_resources.*.code'));

        foreach (['agenda.create', 'agenda.update', 'agenda.done', 'agenda.cancel'] as $action) {
            $this->assertNotSame($action, AuditCatalog::actionLabel($action), "Falta el texto de {$action}");
        }
        $this->assertEquals('Evento de agenda', AuditCatalog::entityLabel('AgendaEvent'));
    }
}
