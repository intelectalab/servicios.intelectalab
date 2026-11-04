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
 * Finanzas, servicios recurrentes: la instancia nace con lo pactado al activar el servicio, cada
 * periodo vencido deja un solo evento facturable, lo que falla se reintenta con la misma clave,
 * renovar conserva la vigencia anterior; permisos, exportar y alcance.
 */
class FinanceRecurringTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MODULES = ['ORDENES_TRABAJO', 'CONTRATOS', 'FINANZAS', 'COMPRAS', 'CONTABILIDAD'];

    private const FINANCE = ['recurring.view', 'recurring.create', 'recurring.manage', 'recurring.export', 'billing.view', 'contracts.view', 'contracted_services.view'];

    /**
     * Instancia recurrente de un servicio contratado activo (2 × 380 sin IGV al mes).
     *
     * @return array{0: string, 1: string} instancia y servicio contratado
     */
    private function recurring(array $data = [], ?User $seller = null, ?string $branchId = null, ?string $customerId = null): array
    {
        $contract = $this->makeContract($seller, branchId: $branchId, customerId: $customerId);
        $contracted = $this->makeContracted(['service_id' => $this->makeService('Soporte mensual', true), 'recurring' => true, 'branch_id' => $branchId], $contract, $seller);
        $id = (string) Str::uuid();
        DB::table('recurring_service_instances')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'contracted_service_id' => $contracted, 'customer_id' => $contract[1],
            'status' => 'ACTIVE', 'frequency' => 'MONTHLY', 'interval_value' => 1, 'next_cycle_date' => now()->toDateString(),
            'unit_price' => 380, 'quantity' => 2, 'currency' => 'PEN',
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'owner_user_id' => $seller?->id, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
            ...$data,
        ]);

        return [$id, $contracted];
    }

    private function cycles(?string $instanceId = null): array
    {
        return $this->postJson('/api/v1/recurring/run-cycles', $instanceId ? ['instance_id' => $instanceId] : [])->assertOk()->json();
    }

    public function test_activating_a_recurring_service_opens_its_instance_with_what_was_agreed(): void
    {
        $this->makeTenant(self::MODULES);
        $seller = $this->makeUser('Vendedora', ['contracts.view']);
        $operations = $this->makeUser('Operaciones', ['contracted_services.view', 'contracted_services.manage']);
        $branch = $this->makeBranch('Lima');
        $contract = $this->makeContract($seller, branchId: $branch);
        DB::table('contracts')->where('id', $contract[0])->update(['currency' => 'USD']);
        $contracted = $this->makeContracted(['service_id' => $this->makeService('Soporte mensual', true), 'recurring' => true, 'status' => 'PENDING', 'branch_id' => $branch], $contract);

        $this->as($operations);
        $this->postJson("/api/v1/contracted-services/{$contracted}/activate")->assertOk()->assertJsonPath('recurring_instance.status', 'ACTIVE');

        $instance = DB::table('recurring_service_instances')->where('contracted_service_id', $contracted)->first();
        $this->assertSame([$contract[1], 'USD', 'MONTHLY', $branch, $seller->id], [$instance->customer_id, $instance->currency, $instance->frequency, $instance->branch_id, $instance->owner_user_id]);
        $this->assertEquals([380, 2], [$instance->unit_price, $instance->quantity]);
        $this->assertSame([now()->toDateString(), now()->addYear()->toDateString()], [$instance->start_date, $instance->end_date]);
        // La ficha del contrato muestra lo pactado por ciclo, con IGV.
        $this->as($this->makeUser('Gerencia', ['contracts.view']));
        $this->getJson("/api/v1/contracts/{$contract[0]}")->assertOk()->assertJsonPath('recurring_instances.0.amount', '896.80');
    }

    public function test_a_due_cycle_leaves_one_billable_event_and_is_not_processed_twice(): void
    {
        $this->makeTenant(self::MODULES);
        $seller = $this->makeUser('Vendedora', ['contracts.view']);
        $branch = $this->makeBranch('Lima');
        [$id] = $this->recurring([], $seller, $branch);
        [$future] = $this->recurring(['next_cycle_date' => now()->addDays(10)->toDateString()]);
        [$suspended] = $this->recurring(['status' => 'SUSPENDED']);
        $this->as($this->makeUser('Finanzas', self::FINANCE));

        $this->getJson('/api/v1/recurring/summary')->assertOk()->assertJsonPath('due', 1);
        $period = now()->format('Y-m');
        $summary = $this->cycles();
        // La que aún no vence ni se mira; la suspendida se omite.
        $this->assertSame([1, 0, 1, 0], [$summary['created'], $summary['skipped_idem'], $summary['skipped_status'], $summary['failed']]);

        $cycle = DB::table('recurring_service_cycles')->where('recurring_instance_id', $id)->first();
        $this->assertSame([$period, 'COMPLETED', 1, "{$this->tenantId}:{$id}:{$period}"], [$cycle->period, $cycle->status, $cycle->attempts, $cycle->idempotency_key]);
        $event = DB::table('billable_events')->where('id', $cycle->billable_event_id)->first();
        $this->assertSame(['RECURRING_CYCLE', $cycle->id, 'PENDING', 'PEN', "Soporte mensual · periodo {$period}", $branch, $seller->id], [
            $event->source_type, $event->source_id, $event->status, $event->currency, $event->description, $event->branch_id, $event->owner_user_id,
        ]);
        // 2 × 380 sin IGV.
        $this->assertEquals([760, 896.80, 896.80], [$event->base, $event->amount, $cycle->amount]);
        $this->assertSame($summary['event_ids'], [$event->id]);
        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), DB::table('recurring_service_instances')->where('id', $id)->value('next_cycle_date'));

        // Otra pasada no duplica nada.
        $again = $this->cycles();
        $this->assertSame([0, 0], [$again['created'], $again['failed']]);
        $this->assertSame(1, DB::table('billable_events')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(0, DB::table('recurring_service_cycles')->whereIn('recurring_instance_id', [$future, $suspended])->count());

        // La base no admite dos ciclos para el mismo periodo, ni un segundo evento para el mismo ciclo, ni borrarlos.
        $this->assertDatabaseRejects(fn () => DB::table('recurring_service_cycles')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'recurring_instance_id' => $id, 'period' => $period,
            'cycle_start' => now()->addYear()->toDateString(), 'cycle_end' => now()->addYear()->toDateString(), 'idempotency_key' => $cycle->idempotency_key,
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('billable_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'source_type' => 'RECURRING_CYCLE', 'source_id' => $cycle->id,
            'customer_id' => $event->customer_id, 'description' => 'Duplicado', 'base' => 1, 'amount' => 1.18, 'idempotency_key' => 'otra',
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('recurring_service_cycles')->where('id', $cycle->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('recurring_service_instances')->where('id', $id)->delete());

        // En la ficha: su ciclo con el estado del evento, y el movimiento en el historial.
        $detail = $this->getJson("/api/v1/recurring/{$id}")->assertOk()->json();
        $this->assertSame([1, 'COMPLETED', 'PENDING', $period], [$detail['cycles_count'], $detail['cycles'][0]['status'], $detail['cycles'][0]['billable_event_status'], $detail['cycles'][0]['period']]);
        $this->assertContains('recurring.cycle_completed', array_column($detail['history'], 'action'));
        foreach (array_unique(array_column($detail['history'], 'action')) as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS, "Falta el texto de {$action} en AuditCatalog");
        }
    }

    public function test_a_late_instance_catches_up_and_expires_at_the_end_of_its_term(): void
    {
        $this->makeTenant(self::MODULES);
        // Tres periodos atrasados y la vigencia termina antes del siguiente.
        [$id] = $this->recurring([
            'next_cycle_date' => now()->startOfMonth()->subMonthsNoOverflow(2)->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);
        $this->as($this->makeUser('Finanzas', self::FINANCE));

        $this->assertSame(3, $this->cycles()['created']);
        $instance = DB::table('recurring_service_instances')->where('id', $id)->first();
        $this->assertSame(['EXPIRED', now()->startOfMonth()->addMonthNoOverflow()->toDateString()], [$instance->status, $instance->next_cycle_date]);
        $this->assertSame(3, DB::table('recurring_service_cycles')->where('recurring_instance_id', $id)->where('status', 'COMPLETED')->distinct()->count('period'));
        // Vencida, ya no genera ciclos.
        $this->assertSame([0, 1], [$this->cycles($id)['created'], $this->cycles($id)['skipped_status']]);
        $this->getJson('/api/v1/recurring?status=EXPIRED')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.cycles_count', 3);
    }

    public function test_a_failed_cycle_is_retried_with_the_same_key(): void
    {
        $this->makeTenant(self::MODULES);
        $customer = $this->makeCustomer('Cliente Sin RUC SAC');
        $partyId = DB::table('customers')->where('id', $customer)->value('party_id');
        DB::table('organizations')->where('party_id', $partyId)->update(['tax_identifier' => null]);
        [$id] = $this->recurring(customerId: $customer);
        $this->as($this->makeUser('Finanzas', self::FINANCE));

        $this->assertSame([0, 1], [$this->cycles()['created'], $this->cycles()['failed']]);
        $failed = DB::table('recurring_service_cycles')->where('recurring_instance_id', $id)->get();
        // Dos intentos sobre la misma fila; la instancia no avanza.
        $this->assertCount(1, $failed);
        $this->assertSame(['FAILED', 2, 'Cliente sin datos fiscales', null], [$failed[0]->status, $failed[0]->attempts, $failed[0]->error, $failed[0]->billable_event_id]);
        $this->assertSame(now()->toDateString(), DB::table('recurring_service_instances')->where('id', $id)->value('next_cycle_date'));
        $this->assertSame(0, DB::table('billable_events')->where('tenant_id', $this->tenantId)->count());

        // Corregido el cliente, el mismo ciclo se completa.
        DB::table('organizations')->where('party_id', $partyId)->update(['tax_identifier' => '20123456789']);
        $this->assertSame(1, $this->cycles()['created']);
        $done = DB::table('recurring_service_cycles')->where('recurring_instance_id', $id)->get();
        $this->assertCount(1, $done);
        $this->assertSame([$failed[0]->id, 'COMPLETED', 3, null], [$done[0]->id, $done[0]->status, $done[0]->attempts, $done[0]->error]);
        $this->assertNotNull($done[0]->billable_event_id);
    }

    public function test_one_instance_can_be_processed_ahead_one_period_at_a_time(): void
    {
        $this->makeTenant(self::MODULES);
        [$soon] = $this->recurring(['next_cycle_date' => now()->addDays(20)->toDateString()]);
        [$far] = $this->recurring(['next_cycle_date' => now()->addDays(60)->toDateString()]);
        $this->as($this->makeUser('Finanzas', self::FINANCE));

        // El proceso general no adelanta nada.
        $this->assertSame(0, $this->cycles()['created']);
        $this->assertSame(1, $this->cycles($soon)['created']);
        $this->assertSame(now()->addDays(20)->addMonthNoOverflow()->toDateString(), DB::table('recurring_service_instances')->where('id', $soon)->value('next_cycle_date'));
        // Más allá de los 40 días no se adelanta.
        $this->assertSame(0, $this->cycles($far)['created']);
        $this->postJson('/api/v1/recurring/run-cycles', ['instance_id' => (string) Str::uuid()])->assertStatus(404);
    }

    public function test_renewing_keeps_the_previous_term_and_a_new_price_does_not_touch_past_cycles(): void
    {
        $this->makeTenant(self::MODULES);
        [$id] = $this->recurring();
        [$expired, $contracted] = $this->recurring(['status' => 'EXPIRED', 'end_date' => now()->subDays(5)->toDateString(), 'next_cycle_date' => now()->subDays(40)->toDateString()]);
        [$cancelled] = $this->recurring(['status' => 'CANCELLED']);
        $this->as($this->makeUser('Finanzas', self::FINANCE));
        $this->cycles($id);

        $this->postJson("/api/v1/recurring/{$id}/renew", [])->assertStatus(422)->assertJsonValidationErrors('months');
        $this->postJson("/api/v1/recurring/{$id}/renew", ['months' => 0])->assertStatus(422)->assertJsonValidationErrors('months');
        $this->postJson("/api/v1/recurring/{$id}/renew", ['months' => 12, 'price' => 0])->assertStatus(422)->assertJsonValidationErrors('price');
        $this->postJson("/api/v1/recurring/{$cancelled}/renew", ['months' => 12])->assertStatus(422)->assertJsonValidationErrors('status');

        $end = now()->addYear();
        $renewed = $this->postJson("/api/v1/recurring/{$id}/renew", ['months' => 12, 'price' => 500])->assertOk()->json();
        $this->assertSame([now()->subMonth()->toDateString(), $end->copy()->addMonthsNoOverflow(12)->toDateString(), 'ACTIVE'], [$renewed['start_date'], $renewed['end_date'], $renewed['status']]);
        $this->assertEquals([500, 1180], [$renewed['unit_price'], $renewed['amount']]);
        // La vigencia anterior queda, con su precio.
        $this->assertCount(1, $renewed['terms']);
        $this->assertEquals([380, now()->subMonth()->toDateString(), $end->toDateString()], [$renewed['terms'][0]['unit_price'], $renewed['terms'][0]['start'], $renewed['terms'][0]['end']]);
        // El ciclo ya procesado conserva su importe.
        $this->assertEquals(896.80, $renewed['cycles'][0]['amount']);
        $this->assertContains('recurring.renew', array_column($renewed['history'], 'action'));
        // Otra renovación: su tramo empieza donde terminó el anterior.
        $twice = $this->postJson("/api/v1/recurring/{$id}/renew", ['months' => 6])->assertOk()->json();
        $this->assertEquals([500, $end->copy()->addDay()->toDateString(), $end->copy()->addMonthsNoOverflow(12)->toDateString()], [$twice['terms'][1]['unit_price'], $twice['terms'][1]['start'], $twice['terms'][1]['end']]);
        $this->assertSame($end->copy()->addMonthsNoOverflow(18)->toDateString(), $twice['end_date']);
        // Una vigencia cerrada no se edita ni se borra.
        $this->assertDatabaseRejects(fn () => DB::table('recurring_service_prices')->where('recurring_instance_id', $id)->update(['amount' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('recurring_service_prices')->where('recurring_instance_id', $id)->delete());

        // Una vencida vuelve a estar activa desde hoy… si su servicio contratado sigue activo.
        DB::table('contracted_services')->where('id', $contracted)->update(['status' => 'SUSPENDED', 'status_reason' => 'Pausa']);
        $this->postJson("/api/v1/recurring/{$expired}/renew", ['months' => 6])->assertStatus(422)->assertJsonValidationErrors('status');
        DB::table('contracted_services')->where('id', $contracted)->update(['status' => 'ACTIVE']);
        $back = $this->postJson("/api/v1/recurring/{$expired}/renew", ['months' => 6])->assertOk()->json();
        $this->assertSame(['ACTIVE', now()->toDateString(), now()->subMonth()->toDateString(), now()->addMonthsNoOverflow(6)->toDateString()], [$back['status'], $back['next_cycle_date'], $back['start_date'], $back['end_date']]);
    }

    public function test_suspending_and_cancelling_need_a_reason_and_reactivating_needs_the_service_active(): void
    {
        $this->makeTenant(self::MODULES);
        [$id, $contracted] = $this->recurring();
        $this->as($this->makeUser('Finanzas', self::FINANCE));

        $this->postJson("/api/v1/recurring/{$id}/suspend", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/recurring/{$id}/suspend", ['reason' => 'Cliente en mora'])->assertOk()->assertJsonPath('status', 'SUSPENDED')->assertJsonPath('status_reason', 'Cliente en mora');
        $this->postJson("/api/v1/recurring/{$id}/suspend", ['reason' => 'Otra vez'])->assertStatus(422)->assertJsonValidationErrors('status');
        // Suspendida no genera ciclos.
        $this->assertSame([0, 1], [$this->cycles()['created'], $this->cycles()['skipped_status']]);

        DB::table('contracted_services')->where('id', $contracted)->update(['status' => 'SUSPENDED', 'status_reason' => 'Pausa']);
        $this->postJson("/api/v1/recurring/{$id}/reactivate")->assertStatus(422)->assertJsonValidationErrors('status');
        DB::table('contracted_services')->where('id', $contracted)->update(['status' => 'ACTIVE']);
        $this->postJson("/api/v1/recurring/{$id}/reactivate")->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('status_reason', null);

        $this->postJson("/api/v1/recurring/{$id}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/recurring/{$id}/cancel", ['reason' => 'Fin del servicio'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->postJson("/api/v1/recurring/{$id}/reactivate")->assertStatus(422);
        $this->assertDatabaseRejects(fn () => DB::table('recurring_service_instances')->where('id', $id)->update(['status' => 'BORRADA']));
    }

    public function test_the_list_filters_sorts_and_warns_about_renewals(): void
    {
        $this->makeTenant(self::MODULES);
        [$soon] = $this->recurring(['end_date' => now()->addDays(30)->toDateString(), 'unit_price' => 100, 'quantity' => 1], customerId: $this->makeCustomer('Alfa SAC'));
        $this->recurring(['frequency' => 'YEARLY', 'next_cycle_date' => now()->addDays(90)->toDateString()], customerId: $this->makeCustomer('Beta SAC'));
        $this->recurring(['status' => 'CANCELLED', 'end_date' => now()->addDays(10)->toDateString()], customerId: $this->makeCustomer('Gama SAC'));
        $this->as($this->makeUser('Finanzas', self::FINANCE));

        $this->getJson('/api/v1/recurring')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/v1/recurring?status=ACTIVE')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/recurring?search=beta')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.frequency', 'YEARLY');
        $this->getJson('/api/v1/recurring?search=soporte')->assertOk()->assertJsonPath('total', 3);
        $rows = $this->getJson('/api/v1/recurring?sort=amount&dir=asc')->assertOk()->json('data');
        $this->assertEquals([118, 896.80, 896.80], array_column($rows, 'amount'));
        $this->assertSame([true, true, false], [$rows[0]['due'], $rows[0]['renewal_due'], $rows[0]['id'] !== $soon]);
        $this->getJson('/api/v1/recurring?filters='.urlencode(json_encode([['id' => 'service', 'type' => 'text', 'operator' => 'contains', 'value' => 'alfa']])))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $soon);

        // Solo las activas que vencen en los próximos 45 días.
        $summary = $this->getJson('/api/v1/recurring/summary')->assertOk()->json();
        $this->assertSame([1, 45, $soon], [$summary['alerts_total'], $summary['alert_days'], $summary['alerts'][0]['id']]);
    }

    public function test_each_action_asks_for_its_own_permission_and_exporting_is_a_separate_one(): void
    {
        $this->makeTenant(self::MODULES);
        [$id] = $this->recurring();
        $viewer = $this->makeUser('Gerencia', ['recurring.view', 'recurring.export']);
        $runner = $this->makeUser('Procesa', ['recurring.view', 'recurring.create']);
        $outsider = $this->makeUser('Técnico', ['work_orders.view']);

        $this->as($outsider);
        $this->getJson('/api/v1/recurring')->assertStatus(403);
        $this->getJson("/api/v1/recurring/{$id}")->assertStatus(403);

        $this->as($viewer);
        $this->getJson("/api/v1/recurring/{$id}")->assertOk();
        $this->postJson('/api/v1/recurring/run-cycles')->assertStatus(403);
        $this->postJson("/api/v1/recurring/{$id}/renew", ['months' => 12])->assertStatus(403);
        $this->postJson("/api/v1/recurring/{$id}/suspend", ['reason' => 'X'])->assertStatus(403);
        $this->getJson('/api/v1/recurring?export=1')->assertOk();
        $this->postJson('/api/v1/exports', ['resource' => 'recurring', 'format' => 'xlsx', 'rows' => 1])->assertOk();

        $this->as($runner);
        $this->postJson('/api/v1/recurring/run-cycles')->assertOk()->assertJsonPath('created', 1);
        $this->postJson("/api/v1/recurring/{$id}/cancel", ['reason' => 'X'])->assertStatus(403);
        $this->getJson('/api/v1/recurring?export=1')->assertStatus(403);
        $this->postJson('/api/v1/exports', ['resource' => 'recurring', 'format' => 'csv', 'rows' => 1])->assertStatus(403);

        // Sin el módulo Finanzas, el permiso no vale.
        $this->asPlatformFixture(fn () => DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'FINANZAS')->delete());
        $this->getJson('/api/v1/recurring')->assertStatus(403)->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_scope_limits_what_each_role_reaches_and_other_companies_do_not_exist(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $lima = $this->makeBranch('Lima');
        $cusco = $this->makeBranch('Cusco');
        // Ventas: lo propio (sin alcance definido). Operaciones: su sede.
        $ana = $this->makeUser('Ana (Ventas)', ['recurring.view', 'recurring.create'], null);
        $operations = $this->makeUser('Operaciones', ['recurring.view'], 'BRANCH', $lima);
        $luis = $this->makeUser('Luis (Ventas)', ['recurring.view'], null);
        [$ofAna] = $this->recurring([], $ana, $cusco);
        [$ofLuis] = $this->recurring([], $luis, $lima);

        $this->as($ana);
        $this->getJson('/api/v1/recurring')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ofAna);
        $this->getJson("/api/v1/recurring/{$ofLuis}")->assertStatus(404);
        $this->getJson('/api/v1/recurring/summary')->assertOk()->assertJsonPath('due', 1);
        // Procesar ciclos alcanza solo lo suyo; lo ajeno "no existe".
        $this->postJson('/api/v1/recurring/run-cycles', ['instance_id' => $ofLuis])->assertStatus(404);
        $this->assertSame(1, $this->cycles()['created']);
        $this->assertSame(0, DB::table('recurring_service_cycles')->where('recurring_instance_id', $ofLuis)->count());

        $this->as($operations);
        $this->getJson('/api/v1/recurring')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ofLuis);

        $this->as($finance);
        $this->getJson('/api/v1/recurring')->assertOk()->assertJsonPath('total', 2);

        // Otra empresa: ni en el listado ni por su identificador.
        $this->makeTenant(self::MODULES);
        $this->as($this->makeUser('Finanzas de otra empresa', self::FINANCE));
        $this->getJson('/api/v1/recurring')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/recurring/{$ofAna}")->assertStatus(404);
        $this->postJson("/api/v1/recurring/{$ofAna}/renew", ['months' => 12])->assertStatus(404);
    }

    public function test_the_scheduled_command_processes_due_cycles_of_every_company(): void
    {
        $this->makeTenant(self::MODULES);
        [$id] = $this->recurring();

        $this->artisan('recurring:run-cycles')->assertSuccessful();
        DB::statement("SET app.tenant_id = '{$this->tenantId}'");
        $this->assertSame('COMPLETED', DB::table('recurring_service_cycles')->where('recurring_instance_id', $id)->value('status'));
    }
}
