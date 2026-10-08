# 2026-10-08 — Importar en Clientes y actores y en Catálogo de servicios

Continúa [2026-10-07_clientes-y-actores.md](2026-10-07_clientes-y-actores.md).

## Qué se pidió

La opción de **importar** que tenían tus módulos, junto al botón "Nueva…" en Clientes y actores y en Servicios.

## Cómo era en tus módulos

Un modal con: descargar la **plantilla Excel** (con instrucciones y ejemplos), elegir un archivo `.xlsx` o `.csv`, e importar. La cabecera se reconoce por sus nombres, las columnas extra se vuelven columnas personalizadas, y las filas con problemas vuelven como avisos.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `app/Services/SpreadsheetImport.php` (nuevo) | Pieza común: lee `.xlsx`, `.xls` o `.csv` (reconoce la cabecera por sus nombres, acepta tildes, espacios y guiones en los títulos, ignora las filas que empiezan con `#`) y genera la plantilla Excel con los colores del logo. |
| `Modules/Core/Http/Controllers/PartyController.php` | `GET /parties/template` y `POST /parties/import`. Una fila por organización o persona, con roles, sector, SLA, correo, teléfono con prefijo, dirección, cargo, "contacto_de" (RUC o nombre de la organización) y condiciones comerciales. Usa las mismas validaciones del formulario. **Si el documento ya existe no se duplica:** se le agregan los roles que le falten. Las columnas que no son de la plantilla se guardan como columnas personalizadas (se crean si no existen). |
| `Modules/Services/Http/Controllers/ServiceController.php` | `GET /services/template` y `POST /services/import`. Una fila por servicio: código, nombre, categoría (se crea si no existe), unidad, modalidad, frecuencia, garantía, precio y descripción. Entran en **borrador**; un código que ya existe se omite. |
| `routes/api.php` | Las plantillas piden el permiso de ver (`core.view`, `services.view`); importar, el de gestionar (`core.manage`, `services.manage`). |
| `tests/Feature/ComercialTest.php` | Prueba nueva: importar actores desde CSV (con instrucciones, títulos con tildes y una columna extra), filas con error, reimportar sin duplicar, archivos inválidos, importar servicios, **importar las plantillas Excel tal como se descargan**, y los permisos. |

Cada fila se guarda por separado: una fila mala no detiene a las demás. Si ninguna entra, la API responde con el listado de motivos.

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/ImportModal.tsx` (nuevo) | Modal común: Plantilla Excel, zona para elegir o arrastrar el archivo, y el resultado (cuántas entraron y, fila por fila, cuáles no y por qué). |
| `components/dashboard/actors/ActorsList.tsx` | Botón **Importar** junto a Nueva organización y Nueva persona. |
| `components/dashboard/services/ServicesList.tsx` | Botón **Importar** junto a Nuevo servicio. Tras importar también se recargan las categorías del filtro. |

## Qué NO se tocó

La importación de Clientes, Proveedores y Empleados, con sus plantillas. El resto de Servicios.

## Diferencias con tu importación

- El resultado **no se cierra solo** a los 2 segundos: queda a la vista con los avisos por fila hasta pulsar Cerrar.
- Se puede **arrastrar** el archivo al recuadro.
- En actores, una fila cuyo documento ya existe **no crea un duplicado** (tu importación de Clientes sí crea otro registro).
- Sin migraciones.

## Base de datos

Datos de prueba que quedaron en "Tenant A - Prueba": la organización **Importado de Prueba S.A.C.** (RUC 20999999992, cliente) y el servicio **SRV-IMP-01 Servicio importado de prueba** (borrador, S/ 250.00).

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test`: las 58 pruebas pasan. En el navegador con `admin@erp-saas.test`:

1. **Clientes y actores:** el botón Importar abre el modal; "Plantilla Excel" genera el archivo (.xlsx válido, 7.9 KB); un `.txt` se rechaza con "Formato no admitido"; con un CSV de tres filas entró 1 ("Importado de Prueba S.A.C.", que apareció en el listado) y se avisó de las otras dos: "Fila 3: Indica el RUC." y "Fila 4: «Actor de Prueba S.A.C.» ya existía; no se cambió."
2. **Servicios:** con un CSV de tres filas entró 1 (SRV-IMP-01, en borrador, recurrente mensual, S/ 250.00) y se avisó del código repetido y de la fila sin código.

No se probó en el navegador: subir un `.xlsx` (sí en las pruebas automáticas, con las propias plantillas), arrastrar el archivo, abrir la plantilla en Excel, ni el candado del botón con un rol sin permiso.

## Pendiente

- La lista de países y esta importación no existen en Clientes, Proveedores y Empleados con el estilo nuevo: siguen con las suyas.
