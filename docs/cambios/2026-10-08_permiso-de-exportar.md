# 2026-10-08 — Exportar como permiso aparte, comprobado en la API y con auditoría

## Qué se pidió

Tras separar "Eliminar" en Clientes y actores, preguntaste si exportar debía ser otro permiso. Se propuso que sí, hecho en el servidor y con registro, y respondiste "sí, hazlo".

## Cómo funciona

- Cada listado que se exporta tiene su permiso **`<recurso>.export`**. Ver un listado ya no alcanza para llevárselo en un archivo.
- Sin el permiso, "Exportar a Excel", "CSV" y "Exportar selección" **no se esconden**: salen con candado y "Tu rol no tiene permiso …".
- **Lo comprueba la API, no solo el botón:**
  - Las páginas que la pantalla pide para armar el archivo van marcadas (`?export=1`) y sin el permiso responden 403.
  - Antes de generar el archivo la pantalla registra la exportación (`POST /v1/exports`); sin el permiso responde 403 y el archivo no se genera. Vale también para las filas marcadas en pantalla.
  - Ningún listado entrega más de **200 filas por página**, se pida lo que se pida (antes no había tope).
- **Cada exportación queda en auditoría**: quién, qué listado, formato, cuántas filas, si era una selección y con qué filtros, búsqueda y orden.
- El permiso solo vale si la empresa tiene el módulo del listado, como los demás.

| Listado | Permiso |
| --- | --- |
| Clientes y actores | `core.export` |
| Catálogo de servicios | `services.export` |
| Cotizaciones | `quotations.export` |
| Contratos | `contracts.export` |
| Órdenes | `orders.export` |
| Solicitudes del portal | `portal.export` |
| Leads | `leads.export` |
| Pipeline | `opportunities.export` |
| Usuarios | `users.export` |

### Reparto

Todo rol que hoy **ve** un listado conserva exportarlo, **menos Ventas** (en el prototipo Ventas no exporta nada). En "Tenant A - Prueba": Administrador exporta los nueve; Gerencia, los ocho que ve; Ventas, ninguno. En Roles y permisos aparece la columna **Exportar**.

## Qué se cambió

| Archivo | Cambio |
| --- | --- |
| `api/app/Http/Middleware/ListExport.php` (nuevo) | La regla en un solo lugar: tope de 200 por página en todos los listados de la empresa, permiso en las páginas de exportación y registro en auditoría. Un listado nuevo se añade a su lista `RESOURCES`. |
| `api/bootstrap/app.php`, `api/routes/api.php` | El control se aplica a todo el grupo de rutas de la empresa; ruta `POST /v1/exports`. |
| `api/.../Subscription/Support/ModulePermissions.php` | El administrador de una empresa nueva recibe también `users.export`. |
| `web/src/lib/export-table.ts` | Las páginas de exportación van marcadas; `recordExport` registra la exportación antes de generar el archivo. |
| `web/src/components/ui/proto.tsx` | `ExportButtons` y `SelectionBar` aceptan el permiso y muestran el candado. |
| Los nueve listados (`ActorsList`, `ServicesList`, `QuotationsList`, `ContractsList`, `OrdersList`, `PortalRequestsList`, `LeadsList`, `PipelineList`, `UsersList`) | Pasan su permiso a los botones y registran la exportación. |

## Qué NO se tocó

- **Monedas y tipo de cambio** sigue exportándose con solo verlo: son datos públicos.
- Los módulos desconectados (Clientes, Proveedores, Empleados).
- Los PDF de cotización, contrato y orden (son un documento, no un listado).
- El Excel y el CSV se siguen armando en el navegador.

## Diferencias con el prototipo

- El prototipo tiene Exportar en su matriz pero lo comprueba en el navegador; aquí lo comprueba la API y queda en auditoría.
- En el prototipo Gerencia no exporta todo lo que ve (p. ej. solicitudes del portal). Aquí conserva lo que ya podía, para que nadie más perdiera nada; se ajusta en Roles y permisos.

## Base de datos

Migración `2026_10_08_000700_add_export_permissions`. **Ya está corrida en la base local**, con el usuario dueño. Solo añade: los nueve permisos y su reparto.

## Lo que este permiso no puede impedir

Quien puede **ver** los datos puede copiarlos a mano o recorrer el listado página por página (de a 200 como mucho). El permiso quita la descarga completa en un clic y deja rastro de cada exportación; no es una barrera contra quien tiene acceso de lectura.

## Cómo se probó

`php artisan test`: **106 pruebas pasan** (101 anteriores + 5 nuevas en `tests/Feature/ExportPermissionTest.php`):

1. Con solo ver, en los nueve listados: el listado normal responde; la página de exportación y el registro responden 403; no queda nada en auditoría.
2. Con el permiso: exporta, y la auditoría guarda quién, listado, formato, filas y filtros. El permiso es por listado. Un listado o formato desconocido no se registra.
3. El permiso no vale si la empresa no tiene el módulo.
4. Pedir 100 000 filas por página devuelve 200; pedir 50 devuelve 50.
5. La matriz de roles ofrece los nueve permisos.

`tsc` y `eslint` sin errores.

En el navegador (`app.localhost:3000`):

- **Ventas:** candado con su mensaje en Clientes y actores, Servicios, Cotizaciones, Solicitudes, Leads y Pipeline, y en "Exportar selección" de Leads. Pidiendo a mano la página de exportación de leads y el registro de una exportación de clientes: 403 en los dos. Pidiendo 100 000 filas por página: responde con el tope.
- **Gerencia:** en Cotizaciones los botones están habilitados; el CSV sale con las 10 cotizaciones y el Excel de una fila marcada se genera bien. Las dos exportaciones quedaron en auditoría con su nombre, formato, filas y si era selección.

### Lo que no se probó

- **Contratos y Órdenes como Ventas:** sus listados están vacíos para esa cuenta y sin filas no hay botones de exportar. Usan la misma pieza y están en las pruebas automáticas.
- **Usuarios:** no se probó exportar ese listado en el navegador. *(La columna **Exportar** de Roles y permisos se vio después, como Administrador: aparece, y en Ventas sale sin marcar en Clientes, Cotizaciones y Leads.)*
- **Los filtros en la auditoría, en el navegador:** se añadieron después de la prueba con Gerencia (ahí salieron vacíos porque no había filtros puestos). Está en la prueba automática de la API.
- Claro, oscuro y 375 px: no cambió el diseño; el candado es el mismo de los demás botones.

## Pendiente

- Pantalla de **Auditoría** para consultar estas exportaciones (hoy se ven en la base).
- Si se quiere, quitarle a Gerencia las exportaciones que el prototipo no le da.
