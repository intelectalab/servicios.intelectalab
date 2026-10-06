# 2026-10-05 — Comercial: vista en teléfono como el prototipo

Continúa [2026-10-05_comercial-seleccion-exportar-paginacion.md](2026-10-05_comercial-seleccion-exportar-paginacion.md).

## Qué se pidió

La parte responsive de Catálogo de servicios y Cotizaciones que muestra el prototipo.

## Qué hace el prototipo en pantallas chicas

- Hasta 639 px (teléfonos): **cada fila de tabla se convierte en una tarjeta**, con la etiqueta de cada dato a la izquierda y el valor a la derecha; los buscadores y filtros ocupan todo el ancho; la lista "etiqueta → valor" de las fichas pasa a una sola columna; los indicadores reducen el número.
- Hasta 767 px: los formularios pasan a una sola columna y el título se achica.
- Hasta 1100 px: el editor de cotización (líneas + totales) y la ficha (2 + 1 columnas) pasan a una sola columna.

## Qué se cambió

| Archivo | Cambio |
| --- | --- |
| `app/globals.css` | Bloque `@media (max-width: 639px)` con las reglas `.proto-tbl` del prototipo: tabla → tarjetas, usando `data-label` de cada celda. Solo se añadió; no se tocaron los tokens ni las reglas existentes. |
| `components/ui/proto.tsx` | `DataTable` marca la tabla con `proto-tbl` y pone `data-label` en cada celda (vacío en la casilla de selección); buscador y filtros a todo el ancho en teléfono; `Kv` en una columna en teléfono; título e indicadores más chicos en teléfono. |
| `components/dashboard/quotations/QuotationEditor.tsx` | La tabla de líneas usa `proto-tbl` y `data-label` (Servicio, Cantidad, Precio unit., Desc. %, IGV, Total). |
| `components/dashboard/quotations/QuotationDetail.tsx` | Ídem en las líneas cotizadas. |

Lo que ya estaba desde la primera entrega y no cambió: formularios en una columna por debajo de 768 px; editor y ficha en una columna por debajo de 1024 px (el prototipo corta en 1100 px).

## Qué NO se tocó

Clientes, Proveedores, Empleados y sus tablas: no usan `proto-tbl`.

## Cómo se probó

`tsc` y `eslint` sin errores. En el navegador a 375 px de ancho (`admin@erp-saas.test`):

1. Catálogo de servicios: sin desplazamiento horizontal; cada servicio es una tarjeta con Servicio, Categoría, Modalidad, Precio vigente, Garantía y Estado; la casilla queda arriba de la tarjeta; buscador y los tres filtros a todo el ancho.
2. Cotizaciones: tarjetas por cotización; indicadores y pie de paginación dentro del ancho.
3. Ficha de la cotización: las líneas como tarjetas, totales y trazabilidad en una columna.
4. Editar cotización: cabecera en una columna; la línea como tarjeta con sus dos campos editables.

No se probó entre 640 y 1100 px (tableta): ahí la tabla sigue siendo tabla con desplazamiento horizontal, como en el prototipo.
