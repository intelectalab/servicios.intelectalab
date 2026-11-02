<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Importación masiva: validar no crea nada y dice qué pasará con cada fila; importar crea solo
 * las filas válidas y deja el historial (también desde el listado de cada módulo). Leads con su
 * plantilla. Importar pide imports.create además del permiso de la entidad; el historial sigue el
 * alcance; una entidad fuera del plan no se ofrece.
 */
class BulkImportTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const IMPORTER = ['imports.view', 'imports.create', 'core.view', 'core.manage', 'services.view', 'services.manage', 'leads.view', 'leads.manage'];

    private function csv(string $name, array $lines): array
    {
        return ['file' => UploadedFile::fake()->createWithContent($name, implode("\n", $lines))];
    }

    private function services(): array
    {
        return $this->csv('servicios.csv', [
            'codigo;nombre;categoria;modalidad;frecuencia;precio',
            'IMP-001;Auditoría de red;Redes;Único;;3200',
            'IMP-002;Monitoreo 24x7;Soporte;Recurrente;Mensual;1400',
            ';Sin código;Soporte;Único;;100',
        ]);
    }

    public function test_validating_creates_nothing_and_reports_each_row(): void
    {
        $this->makeTenant(['SERVICIOS', 'CRM']);
        $this->as($this->makeUser('Importa', self::IMPORTER));

        $check = $this->post('/api/v1/services/import', $this->services() + ['dry_run' => 1], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame([true, 2, 1, 3], [$check->json('dry_run'), $check->json('imported'), $check->json('failed'), $check->json('total')]);
        $this->assertSame(['created', 'created', 'error'], array_column($check->json('rows'), 'status'));
        $this->assertSame('Auditoría de red', $check->json('rows.0.values.nombre'));
        $this->assertStringContainsString('obligatorios', $check->json('rows.2.message'));
        $this->assertSame(0, DB::table('services')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(0, DB::table('service_categories')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(0, DB::table('import_jobs')->where('tenant_id', $this->tenantId)->count());

        // Importar: entran las dos válidas y queda en el historial como "Con omisiones".
        $this->post('/api/v1/services/import', $this->services(), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('warnings.0', 'Fila 4: Código, nombre y categoría son obligatorios.');
        $this->assertSame(2, DB::table('services')->where('tenant_id', $this->tenantId)->count());

        $jobs = $this->getJson('/api/v1/import-jobs')->assertOk();
        $this->assertSame(1, $jobs->json('total'));
        $this->assertSame(['services', 'servicios.csv', 2, 1, 'PARTIAL'], [
            $jobs->json('data.0.entity'), $jobs->json('data.0.file'), $jobs->json('data.0.created'), $jobs->json('data.0.failed'), $jobs->json('data.0.status'),
        ]);
        $detail = $this->getJson('/api/v1/import-jobs/'.$jobs->json('data.0.id'))->assertOk();
        $this->assertSame([4, 'error'], [$detail->json('details.0.row'), $detail->json('details.0.status')]);

        // Un archivo sin ninguna fila válida también queda, como fallido.
        $this->post('/api/v1/services/import', $this->services(), ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame('FAILED', $this->getJson('/api/v1/import-jobs?sort=date&dir=desc')->json('data.0.status'));
    }

    public function test_leads_are_imported_from_their_own_template(): void
    {
        $this->makeTenant(['CRM']);
        $user = $this->makeUser('Ventas', self::IMPORTER);
        $this->as($user);

        // La propia plantilla, tal como se descarga, entra completa.
        $template = UploadedFile::fake()->createWithContent('plantilla_leads.xlsx', $this->get('/api/v1/crm/leads/template')->assertOk()->streamedContent());
        $this->post('/api/v1/crm/leads/import', ['file' => $template], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('warnings', []);
        $leads = DB::table('crm_leads')->where('tenant_id', $this->tenantId)->orderBy('lead_number')->get();
        $this->assertCount(2, $leads);
        $this->assertSame(['NEW', $user->id], [$leads[0]->status, $leads[0]->owner_user_id]);
        $this->assertNotNull($leads[0]->score);

        // Fuente que no existe, sin empresa ni contacto, y correo con un lead abierto: no entran.
        $source = DB::table('crm_sources')->where('tenant_id', $this->tenantId)->value('name');
        $check = $this->post('/api/v1/crm/leads/import', $this->csv('leads.csv', [
            'empresa;contacto;correo;fuente',
            'Nueva SAC;Ana;ana@nueva.pe;'.$source,
            'Otra SAC;Luis;luis@otra.pe;Volante',
            ';;sin@nombre.pe;'.$source,
            "Repetida;Rosa;{$leads[0]->email};{$source}",
        ]) + ['dry_run' => 1], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(['created', 'error', 'error', 'error'], array_column($check->json('rows'), 'status'));
        $this->assertStringContainsString('«Volante» no existe', $check->json('rows.1.message'));
        $this->assertStringContainsString('lead abierto', $check->json('rows.3.message'));
        $this->assertSame(2, DB::table('crm_leads')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_importing_needs_its_permission_and_history_follows_the_scope(): void
    {
        $this->makeTenant(['SERVICIOS']);
        // Sin imports.create no se importa, aunque gestione servicios.
        $this->as($this->makeUser('Sin importar', ['services.view', 'services.manage', 'imports.view']));
        $this->post('/api/v1/services/import', $this->services(), ['Accept' => 'application/json'])->assertForbidden();

        $mine = $this->makeUser('Propio', self::IMPORTER, null);
        $this->as($mine);
        $this->post('/api/v1/services/import', $this->services(), ['Accept' => 'application/json'])->assertOk();
        // CRM no está en el plan: Leads no se ofrece; Clientes y actores (obligatorio) sí.
        $this->assertEqualsCanonicalizing(['parties', 'services'], array_column($this->getJson('/api/v1/imports')->assertOk()->json('entities'), 'key'));

        $other = $this->makeUser('Otro propio', self::IMPORTER, null);
        $this->as($other);
        $this->assertSame(0, $this->getJson('/api/v1/import-jobs')->assertOk()->json('total'));
        $job = DB::table('import_jobs')->where('tenant_id', $this->tenantId)->value('id');
        $this->getJson("/api/v1/import-jobs/{$job}")->assertNotFound();

        // Toda la empresa: lo ve. Sin imports.view no abre la pantalla.
        $this->as($this->makeUser('Gerencia', ['imports.view']));
        $this->assertSame(1, $this->getJson('/api/v1/import-jobs')->json('total'));
        $this->as($this->makeUser('Nada', ['services.view']));
        $this->getJson('/api/v1/imports')->assertForbidden();
    }

    public function test_the_history_cannot_be_edited_or_deleted(): void
    {
        $this->makeTenant(['SERVICIOS']);
        $this->as($this->makeUser('Importa', self::IMPORTER));
        $this->post('/api/v1/services/import', $this->services(), ['Accept' => 'application/json'])->assertOk();
        $job = DB::table('import_jobs')->where('tenant_id', $this->tenantId)->value('id');

        foreach ([
            fn () => DB::table('import_jobs')->where('id', $job)->update(['created_count' => 99]),
            fn () => DB::table('import_jobs')->where('id', $job)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('La base aceptó cambiar el historial.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }
    }
}
