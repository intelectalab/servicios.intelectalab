<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Documents\Antivirus\SimulatedScanner;
use App\Modules\Documents\Support\DocumentStore;
use App\Services\AuditCatalog;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Documentos: subir con vínculo, versiones que no se destruyen, antivirus (simulado) y cuarentena,
 * descarga por enlace temporal registrado, editar y archivar, "Visible en el portal" (permiso y
 * vínculo con un cliente), alcance por rol, la ficha de un registro y lo que garantiza la base.
 */
class DocumentsTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const ADMIN = [
        'documents.view', 'documents.create', 'documents.manage', 'documents.export',
        'contracts.view', 'projects.view', 'work_orders.view', 'core.view',
    ];

    /** Ventas: ve y sube lo suyo; no gestiona (no publica en el portal). */
    private const SALES = ['documents.view', 'documents.create', 'contracts.view', 'core.view'];

    /** Marca de prueba del antivirus simulado (el EICAR real lo borra el antivirus de Windows al escribirlo). */
    private const THREAT = SimulatedScanner::TEST_MARKER.' archivo de prueba';

    private function pdf(string $name = 'contrato.pdf', string $content = '%PDF-1.4 contrato firmado'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload(array $data, ?UploadedFile $file = null)
    {
        return $this->post('/api/v1/documents', [
            'title' => 'Contrato firmado', 'category' => 'Contrato', 'visibility' => 'INTERNAL', ...$data, 'file' => $file ?? $this->pdf(),
        ], ['Accept' => 'application/json']);
    }

    public function test_upload_versions_download_and_archive(): void
    {
        Storage::fake('documents');
        $this->makeTenant(['CONTRATOS', 'DOCUMENTOS']);
        $admin = $this->makeUser('Admin', self::ADMIN);
        [$contractId] = $this->makeContract($admin);
        $this->as($admin);

        // Obligatorios, lista cerrada de categorías y un vínculo que exista.
        $this->upload(['title' => '', 'category' => 'Inventada'])->assertStatus(422)->assertJsonValidationErrors(['title', 'category']);
        $this->upload(['link_type' => 'Contract', 'link_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('link_id');
        $this->upload(['link_type' => 'Invoice', 'link_id' => $contractId])->assertStatus(422)->assertJsonValidationErrors('link_type');

        $sha = hash('sha256', '%PDF-1.4 contrato firmado');
        $doc = $this->upload(['link_type' => 'Contract', 'link_id' => $contractId, 'sha256' => $sha])->assertCreated()->json();
        $this->assertEquals(['ACTIVE', 1, 'contrato.pdf', $sha, 'CLEAN', 'SIMULATED'], [
            $doc['status'], $doc['version'], $doc['file']['name'], $doc['file']['sha256'], $doc['file']['antivirus'], $doc['file']['engine'],
        ]);
        $this->assertSame(['Contract', $contractId, "/contracts/{$contractId}"], [$doc['links'][0]['type'], $doc['links'][0]['id'], $doc['links'][0]['href']]);
        $this->assertStringStartsWith('Contrato CTR-', $doc['links'][0]['label']);

        // Listado con filtros y la ficha del contrato.
        $this->getJson('/api/v1/documents?search=contrato.pdf')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/documents?visibility=PORTAL')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/documents?entity_type=Contract&entity_id={$contractId}")->assertOk()->assertJsonPath('data.0.id', $doc['id']);

        // Versión nueva; el mismo archivo otra vez no.
        $this->post("/api/v1/documents/{$doc['id']}/versions", ['file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $v2 = $this->post("/api/v1/documents/{$doc['id']}/versions", ['file' => $this->pdf('contrato-v2.pdf', '%PDF-1.4 adenda')], ['Accept' => 'application/json'])
            ->assertCreated()->json();
        $this->assertSame([2, 'Contrato firmado', [2, 1]], [$v2['version'], $v2['title'], array_column($v2['versions'], 'version')]);
        $this->assertCount(2, Storage::disk('documents')->allFiles());

        // Descarga por enlace temporal: de la última o de una anterior; queda registrada.
        $link = $this->getJson("/api/v1/documents/{$doc['id']}/download-url")->assertOk()->json();
        $this->assertSame(['contrato-v2.pdf', 2], [$link['name'], $link['version']]);
        $this->getJson("/api/v1/documents/{$doc['id']}/download-url?version=1")->assertOk()->assertJsonPath('name', 'contrato.pdf');
        $this->getJson("/api/v1/documents/{$doc['id']}/download-url?version=9")->assertNotFound();
        $accesses = $this->getJson("/api/v1/documents/{$doc['id']}")->assertOk()->json('accesses');
        $this->assertSame(2, count(array_keys(array_column($accesses, 'action'), 'document.download_url')));
        foreach (array_unique(array_column($accesses, 'action')) as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }

        // Editar y archivar; archivado no se edita ni recibe versiones, pero se puede descargar.
        $this->putJson("/api/v1/documents/{$doc['id']}", ['title' => 'Contrato y adenda', 'category' => 'Contrato', 'visibility' => 'INTERNAL'])
            ->assertOk()->assertJsonPath('title', 'Contrato y adenda');
        $this->postJson("/api/v1/documents/{$doc['id']}/archive")->assertOk()->assertJsonPath('status', 'ARCHIVED');
        $this->postJson("/api/v1/documents/{$doc['id']}/archive")->assertStatus(422);
        $this->putJson("/api/v1/documents/{$doc['id']}", ['title' => 'Otro', 'category' => 'Contrato', 'visibility' => 'INTERNAL'])->assertStatus(422);
        $this->post("/api/v1/documents/{$doc['id']}/versions", ['file' => $this->pdf('v3.pdf', 'tres')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->getJson("/api/v1/documents/{$doc['id']}/download-url")->assertOk();
    }

    public function test_the_antivirus_quarantines_and_the_file_is_never_handed_out(): void
    {
        Storage::fake('documents');
        $this->makeTenant(['CONTRATOS', 'DOCUMENTOS']);
        $admin = $this->makeUser('Admin', self::ADMIN);
        [$contractId] = $this->makeContract($admin);
        $this->as($admin);

        // Un archivo con amenaza (la marca de prueba): queda en cuarentena, aparte, aunque se pidiera "visible en el portal".
        $doc = $this->upload(['link_type' => 'Contract', 'link_id' => $contractId, 'visibility' => 'PORTAL'], $this->pdf('prueba.txt', self::THREAT))
            ->assertCreated()->json();
        $this->assertSame(['QUARANTINED', 'INTERNAL', 'INFECTED'], [$doc['status'], $doc['visibility'], $doc['file']['antivirus']]);
        $this->assertStringContainsString('/quarantine/', Storage::disk('documents')->allFiles()[0]);
        $this->getJson("/api/v1/documents/{$doc['id']}/download-url")->assertStatus(422);
        $this->putJson("/api/v1/documents/{$doc['id']}", ['title' => 'x', 'category' => 'Contrato', 'visibility' => 'PORTAL'])
            ->assertStatus(422)->assertJsonValidationErrors('visibility');

        // Una versión limpia lo deja disponible; la infectada sigue sin entregarse.
        $this->post("/api/v1/documents/{$doc['id']}/versions", ['file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('status', 'ACTIVE');
        $this->getJson("/api/v1/documents/{$doc['id']}/download-url")->assertOk();
        $this->getJson("/api/v1/documents/{$doc['id']}/download-url?version=1")->assertStatus(422);

        // En los adjuntos de otros módulos (entregable, evidencia, extracto) una amenaza se rechaza: no queda nada.
        $before = [DB::table('documents')->count(), count(Storage::disk('documents')->allFiles())];
        try {
            DocumentStore::store($this->tenantId, $admin->id, $this->pdf('foto.txt', self::THREAT), ['title' => 'Foto', 'category' => 'Evidencia', 'visibility' => 'INTERNAL']);
            $this->fail('Se guardó un archivo con amenaza.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('amenaza', $e->errors()['file'][0]);
        }
        $this->assertSame($before, [DB::table('documents')->count(), count(Storage::disk('documents')->allFiles())]);
    }

    public function test_only_who_manages_documents_shows_one_in_the_portal_and_the_customer_sees_it(): void
    {
        Storage::fake('documents');
        $this->makeTenant(['CONTRATOS', 'DOCUMENTOS', 'PORTAL', 'FINANZAS']);
        $admin = $this->makeUser('Admin', self::ADMIN);
        $sales = $this->makeUser('Ventas', self::SALES, 'OWN');
        [$contractId, $customerId] = $this->makeContract($sales);
        $partyId = DB::table('customers')->where('id', $customerId)->value('party_id');

        // Ventas sube, pero no publica en el portal.
        $this->as($sales);
        $this->upload(['link_type' => 'Contract', 'link_id' => $contractId, 'visibility' => 'PORTAL'])->assertForbidden();
        $doc = $this->upload(['link_type' => 'Contract', 'link_id' => $contractId])->assertCreated()->json();
        $this->putJson("/api/v1/documents/{$doc['id']}", ['title' => 'x', 'category' => 'Contrato', 'visibility' => 'PORTAL'])->assertForbidden();

        // Sin vínculo con un cliente no hay a quién mostrárselo.
        $this->as($admin);
        $this->upload(['visibility' => 'PORTAL'])->assertStatus(422)->assertJsonValidationErrors('visibility');
        $this->putJson("/api/v1/documents/{$doc['id']}", ['title' => 'Contrato firmado', 'category' => 'Contrato', 'visibility' => 'PORTAL'])
            ->assertOk()->assertJsonPath('visibility', 'PORTAL');
        $byParty = $this->upload(['title' => 'Ficha RUC', 'category' => 'Cliente', 'link_type' => 'Party', 'link_id' => $partyId, 'visibility' => 'PORTAL'],
            $this->pdf('ruc.pdf', '%PDF ruc'))->assertCreated()->json('id');
        $internal = $this->upload(['title' => 'Notas internas', 'category' => 'Otros', 'link_type' => 'Contract', 'link_id' => $contractId],
            $this->pdf('notas.pdf', '%PDF notas'))->assertCreated()->json('id');

        // El cliente ve los dos publicados (no el interno) y los descarga.
        $client = User::create(['email' => 'cliente-'.Str::random(6).'@cliente.test', 'password_hash' => bcrypt('Cliente123'), 'name' => 'Ana Cliente', 'status' => 'ACTIVE', 'mfa_enabled' => true]);
        DB::table('portal_users')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $client->id, 'customer_id' => $customerId, 'role' => 'PORTAL_ADMIN', 'status' => 'ACTIVE', 'created_at' => now()]);
        $this->as($client);
        $files = collect($this->getJson('/api/v1/portal/documents')->assertOk()->json())->where('kind', 'file');
        $this->assertEqualsCanonicalizing([$doc['id'], $byParty], $files->pluck('id')->all());
        $this->postJson("/api/v1/portal/documents/{$byParty}/temporary-url")->assertOk();
        $this->postJson("/api/v1/portal/documents/{$internal}/temporary-url")->assertNotFound();

        // Archivado, deja de verse en el portal; la descarga del cliente quedó en su registro de accesos.
        $this->as($admin);
        $this->postJson("/api/v1/documents/{$byParty}/archive")->assertOk();
        $this->assertContains('document.portal_download', array_column($this->getJson("/api/v1/documents/{$byParty}")->json('accesses'), 'action'));
        $this->as($client);
        $this->assertSame([$doc['id']], collect($this->getJson('/api/v1/portal/documents')->json())->where('kind', 'file')->pluck('id')->values()->all());
    }

    public function test_scope_record_cards_extracts_and_other_tenants(): void
    {
        Storage::fake('documents');
        $this->makeTenant(['CONTRATOS', 'DOCUMENTOS', 'FINANZAS']);
        $admin = $this->makeUser('Admin', self::ADMIN);
        $sales = $this->makeUser('Ventas', self::SALES, 'OWN');
        $other = $this->makeUser('Ventas 2', self::SALES, 'OWN');
        [$contractId, $customerId] = $this->makeContract($sales);
        $partyId = DB::table('customers')->where('id', $customerId)->value('party_id');

        $this->as($admin);
        $byAdmin = $this->upload(['link_type' => 'Contract', 'link_id' => $contractId])->assertCreated()->json('id');
        $loose = $this->upload(['title' => 'Dominio', 'category' => 'Dominio'], $this->pdf('dominio.txt', 'dominio'))->assertCreated()->json('id');

        // Ventas (lo propio) no lo ve en Documentos, pero sí en la ficha de su contrato y del cliente.
        $this->as($sales);
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/documents?entity_type=Contract&entity_id={$contractId}")->assertOk()->assertJsonPath('data.0.id', $byAdmin);
        $this->getJson("/api/v1/documents?entity_type=Party&entity_id={$partyId}")->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/v1/documents/{$byAdmin}")->assertOk();
        $this->getJson("/api/v1/documents/{$loose}")->assertNotFound();
        $this->assertSame(1, count($this->getJson("/api/v1/parties/{$partyId}")->assertOk()->json('documents')));
        // Lo que sube es suyo.
        $mine = $this->upload(['link_type' => 'Contract', 'link_id' => $contractId], $this->pdf('mio.pdf', 'mio'))->assertCreated()->json('id');
        $this->getJson('/api/v1/documents')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $mine);

        // Otra persona de Ventas no ve ese contrato: tampoco sus documentos.
        $this->as($other);
        $this->getJson("/api/v1/documents?entity_type=Contract&entity_id={$contractId}")->assertNotFound();
        $this->getJson("/api/v1/documents/{$mine}")->assertNotFound();
        $this->upload(['link_type' => 'Contract', 'link_id' => $contractId])->assertStatus(422)->assertJsonValidationErrors('link_id');
        $this->getJson('/api/v1/documents?export=1')->assertForbidden();

        // Un extracto bancario solo con permiso de Tesorería.
        DB::table('document_links')->insert(['tenant_id' => $this->tenantId, 'document_id' => $loose, 'entity_type' => 'BankStatement', 'entity_id' => (string) Str::uuid()]);
        $finance = $this->makeUser('Finanzas', ['documents.view'], 'TENANT');
        $this->as($finance);
        $this->assertNotContains($loose, array_column($this->getJson('/api/v1/documents')->json('data'), 'id'));
        $this->as($this->makeUser('Tesorería', ['documents.view', 'treasury.view'], 'TENANT'));
        $this->assertContains($loose, array_column($this->getJson('/api/v1/documents?per_page=50')->json('data'), 'id'));

        // Otra empresa: no existe.
        $this->makeTenant(['DOCUMENTOS']);
        $stranger = $this->makeUser('Admin B', self::ADMIN);
        $this->as($stranger);
        $this->getJson("/api/v1/documents/{$byAdmin}")->assertNotFound();
        $this->getJson('/api/v1/documents')->assertJsonPath('total', 0);
    }

    public function test_the_database_keeps_documents_versions_and_links(): void
    {
        Storage::fake('documents');
        $this->makeTenant(['CONTRATOS', 'DOCUMENTOS']);
        $admin = $this->makeUser('Admin', self::ADMIN);
        [$contractId] = $this->makeContract($admin);
        $this->as($admin);
        $id = $this->upload(['link_type' => 'Contract', 'link_id' => $contractId])->assertCreated()->json('id');
        $this->postJson("/api/v1/documents/{$id}/archive")->assertOk();

        $this->assertDatabaseRejects(fn () => DB::table('documents')->where('id', $id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('document_versions')->where('document_id', $id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('document_versions')->where('document_id', $id)->update(['version' => 7]));
        $this->assertDatabaseRejects(fn () => DB::table('document_links')->where('document_id', $id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('file_objects')->where('tenant_id', $this->tenantId)->update(['antivirus_status' => 'CLEAN']));
        $this->assertDatabaseRejects(fn () => DB::table('documents')->where('id', $id)->update(['status' => 'ACTIVE', 'archived_at' => null]));
        $this->assertDatabaseRejects(fn () => DB::table('documents')->where('id', $id)->update(['title' => 'Otro']));
    }
}
