# Página de ingreso con la marca de cada empresa

Fecha: 2026-11-01.

## 1. Qué se pidió

Personalizar el login por empresa. Como al entrar todavía no se sabe de qué empresa es la persona, se eligió un **enlace propio por empresa** (`/e/{nombre-corto}`) en vez de subdominios (que tocarían DNS, certificados y nginx del servidor compartido). Decisiones aprobadas:
1. El nombre corto se genera solo al crear la empresa; lo cambian el Administrador (Marca) y la plataforma (consola), con aviso de que el anterior deja de funcionar.
2. Quien entra por el enlace de otra empresa con su cuenta, entra a la suya (como hoy).
3. El panel izquierdo muestra el nombre y la bienvenida de la empresa en lugar del texto comercial del SaaS; "Desarrollado por GSCREATIVAS" queda al pie.
4. La invitación de usuarios y la del administrador de una empresa nueva llevan el enlace con la marca.

## 2. Qué se cambió

**Base de datos** — migración `2026_11_01_000100_tenant_login_page` (solo añade; corrida en la PC como `erp_user`):
- `tenants.login_slug` (citext, único, formato `^[a-z0-9]([a-z0-9-]{1,38}[a-z0-9])$` sin `--`). A las empresas existentes se les generó uno desde su nombre (en la PC: `tenant-a-prueba`, `tenant-b-prueba`, `yadi`, `empresa-ejemplo`, `empresa-3`, `empresa-a`, `consola-prueba`, `empresa-dd`).
- `company_branding.login_welcome` (hasta 240 caracteres).

**API**
- `App\Services\LoginSlug` (nuevo): genera el nombre corto (sin "S.A.C.", "E.I.R.L."…, con número si ya existe), lo valida (formato, palabras reservadas como `login`, `superadmin`, `portal`, `api`…, y que no lo use otra empresa) y entrega **solo** lo público de la marca: nombre, logo, color y bienvenida. La marca se lee dentro del contexto de esa empresa; caché de 10 minutos que se limpia al cambiar el logo, el color, la bienvenida o el enlace.
- `GET /v1/public/login-brand/{slug}` (sin sesión, máx. 30 por minuto): 404 igual si el enlace no existe, está mal escrito o la empresa no está activa (no deja adivinar qué empresas existen).
- `PUT /v1/branding/login-page` (`tenant.manage`): nombre corto y bienvenida, con auditoría `branding.login_page`. `GET /v1/branding` trae `login_slug` y `login_welcome`.
- `PUT /v1/superadmin/tenants/{id}/login-slug`: lo cambia la plataforma, con auditoría `tenant.login_slug_changed`; la ficha del tenant trae `login_slug`.
- `TenantProvisioner`: toda empresa nueva (consola o registro público) nace con su nombre corto.
- Invitaciones (`UserController`, `PlatformTenantController::inviteAdmins`): el enlace a "Crear contraseña" lleva `&empresa={slug}`.

**Web**
- Ruta `/e/[slug]`: la misma pantalla de ingreso con la marca. `proxy.ts` la trata como pantalla de ingreso (con sesión, lleva a la consola de cada uno).
- `AuthShell`: con marca, el panel se tiñe con el color de la empresa (oscurecido para que el texto blanco se lea), muestra "Acceso de la empresa", su nombre y su bienvenida, y a la derecha su logo (o sus iniciales si no tiene). Botones, enlaces y campos toman el color de la empresa (`.tenant-brand` + `brandVars`, en claro y oscuro). Mientras se consulta la marca, el panel queda neutro para no mostrar un instante otra marca.
- `LoginForm`: con marca, sin "Volver al inicio" ni "Ver planes"; "¿Olvidó su contraseña?" conserva la empresa.
- `ForgotPasswordForm` y `ResetPasswordForm`: con `?empresa=` conservan la marca y vuelven al enlace de la empresa.
- Configuración → Marca: tarjeta **Página de ingreso** con el enlace (Copiar / Abrir), nombre corto, bienvenida y aviso si se cambia el enlace.
- Consola → ficha del tenant: fila **Enlace de ingreso** con Copiar y Cambiar.
- `lib/login-brand.ts` (nuevo) y textos de auditoría.

## 3. Qué NO se tocó

- `/login` sigue igual, con la marca de GsCreativas.
- El correo de "¿Olvidó su contraseña?" solo trae el código (la pantalla que lo pide ya conserva la marca).
- La activación del portal (`/portal/activate`) sigue sin marca de empresa.
- Subdominios por empresa: quedan para después (habría que consultarlo con el equipo).

## 4. Diferencias con el prototipo

El prototipo tiene un solo login; esto es nuevo.

## 5. Base de datos

La migración de arriba. Ninguna empresa pierde datos; en el VPS cada empresa recibirá su nombre corto al desplegar.

## 6. Cómo se probó

- `php artisan test`: **301 pruebas pasan**. Nueva `TenantLoginPageTest` (3): la consulta pública entrega solo las 7 claves (sin dirección, correo ni RUC); mayúsculas = misma empresa; inexistente, mal formado o suspendida → 404; sin `tenant.manage` no se cambia; formato, reservadas y ocupadas rechazadas; al cambiar, el anterior deja de responder y el nuevo trae la bienvenida; cambiar el color se refleja enseguida; la consola cambia el enlace con auditoría. `PlatformTenantsTest`: la empresa nueva nace con `servicios-prueba` y la invitación lleva `&empresa=`.
- `tsc --noEmit` y ESLint sin errores.
- Navegador (PC):
  - `/e/tenant-a-prueba`: "Acceso de la empresa · Tenant A - Prueba", iniciales "TA" (no tiene logo), sin "Volver al inicio" ni "Ver planes", "¿Olvidó su contraseña?" con `?empresa=`.
  - Con una marca temporal en Consola Prueba (logo generado, `#C2410C`, bienvenida de dos líneas; se quitó al terminar): panel naranja con nombre, bienvenida y "© 2026 · Consola Prueba SAC"; logo a la derecha; botón con el color de la empresa. "Recuperar contraseña" conserva la marca y vuelve a `/e/consola-prueba`.
  - `/e/no-existe-zz9`: el ingreso general de GsCreativas, sin mensaje de "no existe".
  - Entrar por `/e/consola-prueba` con la cuenta de Tenant A lleva a su propio panel; con sesión abierta, `/e/…` lleva al panel.
  - Marca → Página de ingreso: el enlace con Copiar; `superadmin` rechazado ("reservado"); aviso al cambiar; guardar `tenant-a-demo` con bienvenida funcionó (la consulta pública lo devolvió) y se devolvió a `tenant-a-prueba` sin bienvenida.
- **No probado en el navegador:** la ventana "Cambiar enlace" de la consola (cubierta por la prueba automática) y el aspecto en un teléfono. Sin capturas (ventana minimizada): se comprobó por el contenido y los colores calculados.

## 7. Ajustes tras tu revisión (mismo día)

- **Sin parpadeo:** el botón "Ingresar" salía azul un instante. Ahora el servidor web consulta la marca antes de enviar la página (`lib/login-brand-server.ts`, como mucho una consulta por enlace por minuto y 2,5 s de espera; si la API no responde, sale el ingreso general). Aplica a `/e/…`, "Recuperar contraseña", "Crear nueva contraseña" y "Activar mi acceso". Por eso un cambio de marca puede tardar hasta un minuto en verse en estas pantallas.
- **"Activar mi acceso" (portal)** desde el ingreso de una empresa perdía la marca: ahora va con `?empresa=`, la pantalla lleva su marca y vuelve a su enlace. La **invitación al portal** también llega con el enlace de la empresa.
- **Consola → ficha del tenant → "Enviar al administrador":** manda a los administradores activos de la empresa un correo con su enlace (`TenantLoginLinkMail`, con la marca de la empresa), con auditoría `tenant.login_link_sent`. No se envía si la empresa no está activa.
- **Correo de bienvenida** del registro público: el botón y una fila "Tu enlace de ingreso" llevan a `/e/…`.
- Probado: **302 pruebas pasan** (nuevas: envío del enlace solo a administradores activos y no con la empresa suspendida; bienvenida con el enlace). El HTML que entrega el servidor ya trae la marca en las cuatro pantallas con `?empresa=` o `/e/…`, y la de GsCreativas sin ella o con un enlace inexistente; los enlaces conservan la empresa. El botón de la consola no se probó en el navegador (el superadmin pide código por correo y el correo local está en smtp): lo cubre la prueba automática.

## 8. Despliegue (01/11)

Commiteado por Yadira en `96542d8` y desplegado con `deploy.sh --si` (respaldo `/root/backups/erp/20261101_032511_antes_deploy_abd2a14`; migración `2026_11_01_000100` corrida, lote 10). Enlaces generados en el VPS: `tenant-a-prueba`, `tenant-b-prueba`, `yadi`, `empresa-ejemplo`, `empresa-3`, `empresa-a`, `empresa` (EMP-52F1DB), `empresa-2` (EMP-C873F2) y `empresa-prueba`. Comprobado: `/e/empresa-3` sale con la marca desde el servidor; `/e/no-existe-zz9` y `/login`, con la de GsCreativas; sin errores nuevos en el log.

## 9. Pendiente

- Desplegar en el VPS y pasar a cada cliente su enlace.
- Si más adelante quieren subdominios (`empresa.intelectalab.com`), se reutiliza todo: solo cambia cómo se averigua la empresa.
