<?php

namespace App\Mail;

use App\Mail\Concerns\UsesTenantBrand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo de un evento de la empresa (MessageCenter): el aviso a una persona del equipo, para
 * quien tiene el correo activado en Mi perfil, o el correo al cliente. Lleva el texto de la
 * plantilla de la empresa, su marca, su remitente y, si se indica, la imagen de apertura.
 */
class NotificationMail extends Mailable
{
    use UsesTenantBrand;

    public function __construct(
        public string $title,
        public string $body,
        public string $companyName,
        public ?string $url,
        public string $audience = 'INTERNAL',
        public ?string $pixel = null,
        public ?string $senderName = null,
        public ?string $replyAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        $customer = $this->audience === 'CUSTOMER';

        return new Envelope(
            from: $this->senderName ? new Address((string) config('mail.from.address'), $this->senderName) : null,
            replyTo: $this->replyAddress ? [new Address($this->replyAddress)] : [],
            subject: $customer ? $this->title : "{$this->title} · {$this->companyName}",
        );
    }

    public function content(): Content
    {
        $customer = $this->audience === 'CUSTOMER';
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $this->body) ?: [])));

        return new Content(
            view: 'mail.details',
            text: 'mail.details-text',
            with: [
                'heading' => $this->title,
                'intro' => '',
                'paragraphs' => $paragraphs,
                'preview' => $paragraphs[0] ?? $this->title,
                'rows' => $customer ? [] : ['Empresa' => $this->companyName],
                ...($this->url ? ['button' => ['label' => $customer ? 'Abrir en el portal' : 'Abrir en el sistema', 'url' => $this->url]] : []),
                ...($this->pixel ? ['pixel' => $this->pixel] : []),
                'notice' => $customer
                    ? "Recibes este correo porque eres cliente de {$this->companyName}."
                    : 'Recibes este correo porque tienes activados los avisos por correo. Puedes cambiarlo en Mi perfil → Preferencias de notificación.',
            ],
        );
    }
}
