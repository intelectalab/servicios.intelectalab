# 2026-10-05 — Cotizaciones: ajustes del listado

Continúa [2026-10-05_comercial-revision-a-la-vista.md](2026-10-05_comercial-revision-a-la-vista.md).

## Qué se pidió

Al revisar el listado de Cotizaciones contra el prototipo:

1. Las tarjetas de indicadores no se acomodan a todo el ancho.
2. En celular no se pueden ver las pestañas Aprobadas, Rechazadas y Vencidas.
3. Falta la columna Origen.
4. Le falta el "logo" (avatar con iniciales) al responsable.

Antes, dos consultas sin cambios de código: qué significa el IGV en el catálogo y si los servicios pueden tener distintas monedas (la respuesta quedó en "Decisiones y pendientes").

## Qué se cambió

### Web

| Archivo | Cambio |
| --- | --- |
| `components/ui/proto.tsx` | `Kpis`: los indicadores se reparten todo el ancho disponible (antes, en pantallas anchas quedaba una columna vacía a la derecha). `Tabs`: en teléfono (hasta 639 px) las pestañas pasan a varias filas en vez de quedar ocultas a la derecha; desde 640 px siguen en una fila. Pieza nueva `Who`: nombre de la persona con un círculo de iniciales en el azul suave del logo; sin nombre muestra "Sin asignar". |
| `components/dashboard/quotations/QuotationsList.tsx` | Columna **Origen** entre Cliente y Válida hasta (Directa / CRM / Portal). Filtro **Origen** junto al buscador, con las opciones del prototipo (Directa ERP, Desde oportunidad CRM, Solicitud del portal). La columna Responsable usa `Who`. El Excel y el CSV llevan la columna Origen, y exportar respeta el filtro. |
| `types/commercial.ts` | `Quotation.source_type`. |

### API

| Archivo | Cambio |
| --- | --- |
| `Modules/Services/Http/Controllers/QuotationController.php` | `GET /v1/quotations` acepta `source` (`DIRECT`, `OPPORTUNITY`, `PORTAL`). Una cotización sin origen registrado cuenta como directa. |
| `tests/Feature/ComercialTest.php` | El test del listado comprueba el filtro por origen. |

## Qué NO se tocó

- Clientes, Proveedores, Empleados y Planes SaaS.
- La lógica de cotizaciones: crear, editar, aprobar, versiones, exportar.
- `Kpis` y `Tabs` solo se usan hoy en el listado de Cotizaciones; el cambio se heredará en las pantallas nuevas.

## Diferencias con el prototipo

- El avatar del prototipo cambia de color según el nombre; aquí siempre es el azul suave del logo, por la regla de colores.
- En teléfono el prototipo deja las pestañas en una fila que se desliza con el dedo, sin barra visible; aquí se muestran en varias filas para que se vean todas.
- Hoy todas las cotizaciones salen como "Directa": los orígenes CRM y Portal aparecerán cuando esas pantallas creen cotizaciones (el campo `source_type` ya existe en la tabla).

## Base de datos

Sin cambios. `quotations.source_type` ya existía.

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test --filter=ComercialTest`: 11 pruebas pasan. En el navegador con `admin@erp-saas.test`:

1. A 1440 px: los cuatro indicadores ocupan todo el ancho del contenido (antes dejaban un hueco); la tabla muestra Origen ("Directa") y el responsable con su círculo de iniciales.
2. Filtro Origen: "Solicitud del portal" deja el listado vacío; "Directa ERP" muestra las 5 cotizaciones.
3. A 375 px: las seis pestañas quedan en tres filas, todas dentro de la pantalla; sin desplazamiento horizontal; cada fila-tarjeta incluye Origen y Responsable.

La prueba a 375 px se hizo midiendo posiciones; no se pudo tomar captura porque el panel del navegador de pruebas no estaba a la vista. No se abrió el Excel exportado.

## Pendiente

- Marcar el origen CRM o Portal al crear cotizaciones desde esas pantallas, cuando existan.
- La ficha de la cotización sigue mostrando "Cotización directa ERP" como texto fijo en Trazabilidad.
