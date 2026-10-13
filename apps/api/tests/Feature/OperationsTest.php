<?php

namespace Tests\Feature;

use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Operaciones, primera parte: Servicios contratados y Órdenes de trabajo (asignación sin
 * choques de agenda, checklist obligatorio, alcance por rol, permisos por acción).
 */
class OperationsTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const ADMIN = [
        'contracted_services.view', 'contracted_services.manage', 'contracted_services.export',
        'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve', 'work_orders.export',
        'contracts.view', 'core.view',
    ];

    /** Operaciones / PM de la matriz del prototipo: planifica y controla, no exporta. */
    private const OPERATIONS = [
        'contracted_services.view', 'contracted_services.manage',
        'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve',
    ];

    /** Técnico: ejecuta sus órdenes. */
    private const TECHNICIAN = ['work_orders.view', 'work_orders.manage'];

    /** Gerencia: ve, asigna y exporta; no ejecuta. */
    private const MANAGEMENT = ['contracted_services.view', 'contracted_services.manage', 'work_orders.view', 'work_orders.approve', 'work_orders.export'];

    private const SALES = ['contracted_services.view', 'contracts.view'];

    // ------------------------------------------------------------------ Servicios contratados

    public function test_contracted_service_follows_its_states_and_needs_reasons(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $id = $this->makeContracted(['status' => 'PENDING']);
        $this->as($admin);

        $this->postJson("/api/v1/contracted-services/{$id}/suspend", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/contracted-services/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('history.0.action', 'contracted_service.activate')->assertJsonPath('history.0.metadata.from', 'PENDING');
        $this->postJson("/api/v1/contracted-services/{$id}/activate")->assertStatus(422);

        $this->postJson("/api/v1/contracted-services/{$id}/suspend")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/contracted-services/{$id}/suspend", ['reason' => 'Falta de pago'])->assertOk()
            ->assertJsonPath('status', 'SUSPENDED')->assertJsonPath('status_reason', 'Falta de pago');
        $this->postJson("/api/v1/contracted-services/{$id}/complete")->assertStatus(422);
        $this->postJson("/api/v1/contracted-services/{$id}/reactivate")->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('status_reason', null);

        // Con una orden de trabajo abierta no se completa ni se cancela.
        $order = $this->order($admin, $id);
        $this->postJson("/api/v1/contracted-services/{$id}/complete")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/contracted-services/{$id}/cancel", ['reason' => 'Baja'])->assertStatus(422);
        $this->postJson("/api/v1/work-orders/{$order['id']}/cancel", ['reason' => 'Duplicada'])->assertOk();
        $this->postJson("/api/v1/contracted-services/{$id}/complete")->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->postJson("/api/v1/contracted-services/{$id}/cancel", ['reason' => 'Baja'])->assertStatus(422);

        $other = $this->makeContracted(['status' => 'PENDING']);
        $this->postJson("/api/v1/contracted-services/{$other}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/contracted-services/{$other}/cancel", ['reason' => 'El cliente desistió'])->assertOk()->assertJsonPath('status', 'CANCELLED');

        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', 'ContractedService')->where('entity_id', $other)->where('action', 'contracted_service.cancel')->count());
    }

    public function test_contracted_service_starts_only_under_a_contract_in_force(): void
    {
        $this->makeTenant();
        $this->as($this->makeUser('Admin', self::ADMIN));
        $contract = $this->makeContract(status: 'SUSPENDED');
        $pending = $this->makeContracted(['status' => 'PENDING'], $contract);

        $this->postJson("/api/v1/contracted-services/{$pending}/activate")->assertStatus(422)->assertJsonValidationErrors('status');
        DB::table('contracts')->where('id', $contract[0])->update(['status' => 'ACTIVE']);
        $this->postJson("/api/v1/contracted-services/{$pending}/activate")->assertOk();

        $this->postJson("/api/v1/contracted-services/{$pending}/suspend", ['reason' => 'Pausa'])->assertOk();
        DB::table('contracts')->where('id', $contract[0])->update(['status' => 'TERMINATED']);
        $this->postJson("/api/v1/contracted-services/{$pending}/reactivate")->assertStatus(422);
    }

    public function test_recurring_service_gets_one_instance_that_follows_it(): void
    {
        $this->makeTenant();
        $this->as($this->makeUser('Admin', self::ADMIN));
        $id = $this->makeContracted(['status' => 'PENDING', 'recurring' => true, 'service_id' => $this->makeService('Soporte mensual', true)]);
        $instance = fn () => DB::table('recurring_service_instances')->where('contracted_service_id', $id)->get();

        $this->postJson("/api/v1/contracted-services/{$id}/activate")->assertOk()
            ->assertJsonPath('recurring_instance.status', 'ACTIVE')->assertJsonPath('recurring_instance.frequency', 'MONTHLY')
            ->assertJsonPath('service.frequency', 'MONTHLY')->assertJsonPath('history.0.metadata.note', 'Instancia recurrente creada');
        $this->postJson("/api/v1/contracted-services/{$id}/suspend", ['reason' => 'Pausa'])->assertOk();
        $this->assertSame(['SUSPENDED'], $instance()->pluck('status')->all());
        $this->postJson("/api/v1/contracted-services/{$id}/reactivate")->assertOk();
        $this->assertSame(['ACTIVE'], $instance()->pluck('status')->all());
        $this->postJson("/api/v1/contracted-services/{$id}/cancel", ['reason' => 'Baja'])->assertOk();
        $this->assertSame(['CANCELLED'], $instance()->pluck('status')->all());

        // La base no admite una segunda instancia del mismo servicio, ni un suspendido sin motivo.
        $this->assertDatabaseRejects(fn () => DB::table('recurring_service_instances')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'contracted_service_id' => $id, 'frequency' => 'MONTHLY',
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('contracted_services')->where('id', $id)->update(['status' => 'SUSPENDED', 'status_reason' => ' ']));
        $this->assertDatabaseRejects(fn () => DB::table('contracted_services')->where('id', $id)->update(['status' => 'OTRO']));

        // Un servicio no recurrente no crea instancia.
        $single = $this->makeContracted(['status' => 'PENDING']);
        $this->postJson("/api/v1/contracted-services/{$single}/activate")->assertOk()->assertJsonPath('recurring_instance', null);
    }

    public function test_contracted_services_list_searches_filters_sorts_and_counts(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $andes = $this->makeContract(customerId: $this->makeCustomer('Minera Los Andes SAC'));
        $sur = $this->makeContract(customerId: $this->makeCustomer('Agroindustrias del Sur SAC'));
        $maintenance = $this->makeContracted(['service_id' => $this->makeService('Mantenimiento preventivo')], $andes);
        $this->makeContracted(['service_id' => $this->makeService('Consultoría técnica'), 'status' => 'PENDING'], $sur);
        $this->order($admin, $maintenance);

        $list = $this->getJson('/api/v1/contracted-services')->assertOk()->assertJsonPath('total', 2);
        $row = collect($list->json('data'))->firstWhere('id', $maintenance);
        $this->assertSame(['Mantenimiento preventivo', 1, 380.0, 2.0], [$row['service']['name'], $row['work_orders_count'], (float) $row['price'], (float) $row['quantity']]);
        $this->assertSame('Minera Los Andes SAC', $row['customer']['party']['organization']['legal_name']);
        $this->assertNotNull($row['contract']['contract_number']);

        $this->getJson('/api/v1/contracted-services?status=PENDING')->assertJsonPath('total', 1)->assertJsonPath('data.0.service.name', 'Consultoría técnica');
        $this->getJson('/api/v1/contracted-services?search=agroindustrias')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/contracted-services?search=mantenimiento')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/contracted-services?search='.$row['contract']['contract_number'])->assertJsonPath('total', 1);
        $this->getJson('/api/v1/contracted-services?sort=service&dir=asc')->assertJsonPath('data.0.service.name', 'Consultoría técnica');
        $this->getJson('/api/v1/contracted-services?sort=status&dir=asc')->assertJsonPath('data.0.status', 'ACTIVE');

        $filter = fn (array $f) => '/api/v1/contracted-services?filters='.urlencode(json_encode([$f]));
        $this->getJson($filter(['id' => 'service', 'type' => 'text', 'operator' => 'contains', 'value' => 'andes']))->assertJsonPath('total', 1);
        $this->getJson($filter(['id' => 'price', 'type' => 'number', 'operator' => 'greater', 'value' => '500']))->assertJsonPath('total', 0);
        $this->getJson($filter(['id' => 'start', 'type' => 'date', 'operator' => 'today', 'value' => '']))->assertJsonPath('total', 2);

        $this->getJson("/api/v1/contracted-services/{$maintenance}")->assertOk()->assertJsonCount(1, 'work_orders')->assertJsonPath('work_orders.0.title', 'Mantenimiento de planta');
        $this->getJson('/api/v1/contracted-services?per_page=5000')->assertJsonPath('per_page', 200);
    }

    public function test_contracted_services_respect_scope_permissions_module_and_company(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $ana = $this->makeUser('Ana', self::SALES, 'OWN');
        $beto = $this->makeUser('Beto', self::SALES, null);
        $ofAna = $this->makeContracted(contract: $this->makeContract($ana));
        $ofBeto = $this->makeContracted(contract: $this->makeContract($beto), seller: $beto);
        $this->order($admin, $ofAna);

        // Ventas ve los servicios de sus contratos; los ajenos no existen para ella.
        $this->as($ana);
        $this->getJson('/api/v1/contracted-services')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ofAna)
            // No tiene permiso sobre órdenes de trabajo ni las alcanza: no se le cuentan.
            ->assertJsonPath('data.0.work_orders_count', 0);
        $this->getJson("/api/v1/contracted-services/{$ofBeto}")->assertStatus(404);
        $this->postJson("/api/v1/contracted-services/{$ofAna}/suspend", ['reason' => 'x'])->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->getJson('/api/v1/contracted-services?export=1')->assertStatus(403);
        $this->postJson('/api/v1/exports', ['resource' => 'contracted_services', 'format' => 'csv', 'rows' => 1])->assertStatus(403);

        $this->as($beto);
        $this->getJson('/api/v1/contracted-services')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ofBeto);

        // Gerencia gestiona pero no exporta; fuera de alcance responde 404, no 409.
        $manager = $this->makeUser('Gerencia', self::MANAGEMENT, 'OWN');
        $this->as($manager);
        $this->withHeaders(['If-Match' => '99'])->postJson("/api/v1/contracted-services/{$ofAna}/suspend", ['reason' => 'x'])->assertStatus(404);
        $this->flushHeaders();

        $this->as($admin);
        $this->getJson('/api/v1/contracted-services?export=1')->assertOk()->assertJsonPath('total', 2);
        $this->postJson('/api/v1/exports', ['resource' => 'contracted_services', 'format' => 'xlsx', 'rows' => 2])->assertOk();
        $this->withHeaders(['If-Match' => '0'])->postJson("/api/v1/contracted-services/{$ofAna}/suspend", ['reason' => 'Pausa'])->assertOk()->assertJsonPath('lock_version', 1);
        $this->withHeaders(['If-Match' => '0'])->postJson("/api/v1/contracted-services/{$ofAna}/reactivate")->assertStatus(409);
        $this->flushHeaders();

        // Otra empresa no ve nada de esta; sin el módulo, todo cerrado.
        $this->makeTenant();
        $this->as($this->makeUser('Otra empresa', self::ADMIN));
        $this->getJson('/api/v1/contracted-services')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/contracted-services/{$ofAna}")->assertStatus(404);

        $this->makeTenant(['CONTRATOS']);
        $this->as($this->makeUser('Sin módulo', self::ADMIN));
        $this->getJson('/api/v1/contracted-services')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
        $this->getJson('/api/v1/work-orders')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
    }

    // ------------------------------------------------------------------ Órdenes de trabajo

    public function test_work_order_is_born_from_an_active_contracted_service_with_its_checklist(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $technician = $this->makeUser('Tito Técnico', self::TECHNICIAN, 'OWN');
        $branch = $this->makeBranch('Lima');
        $contracted = $this->makeContracted(['branch_id' => $branch]);
        $pending = $this->makeContracted(['status' => 'PENDING']);
        $template = $this->makeTemplate();
        $this->as($admin);

        $this->postJson('/api/v1/work-orders', [])->assertStatus(422)->assertJsonValidationErrors(['contracted_service_id', 'title']);
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $pending, 'title' => 'X'])->assertStatus(422)->assertJsonValidationErrors('contracted_service_id');
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => (string) Str::uuid(), 'title' => 'X'])->assertStatus(422);
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $contracted, 'title' => 'X', 'priority' => 'URGENTE'])->assertStatus(422);
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $contracted, 'title' => 'X', 'checklist_id' => (string) Str::uuid()])->assertStatus(422);
        $this->postJson('/api/v1/work-orders', [
            'contracted_service_id' => $contracted, 'title' => 'X', 'scheduled_start' => $this->at(2, 12), 'scheduled_end' => $this->at(2, 9),
        ])->assertStatus(422)->assertJsonValidationErrors('scheduled_end');

        $order = $this->order($admin, $contracted, [
            'priority' => 'HIGH', 'scheduled_start' => $this->at(2, 9), 'location' => 'Planta Callao', 'checklist_id' => $template, 'description' => 'Llevar repuestos',
        ]);
        $year = date('Y');
        $this->assertSame(["OT-{$year}-0001", 'PLANNED', 'HIGH', 'Planta Callao', null], [$order['code'], $order['status'], $order['priority'], $order['location'], $order['assignee']]);
        $this->assertSame(['Checklist de instalación', 'PENDING', 3, 0, 2], array_values(array_intersect_key($order['checklist'], array_flip(['name', 'status', 'total', 'done', 'required_pending']))));
        $this->assertSame(['Verificación inicial', 'Ejecución del servicio', 'Foto del equipo'], array_column($order['checklist_items'], 'label'));
        $this->assertSame('Mantenimiento preventivo', $order['service']['name']);
        $this->assertNotNull($order['contract']['contract_number']);
        // Nace en la sede de su servicio contratado.
        $this->assertSame($branch, DB::table('work_orders')->where('id', $order['id'])->value('branch_id'));

        // Cambiar la plantilla después no altera el checklist de la orden.
        DB::table('checklist_items')->where('checklist_id', $template)->update(['label' => 'Otro texto']);
        $this->getJson("/api/v1/work-orders/{$order['id']}")->assertJsonPath('checklist_items.0.label', 'Verificación inicial');

        // Con técnico al crear nace asignada; el técnico debe poder ejecutar órdenes.
        $second = $this->order($admin, $contracted, ['assigned_user_id' => $technician->id, 'scheduled_start' => $this->at(3, 9)]);
        $this->assertSame(["OT-{$year}-0002", 'ASSIGNED', 'Tito Técnico', null], [$second['code'], $second['status'], $second['assignee']['name'], $second['checklist']]);
        $this->assertSame(['work_order.assign', 'work_order.create'], array_column($second['history'], 'action'));
        $seller = $this->makeUser('Vendedora', self::SALES);
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $contracted, 'title' => 'X', 'assigned_user_id' => $seller->id])
            ->assertStatus(422)->assertJsonValidationErrors('assigned_user_id');

        // El formulario ofrece técnicos, plantillas y servicios activos.
        $options = $this->getJson('/api/v1/work-orders/options')->assertOk();
        $this->assertSame(['Admin', 'Tito Técnico'], $options->json('technicians.*.name'));
        $this->assertSame([['id' => $template, 'name' => 'Checklist de instalación', 'items' => 3]], $options->json('templates'));
        $this->assertSame([$contracted], $options->json('contracted_services.*.id'));
        $this->assertSame('Minera Los Andes SAC', $options->json('contracted_services.0.customer'));
    }

    public function test_work_order_follows_its_states_and_completing_needs_the_required_checklist(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $technician = $this->makeUser('Tito Técnico', self::TECHNICIAN, 'OWN');
        $contracted = $this->makeContracted();
        $order = $this->order($admin, $contracted, ['checklist_id' => $this->makeTemplate(), 'scheduled_start' => $this->at(2, 9)]);
        $id = $order['id'];
        $items = array_column($order['checklist_items'], 'id', 'label');

        // Planificada: no se inicia sin técnico ni se marca su checklist.
        $this->postJson("/api/v1/work-orders/{$id}/start")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/{$items['Verificación inicial']}", ['done' => true])->assertStatus(422);
        $this->postJson("/api/v1/work-orders/{$id}/assign", [])->assertStatus(422)->assertJsonValidationErrors('assigned_user_id');
        $this->postJson("/api/v1/work-orders/{$id}/assign", ['assigned_user_id' => $technician->id])->assertOk()
            ->assertJsonPath('status', 'ASSIGNED')->assertJsonPath('assignee.name', 'Tito Técnico')
            ->assertJsonPath('history.0.metadata.note', 'Asignada a Tito Técnico');

        // El técnico ejecuta.
        $this->as($technician);
        $this->postJson("/api/v1/work-orders/{$id}/complete")->assertStatus(422);
        $this->postJson("/api/v1/work-orders/{$id}/start")->assertOk()->assertJsonPath('status', 'IN_PROGRESS');
        $this->assertNotNull(DB::table('work_orders')->where('id', $id)->value('started_at'));
        $this->postJson("/api/v1/work-orders/{$id}/cancel", ['reason' => 'x'])->assertStatus(422);

        $this->postJson("/api/v1/work-orders/{$id}/block")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/work-orders/{$id}/block", ['reason' => 'Falta un repuesto'])->assertOk()
            ->assertJsonPath('status', 'BLOCKED')->assertJsonPath('blocked_reason', 'Falta un repuesto');
        $this->postJson("/api/v1/work-orders/{$id}/complete")->assertStatus(422);
        $this->postJson("/api/v1/work-orders/{$id}/unblock")->assertOk()->assertJsonPath('status', 'IN_PROGRESS')->assertJsonPath('blocked_reason', null);

        // Completar exige los ítems obligatorios.
        $this->postJson("/api/v1/work-orders/{$id}/complete")->assertStatus(422)->assertJsonValidationErrors('checklist')
            ->assertJsonPath('errors.checklist.1', 'Verificación inicial')->assertJsonPath('errors.checklist.2', 'Ejecución del servicio');
        $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/{$items['Verificación inicial']}", ['done' => true, 'evidence_note' => 'Registrado en campo'])
            ->assertOk()->assertJsonPath('checklist.status', 'IN_PROGRESS')->assertJsonPath('checklist.done', 1)
            ->assertJsonPath('checklist_items.0.done_by', 'Tito Técnico')->assertJsonPath('checklist_items.0.evidence_note', 'Registrado en campo');
        $this->postJson("/api/v1/work-orders/{$id}/complete")->assertStatus(422);
        $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/{$items['Ejecución del servicio']}", ['done' => true])
            ->assertOk()->assertJsonPath('checklist.status', 'COMPLETED')->assertJsonPath('checklist.required_pending', 0);
        // Desmarcar un obligatorio lo devuelve a "En curso".
        $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/{$items['Ejecución del servicio']}", ['done' => false])
            ->assertOk()->assertJsonPath('checklist.status', 'IN_PROGRESS')->assertJsonPath('checklist_items.1.done_by', null);
        $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/{$items['Ejecución del servicio']}", ['done' => true])->assertOk();
        $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/".Str::uuid(), ['done' => true])->assertStatus(404);

        $done = $this->postJson("/api/v1/work-orders/{$id}/complete")->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->assertNotNull($done->json('completed_at'));
        // Cerrada: no se edita, no se reasigna, no cambia su checklist.
        $this->putJson("/api/v1/work-orders/{$id}", ['title' => 'Otro'])->assertStatus(422);
        $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/{$items['Foto del equipo']}", ['done' => true])->assertStatus(422);
        $this->as($admin);
        $this->postJson("/api/v1/work-orders/{$id}/assign", ['assigned_user_id' => $admin->id])->assertStatus(422);

        // Cancelar pide motivo y solo desde planificada, asignada o bloqueada.
        $other = $this->order($admin, $contracted)['id'];
        $this->postJson("/api/v1/work-orders/{$other}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/work-orders/{$other}/cancel", ['reason' => 'Duplicada'])->assertOk()
            ->assertJsonPath('status', 'CANCELLED')->assertJsonPath('cancel_reason', 'Duplicada');

        $actions = DB::table('audit_logs')->where('entity_type', 'WorkOrder')->where('entity_id', $id)->pluck('action')->unique()->sort()->values()->all();
        $this->assertSame([
            'work_order.assign', 'work_order.block', 'work_order.checklist.check', 'work_order.checklist.uncheck',
            'work_order.complete', 'work_order.create', 'work_order.start', 'work_order.unblock',
        ], $actions);
        // Todo lo que se registra tiene su texto en la pantalla Auditoría.
        foreach ([...$actions, 'work_order.cancel', 'work_order.update', 'work_order.checklist.approve', 'work_order.checklist.return',
            'contracted_service.activate', 'contracted_service.suspend', 'contracted_service.reactivate', 'contracted_service.complete', 'contracted_service.cancel'] as $action) {
            $this->assertNotSame($action, AuditCatalog::actionLabel($action), "Falta el texto de {$action}");
        }
        $this->assertSame(['Orden de trabajo', 'Servicio contratado'], [AuditCatalog::entityLabel('WorkOrder'), AuditCatalog::entityLabel('ContractedService')]);
    }

    public function test_database_refuses_an_inconsistent_work_order(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $id = $this->order($admin, $this->makeContracted(), ['checklist_id' => $this->makeTemplate()])['id'];
        $update = fn (array $values) => fn () => DB::table('work_orders')->where('id', $id)->update($values);

        $this->assertDatabaseRejects($update(['status' => 'ASSIGNED']));
        $this->assertDatabaseRejects($update(['status' => 'IN_PROGRESS']));
        $this->assertDatabaseRejects($update(['status' => 'BLOCKED', 'assigned_user_id' => $admin->id]));
        $this->assertDatabaseRejects($update(['status' => 'CANCELLED', 'cancel_reason' => '  ']));
        $this->assertDatabaseRejects($update(['status' => 'EN_ESPERA']));
        $this->assertDatabaseRejects($update(['priority' => 'URGENTE']));
        $this->assertDatabaseRejects($update(['checklist_status' => 'REVISADO']));
        $this->assertDatabaseRejects($update(['scheduled_start' => now(), 'scheduled_end' => now()->subHour()]));
        // La aplicación no puede borrar ítems de un checklist.
        $this->assertDatabaseRejects(fn () => DB::table('work_order_checklist_items')->where('work_order_id', $id)->delete());
    }

    public function test_assigning_checks_the_technician_agenda(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $tito = $this->makeUser('Tito', self::TECHNICIAN, 'OWN');
        $rosa = $this->makeUser('Rosa', self::TECHNICIAN, 'OWN');
        $contracted = $this->makeContracted();
        $first = $this->order($admin, $contracted, ['assigned_user_id' => $tito->id, 'scheduled_start' => $this->at(2, 9)]);

        // Otra orden del mismo técnico que se cruza (las órdenes sin fin ocupan dos horas).
        $second = $this->order($admin, $contracted, ['scheduled_start' => $this->at(2, 10)])['id'];
        $this->postJson("/api/v1/work-orders/{$second}/assign", ['assigned_user_id' => $tito->id])->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_start')->assertJsonPath('errors.scheduled_start.0', "El técnico ya tiene otra orden de trabajo en ese horario ({$first['code']}).");
        $this->postJson('/api/v1/work-orders', [
            'contracted_service_id' => $contracted, 'title' => 'X', 'assigned_user_id' => $tito->id, 'scheduled_start' => $this->at(2, 10),
        ])->assertStatus(422)->assertJsonValidationErrors('scheduled_start');
        // Otro técnico, u otra hora, sí.
        $this->postJson("/api/v1/work-orders/{$second}/assign", ['assigned_user_id' => $rosa->id])->assertOk()->assertJsonPath('status', 'ASSIGNED');
        $this->postJson("/api/v1/work-orders/{$second}/assign", ['assigned_user_id' => $tito->id, 'scheduled_start' => $this->at(2, 11)])->assertOk()
            ->assertJsonPath('assignee.name', 'Tito')->assertJsonPath('status', 'ASSIGNED');
        // Reasignar no es un cambio de estado: el historial lo anota sin estado de destino.
        $reassigned = collect($this->getJson("/api/v1/work-orders/{$second}")->json('history'))->firstWhere('metadata.note', 'Reasignada a Tito');
        $this->assertSame(['work_order.assign', false], [$reassigned['action'], isset($reassigned['metadata']['to'])]);

        // Mover la fecha de una orden asignada también valida el choque.
        $this->putJson("/api/v1/work-orders/{$second}", ['title' => 'Mantenimiento de planta', 'scheduled_start' => $this->at(2, 10)])
            ->assertStatus(422)->assertJsonValidationErrors('scheduled_start');
        $this->putJson("/api/v1/work-orders/{$second}", ['title' => 'Revisión de tablero', 'priority' => 'LOW', 'scheduled_start' => $this->at(4, 10), 'location' => 'Sede Ate'])
            ->assertOk()->assertJsonPath('title', 'Revisión de tablero')->assertJsonPath('priority', 'LOW')->assertJsonPath('location', 'Sede Ate');
        $log = DB::table('audit_logs')->where('action', 'work_order.update')->where('entity_id', $second)->first();
        $this->assertSame(['Mantenimiento de planta', 'Revisión de tablero'], [json_decode($log->before_data, true)['title'], json_decode($log->after_data, true)['title']]);

        // Una orden completada o cancelada ya no ocupa la agenda.
        $this->postJson("/api/v1/work-orders/{$first['id']}/cancel", ['reason' => 'Se reprograma'])->assertOk();
        $this->putJson("/api/v1/work-orders/{$second}", ['title' => 'Revisión de tablero', 'scheduled_start' => $this->at(2, 10)])->assertOk();

        // Quien asigna sin alcanzar la otra orden recibe el aviso sin su número.
        $planner = $this->makeUser('Planificadora', self::OPERATIONS, 'OWN');
        $third = $this->order($planner, $this->makeContracted(seller: $planner), ['scheduled_start' => $this->at(2, 10)])['id'];
        $this->postJson("/api/v1/work-orders/{$third}/assign", ['assigned_user_id' => $tito->id])->assertStatus(422)
            ->assertJsonPath('errors.scheduled_start.0', 'El técnico ya tiene otra orden de trabajo en ese horario.');
    }

    public function test_checklist_is_approved_or_returned_by_someone_else(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $lead = $this->makeUser('Jefa de operaciones', self::OPERATIONS);
        $order = $this->order($admin, $this->makeContracted(), ['checklist_id' => $this->makeTemplate(), 'assigned_user_id' => $lead->id]);
        $id = $order['id'];
        $items = array_column($order['checklist_items'], 'id', 'label');
        $this->as($lead);

        $this->postJson("/api/v1/work-orders/{$id}/checklist/approve")->assertStatus(422)->assertJsonValidationErrors('checklist');
        foreach (['Verificación inicial', 'Ejecución del servicio'] as $label) {
            $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/{$items[$label]}", ['done' => true])->assertOk();
        }

        // Quien ejecuta la orden no aprueba su propio checklist (segregación de funciones).
        $this->postJson("/api/v1/work-orders/{$id}/checklist/approve")->assertStatus(422)
            ->assertJsonPath('errors.checklist.0', 'No puedes aprobar el checklist de una orden que ejecutas tú: lo aprueba otra persona.');

        $this->as($admin);
        $this->postJson("/api/v1/work-orders/{$id}/checklist/return")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/work-orders/{$id}/checklist/return", ['reason' => 'Falta la foto'])->assertOk()
            ->assertJsonPath('checklist.status', 'IN_PROGRESS')->assertJsonPath('checklist_review.note', 'Falta la foto')->assertJsonPath('checklist_review.by', 'Admin');
        $this->postJson("/api/v1/work-orders/{$id}/checklist/approve")->assertStatus(422);

        $this->as($lead);
        $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/{$items['Foto del equipo']}", ['done' => true])->assertOk()->assertJsonPath('checklist.status', 'COMPLETED');
        $this->as($admin);
        $this->postJson("/api/v1/work-orders/{$id}/checklist/approve")->assertOk()
            ->assertJsonPath('checklist.status', 'APPROVED')->assertJsonPath('checklist_review.note', null);

        // Aprobado, ya no cambia.
        $this->as($lead);
        $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/{$items['Foto del equipo']}", ['done' => false])->assertStatus(422)->assertJsonValidationErrors('checklist');
        $this->as($admin);
        $this->postJson("/api/v1/work-orders/{$id}/checklist/return", ['reason' => 'x'])->assertStatus(422);

        // Con la segregación desactivada en la empresa, quien ejecuta puede aprobar.
        $own = $this->order($admin, $this->makeContracted(), ['checklist_id' => $this->makeTemplate(), 'assigned_user_id' => $lead->id]);
        $this->as($lead);
        foreach (array_slice($own['checklist_items'], 0, 2) as $item) {
            $this->patchJson("/api/v1/work-orders/{$own['id']}/checklist-items/{$item['id']}", ['done' => true])->assertOk();
        }
        DB::table('tenants')->where('id', $this->tenantId)->update(['configuration' => json_encode(['segregation' => false])]);
        $this->postJson("/api/v1/work-orders/{$own['id']}/checklist/approve")->assertOk()->assertJsonPath('checklist.status', 'APPROVED');
    }

    public function test_work_orders_list_searches_filters_sorts_and_exports(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $tito = $this->makeUser('Tito', self::TECHNICIAN, 'OWN');
        $andes = $this->makeContracted(contract: $this->makeContract(customerId: $this->makeCustomer('Minera Los Andes SAC')));
        $sur = $this->makeContracted(contract: $this->makeContract(customerId: $this->makeCustomer('Agroindustrias del Sur SAC')));

        $a = $this->order($admin, $andes, ['title' => 'Instalación de bomba', 'priority' => 'HIGH', 'scheduled_start' => $this->at(1, 9), 'assigned_user_id' => $tito->id, 'checklist_id' => $this->makeTemplate()]);
        $b = $this->order($admin, $sur, ['title' => 'Calibración de sensores', 'priority' => 'LOW', 'scheduled_start' => $this->at(3, 9)]);
        $c = $this->order($admin, $sur, ['title' => 'Auditoría de planta', 'scheduled_start' => $this->at(2, 9)]);
        $this->postJson("/api/v1/work-orders/{$c['id']}/cancel", ['reason' => 'Duplicada'])->assertOk();

        $list = $this->getJson('/api/v1/work-orders')->assertOk()->assertJsonPath('total', 3);
        $row = collect($list->json('data'))->firstWhere('id', $a['id']);
        $this->assertSame(['Instalación de bomba', 'ASSIGNED', 'Tito', 3, 0], [$row['title'], $row['status'], $row['assignee']['name'], $row['checklist']['total'], $row['checklist']['done']]);
        $this->assertSame('Minera Los Andes SAC', $row['customer']['party']['organization']['legal_name']);

        $this->getJson('/api/v1/work-orders?status=CANCELLED')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $c['id']);
        $this->getJson('/api/v1/work-orders?priority=HIGH')->assertJsonPath('total', 1);
        $this->getJson("/api/v1/work-orders?technician={$tito->id}")->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $a['id']);
        $this->getJson('/api/v1/work-orders?technician=none')->assertJsonPath('total', 2);
        $this->getJson('/api/v1/work-orders?search=sensores')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/work-orders?search=agroindustrias')->assertJsonPath('total', 2);
        $this->getJson('/api/v1/work-orders?search='.$b['code'])->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $b['id']);

        $ids = fn (string $query) => $this->getJson("/api/v1/work-orders?{$query}")->assertOk()->json('data.*.id');
        $this->assertSame([$a['id'], $c['id'], $b['id']], $ids('sort=priority&dir=asc'));
        $this->assertSame([$b['id'], $c['id'], $a['id']], $ids('sort=scheduled&dir=desc'));
        $this->assertSame([$a['id'], $b['id'], $c['id']], $ids('sort=order&dir=asc'));
        $this->assertSame($a['id'], $ids('sort=technician&dir=asc')[0]);
        $this->assertSame($a['id'], $ids('sort=status&dir=asc')[0]);
        $this->assertSame($a['id'], $ids('sort=customer&dir=desc')[0]);

        $filter = fn (array $f) => '/api/v1/work-orders?filters='.urlencode(json_encode([$f]));
        $this->getJson($filter(['id' => 'order', 'type' => 'text', 'operator' => 'contains', 'value' => 'bomba']))->assertJsonPath('total', 1);
        $this->getJson($filter(['id' => 'customer', 'type' => 'text', 'operator' => 'contains', 'value' => 'sur']))->assertJsonPath('total', 2);
        $this->getJson($filter(['id' => 'scheduled', 'type' => 'date', 'operator' => 'range', 'value' => now()->addDay()->toDateString().'|'.now()->addDays(2)->toDateString()]))->assertJsonPath('total', 2);

        $this->getJson('/api/v1/work-orders?per_page=5000')->assertJsonPath('per_page', 200);
        $this->getJson('/api/v1/work-orders?export=1')->assertOk()->assertJsonPath('total', 3);
        $this->postJson('/api/v1/exports', ['resource' => 'work_orders', 'format' => 'xlsx', 'rows' => 3])->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'work_orders.export')->count());

        // Operaciones ve y planifica, pero no exporta.
        $this->as($this->makeUser('Operaciones', self::OPERATIONS));
        $this->getJson('/api/v1/work-orders')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/v1/work-orders?export=1')->assertStatus(403);
        $this->postJson('/api/v1/exports', ['resource' => 'work_orders', 'format' => 'csv', 'rows' => 3])->assertStatus(403);
    }

    public function test_technician_only_reaches_the_work_orders_assigned_to_them(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $lima = $this->makeBranch('Lima');
        $cusco = $this->makeBranch('Cusco');
        $tito = $this->makeUser('Tito', self::TECHNICIAN, 'OWN', $lima);
        $rosa = $this->makeUser('Rosa', self::TECHNICIAN, null, $cusco);
        $inLima = $this->makeContracted(['branch_id' => $lima]);
        $inCusco = $this->makeContracted(['branch_id' => $cusco]);
        $customerId = DB::table('contracted_services')->where('id', $inLima)->value('customer_id');

        $ofTito = $this->order($admin, $inLima, ['assigned_user_id' => $tito->id, 'scheduled_start' => $this->at(1, 9)])['id'];
        $ofRosa = $this->order($admin, $inCusco, ['assigned_user_id' => $rosa->id, 'scheduled_start' => $this->at(1, 9)])['id'];
        $unassigned = $this->order($admin, $inLima)['id'];

        $this->as($tito);
        $this->getJson('/api/v1/work-orders')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ofTito);
        $this->getJson("/api/v1/work-orders/{$ofRosa}")->assertStatus(404);
        $this->postJson("/api/v1/work-orders/{$ofRosa}/start")->assertStatus(404);
        $this->withHeaders(['If-Match' => '99'])->postJson("/api/v1/work-orders/{$unassigned}/cancel", ['reason' => 'x'])->assertStatus(404);
        $this->flushHeaders();
        // Ejecuta la suya; asignar, crear y revisar el checklist no le corresponden.
        $this->postJson("/api/v1/work-orders/{$ofTito}/start")->assertOk();
        $this->postJson("/api/v1/work-orders/{$ofTito}/assign", ['assigned_user_id' => $rosa->id])->assertStatus(403);
        $this->postJson("/api/v1/work-orders/{$ofTito}/checklist/approve")->assertStatus(403);
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $inLima, 'title' => 'X'])->assertStatus(403);
        $this->getJson('/api/v1/work-orders?export=1')->assertStatus(403);
        // El servicio contratado y el contrato de su orden están fuera de lo que ve: no se le entregan.
        $this->getJson("/api/v1/work-orders/{$ofTito}")->assertOk()->assertJsonPath('contract', null)->assertJsonPath('contracted_service', null);

        // Un rol sin alcance definido alcanza solo lo propio.
        $this->as($rosa);
        $this->getJson('/api/v1/work-orders')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ofRosa);

        // Alcance Sede: lo propio y lo de su sede, esté asignado o no.
        $planner = $this->makeUser('Planificadora de Lima', self::OPERATIONS, 'BRANCH', $lima);
        $this->as($planner);
        $ids = $this->getJson('/api/v1/work-orders')->assertOk()->json('data.*.id');
        sort($ids);
        $expected = [$ofTito, $unassigned];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->getJson('/api/v1/contracted-services')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $inLima);
        // No puede crear una orden sobre un servicio que no alcanza.
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $inCusco, 'title' => 'X'])->assertStatus(422)->assertJsonValidationErrors('contracted_service_id');
        // Lo que crea nace en la sede del servicio y es suyo.
        $created = $this->order($planner, $inLima)['id'];
        $this->assertSame([$lima, $planner->id], array_values((array) DB::table('work_orders')->where('id', $created)->first(['branch_id', 'created_by'])));

        // Gerencia asigna, pero no ejecuta ni crea.
        $manager = $this->makeUser('Gerencia', self::MANAGEMENT);
        $this->as($manager);
        $this->getJson('/api/v1/work-orders')->assertJsonPath('total', 4);
        $this->postJson("/api/v1/work-orders/{$unassigned}/assign", ['assigned_user_id' => $rosa->id, 'scheduled_start' => $this->at(5, 9)])->assertOk();
        $this->postJson("/api/v1/work-orders/{$unassigned}/start")->assertStatus(403);
        $this->putJson("/api/v1/work-orders/{$unassigned}", ['title' => 'X'])->assertStatus(403);
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $inLima, 'title' => 'X'])->assertStatus(403);
        // Asignar al crear pide el permiso de asignar.
        $creator = $this->makeUser('Soporte', ['work_orders.view', 'work_orders.create', 'contracted_services.view']);
        $this->as($creator);
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $inLima, 'title' => 'X', 'assigned_user_id' => $tito->id])
            ->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $inLima, 'title' => 'X'])->assertCreated();

        // La vista del cliente entrega las órdenes de trabajo dentro del alcance de quien mira.
        $partyId = DB::table('customers')->where('id', $customerId)->value('party_id');
        $this->as($this->makeUser('Tito con clientes', [...self::TECHNICIAN, 'core.view'], 'OWN'));
        $this->getJson("/api/v1/parties/{$partyId}")->assertOk()->assertJsonCount(0, 'work_orders');
        $this->as($admin);
        $this->assertGreaterThanOrEqual(3, count($this->getJson("/api/v1/parties/{$partyId}")->assertOk()->json('work_orders')));

        // Ediciones simultáneas.
        $this->withHeaders(['If-Match' => (string) DB::table('work_orders')->where('id', $ofRosa)->value('lock_version')])
            ->postJson("/api/v1/work-orders/{$ofRosa}/start")->assertOk();
        $this->withHeaders(['If-Match' => '0'])->postJson("/api/v1/work-orders/{$ofRosa}/block", ['reason' => 'x'])->assertStatus(409);
        $this->flushHeaders();

        // Otra empresa no existe.
        $this->makeTenant();
        $this->as($this->makeUser('Otra empresa', self::ADMIN));
        $this->getJson('/api/v1/work-orders')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/work-orders/{$ofTito}")->assertStatus(404);
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $inLima, 'title' => 'X'])->assertStatus(422);
        $this->assertSame('OT-'.date('Y').'-0001', $this->order(auth()->user(), $this->makeContracted())['code']);
    }

    public function test_roles_matrix_offers_the_operations_permissions_and_scopes(): void
    {
        $this->makeTenant();
        $this->as($this->makeUser('Administra usuarios', ['users.view']));

        $roles = $this->getJson('/api/v1/roles')->assertOk();
        foreach ([...self::ADMIN] as $code) {
            if (str_starts_with($code, 'contracted_services.') || str_starts_with($code, 'work_orders.')) {
                $this->assertContains($code, $roles->json('permissions.*.code'));
            }
        }
        $this->assertContains('contracted_services', $roles->json('scope_resources.*.code'));
        $this->assertContains('work_orders', $roles->json('scope_resources.*.code'));
    }
}
