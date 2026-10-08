# 2026-10-08 — Alcance en los permisos (lo propio, equipo, sede, toda la empresa)

Era el punto 1 del orden acordado tras la comparación con el kit ([../comparacion-con-el-kit.md](../comparacion-con-el-kit.md), sección 3).

## Qué se pidió

Que un rol no solo diga **qué puede hacer** sino también **qué registros alcanza**. Tus tres respuestas:

1. Alcances: los cuatro — lo propio, equipo, sede y toda la empresa.
2. Dónde aplica: Cotizaciones, Contratos, Órdenes y Solicitudes del portal.
3. Reparto: Ventas solo lo suyo, Gerencia y Administrador todo; "guiándose de lo que tiene cada rol en el prototipo".

## Cómo funciona

| Alcance | Qué ve la persona |
| --- | --- |
| **Propios** | Lo que ella registró |
| **Equipo** | Lo propio y lo de las personas de su equipo |
| **Sede** | Lo propio y los documentos de su sede |
| **Toda la empresa** | Todo |

- Se elige **por rol y por recurso** en Roles y permisos, columna **Alcance** (como la matriz del prototipo).
- Un registro fuera de alcance **"no existe"**: no sale en el listado, ni en los indicadores, ni al exportar; su ficha responde "no encontrada" y ninguna acción sobre él funciona (404, igual que un registro de otra empresa).
- **Un contrato o una orden también es "propio" de quien hizo la cotización de origen.** Si Ventas cotiza y Gerencia genera el contrato, Ventas lo sigue viendo.
- **Solicitudes del portal:** una solicitud que nadie tomó la ve quien pueda atenderla. Quien la toma, la cotiza o la rechaza queda como su responsable; desde ahí la ven esa persona, su equipo o su sede según el alcance.
- **Sede de un documento:** nace con la sede de quien lo crea. El contrato y la orden heredan la de la cotización (siguen en la sede de la venta aunque los genere alguien de otra sede). Un documento **sin sede** no se abre a todas las sedes: lo ve quien lo creó (y quien alcanza toda la empresa).
- **Equipo sin asignar o sede sin asignar:** el alcance se queda en lo propio.
- **Rol nuevo:** nace alcanzando solo lo propio. Un rol sin alcance definido también. Ampliar es siempre una decisión explícita.
- **Administrador:** siempre toda la empresa; no se puede recortar ni desde la pantalla ni escribiendo en la tabla.
- Cambiar un alcance queda en la auditoría (`role.scopes.update`, con el antes y el después). Cambiar la sede o el equipo de un usuario también (`user.update`).

### Cómo quedaron tus roles en "Tenant A - Prueba"

| Rol | Cotizaciones | Contratos | Órdenes | Solicitudes |
| --- | --- | --- | --- | --- |
| Administrador | Toda la empresa | Toda la empresa | Toda la empresa | Toda la empresa |
| Gerencia | Toda la empresa | Toda la empresa | Toda la empresa | Toda la empresa |
| Ventas | **Propios** | **Propios** | **Propios** | Toda la empresa |

Es el reparto del prototipo. Además **Ventas recibió el permiso de ver Contratos y Órdenes** (en el prototipo los ve, solo los propios): por eso ahora le aparecen en el menú. Y, desde la segunda parte de esta tanda, el de **crearlos** (ver abajo).

Los roles de las demás empresas conservan lo que veían (toda la empresa).

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `app/Services/AccessScope.php` (nuevo) | Resuelve el alcance de la persona en cada recurso (el más amplio entre sus roles que dan permiso de ver) y arma el filtro. |
| `app/Models/Concerns/ScopedByAccess.php` (nuevo) | Lo aplica **solo** a cualquier consulta de los modelos `Quotation`, `Contract`, `Order` y `PortalRequest`: listados, fichas, conteos y relaciones. No depende de que cada controlador se acuerde de filtrar; lo nuevo que se construya sobre esos modelos queda cubierto. También pone la sede al crear. |
| `Quotation.php`, `Contract.php`, `Order.php`, `PortalRequest.php` | Usan ese filtro. La solicitud guarda quién la tomó. |
| `QuotationController`, `ContractController`, `OrderController`, `PortalRequestController` | Contrato, orden y nueva versión heredan la sede. "Ya tiene un contrato / una orden" se comprueba sin importar quién lo generó. Tomar, cotizar o rechazar una solicitud deja al responsable. |
| `RoleController.php` | La matriz devuelve y guarda el alcance por recurso. Rol nuevo: lo propio. |
| `UserController.php` | Equipo del usuario (la sede ya estaba). Auditoría con sede y equipo. |
| `OrgUnitController.php` (nuevo) | Sedes y equipos: listar, crear, renombrar, activar y desactivar. No se borran. |
| `AuthController.php` | `/me/access` incluye el alcance de la persona, para que la web lo muestre. |
| `routes/api.php` | Rutas `/org-units` (ver con `users.view`, gestionar con `users.manage`). |
| `PartyController.php` | **Fuga corregida:** la vista del cliente listaba todas sus cotizaciones y contratos a cualquiera con permiso de ver clientes. Ahora exige el permiso de ver cotizaciones / contratos y respeta el alcance. |
| `ServiceController.php` | **Fuga corregida:** la ficha del servicio listaba todas las cotizaciones que lo usan. Igual que arriba. |
| `CheckRecordVersion.php` | Un registro fuera de alcance ya no se delata con el aviso de "otra persona lo modificó": responde "no encontrado". |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/admin/RolesPermissions.tsx` | Columna **Alcance** con desplegable (Propios, Equipo, Sede, Toda la empresa) en Cotizaciones, Contratos, Órdenes y Solicitudes del portal; se guarda al instante. Deshabilitado si el rol no tiene "Ver" en ese recurso. En los demás recursos dice "Toda la empresa" (no se filtran). La fila "portal" ahora se llama "Solicitudes del portal". |
| `components/dashboard/admin/UsersList.tsx` | Pestañas **Usuarios · Sedes · Equipos**. Columna y campo **Equipo** (y Sede, que ya existía pero nunca se veía porque no había sedes). Rol, Sede y Equipo del formulario pasan al desplegable propio (`PickSelect`). Exportar incluye Equipo. |
| `components/dashboard/admin/OrgUnits.tsx` (nuevo) | Las pestañas Sedes y Equipos: tabla con nombre, usuarios y estado; Nueva / Editar / Desactivar, con candado sin `users.manage`. |
| `components/dashboard/ScopeNote.tsx` (nuevo) | Etiqueta "Alcance: Propios / Equipo / Sede" en la barra de filtros de los cuatro listados cuando la persona no ve toda la empresa, con su explicación al pasar el cursor. |
| `QuotationsList`, `ContractsList`, `OrdersList`, `PortalRequestsList` | Muestran esa etiqueta. |
| `lib/access-context.tsx`, `components/layout/AppShell.tsx` | El alcance de la sesión, disponible con `useAccess().scopeOf("quotations")`. |

## Qué NO se tocó

- Los demás recursos (Clientes y actores, Servicios, Tipo de cambio, Usuarios): quien tiene el permiso ve toda la empresa, como hasta hoy.
- Nada de lo que choca con el kit por decisión tuya. Los permisos siguen siendo Ver / Gestionar / Aprobar.
- La columna `user_roles.scope` (existía sin uso) sigue sin uso: el alcance vive en el rol, no en cada usuario.
- Tus módulos Clientes, Proveedores y Empleados.

## Diferencias con el prototipo

- **Dónde se gestionan sedes y equipos.** El prototipo los trae cargados y no tiene pantalla para crearlos. Los puse como pestañas dentro de Usuarios; cuando exista Configuración se pueden mover ahí.
- **Documento sin sede.** En el prototipo lo ve cualquiera con alcance "Sede". Aquí solo quien lo creó: es lo más protegido. Todos los documentos anteriores a hoy están sin sede.
- **Una sede y un equipo por persona** (el prototipo admite varias sedes en los datos, pero su formulario también pide una sola).
- **Acciones de la matriz.** El prototipo tiene seis (Ver, Crear, Editar, Eliminar, Aprobar, Exportar). Aquí son Ver, Gestionar y Aprobar, más **Crear** solo en Contratos y Órdenes (segunda parte).
- **Alcance solo en cuatro recursos**, no en todos (lo que respondiste).
- "Todo el tenant" del prototipo se llama aquí "Toda la empresa".

## Base de datos

Migración `2026_10_08_000200_add_access_scope_to_roles`. **Ya está corrida en la base local**, con el usuario dueño. Solo añade:

- Tabla `role_scopes` (alcance por rol y recurso) y tabla `teams`, las dos con su regla de aislamiento por empresa.
- `tenant_users.team_id`; `branch_id` en `quotations`, `contracts`, `orders` y `portal_requests`; `portal_requests.taken_by`.
- Datos: alcance "toda la empresa" para los roles existentes; Ventas a "propios" en cotizaciones, contratos y órdenes, más sus permisos de ver contratos y órdenes; y quién tomó cada solicitud ya atendida, sacado de la auditoría.

## Cómo se probó

`php artisan test`: **82 pruebas pasan** (73 anteriores + 9 nuevas en `tests/Feature/AccessScopeTest.php`):

1. Propios: cada vendedor ve lo suyo; lo ajeno responde 404 en la ficha y en cada acción (enviar, cancelar, editar, nueva versión), no cambia nada y no se delata con el aviso de conflicto; los indicadores cuentan solo lo alcanzado; Gerencia y Administrador ven todo.
2. Un rol sin alcance definido ve solo lo propio.
3. Equipo: ve lo de su equipo y actúa sobre ello; no lo de otro equipo; sin equipo, solo lo propio.
4. Sede: el documento nace con la sede de quien lo crea; se ve lo de la sede; sin sede, solo lo propio; al cambiar de sede cambia lo que se alcanza.
5. Contrato y orden generados por Gerencia: quien vendió los ve, su compañero no; heredan la sede; con permiso de gestionar pero sin alcance no se puede actuar sobre ellos.
6. Solicitudes: libres para todos; tomada por una persona, la otra ya no la ve ni puede rechazarla o cotizarla; queda el responsable.
7. Vista del cliente y ficha del servicio: solo las cotizaciones alcanzadas, y ninguna sin el permiso de ver cotizaciones.
8. Matriz: el alcance se guarda por rol, surte efecto de inmediato y queda auditado; valores inválidos se rechazan; el Administrador no se recorta; sin `users.manage` no se cambia; rol de otra empresa no existe.
9. Sedes y equipos: alta, nombre repetido, renombrar, desactivar, asignar a un usuario (auditado), permisos, y aislamiento entre empresas.

En `ComercialTest` los roles de prueba recibieron alcance de toda la empresa (son pruebas de los flujos, donde una persona aprueba lo de otra); no se cambió ninguna comprobación.

En el navegador (`app.localhost:3000`):

- Como **Ventas**: Cotizaciones muestra solo COT-2026-0009 (la suya), indicadores en 1 y la etiqueta "Alcance: Propios"; Contratos y Órdenes aparecen en el menú y salen vacíos; la ficha de una cotización ajena por su dirección dice "Cotización no encontrada"; Solicitudes muestra las tres.
- Como **Administrador**: columna Alcance en los tres roles; cambié Cotizaciones de Ventas a Equipo (aviso "Alcance actualizado", guardado en la base y en la auditoría) y lo devolví a Propios. En Usuarios: pestañas, alta de una sede y de un equipo, rechazo del nombre repetido, y asignación de ambos a Lucía Paredes desde el formulario.
- A 375 px: Roles y permisos (la matriz en tarjetas, con el desplegable de alcance dentro) y Usuarios → Sedes, sin desborde horizontal.

**Datos de prueba que quedaron en "Tenant A - Prueba":** la sede "Sede principal" y el equipo "Equipo comercial", asignados a Lucía Paredes (ventas). No cambian lo que ve (su alcance es Propios). Se pueden renombrar o desactivar.

### Lo que no se probó

- En el navegador con la cuenta de **Gerencia** (no tengo su contraseña vigente); su caso está en las pruebas automáticas.
- En el navegador los alcances **Equipo y Sede con dos personas reales** (solo hay un usuario Ventas); están en las pruebas automáticas.
- A 375 px: los listados con la etiqueta de alcance y el formulario de usuario (el panel del navegador dejó de dibujar a ratos; medí el desborde por código, no con captura).
- Tomar / rechazar una solicitud y generar contrato / orden desde la pantalla después del cambio (cubierto por las pruebas automáticas; las pantallas no cambiaron).
- El tema claro de las pantallas nuevas.

## Pendiente

- **Documentos anteriores sin sede:** si vas a usar el alcance "Sede", hay que decidir a qué sede pertenecen los documentos que ya existen.
- La ficha de cotización, contrato y orden no muestra la sede del documento ni permite cambiarla.
- Si un rol que aprueba tiene alcance "Propios", no podrá aprobar nada con la segregación activa (quien crea no aprueba). La pantalla no lo avisa.
- Con alcances distintos por recurso (p. ej. Órdenes "Toda la empresa" y Cotizaciones "Propios"), en el listado de órdenes el número de la cotización de origen sale vacío cuando la cotización no se alcanza.
- Los roles base del prototipo (Operaciones, Técnico, Finanzas, Soporte) no se crean solos en una empresa nueva: hoy nace solo con Administrador.
- Buscador global, panel de control y reportes: cuando incluyan cotizaciones, contratos u órdenes, heredan el filtro solos si consultan por los modelos.

## Segunda parte (2026-10-08): "Crear" aparte de "Gestionar" y vista del cliente cerrada

Las dos cosas que aprobaste al revisar la primera parte.

### Ventas crea el contrato y la orden de su venta

- Permisos nuevos **Crear contratos** (`contracts.create`) y **Crear órdenes** (`orders.create`). En Roles y permisos aparece la columna **Crear** en esas dos filas.
- "Convertir en contrato", "Orden directa" (desde la cotización aprobada) y "Crear orden" (desde el contrato vigente) piden ahora el permiso de crear. Editar, suspender, reactivar, terminar y las transiciones de la orden siguen pidiendo Gestionar; activar el contrato, Aprobar.
- **Nadie perdió nada:** todo rol que podía gestionar recibió también crear. Ventas recibió los dos de crear.
- Con su alcance "Propios", Ventas solo puede convertir **sus** cotizaciones: la de otra persona no existe para ella.

| Archivo | Cambio |
| --- | --- |
| Migración `2026_10_08_000300_add_create_permission_to_contracts_and_orders` | Los dos permisos y su reparto. **Ya corrida en la base local.** Solo añade |
| `routes/api.php` | Las tres rutas de conversión piden `contracts.create` / `orders.create` |
| `QuotationDetail.tsx`, `ContractDetail.tsx` | Los tres botones llevan el permiso nuevo (con su candado si falta) |
| `RolesPermissions.tsx` | Columna "Crear" |

### Vista del cliente: cada pestaña pide el permiso de su módulo

Antes, quien podía ver clientes veía también sus comprobantes, saldo, tickets, órdenes de trabajo, leads y oportunidades. Ahora la API solo los entrega con el permiso de ver de cada módulo **y** si la empresa tiene ese módulo:

| Parte de la ficha | Permiso |
| --- | --- |
| Cotizaciones / Contratos | `quotations.view` / `contracts.view` (y el alcance) |
| Órdenes de trabajo | `operations.view` |
| Comprobantes y "Situación financiera" | `billing.view` |
| Tickets | `support.view` |
| Leads y oportunidades | `crm.view` |

En la pantalla la pestaña **no se esconde**: muestra un candado con "Tu rol no tiene permiso …", como los botones. La tarjeta "Situación financiera" del resumen sí desaparece sin el permiso.

| Archivo | Cambio |
| --- | --- |
| `CheckPermission.php` | La regla "permiso + módulo de la empresa" queda disponible para usarla dentro de una pantalla (`CheckPermission::granted`) |
| `PartyController.php`, `ServiceController.php` | La usan para cada parte |
| `ActorDetail.tsx` | Aviso con candado en la pestaña sin permiso |

### Cómo se probó

`php artisan test`: **84 pruebas pasan** (dos nuevas):

1. Ventas genera el contrato y la orden de su cotización aprobada; no puede editarlos, terminarlos, activarlos ni confirmar la orden (403); no convierte la cotización de otra persona (404); un rol que gestiona pero no crea no puede generarlos (403).
2. Vista del cliente: sin los permisos no llegan tickets, leads, comprobantes ni saldo; con el permiso de Soporte llegan los tickets; el permiso de CRM no vale hasta que la empresa tiene el módulo.

En las pruebas existentes, los roles que gestionaban contratos y órdenes recibieron también el permiso de crear (lo mismo que hace la migración).

En el navegador: como **Ventas**, la ficha de "Empresa AAA" muestra su cotización en Comercial y el candado con su mensaje en Operaciones, Finanzas, Soporte y CRM. Como **Administrador**, la matriz de Ventas muestra la columna Crear marcada en Contratos y Órdenes y Gestionar sin marcar; la cotización aprobada conserva sus botones de conversión.

**No se probó en el navegador:** que Ventas convierta de verdad una cotización suya (no tiene ninguna aprobada; está en las pruebas automáticas), ni estas pantallas a 375 px tras el cambio.

## Ajuste aparte (2026-10-08): altura de la tabla de Clientes y actores

La tabla de Clientes y actores tenía una altura mínima de 220 px que las demás no tienen. Venía de cuando los menús de las columnas se dibujaban dentro de la tabla y se cortaban con pocas filas; hoy se dibujan por encima de la página, así que el mínimo solo dejaba un hueco vacío. Se quitó (`ActorsList.tsx`): las seis tablas de listado quedan iguales, con tope en el alto de la ventana menos 290 px y sin mínimo.

Probado en el navegador como Ventas, con el listado filtrado a una sola fila (la tabla mide 133 px): el menú de cada una de las cinco columnas y el de "Gestionar columnas" se abren completos, fuera de la tabla. Como Administrador, también con una sola fila: al editar en celda, el desplegable de Estado (Activo / Inactivo) y el de Tipo de documento de una persona (DNI / CE / Pasaporte) se abren completos fuera de la tabla; se cancelaron sin guardar. No se probó a 375 px (ahí la tabla no tiene tope).
