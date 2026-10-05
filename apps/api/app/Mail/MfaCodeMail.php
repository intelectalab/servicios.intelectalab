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
            htmlString: '<p>Tu código de verificación es:</p>'
                .'<p style="font-size:24px;font-weight:700;letter-spacing:6px">'.e($this->code).'</p>'
                ."<p>Vence en {$this->minutes} minutos. Si no intentaste iniciar sesión, ignora este correo y cambia tu contraseña.</p>",
        );
    }
}
