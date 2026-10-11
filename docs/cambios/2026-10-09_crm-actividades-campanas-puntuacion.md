# 2026-10-09 — CRM: Actividades, Campañas y Puntuación de leads

## Qué se pidió

"Continúa con el módulo de CRM": las tres pantallas que quedaban en "próximamente" tras Leads y Pipeline.

Referencias: prototipo (`fuente/js/ui/views/crm.js` y `extras.js`, `services/crm.js`, `core/fin.js`) y kit (`docs/especificaciones/04-crm.md`, reglas RN-CRM-06, 07 y 08).

No se te consultó antes de empezar: se siguieron tus decisiones vigentes (permisos Ver / Gestionar / Exportar por recurso, reparto de la matriz del prototipo, alcance por rol, rutas en inglés). Lo que se decidió sin preguntarte está en "Decisiones tomadas en esta tanda", para que lo cambies si no te convence.

## Cómo funciona

### Actividades (`/crm/activities`)

- Llamadas, reuniones, correos, demos y visitas, con asunto, fecha y hora, notas y, si se quiere, **un lead o una oportunidad** al que pertenecen.
- **Indicadores:** Atrasadas, Para hoy, Próximas y Realizadas. "Hoy" es el día de quien mira la pantalla, no el del servidor.
- **Tabla** (con todo lo de los demás listados) y **tablero** con las columnas Atrasadas, Hoy, Próximas y Realizadas. Arrastrar a "Realizadas" completa la actividad; sacarla de ahí la reabre. Entre las otras columnas manda la fecha: se cambia editando la actividad.
- La **casilla redonda** de cada fila o tarjeta marca la actividad como realizada (o la reabre) sin abrirla.
- **Clic en la fila** abre la actividad para editarla. Una **realizada no se edita**: se reabre antes.
- En las **fichas de la oportunidad y del lead** hay una tarjeta Actividades con las suyas y el botón Agregar. En la ficha de la oportunidad reemplaza al botón que avisaba "aún no disponible".

### Campañas (`/crm/campaigns` y `/crm/campaigns/{id}`)

- Campaña con nombre, canal, vigencia, presupuesto y código propio (`CMP-2026-0001`).
- **Estados:** Borrador → Activa → Finalizada; se cancela desde Borrador o Activa, **con motivo**. Cerrada, ya no se edita ni cambia sus miembros.
- **Miembros:** leads que se agregan desde la ficha de la campaña. Si la campaña **exige consentimiento** (lo hace por defecto), no entra ningún lead sin marcar que lo dio. **Excluir no borra:** el miembro queda en la lista como "Excluido" y deja de contar; volver a agregarlo reactiva la misma fila.
- **Resultados** de cada campaña: miembros, calificados, oportunidades, ganadas, ingresos y costo por oportunidad.
- Tabla y tablero por estado (arrastrar cambia el estado; soltar en "Canceladas" pide el motivo).
- El **formulario del lead** ya deja elegir una campaña activa (antes el campo estaba deshabilitado), con su casilla de consentimiento. Un lead que ya tiene campaña la conserva: sus campañas se gestionan desde la ficha de cada campaña.

### Puntuación de leads (`/crm/scoring`)

- Cada lead recibe de **0 a 100 puntos** sumando los criterios que cumple, y un grado: **A ≥ 70 · B ≥ 45 · C ≥ 25 · D < 25**.
- Los ocho criterios son los del prototipo: referido, evento, web, cargo con poder de decisión, ya está en Clientes y actores, tiene correo, dos o más actividades registradas y está asociado a una campaña.
- La pantalla muestra las **reglas** con sus puntos (de −50 a 50), la **distribución** de los leads abiertos por grado y la **prioridad de contacto** (los seis de mayor puntaje).
- El puntaje se **recalcula solo** al crear o editar el lead, al registrarle una actividad y al asociarlo a una campaña o excluirlo. Al **guardar las reglas** se recalculan todos; "Recalcular todos" lo hace a pedido.
- El puntaje ya se ve en el listado de leads (columna Puntaje, que ahora ordena y filtra), en su tablero y en la ficha, con los criterios cumplidos y el enlace "Ver reglas". Antes salía "—".
- Los leads que ya existían recibieron su puntaje con las reglas por defecto.

### Permisos y alcance

| Permiso | Qué permite |
| --- | --- |
| `activities.view` / `activities.manage` / `activities.export` | Ver / registrar, editar y completar / exportar actividades |
| `campaigns.view` / `campaigns.manage` / `campaigns.export` | Ver / crear, editar, cambiar de estado y gestionar miembros / exportar campañas |
| `scoring.manage` | Cambiar los puntos de los criterios y recalcular todos los leads |

- Ver la pantalla de Puntuación pide `leads.view`.
- Todos pertenecen al módulo CRM: sin el módulo, la API responde 403.

**Reparto, comparado con la matriz del prototipo:**

| Rol | Actividades | Campañas | Puntuación |
| --- | --- | --- | --- |
| Administrador | Ver, Gestionar, Exportar | Ver, Gestionar, Exportar | Configurar |
| Gerencia | Ver | Ver, Exportar | — |
| Ventas | Ver, Gestionar (**Propios**) | Ver | — |

Es el del prototipo, con una diferencia a tu favor: **en el prototipo Ventas puede cambiar las reglas de puntuación** (cuelgan de "editar leads", que Ventas tiene). Es una decisión de toda la empresa, así que se separó en un permiso propio, `scoring.manage`, que comprueba la API y que solo recibió el Administrador. Ventas y Gerencia ven la pantalla con los campos deshabilitados y el candado.

**Alcance:**

- **Actividades lleva alcance por rol** (Propios, Equipo, Sede, Toda la empresa), como Leads y Oportunidades. "Propio" es lo que la persona registró **y** lo de los leads y oportunidades que lleva: si Gerencia registra una actividad en la oportunidad de una vendedora, ella la ve. Cada rol recibió en Actividades el mismo alcance que ya tenía en Oportunidades.
- **Campañas no lleva alcance:** quien las ve, ve todas (como en el prototipo). Pero **los miembros y los resultados se cuentan sobre lo que el rol alcanza**: una vendedora con alcance Propios ve en la campaña solo sus leads y sus oportunidades, y un aviso con cuántos miembros más llevan otras personas. Una campaña no abre lo que el alcance cierra.
- La distribución y la prioridad de la pantalla de Puntuación también son sobre los leads que la persona alcanza.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Crm/Http/Controllers/CrmActivityController.php` (nuevo) | Listado (búsqueda, filtros, orden y filtros por columna en la base), indicadores, opciones del formulario, alta, edición y marcar realizada / reabrir. |
| `Crm/Http/Controllers/CrmCampaignController.php` (nuevo) | Listado con resultados, indicadores, ficha, alta, edición, lanzar / finalizar / cancelar, agregar y excluir miembros. |
| `Crm/Http/Controllers/CrmScoringController.php` (nuevo) | La pantalla, guardar los puntos y recalcular. |
| `Crm/Support/LeadScoring.php` (nuevo) | Los criterios y el cálculo del puntaje, en un solo lugar. |
| `Crm/Support/CampaignMembership.php` (nuevo) | Alta de un miembro con sus reglas, la use la ficha de la campaña o el formulario del lead. |
| `Crm/Models/CrmActivity.php`, `CrmCampaign.php` (nuevos) | Actividades respeta el alcance del rol por sí sola (`ScopedByAccess`). |
| `Crm/Http/Controllers/CrmLeadController.php`, `StoreLeadRequest.php`, `Models/CrmLead.php` | El lead acepta campaña y consentimiento, devuelve su puntaje y su campaña, ordena y filtra por puntaje, y se recalcula al guardarse. |
| `Services/AccessScope.php`, `Middleware/CheckRecordVersion.php`, `Middleware/ListExport.php` | Actividades entra al alcance por rol; actividades y campañas, al control de ediciones simultáneas y al permiso de exportar. |
| `Services/AuditCatalog.php` | Textos de las entidades y acciones nuevas para la pantalla Auditoría. |
| `routes/api.php`, `database/seeders/ModuleCatalogSeeder.php` | Rutas nuevas bajo `/crm/activities`, `/crm/campaigns` y `/crm/scoring`; el módulo CRM trae los permisos nuevos. |
| `tests/Feature/CrmActivitiesCampaignsScoringTest.php` (nuevo) | Trece pruebas. |
| `tests/Feature/AccessScopeTest.php` | Dos listas de recursos con alcance incluyen ahora `activities`. Las comprobaciones son las mismas. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/crm/ActivitiesList.tsx` (nuevo) | La pantalla Actividades: indicadores, filtros, tabla y tablero. |
| `components/dashboard/crm/Activities.tsx` (nuevo) | Piezas compartidas: formulario de actividad, casilla de realizada, tarjeta Actividades de las fichas y la etiqueta del puntaje. |
| `components/dashboard/crm/CampaignsList.tsx`, `CampaignDetail.tsx`, `CampaignModals.tsx` (nuevos) | Listado, tablero, ficha con tarjetas movibles y formulario de campaña. |
| `components/dashboard/crm/ScoringView.tsx` (nuevo) | La pantalla Puntuación de leads. |
| `app/(dashboard)/crm/activities`, `campaigns`, `campaigns/[id]`, `scoring` (nuevos) | Las rutas. |
| `components/dashboard/crm/LeadsList.tsx`, `LeadDetail.tsx`, `CrmModals.tsx` | Puntaje y campaña en el listado, el tablero, la ficha y el formulario del lead; tarjeta Actividades en la ficha. |
| `components/dashboard/crm/OpportunityDetail.tsx` | La tarjeta Actividades ya funciona. |
| `components/layout/AppShell.tsx` | Las tres pantallas dejan de estar en "próximamente". |
| `components/dashboard/admin/RolesPermissions.tsx`, `ScopeNote.tsx` | Filas Actividades, Campañas y Puntuación de leads en la matriz; texto del alcance de actividades. |
| `lib/crm.ts`, `types/crm.ts`, `lib/api.ts` | Tipos, textos y estados de lo nuevo. |

## Qué NO se tocó

- Leads y Pipeline siguen igual en su flujo: estados, calificar, ganar / perder, convertir y cotizar.
- **Recalcular el puntaje no cuenta como una edición del lead:** no cambia su versión, así que no provoca el aviso de "otra persona modificó este registro".
- Las tablas `crm_activities`, `crm_campaigns`, `crm_campaign_members` y `crm_settings` ya existían, vacías; se conservaron y solo se les añadió lo que faltaba.
- Nada fuera del CRM depende de lo nuevo.

## Decisiones tomadas en esta tanda (dime si alguna la quieres distinta)

1. **Los criterios de puntuación son fijos; la empresa cambia solo los puntos.** Es lo que hace el prototipo. El kit propone poder crear y borrar criterios (su fase F3); no se adoptó.
2. **Cancelar una campaña pide motivo**, como cancelar una orden. El prototipo no lo pide.
3. **El consentimiento se registra al agregar cada miembro** (una casilla), y la campaña decide si lo exige (por defecto, sí). En el prototipo todo miembro entra con consentimiento "sí" sin preguntar.
4. **Una actividad se puede atar a un lead**, no solo a una oportunidad, y la ficha del lead tiene su tarjeta Actividades. Hace falta para que el criterio "dos o más interacciones registradas" se pueda cumplir. En el prototipo el formulario solo ofrece oportunidades.
5. **Las actividades se editan** (asunto, tipo, fecha, notas, relación) mientras están pendientes. En el prototipo solo se marcan; pero su propio tablero dice "edita la fecha para moverla".
6. **Las actividades no se eliminan.** El kit prevé una baja; no se adoptó: una actividad equivocada se corrige o se deja realizada.
7. **Excluir a un lead de su campaña de origen le quita la campaña** (y los 5 puntos del criterio).
8. **Al guardar las reglas se recalculan todos los leads** sin pedirlo aparte, para que no queden puntajes viejos a la vista.

## Diferencias con el prototipo (y con el kit)

- Sin las notas bajo el título de cada pantalla, como en las demás.
- Rutas en inglés y permisos Ver / Gestionar / Exportar.
- Tipos de actividad con mayúscula inicial ("Llamada", "Reunión"), no en mayúsculas.
- Código de campaña con año (`CMP-2026-0001`).
- En la ficha de la campaña: tarjeta "Datos de la campaña", botón Editar e historial; el prototipo no los tiene.
- En el tablero de campañas, cada tarjeta trae "Mover a…" (en teléfono no se arrastra), como en leads y oportunidades.
- La regla de puntuación muestra su condición en palabras ("Fuente es Referido"), no como código (`source == Referido`).
- Se quitó el texto "Próxima fase: modelo predictivo… (IA)" de la pantalla de Puntuación: es de la demo.
- No se adoptó del kit: la tabla `crm_scoring_rules`, las seis acciones por permiso, el punto único de transiciones ni la bandeja de eventos.

## Base de datos

Dos migraciones, **ya corridas en la base local** con el usuario dueño. Solo añaden.

`2026_10_09_000100_crm_activities_campaigns_and_scoring`:

- `crm_activities`: sede, quién la completó, versión del registro. **La base rechaza un tipo desconocido y una actividad atada a un lead y a una oportunidad a la vez.**
- `crm_campaigns`: canal, exigir consentimiento, responsable, motivo de cancelación, versión del registro. **La base rechaza un estado desconocido, una cancelada sin motivo y un fin anterior al inicio.**
- `crm_campaign_members`: consentimiento, quién lo agregó, cuándo y quién lo excluyó. **Un lead figura una sola vez por campaña, y la aplicación no puede borrar miembros.**
- `crm_leads`: campaña, grado y criterios cumplidos del puntaje.
- Los siete permisos, su reparto y el alcance de actividades (el mismo que cada rol tenía en oportunidades).

`2026_10_09_000200_score_existing_leads`: puntúa los leads que ya existían.

`database/schema_reference.sql` no incluye estas migraciones (igual que las anteriores).

## Cómo se probó

`php artisan test`: **131 pruebas pasan** (118 anteriores + 13 nuevas), corridas tres veces seguidas:

1. Actividad: datos obligatorios, tipo conocido, lead u oportunidad (no los dos, y que existan), edición, realizada con quién y cuándo, realizada no se edita, reabrir, auditoría.
2. La base rechaza un tipo desconocido y una actividad con dos padres.
3. Listado de actividades: orden habitual y por columna, filtros de estado, tipo y relación, búsqueda (también por el nombre de la oportunidad), filtros por columna, indicadores con el "hoy" de quien mira, opciones del formulario, tope de filas.
4. **Alcance Propios en actividades:** cada vendedor ve las suyas y las de lo que lleva; la que Gerencia registró en su oportunidad, sí; lo ajeno responde 404 al completar o editar; no puede colgar una actividad del lead ni de la oportunidad de otro; rol sin alcance definido = lo propio.
5. Actividades: cada acción pide su permiso; exportar pide el suyo; ediciones simultáneas (409); otra empresa no existe; sin el módulo CRM, todo cerrado.
6. Campaña: datos obligatorios, fechas y presupuesto válidos, código correlativo, edición, estados en orden, cancelar con motivo, cerrada no se toca; la base rechaza una cancelada sin motivo y un estado desconocido.
7. Miembros: sin consentimiento no entra; un descartado tampoco; no se repite; excluir no borra y devuelve el lead a los disponibles; volver a agregarlo reactiva la misma fila; la base no admite dos filas del mismo lead ni que la aplicación borre; campaña que no exige consentimiento; campaña cerrada no cambia sus miembros; auditoría.
8. **Resultados dentro del alcance:** la administración ve los dos miembros, las dos oportunidades y los ingresos; un vendedor ve solo su lead y su oportunidad (en la ficha, el listado y los indicadores) y el aviso de un miembro más.
9. Listado de campañas: orden, filtros, búsqueda, exportar; Ventas ve y no gestiona ni exporta (cinco acciones, 403); ediciones simultáneas; otra empresa no existe y numera desde 1.
10. Formulario del lead: solo campañas activas; sin consentimiento no se guarda nada; con él, queda miembro y suma al puntaje; sin permiso de ver campañas no las recibe ni asocia a ciegas.
11. Puntaje: suma los criterios, grado, criterios cumplidos; se recalcula al editar y al registrar la segunda actividad; no mueve la versión del lead; el listado ordena y filtra por puntaje.
12. Reglas: Ventas ve la pantalla sobre sus leads y recibe 403 al guardar o recalcular; puntos de −50 a 50, enteros y de criterios que existen; al guardar se recalculan todos, también los de otras personas; auditoría con antes y después; "recalcular" no toca nada si nada cambió; las reglas son de cada empresa.
13. La matriz de roles ofrece los siete permisos y el alcance de actividades.

`tsc` y `eslint` sin errores.

En el navegador (`app.localhost:3000`):

- **Administrador.**
  - *Campañas:* crear (nombre obligatorio, fin anterior al inicio), ficha con sus seis indicadores y cuatro tarjetas, lanzar, agregar un miembro (sin elegir lead, sin consentimiento, con consentimiento), excluir, volver a agregar, editar el presupuesto, historial. Segunda campaña cancelada desde el tablero: transición no permitida, cancelar sin motivo, cancelar con motivo. Tabla: orden por Miembros, filtro Estado.
  - *Leads:* columna Puntaje con sus criterios al pasar el cursor; nuevo lead con campaña (lista de campañas activas, casilla de consentimiento, error sin ella) → nace con B 55 y su campaña bajo la fuente.
  - *Ficha del lead:* Campaña, Puntaje automático con criterios y "Ver reglas", tarjeta Actividades; al registrar la segunda actividad el puntaje pasó a A 70; marcar una como realizada.
  - *Puntuación:* reglas, distribución y prioridad; un valor fuera de rango se marca y no se guarda; cambiar "referido" de 25 a 10 recalculó 5 leads (2 cambiaron) y el lead pasó a B 55; "Recalcular todos"; la regla se devolvió a 25.
  - *Actividades:* indicadores, tabla, nueva actividad (asunto obligatorio), editar con el desplegable "Relacionado con" (oportunidades y leads abiertos), casilla de la fila (completa sin abrir el formulario), realizada en solo lectura, reabrir, filtros Estado y Tipo, buscador, orden por Fecha, filtro por columna en Relacionado, la columna Tipo abre el filtro de arriba, exportar CSV (9 columnas). Tablero: arrastrar a Realizadas, sacarla de ahí, y el aviso al mover entre columnas de fecha.
  - *Ficha de la oportunidad:* tarjeta Actividades con la suya y el formulario sin el campo de relación.
  - *Roles y permisos:* filas Actividades, Campañas y Puntuación de leads para los tres roles, con el alcance "Propios" de Ventas en Actividades.
  - *Auditoría:* los movimientos nuevos salen con su texto ("Actividad realizada", "Cambio de reglas de puntuación", "Campaña"…).
- **Ventas:** en Actividades ve solo la de su oportunidad (la que registró el Administrador), con "Alcance: Propios" y exportar con candado; registró una actividad en su oportunidad (el formulario no le ofrece el lead ajeno) y la marcó realizada. En Campañas: "Nueva campaña" y exportar con candado, el tablero no deja arrastrar; en la ficha ve 1 miembro (el suyo) con el aviso "hay 1 miembro más que lleva otras personas", y Editar, Finalizar, Cancelar, Agregar y Excluir con candado. En Puntuación: campos deshabilitados, "Guardar reglas" y "Recalcular todos" con candado. El formulario del lead le ofrece la campaña activa.
- **Gerencia:** ve las cuatro actividades de la empresa; "Nueva actividad", la casilla y exportar con candado; una actividad se abre en solo lectura. En Campañas exporta y no crea. En Puntuación ve la prioridad de toda la empresa y no cambia reglas.
- **Claro y oscuro:** Actividades y Puntuación en oscuro y Actividades en claro, con captura; la ficha de campaña en oscuro, con captura parcial.
- **375 px** (medido por código): Actividades, Campañas y Puntuación, sus tableros, la ficha de campaña, Leads y los formularios de actividad y de campaña, sin desborde horizontal; las tablas pasan a tarjetas.

### Lo que no se probó

- **Capturas a 375 px y de Campañas en claro:** la ventana de pruebas estuvo oculta casi todo el tiempo y las capturas fallaban; se midió por código. Conviene que mires esas pantallas una vez.
- **Arrastrar con el ratón de verdad** en los tres tableros: el arrastre se envió por código (los mismos eventos que emite el navegador).
- **Excel:** se probó el CSV de Actividades; el Excel y los archivos de Campañas usan la misma pieza que los demás listados y la regla de permiso está en las pruebas automáticas.
- **Alcances Equipo y Sede en actividades**, en el navegador y en pruebas propias: usan la misma regla que Leads y Oportunidades, que sí tiene su prueba.
- **Mover e inmovilizar columnas** en las dos tablas nuevas: es la misma pieza que en los demás listados.

### Datos de prueba que quedaron en "Tenant A - Prueba"

- Campañas `CMP-2026-0001` "Feria industrial 2026 (prueba)" (activa, con LD-2026-0002 y LD-2026-0005 como miembros) y `CMP-2026-0002` "Boletín de noviembre (prueba)" (cancelada).
- Lead `LD-2026-0005` "Constructora Prueba CRM SAC" (abierto, del Administrador, A 70).
- Cuatro actividades: dos en ese lead, y "Visita técnica a planta" y "Llamar a Rosa por la propuesta" en la oportunidad OP-2026-0004.
- Las reglas de puntuación quedaron con sus valores originales.

## Pendiente

- Solicitudes del portal por la ruta CRM (que una solicitud se vuelva oportunidad).
- Pantalla para editar etapas del embudo y fuentes; importar leads desde Excel.
- **Responsable de la actividad:** hoy es quien la registra; no se puede asignar a otra persona.
- **Recordatorios** de actividades atrasadas o para hoy: llegarán con Notificaciones.
- **Campañas con clientes:** la tabla admite clientes como miembros, pero la pantalla solo agrega leads (como el prototipo).
- **Envío real de comunicaciones** de campaña (correo, WhatsApp): la campaña hoy organiza y mide; no envía.
- El filtro "hoy" de la columna Fecha en Actividades usa el día del servidor (UTC), como en los demás listados; los indicadores y el tablero sí usan el día de quien mira.
