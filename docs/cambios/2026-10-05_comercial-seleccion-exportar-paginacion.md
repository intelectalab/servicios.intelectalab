# 2026-10-05 — Comercial: selección de filas, Excel con formato y paginación

Continúa [2026-10-05_comercial-campos-y-funciones.md](2026-10-05_comercial-campos-y-funciones.md).

## Qué se pidió

En Catálogo de servicios y Cotizaciones: casillas para elegir qué filas exportar (como en Clientes), que el Excel exportado se vea como el de Clientes, y paginación.

## Qué se cambió

| Qué | Detalle |
| --- | --- |
| Casillas de selección | Una por fila y una en el encabezado que marca o desmarca todas las de la página. Marcar una casilla no abre la ficha. La fila marcada queda resaltada. |
| Barra de selección | Al marcar filas aparece sobre la tabla: cuántas hay, "Exportar selección a Excel", "CSV" y "Quitar selección". |
| La selección se conserva | Al cambiar de página, de filtro o de pestaña las filas marcadas siguen marcadas, así se puede juntar filas de varias páginas en un solo archivo. |
| Exportar todo | Los botones del pie ("Exportar a Excel", "CSV") siguen exportando todas las filas que cumplen los filtros. |
| Excel con formato | Mismo diseño que el de Clientes: título, "Exportado el … · N registros", encabezado de color, filas alternadas, bordes, montos con "S/" y dos decimales, estado en celda de color, encabezado fijo, filtros y sin cuadrícula. Usa el azul del logo (`#1B8FC9` y `#0F5A82`) en lugar del azul del Excel de Clientes. |
| Paginación | Como la de Clientes: "Mostrando X a Y de N", "Filas por página" (10, 25, 50, 100), primera / anterior / números con "…" / siguiente / última. Siempre visible; por defecto 10 filas. |

### Archivos (`apps/web/src`)

| Archivo | Cambio |
| --- | --- |
| `components/ui/proto.tsx` | `DataTable` acepta `selection`; nuevos `useRowSelection`, `SelectionBar` y `PAGE_SIZES`; `Pager` rehecho con filas por página y páginas numeradas. |
| `lib/export-table.ts` | `exportXlsx` con el formato descrito; cada columna declara su tipo (texto, centrado, número, monto, estado). |
| `lib/commercial.ts` | `excelTone`: color de la celda de estado. |
| `components/dashboard/services/ServicesList.tsx` | Selección, barra, paginación y columnas del Excel. |
| `components/dashboard/quotations/QuotationsList.tsx` | Ídem. |

## Qué NO se tocó

- La API: ya admitía `per_page` y `page`.
- Clientes, su tabla, su exportación y `components/Pagination.tsx`. La paginación nueva es una pieza aparte con el mismo comportamiento y el estilo del prototipo.

## Diferencias con Clientes

| Clientes | Aquí | Motivo |
| --- | --- | --- |
| La casilla aparece al pasar el cursor | Siempre visible | Se ve de entrada que las filas se pueden marcar, y funciona en pantallas táctiles. |
| La selección se borra al cambiar de página | Se conserva | Permite exportar filas de varias páginas. Se limpia con "Quitar selección". |
| Con filas marcadas también hay Activar / Desactivar en lote | Solo exportar | No se pidió; los cambios de estado de servicios y cotizaciones tienen reglas por registro. |

## Cómo se probó

`tsc` y `eslint` sin errores. En el navegador (`localhost:3000`, `admin@erp-saas.test`):

1. Cotizaciones: 5 filas con casilla y casilla en el encabezado. Marcar dos no navega a la ficha y muestra "2 filas seleccionadas".
2. "Exportar selección a Excel" genera `cotizaciones_2026-10-05.xlsx` (archivo Excel válido) y "CSV" genera el `.csv` con el encabezado y exactamente las dos filas marcadas; aviso "2 filas exportadas".
3. La casilla del encabezado marca las 5; "Quitar selección" las desmarca y oculta la barra.
4. Cambiar "Filas por página" a 25 vuelve a pedir el listado con ese tamaño.
5. Catálogo de servicios: mismas casillas, barra y pie.

**No comprobado:**
- El **aspecto** del Excel abierto en Excel. En la prueba el archivo se capturó en memoria en vez de descargarse; se verificó que se genera y es válido, no cómo se ve. El formato replica paso a paso el de Clientes.
- La navegación entre páginas con más de una página: en "Tenant A - Prueba" hay 4 servicios y 5 cotizaciones, y el tamaño mínimo es 10.

## Pendiente

- Abrir un Excel exportado y confirmar que el diseño es el esperado.
- Probar la paginación con más de 10 registros.
