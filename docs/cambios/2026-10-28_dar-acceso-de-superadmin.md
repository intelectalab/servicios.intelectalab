# Dar o quitar el acceso de Super Administrador (desde el servidor)

Fecha: 2026-10-28.

## 1. Qué se pidió

Agregar otro Super Administrador de la plataforma y enviarle la invitación a su correo. No había forma: la marca `is_super_admin` solo se pone por código, a propósito. Se eligió un **comando en el servidor** (no una pantalla), para no abrir una puerta nueva en la web.

## 2. Qué se cambió

**API**
- `app/Console/Commands/SuperAdminAccess.php` (nuevo): `php artisan platform:super-admin {correo} --by={correo de un superadmin activo} [--name="Nombre"] [--revoke]`.
  - **Dar:** crea la cuenta (sin empresa, con verificación en dos pasos, sin contraseña usable) y le envía un código por **3 días** para crear su contraseña. Si ya es superadmin y **nunca entró**, reenvía la invitación (código nuevo). Si el correo ya es de una cuenta de empresa o del portal, no la toca: hay que usar otro correo (la base no deja que una cuenta de plataforma esté en un tenant). Si ya entró alguna vez, no reenvía: usa "¿Olvidó su contraseña?".
  - **Quitar (`--revoke`):** la cuenta pasa a `DISABLED` y se cierran sus sesiones. Nunca a la última activa.
  - `--by` es obligatorio: queda en la auditoría de plataforma como quien lo autorizó (`platform.super_admin_invited`, `…_reinvited`, `…_revoked`). Sin `--name`, el nombre es la parte del correo antes de la @ (lo puede cambiar en Mi perfil de la consola).
- `app/Mail/SuperAdminInvitationMail.php` (nuevo): asunto "Te dieron acceso a la consola de plataforma", con la plantilla de siempre y **sin marca de empresa**: quién da el acceso, qué incluye la consola (no los datos de negocio de las empresas), el código, "Crear mi contraseña" (abre `/reset-password` con el correo puesto) y el aviso de que cada ingreso pide un código.

**Web**
- `lib/platform.ts`: textos de las tres acciones nuevas en Auditoría de plataforma.

## 3. Qué NO se tocó

- La consola no tiene pantalla para administrar superadmins (se puede hacer después si hace falta).
- El inicio de sesión del superadmin sigue igual (siempre con código).

## 4. Diferencias con el prototipo

El prototipo no tiene cómo agregar superadmins.

## 5. Base de datos

Sin migraciones.

## 6. Cómo se probó

- `php artisan test`: **294 pruebas pasan**. Nuevas en `SuperAdminAccessTest` (3): sin `--by` no hace nada; crea la cuenta, envía la invitación (destinatario, quién, 3 días, enlace), el código sirve para crear la contraseña y luego el login pide el segundo paso; no toma cuentas de empresas; reenvía solo antes del primer ingreso; quitar desactiva y cierra sesiones, y no deja la plataforma sin superadmin.
- `tsc --noEmit` sin errores.

## 7. Despliegue e invitación (28/10)

Desplegado `aadc242` con `deploy.sh --si` (sin migraciones; `/login` 200, API 401 como se espera). Invitación enviada a **nueva-superadmin@ejemplo.com** con `--by=superadmin@ejemplo.com`: cuenta activa, superadmin, con verificación en dos pasos, sin ingresos aún; queda en la auditoría de plataforma como "Super Administrador invitado". El código vence el 31/10; si no entra a tiempo, se corre el mismo comando y recibe otro.

## 8. Pendiente

- La cuenta quedó como "nueva-superadmin". Desde el 28/10 se puede cambiar el nombre en Mi perfil (`2026-10-28_editar-nombre-en-mi-perfil.md`).
