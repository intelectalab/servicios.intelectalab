<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso a los superadministradores de que una empresa se registró desde la página pública. */
class NewSubscriptionMail extends Mailable
{
    /** @param array<string, string> $rows */
    public function __construct(
        public string $companyName,
        public array $rows,
        public string $panelUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Nueva suscripción: {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.details',
            text: 'mail.details-text',
            with: [
                'heading' => 'Nueva suscripción',
                'intro' => "{$this->companyName} se registró desde la página de planes.",
                'preview' => "{$this->companyName} se registró.",
                'rows' => $this->rows,
                'button' => ['label' => 'Ver empresas', 'url' => $this->panelUrl],
            ],
        );
    }
}
