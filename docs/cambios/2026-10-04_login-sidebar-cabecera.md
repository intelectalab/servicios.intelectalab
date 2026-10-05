# 2026-10-04 / 05 — Login, sidebar, cabecera y superadmin

Entrada escrita después, a partir del historial de git (commits `ca69dd3` a `5dbedb6`). Resume lo que ya está commiteado.

## Qué se pidió

Llevar al diseño del prototipo el acceso (login y recuperación de contraseña), el menú lateral y la cabecera, también en la consola de superadmin, con los colores del logo.

## Qué se cambió

### Web (`apps/web/src`)

| Archivo | Cambio |
| --- | --- |
| `components/auth/AuthShell.tsx` | Marco común de las pantallas de acceso, con el panel de marca del prototipo. |
| `components/auth/LoginForm.tsx` | Login y paso de verificación en dos pasos. |
| `components/auth/ForgotPasswordForm.tsx`, `ResetPasswordForm.tsx` | Recuperar y crear contraseña con código. |
| `app/blocked/page.tsx` | Pantalla de bloqueo (nueva). |
| `components/layout/Sidebar.tsx` | Sidebar con los grupos, íconos y orden del prototipo; conserva modo íconos y tooltips. |
| `components/layout/Header.tsx` | Cabecera con migas, buscador, tema claro/oscuro y menú de usuario. |
| `components/layout/GlobalSearch.tsx` | Buscador global (nuevo): pantallas y registros. |
| `components/layout/ChangePasswordModal.tsx` | Cambiar contraseña desde el menú de usuario (nuevo). |
| `components/account/ProfileView.tsx`, `app/(dashboard)/profile`, `app/superadmin/profile` | "Mi perfil" (nuevo, solo lectura). |
| `components/layout/AppShell.tsx` | Menú completo del prototipo, candado por plan, ocultar por permiso. |
| `app/superadmin/layout.tsx` | La consola de superadmin usa el mismo Sidebar y Header. |
| `components/superadmin/SuperAdminHeader.tsx`, `SuperAdminSidebar.tsx` | Eliminados: los reemplazan los compartidos. |
| `lib/open-record.ts` | Abrir un registro desde el buscador (nuevo). |
| `lib/api.ts`, `lib/auth.ts`, `proxy.ts` | Manejo de sesión vencida, "Recordarme" y redirecciones. |

### API (`apps/api`)

| Archivo | Cambio |
| --- | --- |
| `app/Http/Controllers/Auth/AuthController.php` | Login con bloqueo por intentos, verificación en dos pasos, `/me/access`, cambio de contraseña. |
| `app/Http/Controllers/Auth/PasswordResetController.php` | Recuperación por código. |
| `app/Mail/MfaCodeMail.php`, `PasswordResetCodeMail.php` | Correos con el código (nuevos). |
| `app/Services/LoginThrottle.php`, `TenantAccess.php`, `PlatformAuditLogger.php` | Servicios nuevos. |
| `app/Modules/Core/Http/Controllers/SearchController.php` | `GET /v1/search` para el buscador (nuevo). |
| `app/Modules/Subscription/Support/TenantModules.php`, `ModulePermissions.php` | Módulos del plan y sus permisos. |
| `app/Http/Middleware/SetTenantContext.php`, `CheckModuleEnabled.php` | Ajustes de contexto de tenant y de módulo. |
| `config/sanctum.php` | Duración de sesiones. |

## Qué NO se tocó

La lógica de Clientes, Proveedores y Empleados. En sus `page.tsx` solo se añadieron 4 líneas a cada una para que el buscador pueda abrir un registro.

## Diferencias con el prototipo

- Colores del logo en lugar de azul marino y naranja; el naranja pasa a blanco.
- Verificación por código al correo, no por aplicación autenticadora.
- Clientes, Proveedores y Empleados como tres ítems, no "Clientes y actores".
- Sin selector de empresa/rol de demostración ni cuentas precargadas.

## Base de datos

Migraciones añadidas (ya corridas en local):

- `keep_super_admins_out_of_tenants` — triggers que impiden que un superadmin pertenezca a un tenant.
- `create_platform_audit_logs_table` — auditoría de plataforma.
- `add_permission_prefixes_to_module_catalog_table` — relación módulo → permisos.

## Cómo se probó

- En el navegador contra `localhost:3000`, con la cuenta de superadmin y la de tenant.
- Tests de la API: `LoginSecurityTest`, `MfaLoginTest`, `PasswordResetCodeTest`, `SuperAdminTest`, `ModuleAccessTest`, `HeaderFeaturesTest`.

## Pendiente

Ver [../decisiones-y-pendientes.md](../decisiones-y-pendientes.md).
