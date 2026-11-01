<?php

namespace Tests\Feature;

use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Credential Vault: guardar cifrado, revelar y usar con motivo, rotar (nueva versión), revocar,
 * vencimiento, historial sin valores, permisos separados, otra empresa y lo que garantiza la base.
 */
class CredentialVaultTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const ADMIN = [
        'credentials.view', 'credentials.manage', 'credentials.use', 'credentials.reveal', 'credentials.delete', 'credentials.export',
    ];

    private const SECRET = 'Clave-Super-Secreta-123';

    private function create(array $data = [])
    {
        return $this->postJson('/api/v1/credentials', [
            'name' => 'Servidor web producción', 'credential_type' => 'SERVIDOR', 'owner_label' => 'Cliente: Plaza Sol',
            'secret' => self::SECRET, 'expires_at' => now('America/Lima')->addDays(90)->toDateString(), ...$data,
        ]);
    }

    public function test_create_reveal_use_rotate_and_revoke(): void
    {
        $this->makeTenant([]);
        $this->as($this->makeUser('Admin', self::ADMIN));

        $this->create(['name' => '', 'credential_type' => 'OTRO', 'secret' => '', 'expires_at' => now()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'credential_type', 'secret', 'expires_at']);

        $credential = $this->create()->assertCreated()->json();
        $this->assertSame(['ACTIVE', 1, 'SERVIDOR', 'Cliente: Plaza Sol'], [$credential['status'], $credential['version'], $credential['type'], $credential['owner_label']]);
        $this->assertArrayNotHasKey('secret', $credential);
        $id = $credential['id'];

        // El listado nunca trae el valor; busca por nombre o "pertenece a" y filtra por tipo y estado.
        $list = $this->getJson('/api/v1/credentials?search=plaza')->assertOk()->assertJsonPath('total', 1);
        $this->assertStringNotContainsString(self::SECRET, $list->getContent());
        $this->getJson('/api/v1/credentials?type=DNS')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/credentials?status=ACTIVE&sort=expires&dir=desc')->assertJsonPath('total', 1);

        // Revelar y usar piden motivo.
        $this->postJson("/api/v1/credentials/{$id}/reveal")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/credentials/{$id}/reveal", ['reason' => 'Soporte al cliente'])->assertOk()->assertJsonPath('secret', self::SECRET);
        $this->postJson("/api/v1/credentials/{$id}/use", ['reason' => 'Despliegue'])->assertOk()->assertJsonPath('secret', self::SECRET);

        // Rotar: nueva versión con su motivo; se revela el valor nuevo.
        $this->postJson("/api/v1/credentials/{$id}/rotate", ['secret' => 'Nueva-Clave-456'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $rotated = $this->postJson("/api/v1/credentials/{$id}/rotate", ['secret' => 'Nueva-Clave-456', 'reason' => 'Rotación trimestral', 'expires_at' => now('America/Lima')->addYear()->toDateString()])
            ->assertOk()->json();
        $this->assertSame(2, $rotated['version']);
        $this->postJson("/api/v1/credentials/{$id}/reveal", ['reason' => 'Comprobar'])->assertJsonPath('secret', 'Nueva-Clave-456');

        // Revocar: con motivo, una sola vez; revocada no se revela, no se usa ni se rota.
        $this->postJson("/api/v1/credentials/{$id}/revoke", ['reason' => 'Se dio de baja el servidor'])->assertOk()->assertJsonPath('status', 'REVOKED');
        $this->postJson("/api/v1/credentials/{$id}/revoke", ['reason' => 'Otra vez'])->assertStatus(422);
        $this->postJson("/api/v1/credentials/{$id}/reveal", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/credentials/{$id}/use", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/credentials/{$id}/rotate", ['secret' => 'Otra', 'reason' => 'x'])->assertStatus(422);

        // Historial: versiones y accesos con motivo (los fallidos también), nunca el valor.
        $history = $this->getJson("/api/v1/credentials/{$id}/history")->assertOk();
        $this->assertSame([2, 1], array_column($history->json('versions'), 'version'));
        $this->assertSame(['Rotación trimestral', 'Alta'], array_column($history->json('versions'), 'reason'));
        $accesses = $history->json('accesses');
        $this->assertSame(['use', 'reveal', 'revoke', 'reveal', 'rotate', 'use', 'reveal', 'create'], array_column($accesses, 'action'));
        $this->assertSame([false, false, true], array_column(array_slice($accesses, 0, 3), 'success'));
        $this->assertStringNotContainsString(self::SECRET, $history->getContent());
        $this->assertStringNotContainsString('Nueva-Clave-456', $history->getContent());

        // Auditoría: cada acción con su texto y sin el valor.
        $audit = DB::table('audit_logs')->where('entity_id', $id)->get();
        $this->assertEqualsCanonicalizing(
            ['credential.create', 'credential.reveal', 'credential.use', 'credential.rotate', 'credential.reveal', 'credential.revoke'],
            $audit->pluck('action')->all(),
        );
        foreach ($audit as $row) {
            $this->assertArrayHasKey($row->action, AuditCatalog::ACTIONS);
            $this->assertStringNotContainsString(self::SECRET, json_encode($row));
            $this->assertStringNotContainsString('Nueva-Clave-456', json_encode($row));
        }
    }

    public function test_expiry_blocks_use_until_rotated(): void
    {
        $this->makeTenant([]);
        $this->as($this->makeUser('Admin', self::ADMIN));
        $id = $this->create()->assertCreated()->json('id');

        // Pasada la fecha ya se muestra expirada y no se usa, aunque la tarea diaria no haya corrido.
        DB::table('credential_records')->where('id', $id)->update(['expires_at' => now()->subMinute(), 'created_at' => now()->subDay()]);
        $this->getJson("/api/v1/credentials/{$id}")->assertJsonPath('status', 'EXPIRED');
        $this->getJson('/api/v1/credentials?status=EXPIRED')->assertJsonPath('total', 1);
        $this->postJson("/api/v1/credentials/{$id}/use", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('status');

        // La tarea diaria la deja expirada en la base, con auditoría.
        $this->artisan('credentials:expire')->assertSuccessful();
        DB::statement("SET app.tenant_id = '{$this->tenantId}'");
        $this->assertSame('EXPIRED', DB::table('credential_records')->where('id', $id)->value('status'));
        $this->assertTrue(DB::table('audit_logs')->where('entity_id', $id)->where('action', 'credential.expire')->exists());

        // Rotarla la reactiva.
        $this->postJson("/api/v1/credentials/{$id}/rotate", ['secret' => 'Renovada', 'reason' => 'Renovación'])->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson("/api/v1/credentials/{$id}/use", ['reason' => 'Despliegue'])->assertOk()->assertJsonPath('secret', 'Renovada');
    }

    public function test_each_action_is_a_separate_permission_and_other_tenants_get_404(): void
    {
        $this->makeTenant([]);
        $admin = $this->makeUser('Admin', self::ADMIN);
        $this->as($admin);
        $id = $this->create()->assertCreated()->json('id');

        // Operaciones o Técnico: ven y usan, no revelan, no rotan, no revocan, no exportan.
        $this->as($this->makeUser('Técnico', ['credentials.view', 'credentials.use']));
        $this->getJson('/api/v1/credentials')->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/v1/credentials/{$id}/history")->assertOk();
        $this->postJson("/api/v1/credentials/{$id}/use", ['reason' => 'Mantenimiento'])->assertOk();
        $this->postJson("/api/v1/credentials/{$id}/reveal", ['reason' => 'x'])->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->postJson("/api/v1/credentials/{$id}/rotate", ['secret' => 'x', 'reason' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/credentials/{$id}/revoke", ['reason' => 'x'])->assertForbidden();
        $this->create()->assertForbidden();
        $this->getJson('/api/v1/credentials?export=1')->assertForbidden();

        // Gerencia o Finanzas: solo ven.
        $this->as($this->makeUser('Gerencia', ['credentials.view']));
        $this->postJson("/api/v1/credentials/{$id}/use", ['reason' => 'x'])->assertForbidden();

        // Sin permiso: nada.
        $this->as($this->makeUser('Ventas', ['quotations.view']));
        $this->getJson('/api/v1/credentials')->assertForbidden();

        // Otra empresa: no existe.
        $this->makeTenant([]);
        $this->as($this->makeUser('Admin B', self::ADMIN));
        $this->getJson('/api/v1/credentials')->assertJsonPath('total', 0);
        $this->getJson("/api/v1/credentials/{$id}")->assertNotFound();
        $this->getJson("/api/v1/credentials/{$id}/history")->assertNotFound();
        $this->postJson("/api/v1/credentials/{$id}/reveal", ['reason' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/credentials/{$id}/revoke", ['reason' => 'x'])->assertNotFound();
        $this->getJson('/api/v1/credentials/no-es-un-id')->assertNotFound();
    }

    public function test_the_database_keeps_versions_and_the_access_log(): void
    {
        $this->makeTenant([]);
        $this->as($this->makeUser('Admin', self::ADMIN));
        $id = $this->create()->assertCreated()->json('id');

        // El valor queda cifrado, no en claro.
        $cipher = DB::table('credential_versions')->where('credential_id', $id)->value(DB::raw("encode(secret_ciphertext, 'escape')"));
        $this->assertStringNotContainsString(self::SECRET, (string) $cipher);

        $this->assertDatabaseRejects(fn () => DB::table('credential_versions')->where('credential_id', $id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('credential_versions')->where('credential_id', $id)->update(['version' => 9]));
        $this->assertDatabaseRejects(fn () => DB::table('credential_access_logs')->where('credential_id', $id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('credential_access_logs')->where('credential_id', $id)->update(['reason' => 'otro']));
        $this->assertDatabaseRejects(fn () => DB::table('credential_records')->where('id', $id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('credential_records')->where('id', $id)->update(['credential_type' => 'INVENTADO']));
        $this->assertDatabaseRejects(fn () => DB::table('credential_records')->where('id', $id)->update(['status' => 'BORRADA']));
    }

    public function test_new_tenants_admins_get_the_vault_with_the_base_plan(): void
    {
        $codes = \App\Modules\Subscription\Support\ModulePermissions::codesFor([]);
        foreach (self::ADMIN as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertSame(6, DB::table('permissions')->whereIn('code', self::ADMIN)->count());
    }
}
