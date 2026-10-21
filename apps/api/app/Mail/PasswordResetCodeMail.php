<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetCodeMail extends Mailable
{
    public function __construct(
        public string $code,
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Código para crear una nueva contraseña');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.code',
            text: 'mail.code-text',
            with: [
                'heading' => 'Crea tu nueva contraseña',
                'intro' => 'Usa este código para crear tu nueva contraseña.',
                'preview' => "Vence en {$this->minutes} minutos.",
                'notice' => 'Si no solicitaste el cambio, ignora este correo.',
            ],
        );
    }
}
