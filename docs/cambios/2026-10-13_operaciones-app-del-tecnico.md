# 2026-10-13 — Operaciones: App del técnico

## Qué se pidió

Tras ver el boceto: "me convence, solo intenta no quitar nada de lo que muestra el prototipo; puedes mejorarlo como me muestras, mas no quitar".

Referencias: prototipo (`fuente/js/ui/views/extras.js`, función `techApp`, y sus estilos `.phone`, `.ph-*`, `.sync-bar`) y kit (`docs/02-ARQUITECTURA.md`, `backlog/F4.md`: aplicación web instalable dentro del mismo proyecto; reglas RN-OPS-09 y RN-OPS-10).

## Cómo funciona (`/campo`)

Es una pantalla más del sistema, pensada para teléfono. En escritorio se ve dentro de un marco de teléfono con tres tarjetas al lado, como el prototipo; en un teléfono ocupa toda la pantalla.

**Todo lo que muestra el prototipo está:**

| Del prototipo | Aquí |
| --- | --- |
| Barra del teléfono con la hora y el estado de la red | Igual: "En línea" / "Sin conexión" |
| Cabecera con el técnico y "N visita(s) pendientes" | Igual |
| Botón "Simular sin conexión" / "Volver a conectar" | "Trabajar sin conexión" / "Volver a conectar" (además se activa solo si se cae la señal) |
| Barra "N cambio(s) guardados en el teléfono" con **Sincronizar** | Igual |
| Mapa esquemático con las visitas numeradas y la ruta | Mapa real de calles con las visitas numeradas y la ruta; el esquemático queda de respaldo sin conexión |
| Tarjeta por visita: número, título, cliente y lugar, fecha y número de OT, estado | Igual |
| "N cambio(s) sin sincronizar" en la tarjeta | Igual |
| Checklist con casillas y asterisco en los obligatorios (con la visita en ejecución) | Igual |
| "Adjuntar foto de evidencia" | Igual, y sube la foto de verdad |
| Iniciar visita, Completar (deshabilitado sin los obligatorios), Bloqueada (pide motivo), Reanudar, Detalle | Igual |
| "Ver como {técnico}" para quien no es técnico, con su nota | Igual, con buscador |
| Tarjetas "Cómo funciona el modo sin conexión", "Últimas sincronizaciones" y "Ruta sugerida" | Igual |

**Mejoras, las del boceto:**

- La visita en ejecución va resaltada.
- "Checklist 3 de 6" en cada tarjeta y "Faltan N ítem(s) obligatorio(s) para completar".
- Una **cámara por ítem** del checklist (en el teléfono abre la cámara directamente).
- **Horas:** registrar las horas de la visita desde su tarjeta.
- **Cómo llegar:** abre la navegación del teléfono (Google Maps) hacia la visita.
- El motivo del bloqueo se ve en la tarjeta.

### Sin conexión

- Si el teléfono pierde la señal (o una acción no llega al servidor), la app pasa sola a "Sin conexión" y lo que el técnico haga **se guarda en el teléfono**: iniciar, bloquear, reanudar, completar, marcar ítems y tomar fotos.
- La tarjeta muestra la visita como quedará (estado, casillas, foto) y cuántos cambios tiene sin enviar. La cola **no se pierde al recargar** la página.
- Al volver la señal, **Sincronizar** envía cada cambio en orden por las rutas normales de la API, que valida estado y permisos. Lo que ya no vale (por ejemplo, iniciar una orden que alguien más ya inició) se **rechaza con su motivo** y no frena los demás.
- Cada resultado queda en "Últimas sincronizaciones" del teléfono, y lo rechazado queda además en el **historial de su orden** ("Cambio sin conexión rechazado"), para que quien supervisa sepa que ese trabajo no se registró.
- Reintentar un envío no lo aplica dos veces: cada cambio lleva su propia clave.

### Ubicación, mapa y ruta

- La orden de trabajo tiene un campo nuevo, **Coordenadas (opcional)**: latitud y longitud como las copia Google Maps ("-12.0464, -77.0428"). En la ficha de la orden se ven como "Ubicación", con enlace al mapa.
- La **ruta sugerida** parte de la primera visita programada y sigue siempre con la más cercana (en línea recta); las visitas sin coordenadas van al final. Lo calcula la API.
- El mapa es real (ver "Ajuste posterior: mapa real" al final); sin conexión se ve el esquemático del prototipo.

### Foto de evidencia

- La foto se guarda como documento del ítem (con versiones: otra foto es la versión 2) y marca el ítem como hecho. Solo se aceptan imágenes.
- En la ficha de la orden de trabajo, el ítem muestra "Foto: nombre" y un clic la abre con un enlace temporal.

### Instalable

- El sistema ya se puede "Agregar a pantalla de inicio" desde el navegador del teléfono: queda un icono que abre a pantalla completa en la App del técnico.

### Permisos y alcance

No hay permisos nuevos. La app pide `work_orders.view` (módulo Órdenes de trabajo) y cada acción pide lo de siempre: `work_orders.manage` para ejecutar y adjuntar, `time_entries.create` para las horas. Sin permiso, el botón sale con candado.

- El **Técnico** (alcance Propios) ve solo sus visitas y no puede elegir a nadie más.
- Quien alcanza más (Sede, Toda la empresa) puede "Ver como" cualquier persona que ejecute órdenes, y ve de ella solo las órdenes dentro de su propio alcance. Sus acciones se registran con su usuario.
- **Ventas** no tiene `work_orders.view`: no ve la app.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Operations/Http/Controllers/FieldController.php` (nuevo) | Las visitas pendientes de una persona en el orden sugerido, y el registro de cambios rechazados al sincronizar. |
| `Operations/Http/Controllers/WorkOrderController.php` | Coordenadas en el alta, la edición y la ficha; foto de evidencia por ítem y su enlace temporal. |
| `Operations/Models/WorkOrder.php`, `WorkOrderChecklistItem.php` | Coordenadas; la foto del ítem. |
| `Services/AuditCatalog.php`, `routes/api.php` | Textos de auditoría y rutas (`/field/route`, `/field/sync-log`, `…/checklist-items/{item}/evidence` y `/evidence-url`). |
| `tests/Feature/OperationsFieldTest.php` (nuevo) | Dos pruebas. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/operations/FieldApp.tsx` (nuevo), `app/(dashboard)/campo` (nuevo) | La pantalla. |
| `lib/field-queue.ts` (nuevo) | La cola sin conexión (en el teléfono), la sincronización y su registro. |
| `app/manifest.ts` (nuevo), `public/icons/` (nuevo) | Lo que la hace instalable; los iconos salen de tu favicon. |
| `components/dashboard/operations/WorkOrderModals.tsx`, `WorkOrderDetail.tsx` | Campo Coordenadas; "Ubicación" y la foto de evidencia en la ficha. |
| `components/layout/AppShell.tsx`, `proxy.ts`, `lib/operations.ts`, `types/operations.ts` | Menú (deja de estar "próximamente"), ruta, textos del historial y tipos. |

## Qué NO se tocó

- Las reglas de la orden de trabajo (estados, checklist, completar exige los obligatorios, quién aprueba) son las mismas: la app usa las rutas que ya existían.
- La ficha de la orden de trabajo sigue igual, con dos datos más.

## Decisiones tomadas en esta tanda (dime si alguna la quieres distinta)

1. **Aplicación web dentro de este proyecto**, no Flutter (lo conversamos).
2. **"Simular sin conexión" pasó a ser "Trabajar sin conexión":** en el prototipo era un botón de demostración; aquí es una función real (útil con señal intermitente) y, además, la app lo activa sola cuando no llega al servidor.
3. **Sincronizar es manual**, como en el prototipo: al volver la señal la app avisa y el técnico pulsa el botón.
4. **Los cambios se envían por las rutas normales** de la orden, uno por uno y en orden (lo que pide el kit), no por una ruta especial de sincronización.
5. **Al sincronizar no se rechaza un cambio solo porque otra persona editó la orden** (por ejemplo, su descripción): se lee la versión vigente y decide el **estado** de la orden. Si la orden fue cancelada o cerrada, sí se rechaza.
6. **Las coordenadas van en la orden de trabajo**, escritas a mano, porque los clientes no tienen ubicación guardada. Es el dato mínimo para tener mapa y ruta sin contratar un proveedor de mapas.
7. **La ruta se ordena en línea recta**, como el prototipo. No considera calles ni tráfico.
8. **La foto marca el ítem como hecho** y reemplaza la nota de evidencia por "Foto: nombre".
9. **Registrar horas necesita conexión** (el botón lo dice): las horas tienen validaciones de solapes que solo puede hacer la API.
10. **Lo guardado en el teléfono es lo mínimo:** la última ruta (solo la ve la sesión que la cargó) y la cola de cambios.
11. **Sin permisos nuevos:** la app se abre con `work_orders.view`, como indica el kit.
12. **El nombre de la app instalada** es "ERP Campo" con tu favicon como icono. Cámbialo cuando tengas el nombre comercial.

## Diferencias con el prototipo

- Sin la nota bajo el título.
- "4G" pasó a "En línea" (el navegador no sabe qué red usa el teléfono).
- El texto de "Cómo funciona el modo sin conexión" describe lo real (se activa solo; sin "simular").

## Base de datos

`2026_10_13_000500_field_app_location_and_evidence`, **ya corrida en la base local** con el usuario dueño. Solo añade:

- `work_orders.latitude` y `longitude`. **La base exige que vayan las dos o ninguna, y dentro de rango.**
- `work_order_checklist_items.evidence_document_id`.

## Cómo se probó

`php artisan test`: **159 pruebas pasan** (157 + 2):

1. Ruta: coordenadas completas y en rango (API y base); solo lo pendiente y asignado; orden sugerido por cercanía con las no ubicadas al final; el técnico no ve lo de otro; "ver como" solo de quien ejecuta órdenes; una visita completada sale de la ruta; reintentar con la misma clave no aplica dos veces; sin permiso, 403; otra empresa.
2. Evidencia: solo fotos; marca el ítem, vincula el documento y audita; otra foto = versión 2; enlace temporal; lo ajeno responde 404 y quien solo ve no adjunta; los rechazos de sincronización llegan al historial solo de órdenes alcanzables; con la orden cerrada ya no se cambia; textos de auditoría.

La primera vez, la prueba encontró un fallo mío: la base aceptaba una latitud sin longitud. Quedó corregido en la migración.

`tsc` y `eslint` sin errores (quedan los dos avisos anteriores de `lib/api.ts`).

En el navegador (`app.localhost:3000`):

- **Técnico, en línea:** ve sus tres visitas en el orden sugerido, con el mapa y las tres tarjetas laterales. Inicia una visita, marca el ítem obligatorio (se habilita Completar), toma una foto por la cámara del ítem y completa: la visita sale de la ruta.
- **Técnico, sin conexión:** "Trabajar sin conexión" → inicia una visita, marca un ítem e inicia otra: nada se envía y la barra cuenta 3 cambios. **Recarga la página: siguen ahí.** Mientras tanto inicié una de esas órdenes desde otra sesión. "Volver a conectar" → "Sincronizar": 2 aplicados y **1 rechazado con su motivo** ("No se puede iniciar una orden de trabajo en ejecución"), que también quedó en el historial de esa orden.
- **Foto y bloqueo sin conexión:** una foto tomada sin señal y un bloqueo con motivo se guardan y se aplican al sincronizar.
- **Caída de señal a mitad:** cortando las llamadas a la API por código, "Reanudar" queda guardado y la app pasa sola a "Sin conexión"; "Volver a conectar" sin señal avisa y sigue igual; con señal, sincroniza.
- **Operaciones:** "Ver como" con buscador; su propia app sin visitas; como el técnico, ve sus dos visitas y puede actuar.
- **Ficha de la orden:** "Ubicación" con enlace al mapa, "Foto: …" en el ítem, y el formulario de edición trae las coordenadas.
- **Instalable:** el manifiesto y los iconos se sirven y la página los enlaza.
- **Claro, oscuro y 375 px:** capturas; sin desborde horizontal.

### Lo que no se probó

- **Un teléfono de verdad:** la cámara, "Agregar a pantalla de inicio" y la pérdida real de señal. Lo probé en el navegador de escritorio simulando cada cosa.
- **Abrir la app desde cero sin señal:** no funciona todavía (ver Pendiente). Sin señal sirve si la app ya estaba abierta.
- **Fotos grandes** (varios MB) guardadas sin conexión y enviadas después: probé con una imagen mínima.
- **Gerencia** (ve pero no ejecuta): sus botones usan la misma pieza con candado que el resto; no entré con esa cuenta en esta tanda.
- **El enlace "Cómo llegar":** se comprobó que apunta a Google Maps con las coordenadas; no lo abrí.

### Datos de prueba que quedaron en "Tenant A - Prueba"

- Coordenadas en `OT-2026-0003` y `OT-2026-0004`; ambas quedaron **en ejecución**, y la 0003 pasó por bloqueada y reanudada.
- `OT-2026-0005` "Inspección en campo (prueba app)", completada, con una foto de evidencia (dos versiones).
- `OT-2026-0004` con la foto "soporte-offline.png".

## Pendiente

- **Abrir la app sin señal desde cero** (que cargue aunque el teléfono no tenga red al tocar el icono). Hoy la app debe estar ya abierta. Hace falta guardar la propia aplicación en el teléfono; lo dejé fuera porque en desarrollo interfiere con tus recargas y conviene hacerlo con el dominio real.
- **Borrar lo guardado en el teléfono al cerrar sesión** (la ruta ya no se muestra a otra sesión, pero la cola de cambios de esa persona sigue ahí hasta sincronizar).
- **Guardar dónde estaba el técnico** al iniciar y al completar la visita.
- **Ubicación en el cliente** (para no escribir coordenadas en cada orden) y rutas por calles con tráfico, si algún cliente lo pide.
- **Antivirus** para las fotos (el mismo pendiente de los archivos de entregables).
- Avisar al técnico cuando le asignan una visita: llega con Notificaciones.
- Pantalla de **plantillas de checklist**.

## Ajuste posterior: mapa real

Preguntaste si en el celular el mapa se vería como un mapa de verdad. No: era un dibujo. Te propuse OpenStreetMap con Leaflet (gratis, sin cuenta ni clave) y respondiste: "sip, si no hay conexión carga nomás el mapa esquemático con un aviso".

- Con conexión, la app muestra un **mapa real de calles** (OpenStreetMap) con las visitas numeradas, la ruta punteada, zoom y arrastre.
- **Sin conexión** se ve el mapa esquemático con el aviso "Sin conexión: se muestra el mapa esquemático."
- Si hay conexión pero las imágenes del mapa no llegan, también se ve el esquemático, con "No se pudo cargar el mapa" y **Reintentar**.
- La ruta sigue en línea recta entre visitas; la navegación por calles sale por "Cómo llegar".

Qué se cambió: `components/dashboard/operations/FieldMap.tsx` (nuevo), `FieldApp.tsx` y la librería `leaflet` en `apps/web/package.json`.

Decisiones:

1. **Librería nueva: Leaflet**, con las imágenes del mapa servidas por OpenStreetMap (lo aprobaste antes de construirlo).
2. **El mapa no cambia con el modo oscuro:** las imágenes de OpenStreetMap son claras.
3. **La rueda del ratón no hace zoom** sobre el mapa, para no atrapar el desplazamiento de la página; se usa el "+/−" o los dedos.

Probado en el navegador con el Técnico: carga el mapa de Lima con sus dos visitas y la ruta; "Trabajar sin conexión" pasa al esquemático con su aviso; cortando por código las imágenes del mapa aparece el otro aviso y "Reintentar" lo recupera; una ventana (bloquear) queda por encima del mapa; claro, oscuro y 375 px sin desborde. `tsc` y `eslint` sin errores; las 159 pruebas siguen pasando (la API no cambió).

No se probó en un teléfono real (zoom con los dedos).

**A tener en cuenta para producción:** los servidores públicos de OpenStreetMap son para uso moderado. Con muchos técnicos a la vez conviene contratar un proveedor de imágenes de mapa (hay varios compatibles; se cambia una dirección en `FieldMap.tsx`).
