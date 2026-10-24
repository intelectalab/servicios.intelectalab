<?php

namespace App\Mail;

use App\Mail\Concerns\UsesTenantBrand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitación a un usuario creado en Administración → Usuarios: código y enlace para crear su contraseña. */
class UserInvitationMail extends Mailable
{
    use UsesTenantBrand;

    public function __construct(
        public string $companyName,
        public string $roleName,
        public string $code,
        public int $days,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Te dieron acceso a {$this->companyName}");
    }

    public function content(): Content
    {
        $role = $this->roleName !== '' ? " con el rol {$this->roleName}" : '';

        return new Content(
            view: 'mail.code',
            text: 'mail.code-text',
            with: [
                'heading' => 'Crea tu contraseña',
                'intro' => "{$this->companyName} te dio acceso a su ERP{$role}. Usa este código para crear tu contraseña y entrar.",
                'preview' => "Tu código vence en {$this->days} días.",
                'minutes' => $this->days * 24 * 60,
                'expiry' => "Vence en {$this->days} días",
                'button' => ['label' => 'Crear mi contraseña', 'url' => $this->resetUrl],
                'notice' => 'Si no esperabas este correo, ignóralo.',
            ],
        );
    }
}
