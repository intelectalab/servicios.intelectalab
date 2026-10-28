# Límite de crédito del cliente: avisa o bloquea al comprometer trabajo

Fecha: 2026-10-27.

## 1. Qué se pidió

El campo "Límite de crédito" del cliente solo se guardaba y se mostraba. Se aprobó la propuesta:

- **Deuda** = sus cuentas por cobrar pendientes (no lo que está "Por facturar").
- Se controla **al aprobar la cotización** (en el ERP y desde el portal) y **al activar el contrato**; al **emitir el comprobante** solo se avisa (el servicio ya se prestó y facturar es obligatorio).
- **Regla de la empresa** con "Solo avisar" por defecto (pide motivo) o "Bloquear".
- **Permiso nuevo** "Aprobar sobre el límite de crédito", para Gerencia por defecto.
- De paso: **0 días = Contado** (vence el mismo día) y la opción "Contado" en Días de crédito.

## 2. Qué se cambió

**API**
- `Modules/Finance/Support/CreditLimit.php` (nuevo):
  - `status()`: límite, deuda, disponible, exceso. La deuda es de **todo el cliente** (no solo lo que alcanza quien consulta), en la moneda del cliente; lo que está en otra moneda se convierte al tipo de cambio de venta vigente (si no hay, no se suma y se avisa). Sin límite → `null`. Límite 0 → sin crédito: cualquier monto lo supera.
  - `warning()`: aviso para las fichas. Los montos (límite, deuda, exceso) solo llegan a quien tiene `receivables.view`; los demás ven "el cliente supera su límite" sin cifras.
  - `guard()`: al aprobar/activar. Con "Bloquear" y sin `credit.approve` → error `credit_limit`. Si no, exige `credit_reason` (hasta 500 caracteres) y devuelve el texto para el historial: "Sobre el límite de crédito (S/ X de exceso): motivo".
- `TenantSettingsController`: ajuste `credit_limit_mode` (`WARN` por defecto, `BLOCK`), con auditoría como los demás.
- `QuotationController::approve`: aplica `guard`; el motivo queda en la decisión (tarjeta Aprobaciones) y en el historial. La ficha trae `credit` mientras está en borrador o enviada.
- `ContractController::activate`: aplica `guard`; el motivo queda en el historial. La ficha trae `credit` en borrador.
- `PortalCommercialController`: con "Bloquear", el cliente **no aprueba** ("Para aprobar esta cotización comunícate con tu ejecutivo de cuenta.", sin mostrar su deuda) y puede rechazar; con "Solo avisar" aprueba y la empresa ve "Sobre el límite de crédito" en el historial. Ficha con `credit_blocked`.
- `InvoiceController`: la ficha del borrador trae `credit` (solo aviso, nunca bloquea). **Vencimiento:** `payment_terms_days ?? 30` (antes `?:`, que trataba 0 como 30).
- `PartyController`: la vista del cliente trae `finance.credit` (usado y disponible), para quien ve cuentas por cobrar.
- `DashboardController`: "Requiere tu atención" → **clientes sobre su límite de crédito** (con `receivables.view`, entre los clientes cuyas cuentas alcanza).
- `ModulePermissions::BASE`: incluye `credit.approve` (no depende del plan: si no, la matriz de Roles no lo mostraba).

**Web**
- `actors/ActorDetail.tsx`: en Condiciones comerciales, "Sin límite" cuando no hay, "Contado" con 0 días y barra **Crédito usado / Disponible** (en rojo y "Excedido por…" si pasa el límite).
- `actors/ActorForm.tsx`: opción **Contado** en Días de crédito.
- `quotations/QuotationDetail.tsx`: aviso arriba; "Aprobar" pide **Motivo para aprobar sobre el límite de crédito** (obligatorio); con "Bloquear" y sin permiso, el botón queda deshabilitado con su motivo.
- `contracts/ContractDetail.tsx`: lo mismo al activar.
- `finance/InvoiceDetail.tsx`: aviso arriba y en la ventana de emitir ("Igual se puede emitir").
- `admin/RolesPermissions.tsx`: regla **Cliente sobre su límite de crédito** (Solo avisar / Bloquear) en Reglas de la empresa y fila **Límite de crédito de clientes** (Aprobar) en la matriz.
- `portal/PortalQuotations.tsx`: con "Bloquear", el aviso y solo "Rechazar".

## 3. Qué NO se tocó

- Orden directa: nace de una cotización ya aprobada (ya pasó el control).
- Cotizaciones aprobadas, contratos y "Por facturar" no cuentan como deuda.
- El límite no se recalcula solo por tiempo: se mide en el momento de aprobar o activar.

## 4. Diferencias con el prototipo

Ni el prototipo ni el kit tienen esta regla: el campo venía del sistema original. Las decisiones son las de la propuesta aprobada.

## 5. Base de datos

- Migración `2026_10_27_000100_credit_limit_permission` (solo añade): permiso `credit.approve`, entregado al Administrador de cada empresa y a Gerencia. **Corrida en la PC** como `erp_user`; en el VPS la corre `deploy.sh`.
- El ajuste va en `tenants.configuration` (sin columna nueva).

## 6. Cómo se probó

- `php artisan test`: **291 pruebas pasan**. Nuevas: `CreditLimitTest` (4: aprobar con motivo / bloqueo / permiso; sin montos para quien no ve CxC; cliente sin límite; activar contrato; emitir solo avisa; ficha del cliente; panel; permiso asignable desde Roles con cualquier plan; contado vence el mismo día) y `PortalTest › over the credit limit the customer approves only if the company allows it`.
- `tsc --noEmit` y ESLint sin errores.
- Navegador, cuenta Administrador (Tenant A):
  - A **Minera Prueba CRM S.A.C.** se le puso límite **S/ 8,000** desde Editar (queda así como dato de prueba): ficha con "Crédito usado S/ 6,423.59 · Disponible S/ 1,576.41" y la barra; "Contado" en Días de crédito.
  - **COT-2026-0013** (S/ 3,480, enviada): aviso "supera su límite de crédito (S/ 8,000.00) por S/ 1,903.59; hoy debe S/ 6,423.59"; Aprobar pide el motivo y avisa si falta. **No se aprobó.**
  - **CTR-2026-0007** (borrador): lo mismo al activar. **No se activó.**
  - Roles y permisos: la regla se cambió a "Bloquear" (guardada en la base; el Administrador, con el permiso, ve "tienes el permiso para autorizarlo") y se dejó otra vez en **"Solo avisar"**. La fila del permiso: Administrador y Gerencia sí, Ventas no.
- **No probado en el navegador:** el portal (tu `.env` local tiene ahora `MAIL_MAILER=smtp`: el código de Rosa sale por correo y no se puede leer del log), el botón deshabilitado para alguien sin el permiso (no hay cuenta de prueba de Gerencia con contraseña ni otro aprobador), el aviso del comprobante en borrador y la línea del panel (ningún cliente de prueba pasa hoy su límite con lo que ya debe). Todo eso está cubierto por las pruebas automáticas. Capturas no hubo (ventana minimizada): se comprobó por el contenido de la página.

## 7. Pendiente

- En tu base hay clientes de prueba con límite **0.00** escrito a mano: con esta regla son "sin crédito" y cualquier cotización suya pedirá motivo. Si eran "sin límite", hay que dejar el campo vacío.
- "Comercial nose" tiene **0 días**: sus comprobantes nuevos vencerán el mismo día (antes, 30).
- **Tenant B - Prueba** y **Yadi SAC** (VPS) no tienen ningún rol creado: nadie recibió el permiso. Se reparte desde Roles cuando creen sus roles.

## 8. Despliegue (28/10)

Desplegado `550021b` con `deploy.sh --si` (respaldo `/root/backups/erp/20261028_072349_antes_deploy_16d8129`). Migración `2026_10_27_000100` corrida; el permiso quedó en Administrador y Gerencia de cada empresa que los tiene. `/login` 200, API 401 como se espera; el cron de los demás proyectos sin cambios.

Ese día se revisó también la **primera corrida del cron** (nota del 27/10 sobre cotizaciones vencidas): a las 05:00 UTC `recurring:run-cycles` completó el ciclo 2026-10 de EMP-52F1DB y dejó su evento por facturar de S/ 153.40 (130 + IGV); la instancia pasó al 24/11. `laravel.log` sin errores nuevos.
