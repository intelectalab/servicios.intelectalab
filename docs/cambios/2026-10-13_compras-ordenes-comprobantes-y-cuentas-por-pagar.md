# 2026-10-13 — Compras: Órdenes de compra, Comprobantes de proveedor y Cuentas por pagar

## Qué se pidió

"Módulo Compras."

Son las tres pantallas del grupo **Compras** del menú, que estaban como "próximamente": **Órdenes de compra** (con su ficha), **Comprobantes de proveedor** y **Cuentas por pagar** (con los pagos a proveedores).

Referencias: prototipo (`fuente/js/ui/views/purchasing.js` completo; `services/finance2.js`: compras, cruce de 3 vías, pago a proveedores, presupuesto; `core/states.js`: `purchaseOrder`, `supplierInvoice`, `payable`, `supplierPayment`; `core/fin.js`: plan de cuentas; `core/rbac.js`: recursos `purchases`, `payables`, `budgets`) y kit (`09-compras-cxp.md`, reglas RN-PUR-01 a 08).

No se te consultó antes de empezar. Lo decidido sin preguntarte está en "Decisiones tomadas en esta tanda".

## A la vista pero sin función o sin dato (léelo primero)

| Dónde | Qué | Por qué |
| --- | --- | --- |
| Ficha de la orden → **Control de presupuesto** | Siempre dice "El centro de costo no tiene presupuesto aprobado." (el texto del prototipo para ese caso) | El presupuesto por centro de costo es de **Contabilidad → Centros de costo y presupuestos**, que no existe. Tu tabla `budgets` no tiene centro de costo ni está aprobada por nadie. El aviso "Supera el presupuesto disponible" está hecho, pero nunca aparece. |
| Ficha del comprobante → **Validez SUNAT** | "Sin consultar" y "La consulta de validez ante SUNAT aún no está conectada." | El prototipo la **simula** ("VÁLIDO"). La consulta real necesita credenciales de la empresa ante SUNAT; no se simuló. |
| Ficha del comprobante → **Asiento** | Se muestra la fila con "Sin asiento: se generará cuando exista Contabilidad (Libro diario)." | No hay Contabilidad ni asientos. |
| **Cuenta de gasto** (líneas de la orden y comprobante) | Lista fija de 11 cuentas del PCGE, las del prototipo | El plan de cuentas es de Contabilidad. |
| Pago → **Cuenta BN del proveedor** | "No registrada" mientras no se llene | Se llena en Clientes y actores (campo nuevo, ver abajo). |

## Cómo funciona

### Órdenes de compra (`/purchase-orders` y `/purchase-orders/{id}`)

- **Listado**, con la disposición del prototipo: cuatro indicadores (**Por aprobar** con su importe, **Por recibir**, **Recibidas sin facturar**, **Comprometido (aprobado)**), pestañas (**Todas, Borradores, Por aprobar, Por recibir, Recibidas, Cerradas**, con su conteo), buscador ("Buscar OC, proveedor o descripción") y filtro **Centro de costo**. Columnas: Orden (con su primera línea y "+N"), Proveedor, Centro de costo, Total, Recepción / facturado (barra + "x % recibido · S/ y facturado"), Solicitante y Estado. Ordenar, filtrar por columna, seleccionar y exportar, todo en el servidor; exportar al pie.
- **Nueva orden de compra**: proveedor (de Clientes y actores, rol Proveedor), centro de costo, moneda, requerida para (diez días si no se indica), notas y las líneas (descripción, cantidad, costo unitario sin IGV, cuenta de gasto). Como el prototipo, nace con tres líneas; se agregan o quitan. Muestra subtotal, IGV y total. **Guardar borrador** o **Guardar y enviar a aprobación**; guardada, se abre su ficha.
- **Ficha**, con el reparto del prototipo (dos tercios y un tercio, tarjetas movibles): a la izquierda **Líneas** (con totales y exportar), **Estados** (recorrido y botones) y **Comprobantes del proveedor** (con el cruce "Cuadra / Revisar" y exportar); a la derecha **Control de presupuesto**, **Recepción**, **Datos** e **Historial**. Arriba: **Editar** (borrador), **Registrar recepción**, **Registrar comprobante del proveedor** y **Descargar PDF** (vista previa con marca de agua mientras no esté aprobada).
- **Estados** (los del prototipo): Borrador → Por aprobar → Aprobada → Recepción parcial → Recibida → Cerrada; además Rechazada y Anulada.
  - **Enviar a aprobación** (con al menos una línea). **Aprobar** y **Rechazar** (con motivo) los hace **otra persona**: quien la creó no la aprueba mientras la empresa tenga la segregación de funciones (el mismo ajuste de las cotizaciones). La ficha lo avisa: "Creaste esta orden: por segregación de funciones debe aprobarla otra persona."
  - Rechazada, **Corregir** la devuelve a borrador.
  - **Registrar recepción**: porcentaje (propone lo pendiente) y observación o guía de remisión. Lo recibido nunca pasa del 100 %.
  - **Cerrar** exige al menos una recepción. **Anular** (con motivo) desde borrador, por aprobar o aprobada, y solo si no tiene comprobantes del proveedor vivos.
- Solo se edita en borrador; enviada, ya no cambian su proveedor, su centro de costo, su moneda ni sus importes.

### Comprobantes de proveedor (`/supplier-invoices`)

- Pestañas **Todos, Por aprobar, Aprobados, Rechazados**, buscador ("Buscar comprobante, proveedor o concepto") y filtro **Centro de costo**. Columnas del prototipo: Comprobante (tipo y concepto), Proveedor, Emisión / vence, Gasto (cuenta y centro de costo), Total (con la detracción o la retención de 4.ª), OC (enlace a la orden) y Estado. Clic en la fila abre la ficha.
- **Registrar comprobante**: proveedor, tipo (**Factura** o **Recibo por honorarios**), serie-número (`E001-1234`, se pasa a mayúsculas), emisión, vencimiento (vacío: 30 días), valor de venta sin IGV, moneda, orden de compra (o "Sin OC (gasto recurrente)"), centro de costo, cuenta de gasto, concepto y **Detracción** (el código del Anexo 3 que trae la factura, o "No aplica"). Desde una orden llega con sus datos y con el valor de lo ya recibido.
  - El IGV se calcula al 18 %; un recibo por honorarios no lleva IGV y, por más de S/ 1500, se le retiene el 8 % (4.ª categoría).
  - La detracción se calcula con el código elegido sobre el total en soles, si pasa de S/ 700, en soles enteros.
  - En dólares se guarda el tipo de cambio de venta del día de emisión (sin tipo de cambio no se registra).
  - **No se registra dos veces** el mismo comprobante de un proveedor (tipo + serie-número); uno anulado deja su número libre.
- **Ficha** (ventana sobre el listado, como el panel del prototipo): todos los datos del prototipo, **Validez SUNAT**, **Cruce con OC (3 vías)** con su explicación, la orden, la cuenta por pagar, estados y botones, e historial.
  - **Aprobar para pago** exige que **cuadre**: la orden aprobada con recepción, y lo facturado (este más los ya aprobados de la misma orden) dentro del **2 %** de lo recibido. Al aprobarse nace su **cuenta por pagar**.
  - **Rechazar** (con motivo) y **Anular** (con motivo, desde registrado o rechazado). Aprobado, ya no cambia.

### Cuentas por pagar (`/payables`)

- Título del prototipo: "Cuentas por pagar y pagos a proveedores". Cuatro indicadores (**Saldo por pagar**, **Vencido**, **Vence en 7 días**, **Detracciones por depositar**), **Vencimientos** (Vencido, 0–7 días, 8–30 días, > 30 días) y **Saldos bancarios disponibles** (los de Bancos y caja; con candado si el rol no ve Bancos y caja).
- Tabla: Comprobante (abre la ficha del comprobante), Proveedor, Vence (con "Vencida"), Original, Detracción (con "depositada"), Saldo y Estado; filtro **Estado** (Pendiente, Vencida, Pagada). Clic en la fila abre la ficha de la cuenta con sus movimientos.
- **Pagar seleccionadas** (la barra del prototipo): al marcar cuentas con saldo aparece el total y el proveedor; si son de proveedores o monedas distintas, avisa en rojo y no deja pagar.
  - Ventana del pago: Total de documentos, Detracción a depositar, **Neto a transferir**, Cuenta BN del proveedor; **Pagar desde** (bancos y cajas de esa moneda, con su saldo), Medio (Transferencia, Cheque, Efectivo), N.º de operación, Fecha e **Incluir el depósito de la detracción** con el **N.º de constancia**.
  - Con la detracción incluida, la cuenta queda **pagada**: salen dos movimientos de la cuenta (el neto al proveedor y el depósito al Banco de la Nación). Sin incluirla, sale solo el neto y la cuenta queda con la detracción **por depositar**; se paga después, con su constancia.
  - Exige **saldo suficiente**, cuenta de la misma moneda que la deuda y número de operación (salvo efectivo). En dólares, el depósito de la detracción sale de una cuenta **en soles** que se elige.
- Tarjeta **Pagos a proveedores**: Pago (medio y n.º de operación), Proveedor, Cuenta, Fecha, Neto pagado y Detracción (con su constancia); ordenar, filtrar por columna y exportar al pie.

### Centros de costo

Mientras no exista Contabilidad, se crean desde los formularios de Compras: debajo del campo **Centro de costo**, "Nuevo centro de costo" (código y nombre, el código no se repite). Usa tu tabla `cost_centers`, que estaba vacía. Pide un permiso propio.

### Cuenta de detracciones del proveedor (Clientes y actores)

Campo nuevo en el formulario de una identidad: **Cuenta de detracciones (Banco de la Nación)**, formato `00-000-000000`. Como los demás datos de un rol, solo se llena con el rol **Proveedor** ("Se guarda con el rol Proveedor"). Se muestra al pagarle y en su cuenta por pagar.

### Lo que la base garantiza por sí sola

- Las líneas de una orden solo cambian en borrador; enviada, la orden no cambia de proveedor, centro de costo, moneda ni importes.
- Rechazar y anular llevan motivo; una orden aprobada tiene fecha de aprobación.
- Solo se recibe una orden aprobada y lo recibido nunca pasa del 100 %. Una conformidad no se edita ni se borra.
- Un comprobante de proveedor no se registra dos veces y, registrado, no se modifica; aprobado o anulado, no cambia de estado.
- Una cuenta por pagar por comprobante; su saldo es la suma de sus movimientos (nadie lo escribe), no baja de cero ni supera el original, y una detracción depositada no vuelve a pendiente.
- Órdenes, comprobantes, cuentas por pagar, movimientos y pagos **no se borran** (la aplicación no tiene ese permiso). Un centro de costo tampoco: se desactivará.
- Todo pago deja su movimiento en Bancos y caja (pasa por `Treasury`), así que el saldo de la cuenta baja solo.

### Permisos y alcance

| Permiso | Para qué | Administrador | Gerencia | Operaciones | Finanzas |
| --- | --- | --- | --- | --- | --- |
| `purchases.view` | Ver órdenes de compra | ✓ | ✓ | ✓ (su sede) | ✓ |
| `purchases.create` | Crear órdenes (en borrador) | ✓ | | ✓ | ✓ |
| `purchases.manage` | Editar el borrador, enviar, recibir, cerrar, anular | ✓ | | ✓ | ✓ |
| `purchases.approve` | Aprobar o rechazar | ✓ | ✓ | | |
| `purchases.export` | Exportar | ✓ | ✓ | | |
| `payables.view` | Ver comprobantes, cuentas por pagar y pagos | ✓ | ✓ | | ✓ |
| `payables.create` | Registrar comprobantes | ✓ | | | ✓ |
| `payables.manage` | Anular comprobantes | ✓ | | | ✓ |
| `payables.approve` | Aprobar comprobantes y **pagar** | ✓ | ✓ | | ✓ |
| `payables.export` | Exportar | ✓ | ✓ | | ✓ |
| `budgets.manage` | Crear centros de costo | ✓ | | | ✓ |

- Es el reparto de la matriz del prototipo (`rbac.js`). **Lo que da de más a un rol frente a lo que ya tenía:** a **Gerencia**, `payables.approve` le permite **pagar a proveedores**, como en el prototipo ("vax" en cuentas por pagar). Si prefieres que Gerencia apruebe comprobantes pero no pague, hay que separar "pagar" en un permiso propio; dímelo y lo hago.
- Enviar una orden a aprobación es **gestionarla**: quien solo tiene `purchases.create` la deja en borrador (la API lo comprueba).
- **Alcance**, en dos recursos nuevos: **Órdenes de compra** (propia = quien la pidió) y **Cuentas por pagar** (comprobantes, cuentas por pagar y pagos; propio = quien los registró). Operaciones ve las de su sede; Gerencia y Finanzas, toda la empresa. Se elige en Roles y permisos.
- Todo cuelga del módulo **Finanzas** del plan.
- Sin permiso, el botón sale con candado y su mensaje; la tarjeta "Comprobantes del proveedor" de la ficha de la orden, con candado si el rol no ve Cuentas por pagar.

## Qué se cambió

### API (`apps/api`)

- Migración `2026_10_13_001000_purchasing_orders_supplier_invoices_and_payables.php` (ya corrida).
- Módulo nuevo `app/Modules/Purchasing`: modelos `PurchaseOrder`, `PurchaseOrderItem`, `SupplierInvoice`, `Payable`, `SupplierPayment` (con alcance), `Support/Purchasing` (cuentas de gasto, datos del proveedor, detracción, retención de 4.ª, cruce de 3 vías) y controladores `PurchaseOrderController`, `SupplierInvoiceController`, `PayableController`, `CostCenterController`.
- `routes/api.php`: rutas de `/purchase-orders`, `/supplier-invoices`, `/payables`, `/supplier-payments`, `/cost-centers` y `PUT /parties/{party}/supplier`.
- `AccessScope` (recursos `purchases` y `payables`), `CheckRecordVersion` (órdenes y comprobantes), `ListExport` (cuatro listados), `AuditCatalog` (entidades y acciones nuevas), `Supplier` (cuenta de detracciones), `PartyController` (`updateSupplier` y el dato en la ficha).
- `InvoiceController`: la ficha de un comprobante muestra la comunicación de baja **viva** y, si no hay, la más reciente (ver "Arreglo de paso").
- Seeders locales de Finanzas y Operaciones con los permisos nuevos.
- Pruebas: `PurchasingTest` (7) y ajustes en `AccessScopeTest` y `OperationsFixtures`.

### Web (`apps/web`)

- `components/dashboard/purchasing/`: `PurchaseOrdersList.tsx`, `PurchaseOrderDetail.tsx`, `SupplierInvoices.tsx`, `Payables.tsx`, `PurchaseModals.tsx` (orden, recepción, comprobante, ficha del comprobante, pago, alta de centro de costo).
- `lib/purchasing.ts` (estados, textos, PDF de la orden) y `types/purchasing.ts`.
- Páginas `app/(dashboard)/purchase-orders`, `purchase-orders/[id]`, `supplier-invoices`, `payables`.
- `AppShell.tsx` (las tres entradas de Compras dejan de ser "próximamente"), `proxy.ts`, `lib/api.ts` (la recepción lleva clave contra envíos repetidos), `RolesPermissions.tsx` (nombres de los recursos nuevos), `ScopeNote.tsx`, `ActorForm.tsx` y `types/actor.ts` (cuenta de detracciones del proveedor).

## Arreglo de paso (fuera de Compras)

La prueba `FinanceFiscalTest › a void goes through the provider…` fallaba a veces: dentro de una prueba todo ocurre en la misma transacción, la baja rechazada y la aceptada quedaban con la misma hora y la ficha mostraba cualquiera de las dos. En producción no pasaba (cada pedido es otra transacción). Ahora la ficha elige primero la baja viva (en curso o aceptada; solo puede haber una) y, si no hay, la más reciente. Para quien usa el sistema no cambia nada.

## Ajuste posterior: la ficha del comprobante como en el prototipo y datos para Cuentas por pagar

Pediste que la ficha del comprobante mostrara lo mismo que el prototipo (con **Asiento**) en Cuentas por pagar y en Comprobantes de proveedor, y probar Cuentas por pagar con datos, porque los indicadores salían en cero.

- En **Cuentas por pagar**, el clic en la fila abre ahora **la ficha del comprobante**, como en el prototipo (antes abría una ventana propia más corta, "Cuenta por pagar"). Esa ventana se quitó; sus **movimientos** pasaron a la ficha del comprobante ("Movimientos de la cuenta por pagar"), así que no se pierde nada. La API entrega esos movimientos con la ficha (`payable.movements`).
- La ficha muestra la fila **Asiento**: "Sin asiento: se generará cuando exista Contabilidad (Libro diario)." (antes la fila no aparecía).
- En un comprobante aprobado o anulado, el bloque de estados dice "Sin transiciones disponibles desde este estado.", como el prototipo.
- **Datos de prueba** creados por la API normal con la cuenta de Finanzas: centro de costo **CC-ADM**; comprobantes **E001-6479** (vencido, S/ 5,097.60), **F002-1180** (detracción 020 de S/ 212; pagado el neto con PP-2026-0002 y la detracción después con PP-2026-0003, constancia 40055667), **F001-4610** (vence en 23 días), **E001-15** (recibo por honorarios de Loulan Ramirez con retención de 4.ª S/ 160), **F002-1201** (US$ 1,180) y **E001-6533** (registrado, por aprobar).
- Probado en el navegador con Finanzas: los cuatro indicadores y los cuatro tramos con el cálculo a mano (saldo S/ 12,162.24 en 5 documentos; vencido 5,097.60; esta semana 212; detracciones por depositar 212; 8–30 días 944; más de 30 días 5,908.64 con el dólar a 3.448); la ficha completa al hacer clic en la fila; y **depositar después la detracción pendiente**, que antes solo estaba probado con pruebas automáticas: la cuenta quedó pagada y los indicadores bajaron a S/ 12.0 mil en 4 documentos y detracciones S/ 0. `php artisan test`: 207 pruebas pasan.

## Qué NO se tocó

- La tabla compartida de los listados (`DataTable`). A 1366 px, con la barra lateral abierta, **Órdenes de compra** se pasa del ancho (1185 px de tabla en 1030 de espacio) y **Comprobantes de proveedor** un poco (1080 en 1030): se desplaza de lado y la columna Estado queda a la derecha. Ya pasa en tus listados de **Cobros** (1183/1000) y **Recurrencia** (1169/1030). El ancho lo fijan las cabeceras largas del prototipo ("Recepción / facturado", "Centro de costo", "Emisión / vence"), que no bajan de línea. Propuesta: que las cabeceras de todas las tablas puedan ir en dos líneas; afecta a todos los listados, así que no lo hice sin preguntarte.
- Tu tabla `budgets` / `budget_lines` (sin centro de costo): se queda para Contabilidad.
- Las pantallas desconectadas de Proveedores.

## Diferencias con el prototipo

- La ficha del comprobante es una **ventana** sobre el listado, no el panel lateral (como Auditoría y Cuentas por cobrar).
- El prototipo calcula la detracción por la "categoría" del proveedor; aquí se elige el **código del Anexo 3** que trae la factura (el mismo catálogo de Facturación electrónica).
- El prototipo inventa el número de constancia de la detracción; aquí se escribe el de la constancia real.
- El formulario de la orden permite **agregar y quitar líneas** (el prototipo tiene tres fijas) y muestra el total mientras se escribe.
- "Vencimiento vacío" usa 30 días (el prototipo usa las condiciones del proveedor, que no existen en el sistema).
- Sin incluir la detracción, el pago deja la cuenta abierta por la detracción (en el prototipo la daba por pagada sin depositar).
- Clic en una fila de Cuentas por pagar abre su ficha con movimientos (en el prototipo la fila no abría nada).
- Las notas explicativas bajo el título son de la demo y no se trajeron.

## Base de datos

Tablas nuevas, todas con aislamiento por empresa: `purchase_orders`, `purchase_order_items`, `purchase_receipts`, `supplier_invoices`, `payables`, `payable_movements`, `supplier_payments`, `supplier_payment_allocations`. Columna nueva `suppliers.detraction_account`. `cost_centers` ya no se borra desde la aplicación. Permisos nuevos `purchases.*`, `payables.*`, `budgets.manage`; el módulo Finanzas los incluye; alcance para `purchases` y `payables`. Solo añade: no borra datos.

## Cómo se probó

- **Pruebas automáticas:** `php artisan test` → **207 pruebas pasan** (`PurchasingTest`, 7: orden de punta a punta con segregación y edición solo en borrador, recepción sin pasar del 100 %, comprobante sin duplicados y cruce de 3 vías, detracción y retención de 4.ª (también en dólares), pago con y sin detracción y saldo de la cuenta, permisos, alcance por sede y lo propio, otra empresa, exportar, cuenta BN del proveedor; y en cada caso lo que la base rechaza por sí sola).
- `tsc` y `eslint` sin errores.
- **En el navegador, a 1366 × 768, con cuentas reales:**
  - **Finanzas:** creó el centro de costo desde el formulario, la orden OC-2026-0001 (dos líneas, S/ 1,416.00) y la envió; vio el aviso de segregación y el candado en Aprobar, Rechazar y Exportar.
  - **Gerencia:** la aprobó; candado en Registrar recepción, Registrar comprobante, Cerrar y Anular; exportó el listado (Excel válido, capturado sin descargar).
  - **Finanzas:** registró la recepción del 60 % (con guía), el comprobante F001-4521 desde la orden (llegó con proveedor, centro, cuenta, valor de lo recibido S/ 720 y concepto; detracción 020 = S/ 102; cruce "diferencia 0.0 %"), lo aprobó (nació la cuenta por pagar de S/ 849.60) y lo pagó desde BCP Soles con la detracción incluida: PP-2026-0001, neto S/ 747.60 + detracción S/ 102.00; la cuenta quedó Pagada y el saldo del BCP pasó de S/ 3,870.00 a S/ 3,020.40.
  - **Operaciones:** solo ve "Órdenes de compra" en el menú, con "Alcance: Sede"; la tarjeta de comprobantes con candado; candado en "Nuevo centro de costo" y en exportar.
  - **Ventas:** no ve Compras en el menú; guardó la cuenta de detracciones de "Actor de Prueba S.A.C." y el formato inválido se rechazó.
  - **Administrador:** la matriz de Roles y permisos muestra los tres recursos nuevos con su alcance.
  - **PDF de la orden:** generado y revisado (proveedor, centro de costo, líneas, totales, quién aprobó y dos firmas).
  - **Oscuro** (ficha de la orden, Cuentas por pagar, Comprobantes) y **375 px** (listado y ficha de órdenes, formulario, Comprobantes y Cuentas por pagar): sin desbordes ni franja vacía al final.

### Lo que no se pudo probar así, y por qué

- **Pagar en dólares con detracción** (cuenta en soles aparte) y **pagar sin incluir la detracción y depositarla después**: solo con pruebas automáticas; en la empresa de prueba no hay cuentas en dólares con saldo y no quise dejar más pagos de prueba.
- **Rechazar, corregir, cerrar y anular una orden** y **rechazar o anular un comprobante** en el navegador: probados con pruebas automáticas, no con clics.
- Las capturas de las ventanas salen a medio aparecer (la animación de entrada no avanza con el panel del navegador oculto); el contenido se comprobó leyendo la página.
- La ventana de **Proveedores Globales S.A.C.** en Clientes y actores no se puede guardar ("Ya existe otra identidad con ese documento"): es uno de los RUC repetidos de tu empresa de prueba (de antes). Por eso la cuenta BN se probó con "Actor de Prueba S.A.C.".

## Datos de prueba que quedaron en "Tenant A - Prueba"

Centro de costo **CC-OPS · Operaciones de campo (prueba)**; orden **OC-2026-0001** (Proveedores Globales, recibida al 60 %, aprobada por Gerencia); comprobante **F001-4521** aprobado; su cuenta por pagar, pagada; pago **PP-2026-0001** (BCP Soles: −S/ 747.60 y −S/ 102.00); cuenta de detracciones `00-581-223344` en "Actor de Prueba S.A.C.". No se pueden borrar desde la aplicación.

## Decisiones tomadas en esta tanda

1. Compras cuelga del módulo **Finanzas** del plan (tus 22 módulos no tienen uno de Compras). Si quieres venderlo aparte, es una fila más en el catálogo de módulos.
2. Permisos en dos recursos, como el prototipo: `purchases` (órdenes) y `payables` (comprobantes, cuentas por pagar y pagos), con las acciones de tu matriz (Ver, Crear, Gestionar, Aprobar, Exportar). **Pagar** va con Aprobar, como en el prototipo.
3. Reparto del prototipo para Gerencia, Operaciones y Finanzas (tabla de arriba); Finanzas no aprueba órdenes ni las exporta.
4. **Enviar a aprobación** pide Gestionar, no solo Crear.
5. Segregación: quien crea la orden no la aprueba, con el mismo ajuste de empresa que las cotizaciones. En comprobantes de proveedor no hay segregación (el prototipo no la pide).
6. Alcance: orden propia = quien la pidió; comprobante, cuenta por pagar y pago propios = quien los registró. La orden nace en la sede de quien la pide.
7. Centros de costo: tu tabla `cost_centers`, con alta rápida desde Compras y permiso propio `budgets.manage` (Administrador y Finanzas). Ya no se borran desde la aplicación.
8. Cuentas de gasto: las 11 del prototipo, fijas, hasta que exista el plan de cuentas.
9. **Control de presupuesto** sin datos (texto del prototipo) hasta Contabilidad.
10. **Validez SUNAT** "Sin consultar": no se simula.
11. Detracción: se elige el código del Anexo 3 de la factura; sobre el total en soles, si pasa de S/ 700, en soles enteros. Mismo catálogo que Facturación electrónica (los códigos y porcentajes que tu contador debe validar).
12. Retención de 4.ª: 8 % en recibos por honorarios por más de S/ 1500; la cuenta por pagar nace con el neto.
13. Tolerancia del cruce de 3 vías: **2 %**, fija (en el prototipo es un ajuste de empresa; irá a Configuración).
14. El comprobante debe ser del **mismo proveedor y moneda** que su orden (el prototipo no lo controla).
15. Un comprobante **aprobado no se anula** (como el prototipo). Corregirlo pedirá una nota de crédito del proveedor (pendiente).
16. Una orden con comprobantes vivos no se anula; primero se anulan los comprobantes.
17. Vencimiento por defecto: 30 días desde la emisión.
18. Un pago es de **un proveedor y una moneda**; paga el saldo completo de cada cuenta elegida (no hay pago parcial por importe).
19. Sin incluir la detracción, la cuenta queda abierta por ese importe ("por depositar") y se paga después con su constancia.
20. El depósito de la detracción sale de la misma cuenta si está en soles; en dólares, de otra cuenta en soles que se elige.
21. El número de constancia de la detracción lo escribe quien paga (obligatorio si se incluye).
22. Números: `OC-2026-0001` para órdenes y `PP-2026-0001` para pagos (el prototipo usa `OC-<empresa>-00001`).
23. Cuenta de detracciones del proveedor en Clientes y actores (campo del rol Proveedor), con su propia ruta (`PUT /parties/{id}/supplier`, permiso `core.manage`) y registro en auditoría. No añadí una tarjeta en la ficha del actor para mostrarla; se ve en el formulario y al pagar.
24. La ficha del comprobante y la de la cuenta por pagar son ventanas; la de la orden, una página.
25. Las tablas dentro de las tarjetas (líneas de la orden, sus comprobantes, pagos a proveedores) se exportan al pie con el permiso del recurso y quedan en auditoría.

## Pendiente

- Contabilidad: presupuestos por centro de costo (control al aprobar: apagado / advertir / bloquear), plan de cuentas, asiento de la compra y del pago, pantalla de centros de costo (activar y desactivar).
- Consulta de validez del comprobante ante SUNAT.
- Nota de crédito de proveedor; revertir un pago a proveedor (el estado existe, la acción no).
- Tolerancia del cruce y reglas de compra en Configuración.
- Avisar a quien aprueba cuando llega una orden (con Notificaciones).
- Pagos parciales por importe y pagos programados.
- Las cabeceras de tabla en dos líneas para que los listados anchos quepan a 1366 px (propuesta, arriba).
