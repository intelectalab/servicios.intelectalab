# 2026-10-13 — Finanzas: comprobantes listos para conectar el proveedor de SUNAT

## Qué se pidió

"Para comprobantes estás tomando en cuenta el tipo de cambio, ¿verdad? Y deja listo para conectarlo al proveedor SUNAT, luego pediré."

También preguntaste por qué en Cuentas por cobrar la columna Detracción / retención solo muestra "—" (respuesta al final, en "Pendiente").

## Tipo de cambio en los comprobantes (ya estaba; no cambió)

- Un comprobante en dólares **no se emite sin tipo de cambio registrado**. Al emitir guarda el de **venta vigente ese día** (Monedas y tipo de cambio) y ya no cambia.
- La ficha lo muestra con su equivalente en soles; el PDF lo imprime; y ahora viaja en lo que recibe el proveedor.
- El cobro en dólares guarda el tipo de cambio **de su fecha**, y cada aplicación guarda la **diferencia de cambio** contra el de la emisión (no hay asiento contable: no existe Contabilidad).
- La antigüedad de Cuentas por cobrar lleva los saldos en dólares a soles con el tipo de cambio vigente.

## Qué se cambió

El sistema no habla con SUNAT directamente: habla con un **proveedor** (OSE/PSE) a través de una sola pieza. Hoy no hay ninguno, así que nada cambia para quien usa el sistema: todo sigue "Sin enviar".

### API (`apps/api/app/Modules/Finance/Fiscal/`)

- **`FiscalProvider`** — el contrato que debe cumplir la clase de cualquier proveedor: su nombre y `send(comprobante, datos fiscales de la empresa)`.
- **`FiscalDocument`** — arma el comprobante como lo pide SUNAT, igual para cualquier proveedor: tipo (01 factura, 03 boleta, 07 nota de crédito), serie y número, fechas, moneda y **tipo de cambio**, RUC y razón social de tu empresa, tipo y número de documento del cliente, líneas, totales y, en una nota de crédito, qué comprobante corrige y por qué (con los importes en positivo).
- **`FiscalResult`** — lo que responde el proveedor: aceptado, rechazado o recibido y pendiente, con su mensaje, código, resumen del XML y constancia (CDR).
- **`FiscalGateway`** — el único punto de salida. Elige el proveedor de la empresa, envía **después de que la emisión quedó guardada** (nunca dentro de la operación: si el proveedor falla, el comprobante no se pierde) y anota la respuesta en el comprobante, en tu tabla `fiscal_documents` y en auditoría.
- `config/fiscal.php` — lista de proveedores disponibles (vacía).
- `InvoiceController`: emitir y la nota de crédito pasan por el gateway; "reintentar" ahora también envía lo emitido antes de tener proveedor y lo que quedó "En envío" sin respuesta. La ficha entrega si hay proveedor y su nombre.
- `AuditCatalog`: textos de aceptado, rechazado y recibido.
- Pruebas: dos nuevas en `FinanceTest` con un proveedor de mentira (acepta, rechaza o no responde).

### Web

- Ficha del comprobante: la fila **Proveedor** muestra su nombre cuando lo haya; aparece **Enviar a SUNAT** (emitido sin enviar) o **Reintentar envío SUNAT** (rechazado o sin respuesta), con permiso de emitir; el aviso "sin enviar a SUNAT" al emitir solo sale si no hay proveedor; el PDF deja de decir "Documento interno" cuando SUNAT lo aceptó.

## Cómo se comporta cuando haya proveedor

1. Emitir → el comprobante queda **En envío** y sale al proveedor al terminar de guardarse.
2. Responde **aceptado** → "Aceptado", con su constancia guardada.
3. Responde **rechazado** → "Rechazado" con el motivo de SUNAT; sigue emitido y se reintenta desde la ficha.
4. **No responde** (caída, tiempo de espera) → "Rechazado" con "No se pudo comunicar con el proveedor"; se reintenta. El detalle técnico va al registro de errores, no a la pantalla.
5. Uno aceptado no se reenvía.

## Qué faltará hacer el día que elijas proveedor

- Escribir **una clase** para ese proveedor (traduce `FiscalDocument` a su formato y su respuesta a `FiscalResult`) y registrarla en `config/fiscal.php`.
- Guardar sus credenciales (no van en `fiscal_configurations`: deben ir a la bóveda o a variables del servidor).
- Poner su código en la empresa (`fiscal_configurations.provider_code`); eso será parte de la pantalla Facturación electrónica.
- XML y CDR descargables, contingencia y comunicación de baja: dependen de lo que ofrezca el proveedor.

## Qué NO se tocó

Tablas (no hubo migración: se usan tus `fiscal_configurations`, `fiscal_series` y `fiscal_documents` tal como estaban), listados, cuentas por cobrar y cobros.

## Diferencias con el prototipo

El prototipo simula que SUNAT acepta a los pocos segundos. Aquí no se simula nada: sin proveedor, "Sin enviar".

## Base de datos

Sin cambios.

## Cómo se probó

- `php artisan test` → **174 pruebas pasan** (2 nuevas). Con el proveedor de mentira: lo que recibe al emitir (códigos, RUC, totales, tipo de cambio en dólares), aceptado con su registro en `fiscal_documents`, rechazado y reintento, proveedor que no responde (el comprobante queda emitido con su número y su cuenta por cobrar), nota de crédito en positivo con lo que corrige, empresa sin proveedor (nada sale; conectándolo después se envía lo ya emitido) y permiso.
- `tsc` y `eslint` sin errores.
- Navegador, cuenta Finanzas: la ficha de `F001-00000001` carga igual que antes ("Sin proveedor conectado", sin botón de envío).

**No se pudo probar:** un proveedor real (no hay ninguno elegido ni credenciales) ni, en el navegador, los botones "Enviar a SUNAT" / "Reintentar" (solo aparecen con proveedor; los cubre la prueba automática de la API, no la pantalla).

## Decisiones tomadas en esta tanda

1. **El envío ocurre en la misma petición, justo después de guardar**, no en una cola. Tu `.env` tiene la cola en Redis pero no hay un proceso atendiéndola; con una cola el comprobante se quedaría "En envío" para siempre. Cuando haya ese proceso, se mueve sin cambiar nada más.
2. **Un fallo de comunicación se muestra como "Rechazado"** (con su motivo) para reutilizar el botón de reintento; no añadí un estado nuevo.
3. **El proveedor es por empresa**, no uno para todo el sistema: cada tenant tendrá su RUC, su certificado y quizá su propio proveedor.
4. No adopté la bandeja de eventos (outbox) del kit.

## Pendiente

- **Detracción / retención** (por qué hoy sale "—"): no la calculé porque hacerlo bien depende de datos que hoy el sistema no tiene dónde guardar: si tu empresa aplica detracción y con qué código y porcentaje (no es el mismo para todos los servicios), su cuenta del Banco de la Nación, y qué clientes son agentes de retención. Con un porcentaje supuesto, las cuentas por cobrar mostrarían un "neto a cobrar" que podría ser falso. Va con la pantalla **Facturación electrónica**, donde se configura.
- Elegir proveedor y escribir su clase; pantalla Facturación electrónica; consultar el estado de un comprobante que el proveedor dejó pendiente.
