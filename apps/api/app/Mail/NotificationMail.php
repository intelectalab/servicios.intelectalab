<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Copia por correo de una notificación del sistema, para quien tiene el correo activado en Mi perfil. */
class NotificationMail extends Mailable
{
    public function __construct(
        public string $title,
        public string $body,
        public string $companyName,
        public ?string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->title} · {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.details',
            text: 'mail.details-text',
            with: [
                'heading' => $this->title,
                'intro' => $this->body,
                'preview' => $this->body,
                'rows' => ['Empresa' => $this->companyName],
                ...($this->url ? ['button' => ['label' => 'Abrir en el sistema', 'url' => $this->url]] : []),
                'notice' => 'Recibes este correo porque tienes activados los avisos por correo. Puedes cambiarlo en Mi perfil → Preferencias de notificación.',
            ],
        );
    }
}
