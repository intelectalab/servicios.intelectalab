<?php

namespace App\Mail;

use App\Mail\Concerns\UsesTenantBrand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Al administrador de una empresa: su enlace de ingreso con la marca (lo envía la plataforma desde la consola). */
class TenantLoginLinkMail extends Mailable
{
    use UsesTenantBrand;

    public function __construct(
        public string $companyName,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "El enlace de ingreso de {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.details',
            text: 'mail.details-text',
            with: [
                'heading' => 'Tu enlace de ingreso',
                'intro' => "{$this->companyName} ya tiene su propia página para entrar al sistema, con su logo y sus colores. Compártela con tu equipo y con tus clientes del portal.",
                'preview' => 'Tu página de ingreso con la marca de tu empresa.',
                'rows' => ['Enlace' => $this->loginUrl],
                'button' => ['label' => 'Abrir mi página de ingreso', 'url' => $this->loginUrl],
                'notice' => 'Lo encuentras siempre en Configuración → Marca de la empresa, donde también puedes cambiarlo y agregar un texto de bienvenida.',
            ],
        );
    }
}
