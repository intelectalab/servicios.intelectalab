# 2026-10-05 — Comercial: campos y funciones que faltaban

Continúa [2026-10-05_comercial.md](2026-10-05_comercial.md). Donde esta entrada y aquella no coincidan, vale esta.

## Qué se pidió

Revisar el prototipo y completar lo que faltaba en Catálogo de servicios y Cotizaciones: funcionalidades, y campos al añadir y al editar.

## Qué se cambió

### Catálogo de servicios

| Qué | Detalle |
| --- | --- |
| Campos nuevos al crear y editar | **Modalidad de facturación** (Único / Recurrente / Por evento), **Frecuencia** (mensual, trimestral, anual; obligatoria si es recurrente), **Garantía (meses)**. La **Categoría** pasa a ser obligatoria. |
| Editar servicio | Botón "Editar" en la ficha; mismo formulario, sin el precio (el precio tiene sus versiones). |
| Estados | Se añade **Archivado**. Transiciones: Borrador → Activo, Activo → Inactivo, Inactivo → Activo (Reactivar), Inactivo → Archivado. |
| Regla de activación | Un servicio en borrador **no se activa sin precio vigente**. |
| Lista | Columnas **Modalidad** y **Garantía**, filtro **Modalidad**, estado "Archivado" en el filtro. |
| Ficha | Configuración con modalidad, recurrencia, garantía y "Afecto a IGV"; **historial** de movimientos bajo el ciclo de vida; tarjeta **"Usado en N cotización(es)"** con el precio que guardó cada una. |
| Exportar | "Exportar a Excel" y "CSV" en el pie de la lista: exporta todas las filas filtradas, no solo la página. |

### Cotizaciones

| Qué | Detalle |
| --- | --- |
| Editar borrador | Botón "Editar" en la ficha (`/quotations/[id]/edit`); solo en estado Borrador. |
| Aprobar / Rechazar | Piden comentario; el **motivo del rechazo es obligatorio**. Queda en la tarjeta **Aprobaciones** con quién y cuándo. |
| No se aprueba vencida | Si pasó la fecha de validez, "Aprobar" se deshabilita y la API lo rechaza. |
| Marcar vencida | Desde Enviada. |
| Cancelar | Desde Borrador o Enviada, con confirmación. |
| Nueva versión | Desde Enviada, Rechazada, Vencida o Aprobada (si no tiene contrato ni una versión posterior). Crea un borrador con las mismas líneas al **precio vigente del catálogo** y el mismo % de descuento. La anterior se conserva; si estaba Enviada, se cancela. |
| Ficha | Tarjetas **Aprobaciones** e **Historial**; en Trazabilidad, "Versión anterior"; aviso cuando fue reemplazada; en cada línea, unidad, recurrencia y "hoy el catálogo dice…" si el precio cambió. |
| Solo se vende lo vendible | Al guardar, el cliente debe estar activo y cada servicio, activo. |
| Lista | Marca "· reemplazada"; "Exportar a Excel" y "CSV". |
| Editor | La línea muestra la recurrencia y avisa si el precio del catálogo cambió desde que se agregó. |

### Archivos web (`apps/web/src`)

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/services/ServiceFormModal.tsx` | Campos nuevos; sirve para crear y para editar. |
| `components/dashboard/services/ServicesList.tsx` | Columnas, filtro y exportación. |
| `components/dashboard/services/ServiceDetail.tsx` | Editar, transiciones, historial, uso en cotizaciones. |
| `components/dashboard/quotations/QuotationEditor.tsx` | Modo edición. |
| `components/dashboard/quotations/QuotationDetail.tsx` | Acciones nuevas, motivo, aprobaciones, historial. |
| `components/dashboard/quotations/QuotationsList.tsx` | "Reemplazada" y exportación. |
| `app/(dashboard)/quotations/[id]/edit/page.tsx` | **Nuevo.** |
| `lib/export-table.ts` | **Nuevo.** Exportar un listado a Excel o CSV. |
| `components/ui/proto.tsx` | Piezas `Timeline` y `ExportButtons`; `Pager` acepta contenido a la izquierda. |
| `lib/commercial.ts`, `types/commercial.ts` | Modalidades, frecuencias, etiquetas del historial y tipos. |

### API (`apps/api`)

| Archivo | Cambio |
| --- | --- |
| `database/migrations/2026_10_05_000300_add_commercial_fields_to_services_and_quotations.php` | **Nueva** (ver "Base de datos"). |
| `Modules/Services/Http/Controllers/ServiceController.php` | `update`, `deactivate`, `archive`; campos nuevos; filtro `billing_mode`; la ficha devuelve historial y cotizaciones que lo usan. |
| `Modules/Services/Http/Controllers/QuotationController.php` | `update`, `expire`, `cancel`, `newVersion`; aprobar y rechazar guardan la decisión; la ficha devuelve aprobaciones, historial y versiones. |
| `Modules/Services/Http/Requests/StoreServiceRequest.php` | Reglas de los campos nuevos. |
| `Modules/Services/Models/ServiceRecurringConfig.php`, `QuotationApproval.php` | **Nuevos** modelos sobre tablas que ya existían. |
| `Modules/Services/Models/Service.php`, `Quotation.php` | Campos y relaciones nuevas. |
| `Services/AuditHistory.php` | **Nuevo.** Lee de la auditoría los movimientos de un registro. |
| `routes/api.php` | Rutas nuevas (abajo). |
| `tests/Feature/ComercialTest.php` | 11 tests (antes 6). |

Rutas nuevas, todas con `services.manage` o `quotations.manage`:

| Método | Ruta |
| --- | --- |
| PUT | `/v1/services/{service}` |
| POST | `/v1/services/{service}/deactivate` |
| POST | `/v1/services/{service}/archive` |
| PUT | `/v1/quotations/{quotation}` |
| POST | `/v1/quotations/{quotation}/expire` |
| POST | `/v1/quotations/{quotation}/cancel` |
| POST | `/v1/quotations/{quotation}/versions` |

## Lógica existente que SÍ cambió

Para seguir las reglas del prototipo, cuatro cosas de la API ya no se comportan como antes:

| Antes | Ahora |
| --- | --- |
| Un servicio se activaba desde cualquier estado y sin precio. | Solo desde Borrador (con precio vigente) o Inactivo. |
| Un servicio se creaba sin categoría. | La categoría es obligatoria. |
| Una cotización se rechazaba sin motivo. | `reason` es obligatorio al rechazar. |
| Una cotización aceptaba cualquier cliente y servicio. | El cliente y los servicios deben estar activos. Se siguen aceptando líneas sin servicio. |

## Qué NO se tocó

- Clientes, Proveedores, Empleados, Planes SaaS, login, sidebar y cabecera.
- Contratos (`ContractController`): generar contrato desde cotización sigue igual.
- El generador de correlativos (`SequentialNumberGenerator`).

## Diferencias con el prototipo que siguen en pie

| Prototipo | Aquí | Motivo |
| --- | --- | --- |
| La nueva versión conserva el número con sufijo (`COT-…-v2`) | Recibe un número nuevo (p. ej. COT-2026-0004) con "Versión 2" y enlace a la anterior | El generador de correlativos toma el último número para calcular el siguiente; un sufijo `-v2` lo haría repetir números. No lo modifiqué porque también lo usan los contratos. |
| Moneda PEN / USD con tipo de cambio | Solo soles | Depende de la pantalla "Monedas y tipo de cambio" (Finanzas), que no existe. |
| Autorización interna por niveles y segregación de funciones ("no apruebas tu propia cotización") | No están | Dependen de "Reglas de aprobación" (Administración), que no existe. |
| Permiso propio para aprobar (`approve`) | Aprobar usa `quotations.manage` | El catálogo de permisos solo tiene `view` y `manage`. |
| "Orden directa" desde una cotización aprobada | No está | La pantalla y la API de Órdenes no existen. |
| Filtro y columna "Origen" (Directa / CRM / Portal) | No están | Todas las cotizaciones nacen en el ERP; CRM y portal aún no las crean. |
| "Descargar PDF" de la cotización | No está | Pendiente. |
| "Convertir en contrato" lleva a la ficha del contrato | Muestra el número en Trazabilidad | La pantalla de Contratos no existe. |
| "Visible en portal" en Trazabilidad | No está | El portal del cliente no muestra cotizaciones todavía. |
| En móvil la tabla se apila en tarjetas | Hecho el mismo día: ver [2026-10-05_comercial-responsive.md](2026-10-05_comercial-responsive.md). | — |

## Base de datos

Una migración, **ya corrida en local** con el usuario dueño de las tablas (`erp_user`). Solo añade:

- `services.billing_mode` (por defecto `ONE_TIME`) y `services.warranty_months` (por defecto 0).
- El estado `ARCHIVED` en la restricción de `services.status`.
- `quotations.previous_id`: la versión anterior de una cotización.

Los servicios que ya existían quedaron como "Único", sin garantía. La recurrencia usa la tabla `service_recurring_configs` y las decisiones `quotation_approvals`; ambas ya existían. El historial sale de `audit_logs`.

En otro entorno hay que correrla igual:

```bash
DB_USERNAME=erp_user DB_PASSWORD=<la de docker-compose> php artisan migrate --path=database/migrations/2026_10_05_000300_add_commercial_fields_to_services_and_quotations.php --force
```

`database/schema_reference.sql` no se regeneró.

## Cómo se probó

**Tests de la API:** `php artisan test` → 48 pasan (11 de Comercial). **Web:** `tsc` y `eslint` sin errores.

**En el navegador** (`localhost:3000`, `admin@erp-saas.test`):

1. Lista de servicios con las columnas y filtros nuevos.
2. "Nuevo servicio" sin categoría ni frecuencia muestra los dos errores; con ellos se crea SRV-003 recurrente mensual con 6 meses de garantía.
3. Activar sin precio muestra "El servicio necesita un precio vigente antes de activarse."; tras crear el precio, se activa.
4. "Editar" carga los datos; se cambió a trimestral, 12 meses y descripción, y la ficha lo refleja. Inactivar y Reactivar cambian los botones y quedan en el historial.
5. Nueva cotización con ese servicio: la línea dice "recurrente trimestral". Guardar borrador → "Editar" carga cliente, fecha, condiciones y línea → 3 unidades con 10 % → "Guardar y enviar" (S/ 796.50).
6. Rechazar sin motivo muestra el error; con motivo queda en Aprobaciones e Historial.
7. "Nueva versión" abre COT-2026-0004 en borrador, Versión 2, con enlace a la anterior; la anterior muestra el aviso de reemplazada. Cancelar la nueva pide confirmación.
8. La lista marca "Versión 1 · reemplazada"; la ficha del servicio lista las dos cotizaciones que lo usan.

**No probado en el navegador:** el clic en "Exportar a Excel" y "CSV" (descarga un archivo), "Archivar" y "Marcar vencida". Archivar y marcar vencida sí están cubiertos por los tests de la API.

**Datos de prueba añadidos a "Tenant A - Prueba":** servicio SRV-003 "Soporte mensual" (activo, recurrente trimestral), cotizaciones COT-2026-0003 (rechazada) y COT-2026-0004 (cancelada).

## Pendiente

- Probar a mano los dos botones de exportar.
- El servicio SRV-001 que ya existía no tiene categoría: al editarlo habrá que elegir una.
- Lo que sigue en la tabla de diferencias.
