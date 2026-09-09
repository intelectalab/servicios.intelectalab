<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Ruta temporal SOLO para este test, que expone tenant_users
        // protegida por el mismo middleware que usa la app real.
        Route::middleware(['auth:sanctum', 'tenant.context'])
            ->get('/test/tenant-users', function () {
                return response()->json(DB::table('tenant_users')->get());
            });
    }

    public function test_user_cannot_see_another_tenant_data(): void
    {
        // --- Arrange: Tenant A + Usuario A ---
        $tenantAId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantAId,
            'code' => 'test-a-'.Str::random(8),
            'name' => 'Test Tenant A',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userA = User::create([
            'email' => 'test-a-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('password123'),
            'name' => 'Test User A',
            'status' => 'ACTIVE',
        ]);

        // Nos "convertimos" temporalmente en Tenant A solo para poder
        // crear su propia membresía (la política de RLS lo exige).
        DB::statement("SET app.tenant_id = '{$tenantAId}'");
        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantAId,
            'user_id' => $userA->id,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // --- Arrange: Tenant B + Usuario B ---
        $tenantBId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantBId,
            'code' => 'test-b-'.Str::random(8),
            'name' => 'Test Tenant B',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userB = User::create([
            'email' => 'test-b-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('password123'),
            'name' => 'Test User B',
            'status' => 'ACTIVE',
        ]);

        // Ahora nos "convertimos" en Tenant B para crear SU membresía.
        DB::statement("SET app.tenant_id = '{$tenantBId}'");
        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantBId,
            'user_id' => $userB->id,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // --- Act + Assert: autenticados como Usuario A ---
        Sanctum::actingAs($userA);
        $responseA = $this->getJson('/test/tenant-users');

        $responseA->assertOk();
        $responseA->assertJsonCount(1);
        $responseA->assertJsonFragment(['tenant_id' => $tenantAId]);
        $responseA->assertJsonMissing(['tenant_id' => $tenantBId]);

        // --- Act + Assert: autenticados como Usuario B ---
        Sanctum::actingAs($userB);
        $responseB = $this->getJson('/test/tenant-users');

        $responseB->assertOk();
        $responseB->assertJsonCount(1);
        $responseB->assertJsonFragment(['tenant_id' => $tenantBId]);
        $responseB->assertJsonMissing(['tenant_id' => $tenantAId]);
    }
}