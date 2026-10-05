# Decisiones y pendientes

Estado al 2026-10-05.

## Decisiones tomadas

### Menú lateral
- `MENU_ITEMS` (en `apps/web/src/components/layout/AppShell.tsx`) replica los grupos y el orden del prototipo.
- Las pantallas que aún no existen van con `soon: true`: se ven atenuadas, no navegan y se ocultan en modo íconos. Al construir una se cambia `soon` por `href` + `module` + `requiredPermission`.
- Clientes, Proveedores y Empleados se mantienen como **tres ítems** (el prototipo los junta en "Clientes y actores").
- "Servicios" pasó a llamarse "Catálogo de servicios", como en el prototipo (2026-10-05).
- Módulo fuera del plan → candado "Plan". Módulo en el plan pero sin permiso → oculto. Los datos vienen de `GET /v1/me/access`.

### Módulos y permisos
- Solo CLIENTES, PROVEEDORES y EMPLEADOS son obligatorios (`is_core`) y comparten el permiso `core`. El resto sigue al plan.
- La relación módulo → permisos vive en `module_catalog.permission_prefixes`.

### Comercial
- Las pantallas nuevas se arman con las piezas de `apps/web/src/components/ui/proto.tsx` (medidas del prototipo, colores del logo, tema oscuro incluido).
- Verde, rojo y ámbar se usan solo como colores de estado; todo lo demás es el azul del logo.
- Las cotizaciones se emiten solo en soles hasta que exista tipo de cambio.
- El precio de un servicio no se edita: cada cambio es una versión nueva y la anterior se cierra.
- Los textos explicativos que el prototipo pone bajo el título de cada pantalla (flujos tipo "Categoría → Servicio → …") son de la demo y no se traen.
- Cuando el prototipo pide una regla que la API no tenía (activar solo con precio, motivo al rechazar, categoría obligatoria), se implementa la regla del prototipo y se anota en el registro de cambios como lógica que cambió.
- Lo que depende de una pantalla que aún no existe (tipo de cambio, reglas de aprobación, órdenes, portal) queda fuera hasta construir esa pantalla.
- Una nueva versión de cotización recibe un correlativo nuevo, no el sufijo `-v2` del prototipo, para no tocar el generador de correlativos.
- El historial de servicios y cotizaciones se lee de `audit_logs`; no hay tabla de historial aparte.
- Los listados nuevos llevan casillas de selección, barra de exportar, Excel con el formato del de Clientes (en azul del logo) y la paginación completa; todo sale de `proto.tsx` y `lib/export-table.ts`, para que las pantallas siguientes lo reutilicen.

### Acceso
- Verificación en dos pasos por **código enviado al correo** (no aplicación autenticadora). Obligatoria para superadmin, rol ADMIN del tenant y usuarios de portal.
- Sesiones de 8 h, o 30 días con "Recordarme". Se permiten varias sesiones a la vez.
- 5 contraseñas incorrectas bloquean la cuenta 15 minutos.
- El superadmin es `users.is_super_admin` (no un rol), no puede pertenecer a un tenant (triggers) y tiene auditoría propia en `platform_audit_logs`.

## Pendientes

- La cuenta de superadmin sigue siendo miembro de `EMP-5E380F` (Yadi SAC), membresía anterior a los triggers; el sistema la ignora. Falta decidir si se borra.
- El candado de módulo es solo visual: en la API solo CRM usa el middleware `module:`.
- El drawer de planes (`SaasPlanDrawer.tsx`) tiene su lista de módulos escrita a mano y no coincide con `module_catalog`.
- No hay página para la auditoría de plataforma (`GET /v1/superadmin/audit`), ni comando para crear superadmins, ni contador de notificaciones.
- "Mi perfil" es de solo lectura; el prototipo además lista y cierra sesiones y guarda preferencias de notificación.
- `php artisan tenants:sync-permissions` da a los tenants ya registrados los permisos de sus módulos. Falta confirmar si se corrió.
- Correo real: falta configurar SMTP en `apps/api/.env` (hoy `MAIL_MAILER=log`).
- El contenido de Clientes, Proveedores, Empleados, Planes SaaS y la landing sigue con el estilo anterior.
- Comercial: faltan las pantallas de Contratos, Órdenes y Solicitudes del portal. Lo que Servicios y Cotizaciones aún no tienen respecto al prototipo está en [cambios/2026-10-05_comercial-campos-y-funciones.md](cambios/2026-10-05_comercial-campos-y-funciones.md).
- `database/schema_reference.sql` no incluye la migración `2026_10_05_000300` (modalidad, garantía, estado archivado, versión anterior).
