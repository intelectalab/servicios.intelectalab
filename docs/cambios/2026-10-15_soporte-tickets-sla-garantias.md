# 2026-10-15 — Soporte: Tickets y SLA, Garantías y Calendario laboral y SLA

## Qué se pidió

"Módulo Soporte", con el capítulo de Soporte del kit (`12-soporte.md`) y las dos pantallas del prototipo del menú Soporte: "Tickets y SLA" y "Garantías". Antes de construir se hizo el inventario del prototipo y se te preguntó lo que el kit trae y el prototipo no muestra en esas pantallas. Respondiste:

| Pregunta | Respuesta |
|---|---|
| Calendario laboral (SLA en horario hábil, con feriados): pantalla del prototipo en Administración, horario fijo o 24×7 | "a. hazla ya": la pantalla del prototipo |
| Tiempos de SLA por prioridad: ¿editables? | "editables tonces" |
| Condiciones de la garantía | "lo que recomiendes": campo en el Catálogo de servicios, junto a los meses |
| Garantías: alta propia, filtros, exportar y ficha (el prototipo solo tiene la tabla y el alta desde el ticket) | "me parece lo que dices, si lo dice el kit tonces sí" |
| Rol Soporte del prototipo con su cuenta de prueba | "hazlo" |

## Cómo funciona

### Tickets y SLA (Soporte → Tickets y SLA, `/tickets`)

- **Listado** como el prototipo: conmutador Tabla / Kanban, botón **Nuevo ticket**, cuatro indicadores (**Tickets abiertos, SLA vencido, En riesgo, Resueltos**), buscador "Buscar ticket, asunto o cliente" y filtros **Estado** (Abiertos (todos), Sin clasificar, En atención, Esperando cliente, Resueltos, Cerrados, Reabiertos), **Prioridad** (P1 Crítica… P4 Baja) y **SLA** (Vencido, En riesgo, A tiempo, En pausa, Cumplido).
- Columnas del prototipo: Ticket (asunto, número · canal · categoría), Cliente, Prioridad, **SLA** (barra del plazo y "A tiempo · quedan 5 h", "En riesgo · quedan 2 h", "Vencido hace 2 h", "SLA en pausa", "SLA cumplido", "Sin SLA (pendiente de triage)"), Asignado y Estado. Orden de siempre: de la prioridad más urgente a la menos (sin clasificar al final) y luego lo más nuevo. Como en todo listado: orden, filtros por columna y exportar sobre la tabla completa, casillas, paginación y exportar al pie.
- **Tablero** con las columnas del prototipo: Abiertos, Clasificados, Asignados (los reabiertos van aquí con su etiqueta "Reabierto"), En atención, Esperando cliente y Resueltos. Como en el prototipo, el tablero no mueve tarjetas: los cambios de estado se hacen en la ficha.
- **Nuevo ticket**: Cliente, Canal (Teléfono, Correo, Backoffice), Asunto, Categoría (Incidencia, Requerimiento, Consulta) y Descripción, como el prototipo. Además (mejora): **Contrato** y **Servicio**, opcionales; sin elegir, el ticket toma el contrato vigente más reciente del cliente y su primer servicio, como hace el prototipo. La descripción abre la conversación.
- **Ficha** (`/tickets/{id}`), con la disposición del prototipo a dos columnas: a la izquierda **SLA** y **Conversación**; a la derecha **Estados**, **Contexto** e **Historial** (tarjetas movibles).
  - Botones del prototipo, cada uno con su permiso y su candado: **Clasificar (triage)** / Reclasificar, **Asignar** / Reasignar, **Escalar**, **Crear OT** y **Reclamo de garantía** (solo con contrato y sin reclamo).
  - **SLA**: barra grande, Política ("P2 Alta · respuesta 2 h · resolución 8 h (contrato Premium)"), Cálculo (horario hábil o 24×7, mejora), Respuesta vence (y cuándo se respondió), Resolución vence y Tiempo en pausa.
  - **Conversación**: "Las notas internas nunca se muestran en el portal", casilla **Mostrar notas internas**, mensajes con su etiqueta (Nota interna, Respuesta pública, Cliente) y la caja de respuesta con **Nota interna** y **Enviar**. Un ticket cerrado no recibe mensajes.
  - **Estados**: Atender, Esperar al cliente (pausa SLA), Retomar, Resolver (pide la resolución), Cerrar y Reabrir (pide el motivo).
  - **Contexto**: Cliente (enlace a su vista 360), Contrato · SLA, Servicio, Prioridad, Asignado, Equipo, Orden de trabajo, Garantía, Resolución y quién lo registró.

### Cómo se calcula el SLA

- Al **clasificar** se elige la prioridad (P1 a P4). El vencimiento sale de la política de esa prioridad, del **calendario laboral** de la empresa (horario por día, feriados) y del nivel del contrato: un contrato **Premium** tiene el porcentaje del tiempo que fije la empresa (50 % si no lo cambia).
- Por defecto, como el prototipo: P1 Crítica 1 h / 4 h en **24×7**; P2 Alta 2 h / 8 h, P3 Media 4 h / 24 h y P4 Baja 8 h / 72 h en **horario hábil** (lunes a viernes 8:30–18:00, sábado 9:00–13:00, sin domingos ni feriados de Perú), en la hora de Lima.
- La **primera respuesta** es la primera respuesta pública o pasar a "En atención".
- **Esperando cliente** pausa el reloj. Al retomar, el tiempo en pausa (contado en el mismo calendario) se suma a los vencimientos.
- **Escalar** sube una prioridad y recalcula la resolución desde ese momento, como el prototipo.
- **Reabrir** pide motivo y recalcula el vencimiento desde la reapertura, con el mismo tiempo de resolución.
- "En riesgo" = consumido el 75 % del plazo, como el prototipo.

### Garantías (Soporte → Garantías, `/warranty-claims`)

- Tabla del prototipo: **Reclamo** (número y descripción), **Cliente / servicio**, **Ticket**, **Cobertura** ("Dentro de garantía" / "Fuera de cobertura" con "12 meses · condiciones · hasta…", o el botón **Validar cobertura**), **Estado** (con el motivo del rechazo) y **Acciones** (**Aprobar** —deshabilitado "Fuera de cobertura"—, **Rechazar** con motivo, **Marcar resuelto**).
- Lo que pediste sumar: botón **Nuevo reclamo** (servicio contratado, ticket opcional del mismo cliente y descripción), buscador, filtros **Estado** y **Cobertura**, orden y filtros por columna, exportar al pie y, al hacer clic en la fila, la **ficha del reclamo** en una ventana (datos, cobertura, desde qué fecha se cuenta, quién la revisó y su historial).
- Desde el ticket, **Reclamo de garantía** abre el mismo formulario con el ticket y su cliente, y la descripción propuesta (el asunto), como el prototipo; al registrarlo lleva a Garantías.
- **Cobertura**: desde la última orden de trabajo completada del servicio contratado (o, si no hay, desde el inicio del servicio) más los meses de garantía del servicio. Se valida sola al registrar el reclamo. Aprobar exige que esté dentro de garantía. No factura nada.
- **Catálogo de servicios**: campo nuevo **Condiciones de garantía** junto a los meses (formulario y ficha del servicio); se muestra en la cobertura de cada reclamo.

### Calendario laboral y SLA (Administración, `/sla-calendar`)

- Pantalla del prototipo, a dos columnas: **Horario de atención** (casilla y horas por día, de lunes a domingo, y **Modo de cálculo por prioridad**: horario hábil o 24×7, con **Guardar horario**), **Simulación: ticket creado ahora** (cuándo vencería cada prioridad) y **Feriados** (lista con quitar y alta de fecha y nombre; "Lista referencial de feriados nacionales… Próximos: …").
- Lo que pediste sumar: tarjeta **Tiempos por prioridad** (respuesta y resolución en horas, y el porcentaje del contrato Premium), la columna **Con Premium** en la simulación y una tarjeta **Historial** de los cambios.
- Cambiar el horario o los tiempos **no mueve** los vencimientos de los tickets ya clasificados.
- Va en Administración, como en el prototipo, pero pertenece al módulo Soporte del plan: sin él sale con el candado "Plan".

### Permisos y alcance

| Rol | Tickets | Garantías | Calendario |
|---|---|---|---|
| Administrador | todo | todo | ver y gestionar |
| Gerencia | ver y exportar (toda la empresa) | ver y aprobar (toda la empresa) | ver |
| Ventas | ver (los de los clientes de sus contratos) | — | — |
| Operaciones | ver y atender (su sede) | ver (su sede) | — |
| Técnico | ver y atender (los suyos) | — | — |
| Finanzas | — | — | — |
| **Soporte** (rol nuevo) | ver, crear, atender, escalar (su equipo y la bandeja sin tomar) | ver, crear, gestionar, aprobar (toda la empresa) | — |

- Permisos: `tickets.view/create/manage/approve/export` (Aprobar = **Escalar**), `warranty.view/create/manage/approve/export` y `sla.view/manage`. Los `support.view/manage` de antes se renombraron a `tickets.view/manage`: quien los tenía no perdió nada.
- **Alcance** nuevo en Tickets y en Garantías. Un ticket es "propio" de quien lo atiende, de quien lo registró y de quien vendió el contrato del cliente. Con alcance **Equipo**, además, los tickets de su equipo y la **bandeja** que nadie tomó todavía. Un reclamo es propio de quien lo registró y de quien atiende su ticket.
- El equipo del ticket es el de quien lo registra y, al asignarlo, el de su responsable.
- Crear la OT del ticket pide ver el ticket y `work_orders.create`. La pestaña Soporte de la vista 360 del cliente pide `tickets.view` y respeta el alcance.

### Lo que la base garantiza

- Pasado el triage, el ticket tiene prioridad; asignado, en atención o esperando al cliente, tiene responsable; **resuelto o cerrado, tiene resolución**; **reabierto, tiene motivo**; el reloj solo está en pausa esperando al cliente.
- Los mensajes de la conversación **no se editan ni se borran**, y el portal nunca escribe notas internas.
- Un reclamo **cubierto o resuelto exige la cobertura validada y dentro de plazo**; rechazado, motivo; resuelto, resolución. Un reclamo por ticket.
- Ni tickets, ni mensajes, ni reclamos, ni políticas se borran. Un feriado quitado queda retirado, con su historia, y no se edita.
- Las tablas nuevas (calendario y feriados) tienen su regla de aislamiento por empresa. Tickets y reclamos llevan `lock_version`: quien tenía la ficha abierta con una versión vieja no pisa el cambio de otra persona; el calendario hace lo mismo con su propia versión.

## Qué se cambió

**API (`apps/api`)**
- `database/migrations/2026_10_15_000100_support_tickets_sla_and_warranty.php` (nueva): tablas `support_calendars` y `support_holidays`; columnas y controles en `support_tickets`, `support_ticket_comments`, `support_slas` y `warranty_claims`; `services.warranty_conditions`; permisos, módulo y alcance.
- `app/Modules/Support/Support/SlaCalendar.php` (nueva): calendario, feriados y políticas de la empresa (se crean la primera vez) y suma/cuenta de minutos hábiles.
- `app/Modules/Support/Http/Controllers/SupportTicketController.php` (rehecho), `WarrantyClaimController.php` y `SlaSettingsController.php` (nuevos).
- `app/Modules/Support/Models/SupportTicket.php` (alcance, estados, SQL del estado del SLA), `SupportTicketComment.php`, `WarrantyClaim.php` (nuevo). Se quitó `Http/Requests/StoreTicketRequest.php` (ya no se usaba).
- `app/Modules/Operations/Support/WorkOrders.php` (nueva): el alta de una OT, que ahora comparten la pantalla de órdenes de trabajo y "Crear OT" del ticket (`WorkOrderController::store` la usa; no cambió su comportamiento).
- `routes/api.php`: rutas `/tickets…`, `/warranty-claims…`, `/sla-settings…`; **se quitaron las rutas antiguas `/support-tickets`**.
- `app/Services/AccessScope.php` (recursos `tickets` y `warranty`, bandeja del equipo), `app/Http/Middleware/CheckRecordVersion.php`, `ListExport.php`, `app/Services/AuditCatalog.php` (textos nuevos), `PartyController.php` (pestaña Soporte por su modelo y con `tickets.view`), catálogo de servicios (`Service`, `StoreServiceRequest`, `ServiceController`).
- `database/seeders/LocalSupportSeeder.php` (nuevo, solo local): módulo Soporte en Tenant A, rol Soporte y cuenta `soporte@erp-saas.test` en el equipo "Mesa de ayuda".
- Pruebas: `tests/Feature/SupportTest.php` (nueva, 7 pruebas); `AccessScopeTest.php` y `Concerns/OperationsFixtures.php` ajustadas a los recursos y al permiso renombrado.

**Web (`apps/web`)**
- `components/dashboard/support/`: `TicketsList.tsx`, `TicketDetail.tsx`, `TicketModals.tsx`, `WarrantyClaimsList.tsx`, `WarrantyModals.tsx`, `SlaCalendar.tsx`, `SupportParts.tsx` (prioridad, barra de SLA).
- `lib/support.ts`, `types/support.ts`; páginas `app/(dashboard)/tickets`, `tickets/[id]`, `warranty-claims`, `sla-calendar`.
- `components/layout/AppShell.tsx` (las tres pantallas dejan de ser "próximamente"), `proxy.ts`, `lib/api.ts` (mensajes y OT del ticket no se envían dos veces), `ScopeNote.tsx`, `admin/RolesPermissions.tsx` (nombres en la matriz), `actors/ActorDetail.tsx` (pestaña Soporte con prioridad, estado y enlace al ticket), `services/ServiceFormModal.tsx` y `ServiceDetail.tsx` (condiciones de garantía), `types/commercial.ts`.

## Qué NO se tocó

- Las tablas viejas que nadie usaba (`support_sla_runs`, `support_ticket_events`, `support_ticket_assignments`, `warranty_policies`) siguen ahí, sin uso.
- El portal del cliente (no existe): solo cuenta tickets abiertos, como antes.
- El conmutador Tabla / Kanban es el compartido del sistema (el prototipo dice "Lista / Tablero").

## Diferencias con el prototipo

- **Se ven, pero aún no hacen nada** (falta Notificaciones): el aviso a Gerencia al escalar o clasificar en P1, y el aviso al responsable al asignar. El texto de la ventana lo dice.
- La respuesta del cliente desde el portal, que reanuda el reloj, no existe porque no hay portal: se retoma con "Retomar".
- El "Job SLA idempotente (PROC-037)" del prototipo no se trae (es una nota de la demo): el estado del SLA se calcula al momento en la base, sobre todo el listado.
- Los indicadores "SLA vencido" y "En riesgo" cuentan solo tickets sin resolver; el prototipo contaba también los resueltos tarde en "vencido".
- Se dejaron fuera las notas que el prototipo pone bajo el título de cada pantalla (son de la demo).

## Base de datos

- `2026_10_15_000100_support_tickets_sla_and_warranty` — **ya corrida** en la base local (como `erp_user`). Dos líneas que se añadieron al archivo después de correrlo (`sla_started_at` y la hora exacta de cada mensaje) se aplicaron a mano en la base con el mismo texto.
- El único ticket que había (TKT-2026-0001, cerrado, prioridad HIGH) pasó a **P2** y recibió como resolución "Registrado antes de la pantalla de Soporte, sin resolución escrita.".
- `php artisan db:seed --class=LocalSupportSeeder` — **ya corrido** (solo local).

## Cómo se probó

- `php artisan test`: **233 pruebas**, todas pasan (`SupportTest` 7, 220 comprobaciones: SLA hábil con feriados, Premium, pausa, escalar y reabrir con horas exactas; indicadores y filtros; alcance Propio, Equipo y Ventas; otra empresa 404; sin módulo 403; OT desde el ticket; cobertura y aprobación de garantías; calendario, tiempos y feriados; protecciones de la base; ediciones simultáneas). `tsc` y `eslint` sin errores.
- Navegador, contra `app.localhost:3000`:
  - **Soporte** (`soporte@`): indicadores 5 / 1 / 1 / 1 con los datos de prueba; filtros SLA Vencido, En riesgo y En pausa; orden por SLA; tablero; ciclo completo de un ticket (clasificar P3 → asignar → atender → esperar → retomar → resolver → cerrar → reabrir → escalar); nota interna y "Mostrar notas internas"; Nuevo ticket; Crear OT (con servicio contratado: abre OT-2026-0008; sin él, el botón queda deshabilitado con el aviso); Reclamo de garantía desde el ticket; Nuevo reclamo desde Garantías; clic en la fila abre la ficha y los botones de la fila no la abren; exportar con candado (Soporte no exporta).
  - **Gerencia**: Nuevo ticket, Reasignar, Escalar, Enviar y los cambios de estado con candado y su mensaje; exportar a Excel funciona (archivo `.xlsx` de 8,4 KB); el calendario en solo lectura; Aprobar y Rechazar garantías habilitados.
  - **Administrador**: horario con error (lunes de 18:00 a 09:00) y guardado; feriado agregado y quitado; tiempos guardados; Auditoría muestra los textos nuevos; la matriz de Roles muestra Tickets y SLA, Garantías y Calendario laboral y SLA.
  - **1366 px**: la tabla de tickets cabe (1000 de 1000 px, tras angostar Ticket y SLA) y la de garantías también; la ficha queda a dos columnas. **375 px**: las cuatro pantallas sin desplazamiento lateral ni franja vacía. **Oscuro y claro**: contraste medido por código; lo único bajo 4,5 son piezas que ya comparte todo el sistema (botón azul con texto blanco e insignias de estado).
- **Segunda ronda ("prueba lo que falta")**, ya con capturas:
  - Vistos con capturas: listado y tablero de tickets, ficha del ticket (SLA, conversación con nota interna, estados, contexto, historial) en claro y en oscuro; Garantías y la ficha del reclamo; Calendario laboral y SLA; tickets y Garantías a 375 px.
  - En el navegador, con cada cuenta: **Técnico** ve solo el ticket que se le asignó (TKT-2026-0008), lo atiende, y Escalar y Crear OT salen con candado; Garantías y Calendario le dicen que su rol no tiene permiso. **Ventas** ve los 3 tickets de los clientes de sus contratos ("Alcance: Propios"), todo con candado (Nuevo ticket, Exportar, Reasignar, Enviar, estados). **Operaciones** ve los 7 de su sede ("Alcance: Sede"), atiende y crea OT; en Garantías ve los 5 reclamos sin poder aprobar ni registrar; el calendario no lo abre. **Finanzas** no ve Soporte en el menú y, por su dirección, la pantalla le dice que su rol no tiene permiso.
  - Se arreglaron tres cosas que solo se veían en pantalla: en **Garantías** el número de ticket se partía en dos líneas (ahora va en una); en **Calendario** la tabla de Simulación se desplazaba de lado a 1366 px (las fechas ahora bajan de línea, 500 de 500 px); y en **teléfono** el nombre del responsable se salía 2 px de la tarjeta (el avatar con nombre de todo el sistema, `Who` en `proto.tsx`, ahora baja de línea solo en teléfono; en escritorio no cambia).
- Lo que no se cambió: el contraste del botón azul con texto blanco (3,6) y de algunas insignias de estado en claro (3,7 a 4,2) queda bajo 4,5. Son piezas de todo el sistema, no de Soporte.

## Datos de prueba que quedaron (Tenant A)

- Tickets: **TKT-2026-0002** (Minera, P2, en atención, con nota interna, OT-2026-0007 y reclamo GAR-2026-0001), **0003** (P1, vencido: sus horas se movieron en la base para que se vea vencido), **0004** (P4 resuelto, reclamo GAR-2026-0005), **0005** (reabierto y escalado a P2, con nota interna), **0006** (Empresa Prueba, contrato Premium, esperando cliente), **0007** (P3 en riesgo, también con horas movidas en la base; OT-2026-0008 y GAR-2026-0003), **0008** (P2, asignado al Técnico y en atención).
- Reclamos: GAR-2026-0001 y 0003 cubiertos, 0002 no cubierto (Mantenimiento preventivo no tiene garantía), 0004 y 0005 en revisión.
- Calendario de Tenant A: el del prototipo (se guardó sin cambios; un feriado de prueba se agregó y se quitó y queda en el historial).

## Pendiente

- Avisos de Notificaciones (escalar, asignar, P1 a Gerencia, SLA por vencer).
- Portal del cliente: abrir tickets, responder (reanuda el reloj) y reclamos desde el portal.
- Tarea programada que marque vencimientos (hoy el estado se calcula al consultar, no se avisa).
- El historial de un ticket ordena mal dos movimientos del mismo segundo (la auditoría guarda segundos), como en las demás fichas.

## Decisiones tomadas sin preguntarte

1. Se conservaron las tablas `support_tickets`, `support_ticket_comments`, `support_slas` y `warranty_claims`, y se rehízo el controlador; las rutas `/support-tickets` se reemplazaron por `/tickets`.
2. Número de ticket con el prefijo que ya tenía tu ticket (`TKT-2026-…`), no el `TK-` del prototipo. Reclamos: `GAR-2026-…`.
3. "Aprobar" de tickets es **Escalar** (como en el kit), y en el prototipo Gerencia no escala.
4. El tiempo en pausa se suma al vencimiento contado en el mismo calendario del SLA (en horario hábil, solo las horas hábiles en pausa).
5. Reabrir recalcula desde ese momento con el tiempo de resolución que ya tenía el ticket (el prototipo daba un día fijo).
6. Escalar recalcula solo la resolución, como el prototipo; la respuesta queda como estaba.
7. Cambiar el calendario o los tiempos no mueve los tickets ya clasificados.
8. El porcentaje del contrato Premium es uno por empresa (el kit lo pone por prioridad).
9. Garantía en meses de calendario (el prototipo usaba 30 días por mes). Se cubre si el reclamo llega hasta el último día inclusive.
10. Un reclamo por ticket. Rechazar pide motivo y "Marcar resuelto" pide cómo se resolvió (el prototipo no pedía texto al resolver).
11. Un servicio contratado se puede reclamar activo, suspendido o completado (no pendiente ni cancelado).
12. Alcance: la "bandeja" (tickets sin responsable ni equipo) la ve quien tiene alcance Equipo; Ventas ve los tickets de los clientes de sus contratos.
13. El calendario va en Administración, como en el prototipo, pero depende del módulo Soporte del plan.
14. El texto del ticket nuevo abre la conversación como "Respuesta pública" de quien lo registró, como el prototipo.
15. Feriados nacionales de Perú de 2026 y 2027 precargados en cada empresa (el prototipo traía solo 2026).
16. Para que se vieran los indicadores de vencido y en riesgo, se movieron en la base las horas de dos tickets de prueba (TKT-2026-0003 y 0007).
