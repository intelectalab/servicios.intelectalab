# Brechas de código del reporte de avance

Fecha: 2026-11-04.

## 1. Qué se pidió

Cerrar las brechas del reporte de avance (comparación con el kit) que dependen del código y no de un proveedor. Decisiones de la usuaria:

- **Vencimiento:** 7 días de gracia con aviso y, después, solo lectura hasta renovar (no se pierden datos).
- **Límites del plan:** bloquean crear más y avisan cuando se está cerca.

## 2. Qué se cambió

### 2.1. Vencimiento de la suscripción

- `SubscriptionState`: `active`, `grace` (venció hace menos de 7 días) o `read_only`. Se calcula por la fecha de fin, así que al extender la vigencia desde la consola vuelve sola a la normalidad. Sin suscripción (empresa armada a mano), sin restricción.
- Middleware `subscription.writable` (en el ERP y en el portal): en solo lectura rechaza crear y modificar con 403 `SUBSCRIPTION_READ_ONLY` y un mensaje claro. Siguen permitidos ver y exportar, lo propio de cada persona (`/me/*`), dejar constancia de una exportación y pedir la renovación (`/tenant/plan/request`).
- Cabecera: en gracia, aviso ámbar a quien administra ("tienes hasta el … para renovarla"); en solo lectura, aviso rojo para todos, sin cerrar, con "Renovar" para el administrador. El aviso de "vence en N días" ahora lleva a Módulos y plan.

### 2.2. Límites del plan

- En la base (migración `2026_11_03_000100_plan_limits_guard`): antes de cada alta de cliente, servicio, orden de trabajo, proyecto, documento, usuario del portal, lead, oportunidad o sede, la base cuenta lo que ya tiene la empresa y lo compara con el límite de su plan. Lo cumplen también la importación masiva y cualquier otra vía. Dos altas a la vez no pasan el límite (candado por empresa y clave). Los usuarios activos se siguen controlando en la app.
- La API convierte el rechazo en 422 `PLAN_LIMIT_REACHED`: "Llegaste al límite de tu plan: 5 leads. Para crear más, pide un plan mayor en Administración → Módulos y plan." La importación muestra el mismo mensaje en las filas que no entran.
- `PlanLimits` reúne límites, uso y aviso. Desde el 80 % aparece un aviso en la cabecera para quien administra ("Estás cerca del límite en leads (4/5)" / "Llegaste al límite…") y en Módulos y plan (la nota bajo cada barra empieza también en el 80 %).

### 2.3. Protecciones en la base y roles base

- Migración `2026_11_03_000200_tenant_tables_guard`:
  - `tenants`: nadie la borra; el código y el estado solo los cambia la consola (sesión de superadmin); cada empresa solo cambia su propia fila; una empresa nueva nace activa o en alta.
  - `tenant_modules`: nadie la borra; sin la consola solo se enciende o agrega un módulo del plan vigente (o uno obligatorio); una empresa sin plan solo vuelve a encender lo que ella apagó.
  - `role_permissions`: con aislamiento por empresa a través de su rol (antes no tenía regla).
- La consola usa `PlatformWrites` también al cambiar el estado, el enlace de ingreso y al aplicar los módulos del plan.
- **Roles base** (`BaseRoles`): una empresa nueva nace con Gerencia, Ventas, Operaciones, Técnico, Finanzas y Soporte, traducidos de la matriz rol × recurso del kit (`docs/06-PERMISOS.md`), solo con los permisos de sus módulos y con su alcance (Ventas: lo propio; Operaciones: su sede; Soporte: su equipo…). Son roles normales: la empresa los edita en Roles y permisos. El aprovisionamiento crea primero la suscripción y después los módulos.
- `composer.json`: PHP `^8.4` (producción, CI y la PC ya usan 8.4).

### 2.4. Hora de Lima

- La aplicación sigue guardando en UTC; las fechas de negocio salen del calendario de Lima con `LocalTime` (55 lugares: emisión, vencimientos, "hoy" del panel, cobros y CxP vencidos, año del presupuesto, ciclos recurrentes, cotizaciones vencidas…). `LocalTime::today()` es la fecha de Lima a medianoche en la zona de la app, para compararla bien con las columnas `date`.
- En SQL, `CURRENT_DATE` pasó a `(now() AT TIME ZONE 'America/Lima')::date`, y la base pone la fecha de Lima por defecto en `orders.order_date` y `cost_entries.incurred_at` (migración `2026_11_03_000300_local_date_defaults`).

### 2.5. Tipo de cambio diario (INT-FX)

- Comando `exchange-rates:sunat` a las 09:00 y 13:00 (Lima): lee una vez lo que publica SUNAT y lo guarda en cada empresa activa con Finanzas. No pisa lo que la empresa ya tenga para ese día (por ejemplo, uno registrado a mano). Si SUNAT no responde, no guarda nada.

### 2.6. App del técnico sin señal

- `public/sw.js`: guarda la pantalla `/campo` y los archivos de la web la primera vez que se abre con conexión (nunca la API ni otras pantallas). Se registra solo en producción; `next.config.ts` lo sirve con `no-cache` y CSP propia.
- Sin señal, el armazón ya no muestra "No se pudo cargar tu sesión" en `/campo`: abre la app con la ruta y la cola guardadas en el teléfono.

### 2.7. Pruebas de punta a punta (Playwright)

- `apps/web/e2e` con `@playwright/test` 1.64.1 y el Chrome instalado (sin descargar navegadores). `npm run e2e` con la API (`127.0.0.1:8000`) y la web (`app.localhost:3000`) levantadas.
- `E2eSeeder` crea dos empresas propias: "E2E Activa" (4 de 5 leads) y "E2E Vencida" (vencida hace 10 días). La contraseña sale de `E2E_PASSWORD` (en `apps/api/.env` y `apps/web/.env.e2e`, que no se suben); el seeder corre antes de cada corrida (`e2e/global-setup.ts`).
- Flujos: aviso al 80 % y bloqueo al llegar al límite; solo lectura tras la gracia; roles base de una empresa nueva; y la App del técnico abriendo sin señal (solo contra la compilación de producción: `next build`, `next start -p 3200` y `E2E_BASE_URL=http://app.localhost:3200`).

## 3. Pruebas

- API: 356 pruebas (7 563 aserciones), con las nuevas `PlanLimitsAndExpiryTest`, `TenantTablesGuardTest` y `LocalTimeTest`. Las pruebas que preparan módulos fuera de un plan o empresas suspendidas usan `asPlatformFixture` (sesión de superadmin de prueba), como lo haría la consola.
- Web: lint y tipos sin errores; compilación de producción correcta.
- E2E: 4 de 4 contra producción local (3 en desarrollo, la de sin señal se salta ahí).
- `schema_reference.sql` regenerado (las tres migraciones nuevas), con el bloque de planes del repositorio (sin los planes locales de prueba).

## 4. Lo que queda

- En la PC, "Tenant AB - Prueba" está en Enterprise con límites de prueba (por ejemplo, 10 órdenes de trabajo): ahora esos límites bloquean. Para seguir probando, subir los límites desde la consola.
- Las empresas que ya existen no reciben los roles base (solo las nuevas). Se puede agregar un comando si se quiere.
- Las pruebas E2E no corren en GitHub (necesitan la API, la web y la base levantadas); quedan para correr en local antes de desplegar.
- Siguen dependiendo de un proveedor: OSE/PSE, pago en línea, WhatsApp, antivirus y gestor externo de secretos.
