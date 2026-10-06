# 2026-10-05 — Botones sin permiso: a la vista, con candado y mensaje

Continúa [2026-10-05_usuarios.md](2026-10-05_usuarios.md).

## Qué se pidió

Los permisos son muy importantes: como en el prototipo, un botón que el rol no puede usar **sí aparece**, pero deshabilitado, y al pasar el cursor sale un mensaje corto ("Tu rol no tiene permiso quotations.create").

## Qué se cambió (solo web)

| Archivo | Cambio |
| --- | --- |
| `components/ui/proto.tsx` | `Btn` acepta `permission="…"`: si el rol del usuario no lo tiene, el botón queda deshabilitado, con un candado y el mensaje "Tu rol no tiene permiso …" al pasar el cursor. Pieza nueva `LinkBtn` para los botones que son enlaces (Nueva cotización, Editar), con el mismo comportamiento. `useDenied` y `deniedText` para casillas y otros controles. |
| `components/dashboard/services/ServicesList.tsx`, `ServiceDetail.tsx` | Nuevo servicio, Editar, Nueva versión de precio y las transiciones usan `permission="services.manage"`. |
| `components/dashboard/quotations/QuotationsList.tsx`, `QuotationDetail.tsx` | Nueva cotización, Editar, Enviar a aprobación, Marcar vencida, Cancelar y Nueva versión (`quotations.manage`); Aprobar y Rechazar (`quotations.approve`); Convertir en contrato (`contracts.manage`). |
| `components/dashboard/finance/ExchangeRates.tsx` | "Registrar tipo de cambio" ya no se esconde sin `billing.manage`: sale con candado. |
| `components/dashboard/admin/RolesPermissions.tsx`, `UsersList.tsx` | "Nuevo rol", "Nuevo usuario", Editar y Desactivar/Activar ya no se esconden ni quedan mudos sin `users.manage`: salen con candado y mensaje. Las casillas de la matriz y la de segregación muestran el motivo al pasar el cursor. |

Antes había tres comportamientos mezclados: botones con mensaje pero sin candado, botones deshabilitados sin mensaje y botones que desaparecían. Ahora es uno solo y sale del botón compartido, así que las pantallas nuevas lo heredan con solo indicar el permiso.

Los otros motivos por los que un botón queda deshabilitado siguen con su propio mensaje y sin candado ("Vencida: no se puede aprobar", "Ya se convirtió en contrato", "Segregación de funciones…"), igual que en el prototipo.

## Qué NO se tocó

- La API: sigue rechazando por su cuenta toda acción sin permiso (el candado es solo el aviso).
- Clientes, Proveedores, Empleados y Planes SaaS: no usan estos botones.
- El menú lateral: una pantalla que el rol no puede ver sigue oculta.

## Diferencias con el prototipo

El mensaje nombra el permiso real de tu tabla (`quotations.manage`, `quotations.approve`…), no los del prototipo (`quotations.create`).

## Base de datos

Sin cambios. Para probar se le puso una contraseña de prueba al usuario `ventas@erp-saas.test` (está en `database/seeders/LocalApproverSeeder.php`; solo se aplica mientras esa cuenta no haya iniciado sesión nunca).

## Cómo se probó

`tsc` y `eslint` sin errores. En el navegador como `ventas@erp-saas.test` (rol Ventas: ve clientes y catálogo, ve y gestiona cotizaciones), a 1440 px:

1. Catálogo de servicios: "Nuevo servicio" deshabilitado, con candado y "Tu rol no tiene permiso services.manage".
2. Ficha de un servicio: Editar, Nueva versión de precio e Inactivar, los tres con candado y el mismo mensaje.
3. Ficha de una cotización vencida: solo "Descargar PDF", habilitado.

No se vio en el navegador: Aprobar y Rechazar con candado (no había ninguna cotización enviada a aprobación en ese momento), ni los candados de Tipo de cambio, Roles y Usuarios (el rol Ventas no ve esas pantallas en el menú). Usan el mismo botón compartido.

## Pendiente

- El mensaje es el aviso estándar del navegador al pasar el cursor; en teléfono no hay cursor, así que ahí solo se ve el candado.
