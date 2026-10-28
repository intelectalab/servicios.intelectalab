<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Límite de crédito del cliente: la deuda son sus cuentas por cobrar pendientes; al aprobar una
 * cotización o activar un contrato que lo supera, la regla de la empresa pide motivo ("Solo
 * avisar", por defecto) o bloquea salvo con `credit.approve`. Emitir un comprobante solo avisa.
 */
class CreditLimitTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const MODULES = ['SERVICIOS', 'COTIZACIONES', 'CONTRATOS', 'FINANZAS', 'COMPRAS', 'CONTABILIDAD'];

    private const FINANCE = ['billing.view', 'billing.create', 'billing.manage', 'billing.approve', 'receivables.view', 'core.view'];

    private const SELLER = ['core.view', 'quotations.view', 'quotations.manage', 'contracts.view', 'contracts.create'];

    /** Quien aprueba cotizaciones y activa contratos, sin ver cuentas por cobrar. */
    private const APPROVER = ['core.view', 'quotations.view', 'quotations.approve', 'contracts.view', 'contracts.approve'];

    /** Las cotizaciones de toda la empresa (el alcance de las pruebas de operaciones no las incluye). */
    private function seeAllQuotations(User $user): void
    {
        $roleId = DB::table('user_roles')->where('user_id', $user->id)->value('role_id');
        DB::table('role_scopes')->insert(['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'resource' => 'quotations', 'scope' => 'TENANT']);
    }

    /** Factura emitida al cliente por esa base sin IGV: su total queda como deuda. */
    private function debt(User $finance, string $customerId, float $base): array
    {
        $this->as($finance);
        $event = $this->postJson('/api/v1/billable-events', [
            'customer_id' => $customerId, 'description' => 'Servicio prestado', 'base' => $base, 'currency' => 'PEN',
        ])->assertCreated()->json('id');
        $draft = $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [$event], 'type' => 'INVOICE'])->assertCreated()->json();

        return $this->postJson("/api/v1/invoices/{$draft['id']}/issue")->assertOk()->json();
    }

    private function submitted(User $seller, string $customerId, float $price): string
    {
        $this->as($seller);
        $id = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId, 'valid_until' => now()->addDays(10)->toDateString(),
            'items' => [['description' => 'Mantenimiento', 'quantity' => 1, 'unit_price' => $price]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/v1/quotations/{$id}/submit")->assertOk();

        return $id;
    }

    public function test_approving_over_the_limit_asks_a_reason_or_blocks_by_company_rule(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $seller = $this->makeUser('Ventas', self::SELLER);
        $approver = $this->makeUser('Jefa comercial', self::APPROVER);
        $manager = $this->makeUser('Gerencia', [...self::APPROVER, 'credit.approve', 'receivables.view']);
        $admin = $this->makeUser('Administración', ['tenant.manage']);
        foreach ([$seller, $approver, $manager] as $user) {
            $this->seeAllQuotations($user);
        }
        $customerId = $this->makeCustomer();
        DB::table('customers')->where('id', $customerId)->update(['credit_limit' => 1500, 'preferred_currency' => 'PEN']);
        // Deuda pendiente: 1,180 (1,000 + IGV). Disponible 320.
        $this->debt($finance, $customerId, 1000);

        // Dentro del límite: sin aviso ni motivo.
        // Líneas escritas a mano, sin IGV: el total es el precio.
        $small = $this->submitted($seller, $customerId, 200);
        $this->as($approver);
        $this->getJson("/api/v1/quotations/{$small}")->assertOk()->assertJsonPath('credit', null);
        $this->postJson("/api/v1/quotations/{$small}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');

        // Ya aprobada no es deuda: la siguiente vuelve a medirse solo contra las cuentas por cobrar.
        $large = $this->submitted($seller, $customerId, 500); // 1,180 + 500 = 1,680 > 1,500
        $this->as($approver);
        $detail = $this->getJson("/api/v1/quotations/{$large}")->assertOk()->json('credit');
        // Quien no ve cuentas por cobrar recibe el aviso sin la deuda del cliente.
        $this->assertSame([true, 'WARN', false], [$detail['exceeds'], $detail['mode'], $detail['blocked']]);
        $this->assertArrayNotHasKey('debt', $detail);
        $this->assertStringNotContainsString('1,180', $detail['message']);

        // "Solo avisar": sin motivo no se aprueba; con motivo sí, y queda en la decisión.
        $this->postJson("/api/v1/quotations/{$large}/approve")->assertStatus(422)->assertJsonValidationErrors('credit_reason');
        $this->postJson("/api/v1/quotations/{$large}/approve", ['credit_reason' => 'Cliente con pago comprometido para el viernes'])
            ->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->assertStringContainsString('Sobre el límite de crédito (S/ 180.00 de exceso): Cliente con pago comprometido para el viernes',
            DB::table('quotation_approvals')->where('quotation_id', $large)->value('comments'));

        // Quien ve las cuentas por cobrar, ve los montos.
        $blocked = $this->submitted($seller, $customerId, 500);
        $this->as($manager);
        $this->getJson("/api/v1/quotations/{$blocked}")->assertOk()
            ->assertJsonPath('credit.debt', 1180)->assertJsonPath('credit.excess', 180)->assertJsonPath('credit.limit', 1500);

        // "Bloquear": sin el permiso no se aprueba ni con motivo.
        $this->as($admin);
        $this->putJson('/api/v1/tenant/settings', ['credit_limit_mode' => 'NADA'])->assertStatus(422)->assertJsonValidationErrors('credit_limit_mode');
        $this->putJson('/api/v1/tenant/settings', ['credit_limit_mode' => 'BLOCK'])->assertOk()->assertJsonPath('credit_limit_mode', 'BLOCK');
        $this->as($approver);
        $this->getJson("/api/v1/quotations/{$blocked}")->assertOk()->assertJsonPath('credit.blocked', true);
        $this->postJson("/api/v1/quotations/{$blocked}/approve", ['credit_reason' => 'Lo autorizo'])
            ->assertStatus(422)->assertJsonValidationErrors('credit_limit');
        // Con el permiso, sí, pero también con motivo.
        $this->as($manager);
        $this->getJson("/api/v1/quotations/{$blocked}")->assertOk()->assertJsonPath('credit.blocked', false);
        $this->postJson("/api/v1/quotations/{$blocked}/approve")->assertStatus(422)->assertJsonValidationErrors('credit_reason');
        $this->postJson("/api/v1/quotations/{$blocked}/approve", ['credit_reason' => 'Gerencia autoriza: cliente estratégico'])->assertOk();

        // Un cliente sin límite nunca avisa.
        $free = $this->makeCustomer('Cliente sin límite SAC');
        $this->debt($finance, $free, 50000);
        $other = $this->submitted($seller, $free, 9000);
        $this->as($approver);
        $this->getJson("/api/v1/quotations/{$other}")->assertOk()->assertJsonPath('credit', null);
        $this->postJson("/api/v1/quotations/{$other}/approve")->assertOk();
    }

    public function test_activating_a_contract_is_controlled_and_issuing_only_warns(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $seller = $this->makeUser('Ventas', self::SELLER);
        $approver = $this->makeUser('Jefa comercial', self::APPROVER);
        $manager = $this->makeUser('Gerencia', [...self::APPROVER, 'credit.approve']);
        foreach ([$seller, $approver, $manager] as $user) {
            $this->seeAllQuotations($user);
        }
        $customerId = $this->makeCustomer();
        DB::table('customers')->where('id', $customerId)->update(['credit_limit' => 1000]);

        // Aprobada cuando aún no debía nada; luego se le factura y pasa a deber 1,180.
        $quotation = $this->submitted($seller, $customerId, 300);
        $this->as($approver);
        $this->postJson("/api/v1/quotations/{$quotation}/approve")->assertOk();
        $this->as($seller);
        $contract = $this->postJson("/api/v1/quotations/{$quotation}/contract")->assertSuccessful()->json('id');
        DB::table('contracts')->where('id', $contract)->update(['start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString()]);
        $this->debt($finance, $customerId, 1000);

        $this->as($approver);
        $this->getJson("/api/v1/contracts/{$contract}")->assertOk()->assertJsonPath('credit.exceeds', true)->assertJsonPath('credit.blocked', false);
        $this->postJson("/api/v1/contracts/{$contract}/activate")->assertStatus(422)->assertJsonValidationErrors('credit_reason');

        DB::table('tenants')->where('id', $this->tenantId)->update(['configuration' => json_encode(['credit_limit_mode' => 'BLOCK'])]);
        $this->postJson("/api/v1/contracts/{$contract}/activate", ['credit_reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('credit_limit');
        $this->as($manager);
        $this->postJson("/api/v1/contracts/{$contract}/activate", ['credit_reason' => 'Renovación pactada con el directorio'])
            ->assertOk()->assertJsonPath('status', 'ACTIVE');
        $note = collect($this->getJson("/api/v1/contracts/{$contract}")->json('history'))->firstWhere('action', 'contract.activate')['metadata']['note'];
        $this->assertStringContainsString('Sobre el límite de crédito', $note);
        $this->assertStringContainsString('Renovación pactada con el directorio', $note);

        // Emitir: el borrador avisa, pero se emite igual aunque la empresa bloquee.
        $this->as($finance);
        $event = $this->postJson('/api/v1/billable-events', ['customer_id' => $customerId, 'description' => 'Visita', 'base' => 100, 'currency' => 'PEN'])->json('id');
        $draft = $this->postJson('/api/v1/invoices/from-events', ['event_ids' => [$event], 'type' => 'INVOICE'])->assertCreated()->json('id');
        $this->getJson("/api/v1/invoices/{$draft}")->assertOk()->assertJsonPath('credit.exceeds', true)->assertJsonPath('credit.debt', 1180);
        $this->postJson("/api/v1/invoices/{$draft}/issue")->assertOk()->assertJsonPath('status', 'ISSUED');

        // Ficha del cliente: crédito usado y disponible (con la deuda de las dos facturas).
        $partyId = DB::table('customers')->where('id', $customerId)->value('party_id');
        $this->getJson("/api/v1/parties/{$partyId}")->assertOk()
            ->assertJsonPath('finance.credit.debt', 1298)->assertJsonPath('finance.credit.available', -298)->assertJsonPath('finance.credit.exceeds', true);

        // Panel: aparece en "Requiere tu atención".
        $attention = collect($this->getJson('/api/v1/dashboard')->assertOk()->json('attention'))->firstWhere('key', 'credit_limit');
        $this->assertSame(1, $attention['count']);
    }

    public function test_the_permission_is_assignable_from_roles_whatever_the_plan(): void
    {
        $this->makeTenant(['SERVICIOS']);
        $admin = $this->makeUser('Administración', ['users.view', 'users.manage']);
        $roleId = DB::table('user_roles')->where('user_id', $this->makeUser('Gerencia', ['core.view'])->id)->value('role_id');

        $this->as($admin);
        $codes = collect($this->getJson('/api/v1/roles')->assertOk()->json('permissions'))->pluck('code');
        $this->assertContains('credit.approve', $codes);
        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['core.view', 'credit.approve']])->assertOk();
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $roleId, 'permission_id' => DB::table('permissions')->where('code', 'credit.approve')->value('id'),
        ]);
    }

    public function test_cash_customers_get_invoices_due_the_same_day(): void
    {
        $this->makeTenant(self::MODULES);
        $finance = $this->makeUser('Finanzas', self::FINANCE);
        $cash = $this->makeCustomer('Contado SAC');
        DB::table('customers')->where('id', $cash)->update(['payment_terms_days' => 0]);
        $usual = $this->makeCustomer('Sin plazo SAC');
        DB::table('customers')->where('id', $usual)->update(['payment_terms_days' => null]);

        $this->assertSame(now()->toDateString(), substr((string) $this->debt($finance, $cash, 100)['due_date'], 0, 10));
        $this->assertSame(now()->addDays(30)->toDateString(), substr((string) $this->debt($finance, $usual, 100)['due_date'], 0, 10));
    }
}
