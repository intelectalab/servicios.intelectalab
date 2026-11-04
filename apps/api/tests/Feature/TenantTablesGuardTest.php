<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\Feature\Concerns\PlatformSession;
use Tests\TestCase;
use Throwable;

/**
 * Empresas, módulos y permisos de los roles frente a la app de las empresas (erp_app), los roles
 * base con los que nace una empresa y el tipo de cambio diario de SUNAT.
 */
class TenantTablesGuardTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;
    use PlatformSession;

    private function assertRejected(callable $attempt, string $message): void
    {
        try {
            DB::transaction($attempt);
            $this->fail('La base aceptó un cambio que debía rechazar.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(QueryException::class, $e, $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function subscribe(array $modules): void
    {
        $this->asPlatform(function () use ($modules) {
            $planId = (string) Str::uuid();
            DB::table('saas_plans')->insert(['id' => $planId, 'code' => 'G-'.Str::random(6), 'name' => 'Plan guarda', 'active' => true,
                'modules' => json_encode(array_fill_keys($modules, true)), 'monthly_price' => 10, 'currency' => 'PEN']);
            DB::table('tenant_subscriptions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'plan_id' => $planId,
                'status' => 'ACTIVE', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function test_the_app_cannot_delete_companies_or_change_their_status_or_another_company(): void
    {
        $this->makeTenant();
        $own = $this->tenantId;
        $this->makeTenant();
        $other = $this->tenantId;
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$own]);

        // Lo propio (nombre, configuración) sí; el estado, el código, otra empresa o borrar, no.
        DB::table('tenants')->where('id', $own)->update(['name' => 'Nuevo Nombre SAC']);
        $this->assertSame('Nuevo Nombre SAC', DB::table('tenants')->where('id', $own)->value('name'));
        $this->assertRejected(fn () => DB::table('tenants')->where('id', $own)->update(['status' => 'ACTIVE', 'code' => 'otro-'.Str::random(4)]), 'solo los cambia la consola');
        $this->assertRejected(fn () => DB::table('tenants')->where('id', $own)->update(['status' => 'CLOSED']), 'solo los cambia la consola');
        $this->assertRejected(fn () => DB::table('tenants')->where('id', $other)->update(['name' => 'Ajena']), 'solo cambia sus propios datos');
        $this->assertRejected(fn () => DB::table('tenants')->where('id', $own)->delete(), 'Solo la consola');
        $this->assertRejected(fn () => DB::statement('TRUNCATE tenants CASCADE'), 'permission denied');

        // Con una sesión de superadmin, la consola sí cambia el estado.
        $this->asPlatform(fn () => DB::table('tenants')->where('id', $own)->update(['status' => 'SUSPENDED']));
        $this->assertSame('SUSPENDED', DB::table('tenants')->where('id', $own)->value('status'));
    }

    public function test_only_plan_modules_can_be_turned_on_without_the_console(): void
    {
        $this->makeTenant([]);
        $this->subscribe(['CRM']);
        $insert = fn (string $code, bool $enabled = true) => DB::table('tenant_modules')->insert(['id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId, 'module_code' => $code, 'enabled' => $enabled]);

        // En el plan: se agrega y se apaga o enciende. Fuera del plan: ni agregarlo encendido ni encenderlo.
        $insert('CRM');
        DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'CRM')->update(['enabled' => false]);
        DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'CRM')->update(['enabled' => true]);
        $this->assertRejected(fn () => $insert('SOPORTE'), 'no está en el plan');
        $insert('SOPORTE', false);
        $this->assertRejected(fn () => DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'SOPORTE')->update(['enabled' => true]), 'no está en el plan');
        $this->assertRejected(fn () => DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'CRM')->delete(), 'Solo la consola');
        $this->assertRejected(fn () => DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'CRM')->update(['module_code' => 'SOPORTE']), 'no cambia');

        // La consola sí enciende un módulo fuera del plan.
        $this->asPlatform(fn () => DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'SOPORTE')->update(['enabled' => true]));
        $this->assertTrue((bool) DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'SOPORTE')->value('enabled'));
    }

    public function test_role_permissions_are_isolated_by_company(): void
    {
        $this->makeTenant();
        $admin = $this->makeUser('Admin', ['users.view']);
        $roleId = DB::table('user_roles')->where('user_id', $admin->id)->value('role_id');
        $this->assertGreaterThan(0, DB::table('role_permissions')->where('role_id', $roleId)->count());

        $this->makeTenant();
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$this->tenantId]);
        // Desde otra empresa, los permisos de ese rol no existen y no se le pueden agregar.
        $this->assertSame(0, DB::table('role_permissions')->where('role_id', $roleId)->count());
        $permission = DB::table('permissions')->where('code', 'audit.view')->value('id');
        $this->assertRejected(fn () => DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permission]), 'row-level security');
    }

    public function test_a_new_company_is_born_with_the_base_roles_of_its_modules(): void
    {
        Mail::fake();
        $planId = $this->asPlatform(fn () => DB::table('saas_plans')->insertGetId([
            'code' => 'REG-'.Str::random(6), 'name' => 'Plan registro', 'active' => true, 'is_public' => true,
            'monthly_price' => 99, 'currency' => 'PEN', 'has_trial' => true, 'trial_days' => 15,
            'modules' => json_encode(['CRM' => true, 'COTIZACIONES' => true, 'ORDENES_TRABAJO' => true]),
        ], 'id'));
        $email = 'roles-'.Str::lower(Str::random(8)).'@erp-saas.test';
        $this->postJson('/api/v1/tenants/register', [
            'plan_id' => $planId, 'billing_period' => 'MONTHLY', 'ruc' => '20'.random_int(100000000, 999999999),
            'razon_social' => 'Roles Base SAC', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'email' => $email, 'password' => 'password123',
        ])->assertCreated();

        $tenantId = (string) DB::table('tenants')->where('legal_name', 'Roles Base SAC')->value('id');
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
        $roles = DB::table('roles')->where('tenant_id', $tenantId)->pluck('name', 'code');
        $this->assertEqualsCanonicalizing(['ADMIN', 'MANAGER', 'SALES', 'OPERATIONS', 'TECHNICIAN', 'FINANCE', 'SUPPORT'], $roles->keys()->all());
        $this->assertSame('Ventas', $roles['SALES']);

        $permissionsOf = fn (string $code) => DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.tenant_id', $tenantId)->where('roles.code', $code)->pluck('permissions.code')->all();
        // Ventas: leads propios y cotizaciones sin aprobar; Gerencia aprueba; nada de módulos fuera del plan.
        $sales = $permissionsOf('SALES');
        $this->assertContains('leads.manage', $sales);
        $this->assertContains('quotations.manage', $sales);
        $this->assertNotContains('quotations.approve', $sales);
        $this->assertContains('quotations.approve', $permissionsOf('MANAGER'));
        $this->assertNotContains('billing.view', $permissionsOf('FINANCE'));
        $this->assertSame('OWN', DB::table('role_scopes')->join('roles', 'roles.id', '=', 'role_scopes.role_id')
            ->where('roles.tenant_id', $tenantId)->where('roles.code', 'SALES')->where('resource', 'quotations')->value('scope'));
        // Técnico: solo lo suyo en órdenes de trabajo.
        $this->assertSame('OWN', DB::table('role_scopes')->join('roles', 'roles.id', '=', 'role_scopes.role_id')
            ->where('roles.tenant_id', $tenantId)->where('roles.code', 'TECHNICIAN')->where('resource', 'work_orders')->value('scope'));
    }

    public function test_existing_companies_get_the_missing_base_roles_without_duplicates(): void
    {
        $this->makeTenant(['CRM', 'COTIZACIONES']);
        $code = DB::table('tenants')->where('id', $this->tenantId)->value('code');
        // Ya tenía su propio "Ventas" con otro código: no se repite.
        DB::table('roles')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'code' => 'VENDEDORES', 'name' => 'Ventas', 'is_system' => false]);

        $this->artisan('tenants:base-roles', ['--tenant' => $code, '--dry-run' => true])->assertSuccessful();
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$this->tenantId]);
        $this->assertSame(0, DB::table('roles')->where('tenant_id', $this->tenantId)->where('code', 'MANAGER')->count());

        $this->artisan('tenants:base-roles', ['--tenant' => $code])->assertSuccessful();
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$this->tenantId]);
        $codes = DB::table('roles')->where('tenant_id', $this->tenantId)->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['VENDEDORES', 'MANAGER', 'OPERATIONS', 'TECHNICIAN', 'FINANCE', 'SUPPORT'], $codes);

        // Correrlo otra vez no cambia nada.
        $this->artisan('tenants:base-roles', ['--tenant' => $code])->expectsOutputToContain('ya tiene los roles base')->assertSuccessful();
    }

    public function test_the_daily_exchange_rate_is_saved_without_overwriting_the_day_already_registered(): void
    {
        $this->makeTenant(['FINANZAS']);
        $withRate = $this->tenantId;
        $this->makeTenant(['FINANZAS']);
        $empty = $this->tenantId;
        $this->makeTenant(['CRM']);
        $noFinance = $this->tenantId;

        $date = now('America/Lima');
        // Primera corrida: SUNAT responde; segunda: no responde.
        Http::fake(['*' => Http::sequence()->push($date->format('d/m/Y').'|3.701|3.712|')->push('', 500)]);
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$withRate]);
        DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $withRate, 'currency' => 'USD',
            'rate_date' => $date->toDateString(), 'buy' => 3.6, 'sell' => 3.65, 'source' => 'Manual']);

        $this->artisan('exchange-rates:sunat')->assertSuccessful();

        $rate = fn (string $tenant) => DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenant])
            ? DB::table('exchange_rates')->where('tenant_id', $tenant)->where('rate_date', $date->toDateString())->first(['sell', 'source'])
            : null;
        $this->assertSame(['3.7120', 'SUNAT'], [(string) $rate($empty)->sell, $rate($empty)->source]);
        $this->assertSame(['3.6500', 'Manual'], [(string) $rate($withRate)->sell, $rate($withRate)->source]);
        $this->assertNull($rate($noFinance));

        // Si SUNAT no responde, no se guarda nada.
        $this->artisan('exchange-rates:sunat')->assertFailed();
    }
}
