# 2026-10-13 — Ficha del proyecto con los indicadores y las pestañas del prototipo

## Qué se pidió

Proyectos no se parecía al prototipo en la forma. De las dos propuestas (rejilla de tarjetas en el listado; ficha con indicadores y pestañas) respondiste **"Solo 2"**: la ficha. El listado queda como estaba (tablero por estado y tabla).

Referencia: `prototipo/fuente/js/ui/views/operations.js`, función `projectDetail`.

## Cómo queda la ficha (`/projects/{id}`)

- **Cinco indicadores en una fila**, en el orden del prototipo: Avance, Tareas, Horas aprobadas, Costo real y Margen. Sin ícono, como en el prototipo. "Tareas" (hechas/total) antes iba como texto pequeño bajo Avance.
- **Cinco pestañas a todo el ancho**, con su contador: Tareas, Horas, Costos, Entregables y Resumen y estados. Abre en Tareas.
  - **Tareas:** el tablero (Por hacer / En curso / Bloqueadas / Hechas) ocupa todo el ancho, sin tarjeta alrededor, y "Nueva tarea" va debajo, como en el prototipo.
  - **Horas** y **Costos:** una tarjeta cada una, con su botón (Registrar horas / Registrar costo) en la cabecera de la tarjeta.
  - **Entregables:** la tabla con "Nuevo entregable" en la cabecera.
  - **Resumen y estados:** dos tarjetas a la par, **movibles** (el orden se guarda en el navegador): "Estados", que ahora incluye el historial como en el prototipo, y "Presupuesto vs real".
- Las reglas, los permisos y la API son los mismos: solo cambió dónde está cada cosa.

## Qué se cambió

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/operations/ProjectDetail.tsx` | Indicadores y pestañas; el tablero de tarjetas movibles queda solo en "Resumen y estados". |
| `components/dashboard/operations/Entries.tsx` | Las tablas de horas y de costos avisan cuántos registros hay (para el contador de la pestaña). Dentro de un proyecto, la de horas muestra **Tarea** en vez de "Contexto" y la de costos no repite la columna "Proyecto". |
| `components/ui/proto.tsx` | `Card` acepta botones en su cabecera (`actions`); `Kpis` tiene la fila compacta del prototipo (`row`) y `Kpi` puede ir sin ícono. Sin esas opciones, todo se ve igual que antes. |
| `components/ui/kanban.tsx` | Opción de columnas algo más angostas (`narrow`), usada solo en el tablero de tareas. |

### API

Nada.

## Qué NO se tocó

- El listado de proyectos, Horas y costos, y las demás pantallas con tablero o indicadores (usan las mismas piezas sin las opciones nuevas; se comprobó Horas y costos).
- Ningún dato de la ficha se perdió: "x/x tareas" pasó a su indicador; "por aprobar" y "% del ingreso" siguen bajo Horas aprobadas y Margen; Cliente, Contrato y Vigencia siguen en "Presupuesto vs real"; el historial pasó dentro de "Estados"; las tareas canceladas se listan bajo el tablero.

## Decisiones tomadas en esta tanda (dime si alguna la quieres distinta)

1. **El historial va dentro de la tarjeta "Estados"**, como en el prototipo (antes era una tarjeta aparte). Quedan dos tarjetas movibles.
2. **Las tablas de horas y de costos de la ficha no repiten el proyecto:** la de horas muestra la tarea (y la descripción), como el prototipo. En Horas y costos siguen con "Contexto" y "Proyecto".
3. **Los contadores de Horas y Costos cuentan lo que quien mira alcanza** (un técnico ve el número de sus horas, no el de todo el proyecto). Sin permiso de ver, la pestaña no se esconde: sale sin contador y con el candado dentro.
4. **Columnas del tablero de tareas de 220 px como mínimo** (el prototipo, 250): con 250, en una pantalla de 1366 px el tablero se pasaba 6 px y sacaba barra horizontal.
5. **El orden de las tarjetas de "Resumen y estados" empieza de cero** (clave nueva en el navegador): si habías movido tarjetas en la ficha anterior, ese orden ya no aplica.
6. **La pestaña abierta no se recuerda** al salir de la ficha: siempre abre en Tareas, como el prototipo.

## Diferencias con el prototipo

- Sin la nota bajo la tabla de entregables ("Preparar → documento → …") ni la de "Métricas canónicas": son textos de la demo.
- "Presupuesto vs real" conserva Cliente, Contrato y Vigencia, que el prototipo no muestra ahí.
- Los indicadores de Horas aprobadas y Margen conservan su línea pequeña ("por aprobar", "% del ingreso").
- El tablero dice bajo él si las tareas se pueden mover ("Arrastra una tarea…", "Las tareas se mueven con el proyecto en ejecución", "Proyecto cerrado").

## Base de datos

Sin cambios.

## Cómo se probó

`php artisan test`: **152 pruebas pasan** (las mismas; la API no cambió). `tsc` y `eslint` sin errores (quedan los dos avisos anteriores de `lib/api.ts`).

En el navegador (`app.localhost:3000`), a 1366 px:

- **Operaciones**, en un proyecto de prueba en ejecución: crear tarea desde el botón bajo el tablero (el contador de la pestaña sube); arrastrarla a En curso; "Mover a…" → Bloqueada con motivo; registrar horas desde la pestaña Horas (contador 0 → 1, indicador "1.5 h por aprobar", botones de aprobar deshabilitados con "No puedes aprobar tus propias horas"); registrar costo (contador 0 → 1); crear entregable ("Enviar a revisión" deshabilitado sin documento); en Resumen y estados: pausar con motivo (el tablero deja de moverse), reanudar, cerrar con una tarea abierta no pasa (mensaje de la API); mover una tarjeta con el teclado y "Restablecer orden". Proyecto cerrado `PRY-2026-0001`: las cinco pestañas con sus datos de siempre.
- **Técnico:** en `PRY-2026-0001` ve Horas 1 (solo las suyas) y Costos 0; Editar con candado; el proyecto de prueba, que no es suyo, "no existe".
- **Gerencia:** Editar, Registrar horas, Nueva tarea y Pausar con candado y su mensaje; Cerrar proyecto habilitado; aprueba las horas desde la pestaña y los indicadores se actualizan (costo real y margen).
- **Sin permiso de ver horas o entregables** (respuesta de la API simulada en el navegador): la pestaña sigue, sin contador, y dentro sale "Tu rol no tiene permiso …".
- **Carga:** primero la ficha; después las opciones y las dos tablas (las mismas llamadas que antes).
- **Oscuro:** capturas de Tareas y de Resumen y estados.
- **375 px:** las cinco pestañas sin desborde horizontal (medido por código); indicadores a dos por fila; capturas de Resumen y de Horas.

### Lo que no se probó

- **Arrastrar una tarea con el ratón de verdad:** la herramienta no dispara el arrastre del navegador; se envió por código, como en la tanda anterior.
- **Aprobar un costo, y devolver, aprobar y entregar un entregable** desde las pestañas nuevas: son los mismos botones de antes, movidos de sitio; se probó que aparecen y su estado.
- **Capturas en claro a 375 px:** la ventana de pruebas se ocultó al final; a 375 px se capturó en oscuro y se midió el desborde por código.

### Datos de prueba que quedaron en "Tenant A - Prueba"

- Proyecto `PRY-2026-0003` "Proyecto de prueba de pestañas (prueba)", en ejecución, con una tarea bloqueada, 1,5 h aprobadas de Operaciones (11 oct., 6:00), un costo de S/ 40 por aprobar y un entregable en preparación.

## Pendiente

- La otra mitad de la propuesta (listado con la rejilla de tarjetas del prototipo) no se hizo: elegiste solo la ficha.
- En teléfono, con cinco indicadores a dos por fila, Margen queda solo en la última fila (igual que en el prototipo).

---

# Segunda parte (mismo día): margen de las tablas y archivo del entregable

## Qué se pidió

1. "Dale un padding a las tablas, se ven muy al borde."
2. "En Entregables, en Adjuntar, el modal muestra mucho más cosas en el prototipo" (Título, Categoría, Vincular a, Visibilidad, el archivo y los pasos Subida, Antivirus, Hash, Versión, Vínculo).
3. Qué pasó en el comentario "Bloqueada (Falta acceso del cliente (pruFalta acceso…": fue un error mío al probar (escribí el motivo dos veces en el campo). No es un fallo del sistema; el historial no se corrige porque la auditoría es inalterable.

## Cómo funciona

- **Margen:** las tablas de Horas, Costos y Entregables de la ficha ya no tocan el borde de su tarjeta. La de horas ya no saca barra horizontal a 1366 px.
- **Adjuntar** abre "Subir documento", como el prototipo, y **sube el archivo de verdad**:
  - Título (propone el nombre del entregable), Categoría (las once del prototipo; propone "Entregable"), Vincular a (fijo: ese entregable) y Visibilidad (Interno / Visible para el cliente en el portal).
  - Zona para elegir o arrastrar el archivo: PDF, documentos de oficina, imágenes, comprimidos, planos y video, hasta 20 MB.
  - Los cinco pasos se pintan con lo que pasó de verdad: tamaño recibido, antivirus, huella, número de versión y vínculo.
- **La huella SHA-256** se calcula en el navegador y la API la compara con lo que recibió: si no coincide, rechaza el archivo.
- **Antivirus:** no hay motor conectado. El paso lo dice tal cual ("Sin analizar: aún no hay antivirus conectado"), en ámbar; no se muestra "Limpio".
- **Versiones:** volver a adjuntar ("Nueva versión") crea la versión 2 del mismo documento; la anterior no se destruye.
- En la tabla, el documento muestra su título (clic = descargar), la versión, el nombre del archivo y el tamaño. La descarga usa un enlace temporal de 5 minutos.
- Con el archivo adjunto, "Enviar a revisión" se habilita (la regla "sin documento no pasa a revisión" es la misma).
- Los entregables anteriores que solo tenían un enlace escrito lo conservan y lo siguen editando en "Editar"; también pueden recibir un archivo ("Adjuntar archivo").

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Documents/Support/DocumentStore.php` (nuevo) | Guarda un archivo como documento con versiones, lo vincula a un registro y da su enlace temporal de descarga. |
| `Operations/Http/Controllers/ProjectController.php` | Adjuntar el archivo de un entregable y pedir su enlace de descarga; la ficha entrega los datos del archivo. |
| `Operations/Models/Deliverable.php`, `Documents/Models/Document.php` | El entregable conoce su documento; el documento guarda su visibilidad. |
| `routes/api.php` | `POST /projects/{p}/deliverables/{d}/document` (`deliverables.manage`) y `GET …/document-url` (`deliverables.view`). |
| `Services/AuditCatalog.php` | Textos de "Documento del entregable adjuntado" y "… descargado". |
| `tests/Feature/OperationsProjectsTest.php` | Una prueba nueva. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/operations/DeliverableDocumentModal.tsx` (nuevo) | La ventana "Subir documento". |
| `components/dashboard/operations/ProjectDetail.tsx` | Margen de las tablas; columna Documento con el archivo y su descarga; botones Adjuntar / Nueva versión. |
| `components/dashboard/operations/ProjectModals.tsx` | "Nuevo entregable" y "Editar" ya no piden el enlace (solo lo muestra el entregable que ya tenía uno). |
| `components/dashboard/operations/Entries.tsx` | La columna Tarea de la ficha ya no fuerza un ancho mínimo. |
| `lib/api.ts` | El cliente de la API sabe enviar un archivo (formulario en vez de JSON). |
| `lib/operations.ts`, `types/operations.ts` | Categorías, tamaño de archivo en texto y tipos. |

## Qué NO se tocó

- Tu `DocumentController` (`/documents`) sigue igual. **Ojo:** esa ruta no limita el tipo de archivo; conviene pasarla por la misma pieza cuando se haga la pantalla Documentos.
- La regla de la base "sin documento no pasa a revisión" es la misma: `document_ref` ahora guarda el nombre del archivo.

## Decisiones tomadas (dime si alguna la quieres distinta)

1. **Subida real**, no el simulador del prototipo, usando tus tablas de documentos y tu almacén de archivos, que ya existían.
2. **Adjuntar pide `deliverables.manage` y descargar `deliverables.view`**, siempre dentro de los proyectos que la persona alcanza. No piden los permisos `documents.*` ni el módulo Documentos del plan: el archivo es parte del entregable.
3. **Solo archivos de trabajo** (lista cerrada de extensiones) y se mira el contenido: un programa con nombre de PDF se rechaza.
4. **Sin antivirus, el archivo se acepta y se puede descargar**, marcado "sin analizar". Bloquear la descarga hasta analizar dejaría la función sin uso mientras no haya antivirus.
5. **"Visible para el cliente en el portal" se guarda** aunque el portal aún no existe (la ventana lo avisa).
6. **"Vincular a" va fijo** al entregable desde el que se abre (en el prototipo es una lista de todo el sistema; eso es de la pantalla Documentos).
7. **La ruta del archivo en el almacén no usa el nombre original** y va en la carpeta de su empresa.
8. **Las versiones de un documento no se modifican ni se borran**: se le quitó ese permiso a la aplicación en la base.
9. **El campo de enlace desaparece** de "Nuevo entregable": el documento se adjunta desde la tabla.

## Base de datos

`2026_10_13_000300_deliverable_document_file`, **ya corrida en la base local** con el usuario dueño. Añade `deliverables.document_id` y `documents.visibility` (Interno o Portal), y quita a la aplicación el permiso de modificar o borrar `document_versions`.

## Cómo se probó

`php artisan test`: **153 pruebas pasan** (152 + 1). La nueva comprueba: título y archivo obligatorios; `.exe` y programa disfrazado de PDF rechazados; categoría inventada; huella que no coincide; nada queda en el almacén ni en la base tras un rechazo; alta con versión 1, vínculo y carpeta de la empresa; segunda subida = versión 2; la base no deja borrar versiones; editar no cambia el documento; descarga con enlace y auditoría; ya en revisión no se cambia; sin archivo, 404; Gerencia no adjunta (403) y sí descarga; sin `deliverables.view`, 403; proyecto ajeno, 404. `tsc` y `eslint` sin errores.

En el navegador, contra el almacén real (MinIO local):

- **Operaciones:** "Subir" sin archivo avisa; un `.exe` marca el paso Subida en rojo con el motivo; un PDF sube y los cinco pasos se llenan (huella "verificado", v1, vínculo); la fila muestra título, versión, nombre y tamaño; "Nueva versión" deja v2; "Editar" ya no pide enlace.
- **Descarga:** el enlace temporal entrega el archivo con su nombre; sin la firma, el almacén responde 403 (comprobado por fuera del navegador).
- **Gerencia:** Nuevo entregable, Editar, Nueva versión y Enviar a revisión con candado; descargar habilitado.
- **Margen:** primera columna a 24 px del borde (título a 20) y 10 px bajo la tabla; Horas y Costos sin barra horizontal a 1366 px.
- **Oscuro y 375 px:** la ventana con la subida terminada, sin desborde (pasos a dos por fila).

### Lo que no se probó

- **Arrastrar un archivo con el ratón** a la zona y **el clic de descarga en el navegador:** las herramientas no manejan archivos del disco; el archivo se puso por código y la descarga se comprobó pidiendo el enlace por la API.
- **Un archivo grande** (cerca de 20 MB) contra el servidor real; el límite está en la prueba automática y en la ventana.
- **Almacén caído:** no lo apagué para ver el mensaje.

### Datos de prueba

El entregable "Informe de prueba (prueba)" de `PRY-2026-0003` tiene un documento con dos versiones (dos PDF de pocos bytes en el almacén local).

## Pendiente

- **Antivirus real** (por ejemplo ClamAV) y decidir si un archivo sin analizar se puede descargar.
- Pantalla **Documentos** (listar, versiones, accesos) y pasar `/documents` por la misma pieza.
- Evidencias de las órdenes de trabajo con archivo, usando esta misma ventana.
