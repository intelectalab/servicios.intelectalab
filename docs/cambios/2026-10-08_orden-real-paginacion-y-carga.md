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

- Los filtros por columna de Clientes y actores ("Filtrar por esta columna"): siguen filtrando solo la página que se ve.
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

No se probó en pantalla: el orden en Servicios, Solicitudes, Contratos, Órdenes, Tipo de cambio y Usuarios (sí su consulta, salvo Usuarios), ordenar por una columna personalizada desde la tabla (sí contra la API), exportar con orden, ni el tema claro.

## Pendiente

- El servidor local (`php artisan serve`) atiende una petición a la vez; publicado, con un servidor normal, las llamadas van en paralelo.
- Filtrar por columna sobre todo el listado (hoy solo la página visible).
