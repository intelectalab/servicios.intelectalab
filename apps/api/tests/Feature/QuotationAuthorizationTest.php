<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Reglas de aprobación: una cotización en borrador que supera una regla no se envía sin su
 * autorización interna, nivel por nivel, de los roles que la empresa eligió; quien la creó no la
 * autoriza ni una persona decide dos niveles; editar montos la anula; rechazar pide motivo.
 * Sin reglas, todo sigue igual.
 */
class QuotationAuthorizationTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const SELLER = ['quotations.view', 'quotations.manage', 'core.view'];

    private function roleOf(User $user): string
    {
        $role = DB::table('user_roles')->where('user_id', $user->id)->value('role_id');
        DB::table('role_scopes')->insert(['role_id' => $role, 'tenant_id' => $this->tenantId, 'resource' => 'quotations', 'scope' => 'TENANT']);

        return $role;
    }

    private function quotation(float $price, float $discount = 0): string
    {
        return $this->postJson('/api/v1/quotations', [
            'customer_id' => $this->makeCustomer(),
            'items' => [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => $price, 'discount' => $discount, 'tax_rate' => 18]],
        ])->assertCreated()->json('id');
    }

    public function test_a_quotation_over_the_rules_needs_each_level_before_it_is_sent(): void
    {
        $this->makeTenant(['COTIZACIONES', 'SERVICIOS']);
        $admin = $this->makeUser('Admin', ['approvals.view', 'approvals.manage', ...self::SELLER]);
        $seller = $this->makeUser('Ventas', self::SELLER);
        $manager = $this->makeUser('Gerencia', ['quotations.view']);
        $director = $this->makeUser('Dirección', ['quotations.view']);
        [$adminRole, $sellerRole, $managerRole, $directorRole] = array_map(fn ($u) => $this->roleOf($u), [$admin, $seller, $manager, $director]);

        $this->as($admin);
        $this->putJson('/api/v1/approval-rules', [
            'levels' => [1 => ['name' => 'Gerencia comercial', 'role_ids' => [$managerRole]], 2 => ['name' => 'Gerencia general', 'role_ids' => [$directorRole, $adminRole]]],
            'rules' => [
                ['name' => 'Monto alto', 'metric' => 'total', 'value' => 10000, 'level' => 1],
                ['name' => 'Monto muy alto', 'metric' => 'total', 'value' => 50000, 'level' => 2],
                ['name' => 'Descuento alto', 'metric' => 'discount', 'value' => 15, 'level' => 1],
            ],
        ])->assertOk()->assertJsonCount(3, 'rules');
        // Un nivel con regla y sin quién lo autorice no se guarda.
        $this->putJson('/api/v1/approval-rules', [
            'levels' => [1 => ['name' => 'N1', 'role_ids' => []], 2 => ['name' => 'N2', 'role_ids' => [$adminRole]]],
            'rules' => [['name' => 'X', 'metric' => 'total', 'value' => 1, 'level' => 1]],
        ])->assertStatus(422);

        // Sin regla alcanzada se envía como siempre.
        $this->as($seller);
        $small = $this->quotation(1000);
        $this->getJson("/api/v1/quotations/{$small}")->assertJsonPath('authorization.status', 'NOT_REQUIRED');
        $this->postJson("/api/v1/quotations/{$small}/submit")->assertOk();

        // 60 000 + IGV: nivel 2. No se envía sin autorización.
        $big = $this->quotation(60000);
        $this->getJson("/api/v1/quotations/{$big}")->assertJsonPath('authorization.required_level', 2)->assertJsonPath('authorization.status', 'MISSING');
        $this->postJson("/api/v1/quotations/{$big}/submit")->assertStatus(422)->assertJsonValidationErrors('authorization');
        $this->postJson("/api/v1/quotations/{$big}/authorization")->assertOk()->assertJsonPath('next_level.level', 1);
        // Quien la creó no la autoriza.
        $this->postJson("/api/v1/quotations/{$big}/authorization/approve")->assertForbidden();

        // Nivel 2 no se salta: primero decide el nivel 1.
        $this->as($director);
        $this->postJson("/api/v1/quotations/{$big}/authorization/approve")->assertForbidden();
        $this->as($manager);
        $this->postJson("/api/v1/quotations/{$big}/authorization/approve")->assertOk()->assertJsonPath('next_level.level', 2);
        $this->as($seller);
        $this->postJson("/api/v1/quotations/{$big}/submit")->assertStatus(422);
        $this->as($director);
        $this->postJson("/api/v1/quotations/{$big}/authorization/approve")->assertOk()->assertJsonPath('request.status', 'APPROVED');

        // Autorizada, se envía. Lo pendiente desaparece de la pantalla.
        $this->as($seller);
        $this->postJson("/api/v1/quotations/{$big}/submit")->assertOk();
        $this->as($admin);
        $this->assertSame([], $this->getJson('/api/v1/approval-rules')->assertOk()->json('pending'));
        $this->assertSame(2, DB::table('quotation_authorization_steps')->whereIn('authorization_id', DB::table('quotation_authorizations')->where('quotation_id', $big)->select('id'))->count());
    }

    public function test_editing_voids_it_rejecting_needs_a_reason_and_one_person_decides_one_level(): void
    {
        $this->makeTenant(['COTIZACIONES', 'SERVICIOS']);
        $admin = $this->makeUser('Admin', ['approvals.view', 'approvals.manage', ...self::SELLER]);
        $seller = $this->makeUser('Ventas', self::SELLER);
        $manager = $this->makeUser('Gerencia', ['quotations.view']);
        [$adminRole, , $managerRole] = array_map(fn ($u) => $this->roleOf($u), [$admin, $seller, $manager]);

        $this->as($admin);
        $this->putJson('/api/v1/approval-rules', [
            'levels' => [1 => ['name' => 'Gerencia', 'role_ids' => [$managerRole]], 2 => ['name' => 'Todos', 'role_ids' => [$managerRole, $adminRole]]],
            'rules' => [['name' => 'Descuento alto', 'metric' => 'discount', 'value' => 15, 'level' => 2]],
        ])->assertOk();

        $this->as($seller);
        $id = $this->quotation(1000, 200); // 20 % de descuento
        $this->getJson("/api/v1/quotations/{$id}")->assertJsonPath('authorization.reasons.0', 'Descuento alto (20 %)');
        $this->postJson("/api/v1/quotations/{$id}/authorization")->assertOk();
        $this->as($manager);
        $this->postJson("/api/v1/quotations/{$id}/authorization/approve")->assertOk();
        // La misma persona no decide el nivel 2, aunque su rol esté en él (también lo impide la base).
        $this->postJson("/api/v1/quotations/{$id}/authorization/approve")->assertForbidden();

        // Cambiar el descuento anula lo autorizado.
        $this->as($seller);
        $this->putJson("/api/v1/quotations/{$id}", [
            'customer_id' => DB::table('quotations')->where('id', $id)->value('customer_id'),
            'items' => [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 1000, 'discount' => 250, 'tax_rate' => 18]],
        ])->assertOk();
        $this->assertSame('VOID', DB::table('quotation_authorizations')->where('quotation_id', $id)->value('status'));
        $this->getJson("/api/v1/quotations/{$id}")->assertJsonPath('authorization.status', 'MISSING');

        // Rechazar pide motivo.
        $this->postJson("/api/v1/quotations/{$id}/authorization")->assertOk();
        $this->as($manager);
        $this->postJson("/api/v1/quotations/{$id}/authorization/reject")->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->postJson("/api/v1/quotations/{$id}/authorization/reject", ['comment' => 'Descuento fuera de política'])->assertOk()->assertJsonPath('request.status', 'REJECTED');
        $this->as($seller);
        $this->postJson("/api/v1/quotations/{$id}/submit")->assertStatus(422);

        // La base no deja que quien creó la cotización registre una decisión.
        $authorization = DB::table('quotation_authorizations')->where('quotation_id', $id)->orderByRaw("status = 'REJECTED' DESC")->value('id');
        try {
            DB::transaction(fn () => DB::table('quotation_authorization_steps')->insert([
                'tenant_id' => $this->tenantId, 'authorization_id' => $authorization, 'level' => 2, 'decision' => 'APPROVED', 'user_id' => $seller->id,
            ]));
            $this->fail('La base aceptó una decisión de quien creó la cotización.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('no la autoriza', $e->getMessage());
        }
    }

    public function test_viewing_and_changing_the_rules_need_their_permissions(): void
    {
        $this->makeTenant(['COTIZACIONES']);
        $this->as($this->makeUser('Gerencia', ['approvals.view']));
        $this->getJson('/api/v1/approval-rules')->assertOk()->assertJsonPath('levels.1.name', 'Gerencia comercial')->assertJsonPath('rules', []);
        $this->putJson('/api/v1/approval-rules', ['levels' => [], 'rules' => []])->assertForbidden();
        $this->as($this->makeUser('Ventas', self::SELLER));
        $this->getJson('/api/v1/approval-rules')->assertForbidden();
    }
}
