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
            htmlString: '<p>Usa este código para crear tu nueva contraseña:</p>'
                .'<p style="font-size:24px;font-weight:700;letter-spacing:6px">'.e($this->code).'</p>'
                ."<p>Vence en {$this->minutes} minutos. Si no solicitaste el cambio, ignora este correo.</p>",
        );
    }
}
