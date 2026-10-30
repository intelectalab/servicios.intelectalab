# Suscripciones: extensión de vigencia, cobros offline/transferencia y canal de renovación

Fecha: 2026-10-29.

## 1. Qué se pidió

1. Administrar a los clientes que pagan periodos personalizados por transferencia, depósito o WhatsApp (ej. 3 meses, 4 meses, 5 meses, 6 meses o fechas específicas como fin de año).
2. Poder **editar libremente el monto final cobrado** en la consola de Superadmin (pudiendo ingresar el importe acordado con descuento o negociación, independiente del cálculo sugerido por catálogo).
3. Seleccionar la fecha de vencimiento tanto por cantidad de meses (+1m, +3m, +4m, +6m, +12m) como **directamente desde un selector de calendario** interactivo.
4. Definir un **canal claro de comunicación y renovación para el cliente**:
   - Banner de advertencia en el ERP cuando le queden 7 días o menos de vigencia (o si ya venció).
   - Botón directo de **1 clic a WhatsApp** con mensaje precargado con los datos de su empresa y plan.
   - Ventana con los **datos de pago bancarios oficiales** (BCP, BBVA, Yape/Plin) para transferir y adjuntar comprobante.

---

## 2. Qué se cambió

### API (Backend)
- `app/Modules/Subscription/Http/Controllers/SuperAdmin/PlatformTenantController.php`:
  - `extendSubscription(Request $request, string $tenant)`:
    - Valida `ends_at` (fecha obligatoria), `amount` (monto numérico >= 0), `payment_method` (texto obligatorio), `reference` (opcional, nº de operación), `notes` (opcional), `months` (opcional, 1 a 120).
    - Actualiza `tenant_subscriptions.ends_at` con la fecha seleccionada.
    - Cambia `configuration->is_trial` a `false` (pasa formalmente a cliente con pago registrado).
    - Registra el cobro en el historial `configuration->payments` (ID único UUID, monto, moneda, medio de pago, referencia, meses, fechas y usuario superadmin que lo registró).
    - Registra la acción en la auditoría de plataforma (`tenant.subscription_extended`) con los datos anteriores y posteriores.
  - `show(string $tenant)`:
    - Adjunta el listado de cobros `payments` a la respuesta del tenant para alimentar la ficha de Superadmin.
- `routes/api.php`:
  - `POST /v1/superadmin/tenants/{tenant}/extend-subscription` registrado bajo el middleware de plataforma.
  - `GET /v1/superadmin/settings` y `PUT /v1/superadmin/settings` para consultar y actualizar la configuración global de canales de soporte (WhatsApp y correo).
- `app/Modules/Subscription/Support/PlatformSettings.php` y `app/Modules/Subscription/Http/Controllers/SuperAdmin/PlatformSettingsController.php`:
  - Gestiona la configuración global de la plataforma con persistencia en `platform_settings` y auditoría (`platform.settings_updated`).
- `app/Http/Controllers/Auth/AuthController.php`:
  - En los endpoints `access()` y `profile()`, ahora se incluye el bloque `support` dinámico con `whatsapp`, `email` y `schedule`.

### Web (Frontend)
- `src/app/superadmin/settings/page.tsx` y `src/components/superadmin/settings/PlatformSettingsView.tsx`:
  - Nueva pantalla **"Configuración de la plataforma"** accesible desde el menú lateral de Superadmin.
  - Permite configurar el WhatsApp oficial de soporte, correo de facturación y horario de atención, con vista previa interactiva en tiempo real.
- `src/components/superadmin/dashboard/PlatformDashboardView.tsx` y `src/components/ui/proto.tsx`:
  - Corrección del warning de React (`Encountered two children with the same key`): ahora se asegura clave única basada en ID o índice para eventos con el mismo timestamp y usuario.
- `src/types/platform.ts`:
  - Se define el tipo `SubscriptionPayment` y se agrega `payments?: SubscriptionPayment[]` al detalle de la empresa (`PlatformTenantDetail`).
- `src/components/superadmin/tenants/TenantDetail.tsx`:
  - Botón principal **`Registrar pago / Extender`** en la tarjeta de Suscripción.
  - Modal interactivo de registro de pago:
    - Atajos rápidos de meses: `+1 Mes`, `+3 Meses`, `+6 Meses`, `+12 Meses`.
    - Contador numérico libre de meses `[ - ] [ N ] meses [ + ]` (para 2, 4, 5, 7 o cualquier número de meses).
    - **Selector nativo de calendario (`<input type="date">`)**: permite marcar cualquier fecha exacta en el calendario, calculando en tiempo real los días restantes y sugiriendo el proporcional.
    - **Monto 100% editable**: inicializado con el costo sugerido según el plan, pero completamente modificable para registrar descuentos o importes negociados.
    - Selector de medio de pago: Transferencia BCP, BBVA, Interbank, Scotiabank, Yape / Plin, Efectivo / Depósito, Tarjeta o Otros.
    - Casilla de Nº de Operación / Referencia y notas comerciales internas.
  - Tarjeta **"Historial de pagos (renovaciones)"**: muestra la tabla con fecha, monto pagado, periodo, método, referencia bancaria, nueva vigencia y usuario que registró la operación.
- `src/components/account/ProfileView.tsx`:
  - Se integra la consulta del plan y vigencia de la empresa directamente dentro de la tarjeta **"Cuenta"** usando la tabla estándar `Kv` y badges `Chip`.
  - Muestra el nombre real del plan contratado (ej. *Emprendedor*, *Empresa*, *Enterprise*), su estado (*Prueba gratuita* o *Activo*) y la fecha de vigencia con días restantes.
  - Enlace rápido **"Extender"** en la fila de vigencia.
  - Bloque destacado al pie de la tarjeta: **"¿Quieres extender o renovar tu plan?"** con botón **"Contactar con soporte"**.
  - Modal nativo del sistema de diseño para contactar con soporte: resume la empresa y plan actual, e incluye enlaces directos para iniciar chat de WhatsApp con soporte (con mensaje prellenado) y envío de correo a facturación/soporte.
- `src/components/layout/AppShell.tsx`:
  - Detección preventiva de vencimiento: si la empresa tiene fecha de vigencia asignada y está a 7 días o menos de vencer (o ya venció), muestra un banner sutil de aviso (`bg-amber-50 border-amber-300 dark:bg-amber-950/40`) con el botón **"Extender plan"** (que conduce a Mi perfil) y botón para descartar.

---

## 3. Pruebas y Verificación

- **Backend (PHPUnit):**
  - Nueva prueba en `tests/Feature/PlatformTenantsTest.php`:
    - `test_superadmin_can_extend_tenant_subscription_with_custom_amount_and_date`: verifica que el endpoint registre el pago, cambie la fecha de expiración, guarde el medio y la referencia, y que la ficha `show` devuelva el cobro en el historial.
  - `HeaderFeaturesTest`: verifica que `access()` devuelva los datos de sesión y suscripción intactos.
  - Resultados: **16 pruebas unitarias pasadas (210 aserciones), 0 fallos**.
- **Frontend (TypeScript):**
  - `npx tsc --noEmit` en `apps/web`: **0 errores de compilación**.
- **Prototipo interactivo:**
  - Disponible en la carpeta de artefactos: `prototipo_planes_vigencia.html`.
