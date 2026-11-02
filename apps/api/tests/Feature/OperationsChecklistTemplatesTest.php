<?php

namespace Tests\Feature;

use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Plantillas de checklist: alta, edición sin tocar las órdenes que ya la usan, ítems que no se
 * borran, duplicar, desactivar, importar desde Excel, evidencia obligatoria en la OT, servicio
 * asociado, permisos y exportar.
 */
class OperationsChecklistTemplatesTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MANAGER = [
        'checklists.view', 'checklists.manage', 'checklists.export', 'imports.create',
        'contracted_services.view', 'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve',
    ];

    private function payload(array $data = []): array
    {
        return [
            'name' => 'Mantenimiento preventivo',
            'description' => 'Visita mensual',
            'items' => [
                ['label' => 'Inspección visual', 'required' => true],
                ['label' => 'Medición de parámetros', 'required' => true, 'evidence_required' => true],
                ['label' => 'Limpieza', 'required' => false],
            ],
            ...$data,
        ];
    }

    public function test_a_template_is_created_edited_without_touching_work_orders_and_never_deleted(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Operaciones', self::MANAGER);
        $this->as($admin);
        $service = $this->makeService('Mantenimiento preventivo');

        $this->postJson('/api/v1/checklists', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'items']);
        $this->postJson('/api/v1/checklists', $this->payload(['items' => [['label' => 'A'], ['label' => ' a ']]]))->assertStatus(422)->assertJsonValidationErrors('items.1.label');
        $this->postJson('/api/v1/checklists', $this->payload(['service_id' => (string) Str::uuid()]))->assertStatus(422)->assertJsonValidationErrors('service_id');

        $created = $this->postJson('/api/v1/checklists', $this->payload(['service_id' => $service]))->assertCreated()->json();
        $this->assertSame(['Mantenimiento preventivo', true, 3, 2, 1, 'Mantenimiento preventivo'], [
            $created['name'], $created['active'], $created['items_count'], $created['required_count'], $created['evidence_count'], $created['service']['name'],
        ]);
        $this->assertSame([1, 2, 3], array_column($created['items'], 'position'));
        $this->assertTrue($created['items'][1]['evidence_required']);
        // El nombre no se repite, sin distinguir mayúsculas.
        $this->postJson('/api/v1/checklists', $this->payload(['name' => '  MANTENIMIENTO  preventivo ']))->assertStatus(422)->assertJsonValidationErrors('name');

        // Una OT copia la plantilla, con la marca de evidencia.
        $order = $this->order($admin, $this->makeContracted(['service_id' => $service]), ['checklist_id' => $created['id']]);
        $this->assertSame([false, true, false], array_column($order['checklist_items'], 'evidence_required'));

        // Editar: los ítems anteriores quedan retirados, no borrados; la OT no cambia.
        $id = $created['id'];
        $edited = $this->putJson("/api/v1/checklists/{$id}", $this->payload([
            'name' => 'Mantenimiento preventivo v2', 'service_id' => null,
            'items' => [['label' => 'Inspección visual', 'required' => true], ['label' => 'Prueba de arranque', 'required' => true]],
        ]))->assertOk()->json();
        $this->assertSame(['Mantenimiento preventivo v2', 2, null, 1], [$edited['name'], $edited['items_count'], $edited['service'], $edited['work_orders_count']]);
        $this->assertSame(5, DB::table('checklist_items')->where('checklist_id', $id)->count());
        $this->assertSame(3, DB::table('checklist_items')->where('checklist_id', $id)->whereNotNull('removed_at')->count());
        $this->assertCount(3, $this->getJson("/api/v1/work-orders/{$order['id']}")->json('checklist_items'));
        $this->assertStringContainsString('Ítems: 3 → 2', $edited['history'][0]['metadata']['note']);

        // Quien tenía la ficha con la versión anterior no la pisa.
        $this->withHeaders(['If-Match' => '0'])->putJson("/api/v1/checklists/{$id}", $this->payload())->assertStatus(409);
        $this->flushHeaders();

        // La base: ni borrar, ni editar un ítem, ni resucitar uno retirado.
        $item = DB::table('checklist_items')->where('checklist_id', $id)->whereNull('removed_at')->value('id');
        $removed = DB::table('checklist_items')->where('checklist_id', $id)->whereNotNull('removed_at')->value('id');
        $this->assertDatabaseRejects(fn () => DB::table('checklists')->where('id', $id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('checklist_items')->where('id', $item)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('checklist_items')->where('id', $item)->update(['label' => 'Otra cosa']));
        $this->assertDatabaseRejects(fn () => DB::table('checklist_items')->where('id', $removed)->update(['removed_at' => null]));
        $this->assertDatabaseRejects(fn () => DB::table('checklists')->where('id', $id)->update(['name' => '  ']));

        // Duplicar: con otro nombre y los mismos ítems.
        $copy = $this->postJson("/api/v1/checklists/{$id}/duplicate")->assertCreated()->json();
        $this->assertSame(['Mantenimiento preventivo v2 (copia)', 2], [$copy['name'], $copy['items_count']]);
        $this->assertSame('Mantenimiento preventivo v2 (copia 2)', $this->postJson("/api/v1/checklists/{$id}/duplicate")->assertCreated()->json('name'));

        // Desactivar: deja de ofrecerse al crear OT, pero no desaparece.
        $this->postJson("/api/v1/checklists/{$id}/deactivate")->assertOk()->assertJsonPath('active', false);
        $this->postJson("/api/v1/checklists/{$id}/deactivate")->assertStatus(422);
        $this->assertNotContains($id, array_column($this->getJson('/api/v1/work-orders/options')->json('templates'), 'id'));
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $this->makeContracted(), 'title' => 'X', 'checklist_id' => $id])->assertStatus(422)->assertJsonValidationErrors('checklist_id');
        $this->assertSame(1, $this->getJson('/api/v1/checklists?status=INACTIVE')->json('total'));
        $this->postJson("/api/v1/checklists/{$id}/activate")->assertOk()->assertJsonPath('active', true);

        $summary = $this->getJson('/api/v1/checklists/summary')->assertOk()->json();
        $this->assertSame([3, 0, 6, 1], [$summary['active'], $summary['inactive'], $summary['required_items'], $summary['open_work_orders']]);

        // Listado: buscar por ítem, filtrar por servicio, ordenar en el servidor.
        $this->assertSame(3, $this->getJson('/api/v1/checklists?search=arranque')->json('total'));
        $this->assertSame(0, $this->getJson("/api/v1/checklists?service_id={$service}")->json('total'));
        $this->assertSame('Mantenimiento preventivo v2', $this->getJson('/api/v1/checklists?sort=name&dir=asc')->json('data.0.name'));

        foreach (DB::table('audit_logs')->where('entity_type', 'Checklist')->pluck('action')->unique() as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }
    }

    public function test_an_item_with_mandatory_evidence_is_done_only_with_its_file_and_the_service_template_is_offered(): void
    {
        Storage::fake('documents');
        $this->makeTenant();
        $admin = $this->makeUser('Operaciones', self::MANAGER);
        $tech = $this->makeUser('Técnico', ['work_orders.view', 'work_orders.manage'], 'OWN');
        $this->as($admin);
        $service = $this->makeService('Instalación');
        $template = $this->postJson('/api/v1/checklists', $this->payload(['name' => 'Instalación', 'service_id' => $service]))->assertCreated()->json();
        $contracted = $this->makeContracted(['service_id' => $service]);

        // El formulario de la OT recibe el servicio de cada plantilla y de cada servicio contratado.
        $options = $this->getJson('/api/v1/work-orders/options')->json();
        $this->assertSame($service, collect($options['templates'])->firstWhere('id', $template['id'])['service_id']);
        $this->assertSame($service, collect($options['contracted_services'])->firstWhere('id', $contracted)['service_id']);

        $order = $this->order($admin, $contracted, ['checklist_id' => $template['id'], 'assigned_user_id' => $tech->id, 'scheduled_start' => $this->at(2, 9)]);
        $evidence = collect($order['checklist_items'])->firstWhere('evidence_required', true)['id'];
        $plain = collect($order['checklist_items'])->firstWhere('label', 'Inspección visual')['id'];

        $this->as($tech);
        $this->patchJson("/api/v1/work-orders/{$order['id']}/checklist-items/{$plain}", ['done' => true])->assertOk();
        $this->patchJson("/api/v1/work-orders/{$order['id']}/checklist-items/{$evidence}", ['done' => true])->assertStatus(422)->assertJsonValidationErrors('evidence');
        // Ni la base lo deja sin archivo.
        $this->assertDatabaseRejects(fn () => DB::table('work_order_checklist_items')->where('id', $evidence)->update(['done' => true]));
        // Con su foto queda hecho.
        $this->post("/api/v1/work-orders/{$order['id']}/checklist-items/{$evidence}/evidence", ['file' => UploadedFile::fake()->image('medicion.jpg', 40, 30)], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertTrue((bool) DB::table('work_order_checklist_items')->where('id', $evidence)->value('done'));
        $this->assertTrue(collect($this->getJson('/api/v1/field/route')->json('orders.0.checklist_items'))->firstWhere('id', $evidence)['evidence_required']);
    }

    public function test_templates_are_imported_from_the_excel_template(): void
    {
        $this->makeTenant();
        $this->as($this->makeUser('Operaciones', self::MANAGER));
        $service = $this->makeService('Mantenimiento preventivo');
        $code = DB::table('services')->where('id', $service)->value('code');
        $this->postJson('/api/v1/checklists', $this->payload(['name' => 'Ya existe']))->assertCreated();
        $json = ['Accept' => 'application/json'];

        // La plantilla que se descarga, subida tal cual, entra con sus ejemplos.
        $template = $this->get('/api/v1/checklists/template')->assertOk();
        $file = UploadedFile::fake()->createWithContent('plantilla_checklists.xlsx', $template->streamedContent());
        $result = $this->post('/api/v1/checklists/import', ['file' => $file], $json)->assertOk()->json();
        $this->assertSame([2, '2 plantillas importadas con 6 ítems.', []], [$result['imported'], $result['message'], $result['warnings']]);
        $imported = $this->getJson('/api/v1/checklists?search=Cierre')->json('data.0');
        $this->assertSame(['Cierre de proyecto', 3, 2, 1], [$imported['name'], $imported['items_count'], $imported['required_count'], $imported['evidence_count']]);
        // Subirla otra vez no duplica: avisa por plantilla.
        $this->post('/api/v1/checklists/import', ['file' => UploadedFile::fake()->createWithContent('otra.xlsx', $template->streamedContent())], $json)
            ->assertStatus(422)->assertJsonFragment(['No se importó ninguna plantilla.']);

        // Un CSV con servicio, un ítem repetido, un servicio que no existe y una plantilla que ya existe.
        $csv = "plantilla;descripcion;servicio;item;obligatorio;evidencia\n"
            ."Visita técnica;Con servicio;{$code};Llegada al sitio;Sí;No\n"
            ."Visita técnica;;;Foto del tablero;sí;sí\n"
            ."Visita técnica;;;Foto del tablero;sí;sí\n"
            ."Otra;;NO-EXISTE;Paso;No;No\n"
            ."ya existe;;;Paso;No;No\n"
            .";;;Sin plantilla;No;No\n";
        $result = $this->post('/api/v1/checklists/import', ['file' => UploadedFile::fake()->createWithContent('checklists.csv', $csv)], $json)->assertOk()->json();
        $this->assertSame(1, $result['imported']);
        $this->assertCount(4, $result['warnings']);
        $visit = $this->getJson('/api/v1/checklists?search=Llegada')->json('data.0');
        $this->assertSame([2, $service, 1], [$visit['items_count'], $visit['service']['id'], $visit['evidence_count']]);
        $this->assertSame(3, DB::table('audit_logs')->where('entity_type', 'Checklist')->where('action', 'checklist.import')->count());
    }

    public function test_permissions_and_export_are_checked_by_the_api(): void
    {
        $this->makeTenant();
        $this->as($this->makeUser('Operaciones', self::MANAGER));
        $id = $this->postJson('/api/v1/checklists', $this->payload())->assertCreated()->json('id');

        // Ver no es gestionar ni exportar (Gerencia).
        $this->as($this->makeUser('Gerencia', ['checklists.view']));
        $this->getJson('/api/v1/checklists')->assertOk();
        $this->getJson("/api/v1/checklists/{$id}")->assertOk();
        $this->postJson('/api/v1/checklists', $this->payload(['name' => 'Otra']))->assertStatus(403);
        $this->putJson("/api/v1/checklists/{$id}", $this->payload())->assertStatus(403);
        $this->postJson("/api/v1/checklists/{$id}/duplicate")->assertStatus(403);
        $this->postJson("/api/v1/checklists/{$id}/deactivate")->assertStatus(403);
        $this->get('/api/v1/checklists/template', ['Accept' => 'application/json'])->assertStatus(403);
        $this->getJson('/api/v1/checklists?export=1')->assertStatus(403);
        $this->postJson('/api/v1/exports', ['resource' => 'checklists', 'format' => 'xlsx', 'rows' => 1])->assertStatus(403);

        // Sin permiso, ni verlas (el técnico).
        $this->as($this->makeUser('Técnico', ['work_orders.view', 'work_orders.manage'], 'OWN'));
        $this->getJson('/api/v1/checklists')->assertStatus(403);

        // Quien exporta: el listado entero y queda en auditoría.
        $this->as($this->makeUser('Administrador', self::MANAGER));
        $this->getJson('/api/v1/checklists?export=1')->assertOk();
        $this->postJson('/api/v1/exports', ['resource' => 'checklists', 'format' => 'xlsx', 'rows' => 1])->assertOk();

        // Sin el módulo de Órdenes de trabajo, el permiso no vale.
        DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'ORDENES_TRABAJO')->delete();
        $this->getJson('/api/v1/checklists')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');

        // Otra empresa: no existe.
        $this->makeTenant();
        $this->as($this->makeUser('Ajena', self::MANAGER));
        $this->getJson("/api/v1/checklists/{$id}")->assertStatus(404);
        $this->postJson("/api/v1/checklists/{$id}/duplicate")->assertStatus(404);
    }
}
