# 2026-10-13 — Operaciones: Agenda

## Qué se pidió

"Ahora sigue con Agenda."

Referencias: prototipo (`fuente/js/ui/views/operations.js`, función `agenda`; `services/operations.js`, `saveAgendaEvent`; `core/states.js` y `core/rbac.js`) y kit (`docs/especificaciones/06-operaciones.md`, RN-OPS-04).

No se te consultó antes de empezar. Lo decidido sin preguntarte está en "Decisiones tomadas en esta tanda".

## Cómo funciona (`/agenda`)

- **Semana en siete columnas**, de lunes a domingo, como el prototipo: cada día con su número, hoy resaltado, y dentro sus eventos por hora (hora y tipo, título, responsable). Arriba, "‹ Semana anterior · Hoy · Siguiente ›" y **Nuevo evento**. En teléfono los días van uno debajo de otro.
- **Las órdenes de trabajo programadas aparecen solas** en su día (con su número y un ícono de llave); un clic lleva a la ficha de la orden. No se copian a la agenda: se leen de las propias órdenes, así que no hay dos fechas que mantener iguales.
- **Nuevo evento:** Título, Tipo (Reunión, Visita, Mantenimiento, Sesión), Inicio, Fin, Responsable y Cliente, como el prototipo, más Lugar y Notas. Sin fin, dura una hora; sin responsable, es de quien agenda.
- **Cruce de horarios:** si la persona ya tiene otro evento programado o una orden de trabajo asignada a esa hora, la ventana avisa ("Conflicto de agenda con …") y el botón pasa a **Guardar igual**, como en el prototipo. Guardarlo así queda anotado en auditoría.
- **Y al revés es una regla dura:** no se puede asignar una orden de trabajo a un técnico encima de un evento programado suyo (antes solo se miraban sus otras órdenes).
- Un clic en un evento abre su ficha: **Marcar realizado**, **Editar** y **Cancelar evento** (pide motivo). Realizado sale en verde; cancelado, en gris y tachado. Un evento cerrado ya no cambia.

### Permisos y alcance

| Permiso | Qué permite |
| --- | --- |
| `agenda.view` | Ver la agenda |
| `agenda.create` | Agendar eventos |
| `agenda.manage` | Editar un evento, marcarlo realizado o cancelarlo |

Cuelgan del módulo **Agenda** del plan, que ya existía en tu catálogo (sin el módulo, la API responde 403).

**Reparto, el de la matriz del prototipo:**

| Rol | Agenda | Alcance |
| --- | --- | --- |
| Administrador | Ver, Crear, Gestionar | Toda la empresa |
| Gerencia | Ver | Toda la empresa |
| Ventas | Ver, Crear, Gestionar | Propios |
| Operaciones (prueba) | Ver, Crear, Gestionar | Sede |
| Técnico (prueba) | Ver | Propios |

No se dio nada de más respecto del prototipo.

**Alcance:** un evento es "propio" de su responsable y de quien lo agendó; nace en la sede de quien lo agenda. Las órdenes de trabajo que muestra la agenda respetan el alcance de órdenes de trabajo, y sin permiso de verlas no salen. El aviso de cruce mira toda la agenda de la persona, pero **solo dice con qué choca si quien agenda puede verlo** (si no: "otro evento de esa persona").

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Operations/Http/Controllers/AgendaController.php` (nuevo) | Semana, opciones, alta, edición, realizar y cancelar. |
| `Operations/Models/AgendaEvent.php` (nuevo) | El evento; respeta el alcance del rol por sí solo. |
| `Operations/Support/AgendaClash.php` (nuevo) | Qué ocupa ya el horario de una persona (eventos y órdenes de trabajo). |
| `Operations/Http/Controllers/WorkOrderController.php` | Asignar una orden también mira los eventos del técnico. |
| `Services/AccessScope.php`, `Middleware/CheckRecordVersion.php`, `Services/AuditCatalog.php`, `routes/api.php` | Alcance, ediciones simultáneas, textos de auditoría y rutas. |
| `database/seeders/LocalOperationsSeeder.php` | La empresa de prueba recibe el módulo y sus roles Operaciones y Técnico, los permisos. |
| `tests/Feature/OperationsAgendaTest.php` (nuevo), `AccessScopeTest.php` | Cuatro pruebas nuevas; la lista de recursos con alcance incluye Agenda. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/operations/Agenda.tsx` (nuevo), `app/(dashboard)/agenda` (nuevo) | La pantalla, la ficha del evento y su formulario. |
| `components/layout/AppShell.tsx`, `proxy.ts` | Agenda deja de estar "próximamente" en el menú. |
| `components/dashboard/admin/RolesPermissions.tsx`, `ScopeNote.tsx`, `lib/operations.ts`, `types/operations.ts` | Fila en la matriz de roles, textos de alcance, tipos y estados. |

## Qué NO se tocó

- Las órdenes de trabajo siguen igual; solo ganaron la comprobación contra eventos al asignar.
- El resto de pantallas.

## Decisiones tomadas en esta tanda (dime si alguna la quieres distinta)

1. **Solo la vista semanal del prototipo**, sin tabla ni exportar: la Agenda es un calendario, no un listado. Si quieres un listado exportable de eventos, se agrega como segunda vista con su permiso de exportar.
2. **Las órdenes de trabajo se leen, no se copian.** El prototipo crea un evento "VISITA" por cada orden; aquí la agenda consulta las órdenes, para que mover la fecha de una orden no deje la agenda desfasada.
3. **El cruce avisa en los eventos y bloquea en las órdenes de trabajo.** Agendar una reunión encima de algo es decisión de quien agenda (como el prototipo); asignar trabajo de campo encima de un evento, no (tu regla "el choque bloquea").
4. **Cancelar pide motivo** (el prototipo no) y **los eventos no se borran** (la aplicación no puede borrarlos en la base).
5. **Módulo del plan: Agenda**, el de tu catálogo. En el prototipo la agenda no depende del plan. Lo activé en la empresa de prueba.
6. **Se puede agendar a cualquier persona con acceso a la agenda**, aunque quien agenda tenga alcance "Propios" (el evento sigue siendo suyo por haberlo agendado).
7. **Elegir cliente pide `core.view`**; sin él, el campo sale deshabilitado con su aviso.
8. **Lugar y Notas** son dos campos de más respecto del prototipo.
9. **Un evento dura como mucho 24 horas** y, sin hora de fin, una hora.
10. **Tipos en un solo color** (el azul del logo): verde y gris quedan para los estados Realizado y Cancelado; las órdenes de trabajo van en neutro. El prototipo pinta la visita en naranja.
11. **El técnico no marca "realizado"** su evento: en la matriz del prototipo solo ve. Lo hace quien gestiona.

## Diferencias con el prototipo

- Sin la nota bajo el título. En su lugar va el rango de la semana ("12 oct. – 18 oct. 2026").
- La ficha del evento (el prototipo no abre nada al pulsar un evento que no es de una orden).
- En teléfono, los días en una columna (el prototipo deja siete columnas con barra horizontal).

## Base de datos

`2026_10_13_000400_operations_agenda`, **ya corrida en la base local** con el usuario dueño. Solo añade:

- Tabla `agenda_events`, aislada por empresa. **La base rechaza un fin anterior al inicio, más de 24 horas, una cancelación sin motivo y un estado o tipo inventado; la aplicación no puede borrar eventos.**
- Permisos `agenda.view`, `agenda.create` y `agenda.manage`, el alcance "agenda" y su reparto (Administrador, Gerencia y Ventas).

## Cómo se probó

`php artisan test`: **157 pruebas pasan** (153 + 4):

1. Eventos: validaciones, responsable y duración por defecto, semana en orden, opciones, editar, realizar, cancelar con motivo, evento cerrado no cambia, auditoría; lo que la base rechaza.
2. Cruces: aviso sin guardar, "force" guarda y lo anota, horarios contiguos y otra persona no chocan, un cancelado libera, editar no choca consigo mismo, orden de trabajo ↔ evento en los dos sentidos, la semana trae las órdenes.
3. Alcance y permisos: Propios, Sede y Toda la empresa; lo ajeno responde 404; el aviso no revela títulos ajenos; el técnico solo ve; 409 por edición simultánea; otra empresa; sin el módulo.
4. La matriz de roles ofrece los permisos y el alcance, y cada acción tiene su texto en Auditoría.

`tsc` y `eslint` sin errores (quedan los dos avisos anteriores de `lib/api.ts`).

En el navegador (`app.localhost:3000`):

- **Operaciones:** la semana carga primero y las opciones después; aparecen las tres órdenes de trabajo de prueba. Nuevo evento para el técnico encima de una orden: aviso de conflicto → "Guardar igual" → aparece en su día. Ficha del evento, editar (se mueve de día), marcar realizado (verde), otro evento y cancelarlo con motivo (gris y tachado). Semana anterior, siguiente y Hoy.
- **Técnico:** "Alcance: Propios"; ve sus órdenes y su evento, no el de Operaciones; Nuevo evento con candado y su mensaje.
- **Gerencia:** ve todo; Nuevo evento con candado.
- **Claro, oscuro y 375 px:** capturas de la semana; sin desborde horizontal.

### Lo que no se probó

- **La fila "Agenda" en la pantalla Roles y permisos:** pide entrar como Administrador (con código); la prueba automática comprueba que la API la ofrece.
- **Ventas** en el navegador: su reparto (Propios) está cubierto por la prueba automática.
- **Los candados de la ficha del evento** (Editar, Marcar realizado, Cancelar) con una cuenta sin permiso: al probar con Técnico y Gerencia ya no quedaba ningún evento programado. Usan la misma pieza que "Nuevo evento", que sí se vio con candado.
- **Cambio de hora y zonas horarias distintas** entre quien agenda y el servidor: la semana se pide con el horario del navegador; no se simuló otra zona.

### Datos de prueba que quedaron en "Tenant A - Prueba"

- Módulo **Agenda** activo.
- "Coordinación con cliente (prueba)", realizado, del técnico (vie 16 oct., 11:00) y "Reunión interna (prueba)", cancelado, de Operaciones (mié 14 oct., 12:30).

## Pendiente

- **App del técnico** (ver nota abajo) y la pantalla de **plantillas de checklist**.
- Filtrar la agenda por persona (útil cuando Gerencia vea a toda la empresa) y una vista de mes: no están en el prototipo; dime si las quieres.
- Recordatorios y avisos al responsable: llegan con Notificaciones.
- Mover la fecha de una orden de trabajo arrastrándola en la agenda.

## Nota: App del técnico, ¿Flutter?

No hace falta crear un proyecto Flutter. El kit la define como una **aplicación web instalable (PWA) dentro de este mismo proyecto** (ruta `/campo`), y el prototipo la simula en el navegador. Flutter solo se justificaría si se necesita algo que una PWA no da bien (publicarla en las tiendas, ubicación en segundo plano, avisos push fiables en iPhone); a cambio es otro proyecto, otro lenguaje y otra publicación que mantener.
