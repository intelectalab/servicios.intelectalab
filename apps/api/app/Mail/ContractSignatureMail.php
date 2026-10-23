<?php

namespace App\Mail;

use App\Mail\Concerns\UsesTenantBrand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso al cliente: tiene un contrato por firmar en su portal. */
class ContractSignatureMail extends Mailable
{
    use UsesTenantBrand;

    /** @param array<string, string> $rows */
    public function __construct(
        public string $companyName,
        public string $contractNumber,
        public array $rows,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Contrato {$this->contractNumber} por firmar");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.details',
            text: 'mail.details-text',
            with: [
                'heading' => 'Tienes un contrato por firmar',
                'intro' => "{$this->companyName} te envió el contrato {$this->contractNumber} para tu firma electrónica. Revísalo y fírmalo desde el portal de clientes.",
                'preview' => "Contrato {$this->contractNumber} por firmar.",
                'rows' => $this->rows,
                'button' => ['label' => 'Revisar y firmar', 'url' => $this->url],
                'notice' => 'Para firmar te pediremos un código que llegará a este correo.',
            ],
        );
    }
}
