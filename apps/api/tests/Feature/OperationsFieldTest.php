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
 * App del técnico: sus visitas pendientes en el orden sugerido, ubicación de la orden, foto de
 * evidencia por ítem, reintentos sin duplicar y rechazos al sincronizar; alcance y permisos.
 */
class OperationsFieldTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const ADMIN = [
        'contracted_services.view', 'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve', 'contracts.view', 'core.view',
    ];

    private const TECHNICIAN = ['work_orders.view', 'work_orders.manage'];

    public function test_route_shows_the_pending_visits_of_a_technician_in_the_suggested_order(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $tito = $this->makeUser('Tito Técnico', self::TECHNICIAN, 'OWN');
        $tina = $this->makeUser('Tina Técnica', self::TECHNICIAN, 'OWN');
        $viewer = $this->makeUser('Solo ve', ['work_orders.view']);
        $contracted = $this->makeContracted();
        $at = fn (int $hour, array $more = []) => ['assigned_user_id' => $tito->id, 'scheduled_start' => $this->at(2, $hour), ...$more];

        // La ubicación va completa o no va, y dentro de rango.
        $this->as($admin);
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $contracted, 'title' => 'X', 'latitude' => -12.05])->assertStatus(422)->assertJsonValidationErrors('longitude');
        $this->postJson('/api/v1/work-orders', ['contracted_service_id' => $contracted, 'title' => 'X', 'latitude' => -120, 'longitude' => -77])->assertStatus(422)->assertJsonValidationErrors('latitude');

        // Tres visitas ubicadas: la segunda por hora queda lejos; la tercera, pegada a la primera.
        $first = $this->order($admin, $contracted, $at(8, ['title' => 'Primera', 'latitude' => -12.0500, 'longitude' => -77.0500]));
        $far = $this->order($admin, $contracted, $at(11, ['title' => 'Lejana', 'latitude' => -12.2000, 'longitude' => -76.9000]));
        $near = $this->order($admin, $contracted, $at(14, ['title' => 'Cercana', 'latitude' => -12.0510, 'longitude' => -77.0490]));
        $plain = $this->order($admin, $contracted, $at(17, ['title' => 'Sin ubicación']));
        $this->assertEquals([-12.05, -77.05], [$first['latitude'], $first['longitude']]);
        $this->order($admin, $contracted, ['title' => 'Sin asignar', 'scheduled_start' => $this->at(3, 9)]);
        $ofTina = $this->order($admin, $contracted, ['title' => 'De Tina', 'assigned_user_id' => $tina->id, 'scheduled_start' => $this->at(2, 8)]);
        $cancelled = $this->order($admin, $contracted, $at(20, ['title' => 'Cancelada']));
        $this->postJson("/api/v1/work-orders/{$cancelled['id']}/cancel", ['reason' => 'Ya no va'])->assertOk();
        $this->assertDatabaseRejects(fn () => DB::table('work_orders')->where('id', $plain['id'])->update(['latitude' => 10]));

        // El técnico ve solo lo suyo pendiente, en el orden sugerido; no elige a nadie más.
        $this->as($tito);
        $mine = $this->getJson('/api/v1/field/route')->assertOk();
        $this->assertEquals(['Primera', 'Cercana', 'Lejana', 'Sin ubicación'], $mine->json('orders.*.title'));
        $this->assertEquals([$tito->id, $tito->id, 'Tito Técnico', []], [$mine->json('viewer_id'), $mine->json('technician.id'), $mine->json('technician.name'), $mine->json('technicians')]);
        // Pedir la de otra persona no le muestra nada: esas órdenes quedan fuera de su alcance.
        $this->assertSame([], $this->getJson("/api/v1/field/route?user={$tina->id}")->assertOk()->json('orders'));

        // Quien alcanza más ve la app de cualquier técnico; solo de quien ejecuta órdenes.
        $this->as($admin);
        $asTina = $this->getJson("/api/v1/field/route?user={$tina->id}")->assertOk();
        $this->assertEquals([['De Tina'], 'Tina Técnica'], [$asTina->json('orders.*.title'), $asTina->json('technician.name')]);
        $this->assertContains($tito->id, $asTina->json('technicians.*.id'));
        $this->assertNotContains($viewer->id, $asTina->json('technicians.*.id'));
        $this->getJson("/api/v1/field/route?user={$viewer->id}")->assertStatus(422)->assertJsonValidationErrors('user');
        $this->getJson('/api/v1/field/route?user=nadie')->assertStatus(422);

        // Una visita iniciada sigue pendiente; completada, sale de la ruta.
        $this->as($tito);
        $this->postJson("/api/v1/work-orders/{$plain['id']}/start")->assertOk();
        $this->postJson("/api/v1/work-orders/{$plain['id']}/complete")->assertOk();
        $this->assertNotContains('Sin ubicación', $this->getJson('/api/v1/field/route')->json('orders.*.title'));

        // Reintentar un envío con la misma clave no lo aplica dos veces ni da error.
        $key = ['Idempotency-Key' => 'op-'.$far['id']];
        $this->postJson("/api/v1/work-orders/{$far['id']}/start", [], $key)->assertOk()->assertJsonPath('status', 'IN_PROGRESS');
        $this->postJson("/api/v1/work-orders/{$far['id']}/start", [], $key)->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->postJson("/api/v1/work-orders/{$far['id']}/start")->assertStatus(422);

        // Sin permiso de ver órdenes, no hay app; otra empresa no ve nada.
        $this->as($this->makeUser('Ventas', ['contracts.view']));
        $this->getJson('/api/v1/field/route')->assertStatus(403);
        $this->makeTenant();
        $this->as($this->makeUser('Otra empresa', self::ADMIN));
        $this->assertSame([], $this->getJson('/api/v1/field/route')->assertOk()->json('orders'));
        $this->getJson("/api/v1/field/route?user={$tito->id}")->assertStatus(422);
        $this->assertNotNull($ofTina['id']);
        $this->assertNotNull($near['id']);
    }

    public function test_evidence_photo_marks_the_item_and_rejected_changes_reach_the_history(): void
    {
        Storage::fake('documents');
        $tenantId = $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $tito = $this->makeUser('Tito Técnico', self::TECHNICIAN, 'OWN');
        $other = $this->makeUser('Otro Técnico', self::TECHNICIAN, 'OWN');
        $order = $this->order($admin, $this->makeContracted(), [
            'assigned_user_id' => $tito->id, 'scheduled_start' => $this->at(2, 9), 'checklist_id' => $this->makeTemplate(),
        ]);
        $id = $order['id'];
        $photoItem = collect($order['checklist_items'])->firstWhere('label', 'Foto del equipo')['id'];
        $url = "/api/v1/work-orders/{$id}/checklist-items/{$photoItem}/evidence";
        $json = ['Accept' => 'application/json'];

        $this->as($tito);
        // Solo fotos, y con archivo.
        $this->post($url, [], $json)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('nota.pdf', '%PDF-1.4')], $json)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame([], Storage::disk('documents')->allFiles());

        $first = $this->post($url, ['file' => UploadedFile::fake()->image('equipo.jpg', 40, 30)], $json)->assertCreated();
        $item = collect($first->json('checklist_items'))->firstWhere('id', $photoItem);
        $this->assertEquals([true, 'Foto: equipo.jpg', 'equipo.jpg', 1], [$item['done'], $item['evidence_note'], $item['evidence']['file_name'], $item['evidence']['version']]);
        $documentId = DB::table('work_order_checklist_items')->where('id', $photoItem)->value('evidence_document_id');
        $this->assertDatabaseHas('documents', ['id' => $documentId, 'document_type' => 'Evidencia', 'tenant_id' => $tenantId]);
        $this->assertDatabaseHas('document_links', ['document_id' => $documentId, 'entity_type' => 'WorkOrderChecklistItem', 'entity_id' => $photoItem]);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'action' => 'work_order.checklist.evidence']);

        // Otra foto del mismo ítem crea un documento independiente (v1); el detalle y la ruta muestran todas las fotos.
        $second = $this->post($url, ['file' => UploadedFile::fake()->image('equipo-2.png', 40, 30)], $json)->assertCreated();
        $this->assertSame(2, DB::table('document_links')->where('entity_type', 'WorkOrderChecklistItem')->where('entity_id', $photoItem)->count());
        $detailItem = collect($second->json('checklist_items'))->firstWhere('id', $photoItem);
        $this->assertEquals(['equipo.jpg', 'equipo-2.png'], array_column($detailItem['evidences'], 'file_name'));
        $secondDocId = $detailItem['evidences'][1]['document_id'];
        $routeItem = collect($this->getJson('/api/v1/field/route')->json('orders.0.checklist_items'))->firstWhere('id', $photoItem);
        $this->assertEquals([true, 'equipo-2.png', 1], [$routeItem['done'], $routeItem['evidence']['file_name'], $routeItem['evidence']['version']]);
        $this->assertEquals(['equipo.jpg', 'equipo-2.png'], array_column($routeItem['evidences'], 'file_name'));

        // Reemplazar la primera foto sube v2 en ese mismo documento sin crear un tercero.
        $replaced = $this->post($url, ['file' => UploadedFile::fake()->image('equipo-1-corregida.jpg', 50, 40), 'document_id' => $documentId], $json)->assertCreated();
        $replacedItem = collect($replaced->json('checklist_items'))->firstWhere('id', $photoItem);
        $this->assertEquals(['equipo-1-corregida.jpg', 'equipo-2.png'], array_column($replacedItem['evidences'], 'file_name'));
        $this->assertEquals([2, 1], array_column($replacedItem['evidences'], 'version'));

        // Ver la foto (última o por documento): enlace temporal, con registro. Un ítem sin foto no tiene qué ver.
        $this->getJson("{$url}-url")->assertOk()->assertJsonStructure(['url', 'expires_in_minutes']);
        $this->getJson("{$url}-url?document_id={$documentId}")->assertOk()->assertJsonStructure(['url', 'expires_in_minutes']);
        $this->getJson("{$url}-url?document_id=".Str::uuid())->assertStatus(404);
        $plain = collect($order['checklist_items'])->firstWhere('label', 'Verificación inicial')['id'];
        $this->getJson("/api/v1/work-orders/{$id}/checklist-items/{$plain}/evidence-url")->assertStatus(404);

        // Quitar una foto archiva su documento; quitar la última foto de un ítem con foto obligatoria lo desmarca.
        $afterRemoveOne = $this->deleteJson("{$url}?document_id={$documentId}")->assertOk();
        $this->assertDatabaseHas('documents', ['id' => $documentId, 'status' => 'ARCHIVED']);
        $itemAfterOne = collect($afterRemoveOne->json('checklist_items'))->firstWhere('id', $photoItem);
        $this->assertEquals([true, ['equipo-2.png']], [$itemAfterOne['done'], array_column($itemAfterOne['evidences'], 'file_name')]);

        $afterRemoveAll = $this->deleteJson("{$url}?document_id={$secondDocId}")->assertOk();
        $itemAfterAll = collect($afterRemoveAll->json('checklist_items'))->firstWhere('id', $photoItem);
        $this->assertEquals([false, []], [$itemAfterAll['done'], $itemAfterAll['evidences']]);

        // Vuelve a subir una foto para dejar el ítem marcado antes de cerrar la orden.
        $this->post($url, ['file' => UploadedFile::fake()->image('equipo-final.jpg', 40, 30)], $json)->assertCreated();

        // Lo ajeno "no existe"; quien solo ve no adjunta.
        $this->as($other);
        $this->post($url, ['file' => UploadedFile::fake()->image('x.jpg')], $json)->assertStatus(404);
        $this->getJson("{$url}-url")->assertStatus(404);
        $this->as($this->makeUser('Solo ve', ['work_orders.view']));
        $this->post($url, ['file' => UploadedFile::fake()->image('x.jpg')], $json)->assertStatus(403);
        $this->getJson("{$url}-url")->assertOk();

        // Los cambios sin conexión que la API rechazó quedan en el historial de su orden (solo de las que se alcanzan).
        $this->as($tito);
        $foreign = $this->order($admin, $this->makeContracted(), ['assigned_user_id' => $other->id, 'scheduled_start' => $this->at(4, 9)])['id'];
        $this->as($tito);
        $this->postJson('/api/v1/field/sync-log', ['rejected' => [
            ['work_order_id' => $id, 'label' => 'Visita completada · OT', 'message' => 'La orden fue cancelada.'],
            ['work_order_id' => $foreign, 'label' => 'Ajena', 'message' => 'x'],
        ]])->assertOk()->assertJsonPath('recorded', 1);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'action' => 'work_order.sync_rejected']);
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $foreign, 'action' => 'work_order.sync_rejected']);
        $this->postJson('/api/v1/field/sync-log', ['rejected' => []])->assertStatus(422);

        // Con el checklist aprobado o la orden cerrada, la evidencia ya no cambia.
        $this->postJson("/api/v1/work-orders/{$id}/start")->assertOk();
        foreach (collect($order['checklist_items'])->where('required', true) as $required) {
            $this->patchJson("/api/v1/work-orders/{$id}/checklist-items/{$required['id']}", ['done' => true])->assertOk();
        }
        $this->postJson("/api/v1/work-orders/{$id}/complete")->assertOk();
        $this->post($url, ['file' => UploadedFile::fake()->image('tarde.jpg')], $json)->assertStatus(422);

        foreach (['work_order.checklist.evidence', 'work_order.checklist.evidence_replace', 'work_order.checklist.evidence_remove', 'work_order.checklist.evidence_download', 'work_order.sync_rejected'] as $action) {
            $this->assertNotSame($action, AuditCatalog::actionLabel($action), "Falta el texto de {$action}");
        }
    }
}
