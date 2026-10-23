# 2026-10-23 — Planes SaaS con el diseño de la consola y módulos según el menú real del ERP

## Qué se pidió

- **Diseño:** "Planes SaaS tiene un diseño distinto a lo demás, que coincida". Yadira eligió tarjetas como en el prototipo y la tabla como segunda vista.
- **Módulos:** que coincidan con lo que hay de verdad en el ERP ("puedes quitar o agregar a lo que ya está"). Eligió un módulo por grupo del menú, con Compras y Contabilidad aparte de Finanzas.

## Módulos del ERP

El catálogo (`module_catalog`) queda con un módulo por grupo de pantallas del menú. Migración `2026_10_23_000200_module_catalog_matches_erp_menu`, que corre `erp_user`; el seeder `ModuleCatalogSeeder` queda igual.

| Módulo | Pantallas | Prefijos de permisos |
|---|---|---|
| Clientes y actores *(obligatorio)* | Clientes y actores | core |
| Servicios | Catálogo de servicios | services |
| Cotizaciones | Cotizaciones, Solicitudes del portal | quotations, portal |
| Contratos | Contratos, Órdenes | contracts, orders |
| Órdenes de trabajo | Órdenes de trabajo, Servicios contratados, Plantillas de checklist, App del técnico | operations, work_orders, contracted_services, checklists |
| Proyectos | Proyectos | projects, tasks, deliverables, contracted_services |
| Horas y costos (código `TIMESHEETS`) | Horas y costos | operations, time_entries, cost_entries |
| Agenda | Agenda | agenda |
| Finanzas | Por facturar, Comprobantes, Cuentas por cobrar, Cobros, Recurrencia, Bancos y caja, Facturación electrónica, Monedas y tipo de cambio | billing, finance, receivables, payments, fiscal, recurring, treasury |
| **Compras** (nuevo) | Órdenes de compra, Comprobantes de proveedor, Cuentas por pagar | purchases, payables |
| **Contabilidad** (nuevo) | Libro diario, Mayor y balance, Estados financieros, Plan de cuentas y periodos, Centros de costo y presupuestos | accounting, budgets |
| Soporte | Tickets y SLA, Garantías, Calendario laboral y SLA | tickets, warranty, sla |
| CRM | Leads, Pipeline, Actividades, Campañas, Puntuación de leads | leads, opportunities, activities, campaigns, scoring |
| Portal de clientes | Usuarios del portal y el portal | portal_users |
| Documentos | Documentos | documents |

- **Retirados**, porque no abrían pantallas propias: Proveedores y Empleados (ya están en Clientes y actores), Tareas, Checklists, Recursos, Entregables, Rentabilidad, Tickets, Facturación electrónica y Pagos y cobranzas. Quedan inactivos en el catálogo, no se borran, y las filas de las empresas se conservan.
- **Nadie perdió acceso:**
  - toda empresa con Finanzas recibió Compras y Contabilidad (en la PC: Tenant A y Empresa Ejemplo SAC);
  - todo plan con Finanzas también (Profesional, Enterprise y Plan 6);
  - los planes quedaron sin los módulos retirados.
- **El menú del ERP** (`AppShell.tsx`) marca ahora las pantallas de Compras con `COMPRAS` y las de Contabilidad con `CONTABILIDAD`. Una empresa sin esos módulos las ve con el candado de "Plan", igual que cualquier módulo fuera de su plan, y la API responde 403.
- **Ventana de planes:** los módulos salen agrupados como el menú (Base del ERP, Comercial, Operaciones, Finanzas, Compras, Contabilidad, Soporte, CRM, Portal de clientes, Control), con Clientes y actores fijo como obligatorio.

## Planes SaaS

- **Indicadores** (los mismos cuatro de antes): Planes activos, Tenants activos, En prueba e Ingresos mensuales. Ahora salen del mismo cálculo del panel: "Tenants activos" cuenta solo las activas (antes era el total) y el ingreso no suma las pruebas (antes daba S/ 646; ahora S/ 99.00, el único plan pagado).
- **Arriba:** pestañas Todos / Activos / Inactivos con su número, buscador por nombre o código, conmutador Tarjetas / Tabla (se recuerda en el navegador) y el botón Nuevo plan.
- **Tarjetas, como "Planes y límites" del prototipo**, tres por fila. Cada una muestra:
  - nombre, estado (y "Oculto" si no es público) y su descripción;
  - precio mensual y anual, prueba;
  - usuarios, almacenamiento, clientes y llamadas API al mes, los cuatro del prototipo;
  - cuántas empresas lo tienen;
  - sus módulos.

  El botón Editar abre la ventana.
- **Tabla:** plan, precios, módulos, usuarios, prueba, empresas y estado. Se puede ordenar, seleccionar y exportar al pie, y el clic en la fila abre la ventana.
- **Ventana de crear y editar:** las mismas 7 pestañas, con los colores y controles de la consola (cabecera azul, campos y botones como los demás, modo oscuro). Se quitó la frase "Define qué puede contratar…" bajo el título.
- No se puso el aviso "[PENDIENTE] Definición de producto" del prototipo: es de la demo.
- Se quitaron la tabla anterior (`SaasPlanTable.tsx`) y la ruta `/v1/superadmin/dashboard/metrics`, que ya nadie usa.

## Pruebas

- **`ModuleAccessTest`, prueba nueva:** con solo Finanzas, una empresa entra a Comprobantes pero no a Órdenes de compra ni al Libro diario (403); con Compras entra a Órdenes de compra, pero sigue sin Libro diario.
- **`PlatformTenantsTest`:** comprueba el catálogo nuevo (solo Clientes y actores es obligatorio, Compras y Contabilidad tienen sus prefijos, Finanzas ya no da compras, los retirados no aparecen).
- Las pruebas que arman empresas con Finanzas y usan compras o contabilidad llevan ahora también esos módulos.
- `php artisan test`: **275 pruebas pasan**. `tsc` y `eslint` sin errores.
- **En el navegador**, con la cuenta superadmin:
  - Planes SaaS en tarjetas y en tabla a 1366 y 375 px, en claro y oscuro, sin desplazamiento lateral;
  - la ventana de edición en sus pestañas Información y Módulos, en claro y oscuro (se cerró sin guardar).
- **Contra la API real:** la cuenta de Finanzas de Tenant A tiene Compras y Contabilidad entre sus módulos y abre Órdenes de compra, Libro diario y Comprobantes.

## Pendiente

- **En el VPS:** al desplegar, la migración hace lo mismo allá: Compras y Contabilidad para cada empresa y plan con Finanzas, y los módulos retirados quedan inactivos.
- El dato "Empresas" de cada plan cuenta las suscripciones vigentes, también las de prueba.
