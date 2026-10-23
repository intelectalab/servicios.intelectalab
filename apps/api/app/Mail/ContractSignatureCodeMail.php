<?php

namespace App\Mail;

use App\Mail\Concerns\UsesTenantBrand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Código de un solo uso para firmar un contrato (cliente o empresa). */
class ContractSignatureCodeMail extends Mailable
{
    use UsesTenantBrand;

    public function __construct(
        public string $code,
        public int $minutes,
        public string $contractNumber,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Código para firmar el contrato {$this->contractNumber}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.code',
            text: 'mail.code-text',
            with: [
                'heading' => 'Firma del contrato',
                'intro' => "Usa este código para firmar el contrato {$this->contractNumber}.",
                'preview' => "Vence en {$this->minutes} minutos.",
                'notice' => 'Si no estás firmando un contrato, ignora este correo.',
            ],
        );
    }
}
