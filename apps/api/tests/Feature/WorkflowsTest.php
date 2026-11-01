<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditCatalog;
use App\Services\Workflows;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Workflows: armar reglas (disparador, condición del catálogo, acciones), que corran al guardar la
 * operación una sola vez por evento, que avisen solo a quien puede ver el registro, el aviso en el
 * portal, el historial, los permisos, otra empresa y lo que garantiza la base.
 */
class WorkflowsTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const ADMIN = [
        'workflows.view', 'workflows.manage', 'workflows.export',
        'tickets.view', 'tickets.create', 'tickets.manage', 'tickets.approve',
    ];

    private function roleOf(User $user): string
    {
        return (string) DB::table('user_roles')->where('user_id', $user->id)->value('role_id');
    }

    private function ticket(): array
    {
        return $this->postJson('/api/v1/tickets', [
            'customer_id' => $this->makeCustomer(), 'channel' => 'PHONE', 'category' => 'INCIDENT',
            'subject' => 'Aire acondicionado gotea', 'description' => 'Gotea en la sala de reuniones.',
        ])->assertCreated()->json();
    }

    public function test_a_rule_runs_after_saving_and_notifies_only_who_can_see_the_record(): void
    {
        $this->makeTenant(['SOPORTE']);
        $admin = $this->makeUser('Admin', self::ADMIN);
        $manager = $this->makeUser('Gerencia', ['tickets.view']);
        // Ve tickets pero es de otro rol: la regla no le avisa.
        $support = $this->makeUser('Soporte', ['tickets.view']);
        // Es de Gerencia pero solo ve lo suyo: el ticket no es suyo, no le avisa.
        $ownOnly = $this->makeUser('Gerencia (propios)', ['tickets.view'], 'OWN');
        DB::table('user_roles')->where('user_id', $ownOnly->id)->update(['role_id' => $this->roleOf($manager)]);
        DB::table('role_scopes')->where('role_id', $this->roleOf($manager))->where('resource', 'tickets')->update(['scope' => 'OWN']);
        $this->as($admin);

        // Validación: campo del catálogo, operador de su tipo, valor de la lista, rol de la empresa.
        $this->postJson('/api/v1/workflows', ['name' => '', 'trigger' => 'inventado', 'actions' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'trigger', 'actions']);
        $base = ['name' => 'Ticket crítico: avisar a Gerencia', 'trigger' => 'ticket.triaged'];
        $this->postJson('/api/v1/workflows', [...$base, 'condition' => ['field' => 'amount', 'op' => '>', 'value' => 1], 'actions' => [['type' => 'notify_owner', 'text' => 'x']]])
            ->assertStatus(422)->assertJsonValidationErrors('condition.field');
        $this->postJson('/api/v1/workflows', [...$base, 'condition' => ['field' => 'priority', 'op' => '>', 'value' => 'P1'], 'actions' => [['type' => 'notify_owner', 'text' => 'x']]])
            ->assertStatus(422)->assertJsonValidationErrors('condition.op');
        $this->postJson('/api/v1/workflows', [...$base, 'condition' => ['field' => 'priority', 'op' => '=', 'value' => 'P9'], 'actions' => [['type' => 'notify_owner', 'text' => 'x']]])
            ->assertStatus(422)->assertJsonValidationErrors('condition.value');
        $this->postJson('/api/v1/workflows', [...$base, 'actions' => [['type' => 'notify_role', 'role_id' => (string) Str::uuid(), 'text' => 'x']]])
            ->assertStatus(422)->assertJsonValidationErrors('actions.0.role_id');

        $rule = $this->postJson('/api/v1/workflows', [...$base,
            'condition' => ['field' => 'priority', 'op' => '=', 'value' => 'P1'],
            'actions' => [['type' => 'notify_role', 'role_id' => $this->roleOf($manager), 'text' => 'Ticket P1 abierto']],
        ])->assertCreated()->json();
        $this->assertSame(['ticket.triaged', 'Ticket clasificado', true, 0, 'Gerencia'], [$rule['trigger'], $rule['trigger_label'], $rule['active'], $rule['runs'], $rule['actions'][0]['role']]);

        // P1: se cumple y avisa a Gerencia (que ve tickets), no a quien no los ve.
        $critical = $this->ticket();
        $this->postJson("/api/v1/tickets/{$critical['id']}/triage", ['priority' => 'P1', 'category' => 'INCIDENT'])->assertOk();
        $this->assertFalse(DB::table('notifications')->where('event', 'workflow.notify')->whereIn('user_id', [$support->id, $ownOnly->id, $manager->id])->exists());
        $this->assertStringContainsString('Nadie de Gerencia puede ver este registro', (string) DB::table('workflow_runs')->value('result'));

        // Con alcance de toda la empresa, Gerencia sí lo ve y le llega.
        DB::table('role_scopes')->where('role_id', $this->roleOf($manager))->where('resource', 'tickets')->update(['scope' => 'TENANT']);
        $second = $this->ticket();
        $this->postJson("/api/v1/tickets/{$second['id']}/triage", ['priority' => 'P1', 'category' => 'INCIDENT'])->assertOk();
        $notices = DB::table('notifications')->where('event', 'workflow.notify')->get();
        $this->assertEqualsCanonicalizing([$manager->id, $ownOnly->id], $notices->pluck('user_id')->all());
        $notice = $notices->first();
        $this->assertSame('Ticket crítico: avisar a Gerencia', $notice->subject);
        $this->assertStringStartsWith('Ticket P1 abierto · TKT-', $notice->body);
        $this->assertSame("/tickets/{$second['id']}", $notice->link);

        // P3: no se cumple; queda en el historial como "no cumplida".
        $normal = $this->ticket();
        $this->postJson("/api/v1/tickets/{$normal['id']}/triage", ['priority' => 'P3', 'category' => 'REQUEST'])->assertOk();

        $runs = $this->getJson('/api/v1/workflow-runs?sort=date&dir=desc')->assertOk()->json('data');
        $this->assertCount(3, $runs);
        $byResult = array_column($runs, 'status', 'result');
        $this->assertSame('SKIPPED', $byResult['Condición no cumplida']);
        $this->assertSame('DONE', $byResult['Aviso a Gerencia (2)']);
        $this->getJson('/api/v1/workflows')->assertJsonPath('rules.0.runs', 3);

        // Desactivada no corre.
        $this->postJson("/api/v1/workflows/{$rule['id']}/toggle", ['active' => false])->assertOk()->assertJsonPath('active', false);
        $other = $this->ticket();
        $this->postJson("/api/v1/tickets/{$other['id']}/triage", ['priority' => 'P1', 'category' => 'INCIDENT'])->assertOk();
        $this->assertSame(3, DB::table('workflow_runs')->where('workflow_definition_id', $rule['id'])->count());

        // Auditoría de las reglas, con su texto.
        $actions = DB::table('audit_logs')->where('entity_id', $rule['id'])->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['workflow.create', 'workflow.deactivate'], $actions);
        foreach ($actions as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }
    }

    public function test_owner_and_portal_actions_and_runs_once_per_event(): void
    {
        $this->makeTenant(['SOPORTE']);
        $admin = $this->makeUser('Admin', self::ADMIN);
        $this->as($admin);
        $this->postJson('/api/v1/workflows', [
            'name' => 'Ticket creado: avisar al cliente', 'trigger' => 'ticket.created',
            'actions' => [
                ['type' => 'portal_notice', 'text' => 'Recibimos tu ticket. Te escribiremos pronto.'],
                ['type' => 'notify_owner', 'text' => 'Nuevo ticket'],
            ],
        ])->assertCreated();

        $ticket = $this->ticket();
        $notice = DB::table('portal_notices')->where('tenant_id', $this->tenantId)->first();
        $this->assertSame([$ticket['customer']['id'] ?? DB::table('support_tickets')->where('id', $ticket['id'])->value('customer_id'), 'Recibimos tu ticket. Te escribiremos pronto.'], [$notice->customer_id, $notice->body]);
        // Lo creó el propio responsable: no se avisa a sí mismo.
        $run = DB::table('workflow_runs')->first();
        $this->assertSame('Aviso publicado en el portal · El responsable hizo la acción: no se le avisa', $run->result);

        // El mismo evento otra vez no vuelve a correr.
        $auditId = DB::table('audit_logs')->where('entity_id', $ticket['id'])->where('action', 'ticket.create')->value('id');
        Workflows::fire($this->tenantId, $admin->id, 'ticket.created', $ticket['id'], $auditId);
        $this->assertSame(1, DB::table('workflow_runs')->count());
        $this->assertSame(1, DB::table('portal_notices')->where('tenant_id', $this->tenantId)->count());

        // Un disparador sin cliente no puede publicar en el portal.
        $this->postJson('/api/v1/workflows', ['name' => 'Horas', 'trigger' => 'time_entry.approved', 'actions' => [['type' => 'portal_notice', 'text' => 'x']]])
            ->assertStatus(422)->assertJsonValidationErrors('actions.0.type');
    }

    public function test_conditions_by_field_type(): void
    {
        $this->assertTrue(Workflows::matches(null, []));
        $this->assertTrue(Workflows::matches(['field' => 'amount', 'op' => '>', 'value' => 20000], ['amount' => 25000.0]));
        $this->assertFalse(Workflows::matches(['field' => 'amount', 'op' => '>', 'value' => 20000], ['amount' => 20000.0]));
        $this->assertTrue(Workflows::matches(['field' => 'amount', 'op' => '<=', 'value' => 100], ['amount' => 100.0]));
        $this->assertTrue(Workflows::matches(['field' => 'priority', 'op' => '!=', 'value' => 'P1'], ['priority' => 'P2']));
        $this->assertTrue(Workflows::matches(['field' => 'billable', 'op' => '=', 'value' => true], ['billable' => true]));
        $this->assertFalse(Workflows::matches(['field' => 'billable', 'op' => '=', 'value' => false], ['billable' => true]));
        // Cada disparador tiene su acción de auditoría y su catálogo de campos.
        foreach (Workflows::TRIGGERS as $key => [, $action]) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS + ['work_order.complete' => 1, 'quotation.approve' => 1, 'quotation.reject' => 1, 'time_entry.approve' => 1, 'contract.activate' => 1, 'invoice.issue' => 1, 'crm_lead.create' => 1, 'crm_opportunity.win' => 1], $key);
            $this->assertArrayHasKey($key, Workflows::FIELDS);
        }
    }

    public function test_permissions_other_tenants_and_database_guards(): void
    {
        $this->makeTenant(['SOPORTE']);
        $this->as($this->makeUser('Admin', self::ADMIN));
        $rule = $this->postJson('/api/v1/workflows', ['name' => 'Regla', 'trigger' => 'ticket.created', 'actions' => [['type' => 'notify_owner', 'text' => 'Nuevo']]])->assertCreated()->json();

        // Gerencia: ve, no edita.
        $this->as($this->makeUser('Gerencia', ['workflows.view']));
        $this->getJson('/api/v1/workflows')->assertOk()->assertJsonPath('rules.0.id', $rule['id']);
        $this->postJson("/api/v1/workflows/{$rule['id']}/toggle", ['active' => false])->assertForbidden();
        $this->putJson("/api/v1/workflows/{$rule['id']}", ['name' => 'X', 'trigger' => 'ticket.created', 'actions' => [['type' => 'notify_owner', 'text' => 'x']]])->assertForbidden();
        $this->getJson('/api/v1/workflow-runs?export=1')->assertForbidden();

        // Sin permiso: nada.
        $this->as($this->makeUser('Ventas', ['quotations.view']));
        $this->getJson('/api/v1/workflows')->assertForbidden();

        // La base: las ejecuciones no se cambian ni se borran; las reglas no se borran.
        DB::table('workflow_runs')->insert(['tenant_id' => $this->tenantId, 'workflow_definition_id' => $rule['id'], 'entity_id' => (string) Str::uuid(), 'event_key' => 'x', 'status' => 'DONE']);
        $this->assertDatabaseRejects(fn () => DB::table('workflow_runs')->where('workflow_definition_id', $rule['id'])->update(['result' => 'otro']));
        $this->assertDatabaseRejects(fn () => DB::table('workflow_runs')->where('workflow_definition_id', $rule['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('workflow_definitions')->where('id', $rule['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('workflow_runs')->insert(['tenant_id' => $this->tenantId, 'workflow_definition_id' => $rule['id'], 'entity_id' => (string) Str::uuid(), 'event_key' => 'x', 'status' => 'DONE']));

        // Otra empresa: no existe.
        $this->makeTenant(['SOPORTE']);
        $this->as($this->makeUser('Admin B', self::ADMIN));
        $this->getJson('/api/v1/workflows')->assertJsonPath('rules', []);
        $this->postJson("/api/v1/workflows/{$rule['id']}/toggle", ['active' => false])->assertNotFound();
        $this->getJson('/api/v1/workflow-runs')->assertJsonPath('total', 0);
    }

    public function test_new_tenants_get_workflows_with_the_base_plan(): void
    {
        $codes = \App\Modules\Subscription\Support\ModulePermissions::codesFor([]);
        foreach (['workflows.view', 'workflows.manage', 'workflows.export'] as $code) {
            $this->assertContains($code, $codes);
        }
    }
}
