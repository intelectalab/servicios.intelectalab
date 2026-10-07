# 2026-10-07 — Comercial: Contratos

Continúa [2026-10-06_sidebar-replegado-grupos.md](2026-10-06_sidebar-replegado-grupos.md).

## Qué se pidió

Seguir con el siguiente módulo de Comercial: **Contratos**, con las reglas de siempre (prototipo, colores del logo, listado con selección/exportar/paginación, ficha con tarjetas movibles, responsive, permisos con candado).

## Cómo es en el prototipo

- **Listado:** Contrato (número y "desde COT-…"), Cliente, Vigencia, SLA, Valor, Estado; buscador y filtro por estado.
- **Ficha:** cabecera con cliente, vigencia y SLA; botones Activar contrato, Descargar PDF y, si está vigente, Crear orden. Tarjetas: Líneas contratadas, Servicios contratados (Operaciones), Firma electrónica, Estados, Relacionado e Historial.
- **Estados:** Borrador → Vigente (Activar); Vigente → Suspendido (con motivo), Terminado (con motivo) o Vencido; Suspendido → Vigente (Reactivar) o Terminado.
- Los contratos solo nacen desde una cotización aprobada, copiando sus líneas.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `database/migrations/2026_10_07_000000_add_sla_level_and_approval_to_contracts.php` (nuevo) | Columna `contracts.sla_level` (ESTANDAR o PREMIUM; los existentes quedan en ESTANDAR) y permiso nuevo `contracts.approve` ("Activar contratos"), que reciben los roles que ya tenían `contracts.manage`. |
| `Modules/Services/Http/Controllers/ContractController.php` | **Listado:** buscador por número o cliente, filtro por estado, cliente y cotización incluidos. **Ficha:** líneas, cliente, cotización, responsable, órdenes, servicios contratados e historial. **Generar desde cotización:** ahora nace vigente desde mañana y por un año, con las condiciones de la cotización (antes quedaba sin fechas). **Editar** (solo borrador): inicio, fin, SLA y condiciones. **Activar:** exige líneas y fecha de inicio, y puede generar un servicio contratado en PENDIENTE por cada línea con servicio. **Suspender** y **Terminar** (con motivo obligatorio), **Reactivar**, **Marcar vencido**. **Crear orden** desde un contrato vigente (una por contrato). Todo queda en la auditoría con estado de origen y destino. |
| `Modules/Services/Models/Contract.php` | Campo `sla_level` y relaciones con líneas, cliente, responsable y órdenes. |
| `routes/api.php` | Rutas nuevas. Editar y cambiar de estado piden `contracts.manage`; activar, `contracts.approve`; crear orden, `orders.manage`. |
| `tests/Feature/ComercialTest.php` | Prueba nueva del ciclo completo: generar, listar y buscar, editar, activar con su permiso, servicios contratados, orden única, suspender con motivo, reactivar, terminar, historial y 404. |

**Lógica que cambió:** activar un contrato pedía `contracts.manage` y ahora pide `contracts.approve`; además ya no se activa un contrato sin líneas o sin fecha de inicio.

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/contracts/ContractsList.tsx`, `app/(dashboard)/contracts/page.tsx` (nuevos) | Listado con las columnas del prototipo, buscador "Buscar contrato o cliente", filtro Estado, casillas, exportar a Excel/CSV y paginación. |
| `components/dashboard/contracts/ContractDetail.tsx`, `app/(dashboard)/contracts/[id]/page.tsx` (nuevos) | Ficha con tarjetas movibles (Líneas contratadas, Servicios contratados, Firma electrónica, Estados, Relacionado, Historial), modal "Activar contrato" con la casilla de generar servicios, modales de confirmación con motivo, modal "Editar contrato" y Descargar PDF. Botones con su permiso (candado y mensaje). |
| `lib/contracts.ts` (nuevo) | Estados, etiquetas de SLA y facturación, y el PDF del contrato. |
| `lib/document-pdf.ts` (nuevo), `lib/quotation-pdf.ts` | La composición del PDF pasa a una pieza común que usan la cotización y el contrato; el de cotización conserva su contenido. |
| `components/dashboard/quotations/QuotationDetail.tsx` | "Convertir en contrato" abre la ficha del contrato creado, como el prototipo; en Trazabilidad el contrato es un enlace. |
| `components/layout/AppShell.tsx`, `proxy.ts` | "Contratos" deja de estar atenuado en Comercial (módulo CONTRATOS, permiso `contracts.view`). |
| `types/commercial.ts`, `lib/commercial.ts` | Tipos de contrato y la etiqueta del historial "Borrador editado". |

## Qué NO se tocó

Clientes, Proveedores, Empleados, Planes SaaS. Catálogo de servicios. La lógica de cotizaciones, salvo que "Convertir en contrato" ahora navega al contrato.

## Diferencias con el prototipo

- **"Editar" en borrador es añadido.** El prototipo no deja editar el contrato; aquí se pueden ajustar vigencia, SLA y condiciones mientras es borrador (los dos borradores que ya existían no tenían fechas y no se habrían podido activar). Las líneas no se editan.
- **Firma electrónica:** no está construida. La tarjeta muestra el texto del prototipo para cuando no es obligatoria, y no existen "Enviar a firma" ni "Firmar por la empresa".
- **Sin "Adjuntar documento"** ni la fila Documentos en Relacionado: dependen de la pantalla Documentos.
- **Servicios contratados:** se generan y se listan con su estado, pero sin el conteo de OT y proyectos ni enlace (la pantalla de Operaciones no existe). No se generan instancias recurrentes.
- **Crear orden:** tras crearla, el prototipo lleva al listado de Órdenes; aquí se queda en la ficha (esa pantalla no existe).
- El SLA del prototipo sale del cliente; aquí se guarda en el contrato y nace como Estándar.
- No se trajo la nota explicativa bajo el título.

## Base de datos

- Migración `2026_10_07_000000` **ya corrida** en la base local (solo añade).
- Datos de prueba movidos en "Tenant A - Prueba": **CTR-2026-0002** pasó de borrador a vigente (vigencia 08/10/2026 → 07/10/2027, SLA Premium), se le generó un servicio contratado, la orden **ORD-2026-0003**, y se suspendió y reactivó. CTR-2026-0003 sigue en borrador, sin fechas.

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test`: las 54 pruebas pasan. En el navegador con `admin@erp-saas.test`, a 1440 px:

1. **Listado:** los tres contratos con sus columnas; filtro con los cinco estados; "Contratos" activo en el menú.
2. **Borrador sin fechas** (CTR-2026-0002): "Activar contrato" deshabilitado con "El contrato activo requiere líneas y vigencia".
3. **Editar:** vigencia, SLA Premium y condiciones → la cabecera se actualiza y Activar se habilita.
4. **Activar** con la casilla marcada: pasa a Vigente y aparece el servicio contratado "Mantenimiento preventivo · Pendiente".
5. **Crear orden:** crea ORD-2026-0003; el botón queda con "Ya tiene orden" y Relacionado la muestra.
6. **Suspender:** sin motivo avisa "Este dato es obligatorio."; con motivo pasa a Suspendido (quedan Reactivar y Terminar). **Reactivar** vuelve a Vigente; el historial muestra cada paso con su motivo.
7. **PDF** (revisado a la vista): "CONTRATO DE SERVICIOS", datos del cliente y SLA, vigencia, cotización de origen, línea, valor total, condiciones, cláusulas y firmas.
8. **375 px** (por medición y una captura de la ficha): listado y ficha sin desplazamiento horizontal, filas como tarjetas.

No se probó en el navegador: Terminar y Marcar vencido (sí en las pruebas automáticas), los candados con un rol sin permiso, el Excel exportado, ni volver a abrir el PDF de cotización tras pasar su composición a la pieza común (compila y usa el mismo código).

## Pendiente

- Pantalla **Órdenes** (hay tres órdenes creadas que solo se ven desde la cotización o el contrato).
- Firma electrónica, adjuntar documentos, instancias recurrentes y el enlace con Operaciones.
- Los planes SaaS deben incluir el módulo Contratos para que una empresa nueva lo vea.
