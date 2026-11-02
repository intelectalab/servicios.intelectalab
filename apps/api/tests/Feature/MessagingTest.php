<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\OperationsFixtures;
use Tests\TestCase;

/**
 * Notificaciones: el correo al cliente usa la plantilla de la empresa, queda en la bitácora y
 * marca su apertura; plantilla desactivada o correo apagado lo dejan Omitido; un fallido se
 * reintenta; lo enviado no se reescribe; permisos de ver y gestionar.
 */
class MessagingTest extends TestCase
{
    use DatabaseTransactions;
    use OperationsFixtures;

    private const ADMIN = ['messaging.view', 'messaging.manage', 'messaging.export', 'tickets.view', 'tickets.manage'];

    private function ticket(string $customerId): string
    {
        $id = (string) Str::uuid();
        DB::table('support_tickets')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'customer_id' => $customerId,
            'ticket_number' => 'TCK-'.random_int(1000, 9999), 'subject' => 'Impresora sin red', 'status' => 'OPEN',
        ]);

        return $id;
    }

    private function customerWithEmail(): string
    {
        $customer = $this->makeCustomer('Minera Correo SAC');
        $party = DB::table('customers')->where('id', $customer)->value('party_id');
        DB::table('contacts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'party_id' => $party,
            'contact_type' => 'EMAIL', 'value' => 'compras@minera.test', 'is_primary' => true,
        ]);

        return $customer;
    }

    /** Corre lo que sale después de responder (los correos). */
    private function flush(): void
    {
        $this->app->terminate();
    }

    public function test_a_public_reply_emails_the_customer_with_the_company_template_and_tracks_the_opening(): void
    {
        Mail::fake();
        $this->makeTenant(['SOPORTE']);
        $this->as($this->makeUser('Admin', self::ADMIN));
        $ticket = $this->ticket($this->customerWithEmail());

        $this->putJson('/api/v1/message-templates/customer.ticket_replied', [
            'subject' => 'Novedades de tu caso {numero}', 'body' => "Estimado {cliente}:\n\n{mensaje}", 'active' => true,
        ])->assertOk();
        // Una variable que no existe no se guarda.
        $this->putJson('/api/v1/message-templates/customer.ticket_replied', ['subject' => 'x', 'body' => '{monto}', 'active' => true])
            ->assertStatus(422)->assertJsonValidationErrors('body');

        $this->postJson("/api/v1/tickets/{$ticket}/comments", ['body' => 'Ya reiniciamos el equipo.'])->assertCreated();
        // La nota interna nunca sale al cliente.
        $this->postJson("/api/v1/tickets/{$ticket}/comments", ['body' => 'Revisar garantía', 'internal' => true])->assertCreated();
        $this->flush();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('compras@minera.test')
            && str_starts_with($mail->title, 'Novedades de tu caso TCK-')
            && str_contains($mail->body, 'Estimado Minera Correo SAC:') && str_contains($mail->body, 'Ya reiniciamos el equipo.')
            && $mail->audience === 'CUSTOMER' && $mail->pixel !== null);
        Mail::assertSentCount(1);

        $message = DB::table('outgoing_messages')->where('tenant_id', $this->tenantId)->first();
        $this->assertSame(['SENT', 'CUSTOMER', 1], [$message->status, $message->audience, $message->attempts]);
        $log = $this->getJson('/api/v1/outgoing-messages')->assertOk();
        $this->assertSame([1, 'Respuesta a un ticket del cliente'], [$log->json('total'), $log->json('data.0.event_label')]);

        // La imagen del correo marca la apertura, sin sesión; una segunda vez no la cambia.
        $this->app['auth']->forgetGuards();
        $this->get("/api/m/{$message->open_token}.gif")->assertOk()->assertHeader('Content-Type', 'image/gif');
        $opened = DB::table('outgoing_messages')->where('id', $message->id)->value('opened_at');
        $this->assertNotNull($opened);
        $this->get('/api/m/'.str_repeat('a', 48).'.gif')->assertOk();
        $this->assertSame(1, DB::table('outgoing_messages')->whereNotNull('opened_at')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_an_inactive_template_or_the_channel_off_leaves_the_message_skipped(): void
    {
        Mail::fake();
        $this->makeTenant(['SOPORTE']);
        $this->as($this->makeUser('Admin', self::ADMIN));
        $customer = $this->customerWithEmail();

        $this->putJson('/api/v1/message-templates/customer.ticket_replied', [
            'subject' => 'Respuesta a tu ticket {numero}', 'body' => '{mensaje}', 'active' => false,
        ])->assertOk();
        $this->postJson('/api/v1/tickets/'.$this->ticket($customer).'/comments', ['body' => 'Hola'])->assertCreated();

        $this->putJson('/api/v1/messaging/channels', ['email' => false, 'sender_name' => "Soporte\r\nBcc: x", 'reply_to' => 'soporte@minera.test'])->assertOk()
            ->assertJsonPath('channels.email', false)->assertJsonPath('channels.sender_name', 'Soporte Bcc: x');
        $this->postJson('/api/v1/message-templates/customer.ticket_replied/reset')->assertOk();
        $this->postJson('/api/v1/tickets/'.$this->ticket($customer).'/comments', ['body' => 'Hola otra vez'])->assertCreated();
        $this->flush();

        Mail::assertNothingSent();
        $errors = DB::table('outgoing_messages')->where('tenant_id', $this->tenantId)->orderBy('created_at')->pluck('error', 'status');
        $this->assertSame(['SKIPPED'], array_keys($errors->all()));
        $this->assertSame(2, DB::table('outgoing_messages')->where('tenant_id', $this->tenantId)->where('status', 'SKIPPED')->count());
        $show = $this->getJson('/api/v1/messaging')->assertOk();
        $template = collect($show->json('templates'))->firstWhere('event', 'customer.ticket_replied');
        $this->assertSame([false, false], [$template['active'], $template['customized']]);
    }

    public function test_a_customer_without_email_is_recorded_and_a_failed_send_can_be_retried(): void
    {
        $this->makeTenant(['SOPORTE']);
        $this->as($this->makeUser('Admin', self::ADMIN));

        // Sin correo del cliente: Omitido, con el motivo.
        $this->postJson('/api/v1/tickets/'.$this->ticket($this->makeCustomer('Sin Correo SAC')).'/comments', ['body' => 'Hola'])->assertCreated();
        $this->flush();
        $this->assertSame('El cliente no tiene correo registrado.', DB::table('outgoing_messages')->where('tenant_id', $this->tenantId)->value('error'));

        // El servidor de correo falla: Fallido; reintentar con el servidor bien lo envía.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
        app('mail.manager')->forgetMailers();
        $this->postJson('/api/v1/tickets/'.$this->ticket($this->customerWithEmail()).'/comments', ['body' => 'Seguimos'])->assertCreated();
        $this->flush();
        $failed = DB::table('outgoing_messages')->where('tenant_id', $this->tenantId)->where('status', 'FAILED')->first();
        $this->assertNotEmpty($failed->error);

        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
        $this->postJson("/api/v1/outgoing-messages/{$failed->id}/retry")->assertOk()->assertJsonPath('status', 'SENT')->assertJsonPath('attempts', 2);
        $this->postJson("/api/v1/outgoing-messages/{$failed->id}/retry")->assertStatus(422);

        // Lo enviado no se reescribe.
        try {
            DB::transaction(fn () => DB::table('outgoing_messages')->where('id', $failed->id)->update(['body' => 'Otro texto']));
            $this->fail('La base aceptó reescribir un mensaje.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('no se modifica', $e->getMessage());
        }
    }

    public function test_viewing_and_managing_need_their_permissions(): void
    {
        $this->makeTenant(['SOPORTE']);
        $this->as($this->makeUser('Gerencia', ['messaging.view']));
        $this->getJson('/api/v1/messaging')->assertOk()->assertJsonPath('kpis.active_templates', 23);
        $this->putJson('/api/v1/message-templates/ticket.created', ['subject' => 'x', 'body' => 'y', 'active' => true])->assertForbidden();
        $this->putJson('/api/v1/messaging/channels', ['email' => false])->assertForbidden();

        $this->as($this->makeUser('Ventas', ['tickets.view']));
        $this->getJson('/api/v1/messaging')->assertForbidden();
        $this->getJson('/api/v1/outgoing-messages')->assertForbidden();
    }
}
