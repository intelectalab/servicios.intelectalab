<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Soporte: tickets con SLA en horario hábil (feriados, Premium, pausa, escalar, reabrir),
 * conversación con notas internas, alcance por rol, OT desde el ticket, reclamos de garantía con
 * su cobertura, calendario laboral y SLA, permisos, exportar y protecciones de la base.
 */
class SupportTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const AGENT = [
        'tickets.view', 'tickets.create', 'tickets.manage', 'tickets.approve', 'tickets.export',
        'warranty.view', 'warranty.create', 'warranty.manage', 'warranty.approve', 'warranty.export',
        'contracted_services.view', 'contracts.view', 'work_orders.view', 'work_orders.create',
    ];

    private function support(): string
    {
        return $this->makeTenant(['SOPORTE', 'ORDENES_TRABAJO', 'CONTRATOS']);
    }

    private function ticket(array $data = []): array
    {
        return $this->postJson('/api/v1/tickets', [
            'customer_id' => $data['customer_id'] ?? $this->makeCustomer(), 'channel' => 'PHONE', 'category' => 'INCIDENT',
            'subject' => 'Aire acondicionado gotea', 'description' => 'Gotea en la sala de reuniones.', ...$data,
        ])->assertCreated()->json();
    }

    private function lima(string $local): Carbon
    {
        return Carbon::parse($local, 'America/Lima')->setTimezone('UTC');
    }

    public function test_the_sla_is_computed_in_business_hours_with_premium_pause_escalation_and_reopening(): void
    {
        $this->support();
        $agent = $this->makeUser('Soporte', self::AGENT);
        $this->as($agent);

        $this->postJson('/api/v1/tickets', [])->assertStatus(422)->assertJsonValidationErrors(['customer_id', 'subject']);
        $this->postJson('/api/v1/tickets', ['customer_id' => (string) Str::uuid(), 'subject' => 'X'])->assertStatus(422)->assertJsonValidationErrors('customer_id');

        // Viernes 16 de octubre, 17:00 en Lima.
        $this->travelTo($this->lima('2026-10-16 17:00'));
        [$contractId, $customerId] = $this->makeContract();
        $ticket = $this->ticket(['customer_id' => $customerId]);
        $id = $ticket['id'];
        $this->assertSame(['OPEN', null, $contractId, 'none'], [$ticket['status'], $ticket['priority'], $ticket['contract']['id'], $ticket['sla']['status']]);
        $this->assertMatchesRegularExpression('/^TKT-/', $ticket['number']);
        $this->assertSame('Gotea en la sala de reuniones.', $ticket['comments'][0]['body']);

        // No se asigna sin clasificar.
        $this->postJson("/api/v1/tickets/{$id}/assign", ['assigned_user_id' => $agent->id])->assertStatus(422)->assertJsonValidationErrors('status');

        // P3 (24 h hábiles): viernes 17–18 (1 h) + sábado 9–13 (4 h) + lunes 8:30–18 (9,5 h) + martes (9,5 h) = martes 18:00.
        $triaged = $this->postJson("/api/v1/tickets/{$id}/triage", ['priority' => 'P3', 'category' => 'REQUEST'])->assertOk()->json();
        $this->assertSame(['TRIAGED', 'P3', 'REQUEST'], [$triaged['status'], $triaged['priority'], $triaged['category']]);
        $this->assertTrue($this->lima('2026-10-20 18:00')->equalTo(Carbon::parse($triaged['sla']['resolution_due_at'])));
        // Respuesta en 4 h hábiles: viernes 1 h + sábado 3 h = sábado 12:00.
        $this->assertTrue($this->lima('2026-10-17 12:00')->equalTo(Carbon::parse($triaged['sla']['response_due_at'])));

        // Contrato Premium: la mitad del tiempo (12 h hábiles → lunes 15:30).
        DB::table('contracts')->where('id', $contractId)->update(['sla_level' => 'PREMIUM']);
        $premium = $this->postJson("/api/v1/tickets/{$id}/triage", ['priority' => 'P3'])->assertOk()->json();
        $this->assertTrue($this->lima('2026-10-19 15:30')->equalTo(Carbon::parse($premium['sla']['resolution_due_at'])));
        $this->assertSame([0.5, 720], [$premium['policy']['factor'], $premium['policy']['applied_resolution_minutes']]);

        // P1 se cuenta 24×7.
        $p1 = $this->postJson("/api/v1/tickets/{$id}/triage", ['priority' => 'P1'])->assertOk()->json();
        $this->assertTrue($this->lima('2026-10-16 19:00')->equalTo(Carbon::parse($p1['sla']['resolution_due_at'])));
        $this->postJson("/api/v1/tickets/{$id}/triage", ['priority' => 'P3'])->assertOk();

        // Asignar a quien no atiende tickets: no.
        $outsider = $this->makeUser('Ventas', ['tickets.view']);
        $this->as($agent);
        $this->postJson("/api/v1/tickets/{$id}/assign", ['assigned_user_id' => $outsider->id])->assertStatus(422)->assertJsonValidationErrors('assigned_user_id');
        $assigned = $this->postJson("/api/v1/tickets/{$id}/assign", ['assigned_user_id' => $agent->id])->assertOk()->json();
        $this->assertSame(['ASSIGNED', $agent->id], [$assigned['status'], $assigned['assignee']['id']]);

        // Atender: primera respuesta.
        $this->travelTo($this->lima('2026-10-19 09:00'));
        $started = $this->postJson("/api/v1/tickets/{$id}/start")->assertOk()->json();
        $this->assertSame('IN_PROGRESS', $started['status']);
        $this->assertNotNull($started['sla']['first_response_at']);

        // Nota interna y respuesta pública.
        $this->postJson("/api/v1/tickets/{$id}/comments", ['body' => '   '])->assertStatus(422);
        $this->postJson("/api/v1/tickets/{$id}/comments", ['body' => 'Revisar logs, no compartir IP interna.', 'internal' => true])->assertCreated();
        $replied = $this->postJson("/api/v1/tickets/{$id}/comments", ['body' => 'Vamos en camino.'])->assertCreated()->json();
        $this->assertSame([false, true, false], array_column($replied['comments'], 'internal'));

        // Esperando cliente pausa el reloj; al retomar, las horas hábiles en pausa se suman al vencimiento.
        $waiting = $this->postJson("/api/v1/tickets/{$id}/wait")->assertOk()->json();
        $this->assertSame(['WAITING_CUSTOMER', 'paused'], [$waiting['status'], $waiting['sla']['status']]);
        $this->travelTo($this->lima('2026-10-19 11:00'));
        $resumed = $this->postJson("/api/v1/tickets/{$id}/resume")->assertOk()->json();
        $this->assertSame([null, 7200], [$resumed['sla']['paused_at'], $resumed['sla']['paused_seconds']]);
        // Vencía el lunes 15:30 (Premium): con 2 h de pausa, lunes 17:30.
        $this->assertTrue($this->lima('2026-10-19 17:30')->equalTo(Carbon::parse($resumed['sla']['resolution_due_at'])));

        // Escalar es otro permiso y recalcula desde ahora con la nueva prioridad (P2, 8 h hábiles × 0,5 = 4 h → 15:00).
        $limited = $this->makeUser('Atención', ['tickets.view', 'tickets.manage']);
        $this->as($limited);
        $this->postJson("/api/v1/tickets/{$id}/escalate")->assertStatus(403);
        $this->as($agent);
        $escalated = $this->postJson("/api/v1/tickets/{$id}/escalate")->assertOk()->json();
        $this->assertSame('P2', $escalated['priority']);
        $this->assertTrue($this->lima('2026-10-19 15:00')->equalTo(Carbon::parse($escalated['sla']['resolution_due_at'])));

        // Resolver pide la resolución; cerrar; un cerrado no recibe mensajes; reabrir pide motivo.
        $this->postJson("/api/v1/tickets/{$id}/resolve")->assertStatus(422)->assertJsonValidationErrors('reason');
        $resolved = $this->postJson("/api/v1/tickets/{$id}/resolve", ['reason' => 'Se cambió la bandeja de drenaje.'])->assertOk()->json();
        $this->assertSame(['RESOLVED', 'met', 'Se cambió la bandeja de drenaje.'], [$resolved['status'], $resolved['sla']['status'], $resolved['resolution']]);
        $this->postJson("/api/v1/tickets/{$id}/close")->assertOk()->assertJsonPath('status', 'CLOSED');
        $this->postJson("/api/v1/tickets/{$id}/comments", ['body' => 'Hola'])->assertStatus(422);
        $this->postJson("/api/v1/tickets/{$id}/reopen")->assertStatus(422);
        $this->travelTo($this->lima('2026-10-20 10:00'));
        $reopened = $this->postJson("/api/v1/tickets/{$id}/reopen", ['reason' => 'Volvió a gotear.'])->assertOk()->json();
        $this->assertSame(['REOPENED', null], [$reopened['status'], $reopened['sla']['resolved_at']]);
        // Desde ahora, con los 240 minutos hábiles que tenía (P2 Premium): martes 14:00.
        $this->assertTrue($this->lima('2026-10-20 14:00')->equalTo(Carbon::parse($reopened['sla']['resolution_due_at'])));
        $this->postJson("/api/v1/tickets/{$id}/start")->assertOk()->assertJsonPath('status', 'IN_PROGRESS');

        // El historial se lee con textos del catálogo de auditoría.
        foreach (DB::table('audit_logs')->where('entity_type', 'SupportTicket')->pluck('action')->unique() as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }
        $this->assertContains('Volvió a gotear.', array_column(array_column($reopened['history'], 'metadata'), 'note'));
    }

    public function test_listing_indicators_filters_and_export_work_on_the_whole_table(): void
    {
        $this->support();
        $agent = $this->makeUser('Soporte', self::AGENT);
        $this->as($agent);

        $customer = $this->makeCustomer('Banco Andino SAC');
        $ok = $this->ticket(['customer_id' => $customer, 'subject' => 'Consulta de certificado']);
        $late = $this->ticket(['subject' => 'Roedores en almacén']);
        $risk = $this->ticket(['subject' => 'Ruido en patio']);
        $done = $this->ticket(['subject' => 'Certificado de fumigación']);
        $this->ticket(['subject' => 'Sin clasificar']);
        foreach ([$ok, $late, $risk, $done] as $ticket) {
            $this->postJson("/api/v1/tickets/{$ticket['id']}/triage", ['priority' => 'P4'])->assertOk();
        }
        // Vencimientos a la medida para ver cada tramo (la base calcula con su propia hora).
        DB::table('support_tickets')->where('id', $late['id'])->update(['priority' => 'P1', 'sla_started_at' => now()->subHours(6), 'resolution_due_at' => now()->subHour()]);
        DB::table('support_tickets')->where('id', $risk['id'])->update(['priority' => 'P2', 'sla_started_at' => now()->subHours(9), 'resolution_due_at' => now()->addHour()]);
        $this->postJson("/api/v1/tickets/{$done['id']}/assign", ['assigned_user_id' => $agent->id])->assertOk();
        $this->postJson("/api/v1/tickets/{$done['id']}/start")->assertOk();
        $this->postJson("/api/v1/tickets/{$done['id']}/resolve", ['reason' => 'Enviado'])->assertOk();

        $summary = $this->getJson('/api/v1/tickets/summary')->assertOk()->json();
        $this->assertSame(['open' => 4, 'breached' => 1, 'risk' => 1, 'resolved' => 1], $summary);

        $this->assertSame(1, $this->getJson('/api/v1/tickets?sla=breached')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/tickets?sla=risk')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/tickets?sla=met')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/tickets?sla=none')->json('total'));
        $this->assertSame(4, $this->getJson('/api/v1/tickets?status=OPEN_ALL')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/tickets?priority=P1')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/tickets?search=Andino')->json('total'));
        // Orden por defecto: de la más urgente a la menos, sin prioridad al final.
        $this->assertSame(['P1', 'P2'], array_slice(array_column($this->getJson('/api/v1/tickets')->json('data'), 'priority'), 0, 2));
        $this->assertSame('Sin clasificar', $this->getJson('/api/v1/tickets?sort=priority&dir=desc')->json('data.0.subject'));

        // Exportar es un permiso aparte de ver.
        $viewer = $this->makeUser('Lectura', ['tickets.view']);
        $this->as($viewer);
        $this->getJson('/api/v1/tickets?export=1')->assertStatus(403);
        $this->postJson('/api/v1/exports', ['resource' => 'tickets', 'format' => 'xlsx', 'rows' => 5])->assertStatus(403);
        $this->as($agent);
        $this->getJson('/api/v1/tickets?export=1&per_page=500')->assertOk()->assertJsonPath('per_page', 200);
        $this->postJson('/api/v1/exports', ['resource' => 'tickets', 'format' => 'xlsx', 'rows' => 5])->assertOk();
    }

    public function test_scope_permissions_and_isolation(): void
    {
        $this->support();
        $agent = $this->makeUser('Soporte', self::AGENT);
        $this->as($agent);
        $mine = $this->ticket(['subject' => 'Del técnico']);
        $pool = $this->ticket(['subject' => 'En la bandeja']);

        // El técnico ve y atiende solo lo que tiene asignado.
        $tech = $this->makeUser('Técnico', ['tickets.view', 'tickets.manage'], 'OWN');
        $this->as($agent);
        $this->postJson("/api/v1/tickets/{$mine['id']}/triage", ['priority' => 'P3'])->assertOk();
        $this->postJson("/api/v1/tickets/{$mine['id']}/assign", ['assigned_user_id' => $tech->id])->assertOk();
        $this->as($tech);
        $this->assertSame([$mine['id']], array_column($this->getJson('/api/v1/tickets')->json('data'), 'id'));
        $this->getJson("/api/v1/tickets/{$pool['id']}")->assertNotFound();
        $this->postJson("/api/v1/tickets/{$pool['id']}/triage", ['priority' => 'P3'])->assertNotFound();
        $this->postJson('/api/v1/tickets', ['customer_id' => $this->makeCustomer(), 'subject' => 'X'])->assertStatus(403);

        // Alcance Equipo: los tickets del equipo y la bandeja que nadie tomó.
        $teamId = (string) Str::uuid();
        DB::table('teams')->insert(['id' => $teamId, 'tenant_id' => $this->tenantId, 'name' => 'Mesa de ayuda']);
        $desk = $this->makeUser('Mesa', ['tickets.view', 'tickets.manage'], 'TEAM');
        DB::table('tenant_users')->where('user_id', $desk->id)->update(['team_id' => $teamId]);
        $this->as($desk);
        $this->assertSame([$pool['id']], array_column($this->getJson('/api/v1/tickets')->json('data'), 'id'));
        $this->postJson("/api/v1/tickets/{$pool['id']}/triage", ['priority' => 'P4'])->assertOk();
        $this->postJson("/api/v1/tickets/{$pool['id']}/assign", ['assigned_user_id' => $desk->id])->assertOk();
        $this->assertSame($teamId, DB::table('support_tickets')->where('id', $pool['id'])->value('team_id'));

        // Ventas ve los tickets de los clientes de sus contratos.
        $seller = $this->makeUser('Ventas', ['tickets.view'], 'OWN');
        [, $customerId] = $this->makeContract($seller);
        $this->as($agent);
        $sold = $this->ticket(['customer_id' => $customerId, 'subject' => 'De un cliente vendido']);
        $this->as($seller);
        $this->assertSame([$sold['id']], array_column($this->getJson('/api/v1/tickets')->json('data'), 'id'));
        $this->postJson("/api/v1/tickets/{$sold['id']}/comments", ['body' => 'Hola'])->assertStatus(403);

        // Otra empresa: no existe. Sin el módulo: no se usa.
        $own = $this->tenantId;
        $this->makeTenant(['SOPORTE']);
        $foreign = (string) Str::uuid();
        DB::table('support_tickets')->insert([
            'id' => $foreign, 'tenant_id' => $this->tenantId, 'customer_id' => $this->makeCustomer(),
            'ticket_number' => 'TKT-OTRA', 'subject' => 'Ajeno', 'status' => 'OPEN',
        ]);
        DB::statement("SET app.tenant_id = '{$own}'");
        $this->tenantId = $own;
        $this->as($agent);
        $this->getJson("/api/v1/tickets/{$foreign}")->assertNotFound();
        DB::table('tenant_modules')->where('tenant_id', $own)->where('module_code', 'SOPORTE')->delete();
        $this->getJson('/api/v1/tickets')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_a_work_order_is_created_from_the_ticket_once(): void
    {
        $this->support();
        $agent = $this->makeUser('Soporte', self::AGENT);
        $this->as($agent);
        $service = $this->makeService('Mantenimiento de aire');
        $contracted = $this->makeContracted(['service_id' => $service]);
        $customerId = DB::table('contracted_services')->where('id', $contracted)->value('customer_id');
        $ticket = $this->ticket(['customer_id' => $customerId]);

        $this->postJson("/api/v1/tickets/{$ticket['id']}/work-order")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/tickets/{$ticket['id']}/triage", ['priority' => 'P2'])->assertOk();

        // Sin permiso de crear órdenes de trabajo, el botón no sirve.
        $limited = $this->makeUser('Atención', ['tickets.view', 'tickets.manage']);
        $this->as($limited);
        $this->postJson("/api/v1/tickets/{$ticket['id']}/work-order")->assertStatus(403);

        $this->as($agent);
        $created = $this->postJson("/api/v1/tickets/{$ticket['id']}/work-order")->assertCreated()->json();
        $order = DB::table('work_orders')->where('id', $created['work_order']['id'])->first();
        $this->assertSame([$contracted, 'HIGH', 'PLANNED'], [$order->contracted_service_id, $order->priority, $order->status]);
        $this->assertStringContainsString($ticket['number'], $order->title);
        $this->assertSame($created['work_order']['code'], $created['ticket']['work_order']['code']);
        $this->postJson("/api/v1/tickets/{$ticket['id']}/work-order")->assertStatus(422)->assertJsonValidationErrors('work_order');
    }

    public function test_warranty_claims_validate_coverage_before_approval(): void
    {
        $this->support();
        $agent = $this->makeUser('Soporte', self::AGENT);
        $this->as($agent);

        $service = $this->makeService('Instalación de equipo');
        DB::table('services')->where('id', $service)->update(['warranty_months' => 6, 'warranty_conditions' => 'Cubre defectos de ejecución.']);
        $contracted = $this->makeContracted(['service_id' => $service]);
        DB::table('contracted_services')->where('id', $contracted)->update(['start_date' => now()->subYear()->toDateString()]);
        $customerId = DB::table('contracted_services')->where('id', $contracted)->value('customer_id');

        // Última OT completada hace dos meses: dentro de los 6 meses.
        DB::table('work_orders')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'customer_id' => $customerId, 'contracted_service_id' => $contracted,
            'code' => 'OT-PRUEBA-1', 'title' => 'Instalación', 'status' => 'COMPLETED', 'priority' => 'NORMAL', 'completed_at' => now()->subMonths(2),
        ]);
        $ticket = $this->ticket(['customer_id' => $customerId]);

        $this->postJson('/api/v1/warranty-claims', [])->assertStatus(422)->assertJsonValidationErrors(['contracted_service_id', 'description']);
        $this->postJson('/api/v1/warranty-claims', ['contracted_service_id' => $contracted, 'description' => 'X', 'ticket_id' => $this->ticket()['id']])
            ->assertStatus(422)->assertJsonValidationErrors('ticket_id');

        $claim = $this->postJson('/api/v1/warranty-claims', [
            'contracted_service_id' => $contracted, 'ticket_id' => $ticket['id'], 'description' => 'Falla recurrente tras la instalación.',
        ])->assertCreated()->json();
        $this->assertMatchesRegularExpression('/^GAR-/', $claim['number']);
        $this->assertSame(['UNDER_REVIEW', true, '6 meses · Cubre defectos de ejecución.', 'Última OT completada (OT-PRUEBA-1)'], [
            $claim['status'], $claim['coverage']['valid'], $claim['coverage']['policy'], $claim['coverage']['base'],
        ]);
        $this->assertSame(now()->subMonths(2)->addMonthsNoOverflow(6)->toDateString(), $claim['coverage']['until']);
        // Un reclamo por ticket, y la ficha del ticket lo muestra.
        $this->postJson('/api/v1/warranty-claims', ['contracted_service_id' => $contracted, 'ticket_id' => $ticket['id'], 'description' => 'Otra'])
            ->assertStatus(422)->assertJsonValidationErrors('ticket_id');
        $this->assertSame($claim['number'], $this->getJson("/api/v1/tickets/{$ticket['id']}")->json('claim.number'));

        // Aprobar es de quien aprueba; resolver pide cómo.
        $limited = $this->makeUser('Revisión', ['warranty.view', 'warranty.manage']);
        $this->as($limited);
        $this->postJson("/api/v1/warranty-claims/{$claim['id']}/approve")->assertStatus(403);
        $this->as($agent);
        $this->postJson("/api/v1/warranty-claims/{$claim['id']}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->postJson("/api/v1/warranty-claims/{$claim['id']}/resolve")->assertStatus(422);
        $this->postJson("/api/v1/warranty-claims/{$claim['id']}/resolve", ['reason' => 'Se reemplazó la pieza.'])->assertOk()->assertJsonPath('status', 'RESOLVED');

        // Sin garantía en el servicio: fuera de cobertura, no se aprueba (tampoco en la base); se rechaza con motivo.
        $plain = $this->makeContracted();
        $outside = $this->postJson('/api/v1/warranty-claims', ['contracted_service_id' => $plain, 'description' => 'Se rompió.'])->assertCreated()->json();
        $this->assertSame([false, 'El servicio no tiene política de garantía'], [$outside['coverage']['valid'], $outside['coverage']['policy']]);
        $this->postJson("/api/v1/warranty-claims/{$outside['id']}/approve")->assertStatus(422)->assertJsonValidationErrors('coverage');
        $this->assertDatabaseRejects(fn () => DB::table('warranty_claims')->where('id', $outside['id'])->update(['status' => 'APPROVED']));
        $this->postJson("/api/v1/warranty-claims/{$outside['id']}/reject")->assertStatus(422);
        $this->postJson("/api/v1/warranty-claims/{$outside['id']}/reject", ['reason' => 'Mal uso del equipo.'])->assertOk()->assertJsonPath('reject_reason', 'Mal uso del equipo.');
        $this->postJson("/api/v1/warranty-claims/{$outside['id']}/check-coverage")->assertStatus(422);

        // Listado: filtros, búsqueda y orden en el servidor.
        $this->assertSame(1, $this->getJson('/api/v1/warranty-claims?coverage=covered')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/warranty-claims?status=REJECTED')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/warranty-claims?search=instalaci')->json('total'));
        $this->assertSame($claim['number'], $this->getJson('/api/v1/warranty-claims?sort=claim&dir=asc')->json('data.0.number'));
        $this->assertNotEmpty($this->getJson('/api/v1/warranty-claims/options')->json('contracted_services'));

        foreach (DB::table('audit_logs')->where('entity_type', 'WarrantyClaim')->pluck('action')->unique() as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }
    }

    public function test_the_business_calendar_and_sla_policies_are_edited_with_holidays(): void
    {
        $this->support();
        $admin = $this->makeUser('Administración', ['sla.view', 'sla.manage', ...self::AGENT]);
        $this->as($admin);

        $settings = $this->getJson('/api/v1/sla-settings')->assertOk()->json();
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_column($settings['policies'], 'priority'));
        $this->assertSame(['24X7', 'BUSINESS'], [$settings['policies'][0]['mode'], $settings['policies'][1]['mode']]);
        $this->assertNull($settings['calendar']['hours']['0']);
        $this->assertSame(['from' => '08:30', 'to' => '18:00'], $settings['calendar']['hours']['1']);
        $this->assertContains('2026-12-25', array_column($settings['holidays'], 'date'));
        $this->assertSame(50, $settings['calendar']['premium_percent']);
        $version = $settings['calendar']['lock_version'];

        // Ver no es cambiar.
        $viewer = $this->makeUser('Gerencia', ['sla.view']);
        $this->as($viewer);
        $this->postJson('/api/v1/sla-settings/holidays', ['date' => '2026-10-19', 'name' => 'X'])->assertStatus(403);
        $this->as($admin);

        $hours = ['1' => ['from' => '09:00', 'to' => '18:00'], '2' => ['from' => '09:00', 'to' => '18:00'], '3' => ['from' => '09:00', 'to' => '18:00'],
            '4' => ['from' => '09:00', 'to' => '18:00'], '5' => ['from' => '09:00', 'to' => '18:00'], '6' => null, '0' => null];
        $modes = ['P1' => '24X7', 'P2' => 'BUSINESS', 'P3' => 'BUSINESS', 'P4' => 'BUSINESS'];
        $this->putJson('/api/v1/sla-settings/calendar', ['hours' => [...$hours, '1' => ['from' => '18:00', 'to' => '09:00']], 'modes' => $modes])
            ->assertStatus(422)->assertJsonValidationErrors('hours.1');
        $saved = $this->putJson('/api/v1/sla-settings/calendar', ['hours' => $hours, 'modes' => $modes, 'lock_version' => $version])->assertOk()->json();
        $this->assertNull($saved['calendar']['hours']['6']);
        // Con una versión vieja no se pisa.
        $this->putJson('/api/v1/sla-settings/calendar', ['hours' => $hours, 'modes' => $modes, 'lock_version' => $version])->assertStatus(409);

        $policies = [
            ['priority' => 'P1', 'response_hours' => 0.5, 'resolution_hours' => 2], ['priority' => 'P2', 'response_hours' => 2, 'resolution_hours' => 8],
            ['priority' => 'P3', 'response_hours' => 4, 'resolution_hours' => 9], ['priority' => 'P4', 'response_hours' => 8, 'resolution_hours' => 72],
        ];
        $this->putJson('/api/v1/sla-settings/policies', ['policies' => [['priority' => 'P1', 'response_hours' => 5, 'resolution_hours' => 2]], 'premium_percent' => 50])
            ->assertStatus(422)->assertJsonValidationErrors('policies.0.resolution_hours');
        $updated = $this->putJson('/api/v1/sla-settings/policies', ['policies' => $policies, 'premium_percent' => 60])->assertOk()->json();
        $this->assertSame([30, 120, 540, 60], [$updated['policies'][0]['response_minutes'], $updated['policies'][0]['resolution_minutes'], $updated['policies'][2]['resolution_minutes'], $updated['calendar']['premium_percent']]);

        // Feriado el lunes 19: un P3 (9 h hábiles) creado el viernes 16 a las 17:00 vence el martes 20 a las 17:00.
        $this->postJson('/api/v1/sla-settings/holidays', ['date' => '2026-10-19', 'name' => 'Feriado regional'])->assertCreated();
        $this->postJson('/api/v1/sla-settings/holidays', ['date' => '2026-10-19', 'name' => 'Otra vez'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->travelTo($this->lima('2026-10-16 17:00'));
        $ticket = $this->ticket();
        $due = $this->postJson("/api/v1/tickets/{$ticket['id']}/triage", ['priority' => 'P3'])->assertOk()->json('sla.resolution_due_at');
        $this->assertTrue($this->lima('2026-10-20 17:00')->equalTo(Carbon::parse($due)));

        // Quitar el feriado lo retira (no se borra); la base no deja editarlo ni revivirlo.
        $holiday = DB::table('support_holidays')->where('tenant_id', $this->tenantId)->where('holiday_date', '2026-10-19')->value('id');
        $after = $this->deleteJson("/api/v1/sla-settings/holidays/{$holiday}")->assertOk()->json();
        $this->assertNotContains('2026-10-19', array_column($after['holidays'], 'date'));
        $this->assertNotNull(DB::table('support_holidays')->where('id', $holiday)->value('removed_at'));
        $this->deleteJson("/api/v1/sla-settings/holidays/{$holiday}")->assertNotFound();
        $this->assertDatabaseRejects(fn () => DB::table('support_holidays')->where('id', $holiday)->update(['removed_at' => null]));
        $this->assertDatabaseRejects(fn () => DB::table('support_holidays')->where('id', $holiday)->delete());

        foreach (DB::table('audit_logs')->where('entity_type', 'SlaSettings')->pluck('action')->unique() as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }
    }

    public function test_the_database_protects_tickets_and_their_conversation(): void
    {
        $this->support();
        $agent = $this->makeUser('Soporte', self::AGENT);
        $this->as($agent);
        $ticket = $this->ticket();
        $id = $ticket['id'];
        $comment = $ticket['comments'][0]['id'];

        $this->assertDatabaseRejects(fn () => DB::table('support_tickets')->where('id', $id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('support_tickets')->where('id', $id)->update(['status' => 'TRIAGED']));
        $this->assertDatabaseRejects(fn () => DB::table('support_tickets')->where('id', $id)->update(['status' => 'RESOLVED', 'priority' => 'P3']));
        $this->assertDatabaseRejects(fn () => DB::table('support_tickets')->where('id', $id)->update(['priority' => 'P3', 'status' => 'IN_PROGRESS']));
        $this->assertDatabaseRejects(fn () => DB::table('support_tickets')->where('id', $id)->update(['paused_at' => now()]));
        $this->assertDatabaseRejects(fn () => DB::table('support_ticket_comments')->where('id', $comment)->update(['body' => 'Otro texto']));
        $this->assertDatabaseRejects(fn () => DB::table('support_ticket_comments')->where('id', $comment)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('support_ticket_comments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'ticket_id' => $id, 'author_type' => 'PORTAL', 'internal' => true, 'body' => 'Nota del portal',
        ]));

        // Ediciones simultáneas: quien tenía la ficha con la versión anterior no la pisa.
        $this->withHeaders(['If-Match' => '99'])->postJson("/api/v1/tickets/{$id}/triage", ['priority' => 'P3'])->assertStatus(409);
    }
}
