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
            <p style="margin:0;font-size:13px;line-height:20px;color:#1B8FC9;font-weight:600;">Vence en {{ $minutes }} minutos</p>
          </td>
        </tr>
@endsection
