{{-- Armazón común de los correos del sistema: franja azul, logo, tarjeta blanca y pie.
     Tablas y estilos en línea: es lo que respetan Gmail, Outlook y los clientes de correo del teléfono. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background-color:#EEF4F8;">
{{-- Texto que el buzón muestra junto al asunto. --}}
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#EEF4F8;">{{ $preview }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#EEF4F8;">
  <tr>
    <td align="center" style="padding:32px 16px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;background-color:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #DCE7EF;">
        <tr>
          <td style="height:5px;line-height:5px;font-size:0;background-color:#1B8FC9;">&nbsp;</td>
        </tr>
        <tr>
          <td align="center" style="padding:28px 32px 8px;">
            <img src="{{ $message->embed(resource_path('mail/logo.png')) }}" width="190" alt="Grupo de Soluciones Creativas" style="display:block;width:190px;max-width:100%;height:auto;border:0;">
          </td>
        </tr>
        <tr>
          <td style="padding:16px 32px 0;font-family:Poppins,'Segoe UI',Arial,sans-serif;color:#1D1D1B;">
            <h1 style="margin:0 0 10px;font-size:20px;line-height:28px;font-weight:600;color:#1D1D1B;text-align:center;">{{ $heading }}</h1>
            <p style="margin:0;font-size:14px;line-height:22px;color:#4A5560;text-align:center;">{{ $intro }}</p>
          </td>
        </tr>
        @yield('content')
        @isset($notice)
        <tr>
          <td style="padding:24px 32px 28px;font-family:Poppins,'Segoe UI',Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding:12px 14px;background-color:#F6F8FA;border-radius:10px;font-size:12.5px;line-height:19px;color:#5C6670;">{{ $notice }}</td>
              </tr>
            </table>
          </td>
        </tr>
        @else
        <tr><td style="height:28px;line-height:28px;font-size:0;">&nbsp;</td></tr>
        @endisset
      </table>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
        <tr>
          <td align="center" style="padding:16px 16px 0;font-family:Poppins,'Segoe UI',Arial,sans-serif;font-size:11.5px;line-height:17px;color:#8A949E;">
            Mensaje automático: no respondas a este correo.
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
