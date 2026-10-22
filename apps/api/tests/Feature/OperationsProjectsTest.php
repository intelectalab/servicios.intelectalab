<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Operaciones, segunda parte: Proyectos (tareas, entregables, cierre), Horas (sin solapes, nadie
 * aprueba las suyas) y Costos; margen del proyecto con lo aprobado; alcance y permisos.
 */
class OperationsProjectsTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const ADMIN = [
        'contracted_services.view', 'contracts.view', 'work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve',
        'projects.view', 'projects.create', 'projects.manage', 'projects.approve', 'projects.export', 'tasks.manage',
        'time_entries.view', 'time_entries.create', 'time_entries.approve', 'time_entries.export',
        'cost_entries.view', 'cost_entries.create', 'cost_entries.approve', 'cost_entries.export',
        'deliverables.view', 'deliverables.manage', 'deliverables.approve',
    ];

    /** Operaciones / PM: planifica, ejecuta y aprueba; no exporta. */
    private const OPERATIONS = [
        'contracted_services.view', 'projects.view', 'projects.create', 'projects.manage', 'projects.approve', 'tasks.manage',
        'time_entries.view', 'time_entries.create', 'time_entries.approve',
        'cost_entries.view', 'cost_entries.create', 'cost_entries.approve',
        'deliverables.view', 'deliverables.manage', 'deliverables.approve',
    ];

    /** Técnico / consultor: trabaja en sus proyectos y registra sus horas y costos. */
    private const TECHNICIAN = [
        'projects.view', 'tasks.manage', 'time_entries.view', 'time_entries.create', 'cost_entries.view', 'cost_entries.create',
        'deliverables.view', 'deliverables.manage',
    ];

    /** Gerencia: ve, cierra, aprueba y exporta; no ejecuta. */
    private const MANAGEMENT = [
        'projects.view', 'projects.approve', 'projects.export', 'time_entries.view', 'time_entries.approve', 'time_entries.export',
        'cost_entries.view', 'cost_entries.approve', 'cost_entries.export', 'deliverables.view', 'deliverables.approve',
    ];

    private function project(User $as, ?string $contractedId = null, array $data = []): array
    {
        $this->as($as);

        return $this->postJson('/api/v1/projects', [
            'contracted_service_id' => $contractedId ?? $this->makeContracted(), 'name' => 'Implementación ERP', ...$data,
        ])->assertCreated()->json();
    }

    /** Lleva el proyecto a "En ejecución". */
    private function running(User $as, ?string $contractedId = null, array $data = []): array
    {
        $project = $this->project($as, $contractedId, $data);
        $this->postJson("/api/v1/projects/{$project['id']}/plan")->assertOk();

        return $this->postJson("/api/v1/projects/{$project['id']}/start")->assertOk()->json();
    }

    private function task(string $projectId, array $data = []): array
    {
        $tasks = $this->postJson("/api/v1/projects/{$projectId}/tasks", ['title' => 'Levantamiento', 'estimate_hours' => 8, ...$data])->assertCreated()->json('tasks');

        return end($tasks);
    }

    private function hours(User $as, array $data, int $day = 1, int $from = 9, int $to = 11): array
    {
        $this->as($as);

        return $this->postJson('/api/v1/time-entries', [
            'started_at' => now()->subDays($day)->setTime($from, 0)->toIso8601String(),
            'ended_at' => now()->subDays($day)->setTime($to, 0)->toIso8601String(),
            ...$data,
        ])->assertCreated()->json();
    }

    // ------------------------------------------------------------------ Proyectos

    public function test_project_is_born_from_an_active_contracted_service_and_follows_its_states(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $lead = $this->makeUser('Jefa de proyecto', self::OPERATIONS);
        $branch = $this->makeBranch('Lima');
        $contracted = $this->makeContracted(['branch_id' => $branch]);
        $pending = $this->makeContracted(['status' => 'PENDING']);
        $this->as($admin);

        $this->postJson('/api/v1/projects', [])->assertStatus(422)->assertJsonValidationErrors(['contracted_service_id', 'name']);
        $this->postJson('/api/v1/projects', ['contracted_service_id' => $pending, 'name' => 'X'])->assertStatus(422)->assertJsonValidationErrors('contracted_service_id');
        $this->postJson('/api/v1/projects', ['contracted_service_id' => (string) Str::uuid(), 'name' => 'X'])->assertStatus(422);
        $this->postJson('/api/v1/projects', ['contracted_service_id' => $contracted, 'name' => 'X', 'start_date' => '2027-02-01', 'end_date' => '2027-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->postJson('/api/v1/projects', ['contracted_service_id' => $contracted, 'name' => 'X', 'manager_user_id' => $this->makeUser('Sin proyectos', ['core.view'])->id])
            ->assertStatus(422)->assertJsonValidationErrors('manager_user_id');

        $project = $this->project($admin, $contracted, ['budget_hours' => 40, 'budget_cost' => 500, 'manager_user_id' => $lead->id, 'start_date' => '2027-01-01', 'end_date' => '2027-03-01']);
        $id = $project['id'];
        $this->assertEquals(['PRY-'.date('Y').'-0001', 'DRAFT', 'Jefa de proyecto'], [$project['code'], $project['status'], $project['manager']['name']]);
        // Ingreso atribuible: lo pactado en el servicio contratado (380 × 2).
        $this->assertEquals([760.0, 500.0, 40.0, 760.0, 100], [$project['budget_revenue'], $project['budget_cost'], $project['budget_hours'], $project['metrics']['margin'], $project['metrics']['margin_pct']]);
        $this->assertEquals($branch, DB::table('projects')->where('id', $id)->value('branch_id'));
        $this->assertNotNull($project['contract']['contract_number']);

        // Estados: Borrador → Planificado → En ejecución ⇄ En pausa → Completado.
        $this->postJson("/api/v1/projects/{$id}/start")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/projects/{$id}/plan")->assertOk()->assertJsonPath('status', 'PLANNED');
        $this->postJson("/api/v1/projects/{$id}/start")->assertOk()->assertJsonPath('status', 'IN_PROGRESS');
        $this->postJson("/api/v1/projects/{$id}/cancel", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/projects/{$id}/hold")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/projects/{$id}/hold", ['reason' => 'El cliente pidió esperar'])->assertOk()
            ->assertJsonPath('status', 'ON_HOLD')->assertJsonPath('hold_reason', 'El cliente pidió esperar');
        $this->postJson("/api/v1/projects/{$id}/complete")->assertStatus(422);
        $this->postJson("/api/v1/projects/{$id}/resume")->assertOk()->assertJsonPath('status', 'IN_PROGRESS')->assertJsonPath('hold_reason', null);

        $this->putJson("/api/v1/projects/{$id}", ['name' => 'Implementación ERP fase 1', 'budget_hours' => 60])->assertOk()
            ->assertJsonPath('name', 'Implementación ERP fase 1')->assertJsonPath('budget_hours', 60)->assertJsonPath('manager.name', 'Jefa de proyecto');
        $this->postJson("/api/v1/projects/{$id}/complete")->assertOk()->assertJsonPath('status', 'COMPLETED');
        // Cerrado: no se edita ni recibe tareas, entregables, horas o costos.
        $this->putJson("/api/v1/projects/{$id}", ['name' => 'Otro'])->assertStatus(422);
        $this->postJson("/api/v1/projects/{$id}/tasks", ['title' => 'X'])->assertStatus(422);
        $this->postJson("/api/v1/projects/{$id}/deliverables", ['name' => 'X'])->assertStatus(422);
        $this->postJson('/api/v1/cost-entries', ['project_id' => $id, 'description' => 'X', 'category' => 'Materiales', 'quantity' => 1, 'unit_cost' => 10])->assertStatus(422);
        $this->postJson('/api/v1/time-entries', ['project_id' => $id, 'started_at' => now()->subHours(3)->toIso8601String(), 'ended_at' => now()->subHour()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('project_id');

        $other = $this->project($admin, $contracted)['id'];
        $this->postJson("/api/v1/projects/{$other}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/projects/{$other}/cancel", ['reason' => 'Se descartó'])->assertOk()->assertJsonPath('status', 'CANCELLED')->assertJsonPath('cancel_reason', 'Se descartó');

        // La base rechaza una pausa o una cancelación sin motivo, y un estado desconocido.
        $draft = $this->project($admin, $contracted)['id'];
        $update = fn (array $values) => fn () => DB::table('projects')->where('id', $draft)->update($values);
        $this->assertDatabaseRejects($update(['status' => 'ON_HOLD']));
        $this->assertDatabaseRejects($update(['status' => 'CANCELLED', 'cancel_reason' => ' ']));
        $this->assertDatabaseRejects($update(['status' => 'ARCHIVADO']));

        $actions = DB::table('audit_logs')->where('entity_type', 'Project')->where('entity_id', $id)->pluck('action')->unique()->sort()->values()->all();
        $this->assertEquals(['project.complete', 'project.create', 'project.hold', 'project.plan', 'project.resume', 'project.start', 'project.update'], $actions);
    }

    public function test_tasks_move_with_the_project_running_and_need_their_required_check(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $tito = $this->makeUser('Tito', self::TECHNICIAN, 'OWN');
        $project = $this->project($admin);
        $id = $project['id'];

        $this->postJson("/api/v1/projects/{$id}/tasks", [])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->postJson("/api/v1/projects/{$id}/tasks", ['title' => 'X', 'assigned_user_id' => $this->makeUser('Ajena', ['core.view'])->id])
            ->assertStatus(422)->assertJsonValidationErrors('assigned_user_id');
        $survey = $this->task($id, ['title' => 'Levantamiento', 'estimate_hours' => 6, 'assigned_user_id' => $tito->id, 'required_check' => 'Acta firmada', 'due_at' => '2027-01-15']);
        $build = $this->task($id, ['title' => 'Configuración', 'estimate_hours' => 14, 'priority' => 'HIGH']);
        $this->assertEquals(['TODO', 'Tito', 'Acta firmada', false, 6.0], [$survey['status'], $survey['assignee']['name'], $survey['required_check'], $survey['required_check_done'], $survey['estimate_hours']]);

        $move = fn (string $taskId, string $status, array $extra = []) => $this->postJson("/api/v1/projects/{$id}/tasks/{$taskId}/move", ['status' => $status, ...$extra]);

        // Con el proyecto sin iniciar, las tareas no se mueven.
        $move($survey['id'], 'IN_PROGRESS')->assertStatus(422)->assertJsonPath('errors.status.0', 'Las tareas se mueven con el proyecto en ejecución.');
        $this->postJson("/api/v1/projects/{$id}/plan")->assertOk();
        $this->postJson("/api/v1/projects/{$id}/start")->assertOk();

        $move($survey['id'], 'DONE')->assertStatus(422)->assertJsonPath('errors.status.0', 'Transición no permitida: Por hacer → Hecha.');
        $move($survey['id'], 'ARCHIVADA')->assertStatus(422);
        $move($survey['id'], 'IN_PROGRESS')->assertOk();
        $move($survey['id'], 'BLOCKED')->assertStatus(422)->assertJsonValidationErrors('reason');
        $blocked = collect($move($survey['id'], 'BLOCKED', ['reason' => 'Falta acceso'])->assertOk()->json('tasks'))->firstWhere('id', $survey['id']);
        $this->assertEquals(['BLOCKED', 'Falta acceso'], [$blocked['status'], $blocked['blocked_reason']]);
        $move($survey['id'], 'IN_PROGRESS')->assertOk();

        // Hecha exige su control obligatorio.
        $move($survey['id'], 'DONE')->assertStatus(422)->assertJsonPath('errors.status.0', 'Falta el control obligatorio: Acta firmada.');
        $this->patchJson("/api/v1/projects/{$id}/tasks/{$build['id']}/check", ['done' => true])->assertStatus(422);
        $this->patchJson("/api/v1/projects/{$id}/tasks/{$survey['id']}/check", ['done' => true])->assertOk();
        // Cambiar el control lo deja pendiente otra vez.
        $edited = collect($this->putJson("/api/v1/projects/{$id}/tasks/{$survey['id']}", ['title' => 'Levantamiento', 'estimate_hours' => 6, 'required_check' => 'Acta firmada por gerencia'])
            ->assertOk()->json('tasks'))->firstWhere('id', $survey['id']);
        $this->assertFalse($edited['required_check_done']);
        $this->patchJson("/api/v1/projects/{$id}/tasks/{$survey['id']}/check", ['done' => true])->assertOk();
        $done = $move($survey['id'], 'DONE')->assertOk();
        // Avance ponderado por horas estimadas: 6 de 20.
        $this->assertEquals([30, 2, 1], [$done->json('metrics.progress'), $done->json('metrics.tasks'), $done->json('metrics.done')]);

        // Hecha, ya no cambia; cancelada no cuenta en el avance.
        $this->putJson("/api/v1/projects/{$id}/tasks/{$survey['id']}", ['title' => 'X'])->assertStatus(422);
        $move($survey['id'], 'IN_PROGRESS')->assertStatus(422);
        $this->assertEquals(100, $move($build['id'], 'CANCELLED')->assertOk()->json('metrics.progress'));
        $this->postJson("/api/v1/projects/{$id}/tasks/".Str::uuid().'/move', ['status' => 'IN_PROGRESS'])->assertStatus(404);

        // La base no deja una tarea hecha con su control pendiente.
        $pending = $this->task($id, ['required_check' => 'Prueba']);
        $this->assertDatabaseRejects(fn () => DB::table('tasks')->where('id', $pending['id'])->update(['status' => 'DONE']));
    }

    public function test_deliverables_need_their_document_and_closing_needs_everything_done(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $project = $this->running($admin);
        $id = $project['id'];
        $task = $this->task($id);
        $at = fn (string $deliverableId, string $action, array $body = []) => $this->postJson("/api/v1/projects/{$id}/deliverables/{$deliverableId}/{$action}", $body);
        $one = fn ($response) => $response->json('deliverables.0');

        $this->postJson("/api/v1/projects/{$id}/deliverables", [])->assertStatus(422)->assertJsonValidationErrors('name');
        $deliverable = $one($this->postJson("/api/v1/projects/{$id}/deliverables", ['name' => 'Informe final', 'due_date' => '2027-02-01'])->assertCreated());
        $did = $deliverable['id'];
        $this->assertEquals(['DRAFT', null], [$deliverable['status'], $deliverable['document_ref']]);

        // Sin documento no pasa a revisión.
        $at($did, 'submit')->assertStatus(422)->assertJsonValidationErrors('document_ref');
        $at($did, 'approve')->assertStatus(422);
        $this->putJson("/api/v1/projects/{$id}/deliverables/{$did}", ['name' => 'Informe final', 'document_ref' => 'https://drive.test/informe-v1.pdf'])->assertOk();
        $this->assertEquals('IN_REVIEW', $one($at($did, 'submit')->assertOk())['status']);
        $this->putJson("/api/v1/projects/{$id}/deliverables/{$did}", ['name' => 'Otro'])->assertStatus(422);

        $at($did, 'return')->assertStatus(422)->assertJsonValidationErrors('reason');
        $returned = $one($at($did, 'return', ['reason' => 'Falta el anexo'])->assertOk());
        $this->assertEquals(['RETURNED', 'Falta el anexo'], [$returned['status'], $returned['review_note']]);
        $at($did, 'deliver')->assertStatus(422);
        $this->assertEquals('IN_REVIEW', $one($at($did, 'submit')->assertOk())['status']);

        // Cerrar exige tareas cerradas y entregables aprobados.
        $this->postJson("/api/v1/projects/{$id}/complete")->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'No se puede cerrar el proyecto: tiene 1 tarea abierta y 1 entregable sin aprobar.');
        $this->assertEquals('APPROVED', $one($at($did, 'approve')->assertOk())['status']);
        $this->postJson("/api/v1/projects/{$id}/complete")->assertStatus(422)->assertJsonPath('errors.status.0', 'No se puede cerrar el proyecto: tiene 1 tarea abierta.');
        $this->postJson("/api/v1/projects/{$id}/tasks/{$task['id']}/move", ['status' => 'CANCELLED'])->assertOk();
        $delivered = $one($at($did, 'deliver')->assertOk());
        $this->assertEquals('DELIVERED', $delivered['status']);
        $this->assertNotNull($delivered['delivered_at']);
        $this->postJson("/api/v1/projects/{$id}/complete")->assertOk()->assertJsonPath('status', 'COMPLETED');

        // La base no admite un entregable en revisión sin documento ni devuelto sin observación.
        $other = $this->running($admin)['id'];
        $draft = $one($this->postJson("/api/v1/projects/{$other}/deliverables", ['name' => 'Manual'])->assertCreated())['id'];
        $this->assertDatabaseRejects(fn () => DB::table('deliverables')->where('id', $draft)->update(['status' => 'IN_REVIEW']));
        $this->assertDatabaseRejects(fn () => DB::table('deliverables')->where('id', $draft)->update(['status' => 'RETURNED']));
        $this->assertDatabaseRejects(fn () => DB::table('deliverables')->where('id', $draft)->update(['status' => 'PERDIDO']));

        // Sin permiso de ver entregables, la ficha no los entrega; revisarlos y gestionarlos son permisos distintos.
        $this->as($this->makeUser('Solo proyectos', ['projects.view']));
        $this->getJson("/api/v1/projects/{$other}")->assertOk()->assertJsonPath('deliverables', null);
        $this->as($this->makeUser('Gerencia', self::MANAGEMENT));
        $this->postJson("/api/v1/projects/{$other}/deliverables", ['name' => 'X'])->assertStatus(403);
        $this->as($this->makeUser('Técnico', self::TECHNICIAN));
        $at($draft, 'approve')->assertStatus(403);
        $at($draft, 'return', ['reason' => 'x'])->assertStatus(403);
    }

    public function test_documents_on_the_server_disk_download_only_with_a_signed_link(): void
    {
        // DOCUMENTS_DRIVER=local, en una carpeta temporal.
        $root = sys_get_temp_dir().'/erp-docs-'.Str::uuid();
        config(['filesystems.disks.documents' => ['driver' => 'local', 'root' => $root, 'bucket' => 'documents', 'throw' => true]]);
        Storage::forgetDisk('documents');
        (new \App\Providers\AppServiceProvider($this->app))->boot();

        try {
            $tenantId = $this->makeTenant();
            $admin = $this->makeUser('Admin', self::ADMIN);
            $id = $this->running($admin)['id'];
            $did = $this->postJson("/api/v1/projects/{$id}/deliverables", ['name' => 'Informe final'])->assertCreated()->json('deliverables.0.id');
            $url = "/api/v1/projects/{$id}/deliverables/{$did}/document";
            $this->post($url, ['title' => 'Informe', 'file' => UploadedFile::fake()->createWithContent('Informe final.pdf', '%PDF-1.4 disco')], ['Accept' => 'application/json'])
                ->assertCreated();

            $files = Storage::disk('documents')->allFiles();
            $this->assertCount(1, $files);
            $this->assertStringStartsWith("tenants/{$tenantId}/documents/", $files[0]);
            $this->assertFileExists("{$root}/{$files[0]}");
            $this->assertDatabaseHas('file_objects', ['object_key' => $files[0], 'storage_provider' => 'local', 'bucket' => 'documents']);

            // El enlace lo da la API con sesión; la descarga no la pide, pero exige la firma.
            $link = $this->getJson("{$url}-url")->assertOk()->json('url');
            $this->assertStringContainsString('/api/v1/files/tenants/', $link);
            $path = parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);
            $this->app['auth']->forgetGuards();

            $download = $this->get($path)->assertOk();
            $this->assertSame('%PDF-1.4 disco', $download->streamedContent());
            $this->assertStringContainsString('attachment; filename="Informe final.pdf"', $download->headers->get('Content-Disposition'));
            $this->assertStringContainsString('sandbox', $download->headers->get('Content-Security-Policy'));

            // Sin firma, con la firma de otro archivo o vencido: no se entrega.
            $this->get(parse_url($link, PHP_URL_PATH))->assertStatus(403);
            $this->get(str_replace(basename($files[0]), 'otro.pdf', $path))->assertStatus(403);
            $this->travel(6)->minutes();
            $this->get($path)->assertStatus(403);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($root);
            Storage::forgetDisk('documents');
        }
    }

    public function test_deliverable_document_is_an_uploaded_file_with_versions(): void
    {
        Storage::fake('documents');
        $tenantId = $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $id = $this->running($admin)['id'];
        $one = fn ($response) => $response->json('deliverables.0');
        $did = $one($this->postJson("/api/v1/projects/{$id}/deliverables", ['name' => 'Informe final'])->assertCreated())['id'];
        $url = "/api/v1/projects/{$id}/deliverables/{$did}/document";
        $pdf = fn (string $name = 'informe.pdf', string $content = '%PDF-1.4 informe') => UploadedFile::fake()->createWithContent($name, $content);

        // Un programa con nombre de PDF: se mira el contenido, no solo la extensión.
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, "<?php echo 'hola'; ?>\n");
        $disguised = new UploadedFile($path, 'pagina.pdf', null, null, true);

        // Título y archivo obligatorios; solo archivos de trabajo; la huella debe coincidir con lo recibido.
        $this->post($url, [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['title', 'file']);
        $this->post($url, ['title' => 'Informe', 'file' => $pdf('instalador.exe', 'MZ')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post($url, ['title' => 'Informe', 'file' => $disguised], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post($url, ['title' => 'Informe', 'category' => 'Inventada', 'file' => $pdf()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('category');
        $this->post($url, ['title' => 'Informe', 'sha256' => str_repeat('a', 64), 'file' => $pdf()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertSame(0, DB::table('documents')->where('tenant_id', $tenantId)->count());

        $sha = hash('sha256', '%PDF-1.4 informe');
        $attached = $one($this->post($url, ['title' => 'Informe final v1', 'visibility' => 'PORTAL', 'sha256' => $sha, 'file' => $pdf()], ['Accept' => 'application/json'])->assertCreated());
        $this->assertEquals('informe.pdf', $attached['document_ref']);
        $this->assertEquals(
            ['Informe final v1', 'Entregable', 'PORTAL', 1, 'informe.pdf', $sha, 'PENDING'],
            [$attached['document']['title'], $attached['document']['category'], $attached['document']['visibility'], $attached['document']['version'],
                $attached['document']['file_name'], $attached['document']['sha256'], $attached['document']['antivirus_status']],
        );
        $documentId = $attached['document']['id'];
        $this->assertDatabaseHas('document_links', ['document_id' => $documentId, 'entity_type' => 'Deliverable', 'entity_id' => $did]);
        $files = Storage::disk('documents')->allFiles();
        $this->assertCount(1, $files);
        // El archivo queda en la carpeta de su empresa y su ruta no usa el nombre que traía.
        $this->assertStringStartsWith("tenants/{$tenantId}/documents/", $files[0]);
        $this->assertStringNotContainsString('informe', $files[0]);

        // Otra subida es una versión nueva del mismo documento; la anterior no se destruye.
        $second = $one($this->post($url, ['title' => 'Informe final v2', 'file' => $pdf('informe-v2.pdf', '%PDF-1.4 dos')], ['Accept' => 'application/json'])->assertCreated());
        $this->assertEquals([$documentId, 2, 'informe-v2.pdf', 'INTERNAL'], [$second['document']['id'], $second['document']['version'], $second['document_ref'], $second['document']['visibility']]);
        $this->assertSame(2, DB::table('document_versions')->where('document_id', $documentId)->count());
        $this->assertCount(2, Storage::disk('documents')->allFiles());
        $this->assertDatabaseRejects(fn () => DB::table('document_versions')->where('document_id', $documentId)->delete());

        // Con archivo adjunto, editar el entregable no cambia su documento.
        $edited = $one($this->putJson("/api/v1/projects/{$id}/deliverables/{$did}", ['name' => 'Informe final', 'document_ref' => 'otro'])->assertOk());
        $this->assertEquals('informe-v2.pdf', $edited['document_ref']);

        // Descargar: enlace temporal, con registro; el archivo ya vale como documento para enviar a revisión.
        $this->getJson("{$url}-url")->assertOk()->assertJsonStructure(['url', 'expires_in_minutes']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'action' => 'project.deliverable.attach']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'action' => 'project.deliverable.download']);
        $this->postJson("/api/v1/projects/{$id}/deliverables/{$did}/submit")->assertOk();
        $this->post($url, ['title' => 'Tarde', 'file' => $pdf()], ['Accept' => 'application/json'])->assertStatus(422);

        // Un entregable sin archivo no tiene qué descargar.
        $plain = $this->postJson("/api/v1/projects/{$id}/deliverables", ['name' => 'Manual'])->assertCreated()->json('deliverables.1.id');
        $this->getJson("/api/v1/projects/{$id}/deliverables/{$plain}/document-url")->assertStatus(404);

        // Adjuntar pide gestionar entregables; descargar, verlos; y siempre dentro de los proyectos que se alcanzan.
        $plainUrl = "/api/v1/projects/{$id}/deliverables/{$plain}/document";
        $this->as($this->makeUser('Gerencia', self::MANAGEMENT));
        $this->post($plainUrl, ['title' => 'X', 'file' => $pdf()], ['Accept' => 'application/json'])->assertStatus(403);
        $this->getJson("{$url}-url")->assertOk();
        $this->as($this->makeUser('Solo proyectos', ['projects.view']));
        $this->getJson("{$url}-url")->assertStatus(403);
        $this->as($this->makeUser('Técnico ajeno', self::TECHNICIAN, 'OWN'));
        $this->post($plainUrl, ['title' => 'X', 'file' => $pdf()], ['Accept' => 'application/json'])->assertStatus(404);
        $this->getJson("{$url}-url")->assertStatus(404);
    }

    // ------------------------------------------------------------------ Horas y costos

    public function test_hours_do_not_overlap_and_nobody_approves_their_own(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $tito = $this->makeUser('Tito', [...self::TECHNICIAN, 'work_orders.view', 'work_orders.manage'], 'OWN');
        $project = $this->running($admin);
        $pid = $project['id'];
        $task = $this->task($pid, ['assigned_user_id' => $tito->id]);
        $order = $this->order($admin, $this->makeContracted(), ['assigned_user_id' => $tito->id])['id'];
        $slot = fn (int $from, int $to, int $day = 1) => [
            'started_at' => now()->subDays($day)->setTime($from, 0)->toIso8601String(), 'ended_at' => now()->subDays($day)->setTime($to, 0)->toIso8601String(),
        ];

        $this->as($tito);
        $this->postJson('/api/v1/time-entries', [])->assertStatus(422)->assertJsonValidationErrors(['started_at', 'ended_at']);
        $this->postJson('/api/v1/time-entries', $slot(9, 11))->assertStatus(422)->assertJsonValidationErrors('project_id');
        $this->postJson('/api/v1/time-entries', [...$slot(9, 11), 'project_id' => $pid, 'work_order_id' => $order])->assertStatus(422)->assertJsonValidationErrors('project_id');
        $this->postJson('/api/v1/time-entries', [...$slot(11, 9), 'project_id' => $pid])->assertStatus(422)->assertJsonValidationErrors('ended_at');
        $this->postJson('/api/v1/time-entries', [...$slot(6, 19), 'project_id' => $pid])->assertStatus(422)->assertJsonValidationErrors('ended_at');
        $this->postJson('/api/v1/time-entries', [...$slot(9, 11), 'project_id' => (string) Str::uuid()])->assertStatus(422);
        $this->postJson('/api/v1/time-entries', [...$slot(9, 11), 'project_id' => $pid, 'task_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('task_id');

        $entry = $this->hours($tito, ['project_id' => $pid, 'task_id' => $task['id'], 'description' => 'Reunión de inicio']);
        $this->assertEquals(['SUBMITTED', 2.0, 100.0, true, 'Levantamiento', 'Tito', true], [
            $entry['status'], $entry['hours'], $entry['cost'], $entry['billable'], $entry['task'], $entry['user']['name'], $entry['own'],
        ]);

        // No se solapan: ni en el mismo proyecto ni en una orden de trabajo.
        $this->postJson('/api/v1/time-entries', [...$slot(10, 12), 'project_id' => $pid])->assertStatus(422)->assertJsonPath('errors.started_at.0', 'Se solapa con otro registro de horas tuyo.');
        $this->postJson('/api/v1/time-entries', [...$slot(10, 12), 'work_order_id' => $order])->assertStatus(422);
        $onOrder = $this->hours($tito, ['work_order_id' => $order, 'billable' => false], 1, 11, 13);
        $this->assertEquals([false, null], [$onOrder['billable'], $onOrder['project']]);
        // La base tampoco lo admite.
        $this->assertDatabaseRejects(fn () => DB::table('time_entries')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $tito->id, 'project_id' => $pid,
            'started_at' => now()->subDay()->setTime(10, 0), 'ended_at' => now()->subDay()->setTime(10, 30), 'hours' => 0.5,
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('time_entries')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $tito->id,
            'started_at' => now()->subDays(9)->setTime(10, 0), 'ended_at' => now()->subDays(9)->setTime(11, 0), 'hours' => 1,
        ]));

        // El técnico no aprueba (no tiene el permiso); quien aprueba no aprueba las suyas.
        $this->postJson("/api/v1/time-entries/{$entry['id']}/approve")->assertStatus(403);
        $own = $this->hours($admin, ['project_id' => $pid]);
        $this->postJson("/api/v1/time-entries/{$own['id']}/approve")->assertStatus(422)->assertJsonPath('errors.status.0', 'No puedes aprobar tus propias horas: las revisa otra persona.');
        $this->postJson("/api/v1/time-entries/{$own['id']}/reject", ['reason' => 'x'])->assertStatus(422);
        $this->assertDatabaseRejects(fn () => DB::table('time_entries')->where('id', $own['id'])->update(['approved_by' => $admin->id]));

        $this->postJson("/api/v1/time-entries/{$entry['id']}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->postJson("/api/v1/time-entries/{$entry['id']}/approve")->assertStatus(422);
        $this->postJson("/api/v1/time-entries/{$onOrder['id']}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/time-entries/{$onOrder['id']}/reject", ['reason' => 'Ese día no hubo visita'])->assertOk()
            ->assertJsonPath('status', 'REJECTED')->assertJsonPath('reject_reason', 'Ese día no hubo visita');
        $this->assertDatabaseRejects(fn () => DB::table('time_entries')->where('id', $own['id'])->update(['status' => 'REJECTED']));
        // Unas horas rechazadas liberan su horario.
        $this->hours($tito, ['work_order_id' => $order], 1, 11, 13);

        // Solo las aprobadas cuentan para el costo del proyecto; las enviadas se ven por aprobar.
        $metrics = $this->getJson("/api/v1/projects/{$pid}")->assertOk()->json('metrics');
        $this->assertEquals([2.0, 2.0, 100.0, 660.0], [$metrics['hours'], $metrics['pending_hours'], $metrics['labor_cost'], $metrics['margin']]);
        // La orden de trabajo suma sus horas.
        $this->assertEquals(4.0, (float) $this->getJson("/api/v1/work-orders/{$order}")->json('hours'));

        $this->assertEquals(
            ['time_entry.approve', 'time_entry.create'],
            DB::table('audit_logs')->where('entity_type', 'TimeEntry')->where('entity_id', $entry['id'])->orderBy('action')->pluck('action')->all(),
        );
    }

    public function test_costs_are_approved_by_someone_else_and_count_in_the_margin(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $lead = $this->makeUser('Jefa de proyecto', self::OPERATIONS);
        $pid = $this->running($admin)['id'];
        $cost = ['project_id' => $pid, 'description' => 'Licencias anuales', 'category' => 'Licencias', 'quantity' => 3, 'unit_cost' => 50.5];

        $this->as($lead);
        $this->postJson('/api/v1/cost-entries', [])->assertStatus(422)->assertJsonValidationErrors(['project_id', 'description', 'category', 'quantity', 'unit_cost']);
        $this->postJson('/api/v1/cost-entries', [...$cost, 'quantity' => 0])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson('/api/v1/cost-entries', [...$cost, 'unit_cost' => 0])->assertStatus(422)->assertJsonValidationErrors('unit_cost');
        $this->postJson('/api/v1/cost-entries', [...$cost, 'category' => 'Regalos'])->assertStatus(422);
        $this->postJson('/api/v1/cost-entries', [...$cost, 'project_id' => (string) Str::uuid()])->assertStatus(422);

        $entry = $this->postJson('/api/v1/cost-entries', $cost)->assertCreated()->json();
        $this->assertEquals(['SUBMITTED', 151.5, 'Jefa de proyecto', true], [$entry['status'], $entry['total'], $entry['creator']['name'], $entry['own']]);

        // Quien lo registró no lo aprueba (segregación de funciones).
        $this->postJson("/api/v1/cost-entries/{$entry['id']}/approve")->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'No puedes aprobar un costo que registraste tú: lo aprueba otra persona.');
        $this->assertEquals(0.0, $this->getJson("/api/v1/projects/{$pid}")->json('metrics.other_cost'));

        $this->as($admin);
        $this->postJson("/api/v1/cost-entries/{$entry['id']}/approve")->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('own', false);
        $this->postJson("/api/v1/cost-entries/{$entry['id']}/reject", ['reason' => 'x'])->assertStatus(422);
        $metrics = $this->getJson("/api/v1/projects/{$pid}")->json('metrics');
        $this->assertEquals([151.5, 151.5, 608.5, 80], [$metrics['other_cost'], $metrics['cost'], $metrics['margin'], $metrics['margin_pct']]);

        $second = $this->postJson('/api/v1/cost-entries', [...$cost, 'description' => 'Viáticos', 'category' => 'Materiales'])->assertCreated()->json('id');
        $this->as($lead);
        $this->postJson("/api/v1/cost-entries/{$second}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/cost-entries/{$second}/reject", ['reason' => 'No corresponde al proyecto'])->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertDatabaseRejects(fn () => DB::table('cost_entries')->where('id', $entry['id'])->update(['status' => 'REJECTED', 'reject_reason' => null]));
        $this->assertDatabaseRejects(fn () => DB::table('cost_entries')->where('id', $entry['id'])->update(['quantity' => 0]));

        // Con la segregación desactivada, quien lo registra puede aprobarlo.
        $third = $this->postJson('/api/v1/cost-entries', $cost)->assertCreated()->json('id');
        DB::table('tenants')->where('id', $this->tenantId)->update(['configuration' => json_encode(['segregation' => false])]);
        $this->postJson("/api/v1/cost-entries/{$third}/approve")->assertOk();

        // Listado: búsqueda, filtros, orden y exportar con su permiso.
        $this->getJson('/api/v1/cost-entries')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/v1/cost-entries?status=REJECTED')->assertJsonPath('total', 1)->assertJsonPath('data.0.description', 'Viáticos');
        $this->getJson('/api/v1/cost-entries?search=licencias')->assertJsonPath('total', 2);
        $this->getJson("/api/v1/cost-entries?project_id={$pid}&sort=cost&dir=desc")->assertJsonPath('data.0.description', 'Viáticos');
        $this->getJson('/api/v1/cost-entries?filters='.urlencode(json_encode([['id' => 'total', 'type' => 'number', 'operator' => 'greater', 'value' => '200']])))->assertJsonPath('total', 0);
        $this->getJson('/api/v1/cost-entries?export=1')->assertStatus(403);
        $this->as($admin);
        $this->getJson('/api/v1/cost-entries?export=1')->assertOk();
        $this->postJson('/api/v1/exports', ['resource' => 'cost_entries', 'format' => 'csv', 'rows' => 3])->assertOk();
    }

    // ------------------------------------------------------------------ Listados, alcance y permisos

    public function test_projects_and_hours_lists_search_filter_sort_and_export(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $tito = $this->makeUser('Tito', self::TECHNICIAN, 'OWN');
        $andes = $this->makeContracted(contract: $this->makeContract(customerId: $this->makeCustomer('Minera Los Andes SAC')));
        $sur = $this->makeContracted(contract: $this->makeContract(customerId: $this->makeCustomer('Agroindustrias del Sur SAC')));
        $erp = $this->running($admin, $andes, ['name' => 'Implementación ERP', 'start_date' => '2027-01-10']);
        $web = $this->project($admin, $sur, ['name' => 'Portal web', 'manager_user_id' => $tito->id, 'start_date' => '2027-03-01']);
        $task = $this->task($erp['id'], ['assigned_user_id' => $tito->id]);
        $this->postJson("/api/v1/projects/{$erp['id']}/tasks/{$task['id']}/move", ['status' => 'IN_PROGRESS'])->assertOk();
        $this->postJson("/api/v1/projects/{$erp['id']}/tasks/{$task['id']}/move", ['status' => 'DONE'])->assertOk();

        $list = $this->getJson('/api/v1/projects')->assertOk()->assertJsonPath('total', 2);
        $row = collect($list->json('data'))->firstWhere('id', $erp['id']);
        $this->assertEquals(['Implementación ERP', 'IN_PROGRESS', 100, 'Admin'], [$row['name'], $row['status'], $row['metrics']['progress'], $row['manager']['name']]);
        $this->assertEquals('Minera Los Andes SAC', $row['customer']['party']['organization']['legal_name']);

        $this->getJson('/api/v1/projects?status=DRAFT')->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Portal web');
        $this->getJson('/api/v1/projects?search=agroindustrias')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/projects?search='.$erp['code'])->assertJsonPath('total', 1);
        foreach (['project' => $erp['id'], 'customer' => $web['id'], 'manager' => $erp['id'], 'start' => $erp['id'], 'status' => $web['id']] as $sort => $first) {
            $this->getJson("/api/v1/projects?sort={$sort}&dir=asc")->assertOk()->assertJsonPath('data.0.id', $first);
        }
        $this->getJson('/api/v1/projects?sort=progress&dir=desc')->assertJsonPath('data.0.id', $erp['id']);
        $this->getJson('/api/v1/projects?sort=margin&dir=desc')->assertOk()->assertJsonPath('total', 2);
        $filter = fn (string $path, array $f) => "/api/v1/{$path}?filters=".urlencode(json_encode([$f]));
        $this->getJson($filter('projects', ['id' => 'manager', 'type' => 'text', 'operator' => 'contains', 'value' => 'tito']))->assertJsonPath('total', 1);
        $this->getJson($filter('projects', ['id' => 'project', 'type' => 'text', 'operator' => 'contains', 'value' => 'portal']))->assertJsonPath('total', 1);
        $this->getJson('/api/v1/projects?per_page=5000')->assertJsonPath('per_page', 200);
        $this->getJson('/api/v1/projects?export=1')->assertOk();
        $this->postJson('/api/v1/exports', ['resource' => 'projects', 'format' => 'xlsx', 'rows' => 2])->assertOk();

        // El formulario ofrece servicios activos y personas que pueden ver proyectos.
        $options = $this->getJson('/api/v1/projects/options')->assertOk();
        $this->assertEquals(['Admin', 'Tito'], $options->json('people.*.name'));
        $this->assertCount(2, $options->json('contracted_services'));

        // Horas: lo propio, lo de los demás, búsqueda, filtros, orden, indicadores.
        $mine = $this->hours($admin, ['project_id' => $erp['id'], 'description' => 'Kickoff'], 1, 9, 11);
        $his = $this->hours($tito, ['project_id' => $erp['id'], 'task_id' => $task['id']], 2, 9, 13);
        $this->as($admin);
        $this->getJson('/api/v1/time-entries')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $mine['id']);
        $this->getJson('/api/v1/time-entries?mine=1')->assertJsonPath('total', 1)->assertJsonPath('data.0.own', true);
        $this->getJson('/api/v1/time-entries?others=1&status=SUBMITTED')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $his['id']);
        $this->getJson("/api/v1/time-entries?project_id={$web['id']}")->assertJsonPath('total', 0);
        $this->getJson('/api/v1/time-entries?search=kickoff')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/time-entries?search=tito')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/time-entries?search=implementación')->assertJsonPath('total', 2);
        $this->getJson('/api/v1/time-entries?sort=hours&dir=desc')->assertJsonPath('data.0.id', $his['id']);
        $this->getJson('/api/v1/time-entries?sort=person&dir=desc')->assertJsonPath('data.0.id', $his['id']);
        $this->getJson($filter('time-entries', ['id' => 'hours', 'type' => 'number', 'operator' => 'greater', 'value' => '3']))->assertJsonPath('total', 1);
        $this->getJson($filter('time-entries', ['id' => 'context', 'type' => 'text', 'operator' => 'contains', 'value' => 'erp']))->assertJsonPath('total', 2);
        $this->getJson('/api/v1/time-entries/summary')->assertOk()->assertJson(['mine' => 1, 'week_hours' => 2.0, 'to_approve' => 1, 'costs_to_approve' => 0]);
        $this->getJson('/api/v1/time-entries?export=1')->assertOk();

        $where = $this->getJson('/api/v1/time-entries/options')->assertOk();
        $this->assertEquals(['Portal web', 'Implementación ERP'], $where->json('projects.*.name'));
        // Solo las tareas abiertas (la del ERP ya está hecha).
        $this->assertEquals([], collect($where->json('projects'))->firstWhere('id', $erp['id'])['tasks']);

        // El técnico ve solo sus horas y solo los proyectos donde participa; no exporta.
        $this->as($tito);
        $this->getJson('/api/v1/time-entries')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $his['id']);
        $this->getJson('/api/v1/time-entries/summary')->assertJson(['mine' => 1, 'to_approve' => 0]);
        $this->getJson('/api/v1/time-entries?export=1')->assertStatus(403);
        $this->getJson('/api/v1/projects?export=1')->assertStatus(403);
        $this->postJson("/api/v1/time-entries/{$mine['id']}/approve")->assertStatus(403);
    }

    public function test_projects_hours_and_costs_respect_scope_permissions_module_and_company(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', self::ADMIN);
        $lima = $this->makeBranch('Lima');
        $cusco = $this->makeBranch('Cusco');
        $tito = $this->makeUser('Tito', self::TECHNICIAN, 'OWN', $lima);
        $rosa = $this->makeUser('Rosa', self::TECHNICIAN, null, $cusco);
        $inLima = $this->running($admin, $this->makeContracted(['branch_id' => $lima]), ['name' => 'Proyecto Lima']);
        $inCusco = $this->running($admin, $this->makeContracted(['branch_id' => $cusco]), ['name' => 'Proyecto Cusco', 'manager_user_id' => $rosa->id]);
        $this->task($inLima['id'], ['assigned_user_id' => $tito->id]);

        // Propio: el proyecto que lleva o donde tiene una tarea.
        $this->as($tito);
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $inLima['id']);
        $this->getJson("/api/v1/projects/{$inCusco['id']}")->assertStatus(404);
        $this->postJson("/api/v1/projects/{$inCusco['id']}/tasks", ['title' => 'X'])->assertStatus(404);
        $this->withHeaders(['If-Match' => '99'])->postJson("/api/v1/projects/{$inCusco['id']}/tasks", ['title' => 'X'])->assertStatus(404);
        $this->flushHeaders();
        $this->postJson('/api/v1/time-entries', [
            'project_id' => $inCusco['id'], 'started_at' => now()->subHours(3)->toIso8601String(), 'ended_at' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('project_id');
        $this->postJson('/api/v1/cost-entries', ['project_id' => $inCusco['id'], 'description' => 'X', 'category' => 'Materiales', 'quantity' => 1, 'unit_cost' => 10])->assertStatus(422);
        // Trabaja las tareas del suyo; pausar, editar, crear y cerrar proyectos no le corresponde.
        $this->postJson("/api/v1/projects/{$inLima['id']}/tasks", ['title' => 'Mi tarea'])->assertCreated();
        $this->postJson("/api/v1/projects/{$inLima['id']}/hold", ['reason' => 'x'])->assertStatus(403);
        $this->putJson("/api/v1/projects/{$inLima['id']}", ['name' => 'X', 'budget_cost' => 0])->assertStatus(403);
        $this->postJson("/api/v1/projects/{$inLima['id']}/cancel", ['reason' => 'x'])->assertStatus(403);
        $this->postJson("/api/v1/projects/{$inLima['id']}/complete")->assertStatus(403);
        $this->postJson('/api/v1/projects', ['contracted_service_id' => (string) Str::uuid(), 'name' => 'X'])->assertStatus(403);
        // El contrato de su proyecto queda fuera de lo que ve: no se le entrega.
        $this->getJson("/api/v1/projects/{$inLima['id']}")->assertOk()->assertJsonPath('contract', null);

        $hisHours = $this->hours($tito, ['project_id' => $inLima['id']]);
        $herHours = $this->hours($rosa, ['project_id' => $inCusco['id']]);
        $this->as($rosa);
        $herCost = $this->postJson('/api/v1/cost-entries', ['project_id' => $inCusco['id'], 'description' => 'Pasajes', 'category' => 'Materiales', 'quantity' => 1, 'unit_cost' => 80])->assertCreated()->json('id');
        // Un rol sin alcance definido alcanza solo lo propio.
        $this->getJson('/api/v1/projects')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $inCusco['id']);
        $this->getJson('/api/v1/time-entries')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $herHours['id']);
        $this->getJson('/api/v1/cost-entries')->assertJsonPath('total', 1);

        // Alcance Sede: aprueba las horas y los costos de su sede, no los de otra.
        $lead = $this->makeUser('Jefa de Lima', self::OPERATIONS, 'BRANCH', $lima);
        $this->as($lead);
        $this->getJson('/api/v1/projects')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $inLima['id']);
        $this->getJson('/api/v1/time-entries')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $hisHours['id']);
        $this->getJson('/api/v1/cost-entries')->assertJsonPath('total', 0);
        $this->postJson("/api/v1/time-entries/{$hisHours['id']}/approve")->assertOk();
        $this->postJson("/api/v1/time-entries/{$herHours['id']}/approve")->assertStatus(404);
        $this->postJson("/api/v1/cost-entries/{$herCost}/approve")->assertStatus(404);
        // Las horas de un proyecto nacen en la sede del proyecto.
        $this->assertEquals($lima, DB::table('time_entries')->where('id', $hisHours['id'])->value('branch_id'));

        // Gerencia aprueba y cierra, pero no ejecuta ni registra.
        $manager = $this->makeUser('Gerencia', self::MANAGEMENT);
        $this->as($manager);
        $this->getJson('/api/v1/projects')->assertJsonPath('total', 2);
        $this->postJson("/api/v1/time-entries/{$herHours['id']}/approve")->assertOk();
        $this->postJson("/api/v1/cost-entries/{$herCost}/approve")->assertOk();
        $this->postJson("/api/v1/projects/{$inCusco['id']}/hold", ['reason' => 'x'])->assertStatus(403);
        $this->postJson("/api/v1/projects/{$inCusco['id']}/tasks", ['title' => 'X'])->assertStatus(403);
        $this->postJson('/api/v1/time-entries', ['project_id' => $inCusco['id'], 'started_at' => now()->subHours(3)->toIso8601String(), 'ended_at' => now()->subHour()->toIso8601String()])->assertStatus(403);
        $this->postJson('/api/v1/cost-entries', ['project_id' => $inCusco['id'], 'description' => 'X', 'category' => 'Materiales', 'quantity' => 1, 'unit_cost' => 10])->assertStatus(403);
        // El margen del proyecto usa todo lo aprobado, lo alcance o no quien lo mira.
        $this->as($rosa);
        $this->assertEquals([100.0, 80.0], [$this->getJson("/api/v1/projects/{$inCusco['id']}")->json('metrics.labor_cost'), $this->getJson("/api/v1/projects/{$inCusco['id']}")->json('metrics.other_cost')]);

        // Ediciones simultáneas en el proyecto.
        $this->as($admin);
        $version = (string) DB::table('projects')->where('id', $inCusco['id'])->value('lock_version');
        $this->withHeaders(['If-Match' => $version])->postJson("/api/v1/projects/{$inCusco['id']}/hold", ['reason' => 'Pausa'])->assertOk();
        $this->withHeaders(['If-Match' => $version])->postJson("/api/v1/projects/{$inCusco['id']}/resume")->assertStatus(409);
        $this->flushHeaders();

        // Otra empresa no existe; sin los módulos, todo cerrado.
        $this->makeTenant();
        $this->as($this->makeUser('Otra empresa', self::ADMIN));
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/projects/{$inLima['id']}")->assertStatus(404);
        $this->getJson('/api/v1/time-entries')->assertJsonPath('total', 0);
        $this->postJson("/api/v1/time-entries/{$hisHours['id']}/reject", ['reason' => 'x'])->assertStatus(404);
        $this->assertEquals('PRY-'.date('Y').'-0001', $this->project(auth()->user())['code']);

        $this->makeTenant(['ORDENES_TRABAJO', 'CONTRATOS']);
        $this->as($this->makeUser('Sin módulos', self::ADMIN));
        $this->getJson('/api/v1/projects')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
        $this->getJson('/api/v1/time-entries')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
        $this->getJson('/api/v1/cost-entries')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_roles_matrix_and_audit_know_the_new_resources(): void
    {
        $this->makeTenant();
        $this->as($this->makeUser('Administra usuarios', ['users.view']));

        $roles = $this->getJson('/api/v1/roles')->assertOk();
        foreach (self::ADMIN as $code) {
            if (preg_match('/^(projects|tasks|time_entries|cost_entries|deliverables)\./', $code)) {
                $this->assertContains($code, $roles->json('permissions.*.code'));
            }
        }
        foreach (['projects', 'time_entries', 'cost_entries'] as $resource) {
            $this->assertContains($resource, $roles->json('scope_resources.*.code'));
        }

        // Todo lo que se registra tiene su texto en la pantalla Auditoría.
        foreach ([
            'project.create', 'project.update', 'project.plan', 'project.start', 'project.hold', 'project.resume', 'project.complete', 'project.cancel',
            'project.task.create', 'project.task.update', 'project.task.move', 'project.task.check', 'project.task.uncheck',
            'project.deliverable.create', 'project.deliverable.update', 'project.deliverable.submit', 'project.deliverable.approve',
            'project.deliverable.return', 'project.deliverable.deliver',
            'time_entry.create', 'time_entry.approve', 'time_entry.reject', 'cost_entry.create', 'cost_entry.approve', 'cost_entry.reject',
        ] as $action) {
            $this->assertNotSame($action, AuditCatalog::actionLabel($action), "Falta el texto de {$action}");
        }
        $this->assertEquals(['Proyecto', 'Registro de horas', 'Costo'], [AuditCatalog::entityLabel('Project'), AuditCatalog::entityLabel('TimeEntry'), AuditCatalog::entityLabel('CostEntry')]);
    }
}
