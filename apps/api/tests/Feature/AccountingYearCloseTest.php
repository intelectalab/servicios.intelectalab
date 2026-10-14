<?php

namespace Tests\Feature;

use App\Modules\Accounting\Support\Chart;
use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Cierre del ejercicio (asiento de cierre a resultados acumulados, diciembre y presupuesto
 * cerrados, reapertura por extorno) e importación de asientos desde Excel o CSV.
 */
class AccountingYearCloseTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const ACCOUNTANT = [
        'accounting.view', 'accounting.create', 'accounting.manage', 'accounting.approve', 'accounting.export',
        'budgets.view', 'budgets.manage', 'budgets.approve',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Chart::forget();
    }

    private function entry(string $date, string $debit, string $credit, float $amount, string $description = 'Movimiento'): void
    {
        $this->postJson('/api/v1/journal-entries', ['date' => $date, 'description' => $description, 'lines' => [
            ['account' => $debit, 'debit' => $amount], ['account' => $credit, 'credit' => $amount],
        ]])->assertCreated();
    }

    private function year(int $year): array
    {
        return collect($this->getJson('/api/v1/accounting-years')->assertOk()->json())->firstWhere('year', $year);
    }

    public function test_closing_the_year_moves_results_to_retained_earnings_and_reopening_reverses_it(): void
    {
        $this->travelTo('2026-03-15 10:00:00');
        $this->makeTenant(['FINANZAS']);
        $this->as($this->makeUser('Contador', self::ACCOUNTANT));
        $this->entry('2026-03-10', '1041', '7041', 10000, 'Venta de servicios');
        $this->entry('2026-03-12', '6352', '1041', 4000, 'Alquiler');
        $budget = (string) Str::uuid();
        DB::table('budgets')->insert(['id' => $budget, 'tenant_id' => $this->tenantId, 'fiscal_year' => 2026, 'name' => 'Presupuesto 2026', 'status' => 'APPROVED', 'approved_at' => now()]);

        // En curso no se cierra.
        $this->postJson('/api/v1/accounting-years/2026/close')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertStringContainsString('aún no termina', $this->year(2026)['blocker']);

        $this->travelTo('2027-01-20 10:00:00');
        // Con un periodo de enero a noviembre abierto, tampoco.
        $this->postJson('/api/v1/accounting-years/2026/close')->assertStatus(422)->assertJsonFragment(['Cierra antes los periodos de enero a noviembre: siguen abiertos 2026-03.']);
        $this->postJson('/api/v1/accounting-periods/2026-03/close')->assertOk();
        // Solo quien aprueba en contabilidad.
        $this->as($this->makeUser('Gerencia', ['accounting.view', 'budgets.view', 'budgets.approve']));
        $this->assertSame(null, $this->year(2026)['blocker']);
        $this->postJson('/api/v1/accounting-years/2026/close')->assertStatus(403);
        $this->as($this->makeUser('Contador', self::ACCOUNTANT));

        $closed = $this->postJson('/api/v1/accounting-years/2026/close')->assertOk()->json();
        $this->assertSame(['CLOSED', 'CLOSED'], [$closed['status'], $closed['budget_status']]);
        $this->assertEquals(6000, $closed['result']);
        $entry = DB::table('acc_journal_entries')->where('id', $closed['closing_entry']['id'])->first();
        $this->assertSame(['YEAR_CLOSE', '2026-12-31', '2026-12'], [$entry->source_type, (string) $entry->entry_date, $entry->period]);
        $lines = DB::table('acc_journal_lines')->where('entry_id', $entry->id)->orderBy('position')->get()
            ->mapWithKeys(fn ($line) => [$line->account_code => [(float) $line->debit, (float) $line->credit]])->all();
        $this->assertEquals(['6352' => [0, 4000], '7041' => [10000, 0], '5911' => [0, 6000]], $lines);
        $this->assertTrue((bool) DB::table('acc_periods')->where('tenant_id', $this->tenantId)->where('period', '2026-12')->where('status', 'CLOSED')->exists());
        $this->assertSame('CLOSED', DB::table('budgets')->where('id', $budget)->value('status'));

        // El resultado del año se sigue viendo (sin el asiento de cierre) y el balance cuadra.
        $statements = $this->getJson('/api/v1/financial-statements?from=2026-01&to=2026-12')->assertOk()->json();
        $this->assertEquals(6000, $statements['kpis']['result']);
        $this->assertTrue($statements['balanced']);
        $sheet = collect($statements['balance_sheet'])->pluck('value', 'label');
        $this->assertEquals([0, 6000], [$sheet['Capital'], $sheet['Resultados acumulados']]);
        $this->assertTrue((bool) $this->getJson('/api/v1/ledger/trial-balance?from=2026-01&to=2026-12')->json('balanced'));

        // El asiento de cierre no se extorna desde el libro diario; diciembre ya no admite asientos.
        $this->postJson("/api/v1/journal-entries/{$entry->id}/reverse", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson('/api/v1/journal-entries', ['date' => '2026-12-20', 'description' => 'Tarde', 'lines' => [
            ['account' => '6352', 'debit' => 1], ['account' => '1041', 'credit' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->postJson('/api/v1/accounting-years/2026/close')->assertStatus(422);
        // La base: el asiento de cierre cae el 31 de diciembre.
        $this->assertDatabaseRejects(fn () => DB::table('acc_journal_entries')->insert([
            'tenant_id' => $this->tenantId, 'entry_number' => 'AS-X', 'entry_date' => '2027-01-05', 'period' => '2027-01', 'description' => 'Cierre', 'source_type' => 'YEAR_CLOSE',
        ]));

        // Reabrir: con motivo; extorna el cierre el mismo 31 de diciembre y reabre diciembre y el presupuesto.
        $this->postJson('/api/v1/accounting-years/2026/reopen')->assertStatus(422)->assertJsonValidationErrors('reason');
        $reopened = $this->postJson('/api/v1/accounting-years/2026/reopen', ['reason' => 'Falta provisionar una factura'])->assertOk()->json();
        $this->assertSame(['OPEN', null, 'APPROVED'], [$reopened['status'], $reopened['closing_entry'], $reopened['budget_status']]);
        $this->assertSame('REVERSED', DB::table('acc_journal_entries')->where('id', $entry->id)->value('status'));
        $this->assertSame('2026-12-31', (string) DB::table('acc_journal_entries')->where('reversal_of', $entry->id)->value('entry_date'));
        $this->assertSame('OPEN', DB::table('acc_periods')->where('tenant_id', $this->tenantId)->where('period', '2026-12')->value('status'));

        // Con más gasto el año da pérdida: va a 5921.
        $this->entry('2026-12-20', '6352', '1041', 9000, 'Provisión de alquiler');
        $again = $this->postJson('/api/v1/accounting-years/2026/close')->assertOk()->json();
        $this->assertEquals(-3000, $again['result']);
        $loss = DB::table('acc_journal_lines')->where('entry_id', $again['closing_entry']['id'])->where('account_code', '5921')->first();
        $this->assertEquals([3000, 0], [(float) $loss->debit, (float) $loss->credit]);
        $this->assertEquals(-3000, $this->getJson('/api/v1/financial-statements?from=2026-01&to=2026-12')->json('kpis.result'));

        // El año en curso no se cierra.
        $this->postJson('/api/v1/accounting-years/2027/close')->assertStatus(422);

        foreach (DB::table('audit_logs')->where('tenant_id', $this->tenantId)->whereIn('entity_type', ['FiscalYear', 'Budget'])->pluck('action')->unique() as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }
        $this->assertSame(2, DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'fiscal_year.close')->count());
    }

    public function test_an_approved_budget_of_an_ended_year_is_closed_by_whoever_approves_budgets(): void
    {
        $this->makeTenant(['FINANZAS']);
        $past = (string) Str::uuid();
        $current = (string) Str::uuid();
        DB::table('budgets')->insert([
            ['id' => $past, 'tenant_id' => $this->tenantId, 'fiscal_year' => now()->year - 1, 'name' => 'Anterior', 'status' => 'APPROVED', 'approved_at' => now()],
            ['id' => $current, 'tenant_id' => $this->tenantId, 'fiscal_year' => now()->year, 'name' => 'Actual', 'status' => 'APPROVED', 'approved_at' => now()],
        ]);

        $this->as($this->makeUser('Finanzas', ['budgets.view', 'budgets.manage']));
        $this->postJson("/api/v1/budgets/{$past}/close")->assertStatus(403);
        $this->as($this->makeUser('Gerencia', ['budgets.view', 'budgets.approve']));
        $this->postJson("/api/v1/budgets/{$current}/close")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/budgets/{$past}/close")->assertOk()->assertJsonPath('status', 'CLOSED');
        $this->postJson("/api/v1/budgets/{$past}/close")->assertStatus(422);
        // Cerrado, no se reabre para ajuste ni cambia sus líneas.
        $this->postJson("/api/v1/budgets/{$past}/reopen", ['reason' => 'x'])->assertStatus(403);
    }

    public function test_journal_entries_are_imported_from_the_excel_template_entry_by_entry(): void
    {
        $this->makeTenant(['FINANZAS']);
        $this->as($this->makeUser('Contador', self::ACCOUNTANT));
        Chart::ensure($this->tenantId);
        $center = (string) Str::uuid();
        DB::table('cost_centers')->insert(['id' => $center, 'tenant_id' => $this->tenantId, 'code' => 'CC-ADM', 'name' => 'Administración', 'active' => true]);
        $json = ['Accept' => 'application/json'];

        // La plantilla descargada, subida tal cual: sus dos asientos de ejemplo.
        $template = $this->get('/api/v1/journal-entries/template')->assertOk();
        $result = $this->post('/api/v1/journal-entries/import', ['file' => UploadedFile::fake()->createWithContent('plantilla_asientos.xlsx', $template->streamedContent())], $json)->assertOk()->json();
        $this->assertSame([2, []], [$result['imported'], $result['warnings']]);
        $imported = DB::table('acc_journal_entries')->where('tenant_id', $this->tenantId)->where('source_type', 'IMPORT')->orderBy('entry_number')->get();
        $this->assertCount(2, $imported);
        $this->assertSame('Aporte de capital de los socios', $imported[0]->description);
        $this->assertSame($center, DB::table('acc_journal_lines')->where('entry_id', $imported[1]->id)->where('account_code', '6352')->value('cost_center_id'));
        $detail = $this->getJson("/api/v1/journal-entries/{$imported[0]->id}")->assertOk()->json();
        $this->assertSame(['IMPORT', 'Importado', false], [$detail['source_type'], $detail['source_label'], $detail['automatic']]);

        // Un CSV con un asiento bueno y cuatro con errores: entra solo el bueno.
        $today = now()->format('d/m/Y');
        $csv = "asiento;fecha;glosa;cuenta;debe;haber;centro_costo;detalle\n"
            ."A;{$today};Bueno;6343;100;;CC-ADM;\nA;;;1041;;100;;\n"
            ."B;{$today};No cuadra;6343;100;;;\nB;;;1041;;90;;\n"
            ."C;{$today};Cuenta título;10;50;;;\nC;;;5011;;50;;\n"
            ."D;{$today};Centro inexistente;6343;20;;CC-NO;\nD;;;1041;;20;;\n"
            ."E;01/01/2000;Periodo cerrado;6343;5;;;\nE;;;1041;;5;;\n";
        DB::table('acc_periods')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'period' => '2000-01', 'status' => 'CLOSED', 'closed_at' => now()]);
        $result = $this->post('/api/v1/journal-entries/import', ['file' => UploadedFile::fake()->createWithContent('asientos.csv', $csv)], $json)->assertOk()->json();
        $this->assertSame(1, $result['imported']);
        $this->assertCount(4, $result['warnings']);
        $this->assertStringContainsString('no cuadra', $result['warnings'][0]);
        $this->assertSame(3, DB::table('acc_journal_entries')->where('tenant_id', $this->tenantId)->where('source_type', 'IMPORT')->count());
        $this->assertSame(3, DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'journal.import')->count());
        $this->assertTrue((bool) $this->getJson('/api/v1/ledger/trial-balance?from=2000-01')->json('balanced'));

        // Nada que importar: error con el detalle.
        $this->post('/api/v1/journal-entries/import', ['file' => UploadedFile::fake()->createWithContent('malo.csv', "asiento;cuenta;debe\nX;1041;10\n")], $json)
            ->assertStatus(422)->assertJsonFragment(['No se importó ningún asiento.']);

        // Importar es registrar asientos: quien solo ve, no.
        $this->as($this->makeUser('Gerencia', ['accounting.view']));
        $this->get('/api/v1/journal-entries/template', $json)->assertStatus(403);
        $this->post('/api/v1/journal-entries/import', ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv)], $json)->assertStatus(403);
    }
}
