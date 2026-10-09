# 2026-10-08 — Pantalla Auditoría (Control)

## Qué se pidió

De las pantallas de fundaciones que faltan, empezar por **Auditoría**: los datos ya se guardaban (cada alta, edición, cambio de estado, exportación, inicio y cierre de sesión) pero solo se podían consultar en la base.

Referencias: prototipo (`#/auditoria`, en `fuente/js/ui/views/control.js`) y kit (P-SYS-06, `GET /audit`, permiso `audit.view`, regla RN-SEC-13).

## Cómo funciona

- **Control → Auditoría** (`/audit`): la lista de movimientos de la empresa, del más reciente al más antiguo. Es de **solo lectura**: no hay nada que crear, editar ni borrar (la aplicación tampoco puede hacerlo en la base).
- Columnas: **Fecha** (con segundos), **Actor**, **Acción**, **Entidad**, **IP** y **Correlation**.
- **Acción y Entidad se leen en castellano** ("Aprobación", "Cambio de alcance del rol", "Cotización", "Rol"…) y debajo va el código del sistema (`quotation.approve`), como en el prototipo. Los identificadores largos se abrevian en la tabla; completos, al pasar el cursor y en el detalle.
- **Buscador**: encuentra por acción, entidad, usuario (nombre o correo), identificador del registro, correlation ID y dirección IP. Busca también por el texto que se lee: "cotizacion" (sin tilde) encuentra las cotizaciones.
- **Filtros de arriba**: Entidad y Usuario (los dos del prototipo). **Filtros por columna**: Fecha (hoy, ayer, esta semana, periodo…), Acción, IP y Correlation. En Actor y Entidad, "Filtrar por esta columna" abre el filtro de arriba (un solo filtro por dato).
- **Orden** por cualquier columna, en el servidor. Acción y Entidad ordenan por el texto que se lee.
- **Clic en la fila** abre el movimiento completo: actor y correo, acción, entidad e identificador, fecha, dirección IP, navegador, correlation ID, el **Detalle** que guardó el sistema y el **Antes / Después**.
- **Abrir registro**: si el movimiento es de una cotización, contrato, orden, servicio, cliente o actor, lead u oportunidad, el detalle lleva a su ficha. Pide el permiso de ver de ese módulo; sin él, el botón sale con candado.
- **Exportar** (Excel, CSV y selección) con su permiso propio, `audit.export`. Llevarse la auditoría **también queda en la auditoría**.
- Quien entra a `/audit` por la dirección sin el permiso ve el candado y "Tu rol no tiene permiso audit.view"; la pantalla no pide nada a la API.

### Permisos

| Permiso | Qué permite |
| --- | --- |
| `audit.view` | Ver la auditoría de **toda la empresa** |
| `audit.export` | Descargarla en Excel o CSV |

- No dependen de ningún módulo del plan (como Usuarios y Roles).
- **Reparto, el de la matriz del prototipo:** Administrador y Gerencia ven y exportan; los demás roles, nada. `audit.view` ya existía en la tabla de permisos, pero ningún rol lo tenía.
- En Roles y permisos aparece la fila **Auditoría** (Ver, Exportar).
- El administrador de una empresa nueva los recibe.

**Qué le da este permiso a un rol (para decidir a quién dárselo):** la auditoría no tiene alcance. Quien tiene `audit.view` ve los movimientos de todas las personas y de todos los módulos, incluidos los que su rol no abre: por ejemplo, vería que alguien cambió los permisos de un rol o editó un contrato, con su antes y después, aunque no pueda entrar a Roles ni a Contratos. A Gerencia se le dio porque el prototipo se lo da y ya alcanza toda la empresa en lo que ve. Para un rol más acotado conviene no darlo.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Core/Http/Controllers/AuditController.php` (nuevo) | `GET /v1/audit` (listado paginado con búsqueda, filtros de arriba, filtros por columna y orden, todo en la base) y `GET /v1/audit/options` (entidades con movimientos y personas de la empresa). Consulta siempre por la empresa de la sesión. Antes de entregar, oculta el valor de cualquier clave con nombre de secreto (`password`, `secret`, `token`, `hash`, `otp`). |
| `Services/AuditCatalog.php` (nuevo) | Los textos de cada entidad y de cada acción, en un solo lugar: los usan la tabla, el buscador, el orden, los filtros y el archivo exportado. Una acción o entidad nueva sin texto se muestra con su código. |
| `routes/api.php` | Las dos rutas, con `permission:audit.view`. |
| `Http/Middleware/ListExport.php` | El listado entra al control de exportar (`audit.export`). |
| `Subscription/Support/ModulePermissions.php` | `audit.view` y `audit.export` entre los permisos de administración de la empresa. |
| `tests/Feature/AuditScreenTest.php` (nuevo) | Ocho pruebas. |
| `tests/Feature/ExportPermissionTest.php` | Usaba "audit" como ejemplo de listado que no existe; ahora usa otro nombre. La comprobación es la misma. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/audit/AuditList.tsx` (nuevo) | La pantalla, con las piezas de los demás listados (tabla con cabecera y casillas fijas, columnas que se mueven y se fijan, filtros por columna con etiquetas, selección, paginación, exportar) y el detalle del movimiento. |
| `app/(dashboard)/audit/page.tsx` (nuevo) | La ruta `/audit`. |
| `components/layout/AppShell.tsx`, `proxy.ts` | Auditoría deja de estar en "próximamente". |
| `components/dashboard/admin/RolesPermissions.tsx` | Nombre de la fila "Auditoría". |

## Qué NO se tocó

- **Lo que se registra.** No cambió ningún movimiento ni se añadieron registros nuevos: la pantalla muestra lo que ya se guardaba.
- La tabla `audit_logs` y sus protecciones (solo alta y lectura para la aplicación, aislamiento por empresa).
- El historial de las fichas (cotización, contrato, orden, lead…), que lee de la misma tabla.
- La auditoría de plataforma (la del superadmin): sigue sin pantalla.

## Diferencias con el prototipo (y con el kit)

- **Sin la nota bajo el título** ("Actor → tenant → entidad → acción…"), como en las demás pantallas.
- **Acción y Entidad con texto legible** además del código. El prototipo muestra solo el código.
- **Columna IP** y, en el detalle, **Dirección IP** y **Navegador**: el prototipo no los tiene; ayudan a reconocer un acceso ajeno.
- **Detalle**: además de Antes / Después. Casi todos los movimientos guardan sus datos ahí ("de Enviada a Aprobada", el motivo, el número), no como antes y después.
- **Sin la fila "Tenant"** del detalle: quien mira solo ve su empresa.
- **El detalle se abre en una ventana**, no en el panel lateral del prototipo: así no se pierden los filtros puestos.
- **Abrir registro**, **filtros por columna**, **orden** y **exportar**: no están en el prototipo.
- Ruta en inglés (`/audit`), por tu decisión vigente.
- Del kit no se adoptó `GET /state-transitions` (su tabla de transiciones no existe aquí) ni la pantalla "Seguridad y aislamiento", que usa el mismo permiso y sigue en "próximamente".

## Base de datos

Migración `2026_10_08_001100_audit_screen_permissions`. **Ya está corrida en la base local**, con el usuario dueño. Solo añade: el permiso `audit.export` y el reparto de `audit.view` / `audit.export` a los roles Administrador y Gerencia de cada empresa.

## Cómo se probó

`php artisan test`: **118 pruebas pasan** (110 anteriores + 8 nuevas en `tests/Feature/AuditScreenTest.php`):

1. Sin `audit.view`, el listado y sus filtros responden 403.
2. Cada movimiento sale con quién, qué, sobre qué registro, cuándo, desde dónde y su antes y después; uno hecho por el sistema (sin persona) y sin detalle sale con sus vacíos.
3. **Otra empresa no existe:** ni en el listado, ni buscando su identificador, ni filtrando por su persona, ni en las listas de los filtros.
4. Un valor guardado bajo una clave con nombre de secreto sale oculto, también dentro de datos anidados.
5. Buscar (por código, por texto legible con y sin tilde, por persona, por IP), filtros de arriba, filtros por columna (hoy, periodo, acción, IP), orden por cada columna, paginación y tope de 200 filas.
6. Exportar pide `audit.export` (la página de exportación y el registro responden 403 sin él) y la exportación aparece en la propia auditoría con sus filtros.
7. No hay ruta para crear, cambiar ni borrar un movimiento.
8. La matriz de roles ofrece los dos permisos y el administrador de una empresa nueva los recibe.

`tsc` y `eslint` sin errores.

En el navegador (`app.localhost:3000`):

- **Gerencia:** el menú Control muestra Auditoría; el listado carga (944 movimientos) con sus seis columnas. "/" lleva al buscador y Escape lo borra y lo suelta. Buscar "cotizacion" deja 26; Entidad = Rol, 19; Usuario = Lucía Paredes, 108, todos suyos. Orden por Entidad en los dos sentidos y por Fecha ascendente. Filtro por columna en Acción ("alcance", 6 filas) con su etiqueta visible; en Entidad, la opción enfoca el filtro de arriba sin crear otro; Fecha = hoy. "Limpiar filtros" devuelve los 944. Clic en la fila abre el detalle con todos sus datos y Antes / Después; Escape lo cierra; marcar la casilla no lo abre. CSV de las filas filtradas (con sus 13 columnas) y Excel de una selección, los dos generados; las dos exportaciones aparecieron en el listado como `audit.export`, con sus filtros en el Detalle. "Abrir registro" llevó a la cotización COT-2026-0011.
- **Candado de exportar:** no hay un rol que vea la auditoría sin exportarla, así que se simuló en el navegador quitando `audit.export` de la sesión: "Exportar a Excel", "CSV" y "Exportar selección" salen deshabilitados, con candado y "Tu rol no tiene permiso audit.export".
- **Ventas:** Auditoría no aparece en el menú; entrando por la dirección ve el candado y "Tu rol no tiene permiso audit.view", sin ninguna petición a la API.
- **Administrador:** en Roles y permisos, la fila Auditoría: Administrador con Ver y Exportar fijos, Gerencia con los dos marcados, Ventas sin ninguno. La pantalla Auditoría carga y los botones de exportar están habilitados.
- **Claro y oscuro** (con captura): el listado en los dos y el detalle en oscuro. **375 px** (con captura): el listado en tarjetas y el detalle, sin desborde horizontal (medido).

### Lo que no se probó

- **Pasar el cursor, con los ojos:** la ventana de pruebas estaba oculta y no admite el cursor real. Se comprobó de otra forma (ver la segunda ronda).

### Segunda ronda: lo que faltaba probar

Pediste probar lo que faltaba.

- **Mover e inmovilizar columnas** (Gerencia): se arrastró IP delante de Actor y se inmovilizó Acción; el orden y la columna fija quedaron guardados, la columna fija se pegó junto a Fecha con su desplazamiento, y al salir y volver a la pantalla todo seguía igual, con "Desinmovilizar columna" en su menú. Al terminar se devolvió la tabla a su orden original.
- **Candado de "Abrir registro":** se simuló en el navegador un rol con auditoría pero sin `quotations.view`: en el detalle de una cotización el botón sale deshabilitado, con candado y "Tu rol no tiene permiso quotations.view".
- **Al pasar el cursor:** como la ventana no admitía el cursor real, se leyó en las hojas de estilo qué regla aplica a cada pieza: la fila cambia de fondo (y lleva el cursor de mano), "Exportar a Excel" cambia fondo y texto, "Cerrar" del detalle cambia fondo y borde, y la X del detalle se aclara. No es lo mismo que verlo: conviene que le pases el cursor una vez.
- **Con muchos movimientos:** se cargaron **500 000** movimientos en la empresa de prueba dentro de una transacción que luego se deshizo (no quedó ninguno: 1 005 antes y después). Tiempos en la base: primera página, 1 ms; contar el total, 19 ms; página 5 000, 7 ms; filtro Entidad, 14 ms; filtro Usuario, 20 ms; buscador de texto libre, 0,34 s; orden por Entidad, 44 ms; lista de entidades del filtro, 27 ms. Medido como dueño de las tablas, sin la regla de aislamiento, que añade una comparación por fila.

### Datos de prueba que quedaron en "Tenant A - Prueba"

Los inicios y cierres de sesión de las tres cuentas y dos exportaciones de la auditoría hechas por Gerencia. No se pueden borrar: la auditoría es de solo alta.

## Pendiente

- **"Hoy" en el filtro de fecha usa el día del servidor (UTC):** de noche en Perú deja fuera lo de la tarde. Es el mismo pendiente de la zona horaria de la aplicación que ya afecta a los demás listados; se resuelve fijándola una vez para todo el sistema.
- **Antes / Después casi siempre vacío:** la mayoría de movimientos guarda solo el Detalle. Completarlo es cambiar qué registra cada acción, módulo por módulo.
- **Identificadores en lugar de nombres:** la entidad se muestra con su identificador interno, no con su número (COT-2026-0011), y dentro del Antes / Después un rol o una sede salen como identificador. "Abrir registro" lo suple en parte.
- **Retención:** la auditoría crece sin límite. Falta decidir cuánto se conserva y un índice por persona si el filtro Usuario se vuelve lento.
- Pantalla de la **auditoría de plataforma** (superadmin) y **Seguridad y aislamiento**.
