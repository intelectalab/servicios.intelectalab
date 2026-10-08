# 2026-10-08 — Filtrar por columna en los demás listados

Continúa [2026-10-08_orden-real-paginacion-y-carga.md](2026-10-08_orden-real-paginacion-y-carga.md) y los ajustes de filtros de [2026-10-07_clientes-y-actores.md](2026-10-07_clientes-y-actores.md).

## Qué se pidió

Llevar "Filtrar por esta columna" de Clientes y actores a los demás listados, donde aporte, con las mismas reglas: un solo filtro por dato, las etiquetas a la vista y "Limpiar filtros" que limpia todo.

## Cómo queda

En los tres puntos de cada cabecera aparece **Filtrar por esta columna**:

| Listado | Agrega un filtro | Abre el filtro de arriba | Sin filtro |
| --- | --- | --- | --- |
| Cotizaciones | Número, Cliente, Válida hasta (fecha), Total (número), Responsable | Origen | Estado (se filtra con las pestañas) |
| Contratos | Contrato, Cliente, Vigencia (fecha), Valor (número) | Estado | SLA |
| Órdenes | Orden, Cliente, Fecha (fecha) | Estado | Origen |
| Catálogo de servicios | Servicio, Precio vigente (número), Garantía (número) | Categoría, Modalidad, Estado | — |
| Solicitudes del portal | Solicitud, Cliente, Servicio | Estado | Ruta |

- Los filtros los aplica la API sobre **todo el listado**: el contador, la paginación, el orden y **Exportar** los respetan.
- Junto a los filtros de arriba están el botón **Filtros por columna** (con su contador) y **Limpiar filtros**, que quita el buscador, los filtros de arriba y los de columna.
- Con el panel cerrado, cada filtro queda a la vista como etiqueta con su ✕.
- Tipo de cambio y Usuarios no llevan filtro por columna (listas cortas).

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `app/Services/ColumnFilters.php` (nuevo) | Pieza común: lee `?filters=` y aplica cada filtro en la base (contiene, igual, mayor, menor; en fechas: hoy, ayer, esta semana, semana pasada, mes pasado, desde inicio de año y rango). Solo acepta las columnas que cada listado declara. |
| `QuotationController`, `ContractController`, `OrderController`, `ServiceController`, `PortalRequestController` | Declaran sus columnas filtrables. |
| `PartyController.php` | Usa la pieza común (antes tenía la lógica propia); se comporta igual. |

Sin migraciones.

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/ColumnFilters.tsx` (nuevo) | Lo que antes estaba dentro del listado de actores, como pieza común: el estado de los filtros (`useServerColumnFilters`), la barra con el botón, "Limpiar filtros", las etiquetas y el panel (`ColumnFilterBar`), y abrir el filtro de arriba (`openTopFilter`). |
| `components/ui/proto.tsx` | Cada columna puede declarar `filter` (el tipo de filtro, o `{ top: "Estado" }` si ya tiene filtro arriba); la tabla muestra la opción en el menú y avisa con `onFilter`. |
| `QuotationsList`, `ContractsList`, `OrdersList`, `ServicesList`, `PortalRequestsList` | Declaran el filtro de cada columna, envían los filtros a la API (también al exportar) y muestran la barra. |
| `actors/ActorsList.tsx` | Pasa a usar la pieza común; se ve y funciona igual. |

`hooks/useColumnFilters.ts` no se tocó.

## A tener en cuenta

- **Total, Valor y Precio filtran por el número, sin convertir moneda** (igual que el orden): "Total mayor que 500" incluye tanto S/ 600 como US$ 600.
- **Vigencia** (Contratos) filtra por la fecha de **inicio**.
- **Si los filtros no dejan ninguna fila, la tabla se reemplaza por el aviso "No hay … con estos filtros"** y desaparecen las cabeceras: para cambiar los filtros se usan las etiquetas, el panel o "Limpiar filtros".
- El operador por defecto de una columna numérica es "contiene" (así lo trae el filtro de siempre); para comparar se elige "mayor que" o "menor que".

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test`: las 58 pruebas pasan. Contra la API: 16 filtros (monto mayor, igual y contiene; cliente; fechas por rango y por semana; dos filtros a la vez; servicios, solicitudes y actores).

En el navegador con `admin@erp-saas.test`, en cada listado: qué columnas ofrecen el filtro, aplicar filtros, ver el contador, las etiquetas, abrir el filtro de arriba y limpiar.

1. **Cotizaciones:** Total mayor que 500 → 4 de 9; sumando Válida hasta del 1 al 20 de octubre → 3. Origen abre el desplegable de arriba. Estado no ofrece filtro. Limpiar vuelve a 9.
2. **Contratos:** Valor mayor que 500 → 2; sumando Cliente contiene "empresa" → 1. Estado abre el de arriba. **Exportar a CSV con esos filtros: 1 fila.**
3. **Órdenes:** Cliente contiene "ana" → 2 de 3. Un filtro de fecha sin fechas no filtra y sale como "Fecha: periodo sin fechas".
4. **Servicios:** Precio vigente mayor que 200 → 3 de 5; sumando Servicio contiene "srv-00" → 2 (busca también por código). Categoría, Modalidad y Estado abren los de arriba.
5. **Solicitudes:** Servicio contiene "frenos" → 1 de 3.
6. **Clientes y actores** (para comprobar que sigue igual tras pasar a la pieza común): Documento contiene "2099" → 2; sumando Nombre contiene "actor" → 1; Roles y Estado abren los de arriba; limpiar vuelve a 45.

Sin errores en la consola. No se probó: a 375 px, el tema claro, ni con el ratón que el desplegable de arriba se abra solo (en la prueba automática queda enfocado).

## Pendiente

- Decidir si, cuando no hay filas, la tabla debe seguir mostrando sus cabeceras.
