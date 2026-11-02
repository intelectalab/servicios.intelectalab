# Importación masiva (Administración)

Fecha: 2026-11-01.

## 1. Qué se pidió

Construir las cuatro pantallas que faltaban en Administración, empezando por **Importación masiva** (orden elegido: Importación → Notificaciones → Reglas de aprobación → Seguridad y aislamiento).

## 2. Qué se cambió

**Pantalla** (`/imports`; `components/dashboard/admin/ImportsView.tsx`), con la disposición del prototipo (dos columnas):

- **1. Archivo:** "Qué vas a importar" (Clientes y actores, Catálogo de servicios, Leads del CRM; solo las que el plan y el rol permiten), qué acepta cada una, el archivo (Excel o CSV, arrastrar o buscar) o **pegar el contenido** CSV, "Descargar plantilla" y **"Validar"**.
- **2. Vista previa y validación:** "N filas · X listas · Y se omitirán · Z con errores", la tabla con la fila, sus primeras columnas y el resultado con su motivo, e **"Importar filas válidas"** (deshabilitado si no hay ninguna).
- **Columnas esperadas** de la entidad elegida, con "obligatoria".
- **Historial:** archivo, qué se importó, fecha, creados, omitidos y estado (Completada, Con omisiones, Fallida), con filtros, orden, paginación y exportar al pie. El clic en la fila abre el detalle con las filas que no entraron y su motivo.

**Validar sin crear nada:** cada importador acepta `dry_run`. Hace todo, fila por fila, dentro de una transacción que se deshace al final, y devuelve qué pasaría con cada fila. Así la vista previa aplica las mismas reglas que la importación: RUC y duplicados en Clientes y actores, código repetido en Servicios, fuente y correo repetido en Leads.

**Pieza compartida** `App\Services\ImportReport`: cada fila en su propia transacción, resultado por fila (creada, actualizada, omitida, con error) e historial. La respuesta mantiene la forma de siempre, así que el botón Importar de cada listado sigue igual.

**Leads importables** (pendiente del CRM): plantilla `GET /v1/crm/leads/template` (empresa, contacto, cargo, correo, teléfono, fuente con lista desplegable, interés) e importación `POST /v1/crm/leads/import`. Siguen las reglas del formulario:
- Hace falta la empresa o el contacto, y la fuente debe existir.
- No entra un correo que ya tiene un lead abierto.
- Nace Nuevo, a nombre de quien importa, en su sede, enlazado a la organización si ya existe, y con su puntaje calculado.

**Historial también desde cada listado:** lo importado desde Clientes y actores, Servicios, Plantillas de checklist y Asientos (con su botón de siempre) también queda en el historial.

**Permisos** (matriz del prototipo):
- `imports.view` (abrir la pantalla y ver el historial), `imports.create` (importar) e `imports.export`.
- **Importar pide `imports.create` además del permiso de crear de la entidad**, también desde cada listado.
- Quien ya importaba algo lo conserva (Administrador, Ventas, Operaciones, Finanzas). Gerencia ve y exporta.
- Alcance nuevo **"Importaciones"**: lo propio es lo que la persona importó. Gerencia y Finanzas ven toda la empresa; el resto, lo propio.

## 3. Qué NO se tocó

- Plantillas de checklist y Asientos se siguen importando desde su pantalla, sin vista previa: agrupan varias filas por registro. Sí quedan en el historial.
- El extracto bancario (Bancos y caja) no es una importación de esta pantalla.

## 4. Diferencias con el prototipo

- Además de CSV, se aceptan Excel (.xlsx) y las plantillas con formato de siempre.
- **No se trae "Cargar ejemplo":** llenaba el cuadro con datos de demostración que, al importarlos, quedarían en la empresa.
- En el prototipo Ventas solo importa leads. Aquí manda el permiso: Ventas también gestiona Clientes y actores (`core.manage`, desde el 2026-10-08), así que también puede importarlos, como ya hacía desde su listado.
- El prototipo exige correo en los leads. Aquí rige la regla del formulario: empresa o contacto, y la fuente.
- Mejoras: historial con detalle por fila, filtros y exportación; el historial incluye lo importado desde cada listado.

## 5. Base de datos

Migración `2026_11_01_000700_bulk_imports` (como `erp_user`):

- Tabla `import_jobs` con su regla de aislamiento. La app solo agrega y lee: no se edita ni se borra. Guarda hasta 500 filas que no entraron.
- Permisos `imports.*` y su reparto, y alcance `imports` en `role_scopes` (CHECK actualizado).

## 6. Cómo se probó

- `BulkImportTest` (4 pruebas nuevas):
  - validar no crea nada (tampoco categorías ni historial) y da el resultado de cada fila;
  - importar crea solo las filas válidas y deja el historial con su detalle; un archivo sin filas válidas queda como Fallida;
  - la plantilla de leads, tal como se descarga, entra completa; las filas con fuente inexistente, sin nombre o con correo repetido no entran;
  - sin `imports.create` no se importa;
  - el historial sigue el alcance (lo de otra persona da 404);
  - una entidad fuera del plan no se ofrece;
  - la base no deja editar ni borrar el historial.
- Las pruebas de importación de antes (Clientes y actores, Servicios, Checklist, Asientos) pasan igual.
- En el navegador, como Administrador:
  - Leads: validé 3 filas pegadas (1 lista, 2 con error, con su motivo), importé la válida (queda el lead **"Importación de Prueba SAC"**, de prueba) y abrí su registro del historial.
  - En 375 px no hay desplazamiento horizontal.
- Como Ventas (por la API): se le ofrecen Clientes y actores y Leads, y no ve la importación del Administrador.

## 7. Pendiente

- Vista previa también para Plantillas de checklist y Asientos.
- Importar otras entidades (productos, contactos de una organización, oportunidades) si se piden.
