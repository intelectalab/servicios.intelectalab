# 2026-10-21 — Módulos de los planes, de menor a mayor

## Qué se pidió

Que un plan tenga todos los módulos y ordenar los demás de menor a mayor, en la PC y en el VPS. Antes ninguno tenía los 22: el Enterprise tenía 12 (menos que el Base, con 14) y el Profesional, 7. CRM, Timesheets, Pagos y cobranzas, Rentabilidad y Agenda casi no estaban en ningún plan.

## Qué se cambió

Solo datos de `saas_plans.modules` (no hubo cambios de código):

| Plan | Módulos |
| --- | --- |
| Plan Base (9) | Clientes, Proveedores, Empleados (obligatorios), Servicios, Cotizaciones, Contratos, Documentos, Tareas, Agenda |
| Plan Profesional (19) | Base + CRM, Órdenes de trabajo, Checklists, Proyectos, Entregables, Timesheets, Recursos, Finanzas, Pagos y cobranzas, Facturación electrónica |
| Plan Enterprise (22) | Todos los módulos activos del catálogo (suma Soporte, Tickets y Rentabilidad) |

Se aplicó con SQL directo: no queda en la auditoría de la plataforma.

## Qué NO se tocó

- **Plan 6:** queda igual, con 14 módulos (parece de pruebas).
- **Empresas existentes:** sus módulos (`tenant_modules`) se copian del plan al registrarse y no cambiaron. Por ejemplo, "Empresa Ejemplo SAC" (Enterprise) sigue con sus 12 módulos y "Empresa" (EMP-52F1DB, creada hoy en el VPS con el Plan Base) sigue con los 14 del Base anterior.
- **Precios y orden de los planes:** el Base cuesta 200.20, el Profesional 120 y el Enterprise 35; están invertidos y no se tocaron.

## Base de datos

Sin migraciones. Respaldo del VPS antes del cambio en `/root/backups/erp/…_antes_planes`; en la PC se guardó el valor anterior aparte.

## Cómo se probó

- Conteo de módulos por plan en las dos bases: Base 9, Profesional 19, Enterprise 22, Plan 6 14. Las listas son idénticas en la PC y en el VPS.
- Se comprobó que todos los códigos existen en `module_catalog`.

## Después: las empresas actuales reciben los módulos de su plan

Ella pidió agregar a cada empresa los módulos que ahora trae su plan, sin quitarle ninguno, en la PC y en el VPS.

- **Módulos:** a cada empresa con suscripción activa se le añadieron en `tenant_modules` los módulos de su plan que no tenía. No se quitó ni se reactivó nada; no había módulos apagados.
  - Empresa Ejemplo SAC (Enterprise): +10, ahora tiene los 22.
  - Yadi SAC (Base): +4, ahora 9.
  - Empresa 3 y Empresa A (Base): +5 cada una, ahora 9.
  - "Empresa" (EMP-52F1DB, solo en el VPS): ya los tenía todos, porque conserva los 14 del Base anterior.
- **Permisos:** se corrió `php artisan tenants:sync-permissions` para que el rol Administrador de sistema de cada empresa tenga los permisos de sus módulos nuevos. Se omiten las empresas sin ese rol: tenant-a, tenant-b y Yadi SAC. Sus administradores tendrán el módulo encendido, pero sus permisos se dan desde Roles y permisos.
- **Respaldo:** en el VPS, `/root/backups/erp/…_antes_modulos_empresas`.
- **Comprobación:** módulos encendidos por empresa, idénticos en la PC y en el VPS: Empresa Ejemplo SAC 22, Yadi SAC 9, Empresa 3 9, Empresa A 9, tenant-a 10, tenant-b 0; en el VPS además "Empresa" 14.

## Después: precios y la usuaria registrada a Enterprise

- **Precios** (PC y VPS, con respaldo en el VPS): el anual equivale a 10 meses, es decir, 2 meses gratis. El Plan 6 no se tocó (S/ 200 y S/ 400).

  | Plan | Antes (mes / año) | Ahora (mes / año) |
  | --- | --- | --- |
  | Base | S/ 200.20 / 1,000.24 | S/ 99 / 990 |
  | Profesional | S/ 120 / 2,500 | S/ 199 / 1,990 |
  | Enterprise | S/ 35 / 1,400.50 | S/ 349 / 3,490 |

  La API pública `/api/v1/saas-plans` del VPS ya devuelve estos precios.
- **la usuaria registrada** (`usuaria@ejemplo.com`, empresa "Empresa", EMP-52F1DB): solo existe en el VPS. Su suscripción activa pasó del Plan Base al Enterprise; sigue en prueba gratis de 30 días y con las mismas fechas. Se le agregaron 8 módulos (CRM, Facturación electrónica, Finanzas, Pagos y cobranzas, Rentabilidad, Soporte, Tickets y Timesheets), quedó con los 22, y su rol Administrador recibió los permisos con `tenants:sync-permissions`.

- **Plan 6 oculto** (PC y VPS): `is_public = false`. Sigue activo y no se borró; ya no aparece en la lista pública (`/api/v1/saas-plans` devuelve Base, Profesional y Enterprise). Desde Superadmin se sigue viendo.

## Pendiente

- Dar desde Roles y permisos los permisos de los módulos nuevos al administrador de Yadi SAC, que no tiene rol de sistema.
- Decidir qué hacer con el Plan 6.
