# Correcciones: pagos de suscripción, configuración de la plataforma y aviso de vencimiento

Fecha: 2026-10-31.

## 1. Qué se pidió

Revisar los cambios del 28 y 29 de octubre (`f09c6b7`, `d39cf49`, `4487efe`; nota `2026-10-29_suscripciones-vigencia-pagos-offline-y-renovacion-whatsapp.md`) y corregir todo lo encontrado.

## 2. Qué se cambió

**Base de datos** — migración `2026_10_31_000100_subscription_payments_and_platform_settings_access` (solo añade y quita permisos; corrida en la PC como `erp_user`; en el VPS la corre `deploy.sh`):
- Tabla **`tenant_subscription_payments`**: un pago por fila, aislada por empresa (misma regla que la suscripción). La app inserta y lee, **no edita ni borra**. Controles: monto ≥ 0, medio de pago obligatorio, meses 1–120 y la nueva vigencia posterior a la anterior. Los pagos que estaban dentro de `tenant_subscriptions.configuration` se pasaron a la tabla y se quitaron de la configuración (en el VPS: el de EMP-A5B191).
- **`platform_settings`**: la app de las empresas (`erp_app`) ya no la lee ni la cambia; solo el usuario de la consola (rol `erp_platform`). Se borra la fila de ejemplo de la primera versión solo si quedó **exactamente** igual (`+51999999999` / `soporte@erp-saas.com`).

**API**
- `PlatformTenantController::show`: el historial de pagos se lee **dentro del contexto de la empresa**. Antes la regla de aislamiento lo ocultaba en el servidor y la ficha siempre salía "Sin cobros".
- `extendSubscription`: la nueva vigencia debe ser **posterior a la actual** (o a hoy, si ya venció); un error se corrige con otro pago, no acortando. Guarda el pago en su tabla. Si la empresa no tenía suscripción, se crea con el primer plan activo **y se le aplican sus módulos** (antes quedaba sin módulos). Mensajes de validación en español.
- `PlatformSettings`: lee y escribe con el usuario de la consola, con caché que se renueva al guardar. **Sin datos de ejemplo**: si la plataforma no configuró sus canales, devuelve `null` y las empresas no ven ningún contacto.
- `PlatformSettingsController`: el WhatsApp debe ser un número con código de país (8 a 15 dígitos); la auditoría se escribe con el usuario de la consola.

**Web**
- `AppShell`: el aviso de vencimiento lo ve **solo quien administra la empresa** (`tenant.manage`) y usa los **colores de aviso del sistema** (`--p-notice-*`, `--p-warning-*`), que siguen el botón de tema (antes, `amber` + `dark:` de Tailwind seguían el modo oscuro de Windows, no el del ERP).
- `ProfileView`: plan y vigencia para todos; "Extender" y "Contactar con soporte" solo para quien administra la empresa. Colores del sistema en lugar de `amber`/`emerald`/`sky`. Sin canales configurados, el modal lo dice en vez de mostrar un número de ejemplo. Arreglada la `key` que faltaba.
- `PlatformSettingsView`: sin datos de ejemplo precargados (aviso mientras no se guarden), errores junto a cada campo y colores del sistema.
- `TenantDetail` y `PlatformSettingsView`: usaban `--app-text-muted`, que no existe (los textos no salían en gris): ahora `--app-muted`. Monto del historial con el color de éxito del sistema.
- `lib/subscription.ts` (nuevo): días que faltan y fecha base para extender, compartido.
- ESLint: los 9 errores corregidos (fechas calculadas fuera del render, memorias manuales quitadas, `any`, variables sin usar).
- `lib/platform.ts`: textos "Pago registrado y vigencia extendida" y "Configuración de la plataforma actualizada" en la Auditoría de plataforma.

**Docs**
- Nota del 29: se corrigió lo que decía de las pruebas y que la ventana de datos bancarios no se hizo; quedó en el índice.

## 3. Qué NO se tocó

- **Qué pasa cuando la suscripción vence**: hoy la empresa sigue entrando igual. Bloquear (o dejar solo lectura) es una decisión pendiente.
- La ventana con datos bancarios (hacen falta las cuentas reales).
- El historial de pagos no tiene botón de exportar todavía.

## 4. Diferencias con el prototipo

Ninguna nueva.

## 5. Base de datos

La migración de arriba. **No borra datos**; mueve los pagos de la configuración a su tabla.

## 6. Cómo se probó

- `php artisan test`: **298 pruebas pasan**. `PlatformTenantsTest` ampliada: rechaza fecha pasada y acortar; la ficha muestra el pago **sin contexto de empresa** (como en el servidor); el pago queda en su tabla y no en la configuración; la app no puede editarlo ni borrarlo; WhatsApp inválido rechazado; auditoría de la configuración; la app no puede leer ni cambiar `platform_settings`.
- `tsc --noEmit` y ESLint sin errores en los archivos tocados.
- Navegador (PC), con el correo local pasado a `log` mientras duró la prueba (luego se devolvió a `smtp`):
  - Administrador: plan "Enterprise", vigencia, "Extender" y el modal con los canales guardados. Ventas: plan y vigencia, sin "Extender" ni "Contactar con soporte".
  - **Aviso de vencimiento**, con la vigencia de Tenant A puesta a 3 días (y devuelta luego a 2027-10-30 03:29:15 UTC, la original): "vence en 3 días (03 nov. 2026)"; sigue el botón de tema del ERP (oscuro `#47380F`, claro `#FFF6DE`) aunque Windows esté en oscuro; "Extender plan" lleva a Mi perfil con "(3 días restantes)"; la X lo cierra. **Ventas no lo ve.**
  - **Sin canales** (se quitaron un momento y se repusieron igual): el modal dice "La plataforma todavía no publicó sus canales de soporte"; la Configuración de la consola muestra los campos vacíos y "Aún no hay canales guardados".
  - **Consola**, con un superadmin de prueba creado con `platform:super-admin` (invitación → crear contraseña → código de verificación; al final se desactivó con `--revoke`): WhatsApp inválido muestra el error junto al campo y no guarda; en **Consola Prueba SAC** una fecha anterior a la vigencia se rechaza y +3 meses queda en el historial (S/ 304.00, OPE-PRUEBA-31, vigencia al 22/02/2027 — ese pago queda como dato de prueba: la tabla no deja borrarlo); la Auditoría de plataforma muestra los textos nuevos.
  - Se encontró y corrigió en la prueba: el mensaje de la fecha mostraba el día en UTC (23/11 en vez de 22/11); ahora en hora de Lima.

## 7. Despliegue (31/10, ya 01/11 en el VPS)

- Primer intento: `deploy.sh` se detuvo en las migraciones porque la contraseña de `erp_user` de `/root/erp_db_passwords.txt` ya no era la de la base (alguien la cambió después del 20/10). Durante unos minutos el VPS quedó con el código nuevo de la API sin la migración (la ficha de tenant de la consola fallaba); el ERP siguió funcionando. Respaldo: `/root/backups/erp/20261101_022052_antes_deploy_4487efe`.
- Yadira actualizó el archivo con la contraseña vigente; comprobada sin mostrarla y despliegue retomado: **`abd2a14` desplegado**, migración `2026_10_31_000100` corrida (lote 9), web compilada, `/login` 200.
- Verificado en el VPS: `erp_app` ya no puede leer `platform_settings`; los canales se leen por la consola; el pago de **EMP-A5B191** (S/ 512.00, Transferencia BCP, vigencia al 30/03/2027) quedó en `tenant_subscription_payments` y ya no en la configuración; la app no puede editarlo. Los dos errores del log de ese día son del intento fallido.

## 8. Pendiente

- En el VPS (y en tu PC) los canales siguen con `+51999999998` y `soporte@erp-saas.com`, que no son reales: **cámbialos en Consola → Configuración** antes de que una empresa los use.
- Decidir qué pasa con una empresa vencida.
