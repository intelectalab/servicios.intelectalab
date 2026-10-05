<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Subscription\Models\SaasPlan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(bool $superAdmin = false): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'sa-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('password123'),
            'name' => 'Test Plataforma',
            'status' => 'ACTIVE',
            'is_super_admin' => $superAdmin,
        ])->save();

        return $user;
    }

    private function makeTenant(): string
    {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'code' => 'test-'.Str::random(8),
            'name' => 'Test Tenant',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement("SET app.tenant_id = '{$tenantId}'");

        return $tenantId;
    }

    private function addMember(string $tenantId, User $user): void
    {
        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_super_admin_flag_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'email' => 'sa-'.Str::random(8).'@erp-saas.test',
            'password_hash' => bcrypt('password123'),
            'name' => 'Intento',
            'status' => 'ACTIVE',
            'is_super_admin' => true,
        ]);

        $this->assertFalse((bool) $user->fresh()->is_super_admin);
    }

    public function test_super_admin_cannot_be_added_to_a_tenant(): void
    {
        $superAdmin = $this->makeUser(true);
        $tenantId = $this->makeTenant();

        $this->expectException(QueryException::class);
        $this->addMember($tenantId, $superAdmin);
    }

    public function test_tenant_member_cannot_become_super_admin(): void
    {
        $user = $this->makeUser();
        $this->addMember($this->makeTenant(), $user);

        $this->expectException(QueryException::class);
        $user->forceFill(['is_super_admin' => true])->save();
    }

    public function test_plan_changes_are_recorded_in_the_platform_audit(): void
    {
        $superAdmin = $this->makeUser(true);
        Sanctum::actingAs($superAdmin);

        $planId = $this->postJson('/api/v1/superadmin/plans', [
            'code' => 'TEST-'.Str::random(6),
            'name' => 'Plan de prueba',
            'currency' => 'PEN',
            'monthly_price' => 10,
        ])->assertCreated()->json('plan.id');

        $this->putJson("/api/v1/superadmin/plans/{$planId}", ['name' => 'Plan renombrado'])->assertOk();
        $this->deleteJson("/api/v1/superadmin/plans/{$planId}")->assertOk();

        $actions = DB::table('platform_audit_logs')->where('entity_id', $planId)->orderBy('created_at')->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['plan.created', 'plan.updated', 'plan.deactivated'], $actions);

        $update = DB::table('platform_audit_logs')->where('entity_id', $planId)->where('action', 'plan.updated')->first();
        $this->assertSame('Plan de prueba', json_decode($update->before_data)->name);
        $this->assertSame('Plan renombrado', json_decode($update->after_data)->name);
        $this->assertSame($superAdmin->id, $update->user_id);

        $this->getJson('/api/v1/superadmin/audit')->assertOk()
            ->assertJsonFragment(['action' => 'plan.deactivated', 'user_email' => $superAdmin->email]);

        $this->assertFalse((bool) SaasPlan::find($planId)->active);
    }

    public function test_platform_audit_is_only_for_super_admins(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/v1/superadmin/audit')->assertStatus(403);
    }

    public function test_platform_audit_cannot_be_edited_or_deleted_by_the_app(): void
    {
        $this->expectException(QueryException::class);
        DB::table('platform_audit_logs')->delete();
    }
}
