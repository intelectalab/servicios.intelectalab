<?php

namespace Tests\Feature;

use App\Modules\Accounting\Support\Chart;
use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Correcciones que no borran nada: el centro o la cuenta de un gasto de caja (corrección registrada,
 * extorno y asiento nuevo), la reversión de un pago a proveedor (movimientos de vuelta y extorno) y
 * la tolerancia del cruce de 3 vías como regla de la empresa.
 */
class TreasuryAndPayablesCorrectionsTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const FINANCE = [
        'treasury.view', 'treasury.create', 'treasury.manage', 'treasury.export',
        'purchases.view', 'purchases.create', 'purchases.manage', 'budgets.manage',
        'payables.view', 'payables.create', 'payables.manage', 'payables.approve', 'payables.pay', 'payables.export',
        'accounting.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Chart::forget();
    }

    private function center(string $code): string
    {
        $id = (string) Str::uuid();
        DB::table('cost_centers')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'code' => $code, 'name' => "Centro {$code}", 'active' => true]);

        return $id;
    }

    private function account(array $data = []): array
    {
        return $this->postJson('/api/v1/bank-accounts', [
            'name' => 'BCP Soles', 'account_type' => 'BANK', 'currency' => 'PEN', 'bank_name' => 'BCP', 'account_number' => '193-'.random_int(1000000, 9999999),
            'opening_balance' => 10000, ...$data,
        ])->assertCreated()->json();
    }

    private function supplier(): string
    {
        $partyId = (string) Str::uuid();
        DB::table('parties')->insert(['id' => $partyId, 'tenant_id' => $this->tenantId, 'party_type' => 'ORGANIZATION', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organizations')->insert(['party_id' => $partyId, 'legal_name' => 'Servicios Andinos SAC', 'tax_identifier' => (string) random_int(20100000000, 20999999999)]);
        $id = (string) Str::uuid();
        DB::table('suppliers')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'party_id' => $partyId, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function lines(string $entryId): array
    {
        return DB::table('acc_journal_lines')->where('entry_id', $entryId)->orderBy('position')->get()
            ->map(fn ($line) => [$line->account_code, (float) $line->debit, (float) $line->credit, $line->cost_center_id])->all();
    }

    public function test_a_cash_expense_is_reclassified_with_its_correction_recorded_and_a_new_entry(): void
    {
        $this->makeTenant(['FINANZAS', 'COMPRAS', 'CONTABILIDAD']);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $cash = $this->account(['name' => 'Caja chica', 'account_type' => 'CASH', 'bank_name' => null, 'account_number' => null, 'opening_balance' => 500, 'fund_amount' => 500]);
        $wrong = $this->center('CC-VTA');
        $right = $this->center('CC-OPS');
        $expense = $this->postJson('/api/v1/cash-expenses', [
            'account_id' => $cash['id'], 'description' => 'Taxi a cliente', 'amount' => 40, 'cost_center_id' => $wrong, 'expense_account' => '6311',
        ])->assertCreated()->json();
        $original = DB::table('acc_journal_entries')->where('source_type', 'CASH_EXPENSE')->where('source_id', $expense['id'])->first();
        $url = "/api/v1/cash-expenses/{$expense['id']}/reclassify";

        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['cost_center_id', 'expense_account', 'reason']);
        $this->postJson($url, ['cost_center_id' => $wrong, 'expense_account' => '6311', 'reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('cost_center_id');
        $this->postJson($url, ['cost_center_id' => $right, 'expense_account' => '1041', 'reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('expense_account');
        // Ver o registrar gastos no es corregirlos.
        $this->as($this->makeUser('Caja', ['treasury.view', 'treasury.create']));
        $this->postJson($url, ['cost_center_id' => $right, 'expense_account' => '6343', 'reason' => 'x'])->assertStatus(403);
        $this->as($this->makeUser('Finanzas', self::FINANCE));

        $done = $this->postJson($url, ['cost_center_id' => $right, 'expense_account' => '6343', 'reason' => 'Era de Operaciones'])->assertOk()->json();
        $this->assertNotNull($done['entry']);
        // El asiento anterior queda extornado y el nuevo lleva el centro y la cuenta correctos.
        $this->assertSame('REVERSED', DB::table('acc_journal_entries')->where('id', $original->id)->value('status'));
        $new = DB::table('acc_journal_entries')->where('source_type', 'CASH_EXPENSE')->where('source_id', $expense['id'])->where('status', 'POSTED')->first();
        $this->assertSame([['6343', 40.0, 0.0, $right], ['1011', 0.0, 40.0, null]], $this->lines($new->id));
        $row = DB::table('cash_expenses')->where('id', $expense['id'])->first();
        $this->assertSame([$right, '6343', 'CC-OPS · Centro CC-OPS', 40.0], [$row->cost_center_id, $row->expense_account, $row->cost_center, (float) $row->amount]);
        $correction = DB::table('cash_expense_reclassifications')->where('cash_expense_id', $expense['id'])->first();
        $this->assertSame([$wrong, $right, '6311', '6343', $original->id, $new->id], [
            $correction->old_cost_center_id, $correction->new_cost_center_id, $correction->old_account, $correction->new_account, $correction->reversed_entry_id, $correction->new_entry_id,
        ]);
        // La caja no se movió y el listado cuenta la corrección.
        $this->assertEquals(460, (float) DB::table('bank_movements')->where('account_id', $cash['id'])->sum('amount'));
        $listed = collect($this->getJson("/api/v1/cash-expenses?account_id={$cash['id']}")->json('data'))->firstWhere('id', $expense['id']);
        $this->assertSame([1, $right, '6343'], [$listed['corrections'], $listed['cost_center_id'], $listed['expense_account_code']]);
        $this->assertTrue((bool) $this->getJson('/api/v1/ledger/trial-balance?from=2000-01')->json('balanced'));

        // La base: sin corrección registrada no cambia la clasificación; el importe nunca; la corrección no se borra.
        $this->assertDatabaseRejects(fn () => DB::table('cash_expenses')->where('id', $expense['id'])->update(['cost_center_id' => $wrong]));
        $this->assertDatabaseRejects(fn () => DB::table('cash_expenses')->where('id', $expense['id'])->update(['amount' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('cash_expense_reclassifications')->where('id', $correction->id)->delete());
        $audit = DB::table('audit_logs')->where('entity_id', $expense['id'])->where('action', 'treasury.cash_reclassify')->first();
        $this->assertNotNull($audit);
        $this->assertArrayHasKey('treasury.cash_reclassify', AuditCatalog::ACTIONS);
    }

    public function test_a_supplier_payment_is_reversed_with_movements_and_its_entry_reversed(): void
    {
        $this->makeTenant(['FINANZAS', 'COMPRAS', 'CONTABILIDAD']);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $bank = $this->account(['opening_balance' => 5000]);
        $supplier = $this->supplier();
        $center = $this->center('CC-OPS');
        $invoice = $this->postJson('/api/v1/supplier-invoices', [
            'supplier_id' => $supplier, 'doc_type' => 'FACTURA', 'doc_number' => 'F001-77', 'issue_date' => now()->toDateString(),
            'subtotal' => 500, 'currency' => 'PEN', 'cost_center_id' => $center, 'expense_account' => '6343',
        ])->assertCreated()->json();
        $payableId = $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/approve")->assertOk()->json('payable.id');
        $payment = $this->postJson('/api/v1/supplier-payments', [
            'payable_ids' => [$payableId], 'financial_account_id' => $bank['id'], 'payment_method' => 'TRANSFERENCIA', 'reference' => 'OP-1',
        ])->assertCreated()->json();
        $this->assertSame('PAID', DB::table('payables')->where('id', $payableId)->value('status'));
        $url = "/api/v1/supplier-payments/{$payment['id']}";

        $detail = $this->getJson($url)->assertOk()->json();
        $this->assertSame(['F001-77', 1, 'POSTED'], [$detail['allocations'][0]['doc_number'], count($detail['movements']), $detail['journal_entry']['status']]);

        // Aprobar no da para revertir (Gerencia); sin motivo, tampoco.
        $this->as($this->makeUser('Gerencia', ['payables.view', 'payables.approve']));
        $this->postJson("{$url}/reverse", ['reason' => 'x'])->assertStatus(403);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $this->postJson("{$url}/reverse", [])->assertStatus(422)->assertJsonValidationErrors('reason');

        $reversed = $this->postJson("{$url}/reverse", ['reason' => 'Transferencia rechazada por el banco'])->assertOk()->json();
        $this->assertSame(['REVERSED', 'Transferencia rechazada por el banco', 'REVERSED'], [$reversed['status'], $reversed['reversal_reason'], $reversed['journal_entry']['status']]);
        // La cuenta por pagar recupera su saldo y el dinero vuelve a la cuenta.
        $payable = DB::table('payables')->where('id', $payableId)->first();
        $this->assertEquals([590, 'OPEN'], [(float) $payable->balance_amount, $payable->status]);
        $this->assertEquals(5000, (float) DB::table('bank_movements')->where('account_id', $bank['id'])->sum('amount'));
        $this->assertSame(['Pago proveedor', 'Reversión de pago'], array_column($reversed['movements'], 'type'));
        $this->assertSame(['CHARGE', 'PAYMENT', 'PAYMENT_REVERSAL'], array_column($this->getJson("/api/v1/payables/{$payableId}")->json('movements'), 'movement_type'));
        $this->assertTrue((bool) $this->getJson('/api/v1/ledger/trial-balance?from=2000-01')->json('balanced'));
        // Revertido no cambia más ni se revierte dos veces; la cuenta se puede pagar de nuevo.
        $this->postJson("{$url}/reverse", ['reason' => 'Otra vez'])->assertStatus(422);
        $this->assertDatabaseRejects(fn () => DB::table('supplier_payments')->where('id', $payment['id'])->update(['status' => 'PAID']));
        $this->assertDatabaseRejects(fn () => DB::table('supplier_payments')->where('id', $payment['id'])->update(['amount' => 1]));
        $this->postJson('/api/v1/supplier-payments', [
            'payable_ids' => [$payableId], 'financial_account_id' => $bank['id'], 'payment_method' => 'TRANSFERENCIA', 'reference' => 'OP-2',
        ])->assertCreated();
        foreach (['supplier_payment.reverse', 'supplier_invoice.payment_reversed'] as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }

        // Un pago con depósito de detracción no se revierte (ni la base lo deja).
        $withDetraction = $this->postJson('/api/v1/supplier-invoices', [
            'supplier_id' => $supplier, 'doc_type' => 'FACTURA', 'doc_number' => 'F001-78', 'issue_date' => now()->toDateString(),
            'subtotal' => 1000, 'currency' => 'PEN', 'cost_center_id' => $center, 'expense_account' => '6343', 'detraction_code' => '020',
        ])->assertCreated()->json();
        $payable = $this->postJson("/api/v1/supplier-invoices/{$withDetraction['id']}/approve")->assertOk()->json('payable.id');
        $deposit = $this->postJson('/api/v1/supplier-payments', [
            'payable_ids' => [$payable], 'financial_account_id' => $bank['id'], 'payment_method' => 'TRANSFERENCIA', 'reference' => 'OP-3',
            'deposit_detraction' => true, 'detraction_number' => '998877',
        ])->assertCreated()->json();
        $this->postJson("/api/v1/supplier-payments/{$deposit['id']}/reverse", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertDatabaseRejects(fn () => DB::table('supplier_payments')->where('id', $deposit['id'])->update(['status' => 'REVERSED', 'reversed_at' => now(), 'reversal_reason' => 'x']));
    }

    public function test_the_three_way_match_tolerance_is_a_company_rule(): void
    {
        $this->makeTenant(['FINANZAS', 'COMPRAS', 'CONTABILIDAD']);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $approver = $this->makeUser('Gerencia', ['purchases.view', 'purchases.approve']);
        $supplier = $this->supplier();
        $center = $this->center('CC-OPS');
        $this->as($finance);
        $order = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier, 'cost_center_id' => $center, 'currency' => 'PEN', 'submit' => true,
            'items' => [['description' => 'Servicio', 'quantity' => 1, 'unit_cost' => 1000, 'account_code' => '6343']],
        ])->assertCreated()->json();
        $this->as($approver);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")->assertOk();
        $this->as($finance);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 100])->assertOk();
        // Facturado 1040 sobre 1000 recibidos: 4 % de diferencia.
        $invoice = $this->postJson('/api/v1/supplier-invoices', [
            'supplier_id' => $supplier, 'doc_type' => 'FACTURA', 'doc_number' => 'F001-90', 'issue_date' => now()->toDateString(), 'purchase_order_id' => $order['id'],
            'subtotal' => 1040, 'currency' => 'PEN', 'cost_center_id' => $center, 'expense_account' => '6343',
        ])->assertCreated()->json();
        $this->assertSame([false, 'Diferencia 4.0 % con la OC '.$order['order_number'].' (tolerancia 2 %)'], [$invoice['match']['ok'], $invoice['match']['label']]);

        // Solo quien gestiona la empresa cambia la regla; de 0 % a 10 %.
        $this->putJson('/api/v1/tenant/settings', ['match_tolerance' => 5])->assertStatus(403);
        $this->as($this->makeUser('Administrador', ['tenant.manage', ...self::FINANCE]));
        $this->putJson('/api/v1/tenant/settings', ['match_tolerance' => 11])->assertStatus(422)->assertJsonValidationErrors('match_tolerance');
        $this->putJson('/api/v1/tenant/settings', ['match_tolerance' => 5])->assertOk()->assertJsonPath('match_tolerance', 5);
        $this->assertTrue($this->getJson("/api/v1/supplier-invoices/{$invoice['id']}")->json('match.ok'));
        $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/approve")->assertOk();
        $this->assertSame(5, (int) json_decode(DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'tenant.settings.update')->value('after_data'), true)['match_tolerance']);
    }
}
