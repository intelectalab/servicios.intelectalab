<?php

namespace Tests\Feature;

use App\Mail\ContractSignatureCodeMail;
use App\Mail\ContractSignatureMail;
use App\Models\User;
use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Firma electrónica de contratos (RN-COM-09, RN-COM-13): enviar a firma con la huella del documento,
 * firma del cliente en el portal (nombre exacto, código al correo, aceptación) y de la empresa,
 * contrato que cambió, rechazo con motivo, anulación, vencimiento, activación con firma exigida y
 * lo que garantiza la base.
 */
class ContractSignatureTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const STAFF = ['contracts.view', 'contracts.manage', 'contracts.approve'];

    private function portalUser(string $customerId, string $role = 'PORTAL_ADMIN', string $name = 'Rosa Quispe'): User
    {
        $user = User::create([
            'email' => 'firma-'.Str::random(8).'@cliente.test', 'password_hash' => bcrypt('Cliente123'), 'name' => $name, 'status' => 'ACTIVE', 'mfa_enabled' => true,
        ]);
        DB::table('portal_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'customer_id' => $customerId,
            'role' => $role, 'status' => 'ACTIVE', 'created_at' => now(),
        ]);

        return $user;
    }

    /** Contrato en borrador con una línea. */
    private function draft(?string $customerId = null): array
    {
        [$contractId, $customerId] = $this->makeContract(status: 'DRAFT', customerId: $customerId);
        DB::table('contracts')->where('id', $contractId)->update(['sla_level' => 'ESTANDAR', 'currency' => 'PEN', 'total_value' => 1180, 'terms' => 'Pago a 30 días.']);
        DB::table('contract_items')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'contract_id' => $contractId, 'description' => 'Mantenimiento preventivo',
            'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1180,
        ]);

        return [$contractId, $customerId];
    }

    private function code(string $class): string
    {
        $code = null;
        Mail::assertSent($class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    public function test_the_customer_and_the_company_sign_with_code_and_evidence(): void
    {
        Mail::fake();
        $this->makeTenant(['CONTRATOS', 'PORTAL']);
        $staff = $this->makeUser('Gerencia', self::STAFF);
        [$contractId, $customerId] = $this->draft();

        // Sin un Portal Admin activo del cliente no se envía.
        $this->as($staff);
        $this->postJson("/api/v1/contracts/{$contractId}/signature-requests")->assertStatus(422)->assertJsonValidationErrors('portal_user_id');

        $client = $this->portalUser($customerId);
        $viewer = $this->portalUser($customerId, 'PORTAL_VIEWER', 'Luis Rojas');
        $sent = $this->postJson("/api/v1/contracts/{$contractId}/signature-requests")->assertOk()->json('signature');
        $this->assertSame(['PENDING', 'SIMPLE', false], [$sent['status'], $sent['provider'], $sent['changed']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sent['document_hash']);
        $this->assertSame('PENDING', DB::table('contracts')->where('id', $contractId)->value('signature_status'));
        Mail::assertSent(ContractSignatureMail::class, fn ($mail) => $mail->hasTo($client->email));
        // Una solicitud pendiente por contrato.
        $this->postJson("/api/v1/contracts/{$contractId}/signature-requests")->assertStatus(422);
        $requestId = $sent['id'];

        // El cliente lo ve en su portal, con el documento; Solo lectura no abre Firmas.
        $this->as($viewer);
        $this->getJson('/api/v1/portal/signatures')->assertForbidden();
        $this->as($client);
        $pending = $this->getJson('/api/v1/portal/signatures')->assertOk()->json('pending.0');
        $this->assertSame([true, 'Mantenimiento preventivo', 'Pago a 30 días.'], [$pending['mine'], $pending['contract']['items'][0]['description'], $pending['contract']['terms']]);
        $this->assertSame(1, $this->getJson('/api/v1/portal/home')->json('signatures_pending'));

        // Sin código, con otro nombre o con un código errado no firma.
        $sign = fn (array $data) => $this->postJson("/api/v1/portal/signatures/{$requestId}/sign", $data + ['typed_name' => 'Rosa Quispe', 'accept' => true]);
        $sign(['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson("/api/v1/portal/signatures/{$requestId}/code")->assertOk();
        $code = $this->code(ContractSignatureCodeMail::class);
        $sign(['code' => $code, 'typed_name' => 'Rosa'])->assertStatus(422)->assertJsonValidationErrors('typed_name');
        $sign(['code' => $code, 'accept' => false])->assertStatus(422)->assertJsonValidationErrors('accept');
        $sign(['code' => $code === '111111' ? '222222' : '111111'])->assertStatus(422)->assertJsonValidationErrors('code');
        $sign(['code' => $code, 'typed_name' => '  rosa quispe '])->assertOk();

        $customer = DB::table('contract_signature_signers')->where('request_id', $requestId)->where('side', 'CLIENTE')->first();
        $this->assertSame(['SIGNED', true, $client->id], [$customer->status, (bool) $customer->otp_verified, $customer->user_id]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $customer->evidence_hash);
        $this->assertSame('PENDING', DB::table('contract_signature_requests')->where('id', $requestId)->value('status'));

        // La empresa firma con su código; con las dos firmas queda firmado.
        $this->as($staff);
        $this->postJson("/api/v1/contracts/{$contractId}/signature-requests/{$requestId}/company-sign", ['code' => '123456', 'accept' => true])->assertStatus(422);
        $this->postJson("/api/v1/contracts/{$contractId}/signature-requests/{$requestId}/code")->assertOk();
        $staffCode = $this->code(ContractSignatureCodeMail::class);
        $signed = $this->postJson("/api/v1/contracts/{$contractId}/signature-requests/{$requestId}/company-sign", ['code' => $staffCode, 'accept' => true])
            ->assertOk()->json('signature');
        $this->assertSame('SIGNED', $signed['status']);
        $this->assertSame(['SIGNED', 'SIGNED'], array_column($signed['signers'], 'status'));
        $this->assertSame('SIGNED', DB::table('contracts')->where('id', $contractId)->value('signature_status'));

        // Una firma registrada no cambia y nada se borra.
        $this->assertDatabaseRejects(fn () => DB::table('contract_signature_signers')->where('request_id', $requestId)->update(['typed_name' => 'Otra']));
        $this->assertDatabaseRejects(fn () => DB::table('contract_signature_requests')->where('id', $requestId)->update(['status' => 'PENDING']));
        $this->assertDatabaseRejects(fn () => DB::table('contract_signature_requests')->where('id', $requestId)->delete());

        foreach (DB::table('audit_logs')->where('entity_id', $contractId)->pluck('action')->unique() as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }
    }

    public function test_a_changed_contract_is_not_signed_declined_cancelled_and_expired_requests(): void
    {
        Mail::fake();
        $this->makeTenant(['CONTRATOS', 'PORTAL']);
        $staff = $this->makeUser('Gerencia', self::STAFF);
        [$contractId, $customerId] = $this->draft();
        $client = $this->portalUser($customerId);
        $other = $this->portalUser($this->makeCustomer());

        $this->as($staff);
        $requestId = $this->postJson("/api/v1/contracts/{$contractId}/signature-requests")->assertOk()->json('signature.id');

        // La empresa cambia las condiciones después de enviarlo: la firma ya no procede.
        $this->putJson("/api/v1/contracts/{$contractId}", [
            'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString(), 'sla_level' => 'ESTANDAR', 'terms' => 'Pago a 15 días.',
        ])->assertOk()->assertJsonPath('signature.changed', true);
        $this->as($client);
        $this->postJson("/api/v1/portal/signatures/{$requestId}/code")->assertOk();
        $this->postJson("/api/v1/portal/signatures/{$requestId}/sign", ['typed_name' => 'Rosa Quispe', 'accept' => true, 'code' => $this->code(ContractSignatureCodeMail::class)])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        // Otro cliente no la ve; el cliente la rechaza con motivo.
        $this->as($other);
        $this->postJson("/api/v1/portal/signatures/{$requestId}/decline", ['reason' => 'x'])->assertNotFound();
        $this->as($client);
        $this->postJson("/api/v1/portal/signatures/{$requestId}/decline", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $history = $this->postJson("/api/v1/portal/signatures/{$requestId}/decline", ['reason' => 'El plazo de pago cambió.'])->assertOk()->json('history.0');
        $this->assertSame(['DECLINED', 'El plazo de pago cambió.'], [$history['status'], $history['declined_reason']]);

        // La empresa anula la rechazada y la vuelve a enviar.
        $this->as($staff);
        $this->postJson("/api/v1/contracts/{$contractId}/signature-requests/{$requestId}/cancel")->assertOk()->assertJsonPath('signature.status', 'CANCELLED');
        $this->assertSame('NONE', DB::table('contracts')->where('id', $contractId)->value('signature_status'));
        $again = $this->postJson("/api/v1/contracts/{$contractId}/signature-requests")->assertOk()->json('signature');
        $this->assertSame([false, 'PENDING'], [$again['changed'], $again['status']]);

        // A los 15 días, sin firmar, vence.
        $this->travel(16)->days();
        $this->getJson("/api/v1/contracts/{$contractId}")->assertOk()->assertJsonPath('signature.status', 'EXPIRED');
        $this->as($client);
        $this->assertSame([], $this->getJson('/api/v1/portal/signatures')->assertOk()->json('pending'));
    }

    public function test_a_contract_sent_to_signature_is_not_activated_until_signed_or_cancelled(): void
    {
        Mail::fake();
        $this->makeTenant(['CONTRATOS', 'PORTAL']);
        $staff = $this->makeUser('Gerencia', self::STAFF);
        [$contractId, $customerId] = $this->draft();
        $client = $this->portalUser($customerId);

        // La empresa no exige la firma, pero el contrato se envió a firma: espera.
        $this->as($staff);
        $requestId = $this->postJson("/api/v1/contracts/{$contractId}/signature-requests")->assertOk()->json('signature.id');
        $this->postJson("/api/v1/contracts/{$contractId}/activate")->assertStatus(422)->assertJsonValidationErrors('status');

        // Rechazada por el cliente: tampoco, hasta anularla.
        $this->as($client);
        $this->postJson("/api/v1/portal/signatures/{$requestId}/decline", ['reason' => 'Revisar el plazo.'])->assertOk();
        $this->as($staff);
        $this->postJson("/api/v1/contracts/{$contractId}/activate")->assertStatus(422)->assertJsonValidationErrors('status');

        // Anulada, se activa como siempre.
        $this->postJson("/api/v1/contracts/{$contractId}/signature-requests/{$requestId}/cancel")->assertOk();
        $this->postJson("/api/v1/contracts/{$contractId}/activate", ['generate_services' => false])->assertOk()->assertJsonPath('status', 'ACTIVE');
    }

    public function test_activation_requires_both_signatures_when_the_company_asks_for_it(): void
    {
        Mail::fake();
        $this->makeTenant(['CONTRATOS', 'PORTAL']);
        $staff = $this->makeUser('Gerencia', [...self::STAFF, 'tenant.manage']);
        [$contractId] = $this->draft();

        $this->as($staff);
        $this->putJson('/api/v1/tenant/settings', ['require_signature' => true])->assertOk()->assertJsonPath('require_signature', true);
        $this->postJson("/api/v1/contracts/{$contractId}/activate")->assertStatus(422)->assertJsonValidationErrors('status');

        DB::table('contracts')->where('id', $contractId)->update(['signature_status' => 'SIGNED']);
        $this->postJson("/api/v1/contracts/{$contractId}/activate", ['generate_services' => false])->assertOk()->assertJsonPath('status', 'ACTIVE');
        // Ya activo, no se envía a firma.
        $this->postJson("/api/v1/contracts/{$contractId}/signature-requests")->assertStatus(422);
    }
}
