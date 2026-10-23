# 2026-10-22 — Panel de la consola de plataforma (superadmin)

## Qué se pidió

Hacer la pantalla `/superadmin/dashboard`, que decía "Sección en construcción", con datos reales y con la seguridad necesaria: solo superadmin con verificación en dos pasos, sin datos de negocio de las empresas (como en el prototipo y CHG-014), cada acción auditada, pruebas de que una cuenta de empresa o del portal no entra y un usuario de base propio para la consola.

El prototipo **no tiene panel de plataforma**: la cuenta SaaS entra directo a Tenants. Lo que se muestra salió de sus pantallas de consola (Tenants, ficha del tenant con Uso, Planes y límites, Auditoría de plataforma) y del objetivo del kit para la plataforma ("tenants, planes, límites, módulos y salud, sin datos de negocio"). Yadira aprobó las cinco recomendaciones: contenido del panel, usuario de base propio, qué se audita, que la consola abra en el panel, y probar con su cuenta.

## Cómo quedó la pantalla

Al iniciar sesión, el superadmin entra al panel (antes entraba a Planes SaaS).

- **Cinco indicadores:** Empresas (por estado y nuevas del mes), Suscripciones vigentes (pagadas, en prueba y empresas sin suscripción), Pruebas por vencer en 7 días (y vencidas sin pasar a pago), Ingreso mensual recurrente (solo suscripciones pagadas; las anuales entre 12) y Usuarios activos (con los del portal y los superadmins).
- **Altas de empresas por mes:** gráfico de los últimos 12 meses (Recharts, color `--p-chart-1`).
- **Empresas por plan:** vigentes, pagadas, en prueba e ingreso mensual de cada plan.
- **Pruebas por vencer:** empresa, plan, fin de la prueba y "Vence en N días" / "Vencida hace N días".
- **Uso frente al plan:** por empresa, usuarios, usuarios del portal y clientes frente al límite del plan (barra ámbar desde el 80 %, roja al límite), almacenamiento en MB y fecha de alta. Es lo mismo que la tarjeta Uso del prototipo, solo con conteos.
- **Salud de la plataforma:** cola de trabajos (pendientes y fallidos), eventos por publicar, integraciones con error sin resolver, archivos en cuarentena, correo (avisa si está en modo `log` y los correos no salen) y cola configurada.
- **Actividad reciente de la plataforma:** las últimas 8 acciones de la auditoría de plataforma.
- Las cuatro tablas tienen selección de filas y exportar a Excel o CSV al pie. Botón **Actualizar** arriba. El botón de tema está en la cabecera, como en el resto de la consola.
- A 1366 px los cinco indicadores van en una fila y las tablas no se cortan; a 375 px las tablas se ven como tarjetas y no hay desplazamiento lateral.

Archivos web: `components/superadmin/dashboard/PlatformDashboardView.tsx` (nuevo), `app/superadmin/dashboard/page.tsx`, `types/platform.ts` (nuevo), `proxy.ts` (`PLATFORM_HOME`) y `components/auth/LoginForm.tsx` (a dónde va el superadmin tras entrar).

## Seguridad

- **Usuario de base propio (`erp_console`, del rol `erp_platform`).** La migración `2026_10_22_000400_platform_console_role_and_dashboard` crea el rol de grupo `erp_platform` (sin login, sin BYPASSRLS) y el usuario `erp_console` con la contraseña de `DB_PLATFORM_PASSWORD`. Sin esa variable crea solo el rol de grupo, y el panel responde 503 hasta que el usuario exista. En Laravel es la conexión `pgsql_platform` (`config/database.php`).
- **Ese usuario no lee ninguna tabla.** Solo puede ejecutar `platform_dashboard()`, una función `SECURITY DEFINER` que devuelve totales de plataforma y la lista de empresas con su nombre, código, plan y conteos. No devuelve clientes, comprobantes, documentos ni montos de negocio. También puede insertar en `platform_audit_logs`.
- **`erp_app` no puede ejecutar `platform_dashboard()`.** De paso se cerró un hueco: las funciones `superadmin_tenant_subscriptions`, `superadmin_companies`, `resolve_active_tenant_for_user`, `resolve_portal_tenant_for_user` y `portal_membership_for_user` las podía ejecutar cualquier rol de la base (PUBLIC). Ahora solo `erp_app`, que es quien las usa.
- **Acceso a la consola** (middleware `super.admin`, `EnsureIsSuperAdmin`): exige superadmin en estado ACTIVE **y** que la sesión haya pasado la verificación en dos pasos (`personal_access_tokens.mfa`). Antes solo miraba `is_super_admin`. Las respuestas son 403 con `PLATFORM_FORBIDDEN` o `MFA_REQUIRED`, y 401 sin sesión. Esto vale para toda la consola: Planes SaaS y Tenants también.
- **Auditoría** (en `platform_audit_logs`):
  - `platform.export`: cada exportación del panel, con la tabla, el formato, las filas y si fue una selección. Se escribe con el usuario de la consola.
  - `platform.access_denied`: cada intento rechazado de una cuenta con sesión (de empresa, del portal o sin 2FA), con el motivo y la ruta.
  - Abrir el panel no se registra, para no llenar la auditoría de ruido.

Archivos API: migración nueva, `config/database.php`, `.env.example` (`DB_PLATFORM_USERNAME`, `DB_PLATFORM_PASSWORD`), `app/Http/Middleware/EnsureIsSuperAdmin.php`, `app/Services/PlatformAuditLogger.php` (acepta la conexión), `app/Modules/Subscription/Http/Controllers/SuperAdmin/PlatformDashboardController.php` (nuevo) y `routes/api.php` (`GET /v1/superadmin/dashboard`, `POST /v1/superadmin/dashboard/exports`). La ruta `/v1/superadmin/dashboard/metrics` sigue igual porque la usa Planes SaaS.

## Pruebas

- `PlatformDashboardTest` (9 pruebas):
  - el superadmin con sesión verificada recibe solo totales y los totales cuadran entre sí;
  - sin 2FA → 403 `MFA_REQUIRED`, auditado;
  - superadmin deshabilitado → 403;
  - cuenta de empresa → 403 en el panel y en exportar, auditado;
  - cuenta del portal → 403 en el panel y en Tenants, auditado;
  - sin sesión → 401;
  - la exportación queda en la auditoría y una tabla que no existe da 422;
  - `erp_app` no puede ejecutar la función;
  - `erp_console` no puede leer `customers`, `parties`, `invoices`, `documents`, `file_objects`, `tenant_subscriptions` ni `users`, ni llamar a `superadmin_companies`, ni borrar la auditoría.
- `SuperAdminTest` usa ahora una sesión real con 2FA (antes simulaba la sesión).
- `php artisan test`: **268 pruebas pasan**. `tsc` y `eslint` sin errores.
- **En el navegador**, con la cuenta superadmin real (contraseña y código del log):
  - el login lleva al panel;
  - vistas a 1366 px y 375 px, en claro y en oscuro;
  - exportar Excel (archivo `PK` de 7,7 KB) y CSV de una selección de 2 filas, las dos en la auditoría;
  - Planes SaaS sigue abriendo;
  - se cerró la sesión al terminar.
- **Contra la API real:** Ventas recibe 403 y queda `platform.access_denied`.
- **Ingreso mensual:** en la PC todas las suscripciones son de prueba, así que sale S/ 0.00. Para no crear empresas falsas, el cálculo se comprobó en una transacción deshecha: con una suscripción Base anual y una Enterprise mensual pagadas, el ingreso dio S/ 431.50 (990 ÷ 12 = 82.50, más 349).

## Lo que muestra hoy en la PC

- 6 empresas activas y 3 suscripciones vigentes, todas en prueba.
- Tenant A y Tenant B no tienen suscripción.
- La prueba Enterprise de "Empresa Ejemplo SAC" venció el 13 de octubre y sigue marcada ACTIVE en la base: sale como "Vencida hace 10 días".
- 12 usuarios activos, 3 del portal y 1 archivo en cuarentena.

## En el VPS (2026-10-23)

Solo se tocó este ERP: su `.env` y su PostgreSQL, que solo tiene la base `erp_saas`.

1. Se añadieron `DB_PLATFORM_USERNAME` y `DB_PLATFORM_PASSWORD` (clave al azar de 32 caracteres) en `apps/api/.env`. Respaldo y forma de volver atrás: `/root/backups/erp_console_env_20261023_015619/LEEME.txt`.
2. El primer despliegue se detuvo en la migración: allí `erp_user` no puede crear roles (en la PC sí, porque es el dueño del contenedor). No quedó nada a medias y el sitio siguió funcionando. Se cambió la migración para que solo cree los roles si quien la corre puede hacerlo; si faltan, se detiene con un mensaje que dice cómo crearlos.
3. Los roles se crearon como administrador de PostgreSQL, sin login para `erp_platform` y sin superusuario ni BYPASSRLS para ninguno:

   ```
   CREATE ROLE erp_platform NOLOGIN;
   CREATE ROLE erp_console LOGIN PASSWORD '<la del .env>';
   GRANT erp_platform TO erp_console;
   GRANT CONNECT ON DATABASE erp_saas TO erp_console;
   ```
4. Después se volvió a desplegar con `deploy.sh` (`b2ed51d`): la migración pasó. El script dijo "Sin cambios en la web" porque el intento fallido ya había traído el código, así que la web se compiló a mano con los mismos pasos del script (copia de `.next`, `npm run build`, `pm2 restart servicios-web`).
5. Comprobado en el VPS:
   - la consola lee con `erp_console` (9 empresas, 6 suscripciones vigentes, 0 pagadas) y ese usuario no puede leer `customers`;
   - `/api/v1/superadmin/dashboard` sin sesión responde 401;
   - `/login` 200;
   - los demás sitios responden igual que en la medición del 21 (`estado.sh`).

   No se abrió el panel con sesión porque el código llega al correo real.

Pendiente de `deploy.sh`: si un despliegue falla después de traer el código, el siguiente no recompila la web. Debería comparar contra el último commit que compiló bien.

## Pendiente

- (Hecho el 2026-10-23, ver "En el VPS").
- Una tarea que pase a vencidas las suscripciones de prueba cuyo plazo terminó (hoy siguen ACTIVE).
- Los indicadores de Planes SaaS cuentan mal: "Tenants activos" es el total y el ingreso suma las pruebas (S/ 646 contra S/ 0 del panel). Se pueden sacar del mismo cálculo del panel.
- Tenants y Suscripciones siguen en construcción. Cuando existan, un clic en una fila del panel abrirá la ficha de la empresa. Esas pantallas, y Planes, deberían pasar también al usuario de la consola: hoy leen con `erp_app` y las funciones `superadmin_*`.
- No hay pantalla para la auditoría de plataforma completa (el panel muestra las últimas 8).
