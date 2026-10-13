# Decisiones y pendientes

Estado al 2026-10-13 (con el CRM completo, la pantalla Auditoría y Operaciones salvo la App del técnico).

## Decisiones tomadas

### Kit de desarrollo (2026-10-08)
- El kit (`kit/`, fuera del repositorio) es una **guía**: no se cumple tal cual. Se mantiene lo construido y se toma lo que suma. Comparación en [comparacion-con-el-kit.md](comparacion-con-el-kit.md).
- Lo que choca con decisiones ya tomadas **se queda como está**: colores del logo, rutas en inglés, código al correo, módulo fuera de plan con candado, tarjetas movibles, orden en página, precio de catálogo en soles, registro público de empresas, landing en `/`, planes con suscripciones, una orden por contrato.
- Orden de trabajo: 1) riesgos de hoy, 2) alcance en permisos y protecciones (envíos repetidos, ediciones simultáneas), 3) pantallas que faltan de fundaciones.
- Todo cambio debe buscar la protección del sistema.

### Protecciones (2026-10-08)
- Toda tabla con datos de una empresa lleva su regla de aislamiento en la base, también las que cuelgan de otra (`persons`, `organizations`).
- La auditoría es de solo alta y lectura para la aplicación; el catálogo de permisos, de solo lectura.
- Un permiso solo vale si la empresa tiene su módulo: lo comprueba la API (`CheckPermission`), no solo el menú.
- El alta de identidades es solo por `/parties`. Las altas de `/customers`, `/suppliers` y `/employees` están desactivadas.
- Contraseñas con Argon2id; cambiar o restablecer la contraseña cierra las otras sesiones; sin acceso vigente a una empresa no hay sesión.
- Lo que la base puede garantizar (una cotización, un contrato) se garantiza en la base, además de en el código.
- Ediciones simultáneas: los registros editables llevan `lock_version`; la web envía `If-Match` y la API responde 409 si otra persona lo cambió (`CheckRecordVersion`). Toda tabla nueva que se edite desde una ficha debe llevar `lock_version` y su entrada en ese control.
- Envíos repetidos: las altas y conversiones llevan `Idempotency-Key` (`Idempotency`); lo pone solo el cliente `lib/api.ts`.

### Alcance en los permisos (2026-10-08)
- Un rol define qué puede hacer (permisos) y qué registros alcanza (alcance): **Propios, Equipo, Sede o Toda la empresa**. Se elige por rol y por recurso en Roles y permisos. Detalle en [cambios/2026-10-08_alcance-en-permisos.md](cambios/2026-10-08_alcance-en-permisos.md).
- Aplica a Cotizaciones, Contratos, Órdenes, Solicitudes del portal, Leads, Oportunidades, Actividades, Servicios contratados, Órdenes de trabajo y, desde el 2026-10-13, Proyectos, Horas y Costos. En los demás recursos, quien tiene el permiso ve toda la empresa.
- Fuera de alcance = "no existe" (404), igual que un registro de otra empresa.
- Reparto: Ventas, lo propio (la bandeja de solicitudes, completa); Gerencia y Administrador, toda la empresa. El Administrador no se recorta.
- Rol nuevo o sin alcance definido: solo lo propio. Sin equipo o sin sede asignados, el alcance se queda en lo propio. Un documento sin sede no se abre a todas las sedes.
- Un contrato o una orden también es "propio" de quien hizo la cotización de origen. El documento nace en la sede de quien lo crea y contrato y orden heredan la de la cotización.
- Una solicitud del portal sin tomar la ve quien pueda atenderla; quien la toma, cotiza o rechaza queda como responsable.
- El filtro lo aplican solos los modelos (`ScopedByAccess`), no cada controlador. Todo modelo nuevo que deba respetar el alcance usa ese trait, declara su recurso y se añade a `AccessScope::RESOURCES` y al CHECK de `role_scopes`.
- Sedes y equipos se gestionan en Usuarios (pestañas); no se borran, se desactivan. Un equipo por persona. **Una sede principal y, si hace falta, sedes adicionales** (2026-10-08, [cambios/2026-10-08_sedes-adicionales.md](cambios/2026-10-08_sedes-adicionales.md)): lo que la persona crea nace en la principal; las adicionales solo amplían lo que ve con el alcance "Sede" y requieren tener principal.
- En Contratos y Órdenes, **Crear** es un permiso aparte de Gestionar (`contracts.create`, `orders.create`): Ventas genera el contrato y la orden de su cotización aprobada, pero no los edita ni les cambia el estado. Quien gestionaba conserva crear.
- La vista del cliente entrega cada parte (comprobantes y saldo, tickets, órdenes de trabajo, leads) solo con el permiso de ver de su módulo y si la empresa lo tiene; la pestaña sin permiso no se esconde, muestra el candado. Para decidirlo dentro de una pantalla se usa `CheckPermission::granted`.

### CRM (2026-10-08)
- Se construyó por partes: Leads y Pipeline de oportunidades ([cambios/2026-10-08_crm-leads-y-pipeline.md](cambios/2026-10-08_crm-leads-y-pipeline.md)) y, el 2026-10-09, Actividades, Campañas y Puntuación de leads ([cambios/2026-10-09_crm-actividades-campanas-puntuacion.md](cambios/2026-10-09_crm-actividades-campanas-puntuacion.md)).
- Permisos por recurso con Ver y Gestionar (`leads.*`, `opportunities.*`), como en Comercial; reemplazan a `crm.*`. Calificar un lead y crear su oportunidad pide `opportunities.manage`.
- Leads y Oportunidades respetan el alcance del rol. "Propio" es lo que la persona lleva como responsable. Ventas, lo propio; Gerencia y Administrador, toda la empresa.
- El cliente no nace del lead: se crea o se reutiliza al **convertir la oportunidad ganada**, con el mismo control de duplicados de Clientes y actores. Dar de alta la organización o agregarle el rol Cliente pide además `core.manage`; convertir con un cliente que ya existe, no. Ventas tiene `core.manage` (lo pediste el 2026-10-08), así que también gestiona Clientes y actores.
- En Clientes y actores, **Eliminar** es un permiso aparte de Gestionar (`core.delete`): eliminar datos de contacto y columnas personalizadas. Como en el prototipo, Ventas crea y edita identidades pero no elimina. Quien gestionaba conserva eliminar.
- La ruta que convertía un lead directo en cliente está desconectada (su código sigue en el controlador).
- Lo que la base garantiza: una oportunidad por lead, una conversión por oportunidad, descartar con motivo, ganar con evidencia, perder con motivo. El registro de conversiones es de solo alta y lectura para la aplicación.
- La oportunidad sigue siendo de quien llevaba el lead, la cree quien la cree. Lead y oportunidad nacen en la sede de su responsable.
- Embudo y fuentes: los del prototipo, creados la primera vez que la empresa entra al CRM. Monto de la oportunidad solo en soles.
- Tablero (kanban): pieza compartida `components/ui/kanban.tsx`; cada tarjeta trae "Mover a…" porque en teléfono no se arrastra. La vista elegida (tabla o tablero) se recuerda en el navegador.
- **Actividades** (2026-10-09): se atan a un lead o a una oportunidad (no a los dos) y llevan alcance por rol; "propio" es lo que la persona registró y lo de los leads y oportunidades que lleva. Se editan mientras están pendientes; una realizada se reabre antes. No se eliminan. "Hoy" en sus indicadores y en su tablero es el día de quien mira.
- **Campañas** (2026-10-09): quien las ve, ve todas; sus miembros y resultados se cuentan sobre los leads y oportunidades que el rol alcanza. Cancelar pide motivo. El consentimiento se registra por miembro y la campaña decide si lo exige (por defecto, sí). Excluir no borra. Un lead conserva su primera campaña como campaña de origen.
- **Puntuación de leads** (2026-10-09): criterios fijos (los del prototipo, en `App\Modules\Crm\Support\LeadScoring`); cada empresa cambia solo los puntos (−50 a 50). Se recalcula al crear o editar el lead, al registrarle una actividad, al asociarlo o excluirlo de una campaña y al guardar las reglas. Recalcular no cuenta como una edición del lead. Cambiar las reglas es un permiso propio (`scoring.manage`): en el prototipo lo tenía Ventas con "editar leads"; aquí solo el Administrador.
- Permisos nuevos del módulo CRM: `activities.*` y `campaigns.*` (Ver, Gestionar, Exportar) y `scoring.manage`. Reparto del prototipo: Gerencia ve actividades y ve y exporta campañas; Ventas gestiona sus actividades y ve campañas.

### Operaciones (2026-10-12)
- Se construye por partes, en el orden del kit. Hecho: Servicios contratados y Órdenes de trabajo ([cambios/2026-10-12_operaciones-servicios-contratados-y-ot.md](cambios/2026-10-12_operaciones-servicios-contratados-y-ot.md)) y Proyectos y Horas y costos ([cambios/2026-10-13_operaciones-proyectos-horas-y-costos.md](cambios/2026-10-13_operaciones-proyectos-horas-y-costos.md)). Hecha también Agenda. Falta la App del técnico.
- Permisos por recurso: `contracted_services.*` (Ver, Gestionar, Exportar) y `work_orders.*` (Ver, Crear, Gestionar, Aprobar, Exportar). Los dos cuelgan del módulo Órdenes de trabajo del plan. El `operations.*` anterior queda para tareas, horas, recursos y plantillas, que aún no tienen pantalla.
- **Asignar el técnico y revisar el checklist** es `work_orders.approve`, aparte de ejecutar (`work_orders.manage`): el técnico ejecuta, no se reasigna ni se aprueba. Asignar al crear la orden pide ese mismo permiso.
- **Técnico** no es un rol fijo: se puede asignar una orden a cualquier persona con acceso vigente cuyo rol tenga `work_orders.manage`.
- Alcance: una orden de trabajo es "propia" de quien la tiene asignada y de quien la creó; un servicio contratado, de su responsable y de quien vendió su contrato. El servicio hereda responsable y sede del contrato; la orden, la sede de su servicio.
- Una orden de trabajo nace siempre de un servicio contratado activo (más adelante, también de un ticket). No se crea con un cliente suelto.
- El técnico no recibe dos órdenes que se crucen: asignar, crear con técnico y mover la fecha validan su agenda contra sus órdenes asignadas o en ejecución de toda la empresa; una orden sin hora de fin ocupa dos horas. El choque bloquea.
- El checklist de una orden es una copia de la plantilla elegida al crearla. Completar la orden exige sus ítems obligatorios. Con ellos marcados queda Completado y se aprueba o se devuelve (con motivo); aprobado, no cambia. **Quien ejecuta la orden no aprueba su checklist**, salvo que la empresa desactive la segregación de funciones (el mismo ajuste de las cotizaciones).
- Un servicio contratado solo se activa o reactiva con su contrato vigente, y no se completa ni se cancela con órdenes de trabajo abiertas. Activar uno recurrente crea su instancia recurrente una sola vez; la instancia sigue al servicio.
- Lo que la base garantiza: motivo en suspender, bloquear y cancelar; técnico en una orden asignada o en ejecución; una instancia recurrente por servicio; los ítems de un checklist no se borran.
- La ficha del servicio contratado es una ventana sobre el listado; la de la orden, una página con tarjetas movibles.
- Quien no puede abrir la pantalla de inicio (Clientes y actores) va a la primera pantalla del menú que su rol sí abre.
- **Proyectos** (2026-10-13): nacen de un servicio contratado activo; su ingreso es lo pactado en ese servicio. Cerrar exige tareas cerradas y entregables aprobados, y es un permiso aparte (`projects.approve`). El margen usa solo horas y costos aprobados, y es un dato del proyecto: quien lo ve, ve su margen aunque no alcance cada registro.
- **Trabajar las tareas** (`tasks.manage`) es un permiso aparte de gestionar el proyecto (`projects.manage`): quien ejecuta no pausa, cancela ni cambia el presupuesto. Las tareas se mueven solo con el proyecto en ejecución; un control obligatorio impide darla por hecha.
- **Entregables:** sin documento no pasan a revisión. Mientras no exista Documentos, el documento es un enlace o referencia escrita. Gestionar y revisar son permisos distintos.
- **Horas:** cada quien registra las suyas; no se solapan; un registro dura como mucho 12 horas; **nadie aprueba sus propias horas, sin excepción**. No se editan ni se borran: rechazadas, se registran de nuevo. Toman el costo por hora de la persona (Usuarios) al registrarse.
- **Costos:** siempre de un proyecto. Quien lo registró no lo aprueba, salvo que la empresa desactive la segregación de funciones.
- Alcance: un proyecto es "propio" de quien lo lleva, de quien lo creó y de quien tiene una tarea suya; las horas, de quien las trabajó; un costo, de quien lo registró.
- Módulos del plan: proyectos, tareas y entregables en Proyectos; horas y costos en Timesheets.
- **Ficha del proyecto** (2026-10-13, [cambios/2026-10-13_proyecto-ficha-con-pestanas.md](cambios/2026-10-13_proyecto-ficha-con-pestanas.md)): sigue la forma del prototipo, con cinco indicadores y pestañas a todo el ancho; las tarjetas movibles quedan solo dentro de "Resumen y estados". El listado conserva tablero y tabla. Regla general: la disposición del prototipo manda y las reglas propias (tarjetas movibles, tabla) se aplican encima sin cambiarla.
- **Archivo del entregable** (2026-10-13): el documento de un entregable es un archivo subido, con versiones que no se destruyen; lo guarda `App\Modules\Documents\Support\DocumentStore` (lista cerrada de tipos, huella comprobada, carpeta por empresa, descarga con enlace temporal). Adjuntar pide `deliverables.manage` y descargar `deliverables.view`, no los permisos de Documentos. Sin antivirus conectado, el archivo queda "sin analizar" y así se muestra. Todo archivo nuevo que se adjunte a un registro debe pasar por esa pieza.
- **Agenda** (2026-10-13, [cambios/2026-10-13_operaciones-agenda.md](cambios/2026-10-13_operaciones-agenda.md)): vista semanal del prototipo y, encima, vista de mes, filtro por persona (por la API), cancelados ocultos y un color por persona (nunca verde, rojo ni ámbar, que son estados); sin tabla ni exportar. Las órdenes de trabajo programadas se leen de sus propias órdenes, no se copian. Un cruce de horario **avisa** al agendar un evento (se puede guardar igual y queda en auditoría) y **bloquea** al asignar una orden de trabajo. Cancelar pide motivo; los eventos no se borran. Permisos `agenda.view`, `agenda.create` y `agenda.manage` bajo el módulo Agenda del plan; un evento es "propio" de su responsable y de quien lo agendó. El aviso de cruce no dice con qué choca si quien agenda no puede verlo.
- En un listado con tablero, pulsar la vista que ya está activa no hace nada (antes vaciaba la lista).

### Auditoría (2026-10-08)
- Pantalla de solo lectura en Control → Auditoría (`/audit`). Detalle en [cambios/2026-10-08_auditoria.md](cambios/2026-10-08_auditoria.md).
- Permisos `audit.view` y `audit.export`, sin módulo del plan. Como en el prototipo, los tienen Administrador y Gerencia.
- **La auditoría no tiene alcance:** quien tiene `audit.view` ve los movimientos de toda la empresa y de todos los módulos, también los que su rol no abre. Es un permiso para pocos.
- Los textos de acciones y entidades viven en `App\Services\AuditCatalog`: una acción o entidad nueva que se registre debe añadirse ahí (si no, se muestra con su código).
- La API nunca entrega el valor de una clave con nombre de secreto, aunque un registro lo hubiera guardado.
- El detalle se abre en una ventana sobre el listado (no en una página ni en el panel lateral del prototipo), para no perder los filtros.

### Menú lateral
- `MENU_ITEMS` (en `apps/web/src/components/layout/AppShell.tsx`) replica los grupos y el orden del prototipo.
- Las pantallas que aún no existen van con `soon: true`: se ven atenuadas y no navegan. En modo íconos la barra muestra un ícono por módulo (los mismos que desplegada) y al pulsarlo abre un panel con sus pantallas (2026-10-06). Al construir una se cambia `soon` por `href` + `module` + `requiredPermission`.
- Los grupos que cada persona abre o cierra se recuerdan en su navegador (2026-10-08); un grupo que nunca tocó arranca abierto si tiene alguna pantalla construida y plegado si no.
- **Clientes y actores** (`/actors`) reemplaza a Clientes, Proveedores y Empleados desde el 2026-10-08. Los tres están **desconectados, no eliminados**: no salen en el menú y sus rutas llevan a `/actors`, pero sus pantallas, componentes y API siguen en el repo. Sin botón Asignar. El inicio de la empresa es `/actors` y el buscador de la cabecera busca ahí.
- "Servicios" pasó a llamarse "Catálogo de servicios", como en el prototipo (2026-10-05).
- Módulo fuera del plan → candado "Plan". Módulo en el plan pero sin permiso → oculto. Los datos vienen de `GET /v1/me/access`.

- En modo oscuro la barra baja de tono (mismo azul, más profundo) y la pantalla activa se pinta con el azul vivo del logo; en claro no cambia. Sus colores son las variables `--side-…` de `globals.css` (2026-10-12).

### Módulos y permisos
- Solo CLIENTES, PROVEEDORES y EMPLEADOS son obligatorios (`is_core`) y comparten el permiso `core`. El resto sigue al plan.
- La relación módulo → permisos vive en `module_catalog.permission_prefixes`.
- Aprobar o rechazar cotizaciones es un permiso propio, `quotations.approve` (2026-10-05). Los permisos de un módulo son todos los de la tabla `permissions` que empiezan con su prefijo, no solo `view` y `manage`.
- La matriz de "Roles y permisos" muestra las acciones que existen en la tabla `permissions` (Ver, Crear, Gestionar, Aprobar, Exportar, Eliminar, según el recurso), no las seis del prototipo en todos; desde el 2026-10-08 tiene la columna Alcance. Ver y editar la matriz depende de `users.view` / `users.manage`. El rol Administrador no se recorta.
- Un ítem del menú sin `module` no depende del plan (pantallas de administración de la empresa).
- **Exportar es un permiso aparte de ver** (`<recurso>.export`, 2026-10-08) en los nueve listados que se exportan; lo comprueba la API, cada exportación queda en auditoría y ningún listado entrega más de 200 filas por página. Ventas no exporta; quien veía un listado conserva exportarlo. Monedas y tipo de cambio queda fuera. Un listado nuevo: su permiso por migración, su entrada en `ListExport::RESOURCES`, `permission` en `ExportButtons` / `SelectionBar` y `recordExport` antes de generar el archivo. Detalle en [cambios/2026-10-08_permiso-de-exportar.md](cambios/2026-10-08_permiso-de-exportar.md).
- Los botones (`Btn` de `proto.tsx`) no usan las clases `.app-surface` / `.app-muted` para su fondo y su texto: esas clases ganan a las de "al pasar el cursor" y el botón se queda sin reacción (corregido el 2026-10-08 en los secundarios y fantasma).
- Una acción que el rol no permite no se esconde: el botón aparece deshabilitado, con candado y "Tu rol no tiene permiso …" al pasar el cursor (`Btn permission="…"` y `LinkBtn` en `proto.tsx`). Lo que sí se oculta es la pantalla entera en el menú.
- Usuarios: un rol por usuario; desactivar es quitar el acceso a la empresa (`tenant_users.status`), no borrar la cuenta. El usuario nuevo no recibe contraseña: la crea con un código por correo. La cuenta se vincula a un empleado existente (`employees.user_id`), no se crea el empleado.
- Orden directa: una cotización aprobada se convierte en orden sin contrato, una sola vez, si la empresa lo permite (`tenants.configuration.direct_order`, activo si no se indica). Los permisos `orders.*` vienen con el módulo Contratos.
- Contratos: nacen solo desde una cotización aprobada, vigentes desde mañana por un año. En borrador se editan vigencia, SLA y condiciones (no las líneas). Activar pide `contracts.approve`. El SLA se guarda en el contrato.
- Órdenes: secuencia lineal Borrador → Confirmada → En ejecución → Completada; cancelar (con motivo) solo desde Borrador o Confirmada. Todas las transiciones piden `orders.manage` (no hay permiso de aprobar). La orden se abre en una página con tarjetas, no en el panel lateral del prototipo. Crear una orden desde una cotización o un contrato lleva al listado de Órdenes.
- Solicitudes del portal: bandeja sin ficha. Cotizar toma la solicitud y abre la cotización con cliente y servicio; al guardarla la solicitud queda Cotizada. Ver la bandeja pide `portal.view`, rechazar `portal.manage` y cotizar `quotations.manage`; los permisos `portal.*` vienen con el módulo Cotizaciones. Todas van por "ERP directo" hasta que exista el pipeline de CRM.
- Un botón, columna o tarjeta del prototipo cuya función aún no existe se muestra igual y avisa que no está disponible (p. ej. Enviar a firma, Adjuntar documento, Ver oportunidad).
- Clientes y actores: los roles son Cliente, Proveedor, Contacto y Empleado. El rol Contacto es una persona enlazada a una organización ("Contacto de"); no tiene tabla propia, vive en `persons.metadata`, y convive con los datos de contacto (teléfonos, correos, direcciones) de siempre. Una identidad se ve inactiva si se dio de baja o si ninguno de sus roles sigue activo; inactivar o reactivar desde ahí cambia la identidad y todos sus roles. Sus columnas personalizadas son propias (tipo `Party`), aparte de las de los tres módulos. Sector y nivel de SLA van en `organizations.metadata`.
- Los PDF (cotización, contrato, orden) comparten la composición de `lib/document-pdf.ts`; cada documento solo aporta sus datos.
- Segregación de funciones: quien crea una cotización no la aprueba. Es un ajuste por empresa (`tenants.configuration.segregation`), activo si no se indica; rechazar no tiene esa restricción.

### Comercial
- Las pantallas nuevas se arman con las piezas de `apps/web/src/components/ui/proto.tsx` (medidas del prototipo, colores del logo, tema oscuro incluido).
- Verde, rojo y ámbar se usan solo como colores de estado; todo lo demás es el azul del logo.
- Los colores de las pantallas son variables con valor para claro y para oscuro (`--p-…` y `--app-…` en `globals.css`). En lo nuevo no se escriben colores fijos en las clases (salvo el azul de los botones y el blanco sobre él, iguales en los dos temas). El tema se aplica antes de pintar, también en el inicio de sesión; la landing no tiene oscuro (2026-10-08, [cambios/2026-10-08_modo-oscuro.md](cambios/2026-10-08_modo-oscuro.md)).
- Las cotizaciones se emiten en soles o en dólares. En dólares usan el tipo de cambio de venta vigente (el más reciente registrado hasta hoy) y lo guardan; sin tipo de cambio registrado no se puede cotizar en dólares.
- El tipo de cambio se registra a mano en Finanzas → Monedas y tipo de cambio (permiso `billing.manage`); no hay sincronización con SUNAT. Uno por día: registrar otro el mismo día lo corrige.
- Los desplegables con muchas opciones usan `SearchSelect` (`proto.tsx`), con buscador.
- El precio del catálogo siempre está en soles y sin IGV, como en el prototipo: los servicios no tienen moneda propia. La moneda (PEN o USD) se elige en la cotización y el precio se convierte con el tipo de cambio del día; la pantalla "Monedas y tipo de cambio" ya existe (2026-10-05).
- "Afecto a IGV: Sí (18%)" en la ficha del servicio es un texto fijo, igual que en el prototipo (todos los servicios nacen afectos). Se deja así por ahora (2026-10-05).
- Una cotización sin origen registrado (`source_type` vacío) es "Directa".
- El precio de un servicio no se edita: cada cambio es una versión nueva y la anterior se cierra.
- Los textos explicativos que el prototipo pone bajo el título de cada pantalla (flujos tipo "Categoría → Servicio → …") son de la demo y no se traen.
- Cuando el prototipo pide una regla que la API no tenía (activar solo con precio, motivo al rechazar, categoría obligatoria), se implementa la regla del prototipo y se anota en el registro de cambios como lógica que cambió.
- Lo que depende de una pantalla que aún no existe (tipo de cambio, reglas de aprobación, órdenes, portal) queda fuera hasta construir esa pantalla.
- Una nueva versión de cotización recibe un correlativo nuevo, no el sufijo `-v2` del prototipo, para no tocar el generador de correlativos.
- El historial de servicios y cotizaciones se lee de `audit_logs`; no hay tabla de historial aparte.
- Los listados nuevos llevan casillas de selección, barra de exportar, Excel con el formato del de Clientes (en azul del logo) y la paginación completa; todo sale de `proto.tsx` y `lib/export-table.ts`, para que las pantallas siguientes lo reutilicen.
- Las tablas de los listados llevan `tableId` en `DataTable`: cabecera y casillas fijas, columnas que se mueven (la primera no) y se inmovilizan (inmovilizar cualquiera inmoviliza también la primera; liberar la primera libera todas). Se recuerda en el navegador. Las columnas personalizadas son solo de Clientes y actores. Todo listado nuevo debe llevar su `tableId`.
- El orden de un listado lo aplica la API (`?sort=&dir=`, con `App\Services\ListSort`), nunca el navegador sobre la página visible. Cada columna ordenable lleva `sortKey` en la web y su entrada en el controlador. Total y Valor ordenan por el número, sin convertir moneda.
- "Filtrar por esta columna" existe en Clientes y actores, Cotizaciones, Contratos, Órdenes, Servicios y Solicitudes (no en Tipo de cambio ni Usuarios). Lo aplica la API (`?filters=`, con `App\Services\ColumnFilters`) sobre todo el listado; exportar respeta filtros y orden. Un dato tiene un solo filtro: si la columna ya tiene filtro arriba (Estado, Rol, Categoría, Modalidad, Origen), la opción abre ese. Los filtros activos se ven como etiquetas y "Limpiar filtros" quita todos, también los de arriba. La pieza web es `components/dashboard/ColumnFilters.tsx`.
- En una pantalla, lo principal (el listado, la ficha) se pide primero; los indicadores y las listas de los filtros van después con `apiFetchLater`. Una lectura repetida mientras la primera sigue en curso no se envía otra vez (2026-10-12, [cambios/2026-10-12_carga-de-pantallas-crm.md](cambios/2026-10-12_carga-de-pantallas-crm.md)).
- Un listado no pide datos fila por fila: lo que la tabla muestra viaja en la respuesta del listado (p. ej. `custom_values` en `/parties`).
- En todos los listados "/" lleva al buscador y Escape lo borra y luego lo suelta; lo da el buscador compartido (`SearchInput`), no cada pantalla (2026-10-08).
- En todos los listados un clic en la fila abre la ficha. Donde además se edita en la tabla (Clientes y actores), la edición va con el lápiz de la celda o con doble clic.
- En teléfono (hasta 639 px) las tablas se muestran como tarjetas con la regla `.proto-tbl` de `globals.css`: toda tabla nueva debe llevar la clase y `data-label` en cada celda (`DataTable` ya lo hace).
- Las tarjetas de las fichas se pueden reordenar (`SortableBoard` en `components/ui/sortable-cards.tsx`); el orden se guarda en el navegador de cada persona, no en el servidor. Las fichas nuevas deben armarse con él.

### Acceso
- La página pública y el sistema van en dominios distintos (como `midominio.com` y `app.midominio.com`), con un solo proyecto web: `proxy.ts` decide según `SITE_URL` y `APP_URL`. Sin esas variables todo vive en un dominio. En local: `localhost:3000` y `app.localhost:3000`. Falta elegir el dominio real.
- Verificación en dos pasos por **código enviado al correo** (no aplicación autenticadora). Obligatoria para superadmin, rol ADMIN del tenant y usuarios de portal.
- Sesiones de 8 h, o 30 días con "Recordarme". Se permiten varias sesiones a la vez. Cada sesión guarda desde qué navegador se abrió y si pasó la verificación en dos pasos; en Mi perfil la persona ve las suyas y puede cerrar las demás (2026-10-08, [cambios/2026-10-08_mi-perfil.md](cambios/2026-10-08_mi-perfil.md)).
- 5 contraseñas incorrectas bloquean la cuenta 15 minutos.
- El superadmin es `users.is_super_admin` (no un rol), no puede pertenecer a un tenant (triggers) y tiene auditoría propia en `platform_audit_logs`.

## Pendientes

- Operaciones: App del técnico (como aplicación web instalable dentro de este proyecto, no Flutter); rejilla por horas y arrastrar para reprogramar en Agenda; pantalla de plantillas de checklist (hoy solo por la API); antivirus para los archivos adjuntos y pantalla Documentos; facturar por horas (con Finanzas); avisos de horas y costos por aprobar (con Notificaciones); retirar las rutas antiguas `/tasks`; evidencias con archivo (con Documentos); evento facturable al completar (con Finanzas); avisar al técnico al asignar (con Notificaciones); órdenes desde un ticket (con Soporte); que suspender o terminar un contrato arrastre sus servicios contratados.
- CRM: solicitudes del portal por la ruta CRM; pantalla para editar etapas y fuentes; importar leads; asignar una actividad a otra persona; recordatorios de actividades (con Notificaciones); clientes como miembros de campaña; envío real de comunicaciones de campaña.
- Alcance: a qué sede pertenecen los documentos anteriores (todos sin sede; solo importa si se usa el alcance Sede); mostrar la sede en las fichas; los roles base del prototipo no se crean solos en una empresa nueva.
- La cuenta de superadmin sigue siendo miembro de `EMP-5E380F` (Yadi SAC), membresía anterior a los triggers; el sistema la ignora. Falta decidir si se borra.
- CI no corre las migraciones (carga `schema_reference.sql`, desactualizado): las pruebas nuevas fallarían ahí.
- Separar la consola de plataforma en su propio usuario de base; cookie de sesión no legible por el navegador; gestor externo para los secretos de la bóveda; regla de aislamiento en `role_permissions`.
- El drawer de planes (`SaasPlanDrawer.tsx`) tiene su lista de módulos escrita a mano y no coincide con `module_catalog`.
- Auditoría: casi ningún movimiento guarda antes y después (solo el detalle); la entidad se muestra con su identificador, no con su número; falta decidir cuánto tiempo se conserva. El filtro "hoy" usa el día del servidor (UTC), como en los demás listados.
- No hay página para la auditoría de plataforma (`GET /v1/superadmin/audit`), ni comando para crear superadmins, ni contador de notificaciones.
- Mi perfil: las preferencias de notificación se guardan pero aún no cambian nada (falta Notificaciones); no se cierra una sesión concreta, solo todas las demás.
- `php artisan tenants:sync-permissions` da a los tenants ya registrados los permisos de sus módulos. Falta confirmar si se corrió.
- En local, el PHP de XAMPP tiene apagada la caché de código (OPcache): cada llamada tarda unos 0,33 s. Activarla en `C:\xampp\php\php.ini` aceleraría todo el sistema (propuesto el 2026-10-12, sin hacer).
- Correo real: falta configurar SMTP en `apps/api/.env` (hoy `MAIL_MAILER=log`).
- El contenido de Planes SaaS y la landing sigue con el estilo anterior.
- Identidades repetidas: en "Tenant A - Prueba" hay 5 documentos con dos registros cada uno, creados en los módulos separados. Clientes y actores no crea más, pero no une los que existen. Lista en [cambios/2026-10-08_desconectar-clientes-proveedores-empleados.md](cambios/2026-10-08_desconectar-clientes-proveedores-empleados.md).
- Borrar de verdad Clientes, Proveedores y Empleados (pantallas, componentes, `lib/open-record.ts`, `/v1/search`) cuando se decida.
- Comercial tiene todas sus pantallas. Faltan el portal del cliente y Usuarios del portal: hoy las solicitudes solo se crean por la API del portal (las de prueba se insertaron a mano). Lo que Servicios y Cotizaciones aún no tienen respecto al prototipo está en [cambios/2026-10-05_comercial-campos-y-funciones.md](cambios/2026-10-05_comercial-campos-y-funciones.md).
- `database/schema_reference.sql` no incluye las migraciones `2026_10_05_000300` (modalidad, garantía, estado archivado, versión anterior) ni `2026_10_05_000400` (tipo de cambio).
- La fecha de emisión de órdenes usa el día del servidor (UTC): de noche en Perú sale el día siguiente. Falta fijar la zona horaria de la aplicación.
- Una cotización cuyo contrato ya generó una orden deja "Orden directa" deshabilitado; en el prototipo son independientes.
- Dólares: falta llevarlos a contratos, comprobantes y cobros (diferencia de cambio), y una fuente automática del tipo de cambio. Detalle en [cambios/2026-10-05_cotizaciones-dolares-y-buscador.md](cambios/2026-10-05_cotizaciones-dolares-y-buscador.md).
