<?php

namespace Tests\Feature\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Throwable;

/** Datos de apoyo de las pruebas de Operaciones: empresa, personas con su rol y alcance, contrato y servicio contratado. */
trait OperationsFixtures
{
    private string $tenantId;

    private function makeTenant(array $modules = ['ORDENES_TRABAJO', 'PROYECTOS', 'TIMESHEETS', 'CONTRATOS']): string
    {
        $this->tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $this->tenantId, 'code' => 'test-'.Str::random(8), 'name' => 'Empresa de Prueba SAC', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SET app.tenant_id = '{$this->tenantId}'");
        foreach ($modules as $module) {
            DB::table('tenant_modules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'module_code' => $module, 'enabled' => true,
            ]);
        }
        DB::table('companies')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'code' => 'PRINCIPAL', 'legal_name' => 'Empresa de Prueba SAC',
        ]);

        return $this->tenantId;
    }

    /** Usuario con un rol propio, esos permisos y ese alcance en operaciones (null = sin definir: lo propio). */
    private function makeUser(string $name, array $permissions, ?string $scope = 'TENANT', ?string $branchId = null): User
    {
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId, 'tenant_id' => $this->tenantId, 'code' => Str::upper(Str::random(8)), 'name' => $name,
            'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        foreach ($scope ? ['contracted_services', 'work_orders', 'contracts', 'projects', 'time_entries', 'cost_entries', 'billing', 'receivables', 'payments', 'recurring', 'purchases', 'payables', 'budgets', 'tickets', 'warranty', 'documents'] : [] as $resource) {
            DB::table('role_scopes')->insert(['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'resource' => $resource, 'scope' => $scope]);
        }

        $user = User::create([
            'email' => 'ops-'.Str::random(8).'@erp-saas.test', 'password_hash' => bcrypt('Actual123'), 'name' => $name, 'status' => 'ACTIVE',
        ]);
        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'default_branch_id' => $branchId,
            'status' => 'ACTIVE', 'hourly_cost' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'role_id' => $roleId, 'scope' => 'TENANT', 'created_at' => now(),
        ]);

        return $user;
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function makeBranch(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('branches')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'name' => $name, 'code' => Str::upper($name),
            'company_id' => DB::table('companies')->where('tenant_id', $this->tenantId)->value('id'),
        ]);

        return $id;
    }

    private function makeCustomer(string $legalName = 'Minera Los Andes SAC'): string
    {
        $partyId = (string) Str::uuid();
        DB::table('parties')->insert([
            'id' => $partyId, 'tenant_id' => $this->tenantId, 'party_type' => 'ORGANIZATION',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('organizations')->insert(['party_id' => $partyId, 'legal_name' => $legalName, 'tax_identifier' => (string) random_int(20100000000, 20999999999)]);

        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId, 'tenant_id' => $this->tenantId, 'party_id' => $partyId,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $customerId;
    }

    private function makeService(string $name = 'Mantenimiento preventivo', bool $recurring = false): string
    {
        $id = (string) Str::uuid();
        DB::table('services')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'code' => 'SRV-'.Str::upper(Str::random(5)), 'name' => $name,
            'status' => 'ACTIVE', 'billing_mode' => $recurring ? 'RECURRING' : 'PER_EVENT',
        ]);
        if ($recurring) {
            DB::table('service_recurring_configs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'service_id' => $id, 'frequency' => 'MONTHLY', 'interval_value' => 1,
            ]);
        }

        return $id;
    }

    private function makeContract(?User $seller = null, string $status = 'ACTIVE', ?string $branchId = null, ?string $customerId = null): array
    {
        $id = (string) Str::uuid();
        $customerId ??= $this->makeCustomer();
        DB::table('contracts')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'customer_id' => $customerId, 'contract_number' => 'CTR-'.Str::upper(Str::random(6)),
            'status' => $status, 'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'created_by' => $seller?->id, 'branch_id' => $branchId,
        ]);

        return [$id, $customerId];
    }

    /** Servicio contratado de un contrato (por defecto vigente y vendido por nadie en particular). */
    private function makeContracted(array $data = [], ?array $contract = null, ?User $seller = null): string
    {
        [$contractId, $customerId] = $contract ?? $this->makeContract($seller, branchId: $data['branch_id'] ?? null);
        $id = (string) Str::uuid();
        DB::table('contracted_services')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'customer_id' => $customerId, 'contract_id' => $contractId,
            'service_id' => $data['service_id'] ?? $this->makeService(), 'status' => $data['status'] ?? 'ACTIVE',
            'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'quantity' => 2, 'price' => 380, 'recurring' => $data['recurring'] ?? false,
            'owner_user_id' => $seller?->id, 'branch_id' => $data['branch_id'] ?? null,
        ]);

        return $id;
    }

    /** Plantilla con dos ítems obligatorios y uno opcional. */
    private function makeTemplate(): string
    {
        $id = (string) Str::uuid();
        // El nombre no se repite en la empresa: la segunda de una prueba lleva su número.
        $taken = DB::table('checklists')->where('tenant_id', $this->tenantId)->count();
        DB::table('checklists')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'name' => 'Checklist de instalación'.($taken ? ' '.($taken + 1) : '')]);
        foreach ([['Verificación inicial', true], ['Ejecución del servicio', true], ['Foto del equipo', false]] as $position => [$label, $required]) {
            DB::table('checklist_items')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'checklist_id' => $id,
                'position' => $position + 1, 'label' => $label, 'required' => $required,
            ]);
        }

        return $id;
    }

    private function order(User $as, string $contractedId, array $data = []): array
    {
        $this->as($as);

        return $this->postJson('/api/v1/work-orders', [
            'contracted_service_id' => $contractedId, 'title' => 'Mantenimiento de planta', ...$data,
        ])->assertCreated()->json();
    }

    private function at(int $days, int $hour): string
    {
        return now()->addDays($days)->setTime($hour, 0)->toIso8601String();
    }

    private function assertDatabaseRejects(callable $attempt): void
    {
        try {
            DB::transaction($attempt);
            $this->fail('La base aceptó un dato que debía rechazar.');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }
    }
}
