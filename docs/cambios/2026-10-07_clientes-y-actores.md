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

- **Rol Contacto y "Contacto de (organización)":** no existen. Aquí los contactos son los datos de contacto de cada identidad (correo, teléfono, dirección), como en tus módulos, no personas enlazadas a una organización. Los roles son Cliente, Proveedor y Empleado.
- **Estado:** en tus módulos se da de baja el rol (el cliente, el proveedor), no la identidad. Aquí una identidad se ve **Inactiva** si se dio de baja o si ninguno de sus roles sigue activo; un rol dado de baja por separado sale atenuado. Inactivar o reactivar desde aquí cambia la identidad **y todos sus roles a la vez**.
- **Columnas personalizadas propias:** las de esta tabla son nuevas e independientes de las que ya tienes en Clientes, Proveedores y Empleados (aquellas siguen en sus pantallas).
- **Tarjeta "Condiciones comerciales (cliente)"** en la vista 360: añadida, para ver días de crédito, límite y moneda. Se editan en Clientes.
- **Responsable** de la identidad: no existe ese dato; no se muestra.
- **Estado de cuenta:** el botón está, pero avisa que aún no está disponible (depende de Finanzas).
- **Pestañas Operaciones, Finanzas, Soporte y CRM:** muestran lo que haya en la base, con el estado tal cual se guarda y sin enlace (esas pantallas no existen). **Documentos** sale siempre vacía: los documentos aún no se enlazan a una identidad.
- Se añadieron, como en tus tablas y los demás listados: casillas, acciones masivas, exportar, paginación y "Más filtros".
- No se trajo la nota explicativa bajo el título.

## Lo que tus tablas tienen y aquí todavía no

- **Importar** desde Excel (cada módulo tiene su plantilla; no hay una plantilla unificada).
- **Asignar** (el botón de la barra de selección).
- Agregar o quitar contactos desde la ficha (aquí se ven; el correo, teléfono y dirección principales se editan con "Editar").
- Editar en la celda las condiciones de cliente (días de crédito, límite, moneda): no son columnas de esta tabla.
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

## Pendiente

- Decidir si este módulo reemplaza a los tres. Si es así, falta traer lo de la lista "Lo que tus tablas tienen y aquí todavía no".
- En la base hay identidades repetidas (la misma empresa creada una vez como cliente y otra como proveedor). El módulo nuevo evita crear más, pero no une las que ya existen.
