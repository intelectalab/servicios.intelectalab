{!! $heading !!}

{!! $intro !!}

{{ $code }}

{{ $expiry ?? 'Vence en '.$minutes.' minutos' }}.
@isset($button)

{!! $button['label'] !!}: {{ $button['url'] }}
@endisset

{!! $notice !!} Nunca compartas este código: nadie de nuestro equipo te lo pedirá.

Mensaje automático: no respondas a este correo.
