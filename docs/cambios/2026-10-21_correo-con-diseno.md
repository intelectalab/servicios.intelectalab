# 2026-10-21 — Diseño de los correos con código

## Qué se pidió

Mejorar el diseño del correo del código de verificación. Antes era texto suelto: un párrafo, el código en negrita y otro párrafo.

## Qué se cambió

**API**
- `resources/views/mail/code.blade.php`: plantilla nueva y común para los correos con código. Lleva:
  - una franja azul del logo arriba (#1B8FC9);
  - el logo incrustado en el correo, así que se ve aunque el sistema no sea accesible desde fuera (por ejemplo, en local);
  - un título y una línea de explicación;
  - el código grande en un recuadro celeste con borde punteado;
  - "Vence en N minutos" en azul;
  - un aviso en gris con "Nunca compartas este código";
  - un pie de "Mensaje automático".

  Está hecha con tablas y estilos en línea, que es lo que respetan Gmail, Outlook y los clientes del teléfono. El buzón muestra "Vence en N minutos" junto al asunto.
- `resources/views/mail/code-text.blade.php`: la versión en texto plano del mismo correo, para los clientes que no muestran HTML (también ayuda a no caer en spam).
- `resources/mail/logo.png`: el logo a 400 px sobre fondo blanco, reducido a 64 colores (9 KB) para que cargue rápido. Sale de `apps/web/public/logo.png`.
- `MfaCodeMail` y `PasswordResetCodeMail` usan la plantilla. Los asuntos no cambian y los textos son los de antes, con un título.

## Qué NO se tocó

Cuándo se envían los códigos, su duración y el número de intentos.

## Base de datos

Sin migraciones.

## Cómo se probó

- Vista previa de los dos correos con el mailer `array` (sin enviar nada), abierta en el navegador a 800 px y a 375 px (teléfono). Se comprobó que llevan el logo como adjunto incrustado y la versión en texto.
- Pruebas de inicio de sesión, MFA y contraseña: 24 pasan.

- Ella lo recibió en su buzón real desde local ("¿Olvidó su contraseña?") y se ve bien. El logo tarda un instante porque Gmail pasa las imágenes por su propio servidor; por eso se aligeró de 28 KB a 9 KB.

## Pendiente

Nada.
