# Tablas de plataforma protegidas frente a la app de las empresas

Fecha: 2026-11-01.

## 1. Qué se pidió

Cerrar el hueco de permisos encontrado al hacer Módulos y plan: el usuario de base de la aplicación (`erp_app`), el mismo que atiende a todas las empresas, podía modificar y borrar `saas_plans`, `module_catalog` y `tenant_subscriptions`. Un error en cualquier pantalla de empresa podía alargarle la vigencia a una empresa, subirla de plan, cambiar precios o módulos de todos los planes, o borrar el catálogo.

## 2. Qué se cambió

**Quién escribía en esas tablas (revisado antes de tocar):**

| Tabla | Quién la cambia | Borra |
| --- | --- | --- |
| `module_catalog` | Solo su seeder, que corre dentro de una migración (como `erp_user`) | Nadie |
| `saas_plans` | Consola → Planes (crear, editar, desactivar) | Nadie: "eliminar" desactiva |
| `tenant_subscriptions` | Registro público y "Aprovisionar" (primera suscripción); consola: cambiar plan y extender vigencia | Nadie |

**En la base** (migración `2026_11_01_000600_platform_tables_write_guard`):

- `module_catalog`: la app solo lee.
- `saas_plans` y `tenant_subscriptions`: la app ya no puede borrar ni vaciar (sin DELETE ni TRUNCATE).
- Crear o cambiar un plan o una suscripción exige una **sesión de superadmin vigente y verificada en dos pasos**. Lo comprueba un trigger (`guard_platform_tables`) contra la sesión real (`personal_access_tokens` con `mfa`, sin vencer, de un superadmin activo). Una cuenta de empresa, una sesión sin código, vencida o de un superadmin desactivado no sirven.
- **Sin esa sesión solo entra la primera suscripción de una empresa nueva**, que es lo que hace el registro público. Debe estar activa, con un plan activo y **público**, y su vigencia no puede pasar de un año (o de la prueba del plan, si es más larga). Una segunda suscripción, alargar la vigencia o cambiar de plan desde fuera de la consola se rechaza.
- Una suscripción no cambia de empresa.
- Las migraciones (el dueño de las tablas) no pasan por el control.

**En la API:**

- `App\Services\PlatformWrites`: indica a la base la sesión del superadmin, solo dentro de la transacción. La usan Planes (crear, editar, desactivar) y, en Tenants, Aprovisionar, Extender vigencia y Cambiar plan. Ahora crear, editar y desactivar un plan se guardan junto con su registro en la auditoría de plataforma, en la misma transacción (antes iban por separado).
- **Registro público:** solo acepta planes activos y públicos, los mismos que muestra la página. Antes aceptaba cualquier plan existente, también uno privado o desactivado. Mensaje: "Ese plan no está disponible. Elige uno de los planes de la página."

## 3. Qué NO se tocó

- Las lecturas: la app sigue leyendo planes, catálogo y suscripciones como antes.
- Los cambios de la consola siguen hechos por la app (`erp_app`), no por el usuario de la consola (`erp_console`). Pasarlos a `erp_console` exigiría partir en dos transacciones operaciones como aprovisionar (la empresa nace en una conexión y su suscripción iría en otra). La protección queda en la base, con el trigger.
- `tenants` y `tenant_modules`: no estaban en el pedido. Ver Pendiente.

## 4. Diferencias con el prototipo

Ninguna: no cambia ninguna pantalla.

## 5. Base de datos

Migración `2026_11_01_000600_platform_tables_write_guard` (correr como `erp_user`; en el VPS la corre `deploy.sh`):

- Funciones `platform_session_user()` y `guard_platform_tables()` (las ejecuta solo el dueño, desde el trigger).
- Triggers `trg_saas_plans_guard` y `trg_tenant_subscriptions_guard` (antes de crear o cambiar una fila).
- Permisos de `erp_app`: `module_catalog` solo SELECT; `saas_plans` y `tenant_subscriptions` SELECT, INSERT y UPDATE (con el trigger), sin DELETE ni TRUNCATE.
- Vuelta atrás: `php artisan migrate:rollback --path=database/migrations/2026_11_01_000600_platform_tables_write_guard.php` (como `erp_user`). Quita los triggers y devuelve los permisos de antes.

`database/schema_reference.sql` (lo usa la prueba de GitHub) regenerado con la migración. Los límites de prueba de Enterprise de la base local no van en él: Enterprise queda con `{}`, como estaba.

## 6. Cómo se probó

- `php artisan test`: **330 pruebas pasan** (325 de antes y 5 nuevas en `PlatformTablesGuardTest`):
  - el catálogo de módulos no se crea, cambia ni borra, ni siquiera con sesión de superadmin;
  - planes y suscripciones no se borran ni se vacían;
  - sin sesión no cambian el precio de un plan, no se crea un plan, no se alarga la vigencia, no se sube de plan ni se crea una segunda suscripción;
  - no sirven una sesión sin verificación, de un superadmin desactivado, vencida o inexistente, un texto cualquiera ni la sesión de una cuenta de empresa; con una sesión verificada sí se puede, y aun así una suscripción no cambia de empresa;
  - la primera suscripción exige un plan activo y público y una vigencia acotada; el registro público rechaza un plan privado.
- Las pruebas de la consola que ya existían (Planes, Aprovisionar, Cambiar plan, Extender vigencia, Auditoría) pasan por las rutas reales con una sesión real de superadmin.
- En el sistema local, como Administrador: Módulos y plan carga su plan y sus módulos como antes. La sesión se cerró al terminar.
- **No probado en el navegador:** editar un plan desde la consola con tu cuenta de superadmin (pide tu contraseña y un código).

## 7. Pendiente

- **`tenants`**: la app aún puede borrar empresas y cambiarles el estado sin pasar por la consola, porque la tabla no tiene control en la base. Lo cambian la consola (estado, enlace de ingreso) y la propia empresa (Marca, ajustes). Se podría aplicar el mismo control a las columnas de la plataforma (estado, código) y quitar el permiso de borrar.
- `tenant_modules`: la empresa enciende y apaga sus módulos (Módulos y plan). La API comprueba que estén en el plan, pero la base no.
- Seeders que escriban planes o suscripciones deben correrse con `erp_user` (hoy no hay ninguno).
