# 2026-10-05 — Módulo Comercial: Servicios y Cotizaciones

## Qué se pidió

Empezar el módulo Comercial y añadir esta carpeta de documentación. De Comercial se eligió hacer **Servicios + Cotizaciones**: las dos pantallas que el menú ya enlazaba (`/services`, `/quotations`) y que tenían API pero no página.

## Estado del que se partió

`git status` limpio en `main` (último commit `5dbedb6`).

| Prototipo | En el ERP | Web antes | API antes |
| --- | --- | --- | --- |
| Clientes y actores | Clientes, Proveedores, Empleados | Hecho (estilo anterior) | Hecho |
| Catálogo de servicios | Servicios | No existía | Listar, ver, crear, activar |
| Cotizaciones | Cotizaciones | No existía | Listar, ver, crear, enviar, aprobar, rechazar |
| Contratos | Contratos (`soon`) | No existe | Listar, ver, generar desde cotización, activar |
| Órdenes | Órdenes (`soon`) | No existe | No existe |
| Solicitudes del portal | Solicitudes del portal (`soon`) | No existe | Solo del lado del portal |

## Qué se cambió

### Pantallas nuevas (web)

| Ruta | Pantalla | Qué hace |
| --- | --- | --- |
| `/services` | Catálogo de servicios | Buscador, filtros por categoría y estado, tabla, paginación, modal "Nuevo servicio" (con alta de categoría en el mismo formulario). |
| `/services/[id]` | Ficha del servicio | Ciclo de vida (Activar / Reactivar), configuración, historial de versiones de precio y modal "Nueva versión de precio". |
| `/quotations` | Cotizaciones | Indicadores, pestañas por estado con contador, buscador por número o cliente, tabla, paginación. |
| `/quotations/new` | Nueva cotización | Cabecera, líneas (cantidad y descuento %), totales en vivo, "Guardar borrador" y "Guardar y enviar a aprobación". |
| `/quotations/[id]` | Ficha de la cotización | Líneas y totales, estados, trazabilidad; Enviar a aprobación, Aprobar, Rechazar, Convertir en contrato. |

### Archivos web (`apps/web/src`)

| Archivo | Cambio |
| --- | --- |
| `components/ui/proto.tsx` | **Nuevo.** Piezas con las medidas del prototipo y los colores del logo: `PageHead`, `Card`, `Btn`, `Badge`, `Chip`, `Filters`, `SearchInput`, `FilterSelect`, `Tabs`, `Kpi`, `DataTable`, `Pager`, `Modal`, `Field`, `Kv`, `StateTrack`, `Notice`, `useToasts`. Las siguientes pantallas las reutilizan. |
| `components/dashboard/services/ServicesList.tsx`, `ServiceFormModal.tsx`, `ServiceDetail.tsx` | **Nuevos.** |
| `components/dashboard/quotations/QuotationsList.tsx`, `QuotationEditor.tsx`, `QuotationDetail.tsx` | **Nuevos.** |
| `app/(dashboard)/services/…`, `app/(dashboard)/quotations/…` | **Nuevos.** Cinco `page.tsx` que solo montan su componente. |
| `lib/commercial.ts` | **Nuevo.** Estados y sus etiquetas, formato de moneda y fecha, nombre del cliente, aviso entre pantallas. |
| `lib/access-context.tsx` | **Nuevo.** `useAccess()`: las pantallas leen los permisos que AppShell ya cargaba. |
| `types/commercial.ts` | **Nuevo.** Tipos de servicio, precio, categoría y cotización. |
| `components/layout/AppShell.tsx` | +2 líneas: envuelve el contenido en `AccessProvider`. No cambia el menú ni la carga de sesión. |
| `proxy.ts` | Añade `/services` y `/quotations` a las rutas del tenant (sin sesión → login; superadmin → su consola). |

### API (`apps/api`) — solo se añade, nada de lo que ya respondía cambia de forma

| Archivo | Cambio |
| --- | --- |
| `Modules/Services/Models/ServiceCategory.php`, `ServicePrice.php` | **Nuevos** modelos sobre tablas que ya existían (`service_categories`, `service_prices`). |
| `Modules/Services/Models/Service.php` | Relaciones `category`, `prices`, `currentPrice`. |
| `Modules/Services/Models/Quotation.php` | Relaciones `customer`, `creator`, `contract`. |
| `Modules/Services/Http/Controllers/ServiceController.php` | `index`: filtros `search` y `category_id`, orden por nombre, incluye categoría y precio vigente. `show`: incluye historial de precios. `store`: acepta `price` (crea la versión 1), y responde 422 si el código ya existe o la categoría no es válida (antes era un error 500 de base de datos). **Nuevo** `storePrice`. `activate` no se tocó. |
| `Modules/Services/Http/Controllers/ServiceCategoryController.php` | **Nuevo.** Listar y crear categorías. |
| `Modules/Services/Http/Controllers/QuotationController.php` | `index`: filtro `search`, orden por fecha, incluye cliente y responsable. `show`: incluye cliente, responsable y contrato. **Nuevo** `summary`. `store`, `submit`, `approve` y `reject` no se tocaron. |
| `Modules/Services/Http/Requests/StoreServiceRequest.php` | Regla para `price`. |
| `routes/api.php` | Rutas nuevas (abajo). |
| `tests/Feature/ComercialTest.php` | **Nuevo.** 6 tests. |

Rutas nuevas:

| Método | Ruta | Permiso |
| --- | --- | --- |
| GET | `/v1/service-categories` | `services.view` |
| POST | `/v1/service-categories` | `services.manage` |
| POST | `/v1/services/{service}/prices` | `services.manage` |
| GET | `/v1/quotations/summary` | `quotations.view` |

## Ajustes posteriores (mismo día)

- El ítem del menú "Servicios" ahora se llama **"Catálogo de servicios"**, como en el prototipo (`AppShell.tsx`, una línea). La cabecera y el buscador global toman el mismo nombre.
- Se quitaron las notas que aparecían encima del buscador en `/services` y `/quotations`, y la de "Nueva cotización".
- En la base local se activaron los módulos SERVICIOS y COTIZACIONES para "Tenant A - Prueba" (dos filas en `tenant_modules`), para que el menú deje de mostrarlos con la etiqueta "Plan". Ese tenant no tiene un plan asignado: sus módulos salen directamente de `tenant_modules`.

## Qué NO se tocó

- Clientes, Proveedores, Empleados y Planes SaaS: ni sus pantallas ni su API.
- Sidebar, cabecera, login. Del menú solo cambió el nombre de un ítem: "Servicios" y "Cotizaciones" ya apuntaban a estas rutas.
- La lógica de crear, enviar, aprobar y rechazar cotizaciones, de activar servicios y de generar contratos.
- `globals.css`.

## Diferencias con el prototipo

> Varias de estas diferencias se cerraron el mismo día: ver [2026-10-05_comercial-campos-y-funciones.md](2026-10-05_comercial-campos-y-funciones.md), que es la lista vigente.

| Prototipo | Aquí | Motivo |
| --- | --- | --- |
| Azul marino y naranja | Azul del logo `#1B8FC9` / `#1679AB`; cabecera del modal y botón primario en azul de marca | Regla de colores del logo. Verde, rojo y ámbar se mantienen solo como colores de estado. |
| Columnas "Modalidad" y "Garantía", filtro "Modalidad" | No están | La tabla `services` no guarda modalidad de facturación ni garantía. Requiere decidir el modelo y una migración. |
| Estado "Archivado"; acciones Inactivar / Archivar | No están | La base solo admite Borrador / Activo / Inactivo y la API solo tiene "activar". |
| Botón "Editar" (servicio y cotización) | No está | La API no tiene edición de servicios ni de cotizaciones. |
| "Sin precio no se puede activar" | Se quitó la frase | La API permite activar sin precio y no se cambió esa lógica. Un servicio sin precio sí queda marcado "no vendible" al cotizar. |
| Categoría obligatoria | Opcional, y se puede crear desde el mismo formulario | La API la admite vacía y no había pantalla para crear categorías. |
| Nota bajo el título de cada pantalla ("Categoría → Servicio → …", "Customer CORE → Cotización → …", "Cotización directa ERP…") | No están | Son textos de la demo; se quitaron a pedido. |
| Moneda PEN / USD con tipo de cambio | Solo soles (selector deshabilitado) | No hay tipo de cambio; mostrar en dólares los precios del catálogo sin convertir sería falso. |
| Filtro y columna "Origen" (Directa / CRM / Portal) | No están; la ficha dice "Cotización directa ERP" | Hoy todas las cotizaciones se crean desde el ERP. |
| Motivo obligatorio al aprobar o rechazar | Confirmación sin motivo | La API no guarda motivo; sí registra usuario y fecha en auditoría. |
| Autorización interna por niveles, segregación de funciones, "Nueva versión", "Orden directa", "Marcar vencida", "Cancelar" | No están | No existen en la API. |
| Tarjetas "Aprobaciones" e "Historial" | No están | La API no devuelve el historial de la cotización. |
| "Convertir en contrato" lleva a la ficha del contrato | Se queda en la cotización y muestra el número del contrato en Trazabilidad | La pantalla de Contratos todavía no existe. |
| "Exportar a Excel / CSV" en el pie de la tabla | No está | Pendiente. |
| En móvil la tabla se apila en tarjetas | La tabla se desplaza en horizontal | Pendiente. |
| "Customer CORE", códigos tipo "PROC-013", "S4-R-004", etiqueta "DOCUMENTADO" | Se quitaron | Son referencias internas de la documentación del prototipo. |
| Tema claro único | Funciona también en tema oscuro | El ERP ya tenía tema oscuro y no se quita. |

## Base de datos

**Ninguna migración.** Se usan tablas que ya existían, con sus permisos y su aislamiento por empresa (RLS) ya configurados.

## Cómo se probó

**Tests de la API:** `php artisan test` → 43 pasan (6 nuevos en `ComercialTest`: alta con categoría y precio, búsqueda y filtros, versión de precio, permisos, listado y resumen de cotizaciones, aislamiento entre empresas).

**Web:** `tsc --noEmit` sin errores; `eslint` sin errores.

**En el navegador** (`localhost:3000`, cuenta `admin@erp-saas.test`, empresa "Tenant A - Prueba"):

1. `/services` lista el servicio que ya existía; el buscador filtra.
2. "Nuevo servicio": se creó la categoría "Mantenimiento" desde el formulario y el servicio SRV-002 con precio S/ 350.00; aparece en la lista en Borrador.
3. Ficha del servicio: "Activar" y "Nueva versión de precio" (S/ 380.00); el historial muestra v2 vigente y v1 vencida.
4. `/quotations` muestra indicadores, pestañas con contador y la cotización que ya existía.
5. "Nueva cotización": guardar vacía muestra los dos errores; con cliente y una línea (2 × S/ 380, 10 % de descuento) los totales dan S/ 807.12, igual que lo que guarda la API.
6. "Guardar y enviar a aprobación" → ficha en estado Enviada → "Aprobar" → "Convertir en contrato" (queda CTR-2026-0002 en borrador y el botón se deshabilita).
7. Ancho de teléfono (375 px) sin desbordes; tema oscuro con fondos y textos correctos.
8. `/customers` sigue cargando igual. Se cerró la sesión al terminar.

**Datos de prueba que quedaron en "Tenant A - Prueba":** categoría "Mantenimiento", servicio SRV-002 "Mantenimiento preventivo" (activo, dos versiones de precio), cotización COT-2026-0002 (aprobada) y contrato CTR-2026-0002 (borrador).

## Pendiente

- Pantallas de Contratos, Órdenes y Solicitudes del portal.
- Todo lo marcado "no está" en la tabla de diferencias.
- El candado de módulo sigue siendo solo visual: a una pantalla fuera del plan se puede entrar escribiendo la ruta.
- Los selectores de la nueva cotización cargan hasta 200 clientes y 200 servicios; con más hará falta un selector con búsqueda.
