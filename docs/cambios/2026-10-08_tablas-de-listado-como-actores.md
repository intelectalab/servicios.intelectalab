# 2026-10-08 — Las tablas de los listados, como la de Clientes y actores

Continúa [2026-10-07_clientes-y-actores.md](2026-10-07_clientes-y-actores.md).

## Qué se pidió

Que las demás tablas tengan el mismo manejo que la de Clientes y actores:

- la columna de casillas, fija;
- la primera columna no se cambia de lugar; las demás sí;
- inmovilizar columnas con la misma regla;
- la cabecera fija, y el desplazamiento horizontal igual;
- las columnas personalizadas, solo en Clientes y actores.

## Qué se cambió

Solo web.

| Archivo | Cambio |
| --- | --- |
| `components/ui/proto.tsx` | `DataTable` acepta `tableId`. Con él, la tabla pasa a ser la de los listados (`ColumnTable`, nueva, en el mismo archivo). Sin él sigue como antes (tablas pequeñas dentro de las fichas). |
| `ServicesList.tsx`, `QuotationsList.tsx`, `ContractsList.tsx`, `OrdersList.tsx`, `PortalRequestsList.tsx`, `ExchangeRates.tsx`, `UsersList.tsx` | Una línea cada uno: `tableId="…"`. |

Cómo queda cada listado:

- **Cabecera fija** al bajar por la tabla, y **casillas fijas** a la izquierda al desplazar hacia los lados. La tabla se desplaza dentro de su recuadro, como la de actores.
- **Mover columnas:** se arrastran por el asa de seis puntos de la cabecera. La primera columna (Servicio, Número, Solicitud…) no tiene asa y nada se puede soltar delante de ella.
- **Inmovilizar:** el menú de tres puntos de cada cabecera ofrece Inmovilizar / Desinmovilizar columna. Inmovilizar cualquiera inmoviliza también la primera y la otra queda a su lado. Desinmovilizar la primera libera todas.
- El orden y las columnas inmovilizadas **se recuerdan en el navegador**, por separado para cada listado.
- En Solicitudes del portal, la columna de botones (Cotizar, Rechazar…) se queda siempre al final y no se mueve.

## Qué NO se tocó

- La tabla de Clientes y actores (`ActorsTable.tsx`): sigue con lo suyo (columnas personalizadas, ordenar, filtrar por columna, edición en celda).
- Las tablas dentro de las fichas (precios de un servicio, servicios de un contrato, pestañas de la vista 360): sin cambios.
- La API.

## Diferencias con la tabla de Clientes y actores

- **Sin columnas personalizadas** (pedido así), y por eso sin la columna "+" del final.
- El menú de la cabecera solo tiene Inmovilizar. **No tiene Orden ascendente / descendente ni "Filtrar por esta columna"**: no se pidieron y cada listado ya tiene sus filtros arriba. Se pueden añadir.
- No hay edición en la celda: en estos listados la fila abre la ficha.

## Base de datos

Sin migraciones.

## Cómo se probó

`tsc` y `eslint` sin errores. En el navegador con `ventas@erp-saas.test`, ventana de 900 × 620:

1. **Cotizaciones:** al bajar por la tabla la cabecera se queda arriba; al desplazar a la derecha las casillas se quedan a la izquierda. Solo la primera columna (Número) no tiene asa.
2. Inmovilizar Estado: quedan fijas casillas, Número y Estado, una junto a otra, mientras el resto se desplaza. Desinmovilizar Número libera las dos; Inmovilizar Número la fija sola.
3. Mover Origen delante de Cliente: se reordena y se guarda. Soltar Total sobre Número: no se acepta.
4. Clic en una fila: abre la ficha de la cotización.
5. **Servicios** y **Solicitudes del portal:** cargan con la tabla nueva; en Solicitudes la columna de botones queda al final, sin asa ni menú, con Cotizar / Rechazar / Ver cotización.
6. **375 px (Cotizaciones):** filas como tarjetas, sin desplazamiento horizontal.

Cómo se probó mover columnas: con eventos de arrastre simulados, no con el ratón (el panel del navegador de pruebas dejó de dibujar). **Falta que lo arrastres tú con el ratón.**

No se probó en pantalla: Contratos, Órdenes, Tipo de cambio y Usuarios (la cuenta de Ventas no entra; usan la misma tabla y el mismo cambio de una línea), ni el tema claro.

## Pendiente

- Decidir si estos listados también llevan ordenar y filtrar por columna desde la cabecera.
