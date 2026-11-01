# Integraciones (Control)

Fecha: 2026-11-01.

## 1. Qué se pidió

Construir **Integraciones** con la parte que funciona sin depender de un proveedor:

- el estado real de las conexiones;
- **webhooks salientes** firmados, con cola de envíos, reintentos y fallidos para reprocesar.

Decisiones tomadas:

- Sin el simulador de webhooks entrantes.
- Solo direcciones https públicas, con la clave guardada en el Credential Vault.
- Integraciones es un **módulo aparte** del catálogo, incluido en Enterprise.
- Permisos solo para el Administrador.
- Los envíos salen con el cron que ya existe.

## 2. Qué se cambió

**Pantalla** (`/integrations`, menú Control → Integraciones; `components/dashboard/integrations/`), con 4 pestañas:

- **Conexiones**, con **el formato de tarjeta del prototipo** (nombre y estado, proveedor, Eventos, Credencial y Últimas 24 h), pero con datos reales:
  - **Emisión electrónica SUNAT:** proveedor y ambiente (o "OSE/PSE por configurar"), eventos `invoice.issued` y `credit_note.issued`, y en las últimas 24 h los comprobantes enviados y los rechazados. Tiene enlace a Facturación electrónica.
  - **Email transaccional:** servidor de la plataforma, `notification.email` y los avisos por correo de las últimas 24 h.
  - **Pasarela de pagos y WhatsApp Business:** "Proveedor por definir", sin datos.
  - **Cada webhook es una conexión más** ("Webhook · Contabilidad externa", como en el prototipo): sus eventos, "Clave de webhook (Vault)", los intentos y errores de las últimas 24 h, y los botones Configurar y Ver envíos.
  - Sin los botones de simulación del prototipo ("Simular caída", "Deshabilitar" de mentira).
- **Webhooks salientes:** una tarjeta por webhook con su nombre, dirección, descripción, eventos y lo entregado, en cola y fallido.
  - Botones: "Enviar prueba" (envía en el momento y dice qué respondió el otro sistema), Editar, Rotar clave, y Activar o Desactivar.
  - "Nuevo webhook": nombre, dirección https, descripción y los 12 eventos de Workflows, a elegir.
  - **La clave de firma se muestra una sola vez** (al crear o rotar), con "Copiar" y la explicación para comprobar la firma.
- **Cola de envíos:** fecha, webhook, evento, registro, estado (Pendiente, Reintentando, Entregado o Fallido), intentos (n/5) y resultado o próximo intento. Tiene filtros, orden y exportación (`integrations.export`).
- **Fallidos:** los que agotaron sus 5 intentos.
- **Clic en un envío:** su detalle, con cada intento (código HTTP, tiempo, error o respuesta), el cuerpo exacto que se envía y "Reprocesar ahora".

**Cómo funciona el envío** (`App\Services\Webhooks`, tarea `webhooks:deliver` cada minuto, sin solaparse):

- **Se arma desde la auditoría**, igual que Workflows: cuando se registra una de las 12 acciones, después de guardar la operación se crea el evento (`outbox_events`, uno por registro de auditoría) y un envío por cada webhook activo suscrito. **Nunca se llama a otro sistema dentro de una transacción.** Si algo falla, la operación no se pierde.
- **Cuerpo:** JSON con `id`, `event`, `event_label`, `occurred_at` y `data` (id, texto, enlace a la ficha, cliente y los campos del evento: monto, prioridad…).
- **Firma:** cabeceras `X-ERP-Event`, `X-ERP-Delivery`, `X-ERP-Timestamp` y `X-ERP-Signature: sha256=HMAC-SHA256("{timestamp}.{cuerpo}", clave)`.
- **Reintentos:** a los 1, 5, 30 y 120 minutos; al quinto fallo pasa a **Fallido**. "Reprocesar" lo devuelve a la cola con sus intentos de nuevo. Un webhook desactivado deja sus envíos esperando, y salen al activarlo.
- **Protección de la dirección (contra que usen el ERP para entrar al servidor):**
  - Solo https al puerto 443, sin usuario ni clave en la dirección y nunca nombres internos (localhost, .local, .internal…).
  - **Se resuelve el nombre y se comprueba que todas sus IP sean públicas** (ni privadas, ni del propio equipo, ni 169.254 de los proveedores de nube, ni 100.64/10).
  - **La llamada va a esa misma IP comprobada**, así que si el nombre cambia de IP entre la comprobación y el envío, igual no llega a una red interna.
  - No se siguen redirecciones. Se espera 3 s para conectar y 8 s en total.
  - Se comprueba al guardar y otra vez en cada envío.
- **La clave vive en el Credential Vault** como tipo nuevo "Clave de webhook" (no se puede elegir a mano). Al rotarla queda una versión nueva, y la anterior sigue en el historial del Vault.

**API** (`IntegrationController`):

- `GET /v1/integrations`
- `POST /v1/integrations/webhooks`, `PUT …/{id}`, `POST …/{id}/toggle`, `…/rotate` y `…/test` (este último con un límite de 10 por minuto)
- `GET /v1/integration-deliveries`, `GET …/{id}` y `POST …/{id}/retry`
- Todo queda en Auditoría (`integration.*`).

**Base de datos** (migración `2026_11_01_000500_integrations`, sobre las tablas que ya existían):

- **Módulo `INTEGRACIONES`** en el catálogo (Control), agregado al Plan Enterprise y encendido en las empresas con Enterprise vigente. En la PC son tenant-a y EMP-49DE7C.
- `credential_records`: tipo nuevo `WEBHOOK`.
- `webhook_endpoints`:
  - descripción, quién lo creó y fecha de cambio;
  - una restricción en la base: solo `https://`.
- `outbox_events`: `event_key`, único por empresa.
- `webhook_deliveries`:
  - tipo de evento, texto del registro y fecha de cambio;
  - estados PENDING, RETRY, SENT o DEAD;
  - un solo envío por webhook y evento;
  - índices para la cola.
- `integration_attempts`: el envío al que pertenece y la duración.
- **Candados:** la aplicación no borra webhooks (se desactivan), ni eventos, ni envíos, y no cambia ni borra intentos.
- Permisos: `integrations.export` (nuevo) y nombres de `integrations.view` e `integrations.manage`. Los tres para el Administrador.

**Otros:** el Vault muestra el tipo "Clave de webhook" y lo incluye en su filtro. Se tiene en cuenta en la Auditoría, en el registro de exportaciones y en el menú (módulo con candado si el plan no lo incluye).

## 3. Qué NO se tocó

- El simulador de webhooks entrantes y los botones "Simular caída" o "Enviar con firma inválida" del prototipo.
- La conexión real con SUNAT, pagos y WhatsApp, que esperan a que se elija un proveedor.
- Workflows y los avisos fijos.

## 4. Diferencias con el prototipo

- No hay simuladores. "Conexiones" muestra el estado real, no conexiones de ejemplo.
- La cola y los fallidos son de los webhooks reales.
- En el prototipo Integraciones era un módulo aparte (INTEGRATIONS); aquí también lo es (`INTEGRACIONES`).
- Mejoras: enviar prueba, rotar la clave, detalle de cada intento, filtros y exportación.

## 5. Base de datos

Migración aditiva, salvo que cambia la restricción de tipos del Vault para sumar `WEBHOOK`. Toca el catálogo de módulos y el Plan Enterprise (le agrega el módulo, no cambia precios ni límites).

`schema_reference.sql` se regeneró y se restaura sin errores. Se dejó el Plan Enterprise **sin** los límites de prueba que tiene tu base local (esos solo son para ver la pantalla Módulos y plan).

## 6. Cómo se probó

- **Pruebas nuevas en `IntegrationsTest`** (5 pruebas, 85 comprobaciones). Simulan el otro sistema y la resolución de nombres, sin salir a internet:
  - **Dirección:** rechaza http, localhost, una IP privada, el puerto 8080, un nombre que apunta a la red interna y un nombre inexistente.
  - **Clave:** cifrada en el Vault (tipo WEBHOOK), nunca en la tabla del webhook; al rotarla queda una versión nueva.
  - **Envío:** un ticket deja un envío pendiente sin llamar a nadie durante la operación; al enviarlo, la firma HMAC es correcta, igual que las cabeceras y el cuerpo. El mismo evento otra vez no duplica.
  - **Fallos:** con 503 reintenta con espera y a los 5 intentos queda Fallido; Reprocesar lo devuelve a la cola, y luego entrega (6 intentos registrados).
  - **Cambio de IP:** si el nombre pasa a una IP interna justo antes del envío, no se llama.
  - **Filtros de envío:** solo webhooks activos y suscritos, solo con el módulo encendido; "Enviar prueba" responde en el momento.
  - **Permisos:** conexiones visibles, Gerencia recibe 403 y otra empresa 404.
  - **Candados de la base.**
- **API completa: 325 pruebas pasan.** `tsc` y ESLint sin errores.
- **Navegador como Administrador** (1366 × 768 y 375 px):
  - Conexiones con el estado real (SUNAT sin configurar, correo activo, pagos y WhatsApp por definir).
  - Un webhook nuevo hacia `https://example.com/erp-webhook` (dominio reservado para ejemplos): **no se pulsó "Enviar prueba" ni salió nada a internet**. La clave se mostró una vez y aparece en el Vault como "Clave de webhook".
  - Un ticket de prueba (TKT-2026-0014) dejó su envío en la cola ("Sale en el próximo minuto"), con el cuerpo exacto en el detalle.
  - `https://localhost/…` y `https://192.168.1.10/…` se rechazan con su motivo.
- **Datos de prueba que quedan en tenant-a:** el webhook "Contabilidad externa (prueba)", **desactivado** (su envío pendiente espera y no saldrá mientras siga así), su clave en el Vault y el ticket TKT-2026-0014.

## 7. Pendiente

- Al desplegar, el cron que ya existe ejecuta `webhooks:deliver` cada minuto: no hay que configurar nada.
- **Conexiones reales:** cuando se elija un proveedor de facturación electrónica, pagos o WhatsApp.
- Webhooks entrantes: se harán con la pasarela de pagos real.
