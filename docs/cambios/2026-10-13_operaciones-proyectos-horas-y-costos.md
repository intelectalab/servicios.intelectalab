# 2026-10-13 — Operaciones, segunda parte: Proyectos y Horas y costos

## Qué se pidió

"Continúa con el resto de Operaciones."

Referencias: kit (`docs/especificaciones/06-operaciones.md`, reglas RN-OPS-06, 07 y 08) y prototipo (`fuente/js/ui/views/operations.js`, `services/operations.js`, `core/states.js`, `core/rbac.js`).

Esta tanda cubre **Proyectos** (con sus tareas y entregables) y **Horas y costos**, que van juntos porque comparten los registros de horas y de costos. Quedan **Agenda** y **App del técnico** (siguen en "próximamente").

No se te consultó antes de empezar. Lo que se decidió sin preguntarte está en "Decisiones tomadas en esta tanda".

## Cómo funciona

### Proyectos (`/projects` y `/projects/{id}`)

- Un proyecto nace de un **servicio contratado activo**: de él toma cliente, contrato y sede. Su **ingreso atribuible** es lo pactado en ese servicio (precio × cantidad). Número propio (`PRY-2026-0001`).
- **Estados:** Borrador → Planificado → En ejecución ⇄ En pausa → Completado; se cancela desde Borrador, Planificado o En pausa. **Pausar y cancelar piden motivo.**
- **Cerrar el proyecto exige todas las tareas cerradas y todos los entregables aprobados.**
- **Listado** en tablero (por estado) y tabla, con avance, tareas, horas y margen de cada proyecto.
- **Ficha** con indicadores (Avance, Horas aprobadas, Costo real, Margen) y tarjetas movibles: Tareas, Horas del proyecto, Costos del proyecto, Entregables, Estados, Presupuesto vs real e Historial.
- **Tareas:** tablero Por hacer / En curso / Bloqueadas / Hechas. Se mueven **solo con el proyecto en ejecución**. Bloquear pide motivo. Una tarea puede llevar un **control obligatorio** ("Acta firmada"): sin marcarlo no queda hecha. El **avance** se pondera por las horas estimadas de cada tarea.
- **Entregables:** En preparación → En revisión → Aprobado → Entregado; en revisión se puede **devolver** con la observación. **Sin documento no pasa a revisión.**
- **Margen** = ingreso atribuible − costo real, y el costo real son **solo las horas y los costos aprobados**.

### Horas y costos (`/timesheets`)

- Cada persona **registra sus horas** en un proyecto (y, si quiere, en una de sus tareas) o en una orden de trabajo. Quedan **enviadas** y otra persona las **aprueba o las rechaza** (con motivo).
- **Las horas de una persona no se solapan** y un registro dura como mucho 12 horas. Unas horas rechazadas liberan su horario.
- **Nadie aprueba sus propias horas.** Es una regla fija, no depende de la configuración de la empresa.
- Las horas toman el **costo por hora** de la persona en el momento de registrarse. Ese costo se pone en **Usuarios → Editar usuario** (campo nuevo "Costo por hora").
- **Costos** directos del proyecto (proveedor, licencias, infraestructura, materiales, personal externo): cantidad × costo unitario. Se registran desde la ficha del proyecto y se aprueban o rechazan (con motivo).
- La pantalla tiene indicadores (Esta semana, Mis registros, Horas por aprobar, Costos por aprobar) y cinco pestañas: **Mis horas**, **Horas por aprobar**, **Costos por aprobar**, **Todas las horas** y **Todos los costos**. Cada una es un listado completo: buscar, filtrar por columna, ordenar, casillas, exportar y paginación, en el servidor.
- "Registrar horas" también está en la ficha del proyecto y en la **ficha de la orden de trabajo** (antes avisaba "aún no disponible"); las horas de la orden suman en su detalle.
- "Nuevo proyecto" en **Servicios contratados** ya funciona (antes avisaba).

### Permisos y alcance

| Permiso | Qué permite |
| --- | --- |
| `projects.view` / `.create` / `.export` | Ver / crear desde un servicio contratado activo / exportar |
| `projects.manage` | Editar el proyecto, planificarlo, iniciarlo, pausarlo, reanudarlo y cancelarlo |
| `projects.approve` | Cerrar el proyecto |
| `tasks.manage` | Crear, editar y mover tareas y marcar su control obligatorio |
| `deliverables.view` / `.manage` / `.approve` | Ver / crear, editar, enviar a revisión y entregar / aprobar o devolver |
| `time_entries.view` / `.create` / `.approve` / `.export` | Ver / registrar las propias / aprobar o rechazar las de otros / exportar |
| `cost_entries.view` / `.create` / `.approve` / `.export` | Ver / registrar / aprobar o rechazar / exportar |

Módulos del plan: Proyectos, tareas y entregables cuelgan de **Proyectos**; horas y costos, de **Timesheets**. Sin el módulo, la API responde 403.

**Reparto, comparado con la matriz del prototipo:**

| Rol | Proyectos | Tareas | Entregables | Horas | Costos |
| --- | --- | --- | --- | --- | --- |
| Administrador | todo | Gestionar | todo | todo | todo |
| Gerencia | Ver, Cerrar, Exportar | — | Ver, Revisar | Ver, Aprobar, Exportar | Ver, Aprobar, Exportar |
| Operaciones (prueba) | Ver, Crear, Gestionar, Cerrar | Gestionar | Ver, Gestionar, Revisar | Ver, Registrar, Aprobar | Ver, Registrar, Aprobar |
| Técnico (prueba) | Ver | Gestionar | Ver, Gestionar | Ver, Registrar | Ver, Registrar |
| Ventas | — | — | — | — | — |

Es el del prototipo, con **una diferencia a tu favor**: en el prototipo el Técnico tiene "editar proyectos", que también le deja **pausar, cancelar y cambiar el presupuesto** del proyecto. Lo separé: `tasks.manage` es trabajar las tareas y `projects.manage` queda para el proyecto en sí. El Técnico recibió solo el primero; quien ya gestionaba proyectos conserva las tareas.

**Alcance** (por rol, en Roles y permisos):

- **Proyectos:** "propio" es el que la persona lleva como responsable, el que creó y aquel donde **tiene una tarea asignada**.
- **Horas:** de quien las trabajó. **Costos:** de quien los registró. Con alcance Sede o Equipo, quien aprueba ve las de su sede o su equipo.
- Las horas y los costos nacen en la sede de su proyecto u orden.
- Los **totales del proyecto** (avance, horas, costo, margen) usan todo lo aprobado: quien ve el proyecto ve su margen, aunque no alcance cada registro. Las filas de horas y costos sí respetan el alcance.
- La ficha del proyecto entrega el contrato solo si quien mira lo alcanza, y los entregables solo con su permiso (si no, candado).
- Las horas se validan contra **todas** las de la persona, las vea o no quien registra.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Operations/Http/Controllers/ProjectController.php` (nuevo) | Listado con totales, opciones, ficha, alta, edición, estados, tareas y entregables. |
| `Operations/Http/Controllers/TimeEntryController.php` | Reescrito: listado, indicadores, opciones, registrar, aprobar y rechazar. |
| `Operations/Http/Controllers/CostEntryController.php` (nuevo) | Listado, registrar, aprobar y rechazar. |
| `Operations/Models/Project.php`, `CostEntry.php`, `Deliverable.php` (nuevos), `TimeEntry.php`, `Task.php` | Proyectos, horas y costos respetan el alcance del rol por sí solos. |
| `Operations/Support/Technicians.php` | Sirve también para saber quién puede llevar un proyecto o una tarea (quien tiene `projects.view`). |
| `Core/Http/Controllers/UserController.php` | Costo por hora de la persona en el alta, la edición y el listado de usuarios. |
| `Services/AccessScope.php`, `Middleware/CheckRecordVersion.php`, `Middleware/ListExport.php`, `Services/AuditCatalog.php` | Alcance, ediciones simultáneas, exportar y textos de auditoría de lo nuevo. |
| `routes/api.php`, `database/seeders/ModuleCatalogSeeder.php`, `LocalOperationsSeeder.php` | Rutas; permisos de cada módulo; la empresa de prueba recibe los módulos y los permisos nuevos. |
| `tests/Feature/OperationsProjectsTest.php` (nuevo) | Ocho pruebas. |
| `tests/Feature/Concerns/OperationsFixtures.php` (nuevo), `OperationsTest.php`, `AccessScopeTest.php` | Los datos de apoyo de las pruebas de Operaciones pasan a una pieza compartida; las listas de recursos con alcance incluyen los nuevos. Las comprobaciones son las mismas. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/operations/ProjectsList.tsx`, `ProjectDetail.tsx` (nuevos) | Listado (tablero y tabla) y ficha del proyecto. |
| `components/dashboard/operations/Timesheets.tsx` (nuevo) | La pantalla Horas y costos. |
| `components/dashboard/operations/Entries.tsx` (nuevo) | Tablas de horas y de costos con aprobar y rechazar; las usan la pantalla y la ficha del proyecto. |
| `components/dashboard/operations/ProjectModals.tsx` (nuevo) | Formularios: proyecto, tarea, entregable, registrar horas y registrar costo. |
| `components/dashboard/operations/ContractedServicesList.tsx`, `WorkOrderDetail.tsx` | "Nuevo proyecto" y "Registrar horas" ya funcionan. |
| `components/dashboard/admin/UsersList.tsx` | Campo "Costo por hora" en el formulario del usuario. |
| `app/(dashboard)/projects`, `projects/[id]`, `timesheets` (nuevos), `proxy.ts`, `components/layout/AppShell.tsx` | Rutas y menú. |
| `components/dashboard/admin/RolesPermissions.tsx`, `ScopeNote.tsx`, `lib/operations.ts`, `types/operations.ts`, `lib/api.ts` | Filas nuevas en la matriz, textos de alcance, estados y tipos. |
| `crm/LeadsList.tsx`, `PipelineList.tsx`, `ActivitiesList.tsx`, `CampaignsList.tsx`, `operations/WorkOrdersList.tsx` | Arreglo del cambio de vista (ver abajo). |

## Arreglo encontrado al probar

En los listados con tablero (Leads, Pipeline, Actividades, Campañas, Órdenes de trabajo y Proyectos), **pulsar la vista que ya estaba activa** (Tabla estando en Tabla) vaciaba la lista y mostraba "No hay registros con estos filtros", hasta recargar. Ahora ese clic no hace nada.

## Qué NO se tocó

- Tus tablas `projects`, `tasks`, `time_entries`, `cost_entries` y `deliverables` se conservaron: solo se les añadieron columnas. El registro de horas que ya tenías (3 h aprobadas en `OT-2026-0001`) sigue aprobado.
- Las rutas anteriores `/tasks`, `/resources` y `/checklists` siguen con su permiso `operations.*`.
- En Usuarios solo se añadió un campo; guardar sin tocarlo no cambia nada más.

## Decisiones tomadas en esta tanda (dime si alguna la quieres distinta)

1. **Trabajar tareas es un permiso aparte de gestionar el proyecto** (`tasks.manage`), para que quien ejecuta no pueda pausar ni cancelar el proyecto.
2. **Nadie aprueba sus propias horas, sin excepción.** En costos, quien lo registró no lo aprueba **salvo que la empresa desactive la segregación de funciones** (el mismo interruptor de cotizaciones y checklists).
3. **El costo por hora vive en la persona** (Usuarios) y las horas lo copian al registrarse: cambiarlo después no altera horas ya registradas. El prototipo usa un valor fijo.
4. **El documento del entregable es un enlace o referencia escrita**, porque Documentos aún no existe. La regla "sin documento no pasa a revisión" ya se cumple; cuando exista Documentos se cambia por el archivo.
5. **Las tareas se mueven solo con el proyecto en ejecución** y **bloquear una tarea pide motivo.** El prototipo no lo exige.
6. **Las horas y los costos no se editan ni se borran:** unas horas rechazadas se vuelven a registrar. El kit prevé editar y dar de baja; no se adoptó.
7. **Un costo siempre pertenece a un proyecto** (no a una orden de trabajo), como en el prototipo.
8. **Dos pestañas de más en Horas y costos** ("Todas las horas" y "Todos los costos"): sin ellas no había dónde ver ni exportar lo ya aprobado.
9. **La ficha del proyecto va en tarjetas movibles**, no en las pestañas del prototipo, como tus demás fichas.
10. **No se registran horas ni costos en un proyecto cerrado**, ni horas en una orden cancelada.
11. **Módulos:** proyectos en "Proyectos", horas y costos en "Timesheets". Los activé en la empresa de prueba.

## Diferencias con el prototipo (y con el kit)

- Sin las notas bajo el título de cada pantalla.
- El listado de proyectos tiene tablero y tabla (el prototipo, solo tarjetas).
- Tareas con edición, prioridad y motivo de bloqueo; las canceladas se listan bajo el tablero.
- Las horas nacen "Enviadas" (sin borrador), como en el prototipo; el kit tiene además "Borrador".
- No se adoptó del kit: `ops_project_members` (los "miembros" son quienes tienen una tarea), editar y borrar horas, el punto único de transiciones ni la bandeja de eventos.

## Base de datos

Dos migraciones, **ya corridas en la base local** con el usuario dueño. Solo añaden.

`2026_10_13_000100_operations_projects_hours_and_costs`:

- Activa la extensión `btree_gist` (hace falta para impedir horas solapadas).
- `projects`: servicio contratado de origen, ingreso, presupuesto de costo y de horas, motivos, quién lo creó, sede, versión. **La base rechaza una pausa o una cancelación sin motivo.**
- `tasks`: horas estimadas, control obligatorio, motivo de bloqueo. **La base no deja una tarea hecha con su control pendiente.**
- `time_entries`: estado, descripción, quién aprobó, motivo, sede, versión. **La base rechaza horas solapadas de una persona, sin proyecto ni orden, de más de 12 horas, un rechazo sin motivo y que alguien apruebe las suyas.**
- `cost_entries`: cantidad, costo unitario, estado, quién registró y quién aprobó. **La base rechaza un rechazo sin motivo y una cantidad en cero.**
- `deliverables`: documento, observación, revisión y entrega. **La base no admite un entregable en revisión sin documento ni devuelto sin observación.**
- `tenant_users.hourly_cost`.
- Dieciséis permisos, su reparto y el alcance de proyectos, horas y costos.

`2026_10_13_000200_separate_task_management_from_projects`: el permiso `tasks.manage`, dado a quien ya gestionaba proyectos.

`database/schema_reference.sql` no incluye estas migraciones.

## Cómo se probó

`php artisan test`: **152 pruebas pasan** (144 anteriores + 8 nuevas):

1. Proyecto: nace de un servicio activo, ingreso y sede heredados, responsable válido, estados, motivos, cerrado no se toca, auditoría; la base rechaza pausa y cancelación sin motivo.
2. Tareas: no se mueven sin proyecto en ejecución, transiciones, motivo al bloquear, control obligatorio, avance ponderado, hecha no cambia; la base no admite hecha sin su control.
3. Entregables: sin documento no pasa a revisión, devolver con observación, aprobar, entregar; cerrar exige tareas y entregables; permisos de gestionar y de revisar por separado; sin permiso de ver, la ficha no los entrega.
4. Horas: validaciones, no se solapan (ni entre proyecto y orden), la base tampoco lo admite, nadie aprueba las suyas (API y base), rechazar con motivo libera el horario, solo las aprobadas cuentan, la orden suma sus horas.
5. Costos: validaciones, quien registra no aprueba (y sí con la segregación desactivada), margen con lo aprobado, listado y exportar.
6. Listados de proyectos y de horas: búsqueda, filtros, siete órdenes, filtros por columna, indicadores, opciones, exportar; el técnico ve solo lo suyo.
7. **Alcance y permisos:** Propios, sin alcance definido, Sede; lo ajeno responde 404; el técnico trabaja tareas y no pausa, edita ni cancela el proyecto; Gerencia aprueba y no ejecuta; margen completo aunque no se alcancen los registros; 409; otra empresa; sin los módulos.
8. La matriz de roles ofrece los permisos y alcances nuevos, y **cada acción registrada tiene su texto** en `AuditCatalog`.

`tsc` y `eslint` sin errores (quedan los dos avisos anteriores de `lib/api.ts`).

En el navegador (`app.localhost:3000`):

- **Operaciones:** "Nuevo proyecto" desde un servicio contratado (nombre propuesto) → ficha. Dos tareas (título obligatorio; responsable, horas, control). Arrastrar con el proyecto sin iniciar no hace nada; tras iniciar: mover, "falta el control obligatorio", transición inválida. Registrar horas con tarea; horas solapadas; registrar costo (validaciones); aprobar su propio costo no pasa; sus propias horas, botones deshabilitados con el motivo. Entregable: crear, enviar deshabilitado sin documento, adjuntar, enviar, devolver con motivo, reenviar, aprobar, entregar. Pausar con motivo y reanudar. Cerrar con tareas abiertas no pasa. Listado en tablero y tabla, orden por Margen en el servidor, exportar con candado. Horas y costos: indicadores y pestañas. Al final: cancelar la tarea pendiente y cerrar el proyecto; registrar horas desde la ficha de una orden de trabajo (suman en su detalle).
- **Técnico:** ve el proyecto porque tiene una tarea; Editar, Pausar y Cerrar con candado; marca el control y termina su tarea con "Mover a…"; registra sus horas; ve solo sus horas y ningún costo ajeno; "Horas por aprobar" con candado; contrato "—".
- **Gerencia:** rechaza unas horas (sin motivo no pasa) y aprueba otras; aprueba el costo; exporta el CSV de horas; ve el margen del proyecto con lo aprobado (450 − 130 − 71 = 249, 55 %); todo lo de ejecutar con candado; cerrar con una tarea abierta no pasa.
- **Administrador:** matriz de Roles y permisos con las cinco filas nuevas; Usuarios: el campo Costo por hora muestra el valor, se guarda y se vuelve a leer.
- **Claro y oscuro:** contraste de todos los textos medido por código en el listado, la ficha del proyecto y Horas y costos, en los dos temas; capturas en claro de la ficha y de Horas y costos.
- **375 px:** las tres pantallas sin desborde horizontal (medido por código); captura de Horas y costos.

### Lo que no se probó

- **Capturas en oscuro y de la ficha a 375 px:** la ventana de pruebas se ocultaba y las capturas fallaban; se midió por código. Conviene que las mires una vez.
- **Arrastrar con el ratón de verdad** en los dos tableros: el arrastre se envió por código.
- **El Excel:** se probó el CSV; el Excel usa la misma pieza de los demás listados.
- **Alcance Equipo:** usa la misma regla de los demás recursos; Propios y Sede sí se probaron.
- **Mover e inmovilizar columnas** en las tablas nuevas: es la misma pieza.

### Datos de prueba que quedaron en "Tenant A - Prueba"

- Módulos **Proyectos** y **Timesheets** activos; costo por hora de 65 (Operaciones) y 45 (Técnico).
- Proyecto `PRY-2026-0001` (completado) con dos tareas, un entregable entregado, un costo aprobado y dos registros de horas (uno aprobado y uno rechazado).
- Un registro de horas de Operaciones en `OT-2026-0003`, por aprobar.

## Pendiente

- **Resto del módulo:** Agenda y App del técnico.
- **Plantillas de checklist:** sigue sin pantalla (propuesta en la tanda anterior).
- **Documentos:** el entregable guarda un enlace; falta adjuntar el archivo.
- **Facturar por horas** o por avance: llega con Finanzas.
- **Avisar** a quien aprueba que tiene horas o costos pendientes, y a quien registró que se los rechazaron: llega con Notificaciones.
- Las rutas anteriores `/tasks` (permiso `operations.*`, que solo tiene el Administrador) listan tareas sin mirar el alcance del proyecto. Propongo retirarlas o pasarlas al permiso nuevo.
- En la matriz de roles, las filas sin alcance propio (Tareas, Entregables) muestran "Toda la empresa" en la columna Alcance, aunque siguen el del proyecto. Ya pasaba con Campañas; conviene mostrar "—".
- Módulos del plan: "Servicios contratados" está en el menú bajo Órdenes de trabajo; una empresa con Proyectos pero sin Órdenes de trabajo la vería con candado aunque puede crear proyectos. Se ordena al hacer Módulos y plan.
