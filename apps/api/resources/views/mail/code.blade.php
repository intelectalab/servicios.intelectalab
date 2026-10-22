{{-- Correo con un código de un solo uso (verificación en dos pasos, crear o cambiar la contraseña). --}}
@extends('mail.layout', ['notice' => $notice.' Nunca compartas este código: nadie de nuestro equipo te lo pedirá.'])

@section('content')
        <tr>
          <td align="center" style="padding:24px 32px 8px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td align="center" style="padding:16px 18px 16px 28px;background-color:#E8F4FB;border:1px dashed #1B8FC9;border-radius:12px;font-family:'Courier New',Consolas,monospace;font-size:34px;line-height:40px;font-weight:700;letter-spacing:10px;color:#1D1D1B;">{{ $code }}</td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding:8px 32px 0;font-family:Poppins,'Segoe UI',Arial,sans-serif;">
            <p style="margin:0;font-size:13px;line-height:20px;color:#1B8FC9;font-weight:600;">{{ $expiry ?? 'Vence en '.$minutes.' minutos' }}</p>
          </td>
        </tr>
        @isset($button)
        <tr>
          <td align="center" style="padding:20px 32px 0;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td align="center" style="border-radius:10px;background-color:#1B8FC9;">
                  <a href="{{ $button['url'] }}" target="_blank" style="display:inline-block;padding:12px 28px;font-family:Poppins,'Segoe UI',Arial,sans-serif;font-size:14px;line-height:20px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:10px;">{{ $button['label'] }}</a>
                </td>
              </tr>
            </table>
            <p style="margin:10px 0 0;font-family:Poppins,'Segoe UI',Arial,sans-serif;font-size:11.5px;line-height:17px;color:#8A949E;">O copia este enlace: <a href="{{ $button['url'] }}" style="color:#1B8FC9;">{{ $button['url'] }}</a></p>
          </td>
        </tr>
        @endisset
@endsection
