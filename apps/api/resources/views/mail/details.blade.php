{{-- Correo con una lista de datos (etiqueta: valor) y un botón opcional. --}}
@extends('mail.layout')

@section('content')
        <tr>
          <td style="padding:22px 32px 0;font-family:Poppins,'Segoe UI',Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #DCE7EF;border-radius:12px;border-collapse:separate;">
              @foreach ($rows as $label => $value)
              <tr>
                <td style="padding:11px 14px;{{ $loop->last ? '' : 'border-bottom:1px solid #EEF2F5;' }}font-size:12.5px;line-height:18px;color:#6B7680;width:38%;vertical-align:top;">{{ $label }}</td>
                <td style="padding:11px 14px;{{ $loop->last ? '' : 'border-bottom:1px solid #EEF2F5;' }}font-size:13.5px;line-height:19px;color:#1D1D1B;font-weight:600;vertical-align:top;word-break:break-word;">{{ $value }}</td>
              </tr>
              @endforeach
            </table>
          </td>
        </tr>
        @isset($button)
        <tr>
          <td align="center" style="padding:24px 32px 0;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td align="center" style="border-radius:10px;background-color:{{ $accent ?? '#1B8FC9' }};">
                  <a href="{{ $button['url'] }}" target="_blank" style="display:inline-block;padding:12px 28px;font-family:Poppins,'Segoe UI',Arial,sans-serif;font-size:14px;line-height:20px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:10px;">{{ $button['label'] }}</a>
                </td>
              </tr>
            </table>
            <p style="margin:10px 0 0;font-family:Poppins,'Segoe UI',Arial,sans-serif;font-size:11.5px;line-height:17px;color:#8A949E;">O copia este enlace: <a href="{{ $button['url'] }}" style="color:{{ $accent ?? '#1B8FC9' }};">{{ $button['url'] }}</a></p>
          </td>
        </tr>
        @endisset
@endsection
