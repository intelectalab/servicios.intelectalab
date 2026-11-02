<?php

namespace App\Services;

use App\Mail\NotificationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Todo correo que sale por un evento de la empresa pasa por aquí: el aviso a una persona del
 * equipo (Notifier) y el correo al cliente (cotización enviada, comprobante emitido, respuesta y
 * resolución de su ticket).
 *
 * - Usa la plantilla de la empresa para el evento (Administración → Notificaciones) o, si no la
 *   cambió, el texto del sistema. Plantilla desactivada o correo de la empresa apagado: el mensaje
 *   queda en la bitácora como Omitido, con el motivo.
 * - Cada mensaje queda en outgoing_messages con la operación (si se deshace, no hay mensaje) y
 *   sale después de responder; su estado pasa a Enviado o Fallido (con el error) y se puede
 *   reintentar. La imagen de apertura marca cuándo se abrió (aproximado: hay programas de correo
 *   que no cargan imágenes).
 */
class MessageCenter
{
    /** Eventos internos: los avisos de Notifier. El texto del sistema es el título y el detalle del aviso. */
    public const INTERNAL = [
        'quotation.submitted' => 'Cotización por aprobar',
        'quotation.approved' => 'Cotización aprobada',
        'quotation.rejected' => 'Cotización rechazada',
        'quotation.authorization_requested' => 'Cotización por autorizar',
        'quotation.authorized' => 'Cotización autorizada',
        'quotation.authorization_rejected' => 'Autorización rechazada',
        'contract.activated' => 'Contrato activado',
        'contract.customer_signed' => 'El cliente firmó el contrato',
        'contract.signed' => 'Contrato firmado',
        'contract.signature_declined' => 'Firma rechazada por el cliente',
        'work_order.assigned' => 'Orden de trabajo asignada',
        'time_entry.submitted' => 'Horas por aprobar',
        'cost_entry.submitted' => 'Costo por aprobar',
        'purchase_order.submitted' => 'Orden de compra por aprobar',
        'supplier_invoice.registered' => 'Comprobante de proveedor por aprobar',
        'portal_request.created' => 'Nueva solicitud del portal',
        'portal_request.opportunity' => 'Solicitud del portal → oportunidad',
        'ticket.created' => 'Nuevo ticket',
        'ticket.assigned' => 'Ticket asignado',
        'ticket.escalated' => 'Ticket escalado',
        'cash_count.difference' => 'Diferencia en arqueo de caja',
        'workflow.notify' => 'Aviso de un workflow',
    ];

    public const INTERNAL_VARIABLES = ['titulo', 'detalle', 'empresa', 'enlace'];

    /** Correos al cliente: nombre, variables y texto del sistema. */
    public const CUSTOMER = [
        'customer.quotation_sent' => [
            'label' => 'Cotización enviada al cliente',
            'variables' => ['cliente', 'numero', 'total', 'vigencia', 'empresa', 'enlace'],
            'subject' => 'Cotización {numero} de {empresa}',
            'body' => "Hola, {cliente}:\n\nTe enviamos la cotización {numero} por {total}, válida hasta el {vigencia}.\n\nGracias por tu interés.",
        ],
        'customer.invoice_issued' => [
            'label' => 'Comprobante emitido',
            'variables' => ['cliente', 'tipo', 'numero', 'total', 'vencimiento', 'empresa', 'enlace'],
            'subject' => 'Comprobante {numero} de {empresa}',
            'body' => "Hola, {cliente}:\n\nEmitimos tu {tipo} {numero} por {total}, con vencimiento el {vencimiento}.\n\nGracias por tu preferencia.",
        ],
        'customer.ticket_replied' => [
            'label' => 'Respuesta a un ticket del cliente',
            'variables' => ['cliente', 'numero', 'asunto', 'mensaje', 'empresa', 'enlace'],
            'subject' => 'Respuesta a tu ticket {numero}',
            'body' => "Hola, {cliente}:\n\nRespondimos tu ticket {numero} ({asunto}):\n\n{mensaje}",
        ],
        'customer.ticket_resolved' => [
            'label' => 'Ticket del cliente resuelto',
            'variables' => ['cliente', 'numero', 'asunto', 'resolucion', 'empresa', 'enlace'],
            'subject' => 'Tu ticket {numero} fue resuelto',
            'body' => "Hola, {cliente}:\n\nResolvimos tu ticket {numero} ({asunto}).\n\nResolución: {resolucion}\n\nSi algo sigue sin funcionar, respóndenos desde tu portal.",
        ],
    ];

    /** Aviso a personas del equipo (lo llama Notifier). */
    public static function internal(string $tenantId, string $event, array $recipients, string $title, string $detail, ?string $link): void
    {
        $company = self::company($tenantId);
        $vars = ['titulo' => $title, 'detalle' => $detail, 'empresa' => $company, 'enlace' => $link ? self::frontend($link) : ''];
        [$subject, $body, $skip] = self::compose($tenantId, $event, '{titulo}', '{detalle}', $vars);

        $ids = [];
        foreach ($recipients as $recipient) {
            $ids[] = self::queue($tenantId, $event, 'INTERNAL', $recipient['email'], $recipient['name'] ?? null, $subject, $body, $link, $skip);
        }
        self::sendLater($tenantId, $ids);
    }

    /**
     * Correo al cliente: a sus usuarios activos del portal o, si no tiene, a su correo principal.
     * Sin correo, queda Omitido. $portalPath: la página del portal que se le enlaza.
     */
    public static function customer(string $tenantId, string $event, string $customerId, array $vars, ?string $portalPath = null): void
    {
        $definition = self::CUSTOMER[$event];
        $company = self::company($tenantId);
        $recipients = self::customerRecipients($tenantId, $customerId);
        $portal = $portalPath && $recipients['portal'] && in_array('PORTAL', \App\Modules\Subscription\Support\TenantModules::enabledCodes($tenantId), true);
        $link = $portal ? $portalPath : null;

        $vars = array_map(fn ($value) => (string) $value, $vars + [
            'cliente' => $recipients['name'], 'empresa' => $company, 'enlace' => $link ? self::frontend($link) : '',
        ]);
        [$subject, $body, $skip] = self::compose($tenantId, $event, $definition['subject'], $definition['body'], $vars);

        $ids = [];
        foreach ($recipients['emails'] ?: [null] as $recipient) {
            $ids[] = self::queue($tenantId, $event, 'CUSTOMER', $recipient['email'] ?? null, $recipient['name'] ?? $recipients['name'], $subject, $body, $link,
                $skip ?? ($recipient ? null : 'El cliente no tiene correo registrado.'), $customerId);
        }
        self::sendLater($tenantId, $ids);
    }

    /** Vuelve a enviar un mensaje fallido; devuelve su estado. */
    public static function retry(string $tenantId, string $messageId): string
    {
        self::deliver($tenantId, $messageId, true);

        return (string) DB::table('outgoing_messages')->where('id', $messageId)->value('status');
    }

    /** Texto de la plantilla de la empresa (o el del sistema) con las variables del evento. */
    public static function render(string $text, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $text);
    }

    /** Ajustes del canal de correo de la empresa. */
    public static function channels(string $tenantId): array
    {
        $configuration = json_decode(DB::table('tenants')->where('id', $tenantId)->value('configuration') ?: '{}', true) ?: [];
        $messaging = $configuration['messaging'] ?? [];

        return [
            'email' => (bool) ($messaging['email'] ?? true),
            'sender_name' => (string) ($messaging['sender_name'] ?? ''),
            'reply_to' => (string) ($messaging['reply_to'] ?? ''),
        ];
    }

    /** @return array{0: string, 1: string, 2: ?string} asunto, texto y motivo para omitirlo */
    private static function compose(string $tenantId, string $event, string $subject, string $body, array $vars): array
    {
        $template = DB::table('message_templates')->where('tenant_id', $tenantId)->where('event', $event)->where('channel', 'EMAIL')->first();
        $skip = null;
        if ($template && ! $template->active) {
            $skip = 'La plantilla de este evento está desactivada.';
        } elseif (! self::channels($tenantId)['email']) {
            $skip = 'El correo de la empresa está apagado (Notificaciones → Canales).';
        }

        return [
            Str::limit(trim(self::render($template->subject ?? $subject, $vars)), 250),
            trim(self::render($template->body ?? $body, $vars)),
            $skip,
        ];
    }

    private static function queue(string $tenantId, string $event, string $audience, ?string $email, ?string $name, string $subject, string $body, ?string $link, ?string $skip, ?string $customerId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('outgoing_messages')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'channel' => 'EMAIL',
            'event' => $event,
            'audience' => $audience,
            'to_address' => $email,
            'to_name' => $name ? Str::limit($name, 250) : null,
            'subject' => $subject,
            'body' => $body,
            'link' => $link,
            'status' => $skip ? 'SKIPPED' : 'QUEUED',
            'error' => $skip,
            'open_token' => $skip ? null : Str::random(48),
            'customer_id' => $customerId,
            'created_by' => request()->user()?->id,
            'created_at' => now(),
        ]);

        return $id;
    }

    /** Sale cuando la operación ya se guardó y después de responder a quien la hizo. */
    private static function sendLater(string $tenantId, array $ids): void
    {
        DB::afterCommit(fn () => app()->terminating(function () use ($tenantId, $ids) {
            foreach ($ids as $id) {
                self::deliver($tenantId, $id);
            }
        }));
    }

    /** Envía con la empresa del mensaje y deja la conexión como estaba. */
    private static function deliver(string $tenantId, string $id, bool $retry = false): void
    {
        $previous = (string) (DB::selectOne("SELECT current_setting('app.tenant_id', true) AS t")->t ?? '');
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
        try {
            self::send($tenantId, $id, $retry);
        } finally {
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$previous]);
        }
    }

    private static function send(string $tenantId, string $id, bool $retry): void
    {
        $message = DB::table('outgoing_messages')->where('id', $id)->first();
        // El envío automático toma solo lo que está en cola; un fallido se reintenta a pedido.
        if (! $message || $message->status !== ($retry ? 'FAILED' : 'QUEUED') || ! $message->to_address) {
            return;
        }

        $channels = self::channels($tenantId);
        $mail = new NotificationMail(
            $message->subject,
            $message->body,
            self::company($tenantId),
            $message->link ? self::frontend($message->link) : null,
            $message->audience,
            $message->open_token ? rtrim((string) config('app.url'), '/')."/api/m/{$message->open_token}.gif" : null,
            $channels['sender_name'] ?: null,
            $channels['reply_to'] ?: null,
        );

        try {
            Mail::to($message->to_address)->send($mail->withBrand($tenantId));
            DB::table('outgoing_messages')->where('id', $id)->update([
                'status' => 'SENT', 'error' => null, 'attempts' => $message->attempts + 1, 'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            DB::table('outgoing_messages')->where('id', $id)->update([
                'status' => 'FAILED', 'error' => Str::limit($e->getMessage(), 500), 'attempts' => $message->attempts + 1,
            ]);
        }
    }

    /** @return array{name: string, portal: bool, emails: array<int, array{email: string, name: ?string}>} */
    private static function customerRecipients(string $tenantId, string $customerId): array
    {
        $customer = DB::table('customers as c')->join('parties as p', 'p.id', '=', 'c.party_id')
            ->leftJoin('organizations as o', 'o.party_id', '=', 'p.id')
            ->leftJoin('persons as pe', 'pe.party_id', '=', 'p.id')
            ->where('c.id', $customerId)
            ->first(['c.party_id', DB::raw("COALESCE(o.trade_name, o.legal_name, trim(concat(pe.given_names, ' ', pe.family_name))) AS name")]);
        $name = (string) ($customer->name ?? 'cliente');

        $portal = DB::table('portal_users as pu')->join('users as u', 'u.id', '=', 'pu.user_id')
            ->where('pu.tenant_id', $tenantId)->where('pu.customer_id', $customerId)->where('pu.status', 'ACTIVE')
            ->get(['u.email', 'u.name'])
            ->map(fn ($row) => ['email' => (string) $row->email, 'name' => $row->name])->all();
        if ($portal) {
            return ['name' => $name, 'portal' => true, 'emails' => $portal];
        }

        $email = $customer ? DB::table('contacts')->where('party_id', $customer->party_id)->where('contact_type', 'EMAIL')
            ->orderByDesc('is_primary')->orderBy('created_at')->value('value') : null;

        return ['name' => $name, 'portal' => false, 'emails' => $email ? [['email' => (string) $email, 'name' => $name]] : []];
    }

    private static function company(string $tenantId): string
    {
        return (string) DB::table('tenants')->where('id', $tenantId)->value('name');
    }

    private static function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
