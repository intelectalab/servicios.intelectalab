<?php

namespace Tests\Feature;

use App\Modules\Finance\Fiscal\FiscalResult;
use App\Modules\Finance\Fiscal\FiscalVoidProvider;
use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Finanzas, facturación electrónica: configuración fiscal y series, detracción y retención del
 * comprobante (fijadas al emitir, constancia que baja la cuenta por cobrar), emisión en
 * contingencia y comunicación de baja por el proveedor; permisos.
 */
class FinanceFiscalTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MODULES = ['CONTRATOS', 'FINANZAS', 'COMPRAS', 'CONTABILIDAD'];

    private const FINANCE = [
        'billing.view', 'billing.create', 'billing.manage', 'billing.approve', 'receivables.view', 'receivables.manage',
        'payments.view', 'payments.create', 'payments.manage', 'payments.approve',
        'fiscal.view', 'fiscal.manage', 'fiscal.configure', 'treasury.view', 'treasury.manage', 'core.view', 'core.manage',
    ];

    private const SETTINGS = ['environment' => 'BETA', 'detraction_enabled' => true, 'detraction_code' => '037', 'detraction_account' => '00-000-123456'];

    private function draft(string $customerId, float $base = 1000, string $type = 'INVOICE', string $currency = 'PEN'): array
    {
        $event = $this->postJson('/api/v1/billable-events', ['customer_id' => $customerId, 'description' => 'Servicio de consultoría', 'base' => $base, 'currency' => $currency])->assertCreated()->json();

        return $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [$event['id']], 'type' => $type])->assertCreated()->json();
    }

    private function issued(string $customerId, float $base = 1000, string $type = 'INVOICE', string $currency = 'PEN'): array
    {
        return $this->postJson('/api/v1/invoices/'.$this->draft($customerId, $base, $type, $currency)['id'].'/issue')->assertOk()->json();
    }

    private function connectProvider(): void
    {
        config(['fiscal.providers' => ['fake' => FakeVoidProvider::class]]);
        FakeVoidProvider::$voids = [];
        FakeVoidProvider::$answer = 'accept';
        DB::table('fiscal_configurations')->updateOrInsert(['tenant_id' => $this->tenantId, 'country_code' => 'PE'], ['provider_code' => 'fake']);
    }

    public function test_fiscal_settings_are_validated_and_series_only_move_forward(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer();

        // Sin configurar: series de siempre, en línea, sin proveedor y sin detracción.
        $initial = $this->getJson('/api/v1/fiscal/settings')->assertOk()->json();
        $this->assertSame([false, null, 'ONLINE', false, '037'], [$initial['provider']['connected'], $initial['provider']['code'], $initial['mode'], $initial['detraction']['enabled'], $initial['detraction']['code']]);
        $this->assertSame(['F001', 'B001', 'FC01', 'BC01'], array_column($initial['series'], 'series'));
        $this->assertSame(['0001', '0002', null, null], array_column($initial['series'], 'contingency_series'));
        $this->assertSame([], $initial['provider']['available']);

        $this->putJson('/api/v1/fiscal/settings', [...self::SETTINGS, 'tax_identifier' => '123'])->assertStatus(422)->assertJsonValidationErrors('tax_identifier');
        $this->putJson('/api/v1/fiscal/settings', [...self::SETTINGS, 'detraction_account' => '123456'])->assertStatus(422)->assertJsonValidationErrors('detraction_account');
        $this->putJson('/api/v1/fiscal/settings', [...self::SETTINGS, 'detraction_account' => null])->assertStatus(422)->assertJsonValidationErrors('detraction_account');
        $this->putJson('/api/v1/fiscal/settings', [...self::SETTINGS, 'detraction_code' => '999'])->assertStatus(422)->assertJsonValidationErrors('detraction_code');
        // Un proveedor que esta instalación no tiene no se puede elegir.
        $this->putJson('/api/v1/fiscal/settings', [...self::SETTINGS, 'provider_code' => 'inventado'])->assertStatus(422)->assertJsonValidationErrors('provider_code');

        $saved = $this->putJson('/api/v1/fiscal/settings', [...self::SETTINGS, 'tax_identifier' => '20123456789'])->assertOk()->json();
        $this->assertSame(['20123456789', true, '00-000-123456'], [$saved['tax_identifier'], $saved['detraction']['enabled'], $saved['detraction']['account']]);
        // El RUC emisor es el de la empresa: el que viaja en cada comprobante.
        $this->assertSame('20123456789', DB::table('companies')->where('tenant_id', $this->tenantId)->value('tax_identifier'));

        // Series: formato por tipo, todas distintas, y el correlativo solo avanza.
        $series = ['series' => ['INVOICE' => 'F002', 'RECEIPT' => 'B002', 'CREDIT_NOTE_INVOICE' => 'FC02', 'CREDIT_NOTE_RECEIPT' => 'BC02'], 'contingency_series' => ['INVOICE' => '0001', 'RECEIPT' => '0002']];
        $this->putJson('/api/v1/fiscal/series', [...$series, 'series' => [...$series['series'], 'INVOICE' => 'B009']])->assertStatus(422)->assertJsonValidationErrors('series.INVOICE');
        $this->putJson('/api/v1/fiscal/series', [...$series, 'series' => [...$series['series'], 'CREDIT_NOTE_INVOICE' => 'F002']])->assertStatus(422)->assertJsonValidationErrors('series');
        $this->putJson('/api/v1/fiscal/series', [...$series, 'contingency_series' => ['INVOICE' => '0001', 'RECEIPT' => '0001']])->assertStatus(422)->assertJsonValidationErrors('series');
        $this->putJson('/api/v1/fiscal/series', [...$series, 'contingency_series' => ['INVOICE' => 'F900', 'RECEIPT' => '0002']])->assertStatus(422)->assertJsonValidationErrors('contingency_series.INVOICE');
        // Una empresa que viene de otro sistema continúa su numeración.
        $updated = $this->putJson('/api/v1/fiscal/series', [...$series, 'next' => ['INVOICE' => 1500]])->assertOk()->json();
        $this->assertSame(['F002', 1499], [$updated['series'][0]['series'], $updated['series'][0]['last_number']]);
        $this->assertSame('F002-00001500', $this->issued($customer)['invoice_number']);
        $this->putJson('/api/v1/fiscal/series', [...$series, 'next' => ['INVOICE' => 1500]])->assertStatus(422)->assertJsonValidationErrors('next.INVOICE');
        $this->putJson('/api/v1/fiscal/series', [...$series, 'next' => ['INVOICE' => 1501]])->assertOk();
        $this->assertDatabaseRejects(fn () => DB::table('fiscal_series')->where('tenant_id', $this->tenantId)->delete());

        foreach (['fiscal.settings_update', 'fiscal.series_update'] as $action) {
            $this->assertTrue(DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', $action)->exists(), $action);
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }
    }

    public function test_an_invoice_keeps_its_detraction_and_the_deposit_receipt_lowers_the_receivable(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer('Minera Los Andes SAC');

        // Sin configurar no se calcula nada: no se supone un porcentaje.
        $plain = $this->issued($customer, 1000);
        $this->assertNull($plain['withholding']);

        $this->putJson('/api/v1/fiscal/settings', self::SETTINGS)->assertOk();
        $detractionAccount = $this->postJson('/api/v1/bank-accounts', ['name' => 'Detracciones BN', 'account_type' => 'DETRACTION', 'currency' => 'PEN', 'account_number' => '00-000-123456'])->assertCreated()->json('id');

        // Hasta S/ 700 no aplica; una boleta, tampoco.
        $this->assertNull($this->issued($customer, 500)['withholding']);
        $this->assertNull($this->issued($customer, 2000, 'RECEIPT')['withholding']);

        // En el borrador se ve lo que le corresponderá; emitida, queda fijada.
        $draft = $this->draft($customer, 1000);
        $this->assertSame(['DETRACTION', '037', 'Detracción 12 % (código 037)'], [$draft['withholding_preview']['type'], $draft['withholding_preview']['code'], $draft['withholding_preview']['label']]);
        $invoice = $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertOk()->json();
        // 12 % de 1,180 = 141.60 → el depósito va en soles y sin decimales.
        $this->assertEquals(['DETRACTION', '037', 12, 142, 'PENDING'], [
            $invoice['withholding']['type'], $invoice['withholding']['code'], $invoice['withholding']['rate'], $invoice['withholding']['amount'], $invoice['withholding']['status'],
        ]);
        $this->assertSame('00-000-123456', $invoice['detraction_account']);
        // Emitida, su detracción no se modifica.
        $this->assertDatabaseRejects(fn () => DB::table('invoices')->where('id', $invoice['id'])->update(['withholding_amount' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('invoices')->where('id', $invoice['id'])->update(['withholding_type' => null, 'withholding_amount' => null, 'withholding_status' => null]));
        $this->putJson("/api/v1/invoices/{$invoice['id']}/withholding", ['mode' => 'NONE'])->assertStatus(422);

        // En cuentas por cobrar y en lo pendiente de clientes.
        $row = $this->getJson('/api/v1/receivables?sort=withholding&dir=desc')->assertOk()->json('data.0');
        $this->assertEquals(['DETRACTION', 142, 'PENDING', $invoice['invoice_number']], [$row['withholding']['type'], $row['withholding']['amount'], $row['withholding']['status'], $row['invoice_number']]);
        $pending = $this->getJson('/api/v1/fiscal/settings')->assertOk()->json('pending');
        $this->assertEquals([1, 142, 0, $invoice['invoice_number']], [$pending['detractions']['count'], $pending['detractions']['amount'], $pending['retentions']['count'], $pending['invoices'][0]['invoice_number']]);

        // La constancia de depósito baja la cuenta por cobrar y entra a la cuenta de detracciones.
        $this->postJson("/api/v1/invoices/{$invoice['id']}/withholding-receipt", [])->assertStatus(422)->assertJsonValidationErrors('number');
        $this->postJson("/api/v1/invoices/{$invoice['id']}/withholding-receipt", ['number' => '123', 'date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->postJson("/api/v1/invoices/{$plain['id']}/withholding-receipt", ['number' => '123'])->assertStatus(422)->assertJsonValidationErrors('status');
        $done = $this->postJson("/api/v1/invoices/{$invoice['id']}/withholding-receipt", ['number' => '0012345678'])->assertOk()->json();
        $this->assertSame(['RECEIVED', '0012345678', now()->toDateString()], [$done['withholding']['status'], $done['withholding']['number'], $done['withholding']['date']]);
        $this->assertEquals([1038, 'PARTIAL'], [$done['receivable']['balance_amount'], $done['receivable']['status']]);
        $movement = collect($done['receivable']['movements'])->firstWhere('movement_type', 'WITHHOLDING');
        $this->assertEquals([-142, 'Detracción · constancia 0012345678'], [$movement['amount'], $movement['reference']]);
        $banked = DB::table('bank_movements')->where('account_id', $detractionAccount)->first();
        $this->assertEquals([142, 'DETRACTION', '0012345678', $invoice['id']], [$banked->amount, $banked->ref_type, $banked->op_number, $banked->ref_id]);
        // Una sola vez, y lo registrado no se cambia.
        $this->postJson("/api/v1/invoices/{$invoice['id']}/withholding-receipt", ['number' => '999'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertDatabaseRejects(fn () => DB::table('invoices')->where('id', $invoice['id'])->update(['withholding_number' => 'otra']));
        $this->assertDatabaseRejects(fn () => DB::table('invoices')->where('id', $invoice['id'])->update(['withholding_status' => 'PENDING']));
        $this->assertSame(0, $this->getJson('/api/v1/fiscal/settings')->json('pending.detractions.count'));
        // El resto se cobra normal; con una constancia aplicada, la nota de crédito pide dejar la cuenta intacta.
        $this->postJson('/api/v1/payments', ['customer_id' => $customer, 'amount' => 1038, 'currency' => 'PEN', 'payment_method' => 'EFECTIVO', 'auto_allocate' => true])->assertCreated();
        $this->postJson("/api/v1/invoices/{$invoice['id']}/credit-note", ['reason' => 'Error'])->assertStatus(422);
    }

    public function test_the_draft_decides_its_detraction_code_or_none_and_a_retention_agent_is_retained(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer();
        $agent = $this->makeCustomer('Agente de Retención SAA');
        DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'rate_date' => now()->toDateString(), 'currency' => 'USD', 'buy' => 3.70, 'sell' => 3.75]);
        $this->putJson('/api/v1/fiscal/settings', self::SETTINGS)->assertOk();

        // Otro código, con su porcentaje.
        $draft = $this->draft($customer, 1000);
        $this->assertCount(10, $draft['detraction_codes']);
        $this->putJson("/api/v1/invoices/{$draft['id']}/withholding", ['mode' => 'DETRACTION'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->putJson("/api/v1/invoices/{$draft['id']}/withholding", ['mode' => 'DETRACTION', 'code' => '999'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->putJson("/api/v1/invoices/{$draft['id']}/withholding", ['mode' => 'DETRACTION', 'code' => '030'])->assertOk()->assertJsonPath('withholding_preview.label', 'Detracción 4 % (código 030)');
        $this->assertEquals(['030', 4, 47], array_values(array_intersect_key($this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertOk()->json('withholding'), array_flip(['code', 'rate', 'amount']))));

        // No aplica (p. ej. licencias de software): se decide antes de emitir.
        $exempt = $this->draft($customer, 1000);
        $this->putJson("/api/v1/invoices/{$exempt['id']}/withholding", ['mode' => 'NONE'])->assertOk()->assertJsonPath('withholding_preview', null);
        $this->assertNull($this->postJson("/api/v1/invoices/{$exempt['id']}/issue")->assertOk()->json('withholding'));
        // En una boleta no hay nada que decidir.
        $this->putJson('/api/v1/invoices/'.$this->draft($customer, 1000, 'RECEIPT')['id'].'/withholding', ['mode' => 'NONE'])->assertStatus(422)->assertJsonValidationErrors('mode');

        // En dólares se calcula en soles con el tipo de cambio de la emisión, y la constancia baja la cuenta en dólares.
        $dollars = $this->issued($customer, 1000, 'INVOICE', 'USD');
        // 1,180 × 3.75 = 4,425 → 12 % = 531.
        $this->assertEquals(531, $dollars['withholding']['amount']);
        $receipt = $this->postJson("/api/v1/invoices/{$dollars['id']}/withholding-receipt", ['number' => 'C-1'])->assertOk()->json();
        $this->assertEquals(round(1180 - 531 / 3.75, 2), $receipt['receivable']['balance_amount']);

        // Sin detracción en la empresa, al agente de retención se le retiene el 3 %.
        $this->putJson('/api/v1/fiscal/settings', [...self::SETTINGS, 'detraction_enabled' => false])->assertOk();
        $this->assertNull($this->issued($customer, 1000)['withholding']);
        $this->putJson("/api/v1/customers/{$agent}", ['retention_agent' => true])->assertOk();
        $retained = $this->issued($agent, 1000);
        $this->assertEquals(['RETENTION', null, 3, 35.40, 'PENDING'], [
            $retained['withholding']['type'], $retained['withholding']['code'], $retained['withholding']['rate'], $retained['withholding']['amount'], $retained['withholding']['status'],
        ]);
        $certificate = $this->postJson("/api/v1/invoices/{$retained['id']}/withholding-receipt", ['number' => 'R001-55'])->assertOk()->json();
        $this->assertEquals(1144.60, $certificate['receivable']['balance_amount']);
        $this->assertSame('Retención · certificado R001-55', collect($certificate['receivable']['movements'])->firstWhere('movement_type', 'WITHHOLDING')['reference']);
        // La retención no entra a ninguna cuenta bancaria.
        $this->assertSame(0, DB::table('bank_movements')->where('tenant_id', $this->tenantId)->where('ref_type', 'DETRACTION')->where('ref_id', $retained['id'])->count());
        // La ficha del cliente lo dice.
        $partyId = DB::table('customers')->where('id', $agent)->value('party_id');
        $this->getJson("/api/v1/parties/{$partyId}")->assertOk()->assertJsonPath('customer.retention_agent', true);
    }

    public function test_contingency_uses_its_own_series_and_restoring_sends_what_was_issued(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer();
        $this->connectProvider();

        $this->postJson('/api/v1/fiscal/contingency/start', [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/fiscal/contingency/end')->assertStatus(422)->assertJsonValidationErrors('status');
        $normal = $this->issued($customer, 100);
        $this->assertSame(['F001-00000001', 'ACCEPTED'], [$normal['invoice_number'], $this->getJson("/api/v1/invoices/{$normal['id']}")->json('fiscal_status')]);
        $sentBefore = count(FakeVoidProvider::$sent);

        // Un borrador preparado antes toma la serie de contingencia al emitirse.
        $prepared = $this->draft($customer, 200);
        $this->postJson('/api/v1/fiscal/contingency/start', ['reason' => 'Caída del proveedor'])->assertOk()->assertJsonPath('mode', 'CONTINGENCY');
        $this->postJson('/api/v1/fiscal/contingency/start', ['reason' => 'Otra vez'])->assertStatus(422)->assertJsonValidationErrors('status');
        $invoice = $this->postJson("/api/v1/invoices/{$prepared['id']}/issue")->assertOk()->json();
        $receipt = $this->issued($customer, 50, 'RECEIPT');
        $this->assertSame(['0001-00000001', 'CONTINGENCY', '0002-00000001', 'CONTINGENCY'], [$invoice['invoice_number'], $invoice['fiscal_status'], $receipt['invoice_number'], $receipt['fiscal_status']]);
        $this->assertStringContainsString('contingencia', $invoice['fiscal_response']);
        // Nada sale mientras dure; cada comprobante nace con su cuenta por cobrar.
        $this->assertCount($sentBefore, FakeVoidProvider::$sent);
        $this->assertSame('OPEN', $invoice['receivable']['status']);
        $this->postJson("/api/v1/invoices/{$invoice['id']}/fiscal-retry")->assertOk();
        $this->getJson("/api/v1/invoices/{$invoice['id']}")->assertJsonPath('fiscal_status', 'ACCEPTED');

        $tab = $this->getJson('/api/v1/fiscal/contingency')->assertOk()->json();
        $this->assertSame(['CONTINGENCY', 1, $receipt['invoice_number'], 'fiscal.contingency_start', 'Caída del proveedor'], [
            $tab['mode'], $tab['pending_count'], $tab['invoices'][0]['invoice_number'], $tab['log'][0]['action'], $tab['log'][0]['metadata']['note'],
        ]);
        $this->assertSame(1, $this->getJson('/api/v1/fiscal/summary')->json('contingency'));

        // Al restablecer, lo pendiente sale hacia SUNAT por el proveedor y vuelve la serie normal.
        $restored = $this->postJson('/api/v1/fiscal/contingency/end')->assertOk()->json();
        $this->assertSame(['ONLINE', 0], [$restored['mode'], $restored['pending_count']]);
        $this->assertTrue(collect(FakeVoidProvider::$sent)->contains(fn ($document) => $document['full_number'] === $receipt['invoice_number'] && $document['contingency'] === true));
        $this->getJson("/api/v1/invoices/{$receipt['id']}")->assertJsonPath('fiscal_status', 'ACCEPTED');
        $this->assertSame('F001-00000002', $this->issued($customer, 100)['invoice_number']);

        // Sin proveedor conectado, restablecer no da nada por enviado: sigue pendiente de informar.
        DB::table('fiscal_configurations')->where('tenant_id', $this->tenantId)->update(['provider_code' => null]);
        $this->postJson('/api/v1/fiscal/contingency/start', ['reason' => 'Sin internet'])->assertOk();
        $offline = $this->issued($customer, 100);
        $this->postJson('/api/v1/fiscal/contingency/end')->assertOk()->assertJsonPath('pending_count', 1);
        $this->getJson("/api/v1/invoices/{$offline['id']}")->assertJsonPath('fiscal_status', 'CONTINGENCY')->assertJsonPath('invoice_number', '0001-00000002');
    }

    public function test_a_void_goes_through_the_provider_and_only_within_its_conditions(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $customer = $this->makeCustomer();

        // Sin proveedor no hay baja: nada está aceptado por SUNAT.
        $unsent = $this->issued($customer, 100);
        $this->assertNotNull($unsent['void_blocked']);
        $this->postJson("/api/v1/invoices/{$unsent['id']}/void", ['reason' => 'Error'])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->connectProvider();
        // Un cobro aplicado a lo que ya estaba emitido (antes de emitir el comprobante que se dará de baja).
        $paid = $this->issued($customer, 100);
        $this->postJson('/api/v1/payments', ['customer_id' => $customer, 'amount' => 118, 'currency' => 'PEN', 'payment_method' => 'EFECTIVO', 'auto_allocate' => true])->assertCreated();
        $invoice = $this->issued($customer, 1000);
        $detail = $this->getJson("/api/v1/invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['ACCEPTED', null], [$detail['fiscal_status'], $detail['void_blocked']]);
        $this->assertTrue($this->getJson('/api/v1/fiscal/settings')->json('provider.supports_void'));
        $this->postJson("/api/v1/invoices/{$invoice['id']}/void", [])->assertStatus(422)->assertJsonValidationErrors('reason');

        // Con un cobro aplicado, o pasados 7 días, se corrige con nota de crédito.
        $blockedByPayment = collect([$unsent, $paid])->first(fn ($row) => (float) DB::table('receivables')->where('invoice_id', $row['id'])->value('balance_amount') < (float) $row['total']);
        $this->postJson("/api/v1/invoices/{$blockedByPayment['id']}/void", ['reason' => 'Error'])->assertStatus(422)->assertJsonValidationErrors('status');
        $old = $this->issued($customer, 300);
        $this->travel(8)->days();
        $this->assertStringContainsString('7 días', $this->postJson("/api/v1/invoices/{$old['id']}/void", ['reason' => 'Error'])->assertStatus(422)->json('errors.status.0'));
        $this->travelBack();

        // Rechazada por SUNAT: el comprobante sigue emitido y se puede volver a pedir.
        FakeVoidProvider::$answer = 'reject';
        $rejected = $this->postJson("/api/v1/invoices/{$invoice['id']}/void", ['reason' => 'Datos del cliente errados'])->assertCreated()->json();
        $this->assertSame(['Datos del cliente errados', $invoice['full_number'] ?? $invoice['invoice_number']], [FakeVoidProvider::$voids[0]['reason'], FakeVoidProvider::$voids[0]['document']['full_number']]);
        $after = $this->getJson("/api/v1/invoices/{$invoice['id']}")->json();
        $this->assertSame(['ISSUED', 'REJECTED', null], [$after['status'], $after['void_request']['status'], $after['void_blocked']]);

        // Aceptada: queda anulado y su cuenta por cobrar, sin saldo.
        FakeVoidProvider::$answer = 'accept';
        $this->postJson("/api/v1/invoices/{$invoice['id']}/void", ['reason' => 'Datos del cliente errados'])->assertCreated();
        $voided = $this->getJson("/api/v1/invoices/{$invoice['id']}")->json();
        $this->assertSame(['VOID', 'ACCEPTED', 'RA-0001', 'CANCELLED'], [$voided['status'], $voided['void_request']['status'], $voided['void_request']['ticket'], $voided['receivable']['status']]);
        $this->assertEquals(0, $voided['receivable']['balance_amount']);
        $this->assertSame('VOID', collect($voided['receivable']['movements'])->last()['movement_type']);
        $this->postJson("/api/v1/invoices/{$invoice['id']}/void", ['reason' => 'Otra'])->assertStatus(422);
        $this->postJson("/api/v1/invoices/{$invoice['id']}/credit-note", ['reason' => 'Otra'])->assertStatus(422);

        $list = $this->getJson('/api/v1/fiscal/voids')->assertOk()->json();
        $this->assertSame(['ACCEPTED', 'REJECTED'], array_column($list, 'status'));
        $this->assertSame(2, $this->getJson('/api/v1/fiscal/summary')->json('voids'));
        // La base: una sola baja viva por comprobante, siempre con motivo, y no se borran.
        $this->assertDatabaseRejects(fn () => DB::table('fiscal_void_requests')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'invoice_id' => $invoice['id'], 'invoice_number' => 'X', 'reason' => 'Doble', 'status' => 'PENDING']));
        $this->assertDatabaseRejects(fn () => DB::table('fiscal_void_requests')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'invoice_id' => $old['id'], 'invoice_number' => 'X', 'reason' => ' ', 'status' => 'PENDING']));
        $this->assertDatabaseRejects(fn () => DB::table('fiscal_void_requests')->where('invoice_id', $invoice['id'])->delete());

        // El proveedor no responde: la baja queda rechazada con ese motivo y el comprobante, emitido.
        FakeVoidProvider::$answer = 'fail';
        $fresh = $this->issued($customer, 400);
        $this->postJson("/api/v1/invoices/{$fresh['id']}/void", ['reason' => 'Error de emisión'])->assertCreated();
        $failed = $this->getJson("/api/v1/invoices/{$fresh['id']}")->json();
        $this->assertSame(['ISSUED', 'REJECTED'], [$failed['status'], $failed['void_request']['status']]);
        $this->assertStringContainsString('No se pudo comunicar', $failed['void_request']['response']);

        foreach (DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'like', 'invoice.void%')->distinct()->pluck('action') as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS, "Falta el texto de {$action} en AuditCatalog");
        }
    }

    public function test_the_exchange_rate_comes_from_sunat_and_nothing_is_saved_if_sunat_fails(): void
    {
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $today = now()->format('d/m/Y');
        $published = fn (string $body, int $status = 200) => Http::fake(['*tipoCambio*' => Http::response($body, $status)]);

        // SUNAT no responde, responde con error o con otra cosa: no se guarda nada.
        Http::fake(['*tipoCambio*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('sin red')]);
        $this->postJson('/api/v1/exchange-rates/sync')->assertStatus(422)->assertJsonValidationErrors('sunat');
        foreach ([['', 503], ['<html>Mantenimiento</html>', 200], ["{$today}|0.000|0.000|", 200], ['01/01/2020|3.400|3.410|', 200]] as [$body, $status]) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            $published($body, $status);
            $this->postJson('/api/v1/exchange-rates/sync')->assertStatus(422)->assertJsonValidationErrors('sunat');
        }
        $this->assertSame(0, DB::table('exchange_rates')->where('tenant_id', $this->tenantId)->count());

        // Lo publicado entra con su fecha y como fuente SUNAT.
        Http::swap(new \Illuminate\Http\Client\Factory());
        $published("{$today}|3.434|3.448|\n");
        $this->postJson('/api/v1/exchange-rates/sync')->assertOk()->assertJsonPath('unchanged', false)
            ->assertJsonPath('rate.source', 'SUNAT')->assertJsonPath('rate.buy', '3.4340')->assertJsonPath('rate.sell', '3.4480')->assertJsonPath('rate.rate_date', now()->toDateString());
        // Otra vez el mismo día: ya estaba al día, sin duplicar ni volver a auditar.
        $this->postJson('/api/v1/exchange-rates/sync')->assertOk()->assertJsonPath('unchanged', true);
        $this->assertSame(1, DB::table('exchange_rates')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'exchange_rate.sync')->count());
        $this->getJson('/api/v1/exchange-rates/current')->assertOk()->assertJsonPath('sell', '3.4480');

        // Uno por día: corrige el que se registró a mano ese día, y queda el antes y el después.
        $this->postJson('/api/v1/exchange-rates', ['date' => now()->toDateString(), 'buy' => 3.5, 'sell' => 3.6])->assertOk()->assertJsonPath('source', 'Manual');
        $this->postJson('/api/v1/exchange-rates/sync')->assertOk()->assertJsonPath('unchanged', false)->assertJsonPath('rate.sell', '3.4480');
        $this->assertSame(1, DB::table('exchange_rates')->where('tenant_id', $this->tenantId)->count());
        $audit = DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'exchange_rate.sync')->orderByDesc('created_at')->get()
            ->first(fn ($row) => $row->before_data !== null);
        $this->assertEquals(['buy' => 3.5, 'sell' => 3.6, 'source' => 'Manual'], json_decode($audit->before_data, true));
        $this->assertArrayHasKey('exchange_rate.sync', AuditCatalog::ACTIONS);

        // Pide el permiso de registrar el tipo de cambio.
        $this->as($this->makeUser('Gerencia', ['fiscal.view']));
        $this->postJson('/api/v1/exchange-rates/sync')->assertStatus(403);
    }

    public function test_configuring_is_a_separate_permission_from_exchange_rates(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        // Quien solo registra el tipo de cambio ve la pantalla, pero no la configura.
        $rates = $this->makeUser('Tipo de cambio', ['fiscal.view', 'fiscal.manage', 'billing.view', 'billing.approve', 'receivables.view']);
        $viewer = $this->makeUser('Operaciones', ['billing.view']);
        $customer = $this->makeCustomer();

        $this->as($finance);
        $this->putJson('/api/v1/fiscal/settings', self::SETTINGS)->assertOk();
        $invoice = $this->issued($customer, 1000);
        $draft = $this->draft($customer, 1000);

        $this->as($viewer);
        $this->getJson('/api/v1/fiscal/settings')->assertStatus(403);
        $this->getJson('/api/v1/fiscal/summary')->assertStatus(403);

        $this->as($rates);
        $this->getJson('/api/v1/fiscal/settings')->assertOk();
        $this->getJson('/api/v1/fiscal/contingency')->assertOk();
        $this->getJson('/api/v1/fiscal/voids')->assertOk();
        $this->putJson('/api/v1/fiscal/settings', self::SETTINGS)->assertStatus(403);
        $this->putJson('/api/v1/fiscal/series', [])->assertStatus(403);
        $this->postJson('/api/v1/fiscal/contingency/start', ['reason' => 'X'])->assertStatus(403);
        // Decidir la detracción del borrador y registrar la constancia tienen su permiso.
        $this->putJson("/api/v1/invoices/{$draft['id']}/withholding", ['mode' => 'NONE'])->assertStatus(403);
        $this->postJson("/api/v1/invoices/{$invoice['id']}/withholding-receipt", ['number' => '1'])->assertStatus(403);

        // Otra empresa no ve ni toca esta configuración.
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas de otra empresa', self::FINANCE));
        $this->getJson('/api/v1/fiscal/settings')->assertOk()->assertJsonPath('detraction.enabled', false)->assertJsonPath('pending.detractions.count', 0);
        $this->postJson("/api/v1/invoices/{$invoice['id']}/withholding-receipt", ['number' => '1'])->assertStatus(404);
        $this->postJson("/api/v1/invoices/{$invoice['id']}/void", ['reason' => 'X'])->assertStatus(404);
    }
}

/** Proveedor de mentira que además presenta comunicaciones de baja. */
class FakeVoidProvider implements FiscalVoidProvider
{
    public static array $sent = [];

    public static array $voids = [];

    /** accept, reject o fail (no logra comunicarse); vale para las bajas. */
    public static string $answer = 'accept';

    public function name(): string
    {
        return 'Proveedor de prueba';
    }

    public function send(array $document, array $settings): FiscalResult
    {
        self::$sent[] = $document;

        return FiscalResult::accepted(code: '0', hash: str_repeat('a', 64), cdr: 'R-'.$document['full_number']);
    }

    public function void(array $document, string $reason, array $settings): FiscalResult
    {
        self::$voids[] = ['document' => $document, 'reason' => $reason];

        return match (self::$answer) {
            'reject' => FiscalResult::rejected('El comprobante no puede darse de baja', '2324'),
            'fail' => throw new \RuntimeException('Tiempo de espera agotado'),
            default => FiscalResult::accepted('Baja aceptada por SUNAT.', 'RA-0001'),
        };
    }
}
