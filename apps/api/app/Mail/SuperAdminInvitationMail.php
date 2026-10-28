<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitación a un Super Administrador de la plataforma: código para crear su contraseña. Sin marca de empresa. */
class SuperAdminInvitationMail extends Mailable
{
    public function __construct(
        public string $invitedBy,
        public string $code,
        public int $days,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Te dieron acceso a la consola de plataforma');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.code',
            text: 'mail.code-text',
            with: [
                'heading' => 'Crea tu contraseña',
                'intro' => "{$this->invitedBy} te dio acceso como Super Administrador de la plataforma ERP SaaS. Desde la consola se administran las empresas, sus planes y suscripciones; no incluye los datos de negocio de ninguna empresa. Usa este código para crear tu contraseña.",
                'preview' => "Tu código vence en {$this->days} días.",
                'expiry' => "Vence en {$this->days} días",
                'button' => ['label' => 'Crear mi contraseña', 'url' => $this->resetUrl],
                'notice' => 'Cada vez que entres te pediremos un código de verificación enviado a este correo. Si no esperabas este correo, ignóralo.',
            ],
        );
    }
}
