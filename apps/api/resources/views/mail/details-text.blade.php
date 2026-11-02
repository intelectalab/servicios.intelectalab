{!! $heading !!}

{!! $intro !!}
@isset($paragraphs)
@foreach ($paragraphs as $paragraph)

{!! $paragraph !!}
@endforeach
@endisset

@foreach ($rows as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach
@isset($button)

{!! $button['label'] !!}: {{ $button['url'] }}
@endisset
@isset($notice)

{!! $notice !!}
@endisset

Mensaje automático: no respondas a este correo.
