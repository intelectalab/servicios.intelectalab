<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitación al portal de clientes: el código para activar el acceso y crear la contraseña. */
class PortalInvitationMail extends Mailable
{
    public function __construct(
        public string $code,
        public string $companyName,
        public string $customerName,
        public int $days,
        public string $activateUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Te invitaron al portal de clientes de {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.code',
            text: 'mail.code-text',
            with: [
                'heading' => 'Activa tu acceso al portal',
                'intro' => "{$this->companyName} te dio acceso a su portal de clientes como parte de {$this->customerName}. Usa este código para activar tu acceso y crear tu contraseña.",
                'preview' => "Tu código de invitación vence en {$this->days} días.",
                'minutes' => $this->days * 24 * 60,
                'expiry' => "Vence en {$this->days} días",
                'button' => ['label' => 'Activar mi acceso', 'url' => $this->activateUrl],
                'notice' => 'Si no esperabas esta invitación, ignora este correo.',
            ],
        );
    }
}
