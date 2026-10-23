# 2026-10-23 — Consola de plataforma: Tenants, Aprovisionar tenant, Auditoría, Suscripciones y módulos de los planes

## Qué se pidió

En la consola de superadmin faltaban pantallas del prototipo (auditoría de plataforma, tenants, aprovisionar tenants). Además, los módulos de la ventana de crear y editar planes debían coincidir con los que hay de verdad en el ERP, respetando cuáles son obligatorios.

Yadira aprobó las cinco recomendaciones:
- **Módulos sin pantallas:** se muestran con su etiqueta y no se borran del catálogo.
- **Cambios en un plan:** no tocan solos a las empresas que ya lo tienen. Se aplican desde la ficha con "Aplicar módulos del plan".
- **Estados:** se quedan los suyos (En alta, Activa, Suspendida, En mantenimiento, Cerrada), con motivo e historial.
- **Administrador de una empresa nueva:** recibe una invitación con código para crear su contraseña.
- **Suscripciones:** se queda en el menú como lista de todas las suscripciones.

## Pantallas

### Planes SaaS → pestaña Módulos

> Más tarde ese mismo día el catálogo se rehízo según el menú real del ERP y Planes SaaS cambió de diseño: ver [2026-10-23_planes-saas-y-modulos-del-erp.md](2026-10-23_planes-saas-y-modulos-del-erp.md). Lo que sigue describe el primer paso.

- La lista sale del catálogo del ERP (`module_catalog`): 23 módulos agrupados en Base del ERP, Operaciones y servicio, Finanzas y facturación, Soporte y atención al cliente, y CRM.
- Se quitaron los que no existen (Hosting, Dominios, Servidores, Cloud, eCommerce, Otros, API, Automatizaciones, Reportes avanzados, Personal, Garantías, Condiciones de entrega).
- **Obligatorios:** Clientes, Proveedores y Empleados van marcados, sin poder desmarcarse, con la etiqueta "Obligatorio". Toda empresa los tiene.
- **"Sin pantallas propias":** sus permisos ya los da otro módulo (Tareas, Checklists y Recursos los da Órdenes de trabajo; Facturación electrónica y Pagos y cobranzas, Finanzas; Tickets, Soporte). Al pasar el cursor se ve cuál.
- **"Sin pantallas todavía":** no habilitan nada aún (Entregables, Rentabilidad).
- La API rechaza un módulo que no esté en el catálogo y siempre guarda los obligatorios.
- **Arreglo de paso:** al editar un plan, la ventana marcaba también los módulos por defecto de un plan nuevo (Órdenes de trabajo, Proyectos, Checklists, Recursos, Entregables), aunque el plan no los tuviera. Al guardar se habrían añadido. Ahora muestra solo los del plan.

### Tenants / Empresas

- Columnas como el prototipo:
  - tenant (nombre, razón social, RUC);
  - plan, con la etiqueta Prueba;
  - módulos encendidos ("N ON"; al pasar el cursor, cuáles);
  - usuarios contra el límite del plan;
  - fecha de alta;
  - estado.
- Buscador, filtros por estado y plan, ordenar por columna, selección y exportar al pie. Todo abarca la lista completa. El clic en la fila abre la ficha.
- Botón "Aprovisionar tenant".

### Ficha del tenant

- **Botones de estado** según desde dónde se puede pasar:
  - En alta → Activar o Cerrar;
  - Activa → Suspender, Poner en mantenimiento o Cerrar;
  - Suspendida → Activar o Cerrar;
  - En mantenimiento → Activar, Suspender o Cerrar;
  - Cerrada → Activar.

  Todos menos Activar piden motivo: el botón queda deshabilitado hasta escribirlo. Al activar por primera vez una empresa recién aprovisionada se envía la invitación a sus administradores que nunca han entrado.
- **Suscripción:**
  - muestra estado, plan, periodo, precio, vigencia (Vigente / En prueba / Prueba vencida / Vencida) y módulos;
  - "Cambiar plan" (o "Asignar plan" si no tiene) muestra antes de confirmar qué módulos se encienden y cuáles se apagan, y bloquea la bajada si la empresa tiene más usuarios activos de los que permite el plan;
  - "Aplicar módulos del plan" deja los módulos como los del plan.

  Apagar un módulo no borra datos: la fila queda en `enabled = false`.
- **Uso:** usuarios activos, usuarios del portal, clientes y almacenamiento, cada uno contra el límite del plan. "Llamadas API (mes)" figura como "Aún no se miden".
- **Historial de estado:** de dónde a dónde pasó, cuándo, quién y con qué motivo.
- **Administradores:** nombre, correo y último ingreso, con "Reenviar invitación" si nunca ha entrado.
- **Tablas Módulos** (en el plan, estado, diferencia con el plan) **e Historial de suscripciones**, con exportar.
- No se puso la tarjeta "Acceso a datos de negocio" del prototipo: es solo un texto explicativo, y la regla ya se cumple porque la consola no abre esos datos.

### Aprovisionar tenant

- Arriba, los pasos que se llenan en esta pantalla (Tenant, Empresa y sedes, Administrador, Plan, Módulos). Empiezan "por completar" y se marcan listos a medida que se completa cada parte; un clic lleva a su parte del formulario. Yadira pidió quitar los que no se llenan aquí (Datos maestros, Roles, Validación A/B, Habilitación) porque aparecían ya marcados y se veía raro. Por la misma razón el plan ya no viene elegido: hay que escogerlo, y hasta entonces "Aprovisionar" queda deshabilitado.
- **Empresa:** nombre comercial, RUC (11 dígitos, empieza con 10 o 20, sin repetir), razón social y sede principal.
- **Administrador:** nombre y correo, sin repetir.
- **Suscripción y módulos:**
  - plan, periodo y precio;
  - prueba gratis (solo si el plan la tiene);
  - Activar CRM y Activar Portal Cliente (solo si el plan los incluye; se pueden dejar apagados);
  - lista de los módulos que tendrá.
- La casilla "Cargar datos maestros base" va marcada y fija: esos datos se crean solos al usar cada módulo.
- **Qué crea:** la empresa, sus datos fiscales, la sede principal, el administrador con el rol ADMIN de sistema y sus permisos, los módulos y la suscripción. Es el mismo alta del registro público (`TenantProvisioner`). La empresa nace "En alta" y se habilita con Activar desde su ficha.
- El administrador nace con una clave al azar que nadie conoce. Al activar la empresa recibe un correo con un código válido 3 días y el enlace a "Crear contraseña" (`/reset-password`, el mismo mecanismo de "¿Olvidó su contraseña?"). El correo no va en el enlace. Su primer ingreso pide el código de verificación, como a todo Administrador.

### Auditoría de plataforma (nueva en el menú)

- Columnas: fecha, actor, acción, entidad y detalle.
- Buscador (acciones, actor, entidad, detalle) y filtros por acción, actor y fechas. Orden y paginación en la API.
- Exportar todo lo filtrado o una selección.
- El clic abre el detalle: IP, navegador, antes, después y detalle, con enlace a la empresa si la acción fue sobre una.

### Suscripciones

- Todas las suscripciones de todas las empresas: empresa, plan, periodo, precio, inicio, fin y estado.
- Filtros por estado, plan y periodo, exportar, y clic que abre la ficha del tenant.

## Seguridad

- **Acceso:** todo va en el grupo `super.admin` (superadmin activo y sesión con verificación en dos pasos). Una cuenta de empresa o del portal recibe 403 y el intento queda auditado.
- **Lecturas con el usuario de la consola** (`erp_console`), mediante funciones que devuelven solo datos de plataforma:
  - `platform_tenants()`, `platform_tenant(id)` y `platform_subscriptions()` dan la identidad de la empresa, su plan, sus módulos, los conteos de uso, el historial y los administradores (nombre, correo y último ingreso);
  - `platform_audit_view` da la auditoría con nombre y correo del actor;
  - la consola puede leer directamente `module_catalog` y `saas_plans`.

  Nada de clientes, comprobantes ni documentos.
- **Cambios con la app** (`erp_app`): aprovisionar, cambiar estado, cambiar plan, aplicar módulos e invitar se hacen en una transacción dentro del contexto de la empresa (RLS). Con bloqueo de fila; si el estado cambió mientras tanto, responde 409.
- **Auditoría** de cada acción en `platform_audit_logs`:
  - `tenant.provisioned`;
  - `tenant.status_changed` (antes, después y motivo);
  - `tenant.plan_changed` (antes, después y módulos encendidos y apagados);
  - `tenant.modules_applied`;
  - `tenant.invitation_sent`;
  - `platform.export` (con la tabla).
- **Historial de estado nuevo** (`tenant_status_log`): la app solo puede leer y agregar, no editar ni borrar.

## Archivos

- **API:**
  - migración `2026_10_23_000100_platform_console_tenants`;
  - `Support/ModuleCatalog`, `Support/TenantPlanModules` y `Support/TenantProvisioner` (nuevos);
  - `PlatformTenantController` y `PlatformAuditController` (nuevos);
  - `TenantInvitationMail` (nuevo);
  - `PasswordResetController::issueCode` (el código de un solo uso, ahora reutilizable);
  - `SaasPlanController` (valida los módulos);
  - `TenantRegisterController` (usa `TenantProvisioner`);
  - `SuperAdminDashboardController` (sin los métodos `tenants` y `audit`, ya reemplazados);
  - `routes/api.php`.
- **Web:**
  - `components/superadmin/ClientTable.tsx`, `tenants/*`, `audit/PlatformAudit.tsx` y `subscriptions/SubscriptionsList.tsx`;
  - `lib/platform.ts`;
  - `types/platform.ts`;
  - páginas en `app/superadmin/tenants`, `tenants/[id]`, `tenants/new`, `audit` y `subscriptions`;
  - menú en `app/superadmin/layout.tsx`;
  - `plans/SaasPlanDrawer.tsx`.

## Pruebas

- **`PlatformTenantsTest` (6 pruebas):**
  - los módulos de los planes siguen el catálogo, con los obligatorios siempre encendidos;
  - aprovisionar crea la empresa en "En alta", sin CRM si se pidió, con su sede, permisos, suscripción en prueba, historial y auditoría; no repite RUC ni correo;
  - activar envía la invitación y su código sirve para crear la contraseña;
  - los estados exigen motivo, siguen las reglas, avisan si cambiaron mientras tanto y el historial no se puede borrar;
  - cambiar de plan respeta el límite de usuarios, enciende y apaga módulos sin borrar datos y da los permisos nuevos al administrador;
  - las lecturas no traen datos de negocio y una empresa inexistente da 404;
  - una cuenta de empresa o del portal recibe 403 en todo.
- `php artisan test`: **274 pruebas pasan**. `tsc` y `eslint` sin errores.
- **En el navegador**, con la cuenta superadmin real:
  - se aprovisionó **Consola Prueba SAC** (RUC 20999999991, Plan Profesional sin CRM, administrador `consola.admin@erp-saas.test`);
  - se activó (la invitación salió en el log), se suspendió con motivo y se reactivó;
  - se pasó a Plan Base: se apagaron 9 módulos y el límite pasó a 1 / 10;
  - se reenvió la invitación;
  - se vieron la auditoría (con filtro y detalle) y Suscripciones;
  - se exportó la auditoría completa (39 filas en CSV);
  - se abrió y guardó Plan Base sin cambiar sus módulos.

  Todas las pantallas se vieron a 1366 y 375 px, en claro y oscuro, sin desplazamiento lateral. Se cerró la sesión al terminar.

## Datos de prueba que quedan en la PC

**Consola Prueba SAC** (`EMP-FCA004`): activa, Plan Base mensual pagado (por eso el panel muestra S/ 99.00 de ingreso), con su historial de estado. Su administrador aún no tiene contraseña: nunca usó la invitación.

## Pendiente

- **En el VPS:** desplegar. La migración nueva la puede correr `erp_user`, porque no crea roles.
- **Datos que la app no guarda todavía:**
  - contar las llamadas a la API por empresa;
  - el límite de almacenamiento en los planes (hoy ninguno lo tiene).
- **La empresa que se aprovisiona no recibe los roles base del prototipo** (Ventas, Gerencia…), solo Administrador. Es el mismo pendiente que ya tenía el registro público.
- **Las suscripciones de prueba vencidas siguen en ACTIVE** en la base (se muestran "Prueba vencida"). Falta la tarea que las pase a vencidas.
