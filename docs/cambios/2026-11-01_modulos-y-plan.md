# Módulos y plan (Administración)

Fecha: 2026-11-01.

## 1. Qué se pidió

Construir **Administración → Módulos y plan** para el administrador de cada empresa, con estas decisiones:

- La empresa apaga o enciende los módulos que su plan incluye. Agregar uno fuera del plan lo hace la plataforma.
- "Solicitar cambio de plan" por WhatsApp o correo de soporte, con registro.
- Se muestran todos los límites con su uso, sin explicar al cliente cuáles bloquean.
- Solo la ve el Administrador (`tenant.manage`).

## 2. Qué se cambió

**Pantalla** (`/plan`; `components/dashboard/admin/PlanModules.tsx`):

- **Mi plan:** nombre, "Activo" o "Prueba gratuita", aviso de vencimiento (7 días o menos), vigencia, precio mensual y anual, soporte, y lo que siempre está incluido. Sin plan asignado lo dice y ofrece solicitarlo.
- **Uso del plan:** barras de uso frente al límite, en ámbar desde el 60 % y en rojo desde el 85 %. Desde el 85 % aparece un aviso bajo la barra ("Te quedan 2" o "Llegaste al límite de tu plan") con **"Solicitar más"**, que abre el pedido con el mensaje ya escrito. No se le explica al cliente qué límites bloquean y cuáles no (por pedido suyo: era un detalle interno).
- **Módulos**, agrupados por área. Cada tarjeta muestra el estado (Activo, Apagado por la empresa, Apagado o No incluido en tu plan) y lo guardado (por ejemplo "7 leads · 8 oportunidades").
  - Los del plan llevan "Apagar" o "Encender".
  - Los que no están en el plan llevan "Solicitar".
  - **Apagar pide motivo y no borra nada.**
- **Pagos de la suscripción**, los que registra la consola, con exportación a Excel o CSV.
- **Historial de módulos y pedidos:** quién encendió o apagó cada módulo (con su motivo) y los pedidos de cambio de plan.
- **"Solicitar cambio de plan" / "Solicitar":** abre el WhatsApp o el correo de soporte con el mensaje ya escrito ("Hola, soy de la empresa X (Plan Y). Quisiera…") y lo deja registrado. Usa los canales de Consola → Configuración; si no hay, avisa.

**Menú:** un módulo que la empresa apagó sale con candado **"Apagado"** (no "Plan"). Su pantalla dice: "Este módulo está apagado en tu empresa. El administrador puede encenderlo en Administración → Módulos y plan." `/v1/me/access` devuelve `modules_off`.

**API** (`TenantPlanController`, permiso `tenant.manage`):

- `GET /v1/tenant/plan`: plan, módulos, uso, pagos, historial y canales de soporte.
- `PUT /v1/tenant/modules/{código}`:
  - Solo módulos que el plan incluye; los obligatorios y los de fuera del plan dan 422.
  - Apagar exige motivo.
  - Guarda en `tenant_modules.configuration` que lo apagó la empresa.
  - Queda en Auditoría (`module.enable`, `module.disable`).
  - En una empresa sin plan, lo que apagó la plataforma no se enciende desde aquí.
- `POST /v1/tenant/plan/request`: registra el pedido (`plan.change_requested`), con un límite de 10 por minuto.

**Protección:** con **Finanzas apagado**, la tarea diaria de los servicios recurrentes ya no procesa los ciclos de esa empresa. Esperan a que se encienda, en lugar de facturarse a escondidas.

## 3. Qué NO se tocó

- **Cambiar de plan o agregar un módulo fuera del plan:** sigue siendo de la consola. Si la plataforma vuelve a aplicar los módulos del plan, se encienden de nuevo los que la empresa había apagado.
- **Qué pasa cuando vence la suscripción:** sigue pendiente. Aquí solo se muestra el aviso.
- **Bloquear al llegar a los demás límites** (clientes, OT, documentos…): sigue pendiente. Solo se muestran.

## 4. Diferencias con el prototipo

- El prototipo solo tiene tres módulos con interruptor (CRM, Portal, Integraciones). Aquí están todos los del catálogo, con lo que tienen guardado.
- Mejoras: vigencia y precios del plan, pagos, pedido de cambio por WhatsApp o correo, y el aviso al acercarse o llegar a un límite.

## 5. Base de datos

Sin migraciones. Usa `saas_plans`, `tenant_subscriptions`, `tenant_subscription_payments`, `tenant_modules` (en `configuration` se guarda quién lo apagó) y `audit_logs`.

**Hallazgo para revisar aparte:** el usuario de la aplicación (`erp_app`) tiene permiso de modificar y borrar en `saas_plans`, `module_catalog` y `tenant_subscriptions`. Son datos de la plataforma, y lo correcto sería que solo los cambie la consola. No se tocó en esta tanda.

## 6. Cómo se probó

- **Pruebas nuevas en `TenantPlanTest`** (3 pruebas, 36 comprobaciones):
  - El administrador ve plan, módulos y uso.
  - Apagar el CRM pide motivo; apagado, la API del CRM da 403 y los leads siguen; al encenderlo vuelve con sus datos.
  - Módulo fuera del plan u obligatorio: 422.
  - Historial y pedido de cambio.
  - Uso frente a límites (la API sigue diciendo cuáles bloquean, para uso interno) y pagos.
  - Gerencia: 403. Otra empresa no toca los módulos de esta.
- **API completa:** las 320 pruebas pasan. `tsc` y ESLint sin errores.
- **Navegador como Administrador** (1366 × 768 y 375 px):
  - Plan Enterprise con su vigencia y precios, y los 14 módulos con lo que tienen guardado.
  - Agenda: apagar sin motivo da error; apagada con motivo, el menú dice "Apagado" y su pantalla lo explica; encendida otra vez, vuelve con sus 3 eventos. El historial registró los dos cambios.
  - "Solicitar cambio de plan" arma los enlaces de WhatsApp y correo con el mensaje.
  - Con límites de prueba en el Plan Enterprise local (OT 9 de 10): la barra en rojo con "Te queda 1 · Solicitar más", que abre el pedido "Quisiera ampliar el límite de órdenes de trabajo de mi plan (hoy 9 de 10)".
  - Sin desplazamiento lateral en el móvil.

## 7. Pendiente

- **Poner los canales reales de soporte en Consola → Configuración.** Hoy están los de ejemplo, `+51999999998` y `soporte@erp-saas.com`, y los pedidos irían ahí.
- Decidir qué pasa cuando vence la suscripción y si se bloquean los demás límites.
- Revisar los permisos de `erp_app` sobre las tablas de la plataforma (hallazgo de arriba).
