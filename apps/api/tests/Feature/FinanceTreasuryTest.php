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
 * Finanzas, bancos y caja: el saldo de una cuenta es la suma de sus movimientos; cobros,
 * reembolsos, transferencias y gastos de caja dejan el suyo; el extracto del banco se concilia
 * solo donde coincide y no entra dos veces; caja chica con tope y arqueo; permisos y exportar.
 */
class FinanceTreasuryTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MODULES = ['CONTRATOS', 'FINANZAS', 'COMPRAS', 'CONTABILIDAD'];

    private const TREASURY = [
        'treasury.view', 'treasury.create', 'treasury.manage', 'treasury.export',
        'payments.view', 'payments.create', 'payments.manage', 'payments.approve',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // El archivo de cada extracto se guarda en Documentos: en las pruebas, en un disco de mentira.
        Storage::fake('documents');
        Storage::disk('documents')->buildTemporaryUrlsUsing(fn (string $path) => "https://archivos.test/{$path}");
    }

    /** Centro de costo activo de la empresa de la prueba. */
    private function center(string $code = 'CC-VTA', bool $active = true): string
    {
        $id = (string) Str::uuid();
        DB::table('cost_centers')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'code' => $code, 'name' => 'Ventas', 'active' => $active]);

        return $id;
    }

    private function account(array $data = []): array
    {
        return $this->postJson('/api/v1/bank-accounts', [
            'name' => 'BCP Soles', 'account_type' => 'BANK', 'currency' => 'PEN', 'bank_name' => 'BCP', 'account_number' => '193-1234567-0-01',
            'opening_balance' => 10000, ...$data,
        ])->assertCreated()->json();
    }

    private function cash(array $data = []): array
    {
        return $this->account(['name' => 'Caja chica', 'account_type' => 'CASH', 'bank_name' => null, 'account_number' => null, 'opening_balance' => 500, 'fund_amount' => 500, ...$data]);
    }

    private function pay(string $customerId, float $amount, array $data = []): array
    {
        return $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId, 'amount' => $amount, 'currency' => 'PEN', 'payment_method' => 'TRANSFERENCIA', 'reference' => 'OP-'.Str::random(6), ...$data,
        ])->assertCreated()->json();
    }

    /** Libro de Excel con esas filas, tal como lo guardaría una persona. */
    private function workbook(array $rows): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function balanceOf(string $accountId): float
    {
        return (float) collect($this->getJson('/api/v1/bank-accounts')->assertOk()->json('data'))->firstWhere('id', $accountId)['balance'];
    }

    private function import(string $accountId, array $rows, string $file = 'extracto.csv')
    {
        $csv = "fecha;descripcion;monto;operacion\n".implode("\n", array_map(fn (array $row) => implode(';', $row), $rows));

        return $this->upload($accountId, $csv, $file);
    }

    private function upload(string $accountId, string $content, string $file = 'extracto.csv')
    {
        return $this->post("/api/v1/bank-accounts/{$accountId}/statements/import", ['file' => UploadedFile::fake()->createWithContent($file, $content)], ['Accept' => 'application/json']);
    }

    public function test_an_account_is_born_with_its_opening_movement_and_its_kind_and_currency_do_not_change(): void
    {
        $this->makeTenant(self::MODULES);
        $custodian = $this->makeUser('Custodia', []);
        $this->as($this->makeUser('Tesorería', self::TREASURY));

        $this->postJson('/api/v1/bank-accounts', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'account_type', 'currency']);
        $this->postJson('/api/v1/bank-accounts', ['name' => 'BCP', 'account_type' => 'BANK', 'currency' => 'PEN'])->assertStatus(422)->assertJsonValidationErrors('account_number');
        $this->postJson('/api/v1/bank-accounts', ['name' => 'BN', 'account_type' => 'DETRACTION', 'currency' => 'USD', 'account_number' => '00-000-000001'])
            ->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->postJson('/api/v1/bank-accounts', ['name' => 'Caja', 'account_type' => 'CASH', 'currency' => 'PEN', 'custodian_user_id' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors('custodian_user_id');

        $bank = $this->account();
        $this->assertSame(['BANK', 'PEN', 'ACTIVE', 0], [$bank['account_type'], $bank['currency'], $bank['status'], $bank['pending_count']]);
        $this->assertEquals([10000, 10000], [$bank['balance'], $bank['balance_pen']]);
        $cash = $this->cash(['custodian_user_id' => $custodian->id, 'expense_limit' => 200]);
        $this->assertSame('Custodia', $cash['custodian']['name']);
        $this->assertEquals([500, 500, 200], [$cash['balance'], $cash['fund_amount'], $cash['expense_limit']]);
        $detraction = $this->account(['name' => 'Detracciones', 'account_type' => 'DETRACTION', 'bank_name' => 'Banco de la Nación', 'account_number' => '00-000-123456', 'opening_balance' => 0]);
        // Una sola cuenta de detracciones.
        $this->postJson('/api/v1/bank-accounts', ['name' => 'Otra', 'account_type' => 'DETRACTION', 'currency' => 'PEN', 'account_number' => '00-000-000002'])
            ->assertStatus(422)->assertJsonValidationErrors('account_type');

        // El saldo es su movimiento de apertura; la apertura no espera conciliación.
        $opening = DB::table('bank_movements')->where('account_id', $bank['id'])->get();
        $this->assertCount(1, $opening);
        $this->assertSame(['OPENING', true], [$opening[0]->ref_type, $opening[0]->reconciled]);
        $this->assertSame(0, DB::table('bank_movements')->where('account_id', $detraction['id'])->count());

        // Editar no cambia tipo ni moneda, ni la API ni la base.
        $this->putJson("/api/v1/bank-accounts/{$bank['id']}", ['name' => 'BCP', 'account_number' => '1', 'currency' => 'USD'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->putJson("/api/v1/bank-accounts/{$bank['id']}", ['name' => 'BCP Principal', 'account_number' => '193-1', 'cci' => '002-193'])
            ->assertOk()->assertJsonPath('name', 'BCP Principal')->assertJsonPath('cci', '002-193');
        $this->assertDatabaseRejects(fn () => DB::table('financial_accounts')->where('id', $bank['id'])->update(['currency' => 'USD']));
        $this->assertDatabaseRejects(fn () => DB::table('financial_accounts')->where('id', $bank['id'])->update(['account_type' => 'CASH']));
        $this->assertDatabaseRejects(fn () => DB::table('financial_accounts')->where('id', $bank['id'])->delete());
        // Con saldo no se desactiva.
        $this->putJson("/api/v1/bank-accounts/{$bank['id']}", ['name' => 'BCP Principal', 'account_number' => '193-1', 'status' => 'INACTIVE'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->putJson("/api/v1/bank-accounts/{$detraction['id']}", ['name' => 'Detracciones', 'account_number' => '00-000-123456', 'status' => 'INACTIVE'])
            ->assertOk()->assertJsonPath('status', 'INACTIVE');

        $summary = $this->getJson('/api/v1/bank-accounts/summary')->assertOk()->json();
        $this->assertEquals([10500, 0, 0, 1, 500, 500], [
            $summary['total_pen'], $summary['unreconciled'], $summary['statements'], $summary['cash_count'], $summary['cash_balance'], $summary['cash_fund'],
        ]);
    }

    public function test_a_payment_leaves_its_movement_in_the_account_and_a_refund_takes_it_out(): void
    {
        $this->makeTenant(self::MODULES);
        $customer = $this->makeCustomer('Minera Los Andes SAC');
        $this->as($this->makeUser('Tesorería', self::TREASURY));

        // Sin cuentas, el cobro queda sin cuenta y sin movimiento.
        $loose = $this->pay($customer, 50);
        $this->assertNull($loose['account_name']);

        $bank = $this->account();
        // Con una sola cuenta posible, entra ahí sin elegirla.
        $payment = $this->pay($customer, 1180, ['reference' => 'OP-777']);
        $this->assertSame('BCP Soles', $payment['account_name']);
        $movement = DB::table('bank_movements')->where('ref_type', 'PAYMENT')->where('ref_id', $payment['id'])->first();
        $this->assertSame([$bank['id'], 'OP-777', false, "Cobro {$payment['payment_number']} · Minera Los Andes SAC"], [$movement->account_id, $movement->op_number, $movement->reconciled, $movement->description]);
        $this->assertEquals(11180, $this->balanceOf($bank['id']));
        // El efectivo no tiene caja todavía: queda sin cuenta.
        $this->assertNull($this->pay($customer, 20, ['payment_method' => 'EFECTIVO', 'reference' => null])['account_name']);
        $cash = $this->cash();
        $inCash = $this->pay($customer, 100, ['payment_method' => 'EFECTIVO', 'reference' => null]);
        $this->assertSame('Caja chica', $inCash['account_name']);
        // Lo que entra a una caja nace conciliado.
        $this->assertTrue(DB::table('bank_movements')->where('ref_id', $inCash['id'])->value('reconciled'));

        // Con dos bancos en soles hay que elegir; la de detracciones y otra moneda no valen.
        $second = $this->account(['name' => 'BBVA Soles']);
        $dollars = $this->account(['name' => 'BCP Dólares', 'currency' => 'USD']);
        $detraction = $this->account(['name' => 'Detracciones', 'account_type' => 'DETRACTION', 'account_number' => '00-000-123456', 'opening_balance' => 0]);
        $try = fn (array $data) => $this->postJson('/api/v1/payments', ['customer_id' => $customer, 'amount' => 10, 'currency' => 'PEN', 'payment_method' => 'TRANSFERENCIA', 'reference' => 'X', ...$data]);
        $try([])->assertStatus(422)->assertJsonValidationErrors('financial_account_id');
        $try(['financial_account_id' => $dollars['id']])->assertStatus(422)->assertJsonValidationErrors('financial_account_id');
        $try(['financial_account_id' => $detraction['id']])->assertStatus(422)->assertJsonValidationErrors('financial_account_id');
        $try(['financial_account_id' => $second['id']])->assertCreated()->assertJsonPath('account_name', 'BBVA Soles');
        $options = $this->getJson('/api/v1/payments/options')->assertOk()->json('accounts');
        $this->assertNotContains($detraction['id'], array_column($options, 'id'));

        // Conciliar el cobro a mano concilia su movimiento; reembolsarlo saca el dinero de su cuenta.
        $this->postJson("/api/v1/payments/{$payment['id']}/reconcile")->assertOk();
        $this->assertTrue(DB::table('bank_movements')->where('id', $movement->id)->value('reconciled'));
        $this->postJson("/api/v1/payments/{$payment['id']}/refund", ['amount' => 180, 'reason' => 'Cobro en exceso'])->assertOk();
        $refund = DB::table('bank_movements')->where('account_id', $bank['id'])->where('ref_type', 'REFUND')->first();
        $this->assertEquals([-180, 11000], [$refund->amount, $this->balanceOf($bank['id'])]);
        // Una caja no reembolsa más de lo que tiene.
        $big = $this->pay($customer, 100, ['payment_method' => 'EFECTIVO', 'reference' => null]);
        DB::table('bank_movements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'account_id' => $cash['id'], 'movement_date' => now()->toDateString(), 'description' => 'Salida', 'amount' => -650, 'ref_type' => 'MANUAL', 'reconciled' => true]);
        $this->postJson("/api/v1/payments/{$big['id']}/refund", ['amount' => 100, 'reason' => 'Devolución'])->assertStatus(422)->assertJsonValidationErrors('amount');

        // La base: un cobro deja un solo movimiento, y un movimiento no se edita, no se desconcilia ni se borra.
        $this->assertDatabaseRejects(fn () => DB::table('bank_movements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'account_id' => $bank['id'], 'movement_date' => now()->toDateString(), 'description' => 'Doble', 'amount' => 1, 'ref_type' => 'PAYMENT', 'ref_id' => $payment['id']]));
        $this->assertDatabaseRejects(fn () => DB::table('bank_movements')->where('id', $movement->id)->update(['amount' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('bank_movements')->where('id', $movement->id)->update(['account_id' => $second['id']]));
        $this->assertDatabaseRejects(fn () => DB::table('bank_movements')->where('id', $movement->id)->update(['reconciled' => false]));
        $this->assertDatabaseRejects(fn () => DB::table('bank_movements')->where('id', $movement->id)->delete());
    }

    public function test_transfers_need_the_same_currency_and_enough_balance(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Tesorería', self::TREASURY));
        $bank = $this->account();
        $other = $this->account(['name' => 'BBVA Soles', 'opening_balance' => 0]);
        $dollars = $this->account(['name' => 'BCP Dólares', 'currency' => 'USD']);
        $detraction = $this->account(['name' => 'Detracciones', 'account_type' => 'DETRACTION', 'account_number' => '00-000-123456', 'opening_balance' => 100]);
        $cash = $this->cash(['opening_balance' => 120]);
        $send = fn (array $data) => $this->postJson('/api/v1/bank-transfers', ['from_account_id' => $bank['id'], 'to_account_id' => $other['id'], 'amount' => 1000, ...$data]);

        $this->postJson('/api/v1/bank-transfers', [])->assertStatus(422)->assertJsonValidationErrors(['from_account_id', 'to_account_id', 'amount']);
        $send(['to_account_id' => $bank['id']])->assertStatus(422)->assertJsonValidationErrors('to_account_id');
        $send(['to_account_id' => $dollars['id']])->assertStatus(422)->assertJsonValidationErrors('to_account_id');
        $send(['to_account_id' => $detraction['id']])->assertStatus(422)->assertJsonValidationErrors('to_account_id');
        $send(['from_account_id' => $detraction['id']])->assertStatus(422)->assertJsonValidationErrors('from_account_id');
        $send(['amount' => 10000.01])->assertStatus(422)->assertJsonValidationErrors('amount');
        $send(['date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('date');

        $send(['reference' => 'TRF-1'])->assertCreated()->assertJsonPath('reference', 'TRF-1');
        $this->assertEquals([9000, 1000], [$this->balanceOf($bank['id']), $this->balanceOf($other['id'])]);
        $pair = DB::table('bank_movements')->where('ref_type', 'TRANSFER')->orderBy('amount')->get();
        $this->assertSame([$pair[0]->ref_id, 'TRF-1', 'Transferencia a BBVA Soles', 'Transferencia desde BCP Soles'], [$pair[1]->ref_id, $pair[0]->op_number, $pair[0]->description, $pair[1]->description]);

        // Reponer la caja: lo que le falta a su fondo, desde un banco.
        $this->postJson("/api/v1/bank-accounts/{$bank['id']}/refill", ['from_account_id' => $other['id']])->assertStatus(422);
        $this->postJson("/api/v1/bank-accounts/{$cash['id']}/refill", ['from_account_id' => $dollars['id']])->assertStatus(422)->assertJsonValidationErrors('to_account_id');
        $this->postJson("/api/v1/bank-accounts/{$cash['id']}/refill", ['from_account_id' => $bank['id']])->assertCreated()->assertJsonPath('amount', 380);
        $this->assertEquals([500, 8620], [$this->balanceOf($cash['id']), $this->balanceOf($bank['id'])]);
        $this->postJson("/api/v1/bank-accounts/{$cash['id']}/refill", ['from_account_id' => $bank['id']])->assertStatus(422)->assertJsonValidationErrors('from_account_id');
    }

    public function test_a_statement_reconciles_what_matches_and_does_not_enter_twice(): void
    {
        $this->makeTenant(self::MODULES);
        $customer = $this->makeCustomer();
        $this->as($this->makeUser('Tesorería', self::TREASURY));
        $bank = $this->account();
        $cash = $this->cash();
        $today = now()->format('d/m/Y');
        $byNumber = $this->pay($customer, 1180, ['reference' => 'OP-100']);
        $byDate = $this->pay($customer, 590, ['reference' => 'OP-200', 'paid_at' => now()->subDays(2)->toDateString()]);
        $tooOld = $this->pay($customer, 300, ['reference' => 'OP-300', 'paid_at' => now()->subDays(10)->toDateString()]);

        $this->postJson("/api/v1/bank-accounts/{$bank['id']}/statements/import", [])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($cash['id'], "fecha;monto\n{$today};1")->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($bank['id'], "dia;valor\n{$today};1")->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($bank['id'], "fecha;monto\nayer;abc")->assertStatus(422)->assertJsonValidationErrors('file');

        $rows = [
            [$today, 'ABONO TRANSF RECIBIDA', '1,180.00', 'OP-100'],
            [$today, 'ABONO SIN NUMERO', '590.00', ''],
            [$today, 'ABONO ANTIGUO', '300.00', 'OTRA'],
            [$today, 'COMISION MANTENIMIENTO', '-25.00', '000771'],
            [$today, 'ITF', '-0.05', ''],
            [$today, 'ITF', '-0.05', ''],
            [$today, 'DEPOSITO SIN REFERENCIA', '850.00', '000773'],
            ['fecha rota', 'NO SE LEE', '10', ''],
        ];
        $result = $this->import($bank['id'], $rows, 'bcp-octubre.csv')->assertCreated()->json();
        // Dos ITF iguales son dos líneas; la fila ilegible queda fuera.
        $this->assertSame([7, 2, 5, 0], [$result['lines'], $result['matched'], $result['unmatched'], $result['duplicates']]);
        // La ventana de importación recibe el resumen y un aviso por cada fila que no se pudo leer.
        $this->assertSame([7, '7 líneas importadas: 2 conciliadas automáticamente y 5 por revisar.'], [$result['imported'], $result['message']]);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('la fecha no es válida', $result['warnings'][0]);

        $view = $this->getJson("/api/v1/bank-statements?account_id={$bank['id']}")->assertOk()->json();
        $lines = collect($view['statement']['lines']);
        $this->assertSame(['bcp-octubre.csv', 29, 5, 1], [$view['statement']['file_name'], $view['statement']['resolved_pct'], $view['statement']['unmatched'], $view['statements_count']]);
        $this->assertSame(['MATCHED', 'Monto + n.º de operación'], [$lines[0]['status'], $lines[0]['rule']]);
        $this->assertSame(['MATCHED', 'Monto + fecha (±3 días)'], [$lines[1]['status'], $lines[1]['rule']]);
        // Fuera del margen de días no se empareja solo, pero se ofrece para hacerlo a mano.
        $this->assertSame(['UNMATCHED', 1], [$lines[2]['status'], count($lines[2]['candidates'])]);
        $this->assertEquals([1, 300], [$view['pending_count'], $view['pending_amount']]);
        // Lo conciliado por el extracto deja conciliados sus cobros.
        $this->assertSame([true, true, false], DB::table('payments')->whereIn('id', [$byNumber['id'], $byDate['id'], $tooOld['id']])->orderByDesc('amount')->pluck('reconciled')->all());
        $this->postJson("/api/v1/payments/{$byNumber['id']}/reconcile")->assertStatus(422);

        // El mismo archivo no entra otra vez; uno con líneas nuevas solo aporta las nuevas.
        $this->import($bank['id'], $rows)->assertStatus(422)->assertJsonValidationErrors('file');
        $more = $this->import($bank['id'], [...$rows, [$today, 'PORTES', '-3.50', '000780']])->assertCreated()->json();
        $this->assertSame([1, 7], [$more['lines'], $more['duplicates']]);
        $this->assertStringContainsString('7 líneas ya importadas', $more['warnings'][0]);

        // La plantilla de Excel se descarga y se puede subir tal cual: sus tres filas de ejemplo entran como líneas.
        $template = $this->get("/api/v1/bank-accounts/{$bank['id']}/statements/template")->assertOk();
        $this->assertStringContainsString('plantilla_extracto_bancario.xlsx', $template->headers->get('content-disposition'));
        $fromTemplate = $this->upload($bank['id'], $template->streamedContent(), 'plantilla_extracto_bancario.xlsx')->assertCreated()->json();
        $this->assertSame([3, 0], [$fromTemplate['lines'], count($fromTemplate['warnings'])]);
        $templateLines = DB::table('bank_statement_lines')->where('statement_id', $fromTemplate['statement_id'])->orderBy('position')->get();
        $this->assertEquals([1180, -25, -0.05], $templateLines->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
        $this->assertSame([now()->subDays(3)->toDateString(), '000123', 'ITF'], [$templateLines[0]->line_date, $templateLines[0]->op_number, $templateLines[2]->description]);
        // Un Excel con la fecha como celda de fecha y el monto como número también se lee.
        $this->upload($bank['id'], $this->workbook([['fecha', 'descripcion', 'monto', 'operacion'], [\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(now()->subDays(4)->startOfDay()), 'ABONO EXCEL', 999.5, 7788]]), 'banco.xlsx')
            ->assertCreated()->assertJsonPath('lines', 1);
        $excel = DB::table('bank_statement_lines')->where('description', 'ABONO EXCEL')->first();
        $this->assertEquals([now()->subDays(4)->toDateString(), 999.5, '7788'], [$excel->line_date, (float) $excel->amount, $excel->op_number]);

        // A mano: emparejar con un movimiento del mismo importe; registrar comisión o abono; ignorar.
        $view = $this->getJson("/api/v1/bank-statements?account_id={$bank['id']}")->assertOk()->json();
        $pending = $view['pending'][0]['id'];
        $this->postJson("/api/v1/bank-statement-lines/{$lines[3]['id']}/match", ['movement_id' => $pending])->assertStatus(422)->assertJsonValidationErrors('movement_id');
        $this->postJson("/api/v1/bank-statement-lines/{$lines[2]['id']}/match", ['movement_id' => $pending])->assertOk();
        $this->postJson("/api/v1/bank-statement-lines/{$lines[2]['id']}/match", ['movement_id' => $pending])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertTrue(DB::table('payments')->where('id', $tooOld['id'])->value('reconciled'));

        $before = $this->balanceOf($bank['id']);
        $this->postJson("/api/v1/bank-statement-lines/{$lines[3]['id']}/resolve", ['kind' => 'INCOME'])->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->postJson("/api/v1/bank-statement-lines/{$lines[6]['id']}/resolve", ['kind' => 'FEE'])->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->postJson("/api/v1/bank-statement-lines/{$lines[3]['id']}/resolve", ['kind' => 'FEE'])->assertOk();
        $this->postJson("/api/v1/bank-statement-lines/{$lines[6]['id']}/resolve", ['kind' => 'INCOME'])->assertOk();
        $this->postJson("/api/v1/bank-statement-lines/{$lines[4]['id']}/resolve", ['kind' => 'IGNORE'])->assertOk();
        $this->assertEquals($before - 25 + 850, $this->balanceOf($bank['id']));
        $fee = DB::table('bank_movements')->where('account_id', $bank['id'])->where('ref_type', 'BANK_FEE')->first();
        $this->assertSame([true, $lines[3]['id'], 'Comisión / gasto bancario · COMISION MANTENIMIENTO'], [$fee->reconciled, $fee->statement_line_id, $fee->description]);
        $states = DB::table('bank_statement_lines')->where('statement_id', $result['statement_id'])->orderBy('position')->pluck('status')->all();
        $this->assertSame(['MATCHED', 'MATCHED', 'MATCHED', 'CREATED', 'IGNORED', 'UNMATCHED', 'CREATED'], $states);

        // La base: lo que dijo el banco no se toca, una línea resuelta no se vuelve a resolver y nada se borra.
        $this->assertDatabaseRejects(fn () => DB::table('bank_statement_lines')->where('id', $lines[5]['id'])->update(['amount' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('bank_statement_lines')->where('id', $lines[4]['id'])->update(['status' => 'UNMATCHED']));
        $this->assertDatabaseRejects(fn () => DB::table('bank_statement_lines')->where('id', $lines[5]['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('bank_statements')->where('id', $result['statement_id'])->update(['file_name' => 'otro.csv']));

        foreach (DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'like', 'treasury.%')->distinct()->pluck('action') as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS, "Falta el texto de {$action} en AuditCatalog");
        }
    }

    public function test_petty_cash_has_a_limit_per_receipt_never_goes_negative_and_is_counted(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Tesorería', self::TREASURY));
        $bank = $this->account();
        $cash = $this->cash();
        $center = $this->center();
        $spend = fn (array $data) => $this->postJson('/api/v1/cash-expenses', [
            'account_id' => $cash['id'], 'description' => 'Taxi a cliente', 'amount' => 40, 'cost_center_id' => $center, 'expense_account' => '6311', ...$data,
        ]);

        $this->postJson('/api/v1/cash-expenses', [])->assertStatus(422)->assertJsonValidationErrors(['account_id', 'description', 'amount', 'cost_center_id', 'expense_account']);
        // Centro de costo y cuenta de gasto del plan, obligatorios.
        $spend(['expense_account' => '1041'])->assertStatus(422)->assertJsonValidationErrors('expense_account');
        $spend(['expense_account' => 'Movilidad'])->assertStatus(422)->assertJsonValidationErrors('expense_account');
        $spend(['cost_center_id' => $this->center('CC-OFF', false)])->assertStatus(422)->assertJsonValidationErrors('cost_center_id');
        $spend(['cost_center_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('cost_center_id');
        $listOptions = $this->getJson('/api/v1/cash-expenses/options')->assertOk()->json();
        $this->assertSame(['CC-VTA'], array_column($listOptions['cost_centers'], 'code'));
        $this->assertContains('6311', array_column($listOptions['expense_accounts'], 'code'));
        $spend(['account_id' => $bank['id']])->assertStatus(422)->assertJsonValidationErrors('account_id');
        // Tope por comprobante: 350 si la caja no define el suyo.
        $spend(['amount' => 350.01])->assertStatus(422)->assertJsonValidationErrors('amount');
        $spend(['expense_date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('expense_date');

        $first = $spend(['receipt' => 'Boleta B001-123'])->assertCreated()->json();
        $this->assertMatchesRegularExpression('/^CCH-\d{4}-0001$/', $first['expense_number']);
        $spend(['amount' => 350, 'description' => 'Útiles'])->assertCreated();
        $this->assertEquals(110, $this->balanceOf($cash['id']));
        // No queda en negativo.
        $spend(['amount' => 110.01])->assertStatus(422)->assertJsonValidationErrors('amount');

        $list = $this->getJson("/api/v1/cash-expenses?account_id={$cash['id']}&sort=amount&dir=asc")->assertOk()->assertJsonPath('total', 2)->json('data');
        $this->assertSame(['Taxi a cliente', 'Boleta B001-123', 'CC-VTA · Ventas', '6311 · Transporte de carga y movilidad'], [$list[0]['description'], $list[0]['receipt'], $list[0]['cost_center'], $list[0]['expense_account']]);
        // Su asiento va a esa cuenta y a ese centro de costo.
        $line = DB::table('acc_journal_lines as l')->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')->where('e.source_id', $first['id'])->where('l.debit', '>', 0)->first(['l.account_code', 'l.cost_center_id']);
        $this->assertSame(['6311', $center], [$line->account_code, $line->cost_center_id]);
        // La base exige centro y cuenta del plan en todo gasto nuevo.
        $this->assertDatabaseRejects(fn () => DB::table('cash_expenses')->insert([
            'tenant_id' => $this->tenantId, 'account_id' => $cash['id'], 'expense_number' => 'X-1', 'expense_date' => now()->toDateString(), 'description' => 'Sin centro', 'amount' => 1, 'expense_account' => '6311',
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('cash_expenses')->insert([
            'tenant_id' => $this->tenantId, 'account_id' => $cash['id'], 'expense_number' => 'X-2', 'expense_date' => now()->toDateString(), 'description' => 'Cuenta inventada', 'amount' => 1, 'cost_center_id' => $center, 'expense_account' => 'Movilidad',
        ]));
        $this->getJson("/api/v1/cash-expenses?account_id={$cash['id']}&search=utiles")->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/cash-expenses?account_id={$cash['id']}&search=Útiles")->assertOk()->assertJsonPath('total', 1);
        $movement = DB::table('bank_movements')->where('ref_type', 'CASH_EXPENSE')->where('ref_id', $first['id'])->first();
        $this->assertEquals([-40, true], [$movement->amount, $movement->reconciled]);

        // Arqueo: deja constancia de la diferencia y no mueve el saldo.
        $this->postJson('/api/v1/cash-counts', ['account_id' => $cash['id'], 'counted' => -1])->assertStatus(422)->assertJsonValidationErrors('counted');
        $this->postJson('/api/v1/cash-counts', ['account_id' => $bank['id'], 'counted' => 1])->assertStatus(422)->assertJsonValidationErrors('account_id');
        $count = $this->postJson('/api/v1/cash-counts', ['account_id' => $cash['id'], 'counted' => 100, 'note' => 'Falta un vale'])->assertCreated()->json();
        $this->assertEquals([110, 100, -10], [$count['book'], $count['counted'], $count['difference']]);
        $this->assertEquals(110, $this->balanceOf($cash['id']));
        $this->getJson("/api/v1/cash-counts?account_id={$cash['id']}")->assertOk()->assertJsonPath('0.difference', -10)->assertJsonPath('0.note', 'Falta un vale');

        // Gastos y arqueos quedan como se registraron.
        $this->assertDatabaseRejects(fn () => DB::table('cash_expenses')->where('id', $first['id'])->update(['amount' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('cash_expenses')->where('id', $first['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('cash_counts')->where('id', $count['id'])->update(['counted' => 110]));
        $this->assertDatabaseRejects(fn () => DB::table('cash_counts')->where('id', $count['id'])->delete());
    }

    public function test_statements_keep_their_file_and_history_and_transfers_are_guarded_by_the_database(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Tesorería', self::TREASURY));
        $bank = $this->account();
        $dollars = $this->account(['name' => 'BCP Dólares', 'currency' => 'USD', 'opening_balance' => 100]);
        $other = $this->account(['name' => 'BBVA Soles', 'opening_balance' => 0]);
        $day = now()->format('d/m/Y');

        $first = $this->import($bank['id'], [[$day, 'ITF', '-1.00', '']], 'octubre.csv')->assertCreated()->json('statement_id');
        $this->travel(1)->minutes();
        $second = $this->import($bank['id'], [[$day, 'COMISION', '-25.00', '77']], 'octubre-2.csv')->assertCreated()->json('statement_id');
        // Repetido entero: ni se importa ni se guarda su archivo.
        $files = DB::table('documents')->where('tenant_id', $this->tenantId)->count();
        $this->import($bank['id'], [[$day, 'ITF', '-1.00', '']], 'otra-vez.csv')->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame($files, DB::table('documents')->where('tenant_id', $this->tenantId)->count());

        // El archivo queda en Documentos, enlazado al extracto, y se descarga con un enlace temporal.
        $document = DB::table('bank_statements')->where('id', $first)->value('document_id');
        $this->assertNotNull($document);
        $this->assertTrue(DB::table('document_links')->where('document_id', $document)->where('entity_type', 'BankStatement')->where('entity_id', $first)->exists());
        $this->assertStringStartsWith('https://archivos.test/tenants/', $this->getJson("/api/v1/bank-statements/{$first}/file-url")->assertOk()->json('url'));
        $this->getJson('/api/v1/bank-statements/'.Str::uuid().'/file-url')->assertNotFound();

        // Historial: el último por defecto, y cualquiera a pedido.
        $latest = $this->getJson("/api/v1/bank-statements?account_id={$bank['id']}")->assertOk()->json();
        $this->assertSame([$second, 2, ['octubre-2.csv', 'octubre.csv']], [$latest['statement']['id'], $latest['statements_count'], array_column($latest['statements'], 'file_name')]);
        $this->assertTrue($latest['statement']['has_file']);
        $older = $this->getJson("/api/v1/bank-statements?account_id={$bank['id']}&statement_id={$first}")->assertOk()->json('statement');
        $this->assertSame(['octubre.csv', 'ITF'], [$older['file_name'], $older['lines'][0]['description']]);

        // Transferencias: un registro propio; la base exige misma moneda, saldo y que los movimientos le correspondan.
        $this->postJson('/api/v1/bank-transfers', ['from_account_id' => $bank['id'], 'to_account_id' => $other['id'], 'amount' => 300, 'reference' => 'TRF-1'])->assertCreated();
        $transfer = DB::table('bank_transfers')->where('reference', 'TRF-1')->first();
        $this->assertEquals([$bank['id'], $other['id'], 300, 'PEN', 'TRANSFER'], [$transfer->from_account_id, $transfer->to_account_id, $transfer->amount, $transfer->currency, $transfer->kind]);
        $this->assertSame(2, DB::table('bank_movements')->where('ref_type', 'TRANSFER')->where('ref_id', $transfer->id)->count());
        $insert = fn (array $data) => DB::table('bank_transfers')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'from_account_id' => $bank['id'], 'to_account_id' => $other['id'],
            'amount' => 10, 'currency' => 'PEN', 'transfer_date' => now()->toDateString(), ...$data,
        ]);
        $this->assertDatabaseRejects(fn () => $insert(['to_account_id' => $dollars['id']]));
        $this->assertDatabaseRejects(fn () => $insert(['amount' => 999999]));
        $this->assertDatabaseRejects(fn () => $insert(['to_account_id' => $bank['id']]));
        $this->assertDatabaseRejects(fn () => DB::table('bank_movements')->insert([
            'tenant_id' => $this->tenantId, 'account_id' => $bank['id'], 'movement_date' => now()->toDateString(), 'description' => 'Sin transferencia', 'amount' => -5, 'ref_type' => 'TRANSFER', 'ref_id' => (string) Str::uuid(),
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('bank_movements')->insert([
            'tenant_id' => $this->tenantId, 'account_id' => $bank['id'], 'movement_date' => now()->toDateString(), 'description' => 'Otra vez', 'amount' => -300, 'ref_type' => 'TRANSFER', 'ref_id' => $transfer->id,
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('bank_transfers')->where('id', $transfer->id)->update(['amount' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('bank_transfers')->where('id', $transfer->id)->delete());
    }

    public function test_movements_carry_a_running_balance_over_the_whole_account(): void
    {
        $this->makeTenant(self::MODULES);
        $customer = $this->makeCustomer();
        $this->as($this->makeUser('Tesorería', self::TREASURY));
        $bank = $this->account(['opening_date' => now()->subDays(5)->toDateString()]);
        $other = $this->account(['name' => 'BBVA Soles', 'opening_balance' => 0]);
        $this->pay($customer, 500, ['reference' => 'OP-1', 'financial_account_id' => $bank['id'], 'paid_at' => now()->subDays(2)->toDateString()]);
        $this->postJson('/api/v1/bank-transfers', ['from_account_id' => $bank['id'], 'to_account_id' => $other['id'], 'amount' => 2000, 'reference' => 'TRF-9'])->assertCreated();

        $this->getJson('/api/v1/bank-movements')->assertStatus(404);
        $rows = $this->getJson("/api/v1/bank-movements?account_id={$bank['id']}")->assertOk()->assertJsonPath('total', 3)->json('data');
        // Lo más reciente primero, con el saldo que dejó cada movimiento.
        $this->assertSame(['TRANSFER', 'PAYMENT', 'OPENING'], array_column($rows, 'ref_type'));
        $this->assertEquals([8500, 10500, 10000], array_column($rows, 'running'));
        // Buscar o filtrar no cambia el saldo corrido.
        $this->getJson("/api/v1/bank-movements?account_id={$bank['id']}&search=TRF-9")->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.running', 8500);
        $this->getJson("/api/v1/bank-movements?account_id={$bank['id']}&ref_type=PAYMENT")->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.running', 10500);
        $this->getJson("/api/v1/bank-movements?account_id={$bank['id']}&reconciled=false")->assertOk()->assertJsonPath('total', 2);
        $sorted = $this->getJson("/api/v1/bank-movements?account_id={$bank['id']}&sort=expense&dir=desc")->assertOk()->json('data');
        $this->assertSame('TRANSFER', $sorted[0]['ref_type']);
        $filter = urlencode(json_encode([['id' => 'income', 'type' => 'number', 'operator' => 'gt', 'value' => '600']]));
        $this->getJson("/api/v1/bank-movements?account_id={$bank['id']}&filters={$filter}")->assertOk();
        // El cobro y las dos puntas de la transferencia.
        $this->assertSame(3, $this->getJson('/api/v1/bank-accounts/summary')->json('unreconciled'));
    }

    public function test_each_action_asks_for_its_own_permission_and_exporting_is_a_separate_one(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Tesorería', self::TREASURY));
        $bank = $this->account();
        $cash = $this->cash();
        $lineId = DB::table('bank_statement_lines')->where('statement_id', $this->import($bank['id'], [[now()->format('d/m/Y'), 'ITF', '-1.00', '']])->json('statement_id'))->value('id');
        $viewer = $this->makeUser('Gerencia', ['treasury.view', 'treasury.export']);
        $operator = $this->makeUser('Cajera', ['treasury.view', 'treasury.create']);

        $this->as($this->makeUser('Ventas', ['receivables.view']));
        $this->getJson('/api/v1/bank-accounts')->assertStatus(403);
        $this->getJson("/api/v1/bank-movements?account_id={$bank['id']}")->assertStatus(403);

        $this->as($viewer);
        $this->getJson('/api/v1/bank-accounts')->assertOk();
        $this->getJson("/api/v1/bank-statements?account_id={$bank['id']}")->assertOk();
        $this->postJson('/api/v1/bank-accounts', ['name' => 'X', 'account_type' => 'CASH', 'currency' => 'PEN'])->assertStatus(403);
        $this->postJson('/api/v1/bank-transfers', ['from_account_id' => $bank['id'], 'to_account_id' => $cash['id'], 'amount' => 1])->assertStatus(403);
        $this->postJson('/api/v1/cash-expenses', ['account_id' => $cash['id'], 'description' => 'X', 'amount' => 1])->assertStatus(403);
        $this->postJson('/api/v1/cash-counts', ['account_id' => $cash['id'], 'counted' => 1])->assertStatus(403);
        $this->postJson("/api/v1/bank-statement-lines/{$lineId}/resolve", ['kind' => 'IGNORE'])->assertStatus(403);
        $this->getJson("/api/v1/bank-movements?account_id={$bank['id']}&export=1")->assertOk();
        $this->postJson('/api/v1/exports', ['resource' => 'treasury', 'format' => 'xlsx', 'rows' => 1])->assertOk();

        $this->as($operator);
        $this->postJson('/api/v1/cash-expenses', ['account_id' => $cash['id'], 'description' => 'Taxi', 'amount' => 10, 'cost_center_id' => $this->center(), 'expense_account' => '6311'])->assertCreated();
        $this->postJson('/api/v1/bank-transfers', ['from_account_id' => $bank['id'], 'to_account_id' => $cash['id'], 'amount' => 10])->assertCreated();
        // Operar no es crear cuentas ni conciliar a mano, ni exportar.
        $this->postJson('/api/v1/bank-accounts', ['name' => 'X', 'account_type' => 'CASH', 'currency' => 'PEN'])->assertStatus(403);
        $this->putJson("/api/v1/bank-accounts/{$bank['id']}", ['name' => 'X', 'account_number' => '1'])->assertStatus(403);
        $this->postJson("/api/v1/bank-statement-lines/{$lineId}/resolve", ['kind' => 'IGNORE'])->assertStatus(403);
        $this->getJson("/api/v1/cash-expenses?account_id={$cash['id']}&export=1")->assertStatus(403);
        $this->postJson('/api/v1/exports', ['resource' => 'treasury', 'format' => 'csv', 'rows' => 1])->assertStatus(403);

        // Sin el módulo Finanzas, el permiso no vale.
        DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'FINANZAS')->delete();
        $this->getJson('/api/v1/bank-accounts')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_accounts_of_another_company_do_not_exist(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Tesorería', self::TREASURY));
        $bank = $this->account();
        $cash = $this->cash();
        $lineId = DB::table('bank_statement_lines')->where('statement_id', $this->import($bank['id'], [[now()->format('d/m/Y'), 'ITF', '-1.00', '']])->json('statement_id'))->value('id');

        $this->makeTenant(self::MODULES);
        $customer = $this->makeCustomer();
        $this->as($this->makeUser('Tesorería de otra empresa', self::TREASURY));
        $own = $this->account(['name' => 'Interbank']);

        $this->getJson('/api/v1/bank-accounts')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/bank-movements?account_id={$bank['id']}")->assertStatus(404);
        $this->getJson("/api/v1/bank-statements?account_id={$bank['id']}")->assertStatus(404);
        $this->getJson("/api/v1/cash-expenses?account_id={$cash['id']}")->assertStatus(404);
        $this->putJson("/api/v1/bank-accounts/{$bank['id']}", ['name' => 'Mía', 'account_number' => '1'])->assertStatus(404);
        $this->postJson('/api/v1/bank-transfers', ['from_account_id' => $own['id'], 'to_account_id' => $bank['id'], 'amount' => 1])->assertStatus(422)->assertJsonValidationErrors('to_account_id');
        $this->postJson('/api/v1/cash-expenses', ['account_id' => $cash['id'], 'description' => 'X', 'amount' => 1, 'cost_center_id' => $this->center(), 'expense_account' => '6311'])->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->upload($bank['id'], "fecha;monto\n01/10/2026;1")->assertStatus(404);
        $this->postJson("/api/v1/bank-statement-lines/{$lineId}/resolve", ['kind' => 'IGNORE'])->assertStatus(404);
        $this->postJson('/api/v1/payments', ['customer_id' => $customer, 'amount' => 10, 'currency' => 'PEN', 'payment_method' => 'TRANSFERENCIA', 'reference' => 'X', 'financial_account_id' => $bank['id']])
            ->assertStatus(422)->assertJsonValidationErrors('financial_account_id');
    }
}
