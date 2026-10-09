# Decisiones y pendientes

Estado al 2026-10-08 (con el flujo principal del CRM).

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
- Aplica a Cotizaciones, Contratos, Órdenes, Solicitudes del portal y, desde que existe el CRM, Leads y Oportunidades. En los demás recursos, quien tiene el permiso ve toda la empresa.
- Fuera de alcance = "no existe" (404), igual que un registro de otra empresa.
- Reparto: Ventas, lo propio (la bandeja de solicitudes, completa); Gerencia y Administrador, toda la empresa. El Administrador no se recorta.
- Rol nuevo o sin alcance definido: solo lo propio. Sin equipo o sin sede asignados, el alcance se queda en lo propio. Un documento sin sede no se abre a todas las sedes.
- Un contrato o una orden también es "propio" de quien hizo la cotización de origen. El documento nace en la sede de quien lo crea y contrato y orden heredan la de la cotización.
- Una solicitud del portal sin tomar la ve quien pueda atenderla; quien la toma, cotiza o rechaza queda como responsable.
- El filtro lo aplican solos los modelos (`ScopedByAccess`), no cada controlador. Todo modelo nuevo que deba respetar el alcance usa ese trait, declara su recurso y se añade a `AccessScope::RESOURCES` y al CHECK de `role_scopes`.
- Sedes y equipos se gestionan en Usuarios (pestañas); no se borran, se desactivan. Una sede y un equipo por persona.
- En Contratos y Órdenes, **Crear** es un permiso aparte de Gestionar (`contracts.create`, `orders.create`): Ventas genera el contrato y la orden de su cotización aprobada, pero no los edita ni les cambia el estado. Quien gestionaba conserva crear.
- La vista del cliente entrega cada parte (comprobantes y saldo, tickets, órdenes de trabajo, leads) solo con el permiso de ver de su módulo y si la empresa lo tiene; la pestaña sin permiso no se esconde, muestra el candado. Para decidirlo dentro de una pantalla se usa `CheckPermission::granted`.

### CRM (2026-10-08)
- Se construye por partes. Hecho: Leads y Pipeline de oportunidades (con ganar, perder y convertir). Faltan Actividades, Campañas y Puntuación de leads. Detalle en [cambios/2026-10-08_crm-leads-y-pipeline.md](cambios/2026-10-08_crm-leads-y-pipeline.md).
- Permisos por recurso con Ver y Gestionar (`leads.*`, `opportunities.*`), como en Comercial; reemplazan a `crm.*`. Calificar un lead y crear su oportunidad pide `opportunities.manage`.
- Leads y Oportunidades respetan el alcance del rol. "Propio" es lo que la persona lleva como responsable. Ventas, lo propio; Gerencia y Administrador, toda la empresa.
- El cliente no nace del lead: se crea o se reutiliza al **convertir la oportunidad ganada**, con el mismo control de duplicados de Clientes y actores. Dar de alta la organización o agregarle el rol Cliente pide además `core.manage`; convertir con un cliente que ya existe, no. Ventas tiene `core.manage` (lo pediste el 2026-10-08), así que también gestiona Clientes y actores.
- En Clientes y actores, **Eliminar** es un permiso aparte de Gestionar (`core.delete`): eliminar datos de contacto y columnas personalizadas. Como en el prototipo, Ventas crea y edita identidades pero no elimina. Quien gestionaba conserva eliminar.
- La ruta que convertía un lead directo en cliente está desconectada (su código sigue en el controlador).
- Lo que la base garantiza: una oportunidad por lead, una conversión por oportunidad, descartar con motivo, ganar con evidencia, perder con motivo. El registro de conversiones es de solo alta y lectura para la aplicación.
- La oportunidad sigue siendo de quien llevaba el lead, la cree quien la cree. Lead y oportunidad nacen en la sede de su responsable.
- Embudo y fuentes: los del prototipo, creados la primera vez que la empresa entra al CRM. Monto de la oportunidad solo en soles.
- Tablero (kanban): pieza compartida `components/ui/kanban.tsx`; cada tarjeta trae "Mover a…" porque en teléfono no se arrastra. La vista elegida (tabla o tablero) se recuerda en el navegador.
- Lo del prototipo que depende de una pantalla que falta se muestra y avisa: Puntaje ("—"), Campaña (deshabilitada), Actividades (botón que avisa).

### Menú lateral
- `MENU_ITEMS` (en `apps/web/src/components/layout/AppShell.tsx`) replica los grupos y el orden del prototipo.
- Las pantallas que aún no existen van con `soon: true`: se ven atenuadas y no navegan. En modo íconos la barra muestra un ícono por módulo (los mismos que desplegada) y al pulsarlo abre un panel con sus pantallas (2026-10-06). Al construir una se cambia `soon` por `href` + `module` + `requiredPermission`.
- **Clientes y actores** (`/actors`) reemplaza a Clientes, Proveedores y Empleados desde el 2026-10-08. Los tres están **desconectados, no eliminados**: no salen en el menú y sus rutas llevan a `/actors`, pero sus pantallas, componentes y API siguen en el repo. Sin botón Asignar. El inicio de la empresa es `/actors` y el buscador de la cabecera busca ahí.
- "Servicios" pasó a llamarse "Catálogo de servicios", como en el prototipo (2026-10-05).
- Módulo fuera del plan → candado "Plan". Módulo en el plan pero sin permiso → oculto. Los datos vienen de `GET /v1/me/access`.

### Módulos y permisos
- Solo CLIENTES, PROVEEDORES y EMPLEADOS son obligatorios (`is_core`) y comparten el permiso `core`. El resto sigue al plan.
- La relación módulo → permisos vive en `module_catalog.permission_prefixes`.
- Aprobar o rechazar cotizaciones es un permiso propio, `quotations.approve` (2026-10-05). Los permisos de un módulo son todos los de la tabla `permissions` que empiezan con su prefijo, no solo `view` y `manage`.
- La matriz de "Roles y permisos" muestra las acciones que existen en la tabla `permissions` (Ver, Crear, Gestionar, Aprobar, Exportar, Eliminar, según el recurso), no las seis del prototipo en todos; desde el 2026-10-08 tiene la columna Alcance. Ver y editar la matriz depende de `users.view` / `users.manage`. El rol Administrador no se recorta.
- Un ítem del menú sin `module` no depende del plan (pantallas de administración de la empresa).
- **Exportar es un permiso aparte de ver** (`<recurso>.export`, 2026-10-08) en los nueve listados que se exportan; lo comprueba la API, cada exportación queda en auditoría y ningún listado entrega más de 200 filas por página. Ventas no exporta; quien veía un listado conserva exportarlo. Monedas y tipo de cambio queda fuera. Un listado nuevo: su permiso por migración, su entrada en `ListExport::RESOURCES`, `permission` en `ExportButtons` / `SelectionBar` y `recordExport` antes de generar el archivo. Detalle en [cambios/2026-10-08_permiso-de-exportar.md](cambios/2026-10-08_permiso-de-exportar.md).
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

- CRM: Actividades, Campañas y Puntuación de leads; solicitudes del portal por la ruta CRM; pantalla para editar etapas y fuentes; importar leads.
- Alcance: a qué sede pertenecen los documentos anteriores (todos sin sede; solo importa si se usa el alcance Sede); mostrar la sede en las fichas; los roles base del prototipo no se crean solos en una empresa nueva.
- La cuenta de superadmin sigue siendo miembro de `EMP-5E380F` (Yadi SAC), membresía anterior a los triggers; el sistema la ignora. Falta decidir si se borra.
- CI no corre las migraciones (carga `schema_reference.sql`, desactualizado): las pruebas nuevas fallarían ahí.
- Separar la consola de plataforma en su propio usuario de base; cookie de sesión no legible por el navegador; gestor externo para los secretos de la bóveda; regla de aislamiento en `role_permissions`.
- El drawer de planes (`SaasPlanDrawer.tsx`) tiene su lista de módulos escrita a mano y no coincide con `module_catalog`.
- No hay página para la auditoría de plataforma (`GET /v1/superadmin/audit`), ni comando para crear superadmins, ni contador de notificaciones.
- Mi perfil: las preferencias de notificación se guardan pero aún no cambian nada (falta Notificaciones); no se cierra una sesión concreta, solo todas las demás.
- `php artisan tenants:sync-permissions` da a los tenants ya registrados los permisos de sus módulos. Falta confirmar si se corrió.
- Correo real: falta configurar SMTP en `apps/api/.env` (hoy `MAIL_MAILER=log`).
- El contenido de Planes SaaS y la landing sigue con el estilo anterior.
- Identidades repetidas: en "Tenant A - Prueba" hay 5 documentos con dos registros cada uno, creados en los módulos separados. Clientes y actores no crea más, pero no une los que existen. Lista en [cambios/2026-10-08_desconectar-clientes-proveedores-empleados.md](cambios/2026-10-08_desconectar-clientes-proveedores-empleados.md).
- Borrar de verdad Clientes, Proveedores y Empleados (pantallas, componentes, `lib/open-record.ts`, `/v1/search`) cuando se decida.
- Comercial tiene todas sus pantallas. Faltan el portal del cliente y Usuarios del portal: hoy las solicitudes solo se crean por la API del portal (las de prueba se insertaron a mano). Lo que Servicios y Cotizaciones aún no tienen respecto al prototipo está en [cambios/2026-10-05_comercial-campos-y-funciones.md](cambios/2026-10-05_comercial-campos-y-funciones.md).
- `database/schema_reference.sql` no incluye las migraciones `2026_10_05_000300` (modalidad, garantía, estado archivado, versión anterior) ni `2026_10_05_000400` (tipo de cambio).
- La fecha de emisión de órdenes usa el día del servidor (UTC): de noche en Perú sale el día siguiente. Falta fijar la zona horaria de la aplicación.
- Una cotización cuyo contrato ya generó una orden deja "Orden directa" deshabilitado; en el prototipo son independientes.
- Dólares: falta llevarlos a contratos, comprobantes y cobros (diferencia de cambio), y una fuente automática del tipo de cambio. Detalle en [cambios/2026-10-05_cotizaciones-dolares-y-buscador.md](cambios/2026-10-05_cotizaciones-dolares-y-buscador.md).
