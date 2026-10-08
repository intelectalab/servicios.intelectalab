# 2026-10-07 — Comercial: Clientes y actores (módulo nuevo, de prueba)

Continúa [2026-10-07_solicitudes-portal.md](2026-10-07_solicitudes-portal.md).

## Qué se pidió

Juntar Clientes, Proveedores y Empleados en una sola pantalla, **Clientes y actores**, con las vistas del prototipo pero **sin perder las funciones de tus tablas** (filtrar, agregar columna personalizada, ocultar, mostrar, etc.). Hacerlo como un **módulo nuevo debajo de Empleados**, para verlo antes de decidir, y **sin eliminar los tres módulos actuales**.

## Cómo es en el prototipo

- **Listado:** Nombre (con razón social, cargo o correo debajo), Documento, Roles en CORE, Sector / relación y Estado. Buscador "Buscar por nombre, RUC, DNI o correo" y filtros Rol, Tipo y Estado. Botones Nueva organización y Nueva persona.
- **Formulario:** datos de la organización o de la persona y sus roles. Si la identidad ya existe (mismo RUC o documento) no la duplica: avisa "Ya existe en CORE" y ofrece abrirla o agregarle el rol.
- **Vista 360:** cabecera con Editar, Estado de cuenta y Nueva cotización; pestañas Resumen, Comercial, Operaciones, Finanzas, Soporte, Documentos y CRM. En Resumen: Identidad canónica (con sus estados Activo / Inactivo), Contactos y Situación financiera.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Modules/Core/Http/Controllers/PartyController.php` (nuevo) | **Listado** de identidades con sus roles (buscador por nombre, RUC, documento o correo; filtros por rol, tipo y estado). **Vista 360** (identidad, contactos, condiciones de cliente, situación financiera, cotizaciones, contratos, órdenes de trabajo, comprobantes, tickets, leads y oportunidades, historial). **Alta** de organización o persona con sus roles y sus contactos principales, sin duplicar. **Agregar rol** a una identidad existente. **Editar** (cambia solo lo que llega, así también guarda la edición en celda). **Inactivar / Reactivar**, individual y en bloque. Todo queda en la auditoría. |
| `Modules/Core/Models/Party.php` | Se añadió la relación con el empleado (no cambia nada más). |
| `routes/api.php` | Rutas `/parties`: consultar pide `core.view` y cambiar pide `core.manage`, los mismos permisos de Clientes, Proveedores y Empleados. |
| `tests/Feature/ComercialTest.php` | Prueba nueva: alta con roles y contactos, que son los mismos clientes y proveedores de los módulos de siempre, duplicados, agregar rol, búsqueda y filtros, edición parcial, baja y alta (individual y masiva), permisos y aislamiento entre empresas. |

**Sin migraciones.** Usa las mismas tablas (`parties`, `persons`, `organizations`, `customers`, `suppliers`, `employees`, `contacts`). El sector y el nivel de SLA se guardan en el campo `metadata` que ya tenía la organización.

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/actors/ActorsTable.tsx` (nuevo) | La tabla, con las funciones de las tuyas: ordenar (clic en la cabecera o desde su menú), **inmovilizar** columnas, **moverlas** arrastrando, **filtrar por columna**, **columnas personalizadas** (agregar, ocultar, mostrar las ocultas, eliminar), **edición en la celda** con aviso "cambios guardados" y **Deshacer**, y casillas de selección. El orden, las columnas ocultas y las inmovilizadas se recuerdan en el navegador. |
| `components/dashboard/actors/ActorsList.tsx`, `app/(dashboard)/actors/page.tsx` (nuevos) | La pantalla: botones Nueva organización y Nueva persona, buscador (también con la tecla "/"), filtros Rol, Tipo y Estado, "Más filtros" con el panel de filtros activos, barra de selección (Exportar, Activar, Desactivar), exportar a Excel/CSV y paginación. |
| `components/dashboard/actors/ActorForm.tsx` (nuevo) | Formulario de organización o persona con sus roles y el aviso de duplicados (Abrir / Agregar rol). Sirve también para editar. |
| `components/dashboard/actors/ActorDetail.tsx`, `app/(dashboard)/actors/[id]/page.tsx` (nuevos) | Vista 360 con sus pestañas; el Resumen tiene tarjetas movibles. |
| `lib/actors.ts`, `types/actor.ts` (nuevos) | Etiquetas de roles y estados, y tipos. |
| `components/layout/AppShell.tsx`, `proxy.ts` | Ítem nuevo **Clientes y actores** debajo de Empleados (permiso `core.view`). |
| `lib/commercial.ts` | Dos textos del historial ("Datos editados", "Rol agregado"). |

## Qué NO se tocó

**Clientes, Proveedores y Empleados siguen igual**: sus pantallas, sus tablas, sus cajones, su importación y sus columnas personalizadas. Tampoco Planes SaaS. El módulo nuevo lee y escribe los mismos datos, así que lo que crees en uno se ve en el otro.

## Diferencias con el prototipo

- **Rol Contacto y "Contacto de (organización)":** al principio no se hicieron; se añadieron el 2026-10-08 (ver más abajo).
- **Estado:** en tus módulos se da de baja el rol (el cliente, el proveedor), no la identidad. Aquí una identidad se ve **Inactiva** si se dio de baja o si ninguno de sus roles sigue activo; un rol dado de baja por separado sale atenuado. Inactivar o reactivar desde aquí cambia la identidad **y todos sus roles a la vez**.
- **Columnas personalizadas propias:** las de esta tabla son nuevas e independientes de las que ya tienes en Clientes, Proveedores y Empleados (aquellas siguen en sus pantallas).
- **Tarjeta "Condiciones comerciales (cliente)"** en la vista 360: añadida, para ver días de crédito, límite y moneda. Se editan en Clientes.
- **Responsable** de la identidad: no existe ese dato; no se muestra.
- **Estado de cuenta:** el botón está, pero avisa que aún no está disponible (depende de Finanzas).
- **Pestañas Operaciones, Finanzas, Soporte y CRM:** muestran lo que haya en la base, con el estado tal cual se guarda y sin enlace (esas pantallas no existen). **Documentos** sale siempre vacía: los documentos aún no se enlazan a una identidad.
- Se añadieron, como en tus tablas y los demás listados: casillas, acciones masivas, exportar, paginación y "Más filtros".
- No se trajo la nota explicativa bajo el título.

## Lo que tus tablas tienen y aquí todavía no

- Importar desde Excel: hecho el 2026-10-08, ver [2026-10-08_importar-actores-y-servicios.md](2026-10-08_importar-actores-y-servicios.md).
- **Asignar** (el botón de la barra de selección).
- Editar en la celda las condiciones de cliente (días de crédito, límite, moneda): no son columnas de esta tabla; se editan en la vista 360.
- En teléfono las filas se ven como tarjetas, sin cabeceras: ordenar, mover, inmovilizar y el menú de columnas solo están en pantallas anchas.

## Base de datos

- Sin migraciones.
- Datos de prueba en "Tenant A - Prueba": se creó **Actor de Prueba S.A.C.** (RUC 20999999991, cliente y proveedor, con correo, teléfono y dirección). Se le cambió y deshizo el sector, se inactivó y se reactivó. Se creó y se **eliminó** una columna personalizada "Instagram".

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test`: las 57 pruebas pasan. En el navegador:

1. **Con `ventas@erp-saas.test`** (solo `core.view`): el listado carga los 42 registros existentes con sus roles (los inactivos atenuados); **Nueva organización** y **Nueva persona** salen deshabilitados con "Tu rol no tiene permiso core.manage"; las celdas no se editan y "Agregar columna nueva" sale deshabilitado. Ordenar ascendente, inmovilizar Documento y filtrar por la columna Roles ("Proveedor" → 2 filas) funcionan.
2. **Con `admin@erp-saas.test`:** Nueva organización valida razón social y RUC; con un RUC existente muestra "Ya existe en CORE" con Abrir y "Agregar rol Cliente"; con uno nuevo crea la identidad y abre su vista 360.
3. **Vista 360:** identidad, roles, contactos, condiciones de cliente, situación financiera, historial, el aviso "Una sola identidad, dos roles" y las pestañas con sus contadores.
4. **Edición en celda:** cambiar el sector muestra "cambios guardados" y **Deshacer** lo restaura. Cambiar Estado a Inactivo desde la celda atenúa los roles.
5. **Columna personalizada:** agregar "Instagram", escribir un valor en la celda, ocultarla, volver a mostrarla desde el "+", y eliminarla.
6. **Masivo:** seleccionar el registro inactivo → Activar → confirmación → queda Activo.
7. **Exportar:** CSV con cabeceras y las 43 filas.
8. **375 px:** listado y vista 360 sin desplazamiento horizontal, filas como tarjetas.

No se probó en el navegador: crear una persona y el rol Empleado (sí en las pruebas automáticas), editar con el formulario, "Agregar rol" desde el aviso de duplicado, mover columnas arrastrando, editar nombre y documento en la celda, Inactivar desde la vista 360, el Excel (solo el CSV) y el tema claro.

## Ajustes tras la primera revisión (2026-10-08)

Lo que pediste al verlo:

- **Contactos en la vista 360:** botón **Agregar contacto** y, en cada contacto, **editar** y **eliminar** (con confirmación). El formulario tiene nombre, tipo, prefijo y número (o correo, dirección) y "Contacto principal". Son los mismos contactos de Clientes, Proveedores y Empleados. Para editar hizo falta una ruta nueva, `PUT /contacts/{id}` (`ContactController::update`, solo añade).
- **Condiciones comerciales:** botón **Editar condiciones** en su tarjeta (días de crédito, límite y moneda). Guarda en el mismo cliente que ves en Clientes.
- **La cabecera de la tabla se montaba sobre el panel del menú replegado:** la tabla ahora queda en su propia capa y el panel sale por encima.
- **Las casillas se pegaban a la línea al achicar la ventana:** la columna de casillas tiene ancho fijo y la casilla va centrada.

Archivos: `components/dashboard/actors/ActorModals.tsx` (nuevo), `ActorDetail.tsx`, `ActorsTable.tsx`, `ActorsList.tsx`; API `ContactController.php`, `routes/api.php` y la prueba.

Probado en el navegador con admin sobre "Actor de Prueba S.A.C.": agregar un contacto (valida el dato obligatorio), editarlo, eliminarlo; cambiar las condiciones a 45 días, US$ 5,000.00 y USD (quedaron así); el panel "Comercial" del menú replegado por encima de la cabecera; y las casillas con 16 px a cada lado a 760 px de ancho. Con las condiciones y los contactos nuevos, los candados no se revisaron con Ventas. Las 57 pruebas pasan.

### Prefijo del país en los contactos (2026-10-08)

Pediste el desplegable de prefijos que tienen los otros módulos. El formulario de contacto de la vista 360 ahora lo tiene para Teléfono y WhatsApp: **lista de países con buscador** (los mismos 27), **"Otro país (Escribir código libre)"** para un código que no esté en la lista, y **"Eliminar formato de número" / "Aplicar formato de número"** para escribir el número completo sin prefijo aparte.

Archivos: `components/dashboard/actors/ActorModals.tsx` y `lib/countries.ts` (nuevo, con la lista de países; el formulario de Clientes, Proveedores y Empleados conserva la suya y no se tocó).

Probado en el navegador con admin: el desplegable abre con Perú +51, buscar "esp" deja España +34 y elegirlo lo aplica; con "Otro país" se escribió +971 (quita los espacios), se guardó "+971 501234567", al editar volvió a abrir con ese código, se alternó el formato y la X regresó a la lista. El contacto de prueba se eliminó.

**Corrección (2026-10-08):** la lista de países se cortaba dentro del modal. Ahora se dibuja por encima del modal: sale bajo el botón, o hacia arriba si abajo no cabe. Probado con una ventana de 720 px de alto: la lista se ve completa, elegir un país la cierra sin cerrar el modal y un clic fuera también la cierra.

**Corrección (2026-10-08):** el menú de los tres puntos de la última columna (Estado) se cortaba contra el borde de la tabla. Los menús de cabecera y el del "+" ahora se dibujan por encima de la tabla y nunca se salen de la ventana; se cierran al desplazar o cambiar el tamaño. Probado con Ventas a 1000 px de ancho: el menú de Estado y el del "+" se ven completos, "Orden descendente" ordena y un clic fuera los cierra.

### Desplegables que no se cortan y columnas personalizadas en el formulario (2026-10-08)

- **Los desplegables de los modales se cortaban** (el de "Tipo de dato" al agregar una columna). Se revisaron todos los del módulo y se cambiaron por un desplegable propio (`components/ui/pick-select.tsx`, nuevo) cuya lista se dibuja por encima del modal o de la tabla, hacia abajo o hacia arriba según el espacio. Cambiados: Tipo de dato (agregar columna), Nivel de SLA y Tipo doc. (organización / persona), Tipo (contacto), Días de crédito y Moneda (condiciones), y en la tabla el Estado y el tipo de documento de la edición en celda. Se maneja también con el teclado; Escape cierra la lista sin cerrar el modal.
- **Las columnas personalizadas ahora aparecen en el formulario** de Nueva organización, Nueva persona y Editar, en una sección "Columnas personalizadas" (texto, número, fecha o casilla Sí / No). También se ven en la tarjeta Identidad canónica de la vista 360.
- Las columnas personalizadas son de cada empresa: la base las separa por empresa, así que una no ve las de otra.

Archivos: `components/ui/pick-select.tsx` (nuevo), `ActorForm.tsx`, `ActorModals.tsx`, `ActorsList.tsx`, `ActorsTable.tsx`, `ActorDetail.tsx`.

No se cambiaron: los selectores del panel "Más filtros" y los filtros de arriba (no están dentro de un modal ni de la tabla), ni los calendarios de los campos de fecha, que son del navegador.

Probado en el navegador con admin, con una ventana de 640 px de alto: la lista de "Tipo de dato" sobresale del modal y se ve completa; Nivel de SLA, Tipo de contacto, Días de crédito y Moneda abren completos y eligen bien; Escape cierra la lista y deja el modal abierto; Estado se cambia desde la celda (Inactivo y de vuelta a Activo). "Nueva organización" muestra la sección con la columna "Año"; al editar "Actor de Prueba S.A.C." el campo llega con su valor, se cambió, se guardó y se vio en la vista 360 (luego se devolvió a 2026-10-05).

### Rol Contacto y teléfono con prefijo en el formulario (2026-10-08)

Preguntaste por el rol Contacto del prototipo, que no se había considerado. Hay dos cosas que se llaman "contacto":

- **Persona de contacto (rol Contacto, como el prototipo):** una persona enlazada a una organización ("Contacto de").
- **Dato de contacto (como en tus módulos):** un teléfono, correo o dirección de una identidad.

Ahora existen las dos:

- **Formulario de persona:** rol **Contacto** (viene marcado en una persona nueva, como en el prototipo), campo **Contacto de (organización)** con buscador, y **Cargo** para cualquier persona (antes solo para empleados). Elegir una organización marca el rol Contacto.
- **Teléfono con prefijo de país** en el formulario de organización y de persona: el mismo desplegable de los contactos (lista con buscador, "Otro país").
- **Vista 360 de una organización:** la tarjeta Contactos tiene arriba **Personas de contacto** (nombre, cargo, correo y teléfono; el nombre abre su ficha) con el botón **Nueva persona de contacto**, que abre el formulario de persona ya enlazado a esa organización. Debajo siguen los **teléfonos, correos y direcciones**.
- **Vista 360 de una persona:** fila "Contacto de" con enlace a la organización.
- **Listado:** rol Contacto en las etiquetas y en el filtro Rol; la columna Sector / relación dice "Contacto de …".

API (`PartyController.php`): rol `CONTACT` solo para personas; `contact_of` debe ser una organización de la empresa; `phone_country_code`; la ficha de la organización devuelve `contact_people`. **Sin migración:** el rol, la organización y el cargo de la persona se guardan en el campo `metadata` que ya tenía la persona. Una persona que solo es contacto no es cliente, proveedor ni empleado, así que no aparece en esos tres módulos.

Web: `ActorForm.tsx`, `ActorModals.tsx` (el teléfono con prefijo pasa a ser una pieza común), `ActorDetail.tsx`, `ActorsTable.tsx`, `lib/actors.ts`, `types/actor.ts` y `components/ui/pick-select.tsx` (buscador opcional).

Diferencia con el prototipo: allí los "Contactos" de la organización son solo personas; aquí la tarjeta muestra las personas y también los datos de contacto que ya manejabas.

Probado: la prueba automática cubre crear la persona de contacto, validar la organización, el filtro por rol, agregar el rol a una persona existente y enlazarla o desenlazarla (57 pruebas pasan). En el navegador con admin: desde "Actor de Prueba S.A.C." → Nueva persona de contacto abre con Contacto marcado y la organización puesta; el buscador de organizaciones filtra ("prueba" → 2); el prefijo se cambió a Colombia +57; al guardar aparece "Contacto De Prueba · Jefa de compras · correo · +57 3001234567". Su ficha muestra "Contacto de Actor de Prueba S.A.C." con enlace; el listado, la etiqueta Contacto y "Contacto de …"; "Nueva persona" desde el listado abre con Contacto marcado. No se comprobó en pantalla que al editar esa persona el prefijo llegue en +57, ni quitar el enlace desde el formulario (sí en la prueba automática).

Dato de prueba que queda: la persona **Contacto De Prueba** (contacto de Actor de Prueba S.A.C.).

**Ajustes (2026-10-08):**

- El teléfono del formulario de organización y de persona ahora también tiene **"Eliminar formato de número" / "Aplicar formato de número"**, igual que el de los contactos (los tres usan la misma pieza, `PhoneField`).
- **Campos que dependen del rol**, como antes con el cargo: **Contacto de (organización)** solo se puede llenar con el rol Contacto marcado, y **Cargo** con Empleado o Contacto. Sin el rol, el campo sale deshabilitado con la nota "Se guarda con el rol …" y no se guarda. (El cargo se dejó también para Contacto porque es lo que se muestra junto a la persona de contacto en la organización.)

Probado en el navegador con admin en "Nueva persona": con Contacto marcado los dos campos están activos; sin roles, los dos deshabilitados con su nota; solo con Empleado, Cargo activo y Contacto de deshabilitado. En el teléfono, "Eliminar formato" quita el prefijo y deja escribir el número completo, y "Aplicar formato" lo devuelve con Perú +51. No se guardó ningún registro en esta prueba.

**Condiciones comerciales en el formulario (2026-10-08):** días de crédito, límite y moneda ahora se llenan en el mismo formulario de organización o persona, al crear y al editar, en una sección "Condiciones comerciales". Como los otros campos con rol, solo se pueden llenar con el rol **Cliente** marcado. Se guardan en el mismo cliente que se ve en Clientes. El botón aparte "Editar condiciones" de la vista 360 se quitó (la tarjeta sigue mostrando los datos). Archivos: `ActorForm.tsx`, `ActorDetail.tsx`, `ActorModals.tsx`.

Probado con admin: al editar "Actor de Prueba S.A.C." la sección llega con 45 días, 5000.00 y USD; se cambió a 60 días y 7500 y la tarjeta mostró "60 días · US$ 7,500.00" (quedó así). En "Nueva organización", al desmarcar Cliente los tres campos se deshabilitan con la nota "Se guarda con el rol Cliente". No se probó crear una organización nueva con condiciones distintas de las de por defecto.

**Personas y datos de contacto, separados (2026-10-08):** tener las dos listas en una sola tarjeta "Contactos" confundía (un dato como "Maria · Teléfono" debajo de "Personas de contacto"). Ahora son dos tarjetas en la vista 360:

- **Personas de contacto** (solo en organizaciones): quién atiende, con cargo, correo y teléfono, y el botón "Nueva persona de contacto".
- **Datos de contacto** (en organizaciones y personas): teléfonos, correos y direcciones de la propia identidad. Cada fila muestra primero el tipo y, si tiene, su etiqueta.

En el formulario del dato, el campo "Nombre del contacto" pasó a llamarse **Etiqueta** (ej. "Central, Facturación, Almacén"), va al final y avisa: "Si es una persona, créala como persona de contacto". Los datos que ya tenían nombre de persona (como "Maria") se quedan como están. Archivos: `ActorDetail.tsx`, `ActorModals.tsx`.

Probado con admin: "Actor de Prueba S.A.C." muestra las dos tarjetas; el formulario del dato abre con Tipo, Número y Etiqueta; la ficha de la persona de contacto muestra solo "Datos de contacto".

## Pendiente

- Decidir si este módulo reemplaza a los tres. Si es así, falta traer lo de la lista "Lo que tus tablas tienen y aquí todavía no".
- En la base hay identidades repetidas (la misma empresa creada una vez como cliente y otra como proveedor). El módulo nuevo evita crear más, pero no une las que ya existen.

## La columna Nombre ya no está fija siempre (2026-10-08)

Pediste que Nombre no quede inmovilizada de forma permanente, sino con la opción de fijarla.

- Su menú (los tres puntos) ahora ofrece **Inmovilizar columna / Desinmovilizar columna**, como las demás. Al entrar ya no viene fija.
- **No se puede mover:** sigue siendo siempre la primera.
- Si se inmoviliza **otra** columna, Nombre se inmoviliza también y la otra queda a su lado.
- Desinmovilizar Nombre libera también las que estaban fijas a su lado (no pueden quedar fijas sin ella).
- La columna de casillas sigue fija a la izquierda, como antes.

Archivo: `components/dashboard/actors/ActorsTable.tsx`.

Probado en el navegador con Ventas, con la ventana a 900 px: al entrar Nombre está libre; Inmovilizar la fija; Desinmovilizar la libera; al inmovilizar Estado quedan fijas Nombre y Estado, una junto a la otra, también al desplazar la tabla; Desinmovilizar Nombre libera las dos. No se comprobó que la elección se conserve al recargar la página.

## Un solo filtro para Rol y Estado (2026-10-08)

Los filtros de arriba (Rol, Estado) y "Filtrar por esta columna" podían filtrar el mismo dato dos veces y contradecirse (Estado: Inactivo arriba y Estado = Activo en el panel dejaba la tabla vacía).

- En las columnas **Roles** y **Estado**, "Filtrar por esta columna" ya no crea un filtro aparte: lleva al desplegable de arriba (Rol o Estado) y lo abre.
- Las demás columnas (Nombre, Documento, Sector / relación y las personalizadas) siguen con su filtro por columna.
- El botón "Más filtros" pasó a llamarse **Filtros por columna**.

Archivo: `components/dashboard/actors/ActorsList.tsx`.

Probado en el navegador con Ventas: desde Estado y desde Roles el foco pasa al desplegable de arriba y no se agrega ningún filtro al panel; desde Documento se agrega uno y el botón muestra "Filtros por columna 1". No se comprobó con el ratón que el desplegable se abra solo (en la prueba automática queda enfocado).

### "Limpiar filtros" limpia todo (2026-10-08)

"Limpiar todos" estaba dentro del panel y solo quitaba los filtros por columna. Ahora hay un solo botón, **Limpiar filtros**, junto a "Filtros por columna": aparece cuando hay cualquier filtro puesto y quita el buscador, Rol, Tipo, Estado y los filtros por columna a la vez.

Archivo: `components/dashboard/actors/ActorsList.tsx`.

Probado en el navegador con Ventas: sin filtros el botón no aparece; con buscador, Rol, Estado y un filtro por columna (3 de 45) aparece, y al pulsarlo todo queda vacío y vuelven los 45.

### Los filtros por columna, siempre a la vista (2026-10-08)

Con el panel "Filtros por columna" cerrado, lo único que avisaba de un filtro activo era el número junto al botón. Ahora cada filtro aparece como una **etiqueta** bajo la barra ("Documento contiene "2099"", "Año: esta semana"):

- la **✕** de la etiqueta quita ese filtro;
- un clic en el texto abre el panel para cambiarlo;
- un filtro al que aún no se le puso valor sale como "Nombre: sin valor" (no filtra nada todavía);
- con el panel abierto las etiquetas no se repiten: ahí ya se ven los filtros.

Archivo: `components/dashboard/actors/ActorsList.tsx`.

Probado en el navegador con Ventas: con tres filtros (Documento contiene 2099, Año esta semana, Nombre sin valor) y el panel cerrado salen las tres etiquetas; la ✕ de Año lo quita y el listado pasa de 1 a 2 filas; el clic en una etiqueta abre el panel. Visto en tema oscuro; no en tema claro ni a 375 px.

## La fila abre la ficha; se edita con el lápiz o con doble clic (2026-10-08)

En los demás listados un clic en cualquier parte de la fila abre el registro; aquí un clic ponía la celda en edición y solo el nombre abría la ficha. Se unificó hacia lo de los demás:

- **Un clic en la fila abre la vista 360**, también para quien no tiene permiso de editar (antes, a esa persona el clic no le hacía nada).
- **Para editar una celda:** el **lápiz** que aparece al pasar el cursor por ella, o **doble clic** en la celda. Lo demás no cambia: se guarda con Enter o al salir, con el aviso "cambios guardados" y Deshacer.
- Las celdas que no se editan (Roles; "Contacto de …") no muestran lápiz.
- La casilla de selección no abre la ficha.
- En teléfono, donde no hay cursor, el lápiz se ve siempre.

En una celda editable el clic espera un cuarto de segundo antes de abrir la ficha, para distinguirlo del primer clic de un doble clic. En las demás celdas abre al instante.

Archivo: `components/dashboard/actors/ActorsTable.tsx`.

Probado en el navegador con admin, sobre "Actor de Prueba S.A.C.": 4 lápices en la fila y ninguno en Roles; el lápiz de Sector abre el editor sin salir del listado; se cambió el sector, salió el aviso y Deshacer lo devolvió a "Tecnología"; el doble clic abre el editor y no la ficha; la casilla marca sin abrir; un clic en la celda de Sector abre la ficha tras la espera, y un clic en Roles también. La prueba fue con clics simulados: falta que lo pruebes con el ratón. No se vio a 375 px.
