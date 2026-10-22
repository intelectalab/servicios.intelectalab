# 2026-10-22 — Documentos (Control → Documentos)

## Qué se pidió

El módulo Documentos: la pantalla Documentos del prototipo y su capítulo del kit (`07-documentos-vault.md`). Subir archivos a un cliente, contrato o proyecto, con versiones y por `DocumentStore`, y marcar cuáles ve el cliente en Documentos del portal. El botón "Adjuntar documento" de la ficha del contrato solo avisaba que no estaba disponible.

Antes de construir se hizo el inventario de lo que muestra el prototipo y lo que pide el kit. Tus respuestas:

1. **Permisos:** cuatro (ver, subir, gestionar y exportar), con el reparto de la matriz del prototipo: "está bien, hazlo".
2. **Mostrar al cliente** exige gestionar documentos: "de acuerdo".
3. **Alcance:** lo propio es lo que la persona subió y Sede es la sede del documento. En la ficha de un registro que la persona ve aparecen todos sus documentos. Los extractos bancarios solo los ve quien tiene Tesorería: "yap".
4. **Vincular a:** un registro por documento (cliente o actor, contrato, proyecto u OT). Los entregables siguen con su "Adjuntar": "yap".
5. **Antivirus:** "simúlalo por mientras y déjame la opción que podría comentarle a mi supervisora". La opción está en [../propuesta-antivirus.md](../propuesta-antivirus.md).
6. **Botones que solo avisaban:** "conéctalo".

Lo que quedó a mi criterio:

- El registro de accesos se lee de la auditoría, sin tabla nueva.
- El límite sigue en 20 MB (el prototipo dice 25).
- Exportar va al pie.
- Un documento archivado deja de verse en el portal.

## Qué se cambió

### Pantalla Documentos (`/documents`, menú Control)

Como el prototipo, con los colores del logo:

- **Botón "Subir documento"** (`documents.create`).
- **Filtros:** buscador (título o nombre de cualquiera de sus archivos), Categoría, Estado (Disponible, En cuarentena, Archivado) y Visibilidad (Interno, Visible en portal). También "Filtros por columna" y el chip de alcance cuando el rol no ve toda la empresa.
- **Columnas:**
  - Documento: título, y debajo el archivo y su tamaño.
  - Categoría.
  - Vinculado a: cada vínculo lleva a su ficha si la persona ve ese registro.
  - Versión.
  - Integridad: el comienzo de la huella SHA-256.
  - Visibilidad.
  - Estado.
- Ordenar desde la cabecera, casillas para exportar filas, paginación, y Exportar a Excel y CSV al pie (`documents.export`). A 1366 px cabe sin desplazarse de lado; en teléfono la tabla pasa a tarjetas.
- **Clic en la fila:** abre la ficha. También se abre con `/documents?document=<id>`.

**Ventana "Subir documento"**

- Campos: Título, Categoría (las 11 del prototipo), Vincular a y Visibilidad.
  - Vincular a es un desplegable con buscador. Muestra clientes y actores, contratos, proyectos y OT, solo los que el rol alcanza.
- Zona para elegir o soltar el archivo, y la línea de pasos del prototipo: Subida, Antivirus, Hash, Versión y Vínculo. Cada paso se llena con lo que respondió la API: tamaño recibido, resultado del antivirus, huella verificada contra la que calculó el navegador, número de versión y vínculo.
- Sin `documents.manage`, la visibilidad queda en "Interno", con candado y el texto "Tu rol no tiene permiso documents.manage".
- "Visible para el cliente en el portal" exige un vínculo con algo de un cliente.

**Ficha del documento** (ventana, como el panel del prototipo)

- Datos: categoría, estado, visibilidad, vínculos, archivo, huella completa, sede, quién lo creó y, si está archivado, quién y cuándo.
- **Obtener URL temporal:** ventana con el enlace, cuenta atrás de 5:00, "Copiar enlace" y "Descargar". Queda registrado.
- **Nueva versión:** solo se elige el archivo. Un archivo idéntico a una versión anterior se rechaza ("Ese archivo ya está subido: es idéntico a la versión vN").
- **Editar:** título, categoría y visibilidad (`documents.manage`).
- **Archivar:** pide confirmación (`documents.manage`).
- **Versiones (no se destruyen):** v, Archivo, SHA-256, Antivirus, Autor, Fecha y un botón para descargar esa versión (añadido: el prototipo solo descarga la última).
- **Registro de accesos:** quién, qué y cuándo. Incluye las descargas desde el portal, desde el entregable y desde la evidencia de la OT.
- Sin permiso, cada botón sale con candado y su mensaje.

### Lo que antes solo avisaba

| Dónde | Ahora |
| --- | --- |
| Contrato → "Adjuntar documento" | Abre "Subir documento" vinculado al contrato (no se elige otro registro), con categoría Contrato. |
| Contrato → fila "Documentos" | Lista los documentos del contrato con su versión; clic abre la ficha. |
| OT → tarjeta "Evidencias" | Lista los documentos de la OT y las fotos de su checklist, con estado, versión y huella como el prototipo. "Subir evidencia" sube vinculado a la OT con categoría Evidencia. |
| Clientes y actores → pestaña "Documentos" | Antes siempre vacía. Ahora muestra los documentos del cliente y los de sus contratos, proyectos y OT que el rol ve, con Documento, Categoría, Versión y Estado como el prototipo. Clic abre la ficha. |

### Antivirus simulado

- Todo archivo pasa por el antivirus antes de guardarse, también los entregables, las evidencias y los extractos.
- Hoy el motor es `SIMULATED` (`config/documents.php`, `DOCUMENTS_ANTIVIRUS=simulated`). No analiza de verdad: solo reconoce dos archivos de prueba.
  - El **EICAR**, el estándar para probar antivirus. Ojo: el antivirus de Windows lo borra antes de que llegue al navegador.
  - Un **texto que empiece con `ERP-SAAS-PRUEBA-ANTIVIRUS`**, que ningún antivirus del equipo toca. Así se prueba la cuarentena.
- Cada archivo queda marcado con el motor que lo analizó, y la pantalla dice "Limpio (simulado)". Cuando haya uno real se sabrá cuáles hay que volver a revisar.
- **En la pantalla Documentos**, un archivo con amenaza:
  - se guarda aparte (carpeta `quarantine/`);
  - el documento queda **En cuarentena**;
  - **nunca se entrega**: ni enlace temporal, ni portal, ni "Visible en el portal".
  - Una versión limpia posterior lo vuelve a dejar disponible; la infectada sigue sin descargarse.
- **En los adjuntos de otros módulos** (entregable, evidencia, extracto), un archivo con amenaza se rechaza y no se guarda nada.
- El motor real es una clase más detrás de `AntivirusScanner`. Ver [../propuesta-antivirus.md](../propuesta-antivirus.md).

### Portal de clientes

- Documentos del portal muestra los documentos marcados "Visible en el portal" vinculados a:
  - el cliente (en Clientes y actores);
  - sus contratos: los mismos que ve en su portal, es decir, no borradores salvo los firmados (antes un contrato en borrador firmado dejaba fuera sus documentos);
  - sus proyectos o sus OT.
- Los archivados y los que están en cuarentena no aparecen.
- Las descargas del cliente quedan en el registro de accesos del documento.

### API (`apps/api`)

- `Documents/Http/Controllers/DocumentController.php` (reescrito):
  - `GET /documents`: listado con filtros, filtros por columna y orden. Con `?entity_type=&entity_id=` devuelve los documentos de un registro.
  - `GET /documents/options`.
  - `GET /documents/{id}`.
  - `POST /documents`.
  - `POST /documents/{id}/versions`.
  - `PUT /documents/{id}`.
  - `POST /documents/{id}/archive`.
  - `GET /documents/{id}/download-url?version=`.
  - Cada cambio deja auditoría (`document.create`, `.version`, `.update` con antes y después, `.archive`, `.download_url`).
- `Documents/Support/DocumentAccess.php` (nuevo): qué documentos alcanza la persona, los de un registro, los vínculos con su texto y enlace, y si un vínculo es de un cliente.
- `Documents/Support/DocumentStore.php`:
  - antivirus;
  - rechazo de versión repetida;
  - sin versiones en un archivado;
  - sede de quien sube;
  - estado según la última versión;
  - descarga de una versión concreta;
  - nunca entrega un archivo infectado.
- `Documents/Antivirus/` (nuevo): `AntivirusScanner` (contrato), `ScanResult` y `SimulatedScanner`; se elige en `AppServiceProvider`.
- Se borró `StoreDocumentRequest` (lo usaba solo el controlador anterior, que no pasaba por `DocumentStore` ni aplicaba alcance).
- `AccessScope`: recurso `documents` (lo propio = lo que subió). `ListExport`: `documents.export`. `CheckRecordVersion`: `lock_version` del documento (dos personas no se pisan la edición). `AuditCatalog`: textos de las acciones nuevas.
- `PartyController`: la pestaña Documentos del cliente. `PortalFinanceController`: vínculos con el cliente y con sus OT, y la regla de contratos del portal. La ruta de descarga del portal usa `{file}` para no tocar la versión del documento.
- `ProjectController`: el archivo del entregable dice qué motor lo analizó.

### Web (`apps/web`)

- `components/dashboard/documents/DocumentsList.tsx` y `DocumentModals.tsx` (nuevos), `app/(dashboard)/documents/page.tsx`, `lib/documents.ts` y `types/documents.ts`.
- `DocumentModals.tsx` tiene la ventana de subida, la ficha, el enlace temporal, editar, las etiquetas de vínculos y `useRecordDocuments` (documentos de un registro para su ficha). La línea de pasos y la zona del archivo son piezas compartidas: también las usa `DeliverableDocumentModal.tsx`.
  - De paso, en el archivo del entregable se quitó la nota "el portal del cliente aún no existe".
- `AppShell.tsx`: "Documentos" deja de estar "próximamente" (módulo DOCUMENTOS, `documents.view`). `proxy.ts`: ruta `/documents`. `ScopeNote.tsx`: texto del alcance de documentos.
- `ContractDetail.tsx`, `WorkOrderDetail.tsx`, `ActorDetail.tsx`: lo de la tabla de arriba.

## Qué NO se tocó

- Los adjuntos que ya existían (entregables, fotos de checklist de la App del técnico, extractos bancarios) siguen con sus pantallas y permisos. Ahora pasan por el antivirus y aparecen en Documentos para quien los alcanza.
- Credential Vault (P-DOC-02 del kit) sigue "próximamente".

## Diferencias con el prototipo

- La ficha es una ventana (como las demás fichas del sistema), no un panel lateral.
- Se añadieron:
  - "Editar", que el prototipo no tiene: sin él no habría cómo mostrar u ocultar un documento al cliente después de subirlo;
  - descargar cada versión;
  - "Copiar enlace" y "Descargar" en la ventana del enlace temporal.
- Un documento archivado se puede seguir descargando (el prototipo solo descarga los disponibles). Uno con amenaza nunca.
- Sin las notas del prototipo bajo el título ("Única ruta para archivos…") ni los textos de su almacén simulado.
- Etiquetas de vínculo legibles ("Contrato CTR-2026-0007", "OT-2026-0006") en vez del tipo interno.

## Base de datos

Migración `2026_10_22_000300_documents_module.php`. Ya está corrida en la PC; **falta en el VPS** (va con `deploy.sh`).

- **Documento:**
  - sede, `lock_version`, archivado (fecha y quién);
  - estados ACTIVE / QUARANTINED / ARCHIVED con CHECK;
  - disparador: uno archivado no vuelve ni se edita, la empresa y el autor no cambian, y uno en cuarentena no se publica en el portal.
- **Archivo:** motor del antivirus y cuándo se analizó.
- **Protección:** la aplicación ya no puede borrar documentos, versiones, vínculos ni archivos, ni cambiar versiones, vínculos ni archivos.
- **Permisos:** `documents.create` y `documents.export` (nuevos), y textos de `view` / `manage`.
  - Reparto: Administrador todo; Gerencia ver y exportar; Operaciones ver, subir y gestionar; Ventas, Técnico, Finanzas y Soporte ver y subir.
  - Alcance: Gerencia, Finanzas y Soporte toda la empresa; Operaciones su sede; Ventas y Técnico lo propio.
- En la PC se activó a mano el módulo DOCUMENTOS en "Tenant A - Prueba" (los tres planes ya lo traían).

## Cómo se probó

- **Pruebas automáticas:** `DocumentsTest` (5 pruebas, nuevas). En total **259 pruebas pasan**. Cubren:
  - subir con vínculo;
  - versiones y versión repetida;
  - descarga por versión y registro;
  - editar y archivar;
  - cuarentena, sin descarga ni portal, y el rechazo en otros módulos;
  - Ventas sin publicar (403);
  - vínculo con cliente obligatorio para el portal;
  - lo que ve el cliente y que el archivado desaparece;
  - alcance propio, ficha del contrato y del cliente, extracto solo con Tesorería y otra empresa (404);
  - lo que la base no deja borrar ni cambiar.
- **En el navegador** (1366 × 768 y 375 px, claro y oscuro):
  - **Administrador:**
    - subió "Anexo SLA firmado" vinculado a CTR-2026-0007, visible en el portal. El enlace temporal se descargó de verdad (200, nombre original);
    - subió la versión v2;
    - subió un archivo con la marca de prueba, que quedó en cuarentena y luego se archivó (la URL temporal sigue con candado);
    - adjuntó un acta desde la ficha del contrato y una foto desde Evidencias de OT-2026-0006;
    - abrió la pestaña Documentos de Minera Prueba;
    - exportó a Excel (archivo válido) y CSV.
  - **Rosa (portal):** ve "Anexo SLA firmado v2" y lo descarga; no ve el acta interna.
  - **Ventas:** alcance "Propios", visibilidad con candado, Editar y Archivar con candado, documentos del contrato en su ficha; subió "Propuesta técnica".

Datos de prueba que quedaron en Tenant A:

- "Anexo SLA firmado" (v2, portal) y "Acta de reunión de inicio" (interno), ambos en CTR-2026-0007;
- "Foto del tablero antes de la intervención" (OT-2026-0006);
- "Adjunto de proveedor (prueba de cuarentena)", archivado;
- "Propuesta técnica enviada al cliente", de Ventas.

## Pendiente

- Antivirus real: ver [../propuesta-antivirus.md](../propuesta-antivirus.md).
- Correr la migración en el VPS (con tu permiso, por `deploy.sh`).
- Credential Vault (P-DOC-02).
- PDF que el sistema genera (cotización, contrato…) guardados como versión en Documentos (RN-DOC-04 del kit): hoy se arman en el navegador.
- Subir desde la pestaña Documentos del cliente: hoy se sube desde Documentos eligiendo el cliente en "Vincular a".
