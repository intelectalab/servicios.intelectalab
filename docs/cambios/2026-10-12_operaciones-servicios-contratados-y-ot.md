# 2026-10-12 — Operaciones, primera parte: Servicios contratados y Órdenes de trabajo

## Qué se pidió

"Módulo Operaciones. Especificación: docs/especificaciones/ del kit."

Referencias: kit (`docs/especificaciones/06-operaciones.md`, máquinas de estado y reglas RN-OPS-01 a 05 y 10) y prototipo (`fuente/js/ui/views/operations.js`, `services/operations.js`, `core/rbac.js`).

El módulo tiene siete pantallas. Esta tanda cubre la **fase 1 del kit**: Servicios contratados, Órdenes de trabajo (tablero y tabla) y la ficha de la orden con su checklist. Proyectos, Horas y costos, Agenda y App del técnico quedan para las siguientes (siguen en "próximamente" en el menú).

No se te consultó antes de empezar: se siguieron tus decisiones vigentes. Lo que se decidió sin preguntarte está en "Decisiones tomadas en esta tanda".

## Cómo funciona

### Servicios contratados (`/contracted-services`)

- Cada línea de un contrato activado es un **servicio contratado**: lo que hay que prestarle al cliente. Nace **Pendiente** al activar el contrato (eso ya existía).
- **Estados:** Pendiente → Activo ⇄ Suspendido → Completado; se cancela desde Pendiente, Activo o Suspendido. **Suspender y cancelar piden motivo.**
- **Activar o reactivar exige que el contrato esté vigente.** **Completar o cancelar no se permite con órdenes de trabajo abiertas.**
- Al activar un servicio **recurrente** se crea su **instancia recurrente** (una sola vez) con la frecuencia del servicio; se suspende, vuelve y se cancela junto con él. Es lo que ya mostraba la tarjeta "Instancias recurrentes" de la ficha del contrato.
- Listado con lo de los demás: buscar, filtro Estado, filtrar por columna, ordenar, casillas, exportar y paginación, todo en el servidor.
- **Clic en la fila** abre la ficha del servicio sobre el listado: estados y sus botones, datos, instancia recurrente, sus órdenes de trabajo e historial.
- En cada fila activa: **Nueva OT** (abre el formulario con el servicio ya elegido) y **Nuevo proyecto** (a la vista; avisa que llegará con Proyectos).

### Órdenes de trabajo (`/work-orders` y `/work-orders/{id}`)

- Una orden nace de un **servicio contratado activo**: de él toma cliente, contrato, servicio y sede. Número propio (`OT-2026-0001`).
- **Estados:** Planificada → Asignada → En ejecución ⇄ Bloqueada → Completada; se cancela desde Planificada, Asignada o Bloqueada. **Bloquear y cancelar piden motivo.**
- **Asignar** pide técnico y valida su agenda: **no se le asigna otra orden que se cruce** con una que ya tiene asignada o en ejecución (una orden sin hora de fin ocupa dos horas). Mover la fecha de una orden asignada valida lo mismo.
- **Técnicos:** las personas con acceso a la empresa cuyo rol puede ejecutar órdenes (`work_orders.manage`). No hay un rol "técnico" fijo: lo decide tu matriz de permisos.
- **Checklist:** al crear la orden se elige una plantilla y sus ítems se **copian** a la orden (cambiar la plantilla después no altera las órdenes ya creadas). Se marca mientras la orden está asignada, en ejecución o bloqueada; cada ítem guarda quién lo marcó, cuándo y una nota de evidencia.
- **Completar la orden exige los ítems obligatorios marcados.**
- Con los obligatorios marcados el checklist queda **Completado** y quien supervisa puede **Aprobarlo** o **Devolverlo** (con motivo; vuelve a "En curso"). Aprobado, ya no cambia.
- **Tablero** (Planificadas, Asignadas, En ejecución, Bloqueadas, Completadas; las canceladas se ven en la tabla) y **tabla**. Arrastrar una tarjeta cambia el estado; soltar una planificada en "Asignadas" abre el formulario de asignar; una transición inválida avisa y no hace nada. Cada tarjeta trae "Mover a…" para teléfono.
- **Ficha** con tarjetas movibles: Estados, Checklist, Evidencias, Detalle e Historial. Botones: Informe PDF, Asignar técnico / Reasignar, Editar y Registrar horas.
- **Informe PDF** de servicio: datos, checklist con evidencias, ejecución y firmas (conformidad del cliente y técnico).

### Permisos y alcance

| Permiso | Qué permite |
| --- | --- |
| `contracted_services.view` / `.manage` / `.export` | Ver / activar, suspender, reactivar, completar y cancelar / exportar |
| `work_orders.view` | Ver órdenes de trabajo, su checklist y su historial |
| `work_orders.create` | Crear una orden desde un servicio contratado activo |
| `work_orders.manage` | Editar, iniciar, bloquear, completar y cancelar, y marcar el checklist |
| `work_orders.approve` | Asignar o reasignar el técnico y la fecha; aprobar o devolver el checklist |
| `work_orders.export` | Exportar el listado |

Todos pertenecen al módulo **Órdenes de trabajo** del plan: sin él, la API responde 403.

**Reparto, comparado con la matriz del prototipo:**

| Rol | Servicios contratados | Órdenes de trabajo |
| --- | --- | --- |
| Administrador | Ver, Gestionar, Exportar | Ver, Crear, Gestionar, Aprobar, Exportar |
| Gerencia | Ver, Gestionar (toda la empresa) | Ver, Aprobar, Exportar (toda la empresa) |
| Ventas | Ver (**Propios**) | — |
| Operaciones (nuevo, de prueba) | Ver, Gestionar (**Sede**) | Ver, Crear, Gestionar, Aprobar (**Sede**) |
| Técnico (nuevo, de prueba) | — | Ver, Gestionar (**Propios**) |

Es el del prototipo. Dos diferencias, las dos hacia menos:

- **Técnico no recibió Clientes y actores.** En el prototipo ve "sus" clientes; aquí Clientes y actores no lleva alcance, así que el permiso le abriría el directorio entero.
- **Operaciones tampoco recibió** Documentos, Vault, Tickets, Compras ni Reportes: esas pantallas aún no existen.

**Alcance** (se elige por rol en Roles y permisos, como en los demás):

- **Órdenes de trabajo:** "propio" es la orden que la persona tiene **asignada** o que **creó**. El técnico ve solo las suyas (RN-OPS-10).
- **Servicios contratados:** "propio" es el del responsable y el de quien **vendió su contrato** (misma regla que Contratos): Ventas ve en qué quedó lo que vendió.
- El servicio contratado hereda responsable y sede de su contrato; la orden nace en la sede de su servicio (si no tiene, en la de quien la crea).
- La ficha de una orden entrega el contrato y el servicio contratado **solo si quien mira los alcanza**; el contador de OT de cada servicio cuenta solo las que la persona alcanza; la vista del cliente (pestaña Operaciones) ahora también respeta el alcance.
- El aviso de choque de agenda dice el número de la otra orden solo si quien asigna la alcanza.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Operations/Http/Controllers/ContractedServiceController.php` (nuevo) | Listado (búsqueda, filtros, orden y filtros por columna en la base), ficha y cambios de estado con sus reglas y la instancia recurrente. |
| `Operations/Http/Controllers/WorkOrderController.php` | Reescrito: listado, opciones del formulario, ficha, alta, edición, asignar, iniciar, bloquear, desbloquear, completar, cancelar, checklist (marcar, aprobar, devolver). Conserva la regla que ya tenía (no completar con ítems obligatorios pendientes en los checklists de sus tareas). |
| `Operations/Models/ContractedService.php`, `WorkOrderChecklistItem.php` (nuevos), `WorkOrder.php` | Los dos documentos respetan el alcance del rol por sí solos (`ScopedByAccess`). |
| `Operations/Support/Technicians.php` (nuevo) | A quién se puede asignar una orden. |
| `Services/Http/Controllers/ContractController.php` | Al generar los servicios contratados les pone su línea de origen, responsable y sede. |
| `Core/Http/Controllers/PartyController.php` | La vista del cliente pide las órdenes de trabajo por su modelo (alcance) y con `work_orders.view`. |
| `Services/AccessScope.php`, `Middleware/CheckRecordVersion.php`, `Middleware/ListExport.php` | Los dos recursos entran al alcance por rol, al control de ediciones simultáneas y al permiso de exportar. |
| `Services/AuditCatalog.php` | Textos de la entidad y las acciones nuevas para la pantalla Auditoría. |
| `routes/api.php`, `database/seeders/ModuleCatalogSeeder.php` | Rutas nuevas; el módulo Órdenes de trabajo trae los permisos nuevos. |
| `database/seeders/LocalOperationsSeeder.php` (nuevo, solo local) | Activa el módulo en la empresa de prueba y crea los roles Operaciones y Técnico con una cuenta cada uno. |
| `tests/Feature/OperationsTest.php` (nuevo) | Trece pruebas. |
| `tests/Feature/AccessScopeTest.php` | Tres listas de recursos con alcance incluyen ahora los dos nuevos. Las comprobaciones son las mismas. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/operations/ContractedServicesList.tsx` (nuevo) | Listado y ficha (ventana) de servicios contratados. |
| `components/dashboard/operations/WorkOrdersList.tsx` (nuevo) | Tablero y tabla de órdenes de trabajo. |
| `components/dashboard/operations/WorkOrderDetail.tsx` (nuevo) | Ficha de la orden. |
| `components/dashboard/operations/WorkOrderModals.tsx` (nuevo) | Formularios: nueva orden / editar y asignar técnico. |
| `lib/operations.ts`, `types/operations.ts` (nuevos) | Estados, textos y el informe PDF. |
| `app/(dashboard)/contracted-services`, `work-orders`, `work-orders/[id]` (nuevos), `proxy.ts` | Las rutas. |
| `components/layout/AppShell.tsx` | Las dos pantallas dejan de estar en "próximamente". **Y un arreglo:** quien no puede abrir la pantalla de inicio (Clientes y actores) va a la primera pantalla que su rol sí abre. |
| `components/dashboard/admin/RolesPermissions.tsx`, `ScopeNote.tsx` | Filas Servicios contratados y Órdenes de trabajo en la matriz; texto del alcance de cada una. |
| `components/dashboard/actors/ActorDetail.tsx` | Pestaña Operaciones de la vista del cliente: pide `work_orders.view`, muestra el estado con su texto (antes salía "ASSIGNED") y la fila abre la orden. |

## Qué NO se tocó

- Tus tablas `contracted_services` y `work_orders` se conservaron: solo se les añadieron columnas. La orden `OT-2026-0001` que ya tenías sigue ahí (tomó su descripción como título).
- Las rutas anteriores de tareas, horas, recursos y plantillas de checklist (`/tasks`, `/time-entries`, `/resources`, `/checklists`) siguen igual, con su permiso `operations.*`.
- `Operations/Http/Requests/StoreWorkOrderRequest.php` queda en el repo sin uso (el alta ya no parte de un cliente suelto, sino de un servicio contratado).
- Contratos: su ficha y sus estados no cambian.

## Arreglo encontrado al probar

Al entrar como Técnico, el sistema lo dejaba en Clientes y actores con "No se pudo cargar el listado": la pantalla de inicio es esa para todos y su rol no la abre. Pasaría con cualquier rol sin ese permiso. Ahora, en ese caso, el sistema lleva a la primera pantalla del menú que el rol sí puede abrir (o a Mi perfil).

## Decisiones tomadas en esta tanda (dime si alguna la quieres distinta)

1. **Solo la fase 1.** Proyectos, Horas y costos, Agenda y App del técnico van después, en ese orden (el del kit).
2. **Permisos por recurso** (`contracted_services.*`, `work_orders.*`), como en CRM y Comercial; el `operations.*` anterior se queda para lo que aún no tiene pantalla. Ambos recursos cuelgan del módulo **Órdenes de trabajo** del plan.
3. **Asignar y revisar el checklist es un permiso aparte de ejecutar** (`work_orders.approve`), como en el prototipo: el técnico ejecuta, no se reasigna ni se aprueba.
4. **Quien ejecuta la orden no aprueba su propio checklist**, salvo que la empresa tenga desactivada la segregación de funciones (el mismo interruptor de las cotizaciones, en Roles y permisos). No está en el prototipo.
5. **Una orden nace siempre de un servicio contratado activo.** El prototipo también admite tickets; llegará con Soporte. La API anterior dejaba crearla con un cliente suelto: ya no.
6. **El choque de agenda bloquea** (no avisa y deja pasar), como en el prototipo.
7. **Activar un servicio exige contrato vigente** y **cerrarlo exige no tener órdenes abiertas.** No están en el prototipo; evitan trabajo sin contrato y servicios cerrados con trabajo en curso.
8. **El checklist de la orden es una copia** de la plantilla, no un enlace.
9. **Prioridades Alta, Media y Baja**, como el prototipo (el kit añade Crítica; no se adoptó).
10. **Editar la orden** (título, prioridad, fechas, lugar, descripción) mientras está abierta. El prototipo no tiene edición.
11. **La ficha del servicio contratado es una ventana sobre el listado** (el prototipo no tiene ficha): así el clic en la fila abre algo y se ve su historial.
12. **Servicios contratados sin tablero y sin indicadores**, y Órdenes de trabajo sin indicadores: el prototipo tampoco los tiene.
13. **En la empresa de prueba** activé el módulo Órdenes de trabajo y creé los roles Operaciones y Técnico con sus cuentas (ver abajo).

## Diferencias con el prototipo (y con el kit)

- Sin las notas bajo el título de cada pantalla.
- Rutas en inglés; permisos Ver / Crear / Gestionar / Aprobar / Exportar.
- La columna "Snapshot" se llama **"Precio pactado"**.
- Tabla con casillas, exportar, columna OT (cuántas tiene) y columna Checklist; el prototipo no las trae.
- En el listado de servicios, Suspender y Cancelar están en la ficha (en el prototipo no aparecen en ningún lado).
- "Evento facturable: Ninguno", "Subir evidencia", "Registrar horas" y "Nuevo proyecto" están a la vista y avisan que aún no están disponibles: dependen de Finanzas, Documentos, Horas y Proyectos.
- Crear una orden **no crea un evento de agenda** (el prototipo sí): llegará con Agenda. El choque se valida contra las otras órdenes del técnico.
- No se adoptó del kit: tablas con prefijo `ops_`, el punto único de transiciones, la bandeja de eventos (`work_order.completed`), `configuration_snapshot` en JSON (se usan tus columnas `quantity`, `price` y `recurring`).

## Base de datos

Una migración, **ya corrida en la base local** con el usuario dueño. Solo añade.

`2026_10_12_000100_operations_contracted_services_and_work_orders`:

- `contracted_services`: línea de origen, responsable, sede, motivo, versión del registro. **La base rechaza un estado desconocido y un suspendido o cancelado sin motivo.**
- `recurring_service_instances`: **una sola instancia por servicio contratado.**
- `work_orders`: título, contrato, servicio, lugar, motivos, inicio y fin reales, quién la creó, sede, checklist, versión. **La base rechaza una orden asignada o en ejecución sin técnico, una bloqueada o cancelada sin motivo, un estado o prioridad desconocidos y un fin anterior al inicio.**
- `work_order_checklist_items` (nueva, con su regla de aislamiento por empresa): **la aplicación no puede borrar ítems.**
- Los ocho permisos, su reparto y el alcance.

`database/schema_reference.sql` no incluye esta migración (igual que las anteriores).

## Cómo se probó

`php artisan test`: **144 pruebas pasan** (131 anteriores + 13 nuevas):

1. Servicio contratado: estados en orden, motivos, no se cierra con órdenes abiertas, auditoría.
2. Activar y reactivar exigen contrato vigente.
3. Instancia recurrente: nace una vez, sigue al servicio; la base no admite una segunda, ni un suspendido sin motivo, ni un estado desconocido.
4. Listado de servicios: datos de la fila, búsqueda (servicio, cliente, contrato), filtro, orden, filtros por columna, ficha, tope de filas.
5. Servicios: alcance Propios de Ventas (lo ajeno responde 404, también al intentar cambiarlo), permisos, exportar, ediciones simultáneas (409), otra empresa, sin el módulo.
6. Alta de la orden: validaciones, solo desde servicio activo y alcanzable, copia del checklist (inalterable al cambiar la plantilla), numeración, sede heredada, nace asignada si lleva técnico, técnico válido, opciones del formulario.
7. Estados de la orden de punta a punta, checklist obligatorio para completar, cerrada no se toca, cancelar con motivo, auditoría, y que **cada acción registrada tenga su texto** en `AuditCatalog`.
8. La base rechaza una orden inconsistente (ocho casos) y que la aplicación borre ítems.
9. Agenda del técnico: choque al asignar, al crear y al mover la fecha; otro técnico u otra hora, sí; una orden cancelada ya no ocupa; el número de la otra orden solo si se alcanza.
10. Checklist: aprobar y devolver, quien ejecuta no aprueba (y sí con la segregación desactivada), aprobado no cambia.
11. Listado de órdenes: búsqueda, filtros (estado, prioridad, técnico, sin asignar), seis órdenes, filtros por columna, exportar y su auditoría; Operaciones no exporta.
12. **Alcance:** el técnico ve solo las suyas (lo ajeno, 404) y no recibe contrato ni servicio contratado; rol sin alcance = lo propio; alcance Sede; no se crea una orden sobre un servicio que no se alcanza; Gerencia asigna pero no ejecuta; asignar al crear pide su permiso; vista del cliente dentro del alcance; 409; otra empresa.
13. La matriz de roles ofrece los ocho permisos y los dos alcances.

`tsc` y `eslint` sin errores (quedan dos avisos anteriores en `lib/api.ts`).

En el navegador (`app.localhost:3000`). Los datos se crearon por el **flujo real**: Ventas cotizó, Gerencia aprobó, Ventas generó el contrato y Gerencia lo activó; de ahí salieron dos servicios contratados en la Sede principal.

- **Operaciones** (alcance Sede): ve los dos servicios de su sede y no el que no tiene sede; activó los dos (el recurrente creó su instancia); ficha del servicio; suspender (sin motivo no pasa) y reactivar; "Nuevo proyecto" avisa; Nueva OT desde la fila (título obligatorio, técnico, plantilla, lugar) → ficha de la orden. Editar. Tablero: transición inválida, soltar en "Asignadas" abre asignar, choque de agenda al asignar y al crear, asignar a otra hora. Tabla: orden por Programada y Prioridad, filtro Estado, filtro por columna en OT, la columna Prioridad abre el filtro de arriba, exportar con candado.
- **Técnico** (alcance Propios): solo "Órdenes de trabajo" en Operaciones; Nueva OT, Reasignar, Aprobar checklist, Devolver y exportar con candado; contrato "—". Inició, intentó completar (aviso con el ítem pendiente), bloqueó con motivo, desbloqueó, marcó un ítem, puso una nota de evidencia, completó. **Informe PDF** generado y revisado.
- **Gerencia:** ve las tres órdenes de la empresa; exportó CSV y Excel; aprobó el checklist; Editar e Iniciar con candado; Reasignar habilitado. En Servicios: activar un servicio de contrato suspendido y completar uno con una orden abierta avisan y no pasan; exportar y Nueva OT con candado.
- **Ventas:** ve sus dos servicios ("Alcance: Propios") con todo en candado; no ve Órdenes de trabajo en el menú; en la vista del cliente, la pestaña Operaciones muestra el candado.
- **Administrador:** matriz de Roles y permisos (las dos filas con su alcance en los cinco roles), Auditoría (textos nuevos), vista del cliente con sus órdenes, exportar servicios, y devolver un checklist con motivo.
- **Claro y oscuro:** capturas del listado y la ficha del servicio y de la ficha de la orden en oscuro, y del tablero en claro; contraste de todos los textos medido por código en las tres pantallas y la ficha del servicio, en los dos temas: ninguno por debajo de lo legible.
- **375 px:** las tres pantallas, el formulario de nueva orden y la ficha del servicio sin desborde horizontal (medido por código); captura del listado de servicios en tarjetas.
- **Carga:** cada pantalla pide primero el listado; las listas de filtros y formularios, después. Tabla a la vista en 0,4 s.

### Lo que no se probó

- **Capturas de la ficha de la orden en claro y a 375 px, y del tablero en oscuro:** la ventana de pruebas quedó oculta a ratos y las capturas fallaban; esas vistas se midieron por código. Conviene que las mires una vez.
- **Arrastrar con el ratón de verdad** en el tablero: el arrastre se envió por código (los mismos eventos que emite el navegador).
- **Mover e inmovilizar columnas** en las dos tablas: es la misma pieza de los demás listados.
- **Alcance Equipo** en los dos recursos: usa la misma regla que los demás, que sí tiene su prueba; Propios y Sede sí se probaron.
- **Que quien ejecuta no apruebe su checklist, en el navegador:** está en las pruebas automáticas.
- **El contenido del Excel:** se comprobó que se genera; el CSV sí se leyó.

### Datos de prueba que quedaron en "Tenant A - Prueba"

- Módulo **Órdenes de trabajo** activo.
- Roles **Operaciones** y **Técnico**, con las cuentas `operaciones@erp-saas.test` y `tecnico@erp-saas.test` (sin código de verificación, en la Sede principal). Sus contraseñas están en `apps/api/database/seeders/LocalOperationsSeeder.php`.
- Cotización `COT-2026-0012` y contrato `CTR-2026-0004` (vigente) de Minera Prueba CRM, con dos servicios contratados activos.
- Órdenes `OT-2026-0002` (completada, checklist aprobado), `OT-2026-0003` (asignada) y `OT-2026-0004` (asignada, checklist devuelto).

## Pendiente

- **Resto del módulo:** Proyectos (con tareas, costos y entregables), Horas y costos, Agenda y App del técnico.
- **Plantillas de checklist:** no hay pantalla para crearlas ni editarlas (hoy solo por la API anterior). Sin ella una empresa nueva no puede usar checklists. Propongo ponerla dentro de Órdenes de trabajo en la siguiente tanda.
- **Evidencias con archivo** (fotos, actas): llegan con Documentos; hoy la evidencia es una nota.
- **Evento facturable** al completar una orden de un servicio "por evento": llega con Finanzas.
- **Avisar al técnico** cuando se le asigna una orden: llega con Notificaciones.
- **Órdenes desde un ticket:** llega con Soporte.
- **Suspender o terminar un contrato no cambia sus servicios contratados** (ya era así): quedan activos aunque el contrato no lo esté; lo que sí se impide es activarlos. Propongo que suspender el contrato suspenda sus servicios.
- La orden antigua `OT-2026-0001` no tiene servicio contratado, sede ni creador: solo la ven los roles con alcance de toda la empresa.
- En la ficha del contrato, la tarjeta de servicios contratados no enlaza a la pantalla nueva.
- El inicio de la empresa sigue siendo Clientes y actores para todos; el Panel de control lo reemplazará.
