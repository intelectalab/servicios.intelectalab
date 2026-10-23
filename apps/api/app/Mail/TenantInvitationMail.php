<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitación al administrador de una empresa aprovisionada: código para crear su contraseña. */
class TenantInvitationMail extends Mailable
{
    public function __construct(
        public string $companyName,
        public string $code,
        public int $days,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tu cuenta de {$this->companyName} está lista");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.code',
            text: 'mail.code-text',
            with: [
                'heading' => 'Crea tu contraseña',
                'intro' => "Te dimos acceso como administrador de {$this->companyName}. Usa este código para crear tu contraseña.",
                'preview' => "Vence en {$this->days} días.",
                'expiry' => "Vence en {$this->days} días",
                'button' => ['label' => 'Crear mi contraseña', 'url' => $this->resetUrl],
                'notice' => 'Si no esperabas este correo, ignóralo.',
            ],
        );
    }
}
