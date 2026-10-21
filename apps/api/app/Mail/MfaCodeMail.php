<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MfaCodeMail extends Mailable
{
    public function __construct(
        public string $code,
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu código de verificación');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.code',
            text: 'mail.code-text',
            with: [
                'heading' => 'Tu código de verificación',
                'intro' => 'Escríbelo en la pantalla de inicio de sesión para terminar de entrar.',
                'preview' => "Vence en {$this->minutes} minutos.",
                'notice' => 'Si no intentaste iniciar sesión, ignora este correo y cambia tu contraseña.',
            ],
        );
    }
}
