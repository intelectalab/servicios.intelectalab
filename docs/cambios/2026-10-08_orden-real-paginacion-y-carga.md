# 2026-10-08 — Orden real en los listados, paginación que responde y carga rápida

Continúa [2026-10-08_tablas-de-listado-como-actores.md](2026-10-08_tablas-de-listado-como-actores.md).

## Qué se pidió

1. Ordenar desde la cabecera con **orden real** (sobre todo el listado, no solo la página visible).
2. La paginación "es rara": tarda demasiado en pasar de página, no cambia de color al pasar el cursor y no se sabe si se pulsó.
3. Al entrar a Clientes y actores "demora muchísimo en cargar".

## Por qué tardaba

La API responde rápido (unos 0,2 s por llamada). Lo lento era la cantidad de llamadas, que el servidor local atiende de una en una:

- **Cada llamada se hacía dos veces.** Antes de cada petición el navegador pide permiso (preflight) y la API no dejaba guardarlo, así que lo repetía siempre.
- **Clientes y actores pedía los valores de las columnas personalizadas fila por fila:** con 10 filas, 12 llamadas por página (24 con el permiso previo), en cada cambio de página.
- En local, la web llamaba a `localhost:8000`, que en Windows añade unos 0,2 s por conexión frente a `127.0.0.1`.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `app/Services/ListSort.php` (nuevo) | Pieza común: aplica `?sort=<columna>&dir=asc|desc` en la base. Solo acepta las columnas que cada listado declara; los vacíos van siempre al final. |
| `ServiceController`, `QuotationController`, `ContractController`, `OrderController`, `PortalRequestController`, `ExchangeRateController` | Su listado acepta el orden. Sin `sort` siguen con el de siempre. |
| `PartyController.php` | El listado acepta el orden (Nombre, Documento, Roles, Sector / relación, Estado y cualquier columna personalizada; las numéricas como número). Además **devuelve en la misma respuesta los valores de las columnas personalizadas** de la página (`custom_values`), con una sola consulta. |
| `config/cors.php` (nuevo) | Igual que el de Laravel por defecto, con `max_age` de un día: el navegador guarda el permiso previo y deja de repetirlo. |

Sin migraciones.

### Web

| Archivo | Cambio |
| --- | --- |
| `components/ui/proto.tsx` | **Tabla de listados:** clic en el título de una columna ordena (y otra vez invierte); flecha ↑ ↓ en la columna ordenada; el menú de tres puntos suma Orden ascendente, Orden descendente y Quitar orden. Mientras llega la respuesta la tabla se atenúa. **Paginador:** los botones cambian de color al pasar el cursor y se hunden al pulsar; la página pulsada se marca al instante con un indicador de carga hasta que llega; indicador también junto a "Mostrando…". |
| Los 6 listados (`ServicesList`, `QuotationsList`, `ContractsList`, `OrdersList`, `PortalRequestsList`, `ExchangeRates`) | Piden el orden a la API; al cambiar de orden vuelven a la página 1. **Exportar todo** sale con el mismo orden. |
| `admin/UsersList.tsx` | Ordena aquí mismo, porque la lista de usuarios llega entera (sigue siendo sobre todos). |
| `actors/ActorsTable.tsx`, `actors/ActorsList.tsx`, `types/actor.ts` | El orden ya no es solo de la página: lo pide a la API. Se añadió "Quitar orden". Los valores de las columnas personalizadas se leen del propio listado (ya no hay una llamada por fila) y las columnas se piden una vez, no en cada página. |
| `.env.local` (no va al repo) | `NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api`. |

Columnas sin orden: Origen en Órdenes y Ruta en Solicitudes (se calculan a partir de varios datos).

**Por qué no cambiaba el color al pasar el cursor:** el botón llevaba la clase `app-surface`, que en `globals.css` le gana a las clases de hover. Se quitó de esos botones.

## Qué NO se tocó

- Las pantallas desconectadas de Clientes, Proveedores y Empleados.

## A tener en cuenta

- **Total y Valor ordenan por el número, sin convertir moneda:** US$ 120 queda por debajo de S/ 316.
- Sector / relación ordena por sector (organizaciones) o cargo (personas); "Contacto de …" no entra en ese orden.
- Al entrar a una pantalla, en desarrollo cada llamada sale duplicada (comportamiento de React en modo desarrollo); publicado no ocurre.

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test`: las 58 pruebas pasan. Contra la API local se pidió cada orden de Clientes y actores, Cotizaciones, Servicios y Solicitudes y se revisó el resultado; los de Contratos, Órdenes y Tipo de cambio se validaron directamente en la base (la cuenta de Ventas no entra a esas pantallas).

En el navegador con `ventas@erp-saas.test`:

1. **Clientes y actores, cambio de página:** antes 12 llamadas (24 con el permiso previo); ahora **1 llamada, 0,3 a 0,5 s**. Al pulsar "2", el botón se marca al instante con el indicador y la tabla se atenúa hasta que llega.
2. **Orden real:** Nombre ascendente empieza en "ABC Store SAC"; descendente en "Yadira…", y la página 2 continúa ese orden. Estado descendente trae los inactivos primero y vuelve a la página 1. "Quitar orden" devuelve el orden habitual.
3. **Cotizaciones:** Total ascendente y descendente, Cliente ascendente y Quitar orden.
4. **Paginador:** al pasar el cursor por "Página siguiente" el botón queda con fondo y borde azules.

## Segunda tanda (2026-10-08): filtrar por columna sobre toda la tabla, y lo que faltaba probar

Pediste que "Filtrar por esta columna" abarque **la tabla completa** y que probara lo pendiente, exportar incluido.

### Filtrar por columna

- **Antes:** el filtro se aplicaba en el navegador, solo a las 10 filas de la página.
- **Ahora:** lo aplica la API sobre todo el listado. El contador ("Mostrando 1 a 8 de 8"), la paginación, el orden y **Exportar** respetan los filtros.
- Mismos operadores de siempre: contiene, igual, mayor, menor; en fechas: hoy, ayer, esta semana, semana pasada, mes pasado, desde inicio de año y rango. Una casilla Sí / No sin marcar cuenta como "No".
- Al escribir espera un momento antes de pedir, y vuelve a la página 1.

Archivos: `PartyController.php` (`applyColumnFilters`, parámetro `filters`) y `actors/ActorsList.tsx`. `hooks/useColumnFilters.ts` no se tocó (lo usan las pantallas desconectadas).

Diferencia: en Sector / relación el filtro mira el sector o el cargo; el texto "Contacto de …" no entra.

### Lo que se probó ahora

`php artisan test`: 58 pruebas pasan. Contra la API: 14 filtros distintos (cada columna, dos a la vez, fechas, valores no válidos).

En el navegador con `admin@erp-saas.test`:

1. **Filtrar por columna (Clientes y actores):** Roles contiene "proveedor" → "Mostrando 1 a 8 de 8" (los 8 de todo el listado, no solo los de la página); sumando Estado = Activo → 4. "Limpiar todos" vuelve a 45.
2. **Orden por columna personalizada:** clic en "Año" trae primero el registro que tiene valor.
3. **Exportar con orden y filtros (CSV):** con Nombre descendente salen las 45 filas, de "Yadira…" a "ABC Store SAC"; con el filtro de proveedores, 8 filas en ese mismo orden.
4. **Orden en los demás listados**, columna por columna, ascendente y descendente: Servicios (6 columnas), Solicitudes del portal (4), Contratos (6), Órdenes (4), Tipo de cambio (4, con un solo registro no hay nada que reordenar) y Usuarios (4).
5. **Tema claro:** la tabla, la cabecera fija y el paginador se ven bien.
6. La columna inmovilizada **se conserva al recargar** la página.

Sigue sin probar: el Excel (se revisó el CSV, que sale de las mismas filas) y arrastrar columnas con el ratón.

### Estado, Modalidad, SLA y Origen ordenan por el texto visible

Ordenaban por su código interno (en Contratos salía Vigente, Borrador, Suspendido, por ACTIVE, DRAFT, SUSPENDED). Pediste que sea por el texto que se ve: ahora el orden ascendente es **Borrador, Suspendido, Vigente**. Aplica a Estado en Servicios, Cotizaciones, Contratos, Órdenes y Solicitudes; Modalidad en Servicios; SLA en Contratos y Origen en Cotizaciones. En Clientes y actores ya coincidía (Activo, Inactivo).

Archivos: `ListSort.php` (`labels`) y los cinco controladores. Probado en la base (códigos y su texto, en orden) y contra la API con Cotizaciones, Servicios y Solicitudes; las 58 pruebas pasan. No se volvió a mirar en pantalla.

Si se cambia el texto de un estado en la web (`lib/commercial.ts`, `lib/contracts.ts`, `lib/orders.ts`), hay que cambiarlo también en el controlador para que el orden siga coincidiendo.

## Pendiente

- El servidor local (`php artisan serve`) atiende una petición a la vez; publicado, con un servidor normal, las llamadas van en paralelo.
