# 2026-10-05 — Cotización: permiso de aprobar y segregación de funciones, Descargar PDF y "Visible en portal"

Continúa [2026-10-05_cotizacion-ficha-como-prototipo.md](2026-10-05_cotizacion-ficha-como-prototipo.md).

## Qué se pidió

1. Agregar "Visible en portal" en Trazabilidad (lo del portal se ve después).
2. Falta "Descargar PDF": fijarse en qué momento aparece ese botón.
3. Al aprobar, el prototipo dice "Creaste esta cotización: por segregación de funciones debe aprobarla otra persona (por ejemplo, Gerencia)". Usar la tabla de permisos y roles para eso y para todo lo que conlleve.

## Cómo lo hace el prototipo

- **Descargar PDF** está siempre en la ficha, en cualquier estado, como último botón. El documento lleva marca de agua si la cotización está en borrador, cancelada, rechazada o vencida.
- **Aprobar** es una acción propia de la matriz de roles: Ventas crea y envía, Gerencia aprueba. Además, quien creó la cotización no puede aprobarla aunque tenga el permiso (segregación de funciones), y eso es un ajuste de cada empresa, activo por defecto. Rechazar no tiene esa restricción.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `database/migrations/2026_10_05_000500_add_quotation_approval_permission.php` (nuevo) | Permiso nuevo `quotations.approve` ("Aprobar cotizaciones") en la tabla `permissions`. Se lo asigna, en `role_permissions`, a todo rol que ya tenía `quotations.manage`, para que nadie pierda lo que podía hacer. |
| `routes/api.php` | Aprobar y rechazar piden `quotations.approve` (antes `quotations.manage`). |
| `Modules/Services/Http/Controllers/QuotationController.php` | Aprobar falla con "Segregación de funciones: no puedes aprobar tu propia cotización." si quien aprueba es quien la creó, salvo que la empresa lo desactive (`tenants.configuration.segregation = false`). El detalle devuelve `own_approval_blocked` para que la ficha avise antes. |
| `Modules/Subscription/Support/ModulePermissions.php` | Los permisos del administrador de una empresa ya no se arman "a mano" como `view` + `manage`: se leen de la tabla `permissions` todos los que empiezan con los prefijos de sus módulos. Así una empresa nueva (y `tenants:sync-permissions`) recibe también `quotations.approve` y cualquier permiso de acción futuro. |
| `Http/Controllers/Auth/AuthController.php` | `/v1/me/access` añade la razón social y el RUC de la empresa, para el PDF. |
| `database/seeders/LocalApproverSeeder.php` (nuevo) | Solo para el entorno local: crea en "Tenant A - Prueba" el rol **Gerencia** (con sus permisos en `role_permissions`) y un usuario con ese rol, para poder aprobar. El correo y la contraseña de prueba están en ese archivo. |
| `tests/Feature/ComercialTest.php` | La prueba de decisiones ahora cubre: sin `quotations.approve` no se aprueba ni rechaza (403); Gerencia aprueba pero no cotiza; quien creó la cotización no la aprueba (422) aunque tenga el permiso; otra persona sí; y con la segregación desactivada, sí puede. |

**Lógica que cambió:** antes cualquiera con `quotations.manage` aprobaba, incluso su propia cotización. Ahora hace falta `quotations.approve` y, con la segregación activa, ser otra persona.

### Web

| Archivo | Cambio |
| --- | --- |
| `lib/quotation-pdf.ts` (nuevo) | Arma el PDF en el navegador con la composición del prototipo: empresa y RUC, recuadro "COTIZACIÓN" con el número, cliente y documento, emisión, validez, moneda (con T.C. si es en dólares) y ejecutivo, tabla de líneas, totales con el TOTAL resaltado, Condiciones, Validez, líneas de firma, pie "Generado el … por …" y marca de agua (BORRADOR, ANULADO, RECHAZADO, VENCIDA). Colores del logo. |
| `components/dashboard/quotations/QuotationDetail.tsx` | Botón **Descargar PDF** siempre visible, al final de las acciones. **Aprobar** y **Rechazar** dependen de `quotations.approve`; si la cotización es tuya, Aprobar queda deshabilitado con su motivo y aparece el aviso "Creaste esta cotización: por segregación de funciones debe aprobarla otra persona (por ejemplo, Gerencia)". Trazabilidad añade **Visible en portal**: "No (borrador)" o "Sí: el cliente puede verla y aprobarla". |
| `types/commercial.ts` | `Quotation.own_approval_blocked`. |
| `package.json` | Dependencias nuevas: `jspdf` y `jspdf-autotable` (se cargan solo al descargar). |

## Qué NO se tocó

Clientes, Proveedores, Empleados, Planes SaaS; crear, editar, enviar, cancelar, versionar y convertir en contrato.

## Diferencias con el prototipo

- **"Visible en portal" es solo el texto del prototipo**: el portal todavía no muestra cotizaciones (se pidió ponerlo y verlo después).
- El PDF no lleva Dirección ni Correo del cliente: la cotización no trae esos datos hoy.
- No hay pantalla "Roles y permisos" ni ajuste visible de la segregación: los roles y el ajuste viven en las tablas (`roles`, `role_permissions`, `tenants.configuration`).
- Sin autorización por niveles (reglas por monto o descuento): depende de "Reglas de aprobación".

## Base de datos

- Migración `2026_10_05_000500` **ya corrida** en la base local (añade un permiso y sus asignaciones; no borra nada). Lo recibieron los roles de administrador de "Tenant A - Prueba" y "Empresa Ejemplo SAC", que eran los que tenían `quotations.manage`.
- Seeder `LocalApproverSeeder` **ya corrido**: rol Gerencia + usuario `gerencia@erp-saas.test` en "Tenant A - Prueba" (entra sin código de verificación porque no es administrador).
- Datos de prueba movidos: COT-2026-0005 pasó de borrador a enviada y luego a aprobada (por Gerencia).

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test`: las 50 pruebas pasan. En el navegador a 1440 px:

1. Como `admin@erp-saas.test`, COT-2026-0005 en borrador: "Visible en portal: No (borrador)"; **Descargar PDF** genera `Cotizacion_COT-2026-0005.pdf` (1 página, revisada a la vista: cabecera, datos, línea, totales, condiciones, firmas y marca "BORRADOR").
2. Tras "Enviar a aprobación": aparece el aviso de segregación, **Aprobar** deshabilitado con su motivo, **Rechazar** habilitado, "Visible en portal: Sí: el cliente puede verla y aprobarla".
3. Como `gerencia@erp-saas.test`: sin aviso, Aprobar habilitado; al aprobar queda "Aprobada · Gerencia de Prueba · backoffice" y en el historial "Aprobada desde Enviada".
4. PDF de COT-2026-0007 (dólares, enviada): "Dólares (T.C. 3.750)", montos en US$, sin marca de agua.

No se probó: a 375 px, el PDF con muchas líneas (salto de página) ni descargarlo realmente a disco (se capturó el archivo generado en el navegador).

## Pendiente

- Pantalla "Roles y permisos" para crear roles y asignar `quotations.approve` sin tocar la base, y un ajuste visible para la segregación.
- Que el portal muestre de verdad las cotizaciones no borrador.
- Correr `php artisan tenants:sync-permissions` no cambia nada para las empresas de prueba antiguas (`tenant-a`, `tenant-b`, Yadi SAC), porque su rol de administrador no es "de sistema"; ya recibieron el permiso por la migración si tenían `quotations.manage`.
