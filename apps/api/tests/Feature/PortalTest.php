<?php

namespace Tests\Feature;

use App\Mail\MfaCodeMail;
use App\Mail\PortalInvitationMail;
use App\Models\User;
use App\Services\AuditCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Portal de clientes: invitación y activación, entrada con verificación en dos pasos, el cliente
 * solo ve lo suyo (y nunca las pantallas internas), roles del portal, módulo del plan,
 * cotizaciones con su decisión, servicios y renovación, solicitudes por la bandeja o el CRM,
 * tickets sin notas internas, usuarios de la empresa, documentos, facturas, avisos y lo que
 * garantiza la base.
 */
class PortalTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const STAFF = ['portal_users.view', 'portal_users.create', 'portal_users.manage', 'portal_users.export'];

    private function portalTenant(array $modules = ['PORTAL', 'COTIZACIONES', 'CONTRATOS', 'FINANZAS', 'COMPRAS', 'CONTABILIDAD', 'SOPORTE']): string
    {
        return $this->makeTenant($modules);
    }

    /** Cuenta del portal ya activa, con su rol, para un cliente. */
    private function portalUser(string $customerId, string $role = 'PORTAL_ADMIN', string $status = 'ACTIVE'): User
    {
        $user = User::create([
            'email' => 'cliente-'.Str::random(8).'@cliente.test', 'password_hash' => bcrypt('Cliente123'), 'name' => 'Ana Cliente '.Str::random(4),
            'status' => 'ACTIVE', 'mfa_enabled' => true,
        ]);
        DB::table('portal_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'customer_id' => $customerId,
            'role' => $role, 'status' => $status, 'created_at' => now(),
        ]);

        return $user;
    }

    private function quotation(string $customerId, string $status = 'SUBMITTED', ?string $validUntil = null, ?User $by = null): string
    {
        $id = (string) Str::uuid();
        DB::table('quotations')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'customer_id' => $customerId, 'quotation_number' => 'COT-'.Str::upper(Str::random(6)),
            'status' => $status, 'version' => 1, 'currency' => 'PEN', 'valid_until' => $validUntil ?? now()->addDays(10)->toDateString(),
            'subtotal' => 100, 'tax_total' => 18, 'discount_total' => 0, 'total' => 118, 'notes' => 'Pago a 30 días.', 'created_by' => $by?->id,
        ]);
        DB::table('quotation_items')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'quotation_id' => $id, 'description' => 'Mantenimiento preventivo',
            'quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'tax_rate' => 18, 'tax_amount' => 18, 'line_total' => 118,
        ]);

        return $id;
    }

    public function test_invitation_activation_and_login_with_two_step_verification(): void
    {
        Mail::fake();
        $this->portalTenant();
        $customerId = $this->makeCustomer();
        $staff = $this->makeUser('Administración', self::STAFF);
        $this->as($staff);

        $this->postJson('/api/v1/portal-users', [])->assertStatus(422)->assertJsonValidationErrors(['customer_id', 'name', 'email', 'role']);
        // Una cuenta del sistema no puede ser del portal (el correo es único en la plataforma).
        $this->postJson('/api/v1/portal-users', ['customer_id' => $customerId, 'name' => 'X', 'email' => $staff->email, 'role' => 'PORTAL_ADMIN'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $invited = $this->postJson('/api/v1/portal-users', [
            'customer_id' => $customerId, 'name' => 'Rosa Quispe', 'email' => 'Rosa@Cliente.test', 'role' => 'PORTAL_ADMIN',
        ])->assertCreated()->json();
        $this->assertSame(['INVITED', 'rosa@cliente.test', 'Portal Admin'], [$invited['status'], $invited['email'], $invited['role_name']]);

        $code = null;
        Mail::assertSent(PortalInvitationMail::class, function (PortalInvitationMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('rosa@cliente.test') && str_ends_with($mail->activateUrl, '/portal/activate');
        });

        // Invitada: todavía no entra.
        $this->postJson('/api/v1/login', ['email' => 'rosa@cliente.test', 'password' => 'Cualquiera1'])->assertStatus(422);

        // Reenviar invalida el código anterior.
        $this->postJson("/api/v1/portal-users/{$invited['id']}/resend")->assertOk();
        $old = $code;
        Mail::assertSent(PortalInvitationMail::class, function (PortalInvitationMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $this->assertNotSame($old, $code);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/portal/activate', ['email' => 'rosa@cliente.test', 'code' => $old, 'password' => 'Portal2026', 'password_confirmation' => 'Portal2026'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/portal/activate', ['email' => 'rosa@cliente.test', 'code' => strtolower($code), 'password' => 'Portal2026', 'password_confirmation' => 'Portal2026'])
            ->assertOk();
        // El código no sirve dos veces.
        $this->postJson('/api/v1/portal/activate', ['email' => 'rosa@cliente.test', 'code' => $code, 'password' => 'Portal2026', 'password_confirmation' => 'Portal2026'])
            ->assertStatus(422);
        $this->assertDatabaseHas('portal_users', ['id' => $invited['id'], 'status' => 'ACTIVE', 'invite_code_hash' => null]);

        // El portal siempre pide la verificación en dos pasos y la sesión llega marcada como del portal.
        $login = $this->postJson('/api/v1/login', ['email' => 'rosa@cliente.test', 'password' => 'Portal2026'])->assertOk();
        $this->assertTrue($login->json('mfa_required'));
        $mfa = null;
        Mail::assertSent(MfaCodeMail::class, function (MfaCodeMail $mail) use (&$mfa) {
            $mfa = $mail->code;

            return $mail->hasTo('rosa@cliente.test');
        });
        $session = $this->postJson('/api/v1/login/mfa', ['mfa_token' => $login->json('mfa_token'), 'code' => $mfa])->assertOk();
        $this->assertTrue($session->json('user.portal'));
        $this->assertNotNull(DB::table('portal_users')->where('id', $invited['id'])->value('last_login_at'));

        foreach (DB::table('audit_logs')->where('entity_type', 'PortalUser')->pluck('action')->unique() as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS);
        }
    }

    public function test_a_portal_account_only_sees_its_customer_and_never_the_internal_screens(): void
    {
        $this->portalTenant();
        $mine = $this->makeCustomer('Clínica San Pablo SAC');
        $other = $this->makeCustomer('Banco Andino SA');
        $staff = $this->makeUser('Ventas', ['quotations.view', 'core.view']);
        $client = $this->portalUser($mine);

        $submitted = $this->quotation($mine);
        $draft = $this->quotation($mine, 'DRAFT');
        $foreign = $this->quotation($other);

        $this->as($client);
        $me = $this->getJson('/api/v1/portal/me')->assertOk()->json();
        $this->assertSame(['Clínica San Pablo SAC', 'PORTAL_ADMIN', true], [$me['customer']['name'], $me['role'], $me['enabled']]);
        $this->assertContains('quotes', $me['sections']);

        // Sin borradores ni cotizaciones de otro cliente: no existen (404).
        $this->assertSame([$submitted], array_column($this->getJson('/api/v1/portal/quotations')->assertOk()->json(), 'id'));
        $this->getJson("/api/v1/portal/quotations/{$draft}")->assertNotFound();
        $this->getJson("/api/v1/portal/quotations/{$foreign}")->assertNotFound();
        $this->postJson("/api/v1/portal/quotations/{$foreign}/decision", ['decision' => 'APPROVED'])->assertNotFound();

        // Una sesión del portal no abre ninguna ruta interna.
        $this->getJson('/api/v1/me/access')->assertForbidden();
        $this->getJson('/api/v1/quotations')->assertForbidden();
        $this->getJson('/api/v1/portal-users')->assertForbidden();

        // Ni una cuenta del sistema entra al portal.
        $this->as($staff);
        $this->getJson('/api/v1/portal/me')->assertForbidden()->assertJsonPath('code', 'NOT_PORTAL_USER');

        // Desactivada, la cuenta del portal ya no entra.
        DB::table('portal_users')->where('user_id', $client->id)->update(['status' => 'INACTIVE']);
        $this->as($client);
        $this->getJson('/api/v1/portal/me')->assertForbidden();
    }

    public function test_portal_roles_and_the_plan_module_decide_the_sections(): void
    {
        $this->portalTenant(['PORTAL', 'COTIZACIONES', 'FINANZAS', 'COMPRAS', 'CONTABILIDAD']);
        $customerId = $this->makeCustomer();
        $finance = $this->portalUser($customerId, 'PORTAL_FINANCE');
        $viewer = $this->portalUser($customerId, 'PORTAL_VIEWER');
        $quotation = $this->quotation($customerId);

        $this->as($finance);
        $this->assertSame(['home', 'invoices', 'pay', 'documents'], $this->getJson('/api/v1/portal/me')->json('sections'));
        $this->getJson('/api/v1/portal/quotations')->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->getJson('/api/v1/portal/invoices')->assertOk();

        // Solo lectura ve cotizaciones pero no decide; sin el módulo de la empresa, la sección no se abre.
        $this->as($viewer);
        $sections = $this->getJson('/api/v1/portal/me')->json('sections');
        $this->assertContains('quotes', $sections);
        $this->assertNotContains('support', $sections);
        $this->assertNotContains('services', $sections);
        $this->getJson("/api/v1/portal/quotations/{$quotation}")->assertOk()->assertJsonPath('can_decide', false);
        $this->postJson("/api/v1/portal/quotations/{$quotation}/decision", ['decision' => 'APPROVED'])->assertForbidden();
        $this->getJson('/api/v1/portal/tickets')->assertForbidden();

        // Sin el módulo del portal: la cuenta entra, ve "Portal desactivado" y ninguna sección responde.
        DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'PORTAL')->update(['enabled' => false]);
        $me = $this->getJson('/api/v1/portal/me')->assertOk()->json();
        $this->assertSame([false, []], [$me['enabled'], $me['sections']]);
        $this->getJson('/api/v1/portal/quotations')->assertForbidden()->assertJsonPath('code', 'PORTAL_DISABLED');
    }

    public function test_the_customer_approves_or_rejects_a_sent_quotation(): void
    {
        $this->portalTenant();
        $customerId = $this->makeCustomer();
        $seller = $this->makeUser('Ventas', ['quotations.view']);
        $client = $this->portalUser($customerId);
        $approved = $this->quotation($customerId, by: $seller);
        $rejected = $this->quotation($customerId);
        $expired = $this->quotation($customerId, validUntil: now()->subDay()->toDateString());

        $this->as($client);
        $detail = $this->getJson("/api/v1/portal/quotations/{$approved}")->assertOk()->json();
        $this->assertSame([true, false, 'Ventas', 1], [$detail['can_decide'], $detail['expired'], $detail['creator']['name'], count($detail['items'])]);
        $this->assertArrayNotHasKey('history', $detail);

        $done = $this->postJson("/api/v1/portal/quotations/{$approved}/decision", ['decision' => 'APPROVED', 'comment' => 'Conforme'])->assertOk()->json();
        $this->assertSame(['APPROVED', 'PORTAL', 'Conforme'], [$done['status'], $done['approvals'][0]['channel'], $done['approvals'][0]['comment']]);
        $this->postJson("/api/v1/portal/quotations/{$approved}/decision", ['decision' => 'REJECTED', 'comment' => 'x'])->assertStatus(422);

        // Rechazar pide motivo; una vencida ya no se decide.
        $this->postJson("/api/v1/portal/quotations/{$rejected}/decision", ['decision' => 'REJECTED'])->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->postJson("/api/v1/portal/quotations/{$rejected}/decision", ['decision' => 'REJECTED', 'comment' => 'Precio alto'])->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->postJson("/api/v1/portal/quotations/{$expired}/decision", ['decision' => 'APPROVED'])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('quotation_approvals', ['quotation_id' => $approved, 'channel' => 'PORTAL', 'approver_user_id' => $client->id]);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $approved, 'action' => 'quotation.approve', 'user_id' => $client->id]);
    }

    public function test_requests_go_to_the_inbox_or_to_the_crm_and_renewals_are_not_repeated(): void
    {
        $this->portalTenant();
        $customerId = $this->makeCustomer();
        $client = $this->portalUser($customerId);
        $service = $this->makeService('Soporte mensual', true);
        $seller = $this->makeUser('Ventas', ['portal.view', 'portal.manage', 'quotations.view', 'quotations.manage', 'opportunities.view', 'opportunities.manage', 'core.view']);
        $contract = $this->makeContract($seller, customerId: $customerId);
        $line = $this->makeContracted(['service_id' => $service, 'recurring' => true], $contract);
        DB::table('recurring_service_instances')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'contracted_service_id' => $line, 'customer_id' => $customerId,
            'status' => 'ACTIVE', 'frequency' => 'MONTHLY', 'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addDays(20)->toDateString(),
            'next_cycle_date' => now()->addDays(5)->toDateString(), 'unit_price' => 100, 'quantity' => 1, 'currency' => 'PEN',
        ]);

        // Sin CRM: a la bandeja.
        $this->as($client);
        $this->postJson('/api/v1/portal/requests', ['service_id' => $service])->assertStatus(422)->assertJsonValidationErrors('description');
        $erp = $this->postJson('/api/v1/portal/requests', ['service_id' => $service, 'description' => 'Necesitamos dos visitas más.'])->assertCreated()->json();
        $this->assertSame(['SUBMITTED', 'Soporte mensual'], [$erp['status'], $erp['subject']]);
        $this->assertSame('ERP', DB::table('portal_requests')->where('id', $erp['id'])->value('route'));

        // Mis servicios: renovación próxima, una sola vez.
        $services = $this->getJson('/api/v1/portal/services')->assertOk()->json();
        $this->assertSame([true, true], [$services['services'][0]['renewal_soon'], $services['services'][0]['can_renew']]);
        $this->postJson("/api/v1/portal/services/{$line}/renewal")->assertCreated()->assertJsonPath('request_type', 'RENEWAL');
        $this->postJson("/api/v1/portal/services/{$line}/renewal")->assertStatus(422);
        $this->assertTrue($this->getJson('/api/v1/portal/services')->json('services.0.renewal_requested'));

        // Con CRM: además nace la oportunidad, a nombre de quien vende al cliente.
        DB::table('tenant_modules')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'module_code' => 'CRM', 'enabled' => true]);
        $crm = $this->postJson('/api/v1/portal/requests', ['service_id' => $service, 'description' => 'Cotícenme el plan anual.'])->assertCreated()->json();
        $stored = DB::table('portal_requests')->where('id', $crm['id'])->first();
        $opportunity = DB::table('crm_opportunities')->where('id', $stored->crm_opportunity_id)->first();
        $this->assertSame(['CRM', 'OPEN', $seller->id, $customerId], [$stored->route, $opportunity->status, $opportunity->owner_user_id, $opportunity->customer_id]);

        // En la bandeja no se toma ni se cotiza: se atiende desde su oportunidad, y cotizarla la deja cotizada.
        $this->as($seller);
        $this->postJson("/api/v1/portal-requests/{$crm['id']}/take")->assertStatus(422);
        $this->getJson('/api/v1/portal-requests')->assertOk()->assertJsonFragment(['opportunity_number' => $opportunity->opportunity_number]);
        $this->postJson('/api/v1/crm/opportunities/'.$opportunity->id.'/lose', ['reason' => 'Eligió otro proveedor'])->assertOk();
        $this->assertSame(['REJECTED', 'Eligió otro proveedor'], [
            DB::table('portal_requests')->where('id', $crm['id'])->value('status'), DB::table('portal_requests')->where('id', $crm['id'])->value('resolution_note'),
        ]);

        // El cliente ve sus solicitudes con el motivo del rechazo.
        $this->as($client);
        $mine = collect($this->getJson('/api/v1/portal/requests')->assertOk()->json())->keyBy('id');
        $this->assertSame('Eligió otro proveedor', $mine[$crm['id']]['resolution_note']);
        $this->assertCount(3, $mine);
    }

    public function test_tickets_from_the_portal_hide_internal_notes_and_a_reply_resumes_the_clock(): void
    {
        $this->portalTenant();
        $customerId = $this->makeCustomer();
        $this->makeContract(customerId: $customerId);
        $client = $this->portalUser($customerId, 'PORTAL_ADMIN');
        $viewer = $this->portalUser($customerId, 'PORTAL_VIEWER');
        $agent = $this->makeUser('Soporte', ['tickets.view', 'tickets.manage', 'tickets.create']);

        $this->as($client);
        $this->postJson('/api/v1/portal/tickets', ['subject' => 'VPN caída'])->assertStatus(422)->assertJsonValidationErrors('description');
        $ticket = $this->postJson('/api/v1/portal/tickets', ['subject' => 'VPN caída', 'description' => 'No conecta desde la sede.'])->assertCreated()->json();
        $this->assertSame(['OPEN', 'RECEIVED', true], [$ticket['status'], $ticket['attention'], $ticket['comments'][0]['from_customer']]);
        $id = $ticket['id'];
        $this->assertSame('PORTAL', DB::table('support_tickets')->where('id', $id)->value('channel'));

        $this->as($agent);
        $this->postJson("/api/v1/tickets/{$id}/triage", ['priority' => 'P2'])->assertOk();
        $this->postJson("/api/v1/tickets/{$id}/assign", ['assigned_user_id' => $agent->id])->assertOk();
        $this->postJson("/api/v1/tickets/{$id}/start")->assertOk();
        $this->postJson("/api/v1/tickets/{$id}/comments", ['body' => 'Revisar el firewall interno.', 'internal' => true])->assertCreated();
        $this->postJson("/api/v1/tickets/{$id}/comments", ['body' => '¿Pueden reiniciar el router?'])->assertCreated();
        $this->postJson("/api/v1/tickets/{$id}/wait")->assertOk();

        $this->as($client);
        $seen = $this->getJson("/api/v1/portal/tickets/{$id}")->assertOk()->json();
        $this->assertSame(['No conecta desde la sede.', '¿Pueden reiniciar el router?'], array_column($seen['comments'], 'body'));
        // Ni las notas internas ni cuántas hay llegan al cliente.
        $this->assertArrayNotHasKey('internal_count', $seen);
        $this->assertNull($seen['comments'][1]['author']);
        $this->travel(2)->hours();
        $replied = $this->postJson("/api/v1/portal/tickets/{$id}/comments", ['body' => 'Listo, ya reiniciamos.'])->assertCreated()->json();
        $this->assertSame('IN_PROGRESS', $replied['status']);
        $this->assertNull(DB::table('support_tickets')->where('id', $id)->value('paused_at'));
        $this->assertGreaterThan(0, (int) DB::table('support_tickets')->where('id', $id)->value('paused_seconds'));
        $this->assertSame('PORTAL', DB::table('support_ticket_comments')->where('ticket_id', $id)->orderByDesc('created_at')->value('author_type'));

        // Solo lectura ve los tickets de su empresa, pero no los crea ni los responde.
        $this->as($viewer);
        $this->assertNotContains('support_write', $this->getJson('/api/v1/portal/me')->json('sections'));
        $this->getJson('/api/v1/portal/tickets')->assertOk();
        $this->getJson("/api/v1/portal/tickets/{$id}")->assertOk();
        $this->postJson('/api/v1/portal/tickets', ['subject' => 'Otro', 'description' => 'Otro problema.'])->assertForbidden();
        $this->postJson("/api/v1/portal/tickets/{$id}/comments", ['body' => 'Hola'])->assertForbidden();

        // Un ticket de otro cliente no existe.
        $foreign = $this->portalUser($this->makeCustomer(), 'PORTAL_ADMIN');
        $this->as($foreign);
        $this->getJson("/api/v1/portal/tickets/{$id}")->assertNotFound();
        $this->postJson("/api/v1/portal/tickets/{$id}/comments", ['body' => 'Hola'])->assertNotFound();
    }

    public function test_the_portal_admin_manages_its_team_with_the_portal_rules(): void
    {
        Mail::fake();
        $this->portalTenant();
        $customerId = $this->makeCustomer();
        $admin = $this->portalUser($customerId);
        $otherCustomerUser = $this->portalUser($this->makeCustomer());
        $otherId = DB::table('portal_users')->where('user_id', $otherCustomerUser->id)->value('id');
        $adminId = DB::table('portal_users')->where('user_id', $admin->id)->value('id');

        $this->as($admin);
        $team = $this->getJson('/api/v1/portal/users')->assertOk()->json();
        $this->assertSame([1, true, 10], [count($team['users']), $team['users'][0]['is_me'], $team['max_users']]);

        $viewer = $this->postJson('/api/v1/portal/users', ['name' => 'Luis Rojas', 'email' => 'luis@cliente.test', 'role' => 'PORTAL_VIEWER'])->assertCreated()->json();
        $this->assertSame([$customerId, 'INVITED'], [$viewer['customer_id'], $viewer['status']]);
        $this->assertSame('PORTAL', DB::table('portal_users')->where('id', $viewer['id'])->value('invited_by_type'));

        // Nadie se desactiva a sí mismo; siempre queda un Portal Admin activo.
        $this->patchJson("/api/v1/portal/users/{$adminId}", ['status' => 'INACTIVE'])->assertStatus(422);
        $this->as($this->makeUser('Administración', self::STAFF));
        $this->patchJson("/api/v1/portal-users/{$adminId}", ['role' => 'PORTAL_VIEWER'])->assertStatus(422)->assertJsonValidationErrors('role');

        // Un usuario de otro cliente "no existe" para este Portal Admin.
        $this->as($admin);
        $this->patchJson("/api/v1/portal/users/{$otherId}", ['status' => 'INACTIVE'])->assertNotFound();

        // Hasta 10 usuarios vigentes por cliente.
        for ($i = 3; $i <= 10; $i++) {
            $this->postJson('/api/v1/portal/users', ['name' => "Persona {$i}", 'email' => "p{$i}@cliente.test", 'role' => 'PORTAL_VIEWER'])->assertCreated();
        }
        $this->postJson('/api/v1/portal/users', ['name' => 'Once', 'email' => 'p11@cliente.test', 'role' => 'PORTAL_VIEWER'])->assertStatus(422);

        // Desactivar libera un cupo y cierra sus sesiones.
        $this->patchJson("/api/v1/portal/users/{$viewer['id']}", ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->postJson('/api/v1/portal/users', ['name' => 'Once', 'email' => 'p11@cliente.test', 'role' => 'PORTAL_VIEWER'])->assertCreated();
        // Reactivarla ya no cabe.
        $this->patchJson("/api/v1/portal/users/{$viewer['id']}", ['status' => 'ACTIVE'])->assertStatus(422);
    }

    public function test_staff_screen_permissions_summary_export_and_notices(): void
    {
        $this->portalTenant();
        $customerId = $this->makeCustomer();
        $client = $this->portalUser($customerId);
        $this->portalUser($this->makeCustomer(), 'PORTAL_VIEWER', 'INVITED');
        $manager = $this->makeUser('Gerencia', ['portal_users.view']);
        $staff = $this->makeUser('Administración', self::STAFF);

        $this->as($manager);
        $this->assertSame(['active' => 1, 'invited' => 1, 'customers' => 2, 'mfa' => 1], $this->getJson('/api/v1/portal-users/summary')->assertOk()->json());
        $this->getJson('/api/v1/portal-users?status=ACTIVE')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/portal-users?export=1')->assertForbidden();
        $this->postJson('/api/v1/portal-notices', ['body' => 'Mantenimiento el sábado'])->assertForbidden();
        $this->postJson('/api/v1/portal-users', ['customer_id' => $customerId, 'name' => 'X', 'email' => 'x@cliente.test', 'role' => 'PORTAL_ADMIN'])->assertForbidden();

        $this->as($staff);
        $this->getJson('/api/v1/portal-users?export=1')->assertOk();
        $this->postJson('/api/v1/portal-notices', ['body' => 'x', 'link' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('link');
        $all = $this->postJson('/api/v1/portal-notices', ['body' => 'Mantenimiento programado el sábado.'])->assertCreated()->json();
        $this->postJson('/api/v1/portal-notices', ['customer_id' => $customerId, 'body' => 'Su contrato se renueva en 20 días.', 'link' => '/portal/services'])->assertCreated();
        $withdrawn = $this->postJson('/api/v1/portal-notices', ['body' => 'Aviso retirado'])->assertCreated()->json();
        $this->postJson("/api/v1/portal-notices/{$withdrawn['id']}/withdraw")->assertOk()->assertJsonPath('status', 'WITHDRAWN');
        $this->assertSame('ACTIVE', $all['status']);

        $this->as($client);
        $notices = array_column($this->getJson('/api/v1/portal/home')->assertOk()->json('notices'), 'body');
        $this->assertEqualsCanonicalizing(['Mantenimiento programado el sábado.', 'Su contrato se renueva en 20 días.'], $notices);
    }

    public function test_roles_screen_shows_the_customer_portal_role_read_only(): void
    {
        $this->portalTenant();
        $this->portalUser($this->makeCustomer());
        $this->portalUser($this->makeCustomer(), 'PORTAL_VIEWER', 'INVITED');
        $this->as($this->makeUser('Administración', ['users.view']));

        $customer = $this->getJson('/api/v1/roles')->assertOk()->json('customer_role');
        $this->assertSame([true, 1, 3], [$customer['enabled'], $customer['users'], count($customer['portal_roles'])]);
        $matrix = collect($customer['matrix'])->keyBy('resource');
        $this->assertSame(['view', 'approve'], $matrix['quotations']['actions']);
        $this->assertSame([], $matrix['warranty']['actions']);
        // Es una fila de la matriz, no un rol de la empresa: no aparece entre los roles editables.
        $this->assertNotContains('Cliente (Portal)', array_column($this->getJson('/api/v1/roles')->json('roles'), 'name'));
    }

    public function test_documents_invoices_and_statement_only_show_what_belongs_to_the_customer(): void
    {
        $this->portalTenant(['PORTAL', 'FINANZAS', 'COMPRAS', 'CONTABILIDAD', 'PROYECTOS']);
        $customerId = $this->makeCustomer();
        $otherId = $this->makeCustomer('Otro Cliente SAC');
        $client = $this->portalUser($customerId, 'PORTAL_FINANCE');

        // Entregable aprobado con archivo (visible) y uno en revisión (no).
        $project = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $project, 'tenant_id' => $this->tenantId, 'customer_id' => $customerId, 'code' => 'PRY-T-1', 'name' => 'Implantación']);
        $documents = [];
        foreach (['APPROVED' => 'Informe final', 'IN_REVIEW' => 'Borrador de informe'] as $status => $title) {
            $document = (string) Str::uuid();
            $file = (string) Str::uuid();
            DB::table('documents')->insert(['id' => $document, 'tenant_id' => $this->tenantId, 'document_type' => 'Entregable', 'title' => $title]);
            DB::table('file_objects')->insert([
                'id' => $file, 'tenant_id' => $this->tenantId, 'storage_provider' => 'local', 'bucket' => 'documents', 'object_key' => "t/{$file}.pdf",
                'original_name' => "{$title}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 2048, 'antivirus_status' => 'PENDING',
            ]);
            DB::table('document_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'document_id' => $document, 'file_object_id' => $file, 'version' => 1]);
            DB::table('deliverables')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'project_id' => $project, 'name' => $title, 'status' => $status, 'document_id' => $document, 'document_ref' => "{$title}.pdf",
            ]);
            $documents[$status] = $document;
        }

        // Comprobantes: uno emitido con saldo, un borrador (no se ve) y uno de otro cliente.
        $invoice = fn (string $customer, string $status, string $number) => tap((string) Str::uuid(), fn ($id) => DB::table('invoices')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'customer_id' => $customer, 'invoice_number' => $number, 'invoice_type' => 'INVOICE',
            'status' => $status, 'currency' => 'PEN', 'issue_date' => now()->subDays(40)->toDateString(), 'due_date' => now()->subDays(10)->toDateString(),
            'subtotal' => 100, 'tax_total' => 18, 'total' => 118, 'balance_due' => 118,
        ]));
        $issued = $invoice($customerId, 'ISSUED', 'F001-00000077');
        $invoice($customerId, 'DRAFT', 'F001-BORRADOR');
        $foreign = $invoice($otherId, 'ISSUED', 'F001-00000078');
        DB::table('receivables')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'customer_id' => $customerId, 'invoice_id' => $issued,
            'original_amount' => 118, 'balance_amount' => 118, 'currency' => 'PEN', 'due_date' => now()->subDays(10)->toDateString(), 'status' => 'OPEN',
        ]);

        $this->as($client);
        $listed = $this->getJson('/api/v1/portal/documents')->assertOk()->json();
        $this->assertSame([$documents['APPROVED']], array_column($listed, 'id'));
        $this->postJson("/api/v1/portal/documents/{$documents['IN_REVIEW']}/temporary-url")->assertNotFound();

        $finance = $this->getJson('/api/v1/portal/invoices')->assertOk()->json();
        $this->assertSame(['F001-00000077'], array_column($finance['invoices'], 'invoice_number'));
        $this->assertEquals([true, 118], [$finance['receivables'][0]['overdue'], $finance['receivables'][0]['balance_amount']]);
        $this->assertFalse($finance['online_payment']);
        $this->getJson("/api/v1/portal/invoices/{$issued}")->assertOk()->assertJsonPath('items', []);
        $this->getJson("/api/v1/portal/invoices/{$foreign}")->assertNotFound();
        $this->assertEquals(118, $this->getJson('/api/v1/portal/statement')->assertOk()->json('totals.0.balance'));

        $home = $this->getJson('/api/v1/portal/home')->assertOk()->json();
        $this->assertSame([true, null, 'PEN'], [$home['overdue'], $home['quotations_pending'], $home['balance'][0]['currency']]);
    }

    public function test_the_customer_downloads_its_contracts_from_documents_and_services(): void
    {
        $this->portalTenant(['PORTAL', 'CONTRATOS', 'FINANZAS', 'COMPRAS', 'CONTABILIDAD']);
        $customerId = $this->makeCustomer();
        [$active] = $this->makeContract(customerId: $customerId);
        [$draft] = $this->makeContract(status: 'DRAFT', customerId: $customerId);
        [$signedDraft] = $this->makeContract(status: 'DRAFT', customerId: $customerId);
        DB::table('contracts')->where('id', $signedDraft)->update(['signature_status' => 'SIGNED']);
        [$foreign] = $this->makeContract(customerId: $this->makeCustomer());
        DB::table('contract_items')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'contract_id' => $active, 'description' => 'Soporte mensual',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 118,
        ]);

        // Portal Finanzas abre Documentos (no Mis servicios): ve sus contratos vigentes y los borradores ya firmados.
        $this->as($this->portalUser($customerId, 'PORTAL_FINANCE'));
        $contracts = collect($this->getJson('/api/v1/portal/documents')->assertOk()->json())->where('kind', 'contract');
        $this->assertEqualsCanonicalizing([$active, $signedDraft], $contracts->pluck('id')->all());
        $pdf = $this->getJson("/api/v1/portal/contracts/{$active}")->assertOk()->json();
        $this->assertSame(['Soporte mensual', null], [$pdf['items'][0]['description'], $pdf['signature']]);
        $this->getJson("/api/v1/portal/contracts/{$draft}")->assertNotFound();
        $this->getJson("/api/v1/portal/contracts/{$foreign}")->assertNotFound();

        // Con el portal desactivado, tampoco.
        DB::table('tenant_modules')->where('tenant_id', $this->tenantId)->where('module_code', 'PORTAL')->update(['enabled' => false]);
        $this->getJson("/api/v1/portal/contracts/{$active}")->assertForbidden();
    }

    public function test_the_database_keeps_portal_accounts_apart_from_system_accounts(): void
    {
        $this->portalTenant();
        $customerId = $this->makeCustomer();
        $client = $this->portalUser($customerId);
        $staff = $this->makeUser('Ventas', []);
        $portalId = DB::table('portal_users')->where('user_id', $client->id)->value('id');

        // Una cuenta del portal no recibe acceso al sistema, ni una del sistema entra al portal.
        $this->assertDatabaseRejects(fn () => DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $client->id, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('portal_users')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'user_id' => $staff->id, 'customer_id' => $customerId, 'role' => 'PORTAL_VIEWER', 'status' => 'ACTIVE',
        ]));
        // No cambia de cliente, el rol es uno del portal y no se borra.
        $this->assertDatabaseRejects(fn () => DB::table('portal_users')->where('id', $portalId)->update(['customer_id' => $this->makeCustomer()]));
        $this->assertDatabaseRejects(fn () => DB::table('portal_users')->where('id', $portalId)->update(['role' => 'OWNER']));
        $this->assertDatabaseRejects(fn () => DB::table('portal_users')->where('id', $portalId)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('portal_notices')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'body' => '  ',
        ]));
    }
}
