# Editar el nombre en Mi perfil

Fecha: 2026-10-28.

## 1. Qué se pidió

Mi perfil solo mostraba el nombre: no se podía cambiar (la cuenta de superadmin nueva quedó como "nueva-superadmin"). Se pidió poder editarlo, en el ERP y en la consola de plataforma.

## 2. Qué se cambió

**API**
- `AuthController::updateName` + ruta `PUT /v1/me/name` (máx. 10 por minuto): la persona cambia **solo su propio nombre** (el correo no, es su usuario). Quita espacios de más; vacío o de una letra no se acepta; hasta 255 caracteres. Si cambia, queda en auditoría `auth.name_changed` con el nombre anterior y el nuevo: en la de la empresa, o en la de plataforma si es superadmin.
- `AuditCatalog`: texto "Cambio de nombre en Mi perfil".

**Web**
- `account/ProfileView.tsx` (lo usan el ERP y la consola): lápiz "Editar nombre" junto al nombre, con Guardar / Cancelar (Escape cancela).
- `lib/auth.ts`: evento `erp-profile-name`; `AppShell` y la cabecera de la consola lo escuchan y cambian el nombre sin recargar.
- `lib/platform.ts`: texto de la acción en Auditoría de plataforma.

## 3. Qué NO se tocó

- El correo no se puede cambiar desde Mi perfil.
- El administrador de la empresa sigue editando a sus usuarios desde Usuarios.
- El portal de clientes no usa esta pantalla.

## 4. Diferencias con el prototipo

El prototipo muestra la cuenta sin editar el nombre; se agrega por pedido.

## 5. Base de datos

Sin migraciones.

## 6. Cómo se probó

- `php artisan test`: **296 pruebas pasan**. Nuevas: `HeaderFeaturesTest › user changes their own name with audit` (vacío y largo rechazados, espacios limpiados, no toca el correo ni a otra persona aunque se envíen, auditoría una sola vez) y `SuperAdminAccessTest › a super admin changes their name in the platform audit`.
- `tsc --noEmit` y ESLint sin errores.
- Navegador, cuenta Administrador (Tenant A): vacío avisa "Escribe tu nombre."; cambiado a "Usuario de Prueba Editado" se ve en la tarjeta y en la cabecera sin recargar; se devolvió a "Usuario de Prueba".
- No visto en la consola de plataforma con el navegador (el superadmin pide código por correo); cubierto por la prueba automática.
