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
 * Finanzas y CxC: eventos facturables (uno por origen), comprobantes (correlativo sin repetir,
 * inmutables al emitirse, nota de crédito), cuentas por cobrar (saldo por movimientos) y cobros
 * (aplicación, conciliación, reembolso); permisos, exportar y alcance.
 */
class FinanceTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MODULES = ['ORDENES_TRABAJO', 'CONTRATOS', 'FINANZAS', 'COMPRAS', 'CONTABILIDAD'];

    /** Finanzas: prepara, emite, cobra, concilia, reembolsa y exporta. */
    private const FINANCE = [
        'billing.view', 'billing.create', 'billing.manage', 'billing.approve', 'billing.export',
        'receivables.view', 'receivables.manage', 'receivables.export',
        'payments.view', 'payments.create', 'payments.manage', 'payments.approve', 'payments.export',
        'contracts.view',
    ];

    /** Quien prepara borradores y registra cobros, sin emitir, conciliar, reembolsar ni exportar. */
    private const CLERK = ['billing.view', 'billing.create', 'receivables.view', 'payments.view', 'payments.create'];

    private const OPERATIONS = ['work_orders.view', 'work_orders.create', 'work_orders.manage', 'work_orders.approve', 'contracted_services.view'];

    private function event(string $customerId, float $base = 1000, array $data = []): array
    {
        return $this->postJson('/api/v1/billable-events', [
            'customer_id' => $customerId, 'description' => 'Servicio de consultoría', 'base' => $base, 'currency' => 'PEN', ...$data,
        ])->assertCreated()->json();
    }

    private function draft(array $eventIds, string $type = 'INVOICE'): array
    {
        return $this->postJson('/api/v1/invoices/from-events', ['event_ids' => $eventIds, 'type' => $type])->assertCreated()->json();
    }

    /** Comprobante emitido por ese importe sin IGV. */
    private function issued(string $customerId, float $base = 1000, array $data = []): array
    {
        $draft = $this->draft([$this->event($customerId, $base, $data)['id']]);

        return $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertOk()->json();
    }

    private function pay(string $customerId, float $amount, array $data = []): array
    {
        return $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId, 'amount' => $amount, 'currency' => 'PEN', 'payment_method' => 'TRANSFERENCIA',
            'reference' => 'OP-'.Str::random(6), ...$data,
        ])->assertCreated()->json();
    }

    private function balance(string $receivableId): array
    {
        $row = DB::table('receivables')->where('id', $receivableId)->first(['balance_amount', 'status']);

        return [(float) $row->balance_amount, $row->status];
    }

    // ------------------------------------------------------------------ Eventos facturables

    public function test_completing_a_per_event_work_order_leaves_a_single_billable_event(): void
    {
        $this->makeTenant(self::MODULES);
        $operations = $this->makeUser('Operaciones', self::OPERATIONS);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $seller = $this->makeUser('Vendedora', ['contracts.view']);
        $branch = $this->makeBranch('Lima');
        $perEvent = $this->makeContracted(['branch_id' => $branch], seller: $seller);
        $recurring = $this->makeContracted(['service_id' => $this->makeService('Soporte mensual', true), 'recurring' => true]);

        $complete = function (string $contractedId) use ($operations) {
            $order = $this->order($operations, $contractedId);
            $this->postJson("/api/v1/work-orders/{$order['id']}/assign", ['assigned_user_id' => $operations->id])->assertOk();
            $this->postJson("/api/v1/work-orders/{$order['id']}/start")->assertOk();

            return $this->postJson("/api/v1/work-orders/{$order['id']}/complete")->assertOk()->json();
        };

        $done = $complete($perEvent);
        $this->assertSame('PENDING', $done['billable_event']['status']);
        // Precio pactado 380 sin IGV.
        $this->assertEquals(448.40, $done['billable_event']['amount']);
        // Un servicio recurrente no factura por orden: lo hará su ciclo.
        $this->assertNull($complete($recurring)['billable_event']);

        $this->as($finance);
        $list = $this->getJson('/api/v1/billable-events')->assertOk()->assertJsonPath('total', 1)->json('data.0');
        $this->assertSame(['WORK_ORDER', "wo:{$done['id']}", 'PENDING', 'PEN'], [$list['source_type'], $list['idempotency_key'], $list['status'], $list['currency']]);
        $this->assertEquals([380, 448.40], [$list['base'], $list['amount']]);
        $event = DB::table('billable_events')->where('id', $list['id'])->first();
        // Hereda la sede de la orden y, por alcance, es de quien vendió el contrato.
        $this->assertSame([$branch, $seller->id], [$event->branch_id, $event->owner_user_id]);

        // La base no admite un segundo evento para la misma orden.
        $this->assertDatabaseRejects(fn () => DB::table('billable_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'source_type' => 'WORK_ORDER', 'source_id' => $done['id'],
            'customer_id' => $event->customer_id, 'description' => 'Duplicado', 'base' => 380, 'amount' => 448.40, 'idempotency_key' => 'otra',
        ]));
    }

    public function test_manual_event_is_validated_and_cancelling_needs_a_reason(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        [$contractId, $customerId] = $this->makeContract();
        $other = $this->makeCustomer('Otra Empresa SAC');
        $this->as($finance);

        $this->postJson('/api/v1/billable-events', [])->assertStatus(422)->assertJsonValidationErrors(['customer_id', 'description', 'base', 'currency']);
        $this->postJson('/api/v1/billable-events', ['customer_id' => (string) Str::uuid(), 'description' => 'X', 'base' => 10, 'currency' => 'PEN'])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/v1/billable-events', ['customer_id' => $customerId, 'description' => 'X', 'base' => 0, 'currency' => 'PEN'])
            ->assertStatus(422)->assertJsonValidationErrors('base');
        // El contrato debe ser de ese cliente.
        $this->postJson('/api/v1/billable-events', ['customer_id' => $other, 'contract_id' => $contractId, 'description' => 'X', 'base' => 10, 'currency' => 'PEN'])
            ->assertStatus(422)->assertJsonValidationErrors('contract_id');

        $event = $this->event($customerId, 1000, ['contract_id' => $contractId]);
        $this->assertSame(['MANUAL', 'PENDING'], [$event['source_type'], $event['status']]);
        $this->assertEquals([1000, 1180], [$event['base'], $event['amount']]);
        $this->assertSame($contractId, $event['contract']['id']);

        $this->postJson("/api/v1/billable-events/{$event['id']}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/billable-events/{$event['id']}/cancel", ['reason' => 'Registrado por error'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->postJson("/api/v1/billable-events/{$event['id']}/cancel", ['reason' => 'Otra vez'])->assertStatus(422)->assertJsonValidationErrors('status');
        // Un evento anulado no se factura.
        $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [$event['id']], 'type' => 'INVOICE'])->assertStatus(422);

        // Buscar, filtrar y ordenar abarcan todo el listado.
        $this->event($customerId, 50, ['description' => 'Capacitación']);
        $this->event($other, 300, ['description' => 'Auditoría']);
        $this->getJson('/api/v1/billable-events?status=PENDING')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/billable-events?source=WORK_ORDER')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/billable-events?search=otra empresa')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.description', 'Auditoría');
        $this->getJson('/api/v1/billable-events?sort=base&dir=desc')->assertOk()->assertJsonPath('data.0.description', 'Servicio de consultoría');
        $filters = json_encode([['id' => 'base', 'type' => 'number', 'operator' => 'less', 'value' => '100']]);
        $this->getJson('/api/v1/billable-events?filters='.urlencode($filters))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.description', 'Capacitación');
    }

    // ------------------------------------------------------------------ Comprobantes

    public function test_draft_groups_pending_events_of_one_customer_and_an_event_is_not_invoiced_twice(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer('Otra Empresa SAC');
        $this->as($finance);
        DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'rate_date' => now()->toDateString(), 'currency' => 'USD', 'buy' => 3.70, 'sell' => 3.75]);

        $first = $this->event($customer, 1000);
        $second = $this->event($customer, 250.50, ['description' => 'Horas adicionales']);
        $foreign = $this->event($other, 100);
        $dollars = $this->event($customer, 100, ['currency' => 'USD']);

        $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [], 'type' => 'INVOICE'])->assertStatus(422)->assertJsonValidationErrors('event_ids');
        $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [$first['id'], $foreign['id']], 'type' => 'INVOICE'])
            ->assertStatus(422)->assertJsonValidationErrors('event_ids');
        $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [$first['id'], $dollars['id']], 'type' => 'INVOICE'])
            ->assertStatus(422)->assertJsonValidationErrors('event_ids');
        $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [$first['id'], (string) Str::uuid()], 'type' => 'INVOICE'])->assertStatus(422);

        $draft = $this->draft([$first['id'], $second['id']]);
        $this->assertSame(['DRAFT', 'INVOICE', 'F001', null, null, 'NOT_SENT'], [
            $draft['status'], $draft['invoice_type'], $draft['series'], $draft['invoice_number'], $draft['issue_date'], $draft['fiscal_status'],
        ]);
        $this->assertEquals([1250.50, 225.09, 1475.59], [$draft['subtotal'], $draft['tax_total'], $draft['total']]);
        $this->assertCount(2, $draft['items']);
        $this->assertSame($first['id'], $draft['items'][0]['billable_event_id']);
        $this->assertNull($draft['receivable']);
        // Vence a los 30 días si el cliente no tiene sus propios días de crédito.
        $this->assertSame(now()->addDays(30)->toDateString(), $draft['due_date']);

        // Ya están facturados: no entran en otro comprobante.
        $this->getJson('/api/v1/billable-events?status=INVOICED')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.invoice.id', $draft['id']);
        $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [$first['id']], 'type' => 'INVOICE'])->assertStatus(422)->assertJsonValidationErrors('event_ids');

        // Descartar el borrador devuelve sus eventos a "por facturar".
        $this->postJson("/api/v1/invoices/{$draft['id']}/discard")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->getJson('/api/v1/billable-events?status=PENDING')->assertOk()->assertJsonPath('total', 4);
        $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/invoices/{$draft['id']}/discard")->assertStatus(422);

        // Los días de crédito del cliente mandan en el vencimiento.
        DB::table('customers')->where('id', $customer)->update(['payment_terms_days' => 15]);
        $this->assertSame(now()->addDays(15)->toDateString(), $this->draft([$first['id']])['due_date']);
    }

    public function test_invoice_needs_the_customer_ruc_and_a_receipt_does_not(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));

        // Una persona con DNI: boleta sí, factura no.
        $partyId = (string) Str::uuid();
        DB::table('parties')->insert(['id' => $partyId, 'tenant_id' => $this->tenantId, 'party_type' => 'PERSON', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('persons')->insert(['party_id' => $partyId, 'given_names' => 'Rosa', 'family_name' => 'Quispe', 'document_type' => 'DNI', 'document_number' => '45678912']);
        $person = (string) Str::uuid();
        DB::table('customers')->insert(['id' => $person, 'tenant_id' => $this->tenantId, 'party_id' => $partyId, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $event = $this->event($person, 100);
        $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [$event['id']], 'type' => 'INVOICE'])->assertStatus(422)->assertJsonValidationErrors('type');

        $receipt = $this->draft([$event['id']], 'RECEIPT');
        $this->assertSame(['RECEIPT', 'B001', 'Rosa Quispe', '45678912'], [$receipt['invoice_type'], $receipt['series'], $receipt['customer_name'], $receipt['customer_tax_id']]);
        $this->postJson("/api/v1/invoices/{$receipt['id']}/issue")->assertOk()->assertJsonPath('invoice_number', 'B001-00000001');
    }

    public function test_issuing_numbers_the_invoice_opens_its_receivable_and_freezes_it(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $customer = $this->makeCustomer('Minera Los Andes SAC');
        $this->as($finance);
        $ruc = DB::table('organizations')->join('customers', 'customers.party_id', '=', 'organizations.party_id')->where('customers.id', $customer)->value('tax_identifier');

        $draft = $this->draft([$this->event($customer, 1000)['id']]);
        $issued = $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertOk()->json();

        $this->assertSame(['ISSUED', 'F001-00000001', now()->toDateString(), 'NOT_SENT', 'Minera Los Andes SAC', $ruc], [
            $issued['status'], $issued['invoice_number'], $issued['issue_date'], $issued['fiscal_status'], $issued['customer_name'], $issued['customer_tax_id'],
        ]);
        // Sin proveedor conectado no se dice que fue a SUNAT.
        $this->assertStringContainsString('no se envió a SUNAT', $issued['fiscal_response']);
        $this->assertSame(['OPEN', false], [$issued['receivable']['status'], $issued['receivable']['overdue']]);
        $this->assertEquals([1180, 1180, 1180], [$issued['receivable']['original_amount'], $issued['receivable']['balance_amount'], $issued['balance_due']]);
        $this->assertSame(['CHARGE'], array_column($issued['receivable']['movements'], 'movement_type'));
        $this->assertSame(['ISSUED', 'DRAFT'], array_values(array_filter(array_map(fn ($entry) => $entry['metadata']['to'] ?? null, $issued['history']))));

        // No se emite dos veces y el correlativo sigue sin saltos ni repeticiones.
        $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('F001-00000002', $this->issued($customer, 50)['invoice_number']);
        $this->assertDatabaseRejects(fn () => DB::table('invoices')->where('id', $draft['id'])->update(['number' => 2]));

        // Emitido, no se modifica: ni sus importes, ni su cliente, ni sus líneas.
        $this->assertDatabaseRejects(fn () => DB::table('invoices')->where('id', $draft['id'])->update(['total' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('invoices')->where('id', $draft['id'])->update(['customer_name' => 'Otro']));
        $this->assertDatabaseRejects(fn () => DB::table('invoices')->where('id', $draft['id'])->update(['status' => 'DRAFT']));
        $this->assertDatabaseRejects(fn () => DB::table('invoice_items')->where('invoice_id', $draft['id'])->update(['unit_price' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('invoice_items')->where('invoice_id', $draft['id'])->delete());
        $this->assertDatabaseRejects(fn () => DB::table('invoices')->where('id', $draft['id'])->delete());

        // El saldo de la cuenta solo cambia con un movimiento, y los movimientos no se tocan.
        $receivableId = $issued['receivable']['id'];
        $this->assertDatabaseRejects(fn () => DB::table('receivables')->where('id', $receivableId)->update(['balance_amount' => 0]));
        $this->assertDatabaseRejects(fn () => DB::table('receivables')->where('id', $receivableId)->update(['original_amount' => 5]));
        $this->assertDatabaseRejects(fn () => DB::table('receivable_movements')->where('receivable_id', $receivableId)->update(['amount' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('receivable_movements')->where('receivable_id', $receivableId)->delete());
        // Ni un abono mayor que el saldo.
        $this->assertDatabaseRejects(fn () => DB::table('receivable_movements')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'receivable_id' => $receivableId, 'movement_type' => 'PAYMENT', 'amount' => -5000,
        ]));
        $this->assertSame([1180.0, 'OPEN'], $this->balance($receivableId));

        // Pestañas y filtros del listado.
        $this->getJson('/api/v1/invoices/summary')->assertOk()->assertExactJson(['ALL' => 2, 'DRAFT' => 0, 'ISSUED' => 2, 'REJECTED' => 0, 'CORRECTED' => 0]);
        $this->getJson('/api/v1/invoices?tab=ISSUED&sort=total&dir=asc')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.invoice_number', 'F001-00000002');
        $this->getJson('/api/v1/invoices?tab=DRAFT')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/invoices?type=RECEIPT')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/invoices?search=00000001')->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/v1/invoices/{$draft['id']}/fiscal-retry")->assertStatus(422);
    }

    public function test_dollar_invoice_needs_an_exchange_rate_and_keeps_it(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer();

        $draft = $this->draft([$this->event($customer, 100, ['currency' => 'USD'])['id']]);
        $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->postJson('/api/v1/payments', ['customer_id' => $customer, 'amount' => 10, 'currency' => 'USD', 'payment_method' => 'TRANSFERENCIA', 'reference' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('currency');

        DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'rate_date' => now()->subDay()->toDateString(), 'currency' => 'USD', 'buy' => 3.70, 'sell' => 3.75]);
        $issued = $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertOk()->json();
        $this->assertEquals(['USD', 3.75, 118], [$issued['currency'], $issued['exchange_rate'], $issued['receivable']['balance_amount']]);

        // Un cobro en soles no se aplica a una cuenta en dólares: queda como anticipo.
        $soles = $this->pay($customer, 500, ['auto_allocate' => true]);
        $this->assertSame(['CONFIRMED', 0], [$soles['status'], count($soles['allocations'])]);
        $this->postJson("/api/v1/payments/{$soles['id']}/allocate")->assertStatus(422)->assertJsonValidationErrors('receivable_ids');

        // En dólares sí, y guarda la diferencia de cambio contra el de la emisión.
        DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'rate_date' => now()->toDateString(), 'currency' => 'USD', 'buy' => 3.80, 'sell' => 3.85]);
        $dollars = $this->pay($customer, 118, ['currency' => 'USD', 'auto_allocate' => true]);
        $this->assertSame('ALLOCATED', $dollars['status']);
        $this->assertEquals(3.85, $dollars['exchange_rate']);
        $this->assertEquals(11.80, DB::table('payment_allocations')->where('payment_id', $dollars['id'])->value('fx_difference_pen'));
        // El resumen lleva los dólares a soles con el tipo de cambio vigente.
        $this->getJson('/api/v1/receivables/summary')->assertOk()->assertJsonPath('open_count', 0)->assertJsonPath('missing_rate', false);
    }

    // ------------------------------------------------------------------ Proveedor de facturación electrónica

    /** Conecta a la empresa un proveedor de prueba que responde lo que se le indique. */
    private function connectProvider(): void
    {
        config(['fiscal.providers' => ['fake' => FakeFiscalProvider::class]]);
        FakeFiscalProvider::$sent = [];
        FakeFiscalProvider::$answer = 'accept';
        DB::table('fiscal_configurations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'country_code' => 'PE', 'provider_code' => 'fake',
            'tax_identifier' => '20123456789', 'environment' => 'BETA',
        ]);
    }

    public function test_with_a_provider_connected_the_invoice_goes_out_after_issuing_and_keeps_the_answer(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer('Minera Los Andes SAC');
        DB::table('companies')->where('tenant_id', $this->tenantId)->update(['tax_identifier' => '20123456789']);
        DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'rate_date' => now()->toDateString(), 'currency' => 'USD', 'buy' => 3.70, 'sell' => 3.75]);
        $this->connectProvider();

        // Un borrador no sale; emitido, sí, y con los datos que SUNAT pide.
        $draft = $this->draft([$this->event($customer, 1000, ['currency' => 'USD'])['id']]);
        $this->assertCount(0, FakeFiscalProvider::$sent);
        $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertOk()->assertJsonPath('fiscal_provider.name', 'Proveedor de prueba');
        $this->assertCount(1, FakeFiscalProvider::$sent);
        $sent = FakeFiscalProvider::$sent[0];
        $this->assertSame(['01', 'F001', 1, 'F001-00000001', 'USD', '6', 'Minera Los Andes SAC', '20123456789'], [
            $sent['type_code'], $sent['series'], $sent['number'], $sent['full_number'], $sent['currency'],
            $sent['customer']['document_type_code'], $sent['customer']['name'], $sent['issuer']['tax_identifier'],
        ]);
        // En dólares viaja el tipo de cambio de venta del día de emisión.
        $this->assertEquals([3.75, 1000, 180, 1180, 1000], [$sent['exchange_rate'], $sent['totals']['taxable'], $sent['totals']['tax'], $sent['totals']['total'], $sent['lines'][0]['unit_value']]);
        $this->assertNull($sent['corrects']);

        $accepted = $this->getJson("/api/v1/invoices/{$draft['id']}")->assertOk()->json();
        $this->assertSame(['ACCEPTED', 'Aceptado por SUNAT.'], [$accepted['fiscal_status'], $accepted['fiscal_response']]);
        $document = DB::table('fiscal_documents')->where('invoice_id', $draft['id'])->first();
        $this->assertSame(['ACCEPTED', 'F001', '0', str_repeat('a', 64)], [$document->status, $document->series, $document->sunat_status, $document->xml_hash]);
        $this->assertNotNull($document->accepted_at);
        // Aceptado, no se reenvía.
        $this->postJson("/api/v1/invoices/{$draft['id']}/fiscal-retry")->assertStatus(422);
        $this->assertCount(1, FakeFiscalProvider::$sent);

        // Rechazado por SUNAT: queda con su motivo y se reintenta. El comprobante sigue emitido.
        FakeFiscalProvider::$answer = 'reject';
        $second = $this->issued($customer, 50);
        $rejected = $this->getJson("/api/v1/invoices/{$second['id']}")->assertOk()->json();
        $this->assertSame(['ISSUED', 'REJECTED', 'RUC del receptor no válido'], [$rejected['status'], $rejected['fiscal_status'], $rejected['fiscal_response']]);
        $this->getJson('/api/v1/invoices?tab=REJECTED')->assertOk()->assertJsonPath('total', 1);
        FakeFiscalProvider::$answer = 'accept';
        $this->postJson("/api/v1/invoices/{$second['id']}/fiscal-retry")->assertOk();
        $this->getJson("/api/v1/invoices/{$second['id']}")->assertOk()->assertJsonPath('fiscal_status', 'ACCEPTED');
        $this->assertSame(1, DB::table('fiscal_documents')->where('invoice_id', $second['id'])->count());

        // El proveedor no responde: la emisión no se pierde; queda para reintentar.
        FakeFiscalProvider::$answer = 'fail';
        $third = $this->issued($customer, 70);
        $failed = $this->getJson("/api/v1/invoices/{$third['id']}")->assertOk()->json();
        $this->assertSame(['ISSUED', 'F001-00000003', 'REJECTED'], [$failed['status'], $failed['invoice_number'], $failed['fiscal_status']]);
        $this->assertStringContainsString('No se pudo comunicar', $failed['fiscal_response']);
        $this->assertSame('OPEN', $failed['receivable']['status']);

        // La nota de crédito también sale, en positivo y diciendo qué corrige.
        FakeFiscalProvider::$answer = 'accept';
        $note = $this->postJson("/api/v1/invoices/{$second['id']}/credit-note", ['reason' => 'Servicio no prestado'])->assertCreated()->json();
        $last = end(FakeFiscalProvider::$sent);
        $this->assertSame(['07', 'FC01-00000001', 'F001-00000002', 'Servicio no prestado', '01'], [
            $last['type_code'], $last['full_number'], $last['corrects']['full_number'], $last['corrects']['reason'], $last['corrects']['type_code'],
        ]);
        $this->assertEquals([50, 9, 59], [$last['totals']['taxable'], $last['totals']['tax'], $last['totals']['total']]);
        $this->getJson("/api/v1/invoices/{$note['id']}")->assertOk()->assertJsonPath('fiscal_status', 'ACCEPTED');

        foreach (['invoice.fiscal_accepted', 'invoice.fiscal_rejected', 'invoice.fiscal_retry'] as $action) {
            $this->assertTrue(DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', $action)->exists(), $action);
            $this->assertNotSame($action, AuditCatalog::actionLabel($action));
        }
    }

    public function test_without_a_provider_nothing_is_sent_and_one_connected_later_can_send_what_was_issued(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer();

        $issued = $this->issued($customer, 100);
        $this->assertSame(['NOT_SENT', false, null], [$issued['fiscal_status'], $issued['fiscal_provider']['connected'], $issued['fiscal_provider']['name']]);
        $this->postJson("/api/v1/invoices/{$issued['id']}/fiscal-retry")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(0, DB::table('fiscal_documents')->where('tenant_id', $this->tenantId)->count());

        // La empresa conecta su proveedor después: lo ya emitido se puede enviar.
        config(['fiscal.providers' => ['fake' => FakeFiscalProvider::class]]);
        FakeFiscalProvider::$sent = [];
        FakeFiscalProvider::$answer = 'accept';
        DB::table('fiscal_configurations')->where('tenant_id', $this->tenantId)->update(['provider_code' => 'fake']);
        $this->postJson("/api/v1/invoices/{$issued['id']}/fiscal-retry")->assertOk();
        $this->getJson("/api/v1/invoices/{$issued['id']}")->assertOk()->assertJsonPath('fiscal_status', 'ACCEPTED')->assertJsonPath('fiscal_provider.connected', true);
        // Quien no emite tampoco envía.
        $this->as($this->makeUser('Asistente', self::CLERK));
        $this->postJson("/api/v1/invoices/{$issued['id']}/fiscal-retry")->assertStatus(403);
    }

    // ------------------------------------------------------------------ Cobros y cuentas por cobrar

    public function test_payment_is_applied_to_the_oldest_receivables_and_the_balance_follows(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer('Otra Empresa SAC');
        $this->as($finance);

        $old = $this->issued($customer, 1000);       // 1180
        $recent = $this->issued($customer, 500);     // 590
        $foreign = $this->issued($other, 100);       // 118
        // El más antiguo vence antes.
        DB::table('receivables')->where('id', $old['receivable']['id'])->update(['due_date' => now()->subDays(40)->toDateString()]);

        $this->postJson('/api/v1/payments', [])->assertStatus(422)->assertJsonValidationErrors(['customer_id', 'amount', 'currency', 'payment_method']);
        $this->postJson('/api/v1/payments', ['customer_id' => $customer, 'amount' => 10, 'currency' => 'PEN', 'payment_method' => 'TRANSFERENCIA'])
            ->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->postJson('/api/v1/payments', ['customer_id' => $customer, 'amount' => 10, 'currency' => 'PEN', 'payment_method' => 'CHEQUE', 'reference' => '1', 'paid_at' => now()->addDays(2)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('paid_at');
        $this->postJson('/api/v1/payments', ['customer_id' => (string) Str::uuid(), 'amount' => 10, 'currency' => 'PEN', 'payment_method' => 'EFECTIVO'])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');

        // Sin aplicar: queda como anticipo, con su saldo libre y las cuentas a las que puede ir.
        $advance = $this->pay($customer, 1500);
        $this->assertSame(['PAG-'.date('Y').'-0001', 'CONFIRMED', 'BACKOFFICE', false], [$advance['payment_number'], $advance['status'], $advance['source'], $advance['reconciled']]);
        $this->assertEquals([1500, 0, 1500], [$advance['amount'], $advance['allocated'], $advance['free']]);
        $this->assertSame([$old['invoice_number'], $recent['invoice_number']], array_column($advance['open_receivables'], 'invoice_number'));

        // Aplicar: primero la más antigua, hasta agotar el saldo. Nunca a cuentas de otro cliente.
        $applied = $this->postJson("/api/v1/payments/{$advance['id']}/allocate")->assertOk()->json();
        $this->assertSame('ALLOCATED', $applied['status']);
        $this->assertEquals([1180, 320], array_column($applied['allocations'], 'amount'));
        $this->assertSame([0.0, 'PAID'], $this->balance($old['receivable']['id']));
        $this->assertSame([270.0, 'PARTIAL'], $this->balance($recent['receivable']['id']));
        $this->assertSame([118.0, 'OPEN'], $this->balance($foreign['receivable']['id']));
        $this->postJson("/api/v1/payments/{$advance['id']}/allocate")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertEquals(270, $this->getJson("/api/v1/invoices/{$recent['id']}")->assertOk()->assertJsonPath('payments.0.payment_number', $advance['payment_number'])->json('balance_due'));

        // Efectivo, sin número de operación, aplicado al registrar; sobra y queda parcial.
        $cash = $this->pay($customer, 300, ['payment_method' => 'EFECTIVO', 'reference' => null, 'auto_allocate' => true]);
        $this->assertSame('PARTIALLY_ALLOCATED', $cash['status']);
        $this->assertEquals([270, 30], [$cash['allocated'], $cash['free']]);
        $this->assertSame([0.0, 'PAID'], $this->balance($recent['receivable']['id']));

        // Conciliar una sola vez.
        $this->postJson("/api/v1/payments/{$cash['id']}/reconcile")->assertOk()->assertJsonPath('reconciled', true);
        $this->postJson("/api/v1/payments/{$cash['id']}/reconcile")->assertStatus(422);

        // Listado: buscar, filtrar y ordenar en el servidor.
        $this->getJson('/api/v1/payments?status=ALLOCATED')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.allocated', 1500);
        $this->getJson('/api/v1/payments?reconciled=true')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.payment_method', 'EFECTIVO');
        $this->getJson('/api/v1/payments?sort=amount&dir=asc')->assertOk()->assertJsonPath('data.0.amount', 300);
        $this->getJson('/api/v1/payments?search='.$advance['reference'])->assertOk()->assertJsonPath('total', 1);

        // Cuentas por cobrar: estados, vencidas y antigüedad.
        $this->getJson('/api/v1/receivables')->assertOk()->assertJsonPath('total', 3)->assertJsonPath('data.0.invoice_number', $old['invoice_number']);
        $this->getJson('/api/v1/receivables?status=PAID')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/receivables?status=OVERDUE')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/receivables?search=otra empresa')->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/v1/receivables/{$old['receivable']['id']}")->assertOk()
            ->assertJsonPath('movements.0.movement_type', 'CHARGE')->assertJsonPath('movements.1.movement_type', 'PAYMENT')->assertJsonPath('status', 'PAID');
    }

    public function test_aging_counts_overdue_balances_by_bucket(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer('Otra Empresa SAC');

        $current = $this->issued($customer, 100);   // 118, por vencer
        $late = $this->issued($customer, 200);      // 236, 45 días
        $older = $this->issued($other, 300);        // 354, 120 días
        DB::table('receivables')->where('id', $late['receivable']['id'])->update(['due_date' => now()->subDays(45)->toDateString()]);
        DB::table('receivables')->where('id', $older['receivable']['id'])->update(['due_date' => now()->subDays(120)->toDateString()]);

        $summary = $this->getJson('/api/v1/receivables/summary')->assertOk()->json();
        $this->assertEquals([708, 590, 3, 2], [$summary['outstanding'], $summary['overdue'], $summary['open_count'], $summary['overdue_customers']]);
        $this->assertEquals(
            ['CURRENT' => 118, 'D30' => 0, 'D60' => 236, 'D90' => 0, 'OVER' => 354],
            array_column($summary['aging'], 'amount', 'key'),
        );

        $this->getJson('/api/v1/receivables?status=OVERDUE')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.invoice_number', $older['invoice_number'])->assertJsonPath('data.0.overdue', true)->assertJsonPath('data.0.bucket', 'OVER');
        $this->getJson('/api/v1/receivables?bucket=D60')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.days_overdue', 45);
        $this->getJson('/api/v1/receivables?bucket=CURRENT')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.invoice_number', $current['invoice_number']);

        // Pagada, deja de estar vencida aunque su fecha ya pasó.
        $this->pay($other, 354, ['auto_allocate' => true]);
        $this->getJson('/api/v1/receivables?status=OVERDUE')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/receivables/summary')->assertOk()->assertJsonPath('overdue_customers', 1)->assertJsonPath('open_count', 2);
    }

    public function test_refund_reverts_the_latest_allocations_and_never_deletes_the_payment(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer();

        $first = $this->issued($customer, 1000);    // 1180
        $second = $this->issued($customer, 500);    // 590
        DB::table('receivables')->where('id', $first['receivable']['id'])->update(['due_date' => now()->subDays(5)->toDateString()]);
        $payment = $this->pay($customer, 2000, ['auto_allocate' => true]);   // 1180 + 590, libres 230
        $id = $payment['id'];
        $this->assertEquals(['PARTIALLY_ALLOCATED', 230], [$payment['status'], $payment['free']]);

        $this->postJson("/api/v1/payments/{$id}/refund", [])->assertStatus(422)->assertJsonValidationErrors(['amount', 'reason']);
        $this->postJson("/api/v1/payments/{$id}/refund", ['amount' => 2500, 'reason' => 'Demasiado'])->assertStatus(422)->assertJsonValidationErrors('amount');

        // Sale primero de lo que no estaba aplicado: las cuentas no cambian.
        $partial = $this->postJson("/api/v1/payments/{$id}/refund", ['amount' => 200, 'reason' => 'Pago en exceso'])->assertOk()->json();
        $this->assertEquals([200, 30, 'PARTIALLY_ALLOCATED'], [$partial['refunded_amount'], $partial['free'], $partial['status']]);
        $this->assertSame([0.0, 'PAID'], $this->balance($second['receivable']['id']));

        // No alcanza con lo libre: se revierte la aplicación más reciente y esa cuenta recupera saldo.
        $more = $this->postJson("/api/v1/payments/{$id}/refund", ['amount' => 330, 'reason' => 'Devolución parcial'])->assertOk()->json();
        $this->assertEquals([530, 0, 'ALLOCATED'], [$more['refunded_amount'], $more['free'], $more['status']]);
        $this->assertEquals([1180, 290], array_column($more['allocations'], 'amount'));
        $this->assertSame([300.0, 'PARTIAL'], $this->balance($second['receivable']['id']));
        $this->assertSame([0.0, 'PAID'], $this->balance($first['receivable']['id']));
        $this->assertSame(['CHARGE', 'PAYMENT', 'REVERSAL'], DB::table('receivable_movements')->where('receivable_id', $second['receivable']['id'])->orderBy('created_at')->orderBy('amount', 'desc')->pluck('movement_type')->sort()->values()->all());

        // Reembolso total: todo vuelve a deberse y el cobro sigue ahí, reembolsado.
        $all = $this->postJson("/api/v1/payments/{$id}/refund", ['amount' => 1470, 'reason' => 'Anulación del servicio'])->assertOk()->json();
        $this->assertSame(['REFUNDED', 0, 3], [$all['status'], count($all['allocations']), count($all['refunds'])]);
        $this->assertEquals(2000, $all['refunded_amount']);
        $this->assertSame([1180.0, 'OPEN'], $this->balance($first['receivable']['id']));
        $this->assertSame([590.0, 'OPEN'], $this->balance($second['receivable']['id']));
        $this->postJson("/api/v1/payments/{$id}/refund", ['amount' => 1, 'reason' => 'Otra vez'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/payments/{$id}/allocate")->assertStatus(422);
        $this->postJson("/api/v1/payments/{$id}/reconcile")->assertStatus(422);

        $this->getJson('/api/v1/payments/refunds')->assertOk()->assertJsonCount(3)->assertJsonPath('0.payment_number', $payment['payment_number']);
        // Ni el cobro ni sus reembolsos se borran o se reescriben; no se reembolsa más de lo cobrado.
        $this->assertDatabaseRejects(fn () => DB::table('payments')->where('id', $id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('refunds')->where('payment_id', $id)->update(['amount' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('payments')->where('id', $id)->update(['refunded_amount' => 2500]));
    }

    public function test_credit_note_corrects_an_issued_invoice_without_touching_it(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer();

        $invoice = $this->issued($customer, 1000);
        $id = $invoice['id'];
        $draft = $this->draft([$this->event($customer, 10)['id']]);

        $this->postJson("/api/v1/invoices/{$draft['id']}/credit-note", ['reason' => 'X'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/invoices/{$id}/credit-note", [])->assertStatus(422)->assertJsonValidationErrors('reason');

        // Con cobros aplicados no: primero se reembolsan.
        $payment = $this->pay($customer, 500, ['auto_allocate' => true]);
        $this->postJson("/api/v1/invoices/{$id}/credit-note", ['reason' => 'Servicio no prestado'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/payments/{$payment['id']}/refund", ['amount' => 500, 'reason' => 'Servicio no prestado'])->assertOk();

        $note = $this->postJson("/api/v1/invoices/{$id}/credit-note", ['reason' => 'Servicio no prestado'])->assertCreated()->json();
        $this->assertSame(['CREDIT_NOTE', 'FC01-00000001', 'ISSUED', $id, 'Servicio no prestado'], [
            $note['invoice_type'], $note['invoice_number'], $note['status'], $note['related']['id'], $note['reason'],
        ]);
        $this->assertEquals([-1000, -180, -1180], [$note['subtotal'], $note['tax_total'], $note['total']]);
        $this->assertNull($note['receivable']);

        $corrected = $this->getJson("/api/v1/invoices/{$id}")->assertOk()->json();
        $this->assertSame(['CORRECTED', $note['id'], 'CANCELLED'], [$corrected['status'], $corrected['credit_note']['id'], $corrected['receivable']['status']]);
        // El original conserva sus importes; solo su saldo queda en cero.
        $this->assertEquals([1180, 0, 0], [$corrected['total'], $corrected['balance_due'], $corrected['receivable']['balance_amount']]);
        $this->assertContains('CREDIT_NOTE', array_column($corrected['receivable']['movements'], 'movement_type'));

        // Una sola corrección por comprobante, y una nota de crédito no se corrige.
        $this->postJson("/api/v1/invoices/{$id}/credit-note", ['reason' => 'Otra'])->assertStatus(422);
        $this->postJson("/api/v1/invoices/{$note['id']}/credit-note", ['reason' => 'Otra'])->assertStatus(422);
        // Una cuenta anulada ya no recibe cobros.
        $this->assertSame('CONFIRMED', $this->pay($customer, 100, ['auto_allocate' => true])['status']);
        $this->getJson('/api/v1/invoices/summary')->assertOk()->assertJsonPath('CORRECTED', 1)->assertJsonPath('DRAFT', 1);
        $this->getJson('/api/v1/invoices?type=CREDIT_NOTE')->assertOk()->assertJsonPath('total', 1);

        // La de una boleta lleva serie B.
        $partyId = DB::table('customers')->where('id', $customer)->value('party_id');
        $receipt = $this->draft([$this->event($customer, 20)['id']], 'RECEIPT');
        $this->postJson("/api/v1/invoices/{$receipt['id']}/issue")->assertOk();
        $this->postJson("/api/v1/invoices/{$receipt['id']}/credit-note", ['reason' => 'Error en el importe'])->assertCreated()->assertJsonPath('invoice_number', 'BC01-00000001');
        $this->assertNotNull($partyId);
    }

    // ------------------------------------------------------------------ Permisos, exportar y alcance

    public function test_each_action_asks_for_its_own_permission_and_exporting_is_a_separate_one(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $clerk = $this->makeUser('Asistente', self::CLERK);
        $sales = $this->makeUser('Ventas', ['receivables.view']);
        $customer = $this->makeCustomer();

        // Quien prepara no emite, no anula, no concilia, no reembolsa ni exporta.
        $this->as($clerk);
        $event = $this->event($customer, 1000);
        $draft = $this->draft([$event['id']]);
        $payment = $this->pay($customer, 100);
        $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertStatus(403);
        $this->postJson("/api/v1/invoices/{$draft['id']}/discard")->assertStatus(403);
        $this->postJson("/api/v1/invoices/{$draft['id']}/credit-note", ['reason' => 'X'])->assertStatus(403);
        $this->postJson("/api/v1/billable-events/{$event['id']}/cancel", ['reason' => 'X'])->assertStatus(403);
        $this->postJson("/api/v1/payments/{$payment['id']}/allocate")->assertStatus(403);
        $this->postJson("/api/v1/payments/{$payment['id']}/reconcile")->assertStatus(403);
        $this->postJson("/api/v1/payments/{$payment['id']}/refund", ['amount' => 1, 'reason' => 'X'])->assertStatus(403);
        foreach (['billable-events', 'invoices', 'receivables', 'payments'] as $list) {
            $this->getJson("/api/v1/{$list}")->assertOk();
            $this->getJson("/api/v1/{$list}?export=1")->assertStatus(403);
        }
        $this->postJson('/api/v1/exports', ['resource' => 'payments', 'format' => 'xlsx', 'rows' => 1])->assertStatus(403);

        // Ventas solo ve cuentas por cobrar.
        $this->as($sales);
        $this->getJson('/api/v1/receivables')->assertOk();
        $this->getJson('/api/v1/receivables/summary')->assertOk();
        foreach (['billable-events', 'invoices', 'payments', 'payments/refunds', 'invoices/summary'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertStatus(403);
        }
        $this->postJson('/api/v1/billable-events', ['customer_id' => $customer, 'description' => 'X', 'base' => 1, 'currency' => 'PEN'])->assertStatus(403);
        $this->postJson('/api/v1/payments', ['customer_id' => $customer, 'amount' => 1, 'currency' => 'PEN', 'payment_method' => 'EFECTIVO'])->assertStatus(403);

        // Finanzas sí, y cada exportación queda en auditoría.
        $this->as($finance);
        $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertOk();
        foreach (['billable-events', 'invoices', 'receivables', 'payments'] as $list) {
            $this->getJson("/api/v1/{$list}?export=1")->assertOk();
        }
        $this->postJson('/api/v1/exports', ['resource' => 'billing', 'format' => 'xlsx', 'rows' => 1])->assertOk();
        $this->postJson('/api/v1/exports', ['resource' => 'receivables', 'format' => 'csv', 'rows' => 1])->assertOk();
        $this->assertSame(2, DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('entity_type', 'Export')->count());

        // Toda acción registrada se lee con su texto, no con su código.
        $this->postJson("/api/v1/payments/{$payment['id']}/allocate")->assertOk();
        $this->postJson("/api/v1/payments/{$payment['id']}/reconcile")->assertOk();
        $this->postJson("/api/v1/payments/{$payment['id']}/refund", ['amount' => 100, 'reason' => 'Prueba'])->assertOk();
        $this->postJson("/api/v1/invoices/{$draft['id']}/credit-note", ['reason' => 'Prueba'])->assertCreated();
        $this->postJson("/api/v1/billable-events/{$this->event($customer, 5)['id']}/cancel", ['reason' => 'Prueba'])->assertOk();
        $this->postJson("/api/v1/invoices/{$this->draft([$this->event($customer, 5)['id']])['id']}/discard")->assertOk();
        $actions = DB::table('audit_logs')->where('tenant_id', $this->tenantId)->distinct()->pluck('action');
        foreach (['billable_event.create', 'billable_event.cancel', 'invoice.create', 'invoice.issue', 'invoice.discard', 'invoice.credit_note', 'invoice.correct',
            'payment.create', 'payment.allocate', 'payment.reconcile', 'payment.refund', 'billing.export', 'receivables.export'] as $action) {
            $this->assertContains($action, $actions);
            $this->assertNotSame($action, AuditCatalog::actionLabel($action), "Falta el texto de {$action}");
        }
        $this->assertSame(['Evento facturable', 'Comprobante', 'Cobro'], [AuditCatalog::entityLabel('BillableEvent'), AuditCatalog::entityLabel('Invoice'), AuditCatalog::entityLabel('Payment')]);
    }

    public function test_finance_permissions_need_the_finance_module(): void
    {
        $this->makeTenant(['CONTRATOS']);
        $this->as($this->makeUser('Finanzas', self::FINANCE));

        foreach (['billable-events', 'invoices', 'receivables', 'payments'] as $list) {
            $this->getJson("/api/v1/{$list}")->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
        }
    }

    public function test_scope_limits_what_each_role_reaches_and_other_companies_do_not_exist(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $lima = $this->makeBranch('Lima');
        $cusco = $this->makeBranch('Cusco');
        // Ventas: lo propio (sin alcance definido). Operaciones: su sede.
        $ana = $this->makeUser('Ana (Ventas)', ['receivables.view', 'contracts.view', 'core.view'], null);
        $luis = $this->makeUser('Luis (Ventas)', ['receivables.view', 'contracts.view'], null);
        $operations = $this->makeUser('Operaciones', ['billing.view', 'core.view'], 'BRANCH', $lima);
        $cashier = $this->makeUser('Cajera', self::CLERK, null);
        $ofAna = $this->makeContract($ana, branchId: $lima);
        $ofLuis = $this->makeContract($luis, branchId: $cusco);

        $this->as($finance);
        $invoiceAna = $this->issued($ofAna[1], 1000, ['contract_id' => $ofAna[0]]);
        $invoiceLuis = $this->issued($ofLuis[1], 500, ['contract_id' => $ofLuis[0]]);
        $paymentOfFinance = $this->pay($ofAna[1], 100);

        // Cada vendedora ve las cuentas por cobrar de lo que vendió; lo ajeno "no existe".
        $this->as($ana);
        $this->getJson('/api/v1/receivables')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.invoice_number', $invoiceAna['invoice_number']);
        $this->getJson('/api/v1/receivables/summary')->assertOk()->assertJsonPath('open_count', 1)->assertJsonPath('outstanding', 1180);
        $this->getJson("/api/v1/receivables/{$invoiceLuis['receivable']['id']}")->assertStatus(404);
        $this->getJson("/api/v1/receivables/{$invoiceAna['receivable']['id']}")->assertOk();
        // También en la vista del cliente: el saldo de lo suyo, y ningún comprobante (su rol no los ve).
        $party = fn (string $customerId) => DB::table('customers')->where('id', $customerId)->value('party_id');
        $this->getJson('/api/v1/parties/'.$party($ofAna[1]))->assertOk()
            ->assertJsonPath('finance.outstanding', 1180)->assertJsonPath('finance.open_documents', 1)->assertJsonCount(0, 'invoices');
        $this->getJson('/api/v1/parties/'.$party($ofLuis[1]))->assertOk()->assertJsonPath('finance.open_documents', 0);

        // Operaciones ve los comprobantes de su sede.
        $this->as($operations);
        // En la vista del cliente, igual: los de su sede, y sin saldo (su rol no ve cuentas por cobrar).
        $this->getJson('/api/v1/parties/'.$party($ofAna[1]))->assertOk()->assertJsonCount(1, 'invoices')->assertJsonPath('finance', null);
        $this->getJson('/api/v1/parties/'.$party($ofLuis[1]))->assertOk()->assertJsonCount(0, 'invoices');
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $invoiceAna['id']);
        $this->getJson('/api/v1/invoices/summary')->assertOk()->assertJsonPath('ALL', 1);
        $this->getJson("/api/v1/invoices/{$invoiceLuis['id']}")->assertStatus(404);
        $this->getJson('/api/v1/billable-events')->assertOk()->assertJsonPath('total', 1);

        // La cajera ve sus cobros, no los de Finanzas; y no alcanza comprobantes ajenos.
        $this->as($cashier);
        $own = $this->pay($ofAna[1], 50);
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $own['id']);
        $this->getJson("/api/v1/payments/{$paymentOfFinance['id']}")->assertStatus(404);
        $this->getJson("/api/v1/invoices/{$invoiceAna['id']}")->assertStatus(404);

        // Otra empresa: nada de esta existe para ella.
        $firstTenant = $this->tenantId;
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas de otra empresa', self::FINANCE));
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/invoices/{$invoiceAna['id']}")->assertStatus(404);
        $this->getJson("/api/v1/receivables/{$invoiceAna['receivable']['id']}")->assertStatus(404);
        $this->getJson("/api/v1/payments/{$paymentOfFinance['id']}")->assertStatus(404);
        $this->postJson("/api/v1/payments/{$paymentOfFinance['id']}/refund", ['amount' => 1, 'reason' => 'X'])->assertStatus(404);
        $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [DB::table('billable_events')->where('tenant_id', $firstTenant)->value('id') ?? (string) Str::uuid()], 'type' => 'INVOICE'])->assertStatus(422);
        // La numeración es por empresa: empieza de nuevo.
        $this->assertSame('F001-00000001', $this->issued($this->makeCustomer(), 10)['invoice_number']);
    }
}

/** Proveedor de facturación electrónica de mentira: guarda lo que recibe y responde lo que se le pida. */
class FakeFiscalProvider implements \App\Modules\Finance\Fiscal\FiscalProvider
{
    public static array $sent = [];

    /** accept, reject o fail (no logra comunicarse). */
    public static string $answer = 'accept';

    public function name(): string
    {
        return 'Proveedor de prueba';
    }

    public function send(array $document, array $settings): \App\Modules\Finance\Fiscal\FiscalResult
    {
        self::$sent[] = $document;

        return match (self::$answer) {
            'reject' => \App\Modules\Finance\Fiscal\FiscalResult::rejected('RUC del receptor no válido', '2800'),
            'fail' => throw new \RuntimeException('Tiempo de espera agotado'),
            default => \App\Modules\Finance\Fiscal\FiscalResult::accepted(code: '0', hash: str_repeat('a', 64), cdr: 'R-'.$document['full_number']),
        };
    }
}
