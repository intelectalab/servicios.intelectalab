<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Bienvenida a quien registra su empresa: plan, vigencia, correo de acceso y enlace al inicio de sesión. */
class TenantWelcomeMail extends Mailable
{
    /** @param array<string, string> $rows */
    public function __construct(
        public string $companyName,
        public array $rows,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu empresa ya está lista');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.details',
            text: 'mail.details-text',
            with: [
                'heading' => 'Te damos la bienvenida',
                'intro' => "Registramos {$this->companyName}. Ya puedes iniciar sesión con tu correo y la contraseña que elegiste.",
                'preview' => 'Tu cuenta de administrador está lista.',
                'rows' => $this->rows,
                'button' => ['label' => 'Iniciar sesión', 'url' => $this->loginUrl],
                'notice' => 'Por seguridad no enviamos tu contraseña. Si la olvidas, usa «¿Olvidó su contraseña?» en el inicio de sesión. Tu enlace de ingreso lo puedes cambiar en Configuración → Marca de la empresa.',
            ],
        );
    }
}
