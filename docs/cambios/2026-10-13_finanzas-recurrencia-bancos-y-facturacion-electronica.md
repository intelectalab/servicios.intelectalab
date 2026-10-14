# 2026-10-13 — Finanzas: Recurrencia, Bancos y caja y Facturación electrónica (con detracciones y retenciones)

## Qué se pidió

"Ahora los demás módulos de finanzas que falten."

Eran tres pantallas del menú: **Recurrencia**, **Bancos y caja** y **Facturación electrónica**. Con ellas el grupo Finanzas queda completo.

Referencias: prototipo (`fuente/js/ui/views/finance.js`: `recurring`, `recurringDetail`; `treasury.js`: `banks`; `fiscal.js`: `fiscal`; `services/support.js`, `services/finance2.js`, `core/fin.js`, `core/rbac.js`) y kit (`08-finanzas-cxc.md`, `10-tesoreria.md`, reglas RN-FIN-04 a 08, 14, 15 y RN-TRE-01 a 04).

No se te consultó antes de empezar. Lo decidido sin preguntarte está en "Decisiones tomadas en esta tanda".

## A la vista pero sin función o sin dato (léelo primero)

| Dónde | Qué | Por qué |
| --- | --- | --- |
| Facturación electrónica → Proveedor | "Formato" y "Certificado digital" dicen "Lo define / lo gestiona el proveedor" | No hay proveedor elegido; esos datos son suyos. |
| Facturación electrónica → Editar configuración | El desplegable "Proveedor OSE/PSE" sale deshabilitado | La lista de proveedores disponibles (`config/fiscal.php`) está vacía. |
| Facturación electrónica → Bajas | La pestaña sale vacía y el botón "Comunicación de baja" de un comprobante no aparece | Una baja solo procede sobre un comprobante **aceptado por SUNAT**, y sin proveedor ninguno llega ahí. La función está hecha y probada con un proveedor de mentira. |
| Comprobante → XML / CDR | Sigue avisando que no está disponible | Depende del proveedor. |
| Recurrencia → ficha | "Renovación automática: No (se alerta 45 días antes)" es un texto fijo | No hay renovación automática; solo el aviso. |
| Bancos y caja → Caja chica | "Centro de costo" y "Cuenta" son texto libre y opcionales | En el prototipo salen de Contabilidad, que no existe. |
| Bancos y caja → cuenta | "Cuenta contable" es un dato opcional que solo se muestra | Igual: no hay Contabilidad ni asientos. |

Lo que antes estaba vacío y **ya tiene dato**: la columna **Detracción / retención** de Cuentas por cobrar y la **Cuenta de destino** del formulario Registrar pago.

## Cómo funciona

### Recurrencia (`/recurring` y `/recurring/{id}`)

- Una **instancia recurrente** nace sola al activar un servicio contratado recurrente (ya pasaba). Ahora guarda **lo pactado**: precio unitario sin IGV, cantidad, moneda del contrato, vigencia, cliente, responsable y sede. Cambiar el catálogo no cambia sus ciclos.
- Listado con las columnas del prototipo: Servicio (con su cliente), Frecuencia, Precio snapshot (con IGV, por ciclo), Próximo ciclo (con "Vencido"), Fin de vigencia (con "Por renovar"), Ciclos y Estado. Buscar, filtro por Estado, ordenar, filtrar por columna y exportar, todo en el servidor.
- Arriba, el aviso "N servicios por renovar en 45 días" con enlace a cada uno.
- **Ejecutar job de ciclos (N vencidos):** procesa los ciclos vencidos de las instancias activas que la persona alcanza. Cada ciclo deja **un evento facturable** ("Soporte mensual · periodo 2026-10") en Por facturar. Una instancia atrasada se pone al día (hasta 12 periodos por pasada). Al terminar muestra el resultado: procesados, omitidos por ya procesados, omitidos por estado y fallidos.
- **Un periodo no se procesa dos veces** (clave empresa + instancia + periodo). Si falla (cliente sin datos fiscales, sin precio pactado) queda "Fallido" con su motivo y se reintenta sobre la misma fila.
- Si el siguiente ciclo cae fuera de la vigencia, la instancia pasa a **Vencida**.
- **Ficha** (página): Instancia y Estados en dos tarjetas movibles, y debajo la tabla Ciclos (Periodo, Clave de idempotencia, Monto, Evento facturable, Intentos, Estado).
  - **Procesar ciclo de esta instancia:** procesa el ciclo que toca, uno por vez y hasta con 40 días de anticipación (avisa si se factura por adelantado).
  - **Renovar:** meses y, si se quiere, precio nuevo. Extiende la vigencia; el tramo anterior (fechas y precio) queda en "Vigencias anteriores". El precio nuevo vale desde el próximo ciclo; los ciclos ya procesados no cambian. Una vencida vuelve a Activa.
  - **Suspender** (con motivo), **Reactivar** y **Cancelar** (con motivo).
- Además del botón, el sistema trae la orden `php artisan recurring:run-cycles`, programada todos los días a las 05:00 para todas las empresas. **Solo corre si el servidor tiene activo el programador de Laravel** (`schedule:run`); en tu máquina no lo está, así que aquí se usa el botón.

### Bancos y caja (`/banks`)

Cuatro indicadores (Disponible total en soles, Movimientos por conciliar, Extractos importados, Caja chica) y cuatro pestañas, como el prototipo.

- **Cuentas:** una tarjeta por cuenta (banco, caja o la cuenta de detracciones del Banco de la Nación) con su saldo, número, CCI, fondo fijo, custodio y cuántos movimientos tiene por conciliar. **Nueva cuenta** y **Editar** (no están en el prototipo: sin ellas una empresa no podría registrar sus cuentas). El tipo y la moneda no se cambian después; una cuenta con saldo no se desactiva.
- **El saldo de una cuenta es la suma de sus movimientos.** No existe un campo de saldo que se pueda editar.
- **Movimientos** de la cuenta elegida: Fecha, Descripción (con n.º de operación), Origen, Ingreso, Egreso, Saldo (corrido) y Conciliado. Buscar, filtrar por Origen y Conciliación, ordenar, filtrar por columna y exportar.
- **Qué genera movimientos:**
  - Registrar un **cobro** → ingreso en su cuenta de destino.
  - **Reembolsar** un cobro → egreso de esa misma cuenta.
  - **Transferir entre cuentas** → un egreso y un ingreso con la misma referencia.
  - **Gasto de caja chica** → egreso de la caja.
  - **Constancia de detracción** → ingreso en la cuenta de detracciones.
  - **Comisión** o **abono por identificar** registrados desde el extracto.
- **Transferir entre cuentas:** misma moneda y saldo suficiente; la cuenta de detracciones no participa.
- **Conciliación:** **Importar extracto** (archivo CSV o pegado; columnas `fecha;descripcion;monto;operacion`). El sistema empareja solo lo que coincide en importe exacto y fecha (±3 días), dando prioridad al número de operación. **Lo ya importado en esa cuenta no entra otra vez.** Lo que quedó sin emparejar se resuelve a mano: **Emparejar con…** un movimiento del mismo importe, **Registrar comisión** (cargos), **Abono por identificar** (abonos) o **Ignorar**. Si el movimiento conciliado es de un cobro, el cobro queda "Conciliado". "Descargar plantilla" entrega un CSV de ejemplo.
- **Caja chica:** custodio, fondo fijo, saldo, por reponer y tope por comprobante. **Registrar gasto** (con tope por comprobante, S/ 350 si la caja no define otro, y sin dejar la caja en negativo), **Reponer fondo** (transfiere desde un banco lo que le falta) y **Arqueo de caja** (compara lo contado con el saldo; no mueve el saldo, deja constancia de la diferencia). Tablas Arqueos y Gastos de caja chica (esta, con paginación y exportar).
- **En Cobros → Registrar pago**, "Cuenta de destino" ya lista tus cuentas. Si no eliges y solo hay una posible (la caja para efectivo, el banco de esa moneda para lo demás), va a esa; **si hay varias, hay que elegir**. Sin cuentas registradas, el cobro queda sin cuenta, como antes.

### Facturación electrónica (`/fiscal`)

Cuatro indicadores (Aceptados, En envío, Rechazados, En contingencia) y tres pestañas, como el prototipo.

- **Configuración**
  - **Proveedor de emisión electrónica:** proveedor, conexión, RUC del emisor, ambiente. **Editar configuración** guarda proveedor (de los disponibles), RUC del emisor, ambiente, cuenta de detracciones (formato `00-000-000000`), código de detracción por defecto y si se aplica detracción en ventas.
  - **Series:** por cada comprobante, su serie normal, su serie de contingencia y el último correlativo. **Editar series** cambia las series y permite **adelantar el próximo correlativo** (para una empresa que viene de otro sistema). Un correlativo nunca retrocede; cada serie debe ser distinta.
  - **Detracciones (SPOT)** y **Pendientes de clientes** (constancias y certificados que aún no entregan, con enlace a cada comprobante).
- **Detracción y retención en el comprobante**
  - Aplican a **facturas por más de S/ 700**. Con la detracción activada, la factura lleva el código por defecto (037, 12 %). Si no hay detracción y el cliente es **agente de retención**, se le calcula la retención del 3 %.
  - En el **borrador** de una factura hay un control "Detracción / retención": Automática, un código concreto (con su porcentaje), Retención 3 % o **No aplica**. La ficha muestra lo que le corresponderá y el **neto a cobrar al cliente**.
  - Al **emitir** queda fijada (importe siempre en soles; en dólares, con el tipo de cambio de la emisión) y **ya no se modifica**.
  - La ficha muestra la tarjeta "Detracción (SPOT)" o "Retención del IGV". **Registrar constancia de depósito / certificado de retención** (número y fecha) baja ese importe de la cuenta por cobrar y, si es detracción, lo ingresa a la cuenta de detracciones de Bancos y caja.
  - Se ve también en el listado de Comprobantes (bajo el total), en **Cuentas por cobrar** (columna con su estado) y en el PDF (con la leyenda del SPOT y la cuenta del Banco de la Nación).
  - **Agente de retención** es una casilla nueva en las condiciones comerciales del cliente (Clientes y actores → Editar), y se muestra en su ficha.
- **Contingencia:** **Activar contingencia** pide motivo. Mientras dure, facturas y boletas salen con su **serie de contingencia** y quedan en estado "Contingencia"; nada se envía. **Restablecer** vuelve a la serie normal y envía lo pendiente por el proveedor. **Sin proveedor conectado, lo emitido en contingencia sigue "pendiente de informar"**: no se da por enviado. Bitácora de cada cambio de modo y tabla de emitidos en contingencia.
- **Bajas:** la comunicación de baja se pide desde el comprobante (aceptado por SUNAT, hasta 7 días desde la emisión, sin cobros y con un proveedor que ofrezca bajas). Sale por el proveedor después de guardarse. Aceptada: el comprobante queda **Anulado** y su cuenta por cobrar, sin saldo. Rechazada o sin comunicación: sigue emitido y se puede volver a pedir.

### Lo que la base garantiza por sí sola

- Un ciclo por instancia y periodo; un evento facturable por ciclo. Instancias, ciclos y vigencias anteriores no se borran.
- El saldo de una cuenta sale de sus movimientos. Un movimiento **no cambia de cuenta, fecha ni importe, ni se borra**; solo se concilia, una vez. Un cobro deja un solo movimiento.
- La misma línea de un extracto no entra dos veces en una cuenta; lo que dijo el banco no se edita; una línea resuelta no se vuelve a resolver.
- La moneda y el tipo de una cuenta no cambian. Gastos de caja y arqueos no se editan ni se borran. Las cuentas no se borran.
- La detracción o retención de un comprobante emitido no se modifica; su constancia se anota **una sola vez**.
- Una sola comunicación de baja en curso o aceptada por comprobante, siempre con motivo. Las series no se borran.

### Permisos y alcance

| Permiso | Qué permite |
| --- | --- |
| `recurring.view` | Ver instancias y ciclos |
| `recurring.create` | Procesar ciclos |
| `recurring.manage` | Renovar, suspender, reactivar y cancelar |
| `recurring.export` | Exportar el listado |
| `treasury.view` | Ver cuentas, movimientos, extractos y caja chica |
| `treasury.create` | Transferir, importar extracto, gasto de caja, reponer fondo y arqueo |
| `treasury.manage` | Crear y editar cuentas; conciliar a mano las líneas del extracto |
| `treasury.export` | Exportar movimientos y gastos de caja chica |
| `fiscal.view` | Ver Facturación electrónica y Monedas y tipo de cambio (**ya existía**) |
| `fiscal.manage` | Registrar el tipo de cambio (**sin cambio**) |
| `fiscal.configure` | **Nuevo.** Configuración fiscal, series y contingencia |
| `billing.manage` | Además: decidir la detracción del borrador |
| `receivables.manage` | Además: registrar la constancia o el certificado |
| `billing.approve` | Además: pedir la comunicación de baja |

Todos cuelgan del módulo **Finanzas** del plan.

**Reparto, comparado con la matriz del prototipo:**

| Rol | Recurrencia | Bancos y caja | Facturación electrónica | Alcance en Recurrencia |
| --- | --- | --- | --- | --- |
| Administrador | Todo | Todo | Ver, Gestionar, Configurar | Toda la empresa |
| Gerencia | Ver, Exportar | Ver, Exportar | Ver | Toda la empresa |
| Ventas | Ver | — | — | Propios |
| Operaciones (prueba) | Ver | — | — | Sede |
| Finanzas (prueba) | Ver, Crear, Gestionar | Todo | Ver, Gestionar, Configurar | Toda la empresa |
| Técnico (prueba) | — | — | — | — |

- Es el reparto del prototipo. **Finanzas no exporta Recurrencia** (el prototipo no se lo da, aunque sí exporta lo demás).
- **De más respecto de un permiso único:** en el prototipo "Facturación electrónica y tipo de cambio" es un solo recurso. Aquí configurar la facturación electrónica se separó en `fiscal.configure`, para que quien solo registra el tipo de cambio no pueda cambiar series ni activar la contingencia. Nadie perdió nada: quien registraba el tipo de cambio lo sigue haciendo.
- **Alcance:** un recurso nuevo, Servicios recurrentes. Una instancia es "propia" de quien lleva su servicio contratado y de quien vendió su contrato; hereda la sede del servicio. Bancos y caja y Facturación electrónica **no tienen alcance**: quien tiene el permiso ve toda la empresa.
- En **Roles y permisos** aparecen "Recurrencia", "Bancos y caja" y "Facturación electrónica y tipo de cambio", con una columna nueva **Configurar**.

## Qué se cambió

### API (`apps/api`)

- **Migraciones** `2026_10_13_000700` (recurrencia), `000800` (bancos y caja) y `000900` (facturación electrónica). Ver "Base de datos".
- **Recurrencia:** modelos `RecurringInstance` (con alcance) y `RecurringCycle`; `Finance/Support/Recurrence.php` (ciclos); `RecurringController`; orden `recurring:run-cycles` (`app/Console/Commands/RunRecurringCycles.php`, programada en `routes/console.php`). `ContractedServiceController` crea la instancia con lo pactado; `ContractController` muestra el importe pactado.
- **Bancos y caja:** modelo `FinancialAccount`; `Finance/Support/Treasury.php` (movimientos) y `StatementCsv.php` (lectura del extracto); `TreasuryController`. `PaymentController`: cuenta de destino, movimiento del cobro, del reembolso y conciliación.
- **Facturación electrónica:** `Finance/Support/FiscalSettings.php` (configuración y series) y `Withholding.php` (detracción y retención); `FiscalController`; `Fiscal/FiscalVoidProvider.php` (contrato de un proveedor que ofrece bajas) y `FiscalGateway` (bajas); `FiscalDocument` (lleva la detracción y si es de contingencia). `InvoiceController`: serie y detracción al emitir, contingencia, modo del borrador, constancia y baja. `ReceivableController`: detracción o retención en cada fila. `Billing`: las series ya no están fijas en el código.
- `CustomerController` y `PartyController`: agente de retención.
- `AccessScope` (recurso `recurring`), `CheckRecordVersion`, `ListExport` (Recurrencia, Movimientos, Gastos de caja chica) y `AuditCatalog` (textos de cada acción nueva).
- **Pruebas:** `FinanceRecurringTest` (11), `FinanceTreasuryTest` (8), `FinanceFiscalTest` (6). Se ajustaron `AccessScopeTest` y `OperationsFixtures` (lista de recursos con alcance).
- `LocalFinanceSeeder`: permisos nuevos del rol Finanzas de prueba.

### Web (`apps/web`)

- Pantallas en `components/dashboard/finance/`: `RecurringList.tsx`, `RecurringDetail.tsx`, `Banks.tsx`, `BankModals.tsx`, `Fiscal.tsx`. Rutas `app/(dashboard)/recurring`, `recurring/[id]`, `banks`, `fiscal`; añadidas a `proxy.ts` y al menú (dejan de estar "próximamente").
- `InvoiceDetail.tsx` (detracción, control del borrador, constancia, baja, aviso de contingencia), `InvoicesList.tsx` (detracción bajo el total), `Receivables.tsx` (columna y ficha), `PaymentModals.tsx` (texto de la cuenta de destino).
- `lib/finance.ts` (estados, textos y PDF con detracción) y `types/finance.ts`.
- `ActorForm.tsx` y `ActorDetail.tsx`: casilla y dato "Agente de retención".
- `RolesPermissions.tsx` (nombres de recursos y columna Configurar), `ScopeNote.tsx` (aviso de alcance de Recurrencia), `lib/api.ts` (claves contra envíos repetidos).

## Ajuste posterior: movimientos de la caja en la pestaña Caja chica

Reportaste que en Bancos y caja no había cómo exportar a Excel o CSV los movimientos de una caja. La exportación existía solo en la pestaña **Movimientos** (eligiendo la caja en el selector); en **Caja chica** solo se exportaban los gastos.

- La pestaña Caja chica ahora termina con la tarjeta **Movimientos de la caja**: todo lo que entró y salió de esa caja (apertura, reposiciones, cobros en efectivo, gastos), con saldo corrido, buscar, filtros, ordenar y **Exportar a Excel / CSV** (permiso `treasury.export`). Con varias cajas, sigue a la caja elegida arriba. No está en el prototipo.
- De paso: en esa pestaña y en Conciliación aparecía una franja vacía bajo la página (la etiqueta oculta de los filtros se colocaba respecto de la página). Se corrigió en el filtro compartido (`FilterSelect` de `proto.tsx`), así que vale para todos los listados.
- Probado en el navegador con Finanzas: la tarjeta lista los tres movimientos de la caja de prueba; el CSV y el Excel se generan con esas filas; sin franja a 1280 px ni a 375 px.

### Segunda corrección (mismo día)

La tabla sin exportar era la de **Conciliación → Movimientos en libros pendientes de conciliar** (lo aclaraste con una captura).

- Esa tabla lleva ahora **Exportar a Excel / CSV** al pie (permiso `treasury.export`, con registro en auditoría). Exporta **todos** los movimientos sin conciliar de la cuenta, no solo los que la tarjeta muestra: Fecha, Descripción, N.º de operación, Origen y Monto.
- Los botones de exportar de Movimientos, Gastos de caja chica y Movimientos de la caja siguen **al pie de su tabla**, como en los demás listados (los había subido a la cabecera sin que lo pidieras; se devolvieron).
- **Cuentas:** cuatro tarjetas por fila en pantalla ancha (tres en mediana, dos en tableta, una en teléfono).
- La tarjeta Movimientos de la caja se queda, como pediste.
- Probado en el navegador a 1366 × 768 con Finanzas: CSV y Excel de los pendientes de BCP generados con sus dos filas; los cuatro juegos de botones al pie de su tabla; las cuatro cuentas en una fila sin desbordes; sin desborde a 375 px.

### Tercera corrección: plantilla del extracto e importación como las demás

Preguntaste si había probado subir la plantilla del extracto y recordaste que las plantillas deben verse bien. **No lo había probado**, y la plantilla era un CSV plano.

- **Importar extracto** usa ahora la misma ventana que Clientes y actores y Catálogo de servicios (`ImportModal`): elegir la cuenta, botón **Plantilla Excel**, arrastrar o buscar el archivo, y al final el resumen (líneas importadas, conciliadas, por revisar) con un aviso por cada fila que no se pudo leer y por las líneas ya importadas.
- **Plantilla en Excel con formato** (`SpreadsheetImport::template`, la pieza de tus otras plantillas): título, instrucciones, cabecera en el azul del logo, tres filas de ejemplo y filas en blanco con borde. Se le dieron anchos propios porque con solo cuatro columnas las instrucciones y la descripción del banco se cortaban (`SpreadsheetImport::template` acepta ahora anchos por columna; las demás plantillas no cambian).
- El extracto se sube como **archivo .xlsx o .csv** (hasta 5 MB), ya no como texto pegado. En Excel, la fecha puede ir como celda de fecha y el monto como número. Se aceptan otros títulos habituales de los bancos (importe, detalle, concepto, n.º de operación…).
- API: `GET /bank-accounts/{cuenta}/statements/template` y `POST /bank-accounts/{cuenta}/statements/import` (reemplazan a `POST /bank-statements`). `Finance/Support/StatementRows.php` reemplaza a `StatementCsv.php`. Se retiró la ventana propia `ImportStatementModal`.
- **Probado:** prueba automática que descarga la plantilla y la sube tal cual (entran sus tres filas de ejemplo) y otra con un Excel con fecha y número nativos; 199 pruebas pasan. En el navegador, con Finanzas: descargué la plantilla desde la tarjeta y desde la ventana, **subí ese mismo archivo sin tocarlo** a BBVA (prueba) y entraron sus líneas (una se omitió por ser igual a un ITF ya importado); luego las dejé como Ignoradas. El aspecto lo comprobé midiendo una vista del Excel (colores, y que ningún texto se corta); **no lo abrí en Excel ni lo vi en captura**.
- Ojo: como en tus otras plantillas, las filas de ejemplo son filas reales. Si se sube la plantilla sin reemplazarlas, entran como líneas del extracto (la primera instrucción lo dice).

### Monedas y tipo de cambio: botón "Actualizar desde SUNAT"

Pediste el botón del prototipo. En el prototipo es simulado; aquí consulta de verdad.

- **Actualizar desde SUNAT** (a la izquierda de Registrar tipo de cambio, como en el prototipo; permiso `fiscal.manage`). Lee el archivo público de SUNAT (`https://www.sunat.gob.pe/a/txt/tipoCambio.txt`, una línea con día|compra|venta) y guarda ese tipo de cambio **con la fecha que SUNAT indica** y fuente "SUNAT".
- Uno por día: si ese día ya había uno (también manual), lo corrige y queda el antes y el después en auditoría. Si ya estaba igual, avisa "ya estaba al día" y no guarda nada.
- **Si SUNAT no responde o responde algo que no se reconoce, no se guarda nada** y se muestra el motivo: nunca se estima un valor. Tampoco se acepta una fecha de más de 7 días ni valores en cero.
- La dirección se puede cambiar con `SUNAT_EXCHANGE_RATE_URL` (`config/fiscal.php`). Sin librerías nuevas.
- API: `POST /v1/exchange-rates/sync`; `Finance/Support/SunatExchangeRate.php`; texto de auditoría `exchange_rate.sync`.
- **Probado:** prueba automática (SUNAT caído, error, formato raro, valores en cero, fecha vieja, guardado, repetición sin duplicar, corrección de uno manual, permiso). En el navegador, con Finanzas, **consulta real a SUNAT**: entró el del 13 oct. 2026 (compra 3.434, venta 3.448); segunda pulsación, "ya estaba al día"; fallo simulado, muestra el motivo y no cambia la tabla; sin desborde a 375 px.
- **Ojo:** el tipo de cambio vigente de tu empresa de prueba pasó de 3.750 (manual, 5 oct.) a 3.448 (SUNAT, 13 oct.). Lo que cotices o emitas en dólares desde ahora usa ese.
- No hay actualización automática diaria: es con el botón.

## Qué NO se tocó

- Tus tablas `recurring_service_*`, `financial_accounts`, `fiscal_configurations` y `fiscal_series` se conservan: solo se les añadieron columnas y reglas. `recurring_service_alerts` y `fiscal_notes` quedan sin uso.
- Por facturar, Monedas y tipo de cambio y el resto de Cobros.
- La configuración de PHP de tu máquina.

## Diferencias con el prototipo

- **Sin la parte de demo:** las notas bajo cada título; "Ejecutar otra vez (prueba idempotencia)" en Recurrencia; "Generar extracto de ejemplo" en Bancos (en su lugar, "Descargar plantilla").
- **Añadido:** exportar y filtros por columna; Nueva cuenta y Editar; Custodio y Tope en la tarjeta de caja; selector de caja si hay más de una; elegir el banco al reponer; confirmar antes de procesar un ciclo; Editar series; el control de detracción del borrador; el aviso de alcance.
- **Recurrencia:** una instancia atrasada se pone al día en una pasada (el prototipo procesa un ciclo por pasada). Suspender, Reactivar y Cancelar son botones en la tarjeta Estados.
- **Bancos:** el prototipo muestra una sola caja fija; aquí puede haber varias. El prototipo elige solo el banco de un cobro sin cuenta; aquí, si hay más de uno, pide elegir.
- **Facturación electrónica:** no se simula nada de SUNAT. El prototipo "acepta" a los segundos, simula el resumen de contingencia y muestra un XML de referencia. "Exentos" no es una lista fija: se decide en cada factura.
- La detracción se redondea a soles enteros (el prototipo usa dos decimales).

## Base de datos

Las tres migraciones están **ya corridas** en tu base local (con el usuario dueño). Solo añaden; no borran datos.

- `2026_10_13_000700_finance_recurring_cycles.php`: columnas en instancias y ciclos, clave única del ciclo, permisos `recurring.create` y `recurring.export`, alcance `recurring`.
- `2026_10_13_000800_finance_banks_and_cash.php`: columnas en `financial_accounts`; tablas `bank_movements`, `bank_statements`, `bank_statement_lines`, `cash_expenses`, `cash_counts` (con su regla de aislamiento por empresa); disparadores de protección; permisos `treasury.*`.
- `2026_10_13_000900_finance_electronic_invoicing.php`: columnas de detracción en `invoices`, `customers.retention_agent`, tabla `fiscal_void_requests`, disparador del comprobante emitido ampliado, permiso `fiscal.configure`.

Para el rol de prueba: `php artisan db:seed --class=LocalFinanceSeeder` (**ya corrido**).

## Cómo se probó

**Automáticas:** `php artisan test` → **199 pruebas pasan** (174 + 25 nuevas). Cubren: instancia con lo pactado; un evento por ciclo y nada duplicado; puesta al día y vencimiento; ciclo fallido y reintento; adelanto de un ciclo; renovación; estados; permisos, exportar, alcance y otra empresa; la orden programada. Cuentas y apertura; cobro, reembolso y conciliación con su movimiento; transferencias y reposición; extracto (emparejamiento, duplicados, a mano, comisión, abono, ignorar); caja chica y arqueo; saldo corrido. Configuración y series; detracción por defecto, por código, "No aplica", en dólares y retención; constancia y su movimiento bancario; contingencia con y sin proveedor; bajas aceptada, rechazada, sin comunicación y fuera de condiciones. En cada módulo se intentó además modificar y borrar directamente en la base lo que no debe cambiar.

**Web:** `tsc` y `eslint` sin errores.

**Navegador** (`app.localhost:3000`):

- **Finanzas — Recurrencia:** listado → ficha → procesar ciclo (un día por adelantado, con su aviso) → resultado y evento en Por facturar → segundo intento bloqueado por los 40 días → suspender sin y con motivo → reactivar → renovar 12 meses con precio nuevo → candado en exportar.
- **Finanzas — Bancos y caja:** pantalla vacía → cuatro cuentas (banco con apertura, segundo banco, caja con fondo y custodio, detracciones) → validación del número → transferencia (saldo insuficiente y correcta) → extracto por archivo (encabezado inválido; una conciliada por n.º de operación) → comisión, abono por identificar e ignorar → segundo extracto y emparejar a mano → gasto sobre el tope y correcto → arqueo con diferencia → reponer fondo → movimientos con saldo corrido → cobro sin elegir cuenta (pide elegir) y con cuenta.
- **Finanzas — Facturación electrónica:** configuración (RUC y cuenta inválidos; guardar) → series repetidas rechazadas → borrador de factura con el control de detracción (código 030, No aplica, Automática) → activar contingencia (el borrador avisa) → restablecer → emitir → tarjeta Detracción → PDF (texto comprobado) → constancia → cuenta por cobrar con su movimiento → columna en Cuentas por cobrar → línea "detr." en Comprobantes → ingreso en la cuenta de detracciones.
- **Ventas:** en Finanzas ve Cuentas por cobrar y Recurrencia ("Alcance: Propios"), con candado en ejecutar y exportar. Entrando por la dirección a Bancos o Facturación electrónica, no cargan. Casilla "Agente de retención" marcada y desmarcada desde Editar.
- **Gerencia:** ve las tres pantallas. Candado en ejecutar ciclos, renovar, suspender y cancelar; en nueva cuenta, transferir, importar, gasto, reponer y arqueo; en editar configuración, series y contingencia. Exporta recurrencia, movimientos y gastos.
- **Operaciones:** ve Recurrencia ("Alcance: Sede") con candado en ejecutar y exportar; no ve Bancos ni Facturación electrónica.
- **Administrador:** las tres pantallas sin candados; Roles y permisos con los recursos nuevos y la columna Configurar. Entré con un código nuevo y cerré solo esa sesión.
- **Oscuro y claro:** contraste medido por código en las tres pantallas, sus pestañas, la ficha del comprobante y Cuentas por cobrar. Lo único bajo 4:1 son los colores que ya usa todo el sistema (blanco sobre el azul de los botones; etiquetas de estado en claro).
- **375 px:** sin desborde en las tres pantallas, sus pestañas, la ficha del comprobante, Cuentas por cobrar y un formulario; las tablas pasan a tarjetas.

### Lo que no se pudo probar así, y por qué

- **Un comprobante emitido en contingencia, en el navegador:** dejaría en tu empresa un comprobante con serie de contingencia "pendiente de informar" que no se puede borrar. Probé activar y restablecer, y el aviso del borrador; la emisión la cubre la prueba automática.
- **Comunicación de baja, en el navegador:** sin proveedor ningún comprobante está aceptado. La cubre la prueba automática con un proveedor de mentira.
- **Retención del 3 % y factura en dólares con detracción, en el navegador:** cubiertas por la prueba automática.
- **Un proveedor real:** no hay ninguno elegido.
- **La orden programada corriendo sola:** el programador no está activo en tu máquina; se probó la orden por sí misma.
- **Capturas:** la ventana del navegador de pruebas se ocultó a mitad de sesión. Vi una captura de Bancos y caja en oscuro; el resto se midió por código. No comprobé los estados al pasar el cursor.
- **Dos operaciones exactamente a la vez** (dos importaciones, dos gastos de caja): la cuenta se bloquea en la base, pero no lo probé con dos conexiones simultáneas.

## Datos de prueba que quedaron en "Tenant A - Prueba"

- **Recurrencia:** tu instancia "Soporte mensual" (Minera Prueba CRM) quedó con el ciclo 2026-10 procesado (evento por facturar de S/ 531.00), renovada hasta el 14 oct. 2028 con precio 480 y con una suspensión y reactivación en su historial.
- **Bancos y caja:** cuatro cuentas con "(prueba)" en el nombre: BCP Soles, BBVA Soles, Caja chica y Detracciones BN; una transferencia, dos extractos, una comisión, un abono por identificar, un gasto `CCH-2026-0001`, un arqueo y una reposición. Las cuentas no se borran; se pueden renombrar o desactivar (sin saldo).
- **Cobros:** `PAG-2026-0005` (S/ 150, sin aplicar, en BCP Soles).
- **Comprobantes:** `F001-00000002` (S/ 1,180, con detracción de S/ 142 y su constancia registrada; saldo S/ 1,038).
- **Configuración fiscal:** dejé la detracción **apagada** y sin cuenta, como estaba. Queda en la bitácora una activación y un restablecimiento de contingencia de prueba.

## Decisiones tomadas en esta tanda

Lo que decidí sin preguntarte; dime cuáles cambio.

**Recurrencia**

1. La instancia guarda su propio precio, cantidad, moneda, vigencia, cliente, responsable y sede. `recurring_service_prices` pasa a ser el historial de vigencias anteriores.
2. El importe del ciclo es precio × cantidad + IGV. El periodo se nombra por el mes de inicio del ciclo.
3. El proceso general pone al día hasta 12 periodos por instancia; a mano, uno por vez y con hasta 40 días de anticipación (lo del prototipo).
4. Un ciclo falla si el cliente no tiene nombre o documento, o si el precio pactado es cero.
5. Renovar extiende la vigencia desde donde terminaba (o desde hoy si ya había vencido) y el precio nuevo vale desde el próximo ciclo.
6. Reactivar o renovar una vencida exige que su servicio contratado siga activo.
7. Suspender una instancia desde aquí pausa solo su cobro. Si después se reactiva el servicio contratado, la instancia vuelve a Activa aunque se hubiera suspendido a mano.
8. Orden programada diaria además del botón.

**Bancos y caja**

9. Se usa tu tabla `financial_accounts`; las cuentas se crean y editan con un permiso propio (`treasury.manage`).
10. Cuenta de destino del cobro: si hay una sola posible va a esa; si hay varias, se exige elegir.
11. Lo que entra o sale de una caja nace conciliado (una caja no tiene extracto). La apertura tampoco se concilia.
12. Una caja no reembolsa ni gasta más de lo que tiene; un banco sí puede quedar en negativo por un reembolso (sobregiro), no por una transferencia.
13. El extracto se sube como archivo (Excel o CSV) pero **no se guarda como adjunto**: se guardan su nombre, su huella y sus líneas. Tope de 2,000 líneas y 5 MB por importación.
14. Dos líneas idénticas en un mismo extracto (dos ITF) cuentan como dos; reimportar el mismo archivo no duplica.
15. Emparejar a mano exige el mismo importe. "Registrar comisión" solo para cargos y "Abono por identificar" solo para abonos.
16. Centro de costo y cuenta de gasto: texto libre y opcionales.
17. El arqueo no ajusta el saldo ni avisa a Gerencia (no hay Notificaciones): queda en auditoría.
18. Una sola cuenta de detracciones activa por empresa. Los cobros anteriores a hoy no tienen movimiento bancario.
19. Bancos y caja no tiene alcance por rol.

**Facturación electrónica**

20. Sin configurar, **no se calcula detracción**. Hay que activarla.
21. Códigos y porcentajes del Anexo 3 del SPOT para servicios (012, 019, 020, 021, 022, 024, 025, 026, 030, 037), fijos en el código. Umbral S/ 700 y retención 3 %, también fijos. **Valídalos con tu contador.**
22. La detracción se redondea a **soles enteros** (el depósito en el Banco de la Nación no lleva decimales); la retención, a dos decimales.
23. "Exentos" se decide en cada borrador de factura (No aplica u otro código), no por servicio del catálogo.
24. Agente de retención es un dato del cliente, guardado con sus condiciones comerciales.
25. La constancia baja la cuenta por cobrar por el importe retenido (en dólares, al tipo de cambio de la emisión). Con una constancia aplicada no se emite nota de crédito, igual que con un cobro.
26. Series de contingencia por defecto `0001` (facturas) y `0002` (boletas): distintas, porque el número de un comprobante no se repite en la empresa. Una nota de crédito emitida en contingencia conserva su serie y queda "Contingencia".
27. La serie se toma al emitir (no al crear el borrador).
28. El RUC del emisor se guarda también en la empresa (`companies`), que es de donde sale en cada comprobante.
29. Permiso `fiscal.configure` separado de `fiscal.manage`, y columna "Configurar" en Roles y permisos.
30. Una baja aceptada no devuelve los eventos a "por facturar" (igual que la nota de crédito). Un fallo de comunicación deja la baja "Rechazada" para volver a pedirla.
31. No adopté del kit la bandeja de eventos, los asientos contables ni sus tablas (`tre_…`, `fin_…`).

## Pendiente

- Elegir proveedor y escribir su clase; con él: XML y CDR, consultar el ticket de una baja que quedó "Enviada", certificado y formato.
- Renovación automática de instancias; avisos de renovación y de arqueo con diferencia (con Notificaciones).
- Centros de costo, cuentas de gasto y asientos (con Contabilidad).
- Guardar el archivo del extracto como adjunto (por `DocumentStore`, que hoy no admite CSV).
- Operación de cambio entre cuentas de distinta moneda; pagos a proveedores (con Compras).
- Detracción por servicio del catálogo, si prefieres no decidirla en cada factura.
- De la tanda anterior siguen: recordatorios de cobranza, eventos por horas y por hitos, nota de crédito parcial, editar el vencimiento de un borrador.
