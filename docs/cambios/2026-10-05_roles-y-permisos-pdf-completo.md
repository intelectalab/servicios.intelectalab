# 2026-10-05 — PDF de cotización completo y pantalla "Roles y permisos"

Continúa [2026-10-05_cotizacion-aprobacion-pdf-portal.md](2026-10-05_cotizacion-aprobacion-pdf-portal.md).

## Qué se pidió

1. En el PDF faltan el RUC de la empresa y la Dirección y el Correo del cliente (captura del prototipo).
2. Hacer lo que quedó pendiente de la tanda anterior, en orden: las pruebas que faltaban (375 px, PDF con muchas líneas) y la pantalla "Roles y permisos" con el ajuste de segregación.

## Qué se cambió

### PDF

| Archivo | Cambio |
| --- | --- |
| API `Auth/AuthController.php` | `/v1/me/access` devuelve `company` (razón social y RUC de la empresa, de la tabla `companies`). |
| API `Modules/Services/Http/Controllers/QuotationController.php` | El detalle de la cotización devuelve `customer_contact` (dirección y correo del cliente, tomados de sus contactos; primero el marcado como principal). |
| Web `lib/quotation-pdf.ts` | Cabecera con la razón social y "RUC …" de la empresa; bloque del cliente con Dirección y Correo (un guion si el cliente no los tiene registrados). |
| Web `types/commercial.ts` | `Quotation.customer_contact`. |

### Roles y permisos (Administración)

| Archivo | Cambio |
| --- | --- |
| API `Modules/Core/Http/Controllers/RoleController.php` (nuevo) | `GET /v1/roles`: roles de la empresa con sus permisos y cuántos usuarios tiene cada uno, más el catálogo de permisos que la empresa puede asignar (los de administración y los de sus módulos). `POST /v1/roles`: crea un rol (sin permisos). `PUT /v1/roles/{id}/permissions`: guarda la matriz de un rol en `role_permissions`. El rol Administrador no se recorta. Todo queda en la auditoría. |
| API `Modules/Core/Http/Controllers/TenantSettingsController.php` (nuevo) | `GET` / `PUT /v1/tenant/settings`: lee y cambia la segregación de funciones de la empresa (`tenants.configuration.segregation`). |
| API `routes/api.php` | Rutas nuevas. Ver roles pide `users.view`; crear roles y editar la matriz, `users.manage` (en el prototipo dependen del recurso "usuarios"); cambiar la segregación, `tenant.manage`. |
| Web `components/dashboard/admin/RolesPermissions.tsx` y `app/(dashboard)/roles/page.tsx` (nuevos) | Pantalla como la del prototipo: selector de roles (con cuántos usuarios tiene cada uno), tarjeta con la matriz Recurso × acción con casillas que se guardan al instante, nota del rol Administrador, botón "Nuevo rol" y tarjeta "Segregación de funciones". En teléfono cada recurso es una tarjeta. |
| Web `components/layout/AppShell.tsx`, `Sidebar.tsx`, `proxy.ts` | "Roles y permisos" deja de estar atenuado en Administración. Un ítem del menú puede no depender de ningún módulo del plan (las pantallas de administración de la empresa). |
| API `tests/Feature/ComercialTest.php` | Prueba nueva de la matriz: catálogo según los módulos, crear rol, guardar permisos, efecto inmediato sobre quién puede aprobar, Administrador intocable, rol de otra empresa inexistente, ajuste de segregación y sus permisos. También se comprueba `customer_contact`. |

## Qué NO se tocó

Clientes, Proveedores, Empleados, Planes SaaS. La lógica de cotizaciones no cambia.

## Diferencias con el prototipo

- La matriz del prototipo tiene seis acciones (Ver, Crear, Editar, Eliminar, Aprobar, Exportar) y un Alcance (propio, equipo, sede, empresa). La de aquí muestra las acciones que existen en tu tabla de permisos: **Ver, Gestionar** (crear y editar juntos) **y Aprobar**. No hay Alcance: hoy la API no filtra por alcance, así que mostrarlo sería decorativo.
- El prototipo trae nueve roles fijos; aquí aparecen los roles que la empresa tenga y se pueden crear más.
- La segregación está en esta pantalla; en el prototipo va en "Configuración", que aún no existe.
- No se trajo la nota explicativa bajo el título.
- No se puede asignar un rol a un usuario ni borrar roles: eso es de la pantalla "Usuarios", que no existe todavía.

## Base de datos

Sin migraciones. En la base local, para poder probar:

- "Tenant A - Prueba" recibió una fila en `companies` con RUC **ficticio** 20600000001 (no tenía empresa; las empresas registradas desde el formulario sí la tienen).
- Al rol de administrador de "Tenant A - Prueba" se le dieron `users.manage` y `tenant.manage`, que le faltaban (las empresas nuevas los reciben al registrarse).
- Se creó desde la pantalla el rol **Ventas** (ve clientes y catálogo; ve y gestiona cotizaciones; no aprueba), sin usuarios.

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test`: las 51 pruebas pasan. En el navegador con `admin@erp-saas.test`:

1. **Roles y permisos** (1440 px): aparecen Administrador y Gerencia con su número de usuarios; la matriz muestra los recursos de los módulos de la empresa. Marcar y desmarcar "Ver" de CRM en Gerencia se guarda ("Permiso actualizado"). El Administrador sale con todas las casillas deshabilitadas y su nota. "Nuevo rol" crea Ventas y lo deja seleccionado; se le marcaron cuatro permisos. La segregación se desactivó y se volvió a activar (queda activa), y ambos cambios quedaron en la auditoría.
2. **Roles y permisos** (375 px): sin desplazamiento horizontal; cada recurso es una tarjeta con sus casillas.
3. **Ficha de cotización a 375 px** (COT-2026-0007): sin desplazamiento horizontal; botones Aprobar (deshabilitado), Rechazar, Nueva versión y Descargar PDF, y el aviso de segregación.
4. **PDF** (revisado a la vista): cabecera "Tenant A - Prueba S.A.C. · RUC 20600000001"; Dirección y Correo del cliente; con 45 líneas ocupa 2 páginas, repite el encabezado de la tabla, numera "1 / 2" y "2 / 2" y deja totales, condiciones y firmas al final.

Se corrigió durante la prueba: la matriz no dejaba guardar si el rol tenía permisos de un módulo que la empresa no tiene activo (el rol Gerencia tiene los de Contratos). Ahora esos permisos se conservan sin tocarlos.

Para el punto 4 se simularon en el navegador las 45 líneas y los datos de contacto (ninguna cotización de prueba tiene un cliente con dirección registrada); que la API entregue dirección y correo está cubierto por las pruebas automáticas. La descarga real a disco sigue sin probarse.

## Pendiente

- Pantalla "Usuarios": crear usuarios y asignarles rol (hoy el rol Ventas no tiene a nadie).
- Alcance por rol y acciones separadas (Crear / Editar / Eliminar / Exportar), si se quiere la matriz completa del prototipo: requiere cambiar cómo la API comprueba los permisos.
- Poner el RUC real de "Tenant A - Prueba" si se va a usar para demostraciones.
