# 2026-10-08 — Clientes, Proveedores y Empleados desconectados; queda Clientes y actores

Continúa [2026-10-08_importar-actores-y-servicios.md](2026-10-08_importar-actores-y-servicios.md).

## Qué se pidió

"Esos tres módulos, no lo elimines pero ya desconéctalo, solo se queda Clientes y actores. Sin botón Asignar."

## Qué se cambió

Solo web. Ningún archivo se borró.

| Archivo | Cambio |
| --- | --- |
| `components/layout/AppShell.tsx` | Se quitaron del menú los ítems Clientes, Proveedores y Empleados. **Clientes y actores** queda primero en Comercial, como en el prototipo. |
| `proxy.ts` | Las rutas `/customers`, `/suppliers` y `/employees` llevan a `/actors` (lista `RETIRED_PREFIXES`). El inicio de la empresa pasa a ser `/actors`. |
| `components/auth/LoginForm.tsx`, `app/blocked/page.tsx` | Tras iniciar sesión (o al reactivarse la empresa) se entra a Clientes y actores. |
| `components/layout/GlobalSearch.tsx` | El buscador de la cabecera (Ctrl K) busca en Clientes y actores: cada identidad sale **una sola vez**, con su documento y sus roles, y abre su vista 360. Ahora también encuentra a las personas que solo son contacto y busca por correo. |
| `components/dashboard/quotations/QuotationEditor.tsx` | El enlace bajo el campo Cliente dice "¿No existe? Créalo en Clientes y actores" y lleva ahí. |

## Qué NO se tocó

- Las pantallas y componentes de Clientes, Proveedores y Empleados (`app/(dashboard)/customers|suppliers|employees`, `components/dashboard/customers|suppliers|employees`) siguen en el repo, sin cambios.
- La API: `/v1/customers`, `/v1/suppliers`, `/v1/employees` y `/v1/search` siguen funcionando (el editor de cotizaciones y el formulario de actores usan `/v1/customers`). Los permisos `core.view` / `core.manage` son los mismos.
- La base de datos. Sin migraciones.
- No se añadió el botón Asignar.

**Para reconectarlos:** vaciar `RETIRED_PREFIXES` en `proxy.ts` y devolver los tres ítems a `MENU_ITEMS` en `AppShell.tsx`.

## Lo que se deja de tener al desconectarlos

- El botón **Asignar** de la barra de selección (pedido así).
- Las **columnas personalizadas** que se crearon en Clientes, Proveedores o Empleados no se ven en Clientes y actores (las suyas son aparte). Los datos siguen en la base.
- Las plantillas de importación de los tres módulos; se usa la de Clientes y actores.

## Cómo se probó

`tsc` y `eslint` sin errores. En el navegador con `ventas@erp-saas.test`:

1. Al iniciar sesión se entra a `/actors`.
2. En el menú, Comercial muestra Clientes y actores, Catálogo de servicios, Cotizaciones y Solicitudes del portal; ya no salen Clientes, Proveedores ni Empleados.
3. Escribir `/customers`, `/suppliers` o `/employees` en la barra de direcciones lleva a `/actors`.
4. Ctrl K → "prueba": sección "Clientes y actores" con Importado de Prueba S.A.C. (RUC · Cliente), Contacto De Prueba (Contacto), Actor de Prueba S.A.C. (Cliente, Proveedor, una sola vez) y Empresa Prueba. Enter abre la vista 360 del primero.
5. Nueva cotización: el enlace bajo Cliente lleva a `/actors`.

No se probó: la pantalla `/blocked`, ni con las cuentas de administrador y gerencia (el cambio no depende del rol). No se corrieron las pruebas de la API porque no se tocó.

## Pendiente

- **Identidades repetidas.** En "Tenant A - Prueba" hay 5 documentos que figuran en dos registros distintos, creados antes en los módulos separados:
  - RUC 20987654321 "Proveedores Globales S.A.C.": uno como proveedor y otro como cliente.
  - DNI 87654321 "Carlos Ramírez Soto": uno como proveedor y otro como cliente.
  - RUC 20123456789 "Corporación Ejemplo S.A.C.": dos veces como cliente.
  - DNI 12345678: "Juan Carlos Pérez Gómez" y "Juan Carlos", los dos clientes.
  - 20138902834: la organización "Comercial" (cliente) y la empleada "Geraldine Ramos" (parece un documento mal escrito, no un duplicado).

  Clientes y actores ya no deja crear más, pero no une las que existen. Falta decidir si se hace una función "Unir" o se dejan.
- Cuando se decida, borrar de verdad los tres módulos (pantallas, componentes, `lib/open-record.ts`, `/v1/search`).
