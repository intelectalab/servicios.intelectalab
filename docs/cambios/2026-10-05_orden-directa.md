# 2026-10-05 — Cotización: botón "Orden directa"

Continúa [2026-10-05_botones-sin-permiso.md](2026-10-05_botones-sin-permiso.md).

## Qué se pidió

Falta el botón "Orden directa" en la ficha de la cotización (captura del prototipo: Convertir en contrato · Orden directa · Nueva versión · Descargar PDF).

## Cómo es en el prototipo

Aparece junto a "Convertir en contrato" cuando la cotización está **aprobada** y la empresa permite órdenes directas (ajuste activo por defecto). Crea una orden en borrador con las líneas de la cotización, sin pasar por contrato. Solo una por cotización: después queda deshabilitado con "Ya tiene orden". Exige el permiso de crear órdenes. Trazabilidad muestra la orden.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Modules/Services/Models/Order.php`, `OrderItem.php` (nuevos) | Modelos de las tablas `orders` y `order_items`, que ya existían en la base. |
| `Modules/Services/Http/Controllers/OrderController.php` (nuevo) | `POST /v1/quotations/{id}/order`: crea la orden (correlativo `ORD-2026-0001`, borrador, misma moneda y total, líneas copiadas). Rechaza si la cotización no está aprobada, si la empresa no permite órdenes directas o si ya tiene orden. Queda en la auditoría. |
| `database/migrations/2026_10_05_000600_grant_order_permissions_with_contracts.php` (nuevo) | Los permisos `orders.view` y `orders.manage` existían pero ningún módulo los daba: pasan a venir con el módulo **Contratos**, y los reciben los roles que ya podían ver o gestionar contratos. |
| `database/seeders/ModuleCatalogSeeder.php` | El módulo Contratos lista también el prefijo `orders`. |
| `routes/api.php` | Ruta nueva, con `permission:orders.manage`. |
| `Modules/Core/Http/Controllers/TenantSettingsController.php` | Ajuste nuevo `direct_order` (activo si no se indica), junto a la segregación. |
| `Modules/Services/Http/Controllers/QuotationController.php`, `Models/Quotation.php` | El detalle devuelve `order` (número y estado) y `direct_order_enabled`. |
| `tests/Feature/ComercialTest.php` | Prueba nueva: sin permiso (403), no aprobada, ajuste desactivado, orden creada con su total y líneas, una sola por cotización, y la ficha la muestra. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/quotations/QuotationDetail.tsx` | Botón **Orden directa** (secundario) entre "Convertir en contrato" y "Nueva versión", solo en cotizaciones aprobadas y si la empresa lo permite. Sin `orders.manage` sale con candado y su mensaje; con orden ya creada, deshabilitado con "Ya tiene orden". Trazabilidad añade la fila **Orden** con su número y estado. |
| `components/dashboard/admin/RolesPermissions.tsx` | La tarjeta de segregación pasa a llamarse "Reglas de la empresa" y añade "Permitir orden directa desde una cotización aprobada". |
| `types/commercial.ts` | `Quotation.order`, `Quotation.direct_order_enabled`. |

## Qué NO se tocó

Clientes, Proveedores, Empleados, Planes SaaS. "Convertir en contrato" sigue igual. Una cotización puede tener contrato y orden a la vez, como en el prototipo.

## Diferencias con el prototipo

- Tras crear la orden, el prototipo lleva al listado de Órdenes; aquí se queda en la ficha, porque la pantalla **Órdenes aún no existe**. La orden se ve solo en Trazabilidad.
- El permiso es `orders.manage` (el de tu tabla), no `orders.create`.
- El ajuste "orden directa" está en Roles y permisos; en el prototipo va en Configuración, que no existe todavía.

## Base de datos

- Migración `2026_10_05_000600` **ya corrida** en la base local (no borra nada). Recibieron los permisos de órdenes los roles Administrador y Gerencia de "Tenant A - Prueba" y el Administrador de "Empresa Ejemplo SAC".
- En "Tenant A - Prueba" se activó el módulo `CONTRATOS`, para que la matriz de Roles y permisos muestre Contratos y Órdenes.
- Dato de prueba creado: orden **ORD-2026-0001** desde COT-2026-0005.

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test`: las 53 pruebas pasan. En el navegador, a 1440 px:

1. Como `ventas@erp-saas.test` (sin permisos de contratos ni órdenes), COT-2026-0005 aprobada: "Convertir en contrato" y "Orden directa" con candado y "Tu rol no tiene permiso contracts.manage" / "orders.manage".
2. Como `admin@erp-saas.test`: "Orden directa" habilitado; al pulsarlo crea ORD-2026-0001, el botón queda deshabilitado con "Ya tiene orden" y Trazabilidad muestra "Orden: ORD-2026-0001 · Borrador".
3. Roles y permisos: la tarjeta "Reglas de la empresa" muestra las dos reglas activas.

No se probó en el navegador: desactivar la regla y ver desaparecer el botón (sí en las pruebas automáticas), ni la vista a 375 px. El aviso "Orden creada" no llegó a leerse.

## Pendiente

- Pantalla **Órdenes** (listado, ficha y transiciones) y "orden desde contrato".
- Los planes SaaS deben incluir el módulo Contratos para que una empresa nueva reciba los permisos de órdenes.
