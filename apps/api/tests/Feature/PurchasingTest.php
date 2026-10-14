<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Compras y cuentas por pagar: la orden de compra se aprueba por otra persona y se recibe sin
 * pasar del 100 %; el comprobante del proveedor no se duplica y debe cuadrar con lo recibido;
 * al aprobarlo nace la cuenta por pagar, cuyo saldo sale de sus movimientos; el pago sale de una
 * cuenta con saldo y separa la detracción. Permisos, alcance y exportar.
 */
class PurchasingTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MODULES = ['FINANZAS'];

    private const BUYER = ['purchases.view', 'purchases.create', 'purchases.manage'];

    private const APPROVER = ['purchases.view', 'purchases.approve', 'purchases.export'];

    private const FINANCE = [
        'purchases.view', 'purchases.create', 'purchases.manage', 'budgets.manage',
        'payables.view', 'payables.create', 'payables.manage', 'payables.approve', 'payables.export',
        'treasury.view', 'treasury.create', 'treasury.manage',
    ];

    private function makeSupplier(string $name = 'Servicios Andinos SAC', bool $person = false): string
    {
        $partyId = (string) Str::uuid();
        DB::table('parties')->insert([
            'id' => $partyId, 'tenant_id' => $this->tenantId, 'party_type' => $person ? 'PERSON' : 'ORGANIZATION',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($person) {
            DB::table('persons')->insert(['party_id' => $partyId, 'given_names' => $name, 'family_name' => 'Quispe', 'document_type' => 'RUC', 'document_number' => (string) random_int(10100000000, 10999999999)]);
        } else {
            DB::table('organizations')->insert(['party_id' => $partyId, 'legal_name' => $name, 'tax_identifier' => (string) random_int(20100000000, 20999999999)]);
        }
        $id = (string) Str::uuid();
        DB::table('suppliers')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'party_id' => $partyId, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function makeCostCenter(string $code = 'CC-OPS'): string
    {
        $id = (string) Str::uuid();
        DB::table('cost_centers')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'code' => $code, 'name' => 'Operaciones', 'active' => true]);

        return $id;
    }

    private function orderPayload(string $supplierId, string $costCenterId, array $data = []): array
    {
        return [
            'supplier_id' => $supplierId, 'cost_center_id' => $costCenterId, 'currency' => 'PEN',
            'items' => [
                ['description' => 'Mantenimiento de grupo electrógeno', 'quantity' => 2, 'unit_cost' => 400, 'account_code' => '6343'],
                ['description' => 'Repuestos', 'quantity' => 1, 'unit_cost' => 200, 'account_code' => '6563'],
            ],
            ...$data,
        ];
    }

    /** Orden aprobada por otra persona y, si se pide, recibida en ese porcentaje. Queda actuando `$buyer`. */
    private function approvedOrder(User $buyer, User $approver, string $supplierId, string $costCenterId, float $received = 0, array $data = []): array
    {
        $this->as($buyer);
        $order = $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplierId, $costCenterId, ['submit' => true, ...$data]))->assertCreated()->json();
        $this->as($approver);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")->assertOk();
        $this->as($buyer);
        if ($received > 0) {
            $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => $received])->assertOk();
        }

        return $this->getJson("/api/v1/purchase-orders/{$order['id']}")->assertOk()->json();
    }

    private function invoicePayload(string $supplierId, string $costCenterId, array $data = []): array
    {
        return [
            'supplier_id' => $supplierId, 'doc_type' => 'FACTURA', 'doc_number' => 'F001-'.random_int(1, 99999999), 'issue_date' => now()->toDateString(),
            'subtotal' => 1000, 'currency' => 'PEN', 'cost_center_id' => $costCenterId, 'expense_account' => '6343', 'description' => 'Mantenimiento',
            ...$data,
        ];
    }

    private function bank(float $opening = 10000, array $data = []): array
    {
        return $this->postJson('/api/v1/bank-accounts', [
            'name' => 'BCP Soles', 'account_type' => 'BANK', 'currency' => 'PEN', 'bank_name' => 'BCP', 'account_number' => '193-'.random_int(1000000, 9999999),
            'opening_balance' => $opening, ...$data,
        ])->assertCreated()->json();
    }

    /** Cuenta por pagar de un comprobante registrado y aprobado, sin orden de compra. */
    private function payable(string $supplierId, string $costCenterId, array $data = []): array
    {
        $invoice = $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplierId, $costCenterId, $data))->assertCreated()->json();
        $invoice = $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/approve")->assertOk()->json();

        return $this->getJson("/api/v1/payables/{$invoice['payable']['id']}")->assertOk()->json();
    }

    public function test_a_purchase_order_is_drafted_edited_only_as_draft_and_approved_by_someone_else(): void
    {
        $this->makeTenant(self::MODULES);
        $supplier = $this->makeSupplier();
        $center = $this->makeCostCenter();
        $buyer = $this->makeUser('Compras', [...self::BUYER, 'purchases.approve']);
        $approver = $this->makeUser('Gerencia', self::APPROVER);
        $this->as($buyer);

        $this->postJson('/api/v1/purchase-orders', [])->assertStatus(422)->assertJsonValidationErrors(['supplier_id', 'cost_center_id', 'items']);
        $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplier, $center, ['items' => [['description' => 'X', 'quantity' => 0, 'unit_cost' => 10, 'account_code' => '6343']]]))
            ->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->postJson('/api/v1/purchase-orders', $this->orderPayload((string) Str::uuid(), $center))->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        DB::table('suppliers')->where('id', $supplier)->update(['status' => 'INACTIVE']);
        $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplier, $center))->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        DB::table('suppliers')->where('id', $supplier)->update(['status' => 'ACTIVE']);

        $order = $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplier, $center))->assertCreated()->json();
        $this->assertSame(['DRAFT', 'PEN', 2, 'Servicios Andinos SAC'], [$order['status'], $order['currency'], $order['items_count'], $order['supplier']['name']]);
        $this->assertMatchesRegularExpression('/^OC-\d{4}-0001$/', $order['order_number']);
        $this->assertEquals([1000, 180, 1180], [$order['subtotal'], $order['tax'], $order['total']]);
        $this->assertSame('6343 · Mantenimiento y reparaciones', $order['items'][0]['account']);
        $this->assertNull($order['budget']);

        // En borrador se edita; las líneas se reemplazan.
        $edited = $this->putJson("/api/v1/purchase-orders/{$order['id']}", $this->orderPayload($supplier, $center, [
            'items' => [['description' => 'Solo mantenimiento', 'quantity' => 1, 'unit_cost' => 500, 'account_code' => '6343']], 'notes' => 'Urgente',
        ]))->assertOk()->json();
        $this->assertEquals([500, 590, 1, 'Urgente'], [$edited['subtotal'], $edited['total'], $edited['items_count'], $edited['notes']]);

        $this->postJson("/api/v1/purchase-orders/{$order['id']}/submit")->assertOk()->assertJsonPath('status', 'SUBMITTED')->assertJsonPath('segregated', true);
        $this->putJson("/api/v1/purchase-orders/{$order['id']}", $this->orderPayload($supplier, $center))->assertStatus(422)->assertJsonValidationErrors('status');
        // Ni la base deja tocar una orden enviada ni sus líneas.
        $this->assertDatabaseRejects(fn () => DB::table('purchase_orders')->where('id', $order['id'])->update(['total' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('purchase_order_items')->where('purchase_order_id', $order['id'])->update(['unit_cost' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('purchase_order_items')->where('purchase_order_id', $order['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('purchase_orders')->where('id', $order['id'])->delete());

        // Quien la creó no la aprueba, aunque tenga el permiso.
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->as($approver);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/reject", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/reject", ['reason' => 'Falta una cotización'])->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('reason', 'Falta una cotización');

        // Rechazada, se corrige y vuelve a enviarse.
        $this->as($buyer);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/reopen")->assertOk()->assertJsonPath('status', 'DRAFT')->assertJsonPath('reason', null);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/submit")->assertOk();
        $this->as($approver);
        $approved = $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")->assertOk()->json();
        $this->assertSame(['APPROVED', 'Gerencia', false], [$approved['status'], $approved['approver'], $approved['segregated']]);
        // Todo ocurre en el mismo segundo: se compara sin orden.
        $this->assertEqualsCanonicalizing(
            ['purchase_order.approve', 'purchase_order.submit', 'purchase_order.reopen', 'purchase_order.reject', 'purchase_order.submit', 'purchase_order.update', 'purchase_order.create'],
            array_column($approved['history'], 'action'),
        );
        foreach (array_column($approved['history'], 'action') as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }

        // Con la segregación desactivada, la empresa permite que apruebe quien la creó.
        DB::table('tenants')->where('id', $this->tenantId)->update(['configuration' => json_encode(['segregation' => false])]);
        $this->as($buyer);
        $own = $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplier, $center, ['submit' => true]))->assertCreated()->assertJsonPath('status', 'SUBMITTED')->json();
        $this->postJson("/api/v1/purchase-orders/{$own['id']}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');
    }

    public function test_reception_never_passes_100_percent_and_closing_needs_a_receipt(): void
    {
        $this->makeTenant(self::MODULES);
        $supplier = $this->makeSupplier();
        $center = $this->makeCostCenter();
        $buyer = $this->makeUser('Compras', self::BUYER);
        $approver = $this->makeUser('Gerencia', self::APPROVER);

        $this->as($buyer);
        $draft = $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplier, $center))->assertCreated()->json();
        $this->postJson("/api/v1/purchase-orders/{$draft['id']}/receipts", ['pct' => 50])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/purchase-orders/{$draft['id']}/close")->assertStatus(422);
        $this->postJson("/api/v1/purchase-orders/{$draft['id']}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/purchase-orders/{$draft['id']}/cancel", ['reason' => 'Ya no se necesita'])->assertOk()->assertJsonPath('status', 'CANCELLED');

        $order = $this->approvedOrder($buyer, $approver, $supplier, $center);
        // Sin recepción no se cierra.
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/close")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 0])->assertStatus(422)->assertJsonValidationErrors('pct');

        $partial = $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 60, 'note' => 'Guía 001-123'])->assertOk()->json();
        $this->assertSame(['PARTIALLY_RECEIVED', 1, 'Guía 001-123', 'Compras'], [$partial['status'], count($partial['receipts']), $partial['receipts'][0]['note'], $partial['receipts'][0]['by']]);
        $this->assertEquals(60, $partial['received_pct']);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 41])->assertStatus(422)->assertJsonValidationErrors('pct');
        // Tampoco la base: ni pasar del 100 % ni borrar una conformidad.
        $this->assertDatabaseRejects(fn () => DB::table('purchase_receipts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'purchase_order_id' => $order['id'], 'received_pct' => 41]));
        $this->assertDatabaseRejects(fn () => DB::table('purchase_receipts')->where('purchase_order_id', $order['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('purchase_receipts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'purchase_order_id' => $draft['id'], 'received_pct' => 10]));

        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 40])->assertOk()->assertJsonPath('status', 'RECEIVED');
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 1])->assertStatus(422)->assertJsonValidationErrors('status');
        // Recibida ya no se anula; se cierra.
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/cancel", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/close")->assertOk()->assertJsonPath('status', 'CLOSED');

        $summary = $this->getJson('/api/v1/purchase-orders/summary')->assertOk()->json();
        $this->assertSame(['ALL' => 2, 'DRAFT' => 0, 'SUBMITTED' => 0, 'RECEIVE' => 0, 'RECEIVED' => 0, 'CLOSED' => 1], $summary['tabs']);
        $this->assertSame(1, $this->getJson('/api/v1/purchase-orders?tab=CLOSED')->assertOk()->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/purchase-orders?search=electr%C3%B3geno&tab=CLOSED')->assertOk()->json('total'));
        $this->assertSame(0, $this->getJson('/api/v1/purchase-orders?search=nada-de-esto')->assertOk()->json('total'));
    }

    public function test_a_supplier_invoice_is_not_duplicated_and_must_match_what_was_received(): void
    {
        $this->makeTenant(self::MODULES);
        $supplier = $this->makeSupplier();
        $other = $this->makeSupplier('Otro Proveedor SAC');
        $center = $this->makeCostCenter();
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $approver = $this->makeUser('Gerencia', self::APPROVER);
        $order = $this->approvedOrder($finance, $approver, $supplier, $center);

        $this->postJson('/api/v1/supplier-invoices', [])->assertStatus(422)->assertJsonValidationErrors(['supplier_id', 'doc_number', 'issue_date', 'subtotal', 'cost_center_id', 'expense_account']);
        $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['doc_number' => '123']))->assertStatus(422)->assertJsonValidationErrors('doc_number');
        $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['issue_date' => now()->addDay()->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('issue_date');
        // La orden debe ser del mismo proveedor y moneda.
        $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($other, $center, ['purchase_order_id' => $order['id']]))->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');

        $invoice = $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['doc_number' => 'f001-88', 'purchase_order_id' => $order['id']]))->assertCreated()->json();
        $this->assertSame(['REGISTERED', 'F001-88', 'NOT_CHECKED', $order['order_number']], [$invoice['status'], $invoice['doc_number'], $invoice['sunat_validation'], $invoice['purchase_order']['order_number']]);
        $this->assertEquals([1000, 180, 1180, now()->addDays(30)->toDateString()], [$invoice['subtotal'], $invoice['tax'], $invoice['total'], $invoice['due_date']]);
        $this->assertNull($invoice['detraction']);
        $this->assertNull($invoice['payable']);

        // No entra dos veces, ni por la API ni por la base.
        $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['doc_number' => 'F001-88']))->assertStatus(422)->assertJsonValidationErrors('doc_number');
        $this->assertDatabaseRejects(fn () => DB::table('supplier_invoices')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'supplier_id' => $supplier, 'supplier_name' => 'X', 'doc_type' => 'FACTURA', 'doc_number' => 'F001-88',
            'issue_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'cost_center_id' => $center, 'expense_account' => '6343', 'subtotal' => 1, 'total' => 1,
        ]));
        // El mismo número de otro proveedor sí.
        $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($other, $center, ['doc_number' => 'F001-88']))->assertCreated();

        // Lo facturado es un dato de la orden.
        $this->assertEquals(1180, $this->getJson("/api/v1/purchase-orders/{$order['id']}")->json('invoiced'));

        // Sin recepción no cuadra: no se aprueba.
        $this->assertSame([false, 'La OC no tiene recepción registrada'], [$invoice['match']['ok'], $invoice['match']['label']]);
        $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/approve")->assertStatus(422)->assertJsonValidationErrors('status');

        // Recibido el 50 %: se esperan 500; 1000 no cuadra.
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 50])->assertOk();
        $match = $this->getJson("/api/v1/supplier-invoices/{$invoice['id']}")->json('match');
        $this->assertFalse($match['ok']);
        $this->assertStringContainsString('Diferencia 100.0 %', $match['label']);
        $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/approve")->assertStatus(422);

        // Recibido todo: cuadra y se aprueba; nace la cuenta por pagar.
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 50])->assertOk();
        // Con un comprobante registrado la orden no se anula... ni aplica: ya está recibida.
        $approved = $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/approve")->assertOk()->json();
        $this->assertSame(['APPROVED', true, 'OPEN', 'Finanzas'], [$approved['status'], $approved['match']['ok'], $approved['payable']['status'], $approved['approver']]);
        $this->assertEquals(1180, $approved['payable']['balance_amount']);

        // Aprobado no cambia de estado ni de importes, ni por la base.
        $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/void", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/approve")->assertStatus(422);
        $this->assertDatabaseRejects(fn () => DB::table('supplier_invoices')->where('id', $invoice['id'])->update(['total' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('supplier_invoices')->where('id', $invoice['id'])->update(['status' => 'REGISTERED']));
        $this->assertDatabaseRejects(fn () => DB::table('supplier_invoices')->where('id', $invoice['id'])->delete());
        // Una sola cuenta por comprobante.
        $this->assertDatabaseRejects(fn () => DB::table('payables')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'supplier_invoice_id' => $invoice['id'], 'supplier_id' => $supplier,
            'due_date' => now()->toDateString(), 'original_amount' => 10,
        ]));

        // Otro comprobante de la misma orden ya no cuadra: lo aprobado cubre lo recibido.
        $extra = $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['purchase_order_id' => $order['id'], 'subtotal' => 100]))->assertCreated()->json();
        $this->postJson("/api/v1/supplier-invoices/{$extra['id']}/approve")->assertStatus(422);
        $this->postJson("/api/v1/supplier-invoices/{$extra['id']}/reject", ['reason' => 'Facturado de más'])->assertOk()->assertJsonPath('status', 'REJECTED');
        // Anulado, deja de contar en la orden y su número queda libre.
        $this->postJson("/api/v1/supplier-invoices/{$extra['id']}/void", ['reason' => 'Error del proveedor'])->assertOk()->assertJsonPath('status', 'VOIDED');
        $this->assertEquals(1180, $this->getJson("/api/v1/purchase-orders/{$order['id']}")->json('invoiced'));
        $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['doc_number' => $extra['doc_number']]))->assertCreated();

        // La ficha de la orden trae sus comprobantes con el cruce.
        $detail = $this->getJson("/api/v1/purchase-orders/{$order['id']}")->assertOk()->json();
        $this->assertCount(2, $detail['supplier_invoices']);
        $this->assertArrayHasKey('match', $detail['supplier_invoices'][0]);

        $tabs = $this->getJson('/api/v1/supplier-invoices/summary')->assertOk()->json('tabs');
        $this->assertSame(['ALL' => 4, 'REGISTERED' => 2, 'APPROVED' => 1, 'REJECTED' => 0], $tabs);
        $this->assertSame(1, $this->getJson('/api/v1/supplier-invoices?tab=APPROVED')->assertOk()->json('total'));
        foreach (array_column($this->getJson("/api/v1/supplier-invoices/{$extra['id']}")->json('history'), 'action') as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }
    }

    public function test_detraction_and_fourth_category_retention_are_computed_when_registering(): void
    {
        $this->makeTenant(self::MODULES);
        $supplier = $this->makeSupplier();
        $person = $this->makeSupplier('Rosa', true);
        $center = $this->makeCostCenter();
        $this->as($this->makeUser('Finanzas', self::FINANCE));

        // Factura por más de S/ 700 con código del Anexo 3: 12 % del total, en soles enteros.
        $invoice = $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['detraction_code' => '020']))->assertCreated()->json();
        $this->assertEquals(['020', 12, 142], [$invoice['detraction']['code'], $invoice['detraction']['pct'], $invoice['detraction']['amount']]);
        // Por S/ 700 o menos no hay detracción.
        $small = $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['subtotal' => 500, 'detraction_code' => '020']))->assertCreated()->json();
        $this->assertNull($small['detraction']);
        $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['detraction_code' => '999']))->assertStatus(422)->assertJsonValidationErrors('detraction_code');

        // Recibo por honorarios: sin IGV; por más de S/ 1500 se retiene el 8 %.
        $fee = $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($person, $center, ['doc_type' => 'RECIBO_HONORARIOS', 'doc_number' => 'E001-15', 'subtotal' => 2000, 'detraction_code' => '020']))->assertCreated()->json();
        $this->assertEquals([0, 2000, 160], [$fee['tax'], $fee['total'], $fee['retention_4ta']]);
        $this->assertNull($fee['detraction']);
        $this->assertSame('Rosa Quispe', $fee['supplier']['name']);
        $lowFee = $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($person, $center, ['doc_type' => 'RECIBO_HONORARIOS', 'doc_number' => 'E001-16', 'subtotal' => 1500]))->assertCreated()->json();
        $this->assertEquals(0, $lowFee['retention_4ta']);

        // Lo retenido no se le paga al proveedor: la cuenta nace con el saldo neto.
        $approved = $this->postJson("/api/v1/supplier-invoices/{$fee['id']}/approve")->assertOk()->json();
        $payable = $this->getJson("/api/v1/payables/{$approved['payable']['id']}")->assertOk()->json();
        $this->assertEquals([2000, 1840, 'PARTIAL'], [$payable['original_amount'], $payable['balance_amount'], $payable['status']]);
        $this->assertSame(['CHARGE', 'RETENTION_4TA'], array_column($payable['movements'], 'movement_type'));

        // En dólares hace falta el tipo de cambio; con él, la detracción se calcula en soles.
        $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['currency' => 'USD']))->assertStatus(422)->assertJsonValidationErrors('currency');
        DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'rate_date' => now()->subDay()->toDateString(), 'currency' => 'USD', 'buy' => 3.7, 'sell' => 3.75]);
        $usd = $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center, ['currency' => 'USD', 'detraction_code' => '037']))->assertCreated()->json();
        // 1180 USD × 3.75 = 4425 → 12 % = 531.
        $this->assertEquals([3.75, 531], [$usd['exchange_rate'], $usd['detraction']['amount']]);
    }

    public function test_paying_suppliers_takes_the_money_from_an_account_and_separates_the_detraction(): void
    {
        $this->makeTenant(self::MODULES);
        $supplier = $this->makeSupplier();
        $other = $this->makeSupplier('Otro Proveedor SAC');
        $center = $this->makeCostCenter();
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $bank = $this->bank(3000);
        $dollars = $this->bank(5000, ['name' => 'BCP Dólares', 'currency' => 'USD']);

        $first = $this->payable($supplier, $center, ['detraction_code' => '020']);   // 1180, detracción 142
        $second = $this->payable($supplier, $center, ['subtotal' => 500]);           // 590
        $foreign = $this->payable($other, $center);
        $this->assertEquals([1180, 1180, 142, false, 'OPEN'], [$first['original_amount'], $first['balance_amount'], $first['detraction_amount'], $first['detraction_deposited'], $first['status']]);

        $pay = fn (array $data) => $this->postJson('/api/v1/supplier-payments', [
            'payable_ids' => [$first['id'], $second['id']], 'financial_account_id' => $bank['id'], 'payment_method' => 'TRANSFERENCIA', 'reference' => 'OP-778', ...$data,
        ]);

        $pay(['payable_ids' => []])->assertStatus(422)->assertJsonValidationErrors('payable_ids');
        $pay(['payable_ids' => [$first['id'], $foreign['id']]])->assertStatus(422)->assertJsonValidationErrors('payable_ids');
        $pay(['payable_ids' => [(string) Str::uuid()]])->assertStatus(422)->assertJsonValidationErrors('payable_ids');
        $pay(['reference' => ''])->assertStatus(422)->assertJsonValidationErrors('reference');
        $pay(['financial_account_id' => $dollars['id']])->assertStatus(422)->assertJsonValidationErrors('financial_account_id');
        // Un depósito de detracción lleva su constancia.
        $pay(['deposit_detraction' => true])->assertStatus(422)->assertJsonValidationErrors('detraction_number');

        // Sin incluir la detracción: sale solo el neto y la primera cuenta queda con la detracción por depositar.
        $payment = $pay([])->assertCreated()->json();
        $this->assertMatchesRegularExpression('/^PP-\d{4}-0001$/', $payment['payment_number']);
        $this->assertEquals([1628, 0, 'BCP Soles', 'PAID'], [$payment['amount'], $payment['detraction_amount'], $payment['account_name'], $payment['status']]);
        $afterFirst = $this->getJson("/api/v1/payables/{$first['id']}")->json();
        $this->assertEquals([142, 'PARTIAL', false], [$afterFirst['balance_amount'], $afterFirst['status'], $afterFirst['detraction_deposited']]);
        $this->assertEquals([0, 'PAID'], [$this->getJson("/api/v1/payables/{$second['id']}")->json('balance_amount'), $this->getJson("/api/v1/payables/{$second['id']}")->json('status')]);
        $this->assertEquals(3000 - 1628, (float) DB::table('bank_movements')->where('account_id', $bank['id'])->sum('amount'));
        $movement = DB::table('bank_movements')->where('ref_id', $payment['id'])->first();
        $this->assertSame(['SUPPLIER_PAYMENT', 'OP-778', false], [$movement->ref_type, $movement->op_number, $movement->reconciled]);
        $this->assertStringContainsString('Servicios Andinos SAC', $movement->description);

        // Lo ya pagado no se paga otra vez.
        $pay(['payable_ids' => [$second['id']]])->assertStatus(422)->assertJsonValidationErrors('payable_ids');
        // De la primera solo queda la detracción: hay que incluir su depósito.
        $pay(['payable_ids' => [$first['id']]])->assertStatus(422)->assertJsonValidationErrors('deposit_detraction');
        $deposit = $pay(['payable_ids' => [$first['id']], 'deposit_detraction' => true, 'detraction_number' => '12345678'])->assertCreated()->json();
        $this->assertEquals([0, 142, '12345678'], [$deposit['amount'], $deposit['detraction_amount'], $deposit['detraction_number']]);
        $paid = $this->getJson("/api/v1/payables/{$first['id']}")->json();
        $this->assertEquals([0, 'PAID', true], [$paid['balance_amount'], $paid['status'], $paid['detraction_deposited']]);
        $this->assertSame(['CHARGE', 'PAYMENT', 'DETRACTION'], array_column($paid['movements'], 'movement_type'));
        // La ficha del comprobante trae los mismos movimientos de su cuenta por pagar.
        $this->assertSame(['CHARGE', 'PAYMENT', 'DETRACTION'], array_column($this->getJson("/api/v1/supplier-invoices/{$first['supplier_invoice']['id']}")->json('payable.movements'), 'movement_type'));
        $this->assertEquals(3000 - 1628 - 142, (float) DB::table('bank_movements')->where('account_id', $bank['id'])->sum('amount'));

        // De una vez: neto y detracción, con saldo suficiente para los dos.
        $third = $this->payable($supplier, $center, ['subtotal' => 2000, 'detraction_code' => '037']);   // 2360, detracción 283
        $pay(['payable_ids' => [$third['id']], 'deposit_detraction' => true, 'detraction_number' => '555'])->assertStatus(422)->assertJsonValidationErrors('financial_account_id');
        $this->postJson("/api/v1/bank-accounts/{$bank['id']}/refill", [])->assertStatus(422);
        $extra = $this->bank(5000, ['name' => 'BBVA Soles']);
        $both = $pay(['payable_ids' => [$third['id']], 'financial_account_id' => $extra['id'], 'deposit_detraction' => true, 'detraction_number' => '555'])->assertCreated()->json();
        $this->assertEquals([2077, 283], [$both['amount'], $both['detraction_amount']]);
        $this->assertEquals(5000 - 2360, (float) DB::table('bank_movements')->where('account_id', $extra['id'])->sum('amount'));
        $this->assertSame(2, DB::table('bank_movements')->where('ref_id', $both['id'])->count());

        // Ni el saldo de la cuenta por pagar ni un pago se tocan a mano.
        $this->assertDatabaseRejects(fn () => DB::table('payables')->where('id', $foreign['id'])->update(['balance_amount' => 0]));
        $this->assertDatabaseRejects(fn () => DB::table('payables')->where('id', $first['id'])->update(['detraction_deposited' => false]));
        $this->assertDatabaseRejects(fn () => DB::table('payable_movements')->where('payable_id', $first['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('supplier_payments')->where('id', $payment['id'])->update(['amount' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('supplier_payments')->where('id', $payment['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('payable_movements')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'payable_id' => $foreign['id'], 'movement_type' => 'PAYMENT', 'amount' => -99999,
        ]));

        $this->assertSame(3, $this->getJson('/api/v1/supplier-payments')->assertOk()->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/payables?status=OPEN')->assertOk()->json('total'));
        $this->assertSame(3, $this->getJson('/api/v1/payables?status=PAID')->assertOk()->json('total'));
        $this->assertSame(0, $this->getJson('/api/v1/payables?status=OVERDUE')->assertOk()->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/payables?search=Otro')->assertOk()->json('total'));

        $summary = $this->getJson('/api/v1/payables/summary')->assertOk()->json();
        $this->assertEquals([1180, 1, 0, 0, 0], [$summary['outstanding'], $summary['open_count'], $summary['overdue'], $summary['due_soon'], $summary['detraction_pending']]);
        $this->assertEquals(1180, collect($summary['buckets'])->firstWhere('key', 'LATER')['amount']);
        $this->assertCount(3, $summary['accounts']);

        // El pago queda en el historial del comprobante.
        $invoiceId = $first['supplier_invoice']['id'];
        $this->assertContains('supplier_invoice.payment', array_column($this->getJson("/api/v1/supplier-invoices/{$invoiceId}")->json('history'), 'action'));
    }

    public function test_the_supplier_detraction_account_is_kept_with_the_supplier_role_and_shown_when_paying(): void
    {
        $this->makeTenant(self::MODULES);
        $supplier = $this->makeSupplier();
        $center = $this->makeCostCenter();
        $partyId = DB::table('suppliers')->where('id', $supplier)->value('party_id');
        $customer = $this->makeCustomer();
        $customerParty = DB::table('customers')->where('id', $customer)->value('party_id');

        $this->as($this->makeUser('Solo ve actores', ['core.view']));
        $this->putJson("/api/v1/parties/{$partyId}/supplier", ['detraction_account' => '00-123-456789'])->assertStatus(403);

        $this->as($this->makeUser('Gestiona actores', ['core.view', 'core.manage', ...self::FINANCE]));
        $this->putJson("/api/v1/parties/{$partyId}/supplier", ['detraction_account' => '123'])->assertStatus(422)->assertJsonValidationErrors('detraction_account');
        // Sin el rol Proveedor no hay dónde guardarla.
        $this->putJson("/api/v1/parties/{$customerParty}/supplier", ['detraction_account' => '00-123-456789'])->assertStatus(404);
        $this->putJson("/api/v1/parties/{$partyId}/supplier", ['detraction_account' => '00-123-456789'])->assertOk()->assertJsonPath('supplier.detraction_account', '00-123-456789');
        $this->assertContains('party.supplier.update', DB::table('audit_logs')->where('entity_id', $partyId)->pluck('action')->all());
        $this->assertArrayHasKey('party.supplier.update', AuditCatalog::ACTIONS);

        $payable = $this->payable($supplier, $center, ['detraction_code' => '020']);
        $this->assertSame('00-123-456789', $payable['supplier']['detraction_account']);
        $this->assertSame('00-123-456789', collect($this->getJson('/api/v1/supplier-invoices/options')->json('suppliers'))->firstWhere('id', $supplier)['detraction_account']);
    }

    public function test_permissions_scope_and_export_are_checked_by_the_api(): void
    {
        $this->makeTenant(self::MODULES);
        $supplier = $this->makeSupplier();
        $center = $this->makeCostCenter();
        $north = $this->makeBranch('Norte');
        $south = $this->makeBranch('Sur');
        $buyer = $this->makeUser('Compras norte', self::BUYER, 'BRANCH', $north);
        $peer = $this->makeUser('Otro del norte', self::BUYER, 'BRANCH', $north);
        $southern = $this->makeUser('Compras sur', self::BUYER, 'BRANCH', $south);
        $own = $this->makeUser('Solo lo suyo', self::BUYER, null);
        $creator = $this->makeUser('Solo crea', ['purchases.view', 'purchases.create']);
        $approver = $this->makeUser('Gerencia', self::APPROVER);
        $viewer = $this->makeUser('Solo ve', ['payables.view']);
        $nobody = $this->makeUser('Sin permisos', ['core.view']);

        $this->as($buyer);
        $order = $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplier, $center, ['submit' => true]))->assertCreated()->json();

        // Sin permiso: 403.
        $this->as($nobody);
        $this->getJson('/api/v1/purchase-orders')->assertStatus(403);
        $this->getJson('/api/v1/supplier-invoices')->assertStatus(403);
        $this->getJson('/api/v1/payables')->assertStatus(403);
        $this->getJson('/api/v1/supplier-payments')->assertStatus(403);
        $this->postJson('/api/v1/cost-centers', ['code' => 'X', 'name' => 'X'])->assertStatus(403);

        // Quien pide no aprueba; quien aprueba no pide ni recibe.
        $this->as($buyer);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")->assertStatus(403);
        $this->postJson('/api/v1/cost-centers', ['code' => 'X', 'name' => 'X'])->assertStatus(403);
        $this->getJson('/api/v1/purchase-orders?export=1')->assertStatus(403);
        $this->postJson('/api/v1/exports', ['resource' => 'purchases', 'format' => 'xlsx', 'rows' => 1])->assertStatus(403);
        $this->as($approver);
        $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplier, $center))->assertStatus(403);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 10])->assertStatus(403);
        $this->getJson('/api/v1/purchase-orders?export=1')->assertOk();
        $this->postJson('/api/v1/exports', ['resource' => 'purchases', 'format' => 'xlsx', 'rows' => 1])->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")->assertOk();
        // Sin permiso de cuentas por pagar, la ficha de la orden no entrega sus comprobantes.
        $this->assertNull($this->getJson("/api/v1/purchase-orders/{$order['id']}")->json('supplier_invoices'));

        // Quien solo crea deja la orden en borrador: enviarla es gestionarla.
        $this->as($creator);
        $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplier, $center, ['submit' => true]))->assertStatus(403);
        $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplier, $center))->assertCreated()->assertJsonPath('status', 'DRAFT');

        // Alcance: la sede ve lo de su sede; otra sede y "lo propio" de otra persona, 404.
        $this->as($peer);
        $this->getJson("/api/v1/purchase-orders/{$order['id']}")->assertOk();
        $this->assertSame(1, $this->getJson('/api/v1/purchase-orders')->json('total'));
        $this->as($southern);
        $this->getJson("/api/v1/purchase-orders/{$order['id']}")->assertStatus(404);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", ['pct' => 10])->assertStatus(404);
        $this->assertSame(0, $this->getJson('/api/v1/purchase-orders')->json('total'));
        $this->assertSame(0, $this->getJson('/api/v1/purchase-orders/summary')->json('tabs.ALL'));
        $this->as($own);
        $this->getJson("/api/v1/purchase-orders/{$order['id']}")->assertStatus(404);

        // Cuentas por pagar: ver no es registrar, aprobar ni pagar.
        $this->as($viewer);
        $this->getJson('/api/v1/payables/summary')->assertOk()->assertJsonPath('accounts', null);
        $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center))->assertStatus(403);
        $this->postJson('/api/v1/supplier-payments', [])->assertStatus(403);
        $this->getJson('/api/v1/supplier-payments/options')->assertStatus(403);
        $this->getJson('/api/v1/payables?export=1')->assertStatus(403);
        $this->getJson('/api/v1/supplier-invoices?export=1')->assertStatus(403);
        $this->getJson('/api/v1/supplier-payments?export=1')->assertStatus(403);

        // Centros de costo: código único por empresa.
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $this->postJson('/api/v1/cost-centers', ['code' => 'cc-adm', 'name' => 'Administración'])->assertCreated()->assertJsonPath('code', 'CC-ADM');
        $this->postJson('/api/v1/cost-centers', ['code' => 'CC-ADM', 'name' => 'Otra'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertCount(2, $this->getJson('/api/v1/purchase-orders/options')->assertOk()->json('cost_centers'));
        $this->assertDatabaseRejects(fn () => DB::table('cost_centers')->where('id', $center)->delete());
        $invoice = $this->postJson('/api/v1/supplier-invoices', $this->invoicePayload($supplier, $center))->assertCreated()->json();
        $this->getJson('/api/v1/supplier-invoices?export=1')->assertOk();

        // Otra empresa: no existe.
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas de otra empresa', [...self::FINANCE, 'purchases.approve']));
        $this->getJson("/api/v1/purchase-orders/{$order['id']}")->assertStatus(404);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")->assertStatus(404);
        $this->getJson("/api/v1/supplier-invoices/{$invoice['id']}")->assertStatus(404);
        $this->postJson("/api/v1/supplier-invoices/{$invoice['id']}/approve")->assertStatus(404);
        $this->postJson('/api/v1/purchase-orders', $this->orderPayload($supplier, $center))->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        $this->assertSame(0, $this->getJson('/api/v1/supplier-invoices')->json('total'));
    }
}
