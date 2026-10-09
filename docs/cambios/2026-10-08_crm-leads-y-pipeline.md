# 2026-10-08 — CRM, flujo principal: Leads y Pipeline de oportunidades

## Qué se pidió

Construir el módulo CRM, con la especificación del kit (`docs/especificaciones/04-crm.md`) como guía y el prototipo como referencia de pantallas.

Antes de empezar se te consultaron cuatro puntos. Lo que respondiste:

1. **Alcance de hoy: el flujo principal.** Leads (tabla, tablero y ficha), Pipeline de oportunidades (tabla, tablero y ficha), ganar / perder y convertir a cliente. Actividades, Campañas y Puntuación de leads quedan para la siguiente tanda.
2. **Permisos:** no quedó clara la diferencia entre las opciones; se siguió con tu decisión vigente (una fila por recurso con **Ver** y **Gestionar**, como en Comercial).
3. **Alcance del rol: sí, como el prototipo.** Ventas ve solo lo propio; Gerencia y Administrador, toda la empresa.
4. **La ruta que convertía un lead directo en cliente: reemplazarla.**

## Cómo funciona

```
Lead  →  Calificar y crear oportunidad  →  Oportunidad (etapas)  →  Ganada / Perdida  →  Convertir a cliente  →  Cotizar
```

- **Lead** (`LD-2026-0001`): Nuevo → Contactado → Calificado → Convertido a oportunidad. Se puede descartar desde cualquiera de los tres primeros, **con motivo**. Un mismo correo no puede tener dos leads abiertos.
- Si la empresa del lead **ya está en Clientes y actores**, el lead nace enlazado a ella ("En CORE"); si se registra después, se marca "Coincidencia". No se crea nada en Clientes y actores todavía.
- **Calificar y crear oportunidad** pide el monto estimado y deja el lead como Convertido. **Una sola oportunidad por lead.** La oportunidad sigue siendo de quien llevaba el lead, la cree quien la cree.
- **Oportunidad** (`OP-2026-0001`): abierta, avanza por las etapas del embudo (Prospección 10 %, Calificación 25 %, Propuesta 50 %, Negociación 75 %); cada etapa pone su probabilidad. **Ganar exige evidencia; perder exige motivo.** Cerrada, ya no se edita ni cambia de etapa.
- **Convertir a cliente** (solo las ganadas, una sola vez):
  - **Reutilizar una identidad existente:** las coincidencias aparecen primero; también se puede buscar cualquier identidad. Si aún no es cliente, se le agrega el rol.
  - **Crear organización + cliente:** razón social y RUC, con el mismo control de duplicados de Clientes y actores. Si el RUC ya existe **no se crea otra**: la pantalla la deja seleccionada para reutilizarla. Si el lead tenía contacto con nombre y apellido, nace también la persona con rol Contacto de esa organización.
- **Cotizar desde la oportunidad:** con cliente y sin estar perdida, abre la nueva cotización con el cliente puesto. La cotización queda con origen "Oportunidad CRM" y enlazada en las dos fichas.

### Permisos y alcance

| Permiso | Qué permite |
| --- | --- |
| `leads.view` / `leads.manage` | Ver leads / crear, editar, contactar, calificar y descartar |
| `opportunities.view` / `opportunities.manage` | Ver el pipeline / crear oportunidades (también desde un lead), moverlas, ganarlas, perderlas y convertirlas |
| `core.manage` (además) | Al convertir, **dar de alta** una organización o **agregar el rol Cliente** a una identidad. Convertir con un cliente que ya existe no lo pide |

- Sustituyen a `crm.view` / `crm.manage`, que se **renombraron** a `leads.*`: quien los tenía los conserva y recibió además los de oportunidades.
- **Leads y Oportunidades llevan alcance por rol** (Propios, Equipo, Sede, Toda la empresa), igual que Cotizaciones. "Propio" es lo que la persona lleva como responsable. Fuera de alcance = no existe (404).

Cómo quedaron tus roles en "Tenant A - Prueba":

| Rol | Leads | Oportunidades | Alcance |
| --- | --- | --- | --- |
| Administrador | Ver, Gestionar | Ver, Gestionar | Toda la empresa |
| Gerencia | Ver | Ver, Gestionar | Toda la empresa |
| Ventas | Ver, Gestionar | Ver, Gestionar | **Propios** |

Ventas tiene además `core.manage` desde la segunda parte.

Es el reparto del prototipo. En las demás empresas, los roles llamados Ventas y Gerencia recibieron lo mismo; no surte efecto mientras la empresa no tenga el módulo CRM.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Crm/Http/Controllers/CrmLeadController.php` | Reescrito: listado con búsqueda, filtros, orden y filtros por columna en el servidor; indicadores; alta, edición, contactar, calificar, descartar; calificar y crear oportunidad. El método que convertía el lead en cliente **sigue en el archivo, sin ruta**. |
| `Crm/Http/Controllers/CrmOpportunityController.php` (nuevo) | Pipeline: listado, indicadores, alta, edición, mover de etapa, ganar, perder, convertir. |
| `Crm/Models/CrmLead.php`, `CrmOpportunity.php`, `CrmStage.php`, `CrmSource.php` | Leads y oportunidades respetan el alcance del rol por sí solos (`ScopedByAccess`). |
| `Crm/Support/CrmCatalog.php` (nuevo) | La primera vez que una empresa entra al CRM se le crean el embudo "Ventas" con las cuatro etapas y las fuentes del prototipo. |
| `Core/Http/Controllers/PartyController.php` | Dos entradas para que otros módulos den de alta una identidad o le agreguen el rol Cliente **con la misma validación y la misma búsqueda de duplicados** de la pantalla. En la vista del cliente, leads y oportunidades se consultan por sus modelos (heredan el alcance) y piden `leads.view` / `opportunities.view`. |
| `Services/Http/Controllers/QuotationController.php`, `StoreQuotationRequest.php` | La cotización acepta la oportunidad de origen: tiene que alcanzarla quien cotiza, no estar perdida y ser del mismo cliente. La ficha devuelve la oportunidad de origen. |
| `Services/AccessScope.php`, `Middleware/CheckRecordVersion.php` | Leads y oportunidades entran al alcance por rol y al control de ediciones simultáneas. |
| `routes/api.php` | Rutas nuevas bajo `/crm/leads` y `/crm/opportunities`. La ruta `POST /crm/leads/{id}/convert` quedó comentada. |
| `database/seeders/ModuleCatalogSeeder.php` | El módulo CRM trae los permisos `leads.*` y `opportunities.*`. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/crm/LeadsList.tsx` (nuevo) | Leads: indicadores, filtros (Estado, Fuente, Responsable), tabla con todo lo de los demás listados (orden y filtros en el servidor, selección, exportar, clic en la fila) y tablero por estado. |
| `components/dashboard/crm/LeadDetail.tsx` (nuevo) | Ficha: datos del prospecto, ciclo del lead con sus botones e historial, aviso si la empresa ya existe en CORE. Tarjetas movibles. |
| `components/dashboard/crm/PipelineList.tsx` (nuevo) | Pipeline: indicadores (abierto, ponderado, ganadas, tasa de cierre), tablero por etapa y tabla. |
| `components/dashboard/crm/OpportunityDetail.tsx` (nuevo) | Ficha: etapas, aviso de conversión, Resumen, Actividades, Cotizaciones vinculadas, Historial. Tarjetas movibles. |
| `components/dashboard/crm/CrmModals.tsx` (nuevo) | Nuevo / editar lead, calificar, descartar, nueva / editar oportunidad, ganar / perder, convertir. Desplegables propios (`PickSelect`, `SearchSelect`). |
| `components/ui/kanban.tsx` (nuevo) | Tablero por columnas reutilizable (arrastrar y soltar) y el conmutador Tabla / Kanban. La vista elegida se recuerda en el navegador. |
| `lib/crm.ts`, `types/crm.ts` (nuevos) | Estados, textos y tipos del CRM. |
| `app/(dashboard)/crm/…` (nuevo) | Rutas `/crm/leads`, `/crm/leads/[id]`, `/crm/opportunities`, `/crm/opportunities/[id]`. |
| `components/layout/AppShell.tsx`, `proxy.ts` | Leads y Pipeline dejan de estar en "próximamente". |
| `lib/api.ts` | El control de ediciones simultáneas y el de envíos repetidos reconocen las rutas del CRM. |
| `components/dashboard/admin/RolesPermissions.tsx` | Filas **Leads** y **Oportunidades** (antes "CRM"), con su columna Alcance. |
| `components/dashboard/ScopeNote.tsx` | Texto de "Alcance" propio del CRM. |
| `components/dashboard/actors/ActorDetail.tsx` | La pestaña CRM muestra dos tablas, Oportunidades y Leads, cada una con su permiso y con clic a su ficha. |
| `components/dashboard/quotations/QuotationEditor.tsx`, `QuotationDetail.tsx` | La nueva cotización recibe la oportunidad; en Trazabilidad, "Origen" enlaza a ella. |

## Qué NO se tocó

- Tus tablas `crm_…` se conservan con sus nombres y columnas; solo se les añadió lo que faltaba.
- El lead que ya existía (Roberto Diaz, convertido el 16 de septiembre) recibió el número `LD-2026-0001` y sigue como estaba.
- Nada fuera del CRM depende de él: una empresa sin el módulo cotiza y opera igual (hay prueba).
- Solicitudes del portal: siguen yendo por "ERP directo".
- Las piezas compartidas (`proto.tsx`, tablas, filtros) no cambiaron.

## Diferencias con el prototipo (y con el kit)

- **Rutas en inglés y permisos Ver / Gestionar**, por tus decisiones vigentes. El kit pide `/crm/oportunidades` y seis acciones por recurso.
- **Números con año** (`LD-2026-0001`, `OP-2026-0001`), como el resto de tus documentos; el prototipo usa `LD-00001`.
- **Puntaje:** la columna y el dato de la ficha se muestran con "—". El puntaje automático llega con la pantalla Puntuación de leads.
- **Campaña** en el formulario del lead: el campo está, deshabilitado, hasta que exista Campañas.
- **Actividades** en la ficha de oportunidad: la tarjeta y el botón Agregar están; el botón avisa que aún no está disponible.
- **Convertir pide además `core.manage`** para crear una organización o agregar el rol Cliente. Ventas no lo tenía; se le dio después (ver "Segunda parte").
- **Al convertir se puede buscar cualquier identidad**, no solo las coincidencias por nombre: así un cliente registrado con otro nombre se reutiliza en vez de duplicarse.
- **Textos de la conversión:** "identidad", "organización" y "cliente" en lugar de Party, Organization y Customer. Se quitaron la lista decorativa de pasos y los códigos de regla (S10-R-008…).
- **"Mover a…" también en las tarjetas de leads** (el prototipo lo tiene solo en oportunidades): en teléfono no se puede arrastrar.
- **Filtro "Etapa"** en la tabla del pipeline y botón **Editar** en las dos fichas: el prototipo no los tiene.
- **Tarjetas movibles** en las fichas e Historial en tarjeta propia, como en tus demás fichas.
- **Tablero:** muestra hasta 200 registros; si hay más, lo avisa y se acota con los filtros.
- **Monto de la oportunidad solo en soles**, como el prototipo.
- **Crear una organización al convertir exige RUC** (como el prototipo). Un prospecto que es persona natural se registra antes en Clientes y actores y se reutiliza.
- No se adoptó del kit: punto único de transiciones, bandeja de eventos, identificadores de prueba en pantalla, etiquetas (`crm_tags`).

## Base de datos

Migración `2026_10_08_000400_crm_leads_and_pipeline`. **Ya está corrida en la base local**, con el usuario dueño. Solo añade; no borra datos.

- `crm_leads`: número, cargo, interés, motivo de descarte, sede, versión del registro; estado nuevo "Contactado". **La base rechaza un lead descartado sin motivo.**
- `crm_opportunities`: número, evidencia de cierre, quién la ganó, sede, versión del registro. **La base garantiza una sola oportunidad por lead** y rechaza una ganada sin evidencia o una perdida sin motivo.
- `crm_conversions`: identidad y modo (creada / reutilizada); **una sola conversión por oportunidad**; admite oportunidades que no nacen de un lead. **La aplicación solo puede añadir y leer** conversiones, no corregirlas ni borrarlas.
- Permisos: `crm.view` / `crm.manage` renombrados a `leads.view` / `leads.manage`; nuevos `opportunities.view` / `opportunities.manage`; el catálogo de módulos apunta a los nuevos.
- `role_scopes`: admite `leads` y `opportunities`; alcances iniciales según la tabla de arriba.

`database/schema_reference.sql` no incluye esta migración (igual que las anteriores).

## Cómo se probó

`php artisan test`: **101 pruebas pasan** (84 anteriores + 17 nuevas en `tests/Feature/CrmTest.php`):

1. Lead: datos obligatorios, correlativo, correo repetido, edición, estados en orden, descartar con motivo, cerrado no se toca, historial, indicadores, búsqueda, orden y filtro por columna.
2. La base rechaza un lead descartado sin motivo.
3. Un lead de una empresa ya registrada queda enlazado y la conversión ofrece reutilizarla.
4. Calificar crea una sola oportunidad (por pantalla y escribiendo en la base).
5. Oportunidad: alta, etapas con su probabilidad, edición, indicadores, ganar con evidencia, perder con motivo, cerrada no se toca.
6. La base rechaza una ganada sin evidencia y una perdida sin motivo.
7. Conversión creando: nacen organización, cliente y contacto; queda registrada con quién y cómo; repetirla no crea otro cliente; el registro no se puede modificar ni borrar.
8. Conversión reutilizando: un RUC existente no se duplica; se agrega el rol Cliente a la misma identidad.
9. Sin `core.manage` no se crea ni se agrega rol; sí se convierte con un cliente existente.
10. Alcance Propios: cada vendedor ve lo suyo (listado, indicadores, filtros, vista del cliente); lo ajeno responde 404 en ficha y acciones; rol sin alcance = lo propio; Gerencia ve todo.
11. Alcance Equipo y Sede: se ve lo del equipo o de la sede; lead y oportunidad nacen con su sede.
12. Cada acción pide su permiso; gestionar leads no alcanza para crear oportunidades; la ruta vieja de convertir ya no existe.
13. Sin el módulo CRM todo el CRM responde 403 y Cotizaciones sigue funcionando.
14. Leads y oportunidades de otra empresa no existen; el correlativo es por empresa.
15. Ediciones simultáneas: la segunda pestaña recibe el aviso de conflicto y no cambia nada.
16. Cotización desde la oportunidad: mismo cliente, no perdida, alcanzable; queda enlazada y contada.
17. La matriz de roles ofrece los permisos y alcances del CRM, y ampliar el alcance surte efecto al instante.

Se ajustaron cuatro pruebas existentes al cambio de nombre de los permisos y a los dos recursos nuevos con alcance; ninguna comprobación se quitó.

`tsc` y `eslint` sin errores.

En el navegador (`app.localhost:3000`):

- **Ventas** (recorrido completo): menú CRM; crear leads con sus validaciones y el correo repetido; lead de "Actor de Prueba S.A.C." enlazado ("En CORE"); tabla con "/", Escape, orden, filtros de arriba, filtro por columna, limpiar, exportar CSV (3 filas, con el orden puesto) y "Alcance: Propios"; tablero con "Mover a…", arrastre entre columnas, transición no permitida, descartar con motivo; ficha con sus botones, edición e historial; calificar y crear oportunidad; ficha de oportunidad con cambio de etapa, edición, ganar con evidencia y la conversión que se abre sola; convertir reutilizando; pipeline en tablero (arrastrar de etapa, perder con motivo, ganar → conversión) y en tabla (filtro Etapa, orden por monto, clic en la fila); **candado al crear organización** y rechazo al intentar dar el rol Cliente a un proveedor; cotizar desde la oportunidad hasta guardar el borrador y volver por el enlace de Origen.
- **Gerencia:** ve los leads de toda la empresa (incluido el de septiembre); "Nuevo lead", "Editar" y "Descartar" con candado y su mensaje; en el tablero de leads no arrastra; califica el lead de Ventas y la oportunidad queda a nombre de Ventas.
- **Administrador:** convertir creando (campos obligatorios, RUC existente → se selecciona para reutilizar, RUC nuevo → cliente creado); Roles y permisos con las filas Leads y Oportunidades y su alcance en los tres roles; pestaña CRM de la ficha de "Actor de Prueba S.A.C." con su oportunidad y su lead, y clic a la ficha.
- **Claro y oscuro:** listado de leads (oscuro y claro), tablero de leads (oscuro y claro), ficha de oportunidad (oscuro y claro) y tablero del pipeline (claro), con captura.
- **375 px:** leads (tabla en tarjetas y tablero con desplazamiento lateral), ficha de lead, pipeline (tablero y tabla), ficha de oportunidad y los modales de lead, oportunidad y conversión: sin desborde horizontal (medido), con captura de leads y del modal de conversión.

### Lo que no se probó

- **Arrastrar con el ratón de verdad:** la ventana de pruebas se ocultaba y el arrastre se envió por código (los mismos eventos que emite el navegador). Conviene que lo pruebes tú una vez.
- **En oscuro, con los ojos:** el tablero del pipeline y los modales. Usan las mismas variables de color que lo que sí se vio.
- **Excel:** se probó el CSV; el Excel usa la misma pieza que los demás listados.
- **Alcances Equipo y Sede en el navegador:** están en las pruebas automáticas (solo hay un usuario Ventas).
- **Gerencia y Administrador a 375 px** (las pantallas son las mismas que con Ventas).

### Datos de prueba que quedaron en "Tenant A - Prueba"

- Leads `LD-2026-0002` (Agroindustrias del Sur SAC, convertido), `LD-2026-0003` (actor de prueba s.a.c., convertido), `LD-2026-0004` (Textiles Lima EIRL, descartado).
- Oportunidades `OP-2026-0001` (ganada y convertida, con la cotización `COT-2026-0010` en borrador), `OP-2026-0002` (perdida), `OP-2026-0003` (ganada y convertida), `OP-2026-0004` (abierta).
- La organización **Minera Prueba CRM S.A.C.** (RUC 20601234561), creada al convertir `OP-2026-0003`.
- El embudo "Ventas" con sus cuatro etapas y las siete fuentes.

## Segunda parte (2026-10-08): Ventas puede crear el cliente al convertir

Pediste darle a Ventas el permiso necesario. Recibió **`core.manage`** (Gestionar Clientes y actores), como en el prototipo.

- Migración `2026_10_08_000500_let_sales_manage_identities`. **Ya corrida en la base local.** Solo añade; alcanza a los roles Ventas que ya gestionan oportunidades (hoy, el de "Tenant A - Prueba").
- **Qué más le da ese permiso:** no es solo la conversión. Ventas ahora también puede crear, editar, importar e inactivar identidades en Clientes y actores, y gestionar sus contactos y columnas personalizadas. No existe un permiso más fino para "solo crear clientes"; si lo quieres, hay que crearlo.
- La regla no cambió: un rol sin `core.manage` sigue sin poder crear identidades desde el CRM (la prueba automática sigue en pie).

Probado: las 101 pruebas pasan. En el navegador, como Ventas: en Clientes y actores, "Nueva organización", "Nueva persona" e "Importar" ya no llevan candado; en la conversión, "Crear organización + cliente" sale habilitado con sus campos (se abrió simulando en el navegador que la oportunidad abierta estaba ganada; no se guardó ni se cambió ningún dato).

**No se probó:** que Ventas complete una conversión creando el cliente (no hay una oportunidad suya ganada sin convertir; la misma operación se probó completa como Administrador y está en las pruebas automáticas).

## Pendiente

- **Siguiente tanda del CRM:** Actividades, Campañas y Puntuación de leads (siguen en "próximamente").
- Solicitudes del portal por la ruta CRM (que una solicitud se vuelva oportunidad).
- Pantalla para editar etapas del embudo y fuentes (hoy son las del prototipo).
- Importar leads desde Excel (el prototipo lo tiene en Importación masiva).
- Dos movimientos del mismo registro en el mismo segundo pueden salir en otro orden en el historial (la auditoría guarda la hora al segundo; ya pasaba en las demás fichas).
- El nombre del archivo exportado usa la fecha UTC (de noche en Perú sale el día siguiente), como en los demás listados.
