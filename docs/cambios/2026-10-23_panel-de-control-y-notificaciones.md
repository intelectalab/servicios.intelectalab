# 2026-10-23 — Panel de control y Notificaciones

## Qué se pidió

Dos cosas:

- **El panel de control del ERP:** la pantalla de inicio de cada empresa (P-DASH-01 del kit, `GET /dashboard`; prototipo `dashboard.js`).
- **El módulo de Notificaciones:**
  - la campana de la cabecera con su contador;
  - el centro de notificaciones;
  - marcar como leídas;
  - que las preferencias de Mi perfil, que ya se guardaban, surtan efecto.

Antes de construir se hizo el inventario de lo que muestra el prototipo. Tus respuestas:

1. **Eventos que avisan:** los del prototipo más los que estaban en pendientes. Los avisos que dependen de una fecha (SLA por vencer, cobranza vencida, renovaciones) quedan en "Requiere tu atención" del panel, sin tarea programada.
2. **Correo:** sí, según la preferencia de cada persona. WhatsApp queda guardado, con el aviso de que no hay proveedor.
3. **"Qué está funcionando":** como en el prototipo, con el aviso de "no disponible".
4. **Inicio:** todos entran al panel, salvo quien solo ejecuta órdenes de trabajo, que entra directo a la App del técnico.

## Panel de control (`/dashboard`, menú Inicio → Panel de control)

Cada bloque lo calcula la API con los permisos, el alcance (lo propio, equipo, sede o toda la empresa) y el plan de la persona.

- Sin el permiso, el bloque no sale, como en el menú.
- Con el permiso pero sin el módulo en el plan, sale con candado y el texto "No incluido en el plan de tu empresa", sin datos.

**Saludo.** "Hola, {nombre}", con el rol, la empresa y la fecha del día de quien mira, más el botón principal según lo que el rol puede crear:

- Nueva cotización;
- Nueva orden de trabajo;
- Registrar cobro;
- Nuevo ticket;
- Mis órdenes (para el técnico).

No se trajeron "Recorrer flujos" ni el aviso del prototipo, porque son de la demo.

**Indicadores (como en el prototipo).** Cada uno lleva a su pantalla.

- Pipeline abierto y Tasa de cierre. Sin CRM: Cotizaciones abiertas y Aprobación de cotizaciones.
- Facturado este mes, neto de notas de crédito.
- Cuentas por cobrar, con lo vencido en rojo.
- Órdenes de trabajo: en curso, programadas hoy y completadas.
- Cumplimiento SLA, con los tickets abiertos y los críticos.
- Proyectos en ejecución.
- Mis horas (7 días), para el técnico.

Los montos están en soles. Lo que está en dólares se convierte con el tipo de cambio que guarda cada documento.

**Requiere tu atención.** Los 24 avisos del prototipo, cada uno con su número, su color de estado y su enlace. Solo aparecen los que tienen algo pendiente.

- La DLQ de integraciones y las credenciales por vencer salen a quien tenga esos permisos y dicen "Pantalla aún no disponible".
- La autorización interna de cotizaciones por niveles todavía no existe, así que no aparece.
- Al pie va "Tu alcance: Cotizaciones: Propios · OT: Sede…" y la exportación a Excel o CSV.

**Gráficos.**

- Facturación de los últimos 6 meses (Recharts), con lo cobrado en el mismo periodo.
- Antigüedad de cuentas por cobrar.
- Pipeline por etapa.
- Tickets abiertos por estado de SLA.

**Qué está funcionando.** Integraciones y Workflows, con "Aún no disponible". La ve quien tiene `integrations.view`. Si la empresa tuviera conexiones registradas, aparecerían con su estado.

**Actividad reciente (auditoría).** Los últimos 6 movimientos, con "Ver todo" y exportar al pie. Exportar pide `audit.export` y queda en la auditoría.

**Pantalla de inicio.** El sistema abre en `/dashboard` (inicio de sesión, `proxy.ts`, pantalla de empresa bloqueada). Quien tiene `work_orders.manage` sin `work_orders.approve` (ejecuta OT sin asignarlas) entra a `/campo`. Si después abre el panel desde el menú, se queda en él.

## Notificaciones

**A quién le llega.** Se decide por permiso y alcance, nunca por el nombre del rol: es quien puede actuar sobre ese registro y lo vería en su pantalla. Nadie recibe aviso de lo que hizo él mismo.

| Aviso | Le llega a |
|---|---|
| Nueva orden de trabajo asignada | El técnico asignado (al crear con técnico o al asignar o reasignar). |
| Nuevo ticket (también desde el portal) | Quien atiende tickets (`tickets.manage`) y lo alcanza. |
| Ticket asignado | Quien lo atenderá. |
| Ticket escalado | Quien escala (`tickets.approve`) y lo alcanza, más quien lo atiende. |
| Cotización por aprobar | Quien aprueba cotizaciones y la alcanza, salvo quien la creó. |
| Tu cotización fue aprobada / rechazada (también "por el cliente", desde el portal) | Quien la creó. |
| Contrato activado | Quien gestiona servicios contratados y los alcanza. |
| El cliente firmó el contrato (falta la empresa) | Quien firma por la empresa (`contracts.approve`) y lo alcanza. |
| Contrato firmado | Lo mismo, más quien lo vendió. |
| Firma rechazada por el cliente | Quien vendió el contrato y quien lo envió a firma. |
| Solicitud del portal → oportunidad | Quien lleva la oportunidad (con CRM). Sin CRM, o sin responsable: quien atiende la bandeja (`portal.manage`). |
| Orden de compra por aprobar | Quien aprueba compras y la alcanza, salvo quien la creó. |
| Comprobante de proveedor por aprobar | Quien aprueba comprobantes (`payables.approve`) y lo alcanza. |
| Horas por aprobar | Quien aprueba horas y las alcanza, salvo quien las registró. |
| Costo por aprobar | Quien aprueba costos y lo alcanza. |
| Diferencia en arqueo de caja | Quien ve Bancos y caja (`treasury.view`). |

Añadido a lo del prototipo: "Tu cotización fue rechazada", "El cliente firmó el contrato", y el aviso de horas y costos (estaba en pendientes).

**Preferencias de Mi perfil.** Ahora surten efecto:

- Sin "En la aplicación", el aviso no aparece en la campana.
- Con "Correo", sale un correo con la plantilla de siempre: título, detalle, empresa y el botón "Abrir en el sistema".
- Si la persona solo pidió correo, queda el registro de que se le avisó.
- WhatsApp se guarda y dice que aún no está disponible.

El aviso se guarda junto con la operación: si la operación se deshace, no hay aviso. El correo sale después de responder a quien hizo la operación y, si falla, la operación no se pierde.

**Campana.**

- Contador de no leídas: "9+" desde diez, como en el prototipo.
- Al pulsarla, un panel con las 8 más recientes, "Marcar todas como leídas" y "Ver todas". Es una mejora: en el prototipo la campana solo lleva a la página.
- Un clic en un aviso lo marca como leído y abre lo que lo originó.
- La campana mira si hay avisos nuevos cada minuto, mientras la pestaña está a la vista, y al volver a ella.
- La consola de plataforma no tiene campana.

**Centro de notificaciones (`/notificaciones` del prototipo, aquí `/notifications`).**

- La lista del prototipo, con las no leídas resaltadas.
- Pestañas Todas / Sin leer / Leídas, buscador y paginación.
- Exportar a Excel o CSV al pie. Son los avisos de la propia persona: no pide permiso y no va a la auditoría.

**Protecciones.**

- Cada persona ve solo las suyas. La de otra persona o de otra empresa "no existe" (404).
- La base no deja cambiar el texto ni borrar avisos (la aplicación solo marca la lectura), ni volver a "no leído".
- El enlace de un aviso solo puede ser una pantalla del sistema.

## API

- `GET /v1/dashboard` → `App\Http\Controllers\DashboardController`.
- Notificaciones (siempre las propias) → `NotificationController`:
  - `GET /v1/me/notifications` (`status=all|unread|read`, `q`, paginado);
  - `GET /v1/me/notifications/summary`;
  - `POST /v1/me/notifications/{id}/read`;
  - `POST /v1/me/notifications/read-all`.
- `App\Services\Notifier`: es la pieza de todo aviso nuevo.
  - `send(evento, título, detalle, enlace, personas)`;
  - `withPermission(permiso, registro)`: quien tiene el permiso y alcanza el registro.
- `AccessScope::applyFor()`: el alcance de otra persona sobre un registro.
- `CheckPermission::moduleAvailable()` ahora es pública.
- Correo: `App\Mail\NotificationMail`, sobre `mail.details`.
- **Migración `2026_10_23_000300_in_app_notifications`** (corrida en la PC, no en el VPS):
  - columnas `event`, `link`, `read_at` y `actor_user_id` en `notifications`, que ya existía sin uso;
  - canal `IN_APP` o `EMAIL`;
  - enlace interno;
  - leído para siempre (disparador);
  - la aplicación solo actualiza `read_at` y no borra.

## Web

- `components/dashboard/home/DashboardView.tsx` y `types/dashboard.ts`.
- `components/layout/NotificationBell.tsx` (en `Header`, con `notifications`).
- `components/dashboard/notifications/NotificationsCenter.tsx` y `lib/notifications.ts`.
- Rutas `/dashboard` y `/notifications`, también en `proxy.ts`.
- Menú: "Panel de control" ya no es "próximamente".
- Mi perfil: se quitó la nota "Se aplicarán… cuando exista Notificaciones" y WhatsApp dice que aún no está disponible.
- Comprobantes de proveedor abre la ficha con `?invoice=<id>`, que es el enlace del aviso.

## Cómo se probó

- **Pruebas automáticas:** `NotificationsAndDashboardTest` (5 pruebas, 89 comprobaciones). Cubren:
  - canales según la preferencia (campana, solo correo, nada) y sin aviso repetido al reasignar a la misma persona;
  - a quién le llega según permiso y alcance: toda la empresa y la misma sede sí; otra sede, solo lo propio, sin permiso y quien registró, no;
  - el centro: lista, búsqueda, filtros, marcar leída o todas, la de otra persona y la de otra empresa → 404;
  - las protecciones de la base;
  - el panel por permiso, alcance y plan (con candado), un rol sin permisos, otra empresa sin datos ajenos y el técnico;
  - exportar la actividad sin `audit.export` → 403, y sin sesión → 401.
- **Total:** 280 pruebas pasan.
- **Datos de prueba en Tenant A**, creados por los flujos normales:
  - OT-2026-0007 asignada a Tomás (Técnico) para el 24/10;
  - 2,5 h de Tomás en esa OT (por aprobar);
  - COT-2026-0009 enviada a aprobación;
  - TKT-2026-0011 "Equipo no enciende tras mantenimiento".
  - Quedaron 8 avisos, a admin, gerencia, operaciones y técnico, y sus 8 correos en el log.
- **Navegador** (Operaciones y Técnico, a 1366 y 375 px, claro y oscuro):
  - el panel con datos;
  - la campana y su contador: un clic marca y abre, y el contador baja;
  - el centro: pestañas, marcar todas, y exportar a CSV y a Excel (archivo de 7 KB);
  - el técnico entra a su app y desde el menú abre el panel;
  - sin desborde a 375 px.
  - Los bloques que esas cuentas no tienen (candado de plan, "Qué está funcionando", actividad reciente) se vieron simulando la respuesta de la API.
- **No se probó en el navegador** con la cuenta de administrador ni con la de Gerencia: sus contraseñas no estaban en esta sesión.

## Pendiente

- Avisos por fecha con tarea programada (SLA por vencer, cobranza, renovaciones, recordatorios de actividades) cuando se active el programador del servidor.
- Plantillas, bitácora y canales de la empresa (Administración → Notificaciones, P-SEC-09). El ítem del menú sigue como "próximamente".
- WhatsApp: necesita proveedor.
- Avisos al cliente en el portal.
- Correr la migración en el VPS.
- Las horas se registran con la hora tal cual, sin zona horaria: es el pendiente general de la zona horaria de la aplicación. Un registro de 8:00 en Perú se guarda como 8:00 UTC.
