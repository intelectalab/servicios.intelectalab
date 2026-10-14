<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Support\Chart;
use App\Modules\Accounting\Support\Posting;
use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Contabilidad: cada documento deja su asiento (uno solo, cuadrado, en un periodo abierto), los
 * asientos no se editan sino que se extornan, los periodos se cierran ya terminados y conciliados,
 * el presupuesto controla las órdenes de compra; permisos, alcance y exportar.
 */
class AccountingTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MODULES = ['FINANZAS'];

    private const ACCOUNTANT = [
        'accounting.view', 'accounting.create', 'accounting.manage', 'accounting.approve', 'accounting.export',
        'budgets.view', 'budgets.manage',
    ];

    private const FINANCE = [
        'billing.view', 'billing.create', 'billing.manage', 'billing.approve',
        'receivables.view', 'receivables.manage', 'payments.view', 'payments.create', 'payments.manage', 'payments.approve',
        'treasury.view', 'treasury.create', 'treasury.manage',
        'purchases.view', 'purchases.create', 'purchases.manage', 'budgets.manage',
        'payables.view', 'payables.create', 'payables.manage', 'payables.approve', 'payables.pay',
        ...self::ACCOUNTANT,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Chart::forget();
    }

    /** Líneas del asiento vigente de un documento: [cuenta => [debe, haber]]. */
    private function lines(string $type, string $sourceId): array
    {
        $entry = DB::table('acc_journal_entries')->where('source_type', $type)->where('source_id', $sourceId)->where('status', 'POSTED')->first();
        $this->assertNotNull($entry, "Sin asiento {$type}");

        return DB::table('acc_journal_lines')->where('entry_id', $entry->id)->orderBy('position')->get()
            ->mapWithKeys(fn ($line) => [$line->account_code => [(float) $line->debit, (float) $line->credit]])->all();
    }

    private function supplier(string $name = 'Servicios Andinos SAC'): string
    {
        $partyId = (string) Str::uuid();
        DB::table('parties')->insert(['id' => $partyId, 'tenant_id' => $this->tenantId, 'party_type' => 'ORGANIZATION', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organizations')->insert(['party_id' => $partyId, 'legal_name' => $name, 'tax_identifier' => (string) random_int(20100000000, 20999999999)]);
        $id = (string) Str::uuid();
        DB::table('suppliers')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'party_id' => $partyId, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function bank(float $opening = 10000, array $data = []): array
    {
        return $this->postJson('/api/v1/bank-accounts', [
            'name' => 'BCP Soles', 'account_type' => 'BANK', 'currency' => 'PEN', 'bank_name' => 'BCP', 'account_number' => '193-'.random_int(1000000, 9999999),
            'opening_balance' => $opening, ...$data,
        ])->assertCreated()->json();
    }

    private function issued(string $customerId, float $base = 1000): array
    {
        $event = $this->postJson('/api/v1/billable-events', ['customer_id' => $customerId, 'description' => 'Consultoría', 'base' => $base, 'currency' => 'PEN'])->assertCreated()->json();
        $draft = $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [$event['id']], 'type' => 'INVOICE'])->assertCreated()->json();

        return $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertOk()->json();
    }

    private function trialBalanced(): bool
    {
        return (bool) $this->getJson('/api/v1/ledger/trial-balance?from=2000-01')->assertOk()->json('balanced');
    }

    public function test_sales_collections_and_credit_notes_post_balanced_entries_once(): void
    {
        $this->makeTenant(self::MODULES);
        $customer = $this->makeCustomer();
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $bank = $this->bank(5000);

        // La apertura de la cuenta: banco contra resultados acumulados.
        $opening = DB::table('bank_movements')->where('account_id', $bank['id'])->where('ref_type', 'OPENING')->value('id');
        $this->assertEquals(['1041' => [5000, 0], '5911' => [0, 5000]], $this->lines('OPENING', $opening));

        $invoice = $this->issued($customer, 1000);
        $this->assertEquals(['1212' => [1180, 0], '40111' => [0, 180], '7041' => [0, 1000]], $this->lines('INVOICE', $invoice['id']));
        $party = DB::table('customers')->where('id', $customer)->value('party_id');
        $this->assertSame($party, DB::table('acc_journal_lines')->where('account_code', '1212')->value('party_id'));

        // Un documento genera un único asiento: volver a contabilizarlo no escribe otro.
        $this->assertNull(Posting::invoice($this->tenantId, $invoice['id'], null));
        $this->assertSame(1, DB::table('acc_journal_entries')->where('source_id', $invoice['id'])->count());

        $payment = $this->postJson('/api/v1/payments', [
            'customer_id' => $customer, 'amount' => 1180, 'currency' => 'PEN', 'payment_method' => 'TRANSFERENCIA', 'reference' => 'OP-1', 'auto_allocate' => true,
        ])->assertCreated()->json();
        $this->assertEquals(['1041' => [1180, 0], '1212' => [0, 1180]], $this->lines('PAYMENT', $payment['id']));

        // Reembolso parcial: vuelve a la cuenta por cobrar y sale del banco.
        $this->postJson("/api/v1/payments/{$payment['id']}/refund", ['amount' => 100, 'reason' => 'Cobro de más'])->assertOk();
        $refund = DB::table('refunds')->where('payment_id', $payment['id'])->value('id');
        $this->assertEquals(['1212' => [100, 0], '1041' => [0, 100]], $this->lines('REFUND', $refund));

        // Nota de crédito: el inverso del comprobante.
        $second = $this->issued($customer, 500);
        $note = $this->postJson("/api/v1/invoices/{$second['id']}/credit-note", ['reason' => 'Error de importe'])->assertCreated()->json();
        $this->assertEquals(['7041' => [500, 0], '40111' => [90, 0], '1212' => [0, 590]], $this->lines('INVOICE', $note['id']));

        $entries = $this->getJson('/api/v1/journal-entries')->assertOk()->json();
        $this->assertSame(6, $entries['total']);
        $first = collect($entries['data'])->firstWhere('source_number', $invoice['invoice_number']);
        $this->assertSame(['Venta', '1212 D · 40111 H · 7041 H', 1180.0, 'POSTED'], [$first['source_label'], $first['accounts'], (float) $first['amount'], $first['status']]);
        $this->assertMatchesRegularExpression('/^AS-\d{4}-\d{2}-000\d$/', $first['entry_number']);
        // Buscar por cuenta y filtrar por origen.
        $this->assertSame(3, $this->getJson('/api/v1/journal-entries?search=7041')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/journal-entries?source=PAYMENT')->json('total'));
        $this->assertSame(['total' => 6, 'automatic' => 6, 'balanced' => true], array_intersect_key($this->getJson('/api/v1/journal-entries/summary')->json(), array_flip(['total', 'automatic', 'balanced'])));

        $balance = $this->getJson('/api/v1/ledger/trial-balance?from=2000-01')->assertOk()->json();
        $this->assertTrue($balance['balanced']);
        $receivables = collect($balance['rows'])->firstWhere('code', '1212');
        // 1180 + 590 + 100 − 1180 − 590 = 100 por cobrar (lo reembolsado).
        $this->assertEquals([1870, 1770, 100, 0], [$receivables['debit'], $receivables['credit'], $receivables['debtor'], $receivables['creditor']]);

        // Mayor de la cuenta, con saldo corrido; la más reciente primero.
        $ledger = $this->getJson('/api/v1/ledger/accounts/1212?from=2000-01')->assertOk()->json();
        $this->assertSame(5, $ledger['total']);
        $this->assertEquals(100, $ledger['data'][0]['running']);
        $this->getJson('/api/v1/ledger/accounts/9999')->assertNotFound();

        $statements = $this->getJson('/api/v1/financial-statements?from=2000-01')->assertOk()->json();
        $this->assertEquals([1000, 0, 1000], [$statements['kpis']['income'], $statements['kpis']['expenses'], $statements['kpis']['result']]);
        // El gráfico sigue al rango (como mucho 24 meses) y trae el resultado de cada mes.
        $this->assertCount(24, $statements['months']);
        $this->assertEquals(['period' => now()->format('Y-m'), 'income' => 1000, 'expenses' => 0, 'result' => 1000], end($statements['months']));
        $this->assertCount((int) now()->month, $this->getJson('/api/v1/financial-statements')->json('months'));
        $this->assertTrue($statements['balanced']);
        $this->assertAllEntriesPassTheDatabaseCheck();
    }

    /** Dispara ya la comprobación que la base hace al confirmar (cuadre y dos líneas) sobre todo lo escrito. */
    private function assertAllEntriesPassTheDatabaseCheck(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertGreaterThan(0, DB::table('acc_journal_entries')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_purchases_post_expense_by_cost_center_and_payments_with_detraction_and_retention(): void
    {
        $this->makeTenant(self::MODULES);
        $supplier = $this->supplier();
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $center = $this->postJson('/api/v1/cost-centers', ['code' => 'cc-ops', 'name' => 'Operaciones'])->assertCreated()->json();
        $this->assertSame(['CC-OPS', 'COST'], [$center['code'], $center['kind']]);
        $bank = $this->bank(10000);

        $register = fn (array $data) => $this->postJson('/api/v1/supplier-invoices', [
            'supplier_id' => $supplier, 'doc_type' => 'FACTURA', 'doc_number' => 'F001-'.random_int(1, 99999999), 'issue_date' => now()->toDateString(),
            'subtotal' => 1000, 'currency' => 'PEN', 'cost_center_id' => $center['id'], 'expense_account' => '6343', ...$data,
        ]);
        // La cuenta de gasto sale del plan: una cuenta que no es de gasto no se acepta.
        $register(['expense_account' => '1041'])->assertStatus(422)->assertJsonValidationErrors('expense_account');

        $invoice = $register(['detraction_code' => '020'])->assertCreated()->json();
        $this->assertNull($this->getJson("/api/v1/supplier-invoices/{$invoice['id']}")->json('journal_entry'));
        $approved = $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/approve")->assertOk()->json();
        $this->assertEquals(['6343' => [1000, 0], '40111' => [180, 0], '4212' => [0, 1180]], $this->lines('SUPPLIER_INVOICE', $invoice['id']));
        $this->assertSame($center['id'], DB::table('acc_journal_lines')->where('account_code', '6343')->value('cost_center_id'));
        $this->assertMatchesRegularExpression('/^AS-/', $this->getJson("/api/v1/supplier-invoices/{$invoice['id']}")->json('journal_entry.number'));

        // Pago con el depósito de la detracción desde la misma cuenta.
        $payment = $this->postJson('/api/v1/supplier-payments', [
            'payable_ids' => [$approved['payable']['id']], 'financial_account_id' => $bank['id'], 'payment_method' => 'TRANSFERENCIA', 'reference' => 'OP-9',
            'deposit_detraction' => true, 'detraction_number' => '777',
        ])->assertCreated()->json();
        $entry = DB::table('acc_journal_entries')->where('source_id', $payment['id'])->first();
        $lines = DB::table('acc_journal_lines')->where('entry_id', $entry->id)->orderBy('position')->get(['account_code', 'debit', 'credit'])
            ->map(fn ($line) => [$line->account_code, (float) $line->debit, (float) $line->credit])->all();
        $this->assertEquals([['4212', 1180, 0], ['1041', 0, 1038], ['1041', 0, 142]], $lines);

        // Recibo por honorarios con retención de 4.ª: la cuenta por pagar nace con el neto.
        $fee = $register(['doc_type' => 'RECIBO_HONORARIOS', 'doc_number' => 'E001-15', 'subtotal' => 2000])->assertCreated()->json();
        $this->postJson("/api/v1/supplier-invoices/{$fee['id']}/approve")->assertOk();
        $this->assertEquals(['6343' => [2000, 0], '4212' => [0, 1840], '40172' => [0, 160]], $this->lines('SUPPLIER_INVOICE', $fee['id']));

        // Caja chica: la cuenta y el centro de costo del gasto (del plan) van a su asiento.
        $cash = $this->postJson('/api/v1/bank-accounts', ['name' => 'Caja chica', 'account_type' => 'CASH', 'currency' => 'PEN', 'opening_balance' => 300, 'fund_amount' => 300])->assertCreated()->json();
        $expense = $this->postJson('/api/v1/cash-expenses', ['account_id' => $cash['id'], 'description' => 'Taxi', 'amount' => 25, 'cost_center_id' => $center['id'], 'expense_account' => '6311'])->assertCreated()->json();
        $this->assertEquals(['6311' => [25, 0], '1011' => [0, 25]], $this->lines('CASH_EXPENSE', $expense['id']));
        $this->assertSame($center['id'], DB::table('acc_journal_lines')->where('account_code', '6311')->value('cost_center_id'));
        $other = $this->postJson('/api/v1/cash-expenses', ['account_id' => $cash['id'], 'description' => 'Útiles', 'amount' => 10, 'cost_center_id' => $center['id'], 'expense_account' => '6563'])->assertCreated()->json();
        $this->assertArrayHasKey('6563', $this->lines('CASH_EXPENSE', $other['id']));

        // Transferencia entre cuentas.
        $transfer = $this->postJson('/api/v1/bank-transfers', ['from_account_id' => $bank['id'], 'to_account_id' => $cash['id'], 'amount' => 35, 'reference' => 'REP-1'])->assertCreated();
        $transferId = DB::table('bank_movements')->where('ref_type', 'TRANSFER')->where('op_number', 'REP-1')->value('ref_id');
        $this->assertEquals(['1011' => [35, 0], '1041' => [0, 35]], $this->lines('TRANSFER', $transferId));

        $this->assertTrue($this->trialBalanced());
        $statements = $this->getJson('/api/v1/financial-statements?from=2000-01')->json();
        $this->assertEquals(3035, $statements['kpis']['expenses']);
        $this->assertAllEntriesPassTheDatabaseCheck();
    }

    public function test_manual_entries_are_balanced_and_corrected_only_by_reversal(): void
    {
        $this->makeTenant(self::MODULES);
        $accountant = $this->makeUser('Contador', self::ACCOUNTANT);
        $this->as($accountant);
        $today = now()->toDateString();
        $entry = fn (array $lines, array $data = []) => $this->postJson('/api/v1/journal-entries', ['date' => $today, 'description' => 'Aporte de capital', 'lines' => $lines, ...$data]);

        $entry([['account' => '1041', 'debit' => 100]])->assertStatus(422)->assertJsonValidationErrors('lines');
        $entry([['account' => '1041', 'debit' => 100], ['account' => '5011', 'credit' => 90]])->assertStatus(422)->assertJsonValidationErrors('lines');
        // Una cuenta título no es imputable.
        $entry([['account' => '10', 'debit' => 100], ['account' => '5011', 'credit' => 100]])->assertStatus(422)->assertJsonValidationErrors('lines');
        $entry([['account' => '1041', 'debit' => 100, 'credit' => 100], ['account' => '5011', 'credit' => 100]])->assertStatus(422)->assertJsonValidationErrors('lines');
        $entry([['account' => '1041', 'debit' => 100], ['account' => '5011', 'credit' => 100]], ['description' => ''])->assertStatus(422)->assertJsonValidationErrors('description');

        $created = $entry([['account' => '1041', 'debit' => 1500], ['account' => '5011', 'credit' => 1500], ['account' => '', 'debit' => 0]])->assertCreated()->json();
        $this->assertSame(['MANUAL', 'Manual', 'POSTED', 2, 'Contador'], [$created['source_type'], $created['source_label'], $created['status'], count($created['lines']), $created['created_by']]);
        $this->assertSame('AS-'.now()->format('Y-m').'-0001', $created['entry_number']);
        $this->assertSame('Acciones', $created['lines'][1]['account_name']);

        $this->postJson("/api/v1/journal-entries/{$created['id']}/reverse", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $reversed = $this->postJson("/api/v1/journal-entries/{$created['id']}/reverse", ['reason' => 'Aporte duplicado'])->assertOk()->json();
        $this->assertSame(['REVERSED', 'Aporte duplicado', 'AS-'.now()->format('Y-m').'-0002'], [$reversed['status'], $reversed['reason'], $reversed['reversed_by']['number']]);
        $reversal = $this->getJson("/api/v1/journal-entries/{$reversed['reversed_by']['id']}")->assertOk()->json();
        $this->assertSame(['REVERSAL', $created['entry_number']], [$reversal['source_type'], $reversal['reversal_of']['number']]);
        $this->assertEquals([0, 1500], [$reversal['lines'][0]['debit'], $reversal['lines'][0]['credit']]);
        $this->postJson("/api/v1/journal-entries/{$created['id']}/reverse", ['reason' => 'Otra vez'])->assertStatus(422);
        $this->postJson("/api/v1/journal-entries/{$reversal['id']}/reverse", ['reason' => 'Extorno del extorno'])->assertStatus(422);
        $this->assertTrue($this->trialBalanced());

        $this->assertSame('Asiento extornado', AuditCatalog::actionLabel('journal.reverse'));
        $this->assertSame(1, DB::table('audit_logs')->where('entity_id', $created['id'])->where('action', 'journal.reverse')->count());

        // La base: un asiento no se edita, sus líneas no se tocan, ni se borra nada.
        $this->assertDatabaseRejects(fn () => DB::table('acc_journal_entries')->where('id', $reversal['id'])->update(['description' => 'Otra glosa']));
        $this->assertDatabaseRejects(fn () => DB::table('acc_journal_lines')->where('entry_id', $created['id'])->update(['debit' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('acc_journal_lines')->where('entry_id', $created['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('acc_journal_entries')->where('id', $created['id'])->delete());
        // La base comprueba al cerrar la operación que cada asiento cuadre y tenga dos líneas; aquí
        // se fuerza esa comprobación al instante, porque la prueba nunca llega a confirmar.
        $write = function (float $credit, int $lines = 2) {
            $id = (string) Str::uuid();
            DB::table('acc_journal_entries')->insert([
                'id' => $id, 'tenant_id' => $this->tenantId, 'entry_number' => 'AS-X-'.Str::random(4), 'entry_date' => now()->toDateString(), 'period' => now()->format('Y-m'),
                'description' => 'Prueba', 'source_type' => 'MANUAL',
            ]);
            DB::table('acc_journal_lines')->insert(['tenant_id' => $this->tenantId, 'entry_id' => $id, 'position' => 1, 'account_code' => '1041', 'debit' => 10, 'credit' => 0]);
            if ($lines > 1) {
                DB::table('acc_journal_lines')->insert(['tenant_id' => $this->tenantId, 'entry_id' => $id, 'position' => 2, 'account_code' => '5011', 'debit' => 0, 'credit' => $credit]);
            }
            // Pasar a inmediatas dispara ahora las comprobaciones pendientes, como al confirmar.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        };
        foreach ([[9, 2, 'no cuadra'], [10, 1, 'al menos dos líneas']] as [$credit, $count, $message]) {
            try {
                DB::transaction(fn () => $write($credit, $count));
                $this->fail('La base aceptó un asiento inválido.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        // Uno que cuadra pasa la misma comprobación.
        DB::transaction(fn () => $write(10));
        // (Al final: este asiento sin líneas dejaría pendiente su comprobación.)
        // Una línea nueva a un asiento ya escrito (en otra operación) se rechaza.
        $old = (string) Str::uuid();
        DB::table('acc_journal_entries')->insert([
            'id' => $old, 'tenant_id' => $this->tenantId, 'entry_number' => 'AS-2000-01-0001', 'entry_date' => '2000-01-15', 'period' => '2000-01',
            'description' => 'Antiguo', 'source_type' => 'MANUAL', 'created_at' => now()->subDay(),
        ]);
        $this->assertDatabaseRejects(fn () => DB::table('acc_journal_lines')->insert([
            'tenant_id' => $this->tenantId, 'entry_id' => $old, 'account_code' => '1041', 'debit' => 10, 'credit' => 0,
        ]));
    }

    public function test_periods_close_once_ended_and_reconciled_and_closed_periods_take_no_entries(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $last = now()->subMonthNoOverflow();
        $lastPeriod = $last->format('Y-m');
        $bank = $this->bank(0);
        // Un gasto bancario del mes pasado, sin conciliar.
        $account = \App\Modules\Finance\Models\FinancialAccount::find($bank['id']);
        $movement = \App\Modules\Finance\Support\Treasury::move($account, -12, 'BANK_FEE', 'Comisión', ['date' => $last->copy()->startOfMonth()->addDays(3)]);
        Posting::bankMovement($this->tenantId, $movement, null);
        $this->assertEquals(['6391' => [12, 0], '1041' => [0, 12]], $this->lines('BANK_FEE', $movement));

        $periods = collect($this->getJson('/api/v1/accounting-periods')->assertOk()->json());
        $this->assertSame([now()->format('Y-m'), $lastPeriod], $periods->pluck('period')->all());
        $this->assertSame([1, 1], [$periods->firstWhere('period', $lastPeriod)['entries'], $periods->firstWhere('period', $lastPeriod)['unreconciled']]);

        // El periodo en curso no se cierra; uno con movimientos sin conciliar, tampoco.
        $this->postJson('/api/v1/accounting-periods/'.now()->format('Y-m').'/close')->assertStatus(422);
        $this->postJson("/api/v1/accounting-periods/{$lastPeriod}/close")->assertStatus(422)->assertJsonValidationErrors('status');
        DB::table('bank_movements')->where('id', $movement)->update(['reconciled' => true, 'reconciled_at' => now()]);
        $closed = $this->postJson("/api/v1/accounting-periods/{$lastPeriod}/close")->assertOk()->json();
        $this->assertSame(['CLOSED', 'Finanzas'], [$closed['status'], $closed['closed_by']]);

        // Un asiento manual con fecha en el periodo cerrado se rechaza; un documento de esa fecha se contabiliza hoy.
        $this->postJson('/api/v1/journal-entries', ['date' => $last->toDateString(), 'description' => 'Ajuste', 'lines' => [
            ['account' => '6391', 'debit' => 5], ['account' => '1041', 'credit' => 5],
        ]])->assertStatus(422)->assertJsonValidationErrors('date');
        $late = \App\Modules\Finance\Support\Treasury::move($account, -3, 'BANK_FEE', 'Comisión tardía', ['date' => $last->toDateString()]);
        Posting::bankMovement($this->tenantId, $late, null);
        $this->assertSame(now()->format('Y-m'), DB::table('acc_journal_entries')->where('source_id', $late)->value('period'));
        $this->assertDatabaseRejects(fn () => DB::table('acc_journal_entries')->insert([
            'tenant_id' => $this->tenantId, 'entry_number' => 'AS-Z', 'entry_date' => $last->toDateString(), 'period' => $lastPeriod,
            'description' => 'En periodo cerrado', 'source_type' => 'MANUAL',
        ]));

        $this->postJson("/api/v1/accounting-periods/{$lastPeriod}/reopen")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('OPEN', $this->postJson("/api/v1/accounting-periods/{$lastPeriod}/reopen", ['reason' => 'Falta una factura'])->assertOk()->json('status'));
        $this->assertSame(2, DB::table('audit_logs')->where('entity_type', 'AccountingPeriod')->count());
    }

    public function test_the_budget_is_prepared_approved_and_controls_purchase_orders(): void
    {
        $this->makeTenant(self::MODULES);
        $supplier = $this->supplier();
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $manager = $this->makeUser('Gerencia', ['budgets.view', 'budgets.approve', 'budgets.export', 'purchases.view', 'purchases.approve', 'payables.view', 'payables.approve']);
        $this->as($finance);
        $center = $this->postJson('/api/v1/cost-centers', ['code' => 'CC-OPS', 'name' => 'Operaciones', 'manager_user_id' => $finance->id])->assertCreated()->json();
        $revenue = $this->postJson('/api/v1/cost-centers', ['code' => 'CC-VTA', 'name' => 'Ventas', 'kind' => 'REVENUE'])->assertCreated()->json();
        $year = (int) now()->year;
        // Con tipo de cambio registrado: lo comprometido en dólares se lleva a soles.
        DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'currency' => 'USD', 'rate_date' => now()->toDateString(), 'buy' => 3.44, 'sell' => 3.448]);

        $this->assertNull($this->getJson('/api/v1/budgets/execution')->assertOk()->json('budget'));
        $budget = $this->postJson('/api/v1/budgets', ['year' => $year])->assertCreated()->json();
        $this->postJson('/api/v1/budgets', ['year' => $year])->assertStatus(422);
        $this->assertSame(['DRAFT', "Presupuesto {$year}"], [$budget['status'], $budget['name']]);

        $line = fn (array $data) => $this->putJson("/api/v1/budgets/{$budget['id']}/lines", ['cost_center_id' => $center['id'], 'account_code' => '6343', 'annual' => 1200, ...$data]);
        $line(['account_code' => '1041'])->assertStatus(422)->assertJsonValidationErrors('account_code');
        $line([])->assertOk();
        $line(['annual' => 1000])->assertOk();   // se ajusta: 83.33 × 11 + 83.37
        $this->assertEquals(1000, DB::table('budget_lines')->where('budget_id', $budget['id'])->sum('amount'));
        $this->assertSame(12, DB::table('budget_lines')->where('budget_id', $budget['id'])->count());
        $this->putJson("/api/v1/budgets/{$budget['id']}/lines", ['cost_center_id' => $revenue['id'], 'account_code' => '7041', 'annual' => 12000])->assertOk();

        // Aprobar es de otra persona con su permiso.
        $this->postJson("/api/v1/budgets/{$budget['id']}/approve")->assertForbidden();
        $this->as($manager);
        $this->assertSame('APPROVED', $this->postJson("/api/v1/budgets/{$budget['id']}/approve")->assertOk()->json('status'));
        $this->as($finance);
        $line(['annual' => 5000])->assertStatus(422);
        $this->assertDatabaseRejects(fn () => DB::table('budget_lines')->where('budget_id', $budget['id'])->update(['amount' => 1]));

        // Orden de compra de 800 + 400 = 1200 sobre un disponible de 1000: se aprueba con advertencia.
        $order = $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'cost_center_id' => $center['id'], 'currency' => 'PEN', 'submit' => true, 'items' => [
            ['description' => 'Mantenimiento', 'quantity' => 2, 'unit_cost' => 600, 'account_code' => '6343'],
        ]])->assertCreated()->json();
        $detail = $this->getJson("/api/v1/purchase-orders/{$order['id']}")->json('budget');
        $this->assertEquals(['annual' => 1000, 'actual' => 0, 'committed' => 0, 'available' => 1000, 'over' => true], $detail);
        $this->as($manager);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")->assertOk();
        $this->assertStringContainsString('sobre presupuesto', (string) DB::table('purchase_orders')->where('id', $order['id'])->value('budget_warning'));

        $this->as($finance);
        $execution = $this->getJson('/api/v1/budgets/execution')->assertOk()->json();
        $ops = collect($execution['centers'])->firstWhere('code', 'CC-OPS');
        $this->assertEquals([1000, 0, 1200, 120, 'Finanzas'], [$ops['annual'], $ops['actual'], $ops['committed'], $ops['pct'], $ops['manager']]);
        $this->assertEquals(['cost_budget' => 1000, 'cost_actual' => 0, 'committed' => 1200, 'revenue_pct' => 0], $execution['kpis']);
        $this->assertNull(collect($execution['centers'])->firstWhere('code', 'CC-VTA')['committed']);

        // Facturado y aprobado: pasa de comprometido a ejecutado.
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 100])->assertOk();
        $invoice = $this->postJson('/api/v1/supplier-invoices', [
            'supplier_id' => $supplier, 'doc_type' => 'FACTURA', 'doc_number' => 'F001-77', 'issue_date' => now()->toDateString(), 'subtotal' => 1200,
            'currency' => 'PEN', 'cost_center_id' => $center['id'], 'expense_account' => '6343', 'purchase_order_id' => $order['id'],
        ])->assertCreated()->json();
        $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/approve")->assertOk();
        $ops = collect($this->getJson('/api/v1/budgets/execution')->json('centers'))->firstWhere('code', 'CC-OPS');
        $this->assertEquals([1200, 0, 120], [$ops['actual'], $ops['committed'], $ops['pct']]);
        $lines = $this->getJson("/api/v1/cost-centers/{$center['id']}/budget")->assertOk()->json('lines');
        $this->assertEquals(['6343', 1000, 1200, 120], [$lines[0]['account_code'], $lines[0]['annual'], $lines[0]['actual'], $lines[0]['pct']]);

        // Reabrir para ajuste pide motivo; en borrador vuelve a aceptar líneas.
        $this->postJson("/api/v1/budgets/{$budget['id']}/reopen")->assertStatus(422);
        $this->postJson("/api/v1/budgets/{$budget['id']}/reopen", ['reason' => 'Más mantenimiento'])->assertOk();
        $line(['annual' => 5000])->assertOk();

        // El código de un centro de costo no cambia; el resto se edita.
        $edited = $this->putJson("/api/v1/cost-centers/{$center['id']}", ['name' => 'Operaciones Lima', 'kind' => 'COST', 'active' => true])->assertOk()->json();
        $this->assertSame(['CC-OPS', 'Operaciones Lima'], [$edited['center']['code'], $edited['center']['name']]);
        $this->assertDatabaseRejects(fn () => DB::table('cost_centers')->where('id', $center['id'])->update(['code' => 'OTRO']));
    }

    public function test_permissions_scope_exports_and_the_chart_of_accounts(): void
    {
        $this->makeTenant(self::MODULES);
        $north = $this->makeBranch('Sede norte');
        $south = $this->makeBranch('Sede sur');
        $admin = $this->makeUser('Contador', self::FINANCE);
        $viewer = $this->makeUser('Gerencia', ['accounting.view', 'budgets.view']);
        $operations = $this->makeUser('Operaciones', ['budgets.view'], 'BRANCH', $north);
        $nobody = $this->makeUser('Ventas', ['billing.view']);
        $this->as($admin);
        $mine = $this->postJson('/api/v1/cost-centers', ['code' => 'CC-N', 'name' => 'Norte', 'branch_id' => $north])->assertCreated()->json();
        $theirs = $this->postJson('/api/v1/cost-centers', ['code' => 'CC-S', 'name' => 'Sur', 'branch_id' => $south])->assertCreated()->json();
        $this->postJson('/api/v1/cost-centers', ['code' => 'CC-X', 'name' => 'Sede rara', 'branch_id' => (string) Str::uuid()])->assertStatus(422);

        // Plan de cuentas: subcuentas, cuentas título y cuentas de las reglas automáticas.
        $this->postJson('/api/v1/accounting-accounts', ['code' => '63921', 'name' => 'Subcontratos', 'account_type' => 'EXPENSE', 'postable' => true])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/accounting-accounts', ['code' => '6343', 'name' => 'Repetida', 'account_type' => 'EXPENSE', 'postable' => true])->assertStatus(422);
        $new = $this->postJson('/api/v1/accounting-accounts', ['code' => '6395', 'name' => 'Seguridad y vigilancia', 'account_type' => 'EXPENSE', 'postable' => true])->assertCreated()->json();
        $this->assertSame(['63', 'D', true], [$new['parent_code'], $new['nature'], $new['postable']]);
        $sub = $this->postJson('/api/v1/accounting-accounts', ['code' => '63951', 'name' => 'Vigilancia nocturna', 'account_type' => 'EXPENSE', 'postable' => true])->assertCreated()->json();
        $this->assertSame('6395', $sub['parent_code']);
        $chart = collect($this->getJson('/api/v1/accounting-accounts')->assertOk()->json());
        $this->assertFalse($chart->firstWhere('code', '6395')['postable']);
        // Compras ofrece la cuenta nueva y ya no la que pasó a título.
        $offered = collect($this->getJson('/api/v1/supplier-invoices/options')->json('expense_accounts'))->pluck('code');
        $this->assertTrue($offered->contains('63951'));
        $this->assertFalse($offered->contains('6395'));
        $this->putJson("/api/v1/accounting-accounts/{$chart->firstWhere('code', '1212')['id']}", ['name' => 'Facturas por cobrar', 'active' => false])->assertStatus(422);
        $this->assertSame('Vigilancia', $this->putJson("/api/v1/accounting-accounts/{$sub['id']}", ['name' => 'Vigilancia', 'active' => false])->assertOk()->json('name'));
        $this->assertDatabaseRejects(fn () => DB::table('acc_accounts')->where('id', $sub['id'])->update(['code' => '63952']));
        $this->assertCount(count(Posting::RULES), $this->getJson('/api/v1/accounting-rules')->assertOk()->json());

        // Sin permiso de ver: 403. Ver sin exportar: la exportación se rechaza.
        $this->as($nobody);
        $this->getJson('/api/v1/journal-entries')->assertForbidden();
        $this->getJson('/api/v1/budgets/execution')->assertForbidden();
        $this->as($viewer);
        $this->getJson('/api/v1/journal-entries')->assertOk();
        $this->getJson('/api/v1/journal-entries?export=1')->assertForbidden();
        $this->postJson('/api/v1/exports', ['resource' => 'accounting', 'format' => 'xlsx', 'rows' => 3])->assertForbidden();
        $this->postJson('/api/v1/journal-entries', ['date' => now()->toDateString(), 'description' => 'X', 'lines' => []])->assertForbidden();
        $this->as($admin);
        $this->getJson('/api/v1/journal-entries?export=1')->assertOk();
        $this->postJson('/api/v1/exports', ['resource' => 'accounting', 'format' => 'csv', 'rows' => 2])->assertSuccessful();
        // Finanzas prepara presupuestos pero no los exporta (matriz del prototipo).
        $this->postJson('/api/v1/exports', ['resource' => 'budgets', 'format' => 'csv', 'rows' => 2])->assertForbidden();

        // Alcance "Sede": Operaciones ve los centros de su sede; el de otra sede no existe para ella.
        $this->as($operations);
        $this->assertSame(['CC-N'], collect($this->getJson('/api/v1/budgets/execution')->assertOk()->json('centers'))->pluck('code')->all());
        $this->getJson("/api/v1/cost-centers/{$mine['id']}/budget")->assertOk();
        $this->getJson("/api/v1/cost-centers/{$theirs['id']}/budget")->assertNotFound();
        $this->putJson("/api/v1/cost-centers/{$mine['id']}", ['name' => 'X', 'kind' => 'COST'])->assertForbidden();

        // Otra empresa: lo de esta no existe.
        $entry = DB::table('acc_journal_entries')->where('tenant_id', $this->tenantId)->value('id');
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Contador B', self::FINANCE));
        $this->getJson("/api/v1/cost-centers/{$mine['id']}/budget")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/budgets/execution')->json('centers'));
        $this->assertSame(0, $this->getJson('/api/v1/journal-entries')->json('total'));
        if ($entry) {
            $this->getJson("/api/v1/journal-entries/{$entry}")->assertNotFound();
        }
    }

    public function test_the_pending_command_posts_documents_registered_before_accounting_once(): void
    {
        $this->makeTenant(self::MODULES);
        $supplier = $this->supplier();
        $center = (string) Str::uuid();
        DB::table('cost_centers')->insert(['id' => $center, 'tenant_id' => $this->tenantId, 'code' => 'CC-ADM', 'name' => 'Administración', 'active' => true]);
        // Como si se hubieran registrado antes de Contabilidad: escritos directo, sin asiento.
        $account = (string) Str::uuid();
        DB::table('financial_accounts')->insert(['id' => $account, 'tenant_id' => $this->tenantId, 'code' => 'CTA-1', 'name' => 'BCP', 'account_type' => 'BANK', 'currency' => 'PEN', 'status' => 'ACTIVE']);
        $opening = (string) Str::uuid();
        DB::table('bank_movements')->insert(['id' => $opening, 'tenant_id' => $this->tenantId, 'account_id' => $account, 'movement_date' => now()->toDateString(), 'description' => 'Apertura', 'amount' => 1000, 'ref_type' => 'OPENING', 'reconciled' => true]);
        $invoice = (string) Str::uuid();
        DB::table('supplier_invoices')->insert([
            'id' => $invoice, 'tenant_id' => $this->tenantId, 'supplier_id' => $supplier, 'supplier_name' => 'Servicios Andinos SAC', 'doc_type' => 'FACTURA',
            'doc_number' => 'F001-1', 'issue_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'cost_center_id' => $center,
            'expense_account' => '6364', 'currency' => 'PEN', 'subtotal' => 100, 'tax' => 18, 'total' => 118, 'status' => 'APPROVED', 'approved_at' => now(),
        ]);
        $this->assertSame(0, DB::table('acc_journal_entries')->where('tenant_id', $this->tenantId)->count());

        $code = DB::table('tenants')->where('id', $this->tenantId)->value('code');
        $this->artisan('accounting:post-pending', ['--tenant' => $code])->assertSuccessful();
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$this->tenantId]);
        $this->assertEquals(['6364' => [100, 0], '40111' => [18, 0], '4212' => [0, 118]], $this->lines('SUPPLIER_INVOICE', $invoice));
        $this->assertEquals(['1041' => [1000, 0], '5911' => [0, 1000]], $this->lines('OPENING', $opening));
        $this->assertSame(2, DB::table('acc_journal_entries')->where('tenant_id', $this->tenantId)->count());
        // La apertura va antes que lo demás del mismo día.
        $this->assertSame('OPENING', DB::table('acc_journal_entries')->where('tenant_id', $this->tenantId)->orderBy('entry_number')->value('source_type'));

        $this->artisan('accounting:post-pending', ['--tenant' => $code])->assertSuccessful();
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$this->tenantId]);
        $this->assertSame(2, DB::table('acc_journal_entries')->where('tenant_id', $this->tenantId)->count());
    }
}
