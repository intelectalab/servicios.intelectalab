# 2026-10-21 — Correos al registrarse y enlaces del login a la página pública

## Qué se pidió

1. Que al suscribirse llegue un correo.
2. Un botón en el login para volver a la vista de los planes.

## Qué se cambió

**API**
- `TenantRegisterController`: después de confirmar el registro (fuera de la transacción) envía dos correos:
  - **Bienvenida** (`TenantWelcomeMail`) a quien se registró. Asunto: "Tu empresa ya está lista". Lleva la empresa, el plan, la vigencia ("Prueba gratis hasta el dd/mm/aaaa" o "Mensual/Anual, vigente hasta…"), el precio (al terminar la prueba, si la hay) y el correo de acceso. Incluye el botón "Iniciar sesión" (`FRONTEND_URL/login`). **No** lleva la contraseña: el aviso explica que se usa «¿Olvidó su contraseña?» si se olvida.
  - **Aviso de nueva suscripción** (`NewSubscriptionMail`) a cada superadministrador activo. Asunto: "Nueva suscripción: {empresa}". Lleva empresa, RUC, plan, vigencia, precio, administrador, correo, teléfono y celular (si los dio) y fecha y hora de Lima. Incluye el botón "Ver empresas" (`/superadmin/tenants`).
  - Si el servidor de correo falla, el registro igual responde 201 y el error queda en el log (`report()`). Los correos se envían en el momento, sin cola, porque en el VPS no hay un proceso de colas.
- Plantillas: `mail/layout.blade.php` es el armazón común (franja azul, logo incrustado, título, aviso gris y pie). Sobre él están `mail/code.blade.php` (el correo del código, igual que antes) y `mail/details.blade.php` (lista de datos con botón), cada una con su versión en texto.

**Web**
- `AuthShell`: nueva opción `back`, un enlace discreto con flecha sobre el logo.
- Login: arriba "← Volver al inicio" y abajo "¿Aún no tiene cuenta? Ver planes", que lleva a la sección `#plans` de la página pública. La dirección sale de `SITE_URL`; sin ella, de la raíz del mismo dominio. No aparecen en el paso del código, que ya tiene "Volver".

## Qué NO se tocó

Los datos y las reglas del registro, y la pantalla de registro de la página pública.

## Base de datos

Sin migraciones.

## Cómo se probó

- `php artisan test`: 237 pruebas, todas pasan. Las nuevas, en `TenantRegisterMailsTest`, cubren dos casos:
  - bienvenida con plan, vigencia, precio y enlace al login, sin la contraseña; aviso al superadmin con RUC y teléfono, sin celular vacío;
  - con un servidor de correo que rechaza la conexión, el registro igual se crea (201).
- Vista previa de los tres correos (código, bienvenida y aviso) en el navegador, sin enviar nada.
- Login en `app.localhost:3000/login`, en escritorio y a 375 px: "Volver al inicio" lleva a `http://localhost:3000/`, y "Ver planes" a `http://localhost:3000/#plans`, donde la sección queda a la vista con Base, Profesional y Enterprise.
- `tsc` y `eslint` sin errores.

## Pendiente

- Probar un registro real con el correo SMTP (llegan dos correos: al que se registra y al superadmin).
- En el VPS los correos solo saldrán cuando su `.env` tenga el SMTP configurado; hoy tiene `MAIL_MAILER=log`.
