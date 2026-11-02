<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Configuración → Identidad y región (solo el nombre comercial se cambia) y Comercial (vigencia
 * por defecto con la que nace una cotización sin fecha).
 */
class CompanySettingsTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    public function test_identity_shows_the_fiscal_data_and_only_the_trade_name_changes(): void
    {
        $this->makeTenant(['COTIZACIONES']);
        $this->as($this->makeUser('Admin', ['tenant.manage']));

        $this->getJson('/api/v1/tenant/identity')->assertOk()
            ->assertJsonPath('legal_name', 'Empresa de Prueba SAC')->assertJsonPath('currency', 'PEN');
        $this->putJson('/api/v1/tenant/identity', ['trade_name' => 'Prueba Servicios', 'legal_name' => 'Otra SAC'])->assertOk()
            ->assertJsonPath('trade_name', 'Prueba Servicios')->assertJsonPath('legal_name', 'Empresa de Prueba SAC')
            ->assertJsonPath('display_name', 'Prueba Servicios');
        // Es también el nombre con el que se muestra la empresa (cabecera, menú, correos).
        $this->assertSame('Prueba Servicios', DB::table('tenants')->where('id', $this->tenantId)->value('name'));
        $this->assertSame('Prueba Servicios', $this->getJson('/api/v1/me/access')->json('tenant.name'));
        $this->putJson('/api/v1/tenant/identity', ['trade_name' => ''])->assertStatus(422);
        $this->assertSame(1, DB::table('audit_logs')->where('tenant_id', $this->tenantId)->where('action', 'tenant.identity.update')->count());

        $this->as($this->makeUser('Ventas', ['quotations.view']));
        $this->getJson('/api/v1/tenant/identity')->assertForbidden();
        $this->putJson('/api/v1/tenant/identity', ['trade_name' => 'X'])->assertForbidden();
    }

    public function test_a_new_quotation_without_date_takes_the_company_default_validity(): void
    {
        $this->makeTenant(['COTIZACIONES', 'SERVICIOS']);
        $this->as($this->makeUser('Admin', ['tenant.manage', 'quotations.view', 'quotations.manage', 'core.view']));
        $this->getJson('/api/v1/tenant/settings')->assertOk()->assertJsonPath('quotation_validity_days', 15);
        $this->putJson('/api/v1/tenant/settings', ['quotation_validity_days' => 400])->assertStatus(422);
        $this->putJson('/api/v1/tenant/settings', ['quotation_validity_days' => 30])->assertOk()->assertJsonPath('quotation_validity_days', 30);

        $line = [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 18]];
        $auto = $this->postJson('/api/v1/quotations', ['customer_id' => $this->makeCustomer(), 'items' => $line])->assertCreated()->json('valid_until');
        $this->assertSame(now('America/Lima')->addDays(30)->toDateString(), substr($auto, 0, 10));
        $given = $this->postJson('/api/v1/quotations', ['customer_id' => $this->makeCustomer(), 'items' => $line, 'valid_until' => '2027-01-31'])->json('valid_until');
        $this->assertSame('2027-01-31', substr($given, 0, 10));
    }
}
