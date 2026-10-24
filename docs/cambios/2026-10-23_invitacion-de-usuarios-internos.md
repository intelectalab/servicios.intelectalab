# Invitación propia para los usuarios creados en Administración

Fecha: 2026-10-23.

## 1. Qué se pidió

Al crear un usuario como administrador, el correo solo traía un código, sin botón ni enlace, a diferencia de la invitación al portal de clientes.

**Causa:** el alta reutilizaba el correo de "¿Olvidó su contraseña?" (`PasswordResetCodeMail`): sin botón, con el texto "Si no solicitaste el cambio…" y un código que vencía en **15 minutos**.

## 2. Qué se cambió

**API**
- `app/Mail/UserInvitationMail.php` (nuevo): asunto "Te dieron acceso a {empresa}", la empresa y el rol en el texto, botón **Crear mi contraseña** y código que vale **7 días** (como el portal). Usa la misma plantilla `mail.code`.
- `Core/Http/Controllers/UserController.php`:
  - `store` manda la invitación nueva. El enlace va a `/reset-password?email=…`, que abre la pantalla con el correo ya puesto. Si el correo falla, el usuario igual se crea, el error se registra y la respuesta trae `invited: false`.
  - `resendInvitation` (nuevo): código nuevo por 7 días; el anterior deja de servir. Solo para usuarios activos que **nunca han entrado**; si ya entró, debe usar "¿Olvidó su contraseña?". Queda en auditoría (`user.invitation_sent`).
- `routes/api.php`: `POST /v1/users/{user}/invitation` (permiso `users.manage`).
- `Services/AuditCatalog.php`: texto "Invitación al usuario reenviada".

**Web**
- `components/dashboard/admin/UsersList.tsx`: botón **Reenviar invitación** en la fila (usuario activo sin último acceso), con confirmación. El aviso al crear dice que se envió código y enlace, o advierte si el correo no salió.

## 3. Qué NO se tocó

- "¿Olvidó su contraseña?" sigue igual (15 minutos, mismo correo).
- La pantalla de crear contraseña y su validación del código no cambian.
- La invitación del portal y la del administrador aprovisionado desde la consola.

## 4. Diferencias con el prototipo

El prototipo no envía correos reales; se sigue el mismo patrón que la invitación del portal (código por 7 días, botón, reenviar).

## 5. Base de datos

Sin migraciones.

## 6. Cómo se probó

- `php artisan test`: `ComercialTest › company users are managed with their role` ahora comprueba la invitación (empresa, rol, 7 días, enlace con el correo), que el código sirve para crear la contraseña, que reenviar invalida el anterior y que no se reenvía a quien ya entró. `PasswordResetCodeTest` y `LoginSecurityTest` siguen pasando.
- Web: `tsc --noEmit` y ESLint sin errores.

## 7. Pendiente

Nada. La invitación del administrador aprovisionado desde la consola (`PlatformTenantController::inviteAdmins`) también lleva ahora el correo en el enlace (`/reset-password?email=…`); `PlatformTenantsTest` lo comprueba.
