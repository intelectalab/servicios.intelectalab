# 2026-10-07 — Comercial: Solicitudes del portal

Continúa [2026-10-07_contratos-ajustes.md](2026-10-07_contratos-ajustes.md).

## Qué se pidió

La pantalla **Solicitudes del portal**, la última de Comercial, con las reglas de siempre.

## Cómo es en el prototipo

- Una bandeja, sin ficha: Solicitud (número y descripción), Cliente, Servicio, Ruta ("ERP directo" o "CRM → Oportunidad"), Estado y un botón por fila.
- **Estados:** Recibida → En revisión → Cotizada, o Rechazada (con motivo).
- **Cotizar:** toma la solicitud (pasa a En revisión, aviso "Solicitud tomada") y abre una cotización nueva con el cliente y el servicio ya puestos. Al guardar esa cotización, la solicitud queda Cotizada y el botón pasa a "Ver cotización".
- La cotización nacida así tiene origen "Solicitud del portal".

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `database/migrations/2026_10_07_000200_portal_requests_inbox.php` (nuevo) | En `portal_requests`: número (`request_number`, SOL-…), servicio pedido (`service_id`) y motivo del rechazo (`resolution_note`); los estados pasan a ser Recibida, En revisión, Cotizada y Rechazada. Permiso nuevo `portal.view`; `portal.view` y `portal.manage` (que ya existía sin uso) vienen con el módulo Cotizaciones y los reciben los roles que ya veían o gestionaban cotizaciones. |
| `Modules/Portal/Http/Controllers/PortalRequestController.php` (nuevo) | **Bandeja** (buscador por número, texto o cliente; filtro por estado), **Tomar** y **Rechazar** con motivo obligatorio; todo queda en la auditoría. |
| `Modules/Services/Http/Controllers/QuotationController.php`, `Http/Requests/StoreQuotationRequest.php` | Al crear una cotización se puede indicar la solicitud del portal que se cotiza: debe ser del mismo cliente y estar Recibida o En revisión. La cotización nace con origen Portal y la solicitud queda Cotizada y enlazada. Sin ese dato, crear una cotización funciona igual que antes. |
| `Modules/Portal/Http/Controllers/PortalController.php` | La solicitud que envía el cliente desde el portal nace con su número, en estado Recibida y, si lo indica, con el servicio. |
| `Modules/Portal/Models/PortalRequest.php` | Campos nuevos y relaciones con cliente, servicio y cotización. |
| `routes/api.php` | `GET /portal-requests` pide `portal.view`; tomar, `quotations.manage` (es el primer paso de cotizar); rechazar, `portal.manage`. |
| `tests/Feature/ComercialTest.php` | Prueba nueva: bandeja y búsqueda, tomar, cotizar (origen Portal, solicitud enlazada, cliente distinto rechazado), no cotizar dos veces, rechazar con su permiso y motivo, 403 sin `portal.view` y aislamiento entre empresas. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/portal-requests/PortalRequestsList.tsx`, `app/(dashboard)/portal-requests/page.tsx` (nuevos) | Bandeja con las columnas del prototipo, buscador, filtro Estado, casillas, exportar a Excel/CSV y paginación. Por fila: **Cotizar** y **Rechazar** si está abierta, **Ver cotización** si ya se cotizó, **Ver oportunidad** si vino por CRM. |
| `components/dashboard/quotations/QuotationEditor.tsx` | Si se llega desde una solicitud, la cotización nueva abre con el cliente y el servicio puestos y al guardar avisa a la API de qué solicitud es. Entrando por "Nueva cotización" no cambia nada. |
| `components/layout/AppShell.tsx`, `proxy.ts` | "Solicitudes del portal" deja de estar atenuado en Comercial (módulo COTIZACIONES, permiso `portal.view`). |
| `types/commercial.ts` | Tipo de la solicitud. |
| `components/dashboard/contracts/ContractDetail.tsx` | Los avisos de "aún no está disponible" (firma, adjuntar) salen con el ícono de aviso en vez del de éxito. |

## Qué NO se tocó

Clientes, Proveedores, Empleados, Planes SaaS. Catálogo, contratos y órdenes. De cotizaciones, solo lo indicado.

## Diferencias con el prototipo

- **Botón Rechazar: añadido.** El prototipo define el estado Rechazada y su transición con motivo, pero en la bandeja solo pone "Cotizar". Sin el botón no habría forma de rechazar. El motivo se ve bajo el estado.
- **Buscador, filtro por estado, casillas, exportar y paginación: añadidos**, como en los demás listados (el prototipo muestra la tabla sola).
- **Ruta CRM:** todas las solicitudes van hoy por "ERP directo". En el prototipo, con CRM activo la solicitud crea una oportunidad en el pipeline; ese pipeline no existe aún. La columna y el botón "Ver oportunidad" (que avisa que no está disponible) ya están para cuando exista.
- **Permisos:** rechazar pide `portal.manage` y cotizar pide `quotations.manage` (en el prototipo, crear cotizaciones).
- No se trajo la nota explicativa bajo el título ni la de "Desde una solicitud del portal" en el editor.

## Base de datos

- Migración `2026_10_07_000200` **ya corrida** en la base local (solo añade; no había solicitudes).
- **Datos de prueba insertados a mano** en "Tenant A - Prueba", porque aún no existe el portal del cliente para crearlas: SOL-2026-0001 (Empresa AAA, Mantenimiento preventivo), SOL-2026-0002 (Juan Pablo Pérez Gómez, Cambio de frenos) y SOL-2026-0003 (Empresa AAA, Consultoría técnica).
- Tras la prueba: SOL-2026-0001 quedó **Cotizada** con la cotización nueva **COT-2026-0009** (borrador, S/ 448.40); SOL-2026-0003 quedó **Rechazada** (motivo de prueba); SOL-2026-0002 sigue Recibida.

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test`: las 56 pruebas pasan. En el navegador con `ventas@erp-saas.test`:

1. **Bandeja** (1440 px): las tres solicitudes con sus columnas y los botones Cotizar y Rechazar; "Solicitudes del portal" activo en el menú.
2. **Cotizar** SOL-2026-0001: abre "Nueva cotización" con Empresa AAA y la línea Mantenimiento preventivo (S/ 380.00) ya puestas. Guardar borrador crea COT-2026-0009 con Origen "Solicitud del portal"; de vuelta en la bandeja la solicitud está Cotizada con "Ver cotización".
3. **Rechazar** SOL-2026-0003: sin motivo avisa "Debes indicar un motivo."; con motivo queda Rechazada y el motivo se ve bajo el estado.
4. **Exportar:** CSV con cabeceras y filas; el Excel se genera (archivo válido, no se abrió en Excel).
5. **375 px:** sin desplazamiento horizontal, filas como tarjetas.

No se probó en el navegador: el candado de los botones (los tres roles de prueba tienen `portal.manage` y `quotations.manage`; la API responde 403 en las pruebas automáticas), "Ver oportunidad" (no hay solicitudes por CRM), ni una solicitud enviada de verdad desde el portal.

## Pendiente

- **Portal del cliente** (pantallas) y **Usuarios del portal**: hoy las solicitudes solo pueden crearse por la API del portal, y no hay usuarios de portal.
- Ruta CRM: crear la oportunidad cuando la empresa tiene CRM, al construir el Pipeline.
- En teléfono, una solicitud ya rechazada deja una fila vacía al final de su tarjeta (la de acciones).
