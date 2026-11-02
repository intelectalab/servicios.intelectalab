# Notificaciones (Administración)

Fecha: 2026-11-01.

## 1. Qué se pidió

La segunda de las cuatro pantallas de Administración que faltaban. Decisiones:

- **Correos al cliente en 4 eventos:** cotización enviada, comprobante emitido, respuesta a su ticket y ticket resuelto.
- **Apertura rastreada** con una imagen invisible en cada correo, para el indicador del prototipo "Entregados o leídos".

## 2. Qué se cambió

**Pantalla** (`/messaging`; `components/dashboard/admin/MessagingView.tsx`), con la forma del prototipo:

- **Indicadores:** Mensajes enviados, **Abiertos** (con el porcentaje de los enviados), Fallidos (y cuántos hay en cola) y Plantillas activas.
- **Plantillas:** una por evento: los 4 del cliente y los 19 avisos internos (cotización por aprobar, OT asignada, ticket nuevo…). Columnas Plantilla, Canal, Destinatario (Cliente o Equipo interno), Aprobación Meta (— en correo) y Activa, con exportar al pie. Al abrir una:
  - se editan el asunto y el mensaje, con sus variables (`{cliente}`, `{numero}`, `{total}`…) y una vista previa con datos de ejemplo;
  - se activa o desactiva el envío;
  - "Texto del sistema" vuelve al texto original.
  - Si otra persona cambió la plantilla mientras tanto, no se pisa su cambio (409).
- **Bitácora:** cada correo con su fecha, destinatario, asunto y evento, estado (En cola, Enviado, Fallido, Omitido), motivo y fecha de apertura. Tiene buscador, filtros (destinatario, estado, por columna), orden, paginación y exportar. El clic abre el mensaje completo; un fallido se **reintenta** desde la fila o desde el detalle.
- **Canales:**
  - **Correo transaccional** encendido o apagado: apagado, ningún correo sale y queda Omitido con su motivo.
  - **Nombre del remitente:** sale desde la dirección de la plataforma, con el nombre de la empresa.
  - **Correo para respuestas:** a donde llega lo que el cliente responda.
  - **WhatsApp Business:** se muestra con "Proveedor por definir", sin simular nada.

**Envío** (`App\Services\MessageCenter`): todo correo por un evento de la empresa pasa por aquí, tanto los avisos internos (antes los mandaba Notifier sin dejar registro) como los del cliente.
- Usa la plantilla de la empresa o el texto del sistema.
- El mensaje se guarda con la operación y sale después de responder: Enviado, o Fallido con el error.
- **Correos al cliente:** van a sus usuarios activos del portal (con el enlace a la página del portal, si la empresa tiene el Portal) o, si no tiene, a su correo principal de Clientes y actores. Sin correo, queda Omitido ("El cliente no tiene correo registrado"). Salen con la marca de la empresa. La nota interna de un ticket nunca sale.
- **Apertura:** imagen de 1×1 en `/api/m/{token}.gif`, pública y sin sesión; solo marca la primera apertura. Es un dato aproximado: hay programas de correo que no cargan imágenes y otros que las abren solos.

**Permisos** (matriz del prototipo): `messaging.view`, `messaging.manage` (editar plantillas y canales, reintentar) y `messaging.export`. El Administrador tiene todo; Gerencia y Finanzas ven y exportan.

## 3. Qué NO se tocó

- La campana y las preferencias de Mi perfil siguen igual: la persona decide si recibe el aviso en la aplicación, por correo o de las dos formas.
- No se crean eventos nuevos desde la pantalla ("Nueva plantilla" del prototipo): cada plantilla corresponde a un evento que el sistema ya avisa.
- WhatsApp: sin proveedor, nada se envía.

## 4. Diferencias con el prototipo

- "Entregados o leídos" pasa a **"Abiertos"**: en correo no hay acuse de entrega, y la apertura se mide con la imagen.
- No se trae "Simular aprobación" de WhatsApp, ni el recuadro "Cómo se envía" (texto de la demo). En su lugar hay un aviso real sobre lo aproximado de la apertura.
- Mejoras: remitente y correo para respuestas por empresa, motivo de cada Omitido y Fallido, vista previa de la plantilla, reintentar desde el detalle.

## 5. Base de datos

Migración `2026_11_01_000800_messaging` (como `erp_user`):

- `message_templates`, por empresa, con `lock_version`.
- `outgoing_messages`, por empresa. Lo enviado no se reescribe (lo impide un trigger): solo cambian el estado, el error, los intentos y las fechas. La apertura no cambia una vez marcada. No se borra.
- `message_opened(token)`: la usa la imagen de apertura; solo toca la fecha de apertura.
- Permisos `messaging.*`.
- Los canales se guardan en `tenants.configuration.messaging`.

**Ojo al desplegar:** la imagen de apertura usa `APP_URL` de la API. En el VPS debe ser la dirección pública de la API; si no, las aperturas no se registran (el correo sale igual).

## 6. Cómo se probó

- `MessagingTest` (4 pruebas nuevas):
  - la respuesta pública le llega al cliente con la plantilla de la empresa, y la nota interna no;
  - una variable desconocida no se guarda;
  - la imagen marca la apertura una sola vez, sin sesión;
  - plantilla desactivada o correo apagado: Omitido;
  - el nombre del remitente no admite saltos de línea;
  - cliente sin correo: Omitido con su motivo;
  - servidor de correo caído: Fallido, y "Reintentar" lo envía;
  - lo enviado no se puede reescribir;
  - quien solo ve no edita, y sin permiso no se abre la pantalla.
- Las pruebas de avisos, cotizaciones, comprobantes y tickets de antes pasan igual.
- En el sistema local, como Administrador:
  - respondí el ticket de prueba **TKT-2026-0009** (Minera Prueba CRM): el correo le llegó a la usuaria del portal, Rosa;
  - simulé la apertura con la imagen;
  - revisé los indicadores (1 enviado, 1 abierto), la bitácora, el detalle del mensaje, la vista previa de una plantilla y la pestaña Canales;
  - en 375 px no hay desplazamiento horizontal.

## 7. Pendiente

- WhatsApp cuando se elija el proveedor (con la aprobación de plantillas de Meta).
- Avisos por fecha (vencimientos) con una tarea programada.
- Más eventos al cliente si se piden (cotización por vencer, recordatorio de cobranza).
