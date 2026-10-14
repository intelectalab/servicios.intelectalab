# 2026-10-14 — Contabilidad: Libro diario, Mayor y balance, Estados financieros, Centros de costo y presupuestos, Plan de cuentas y periodos

## Qué se pidió

"Sigue con el módulo de contabilidad."

Son las cinco pantallas del grupo **Contabilidad** del menú, que estaban como "próximamente", y lo que Compras dejó esperando: que la fila **Asiento** del comprobante de proveedor tenga número y que funcione el **Control de presupuesto** de la orden de compra.

Referencias: prototipo (`fuente/js/ui/views/accounting.js` completo; `core/fin.js`: plan de cuentas, reglas y `post.*`; `services/finance2.js`: asiento manual, extorno, periodos, presupuesto, comprometido y control de la orden de compra; `core/states.js`: `journal`, `period`, `budget`; `core/rbac.js`: recursos `accounting` y `budgets`) y kit (`11-contabilidad.md`, reglas RN-ACC-01 a 06).

No se te consultó antes de empezar: la disposición del prototipo se respetó en las cinco pantallas, así que no hubo nada que proponerte antes. Lo decidido sin preguntarte está en "Decisiones tomadas en esta tanda".

## A la vista pero sin función o sin dato (léelo primero)

| Dónde | Qué | Por qué |
| --- | --- | --- |
| Estados financieros → **Gastos de personal (62)**, **Ingresos financieros (77)**, **Gastos financieros (67)**, **Otras cuentas por cobrar (16)** | Salen en S/ 0.00 | Ningún documento los genera todavía: no hay planilla (62); 77 y 67 solo los mueve la diferencia de cambio y en tus datos no hay ventas en dólares cobradas; ninguna regla usa la 16. Se pueden llenar con asientos manuales. |
| Plan de cuentas → **Reglas automáticas** | La lista es de solo lectura | Las reglas están escritas en el sistema. El kit propone editarlas; no se hizo (ver Pendientes). |
| Mayor y balance, Bancos y caja | La cuenta **1041** dice S/ 6,045.40 y Bancos y caja suma S/ 3,275.40; la **1011** dice S/ 900 y la caja S/ 500 | Datos viejos: los cobros PAG-2026-0001, 0002 y 0003 (S/ 2,770) y el cobro en efectivo PAG-2026-0004 (S/ 600, con S/ 200 reembolsados) se registraron **antes de que existiera Bancos y caja** y no tienen cuenta ni movimiento. El asiento sí los lleva al banco y a la caja. En un tenant nuevo no pasa: hoy todo cobro deja su movimiento. |

## Cómo funciona

### Asientos automáticos (todo documento deja su asiento)

Cada documento escribe su asiento **en la misma operación** en que se registra: el documento y su asiento existen juntos o no existe ninguno. Un documento genera **un solo asiento vigente** (la base lo impide dos veces). Moneda funcional: soles; lo que está en dólares se lleva a soles con el tipo de cambio que guarda el propio documento.

| Documento | Asiento |
| --- | --- |
| Comprobante de venta emitido | D 1212 total (con el cliente) · H 40111 IGV · H 7041 valor de venta (con el **centro de ingresos de la sede** del comprobante, si la empresa lo creó) |
| Nota de crédito | El inverso: D 7041 · D 40111 · H 1212 |
| Baja aceptada por SUNAT | **Extorno** del asiento del comprobante |
| Cobro | D 1041/1011 (la cuenta de Bancos y caja donde entró) · H 1212 |
| Aplicación de un cobro en US$ | Diferencia de cambio: D 1212 / H 7761 si se ganó, D 6761 / H 1212 si se perdió |
| Reembolso de un cobro | D 1212 · H banco o caja, al tipo de cambio del cobro |
| Constancia de detracción / certificado de retención | D 1042 (cuenta de detracciones) o D 40113 · H 1212 |
| Comprobante de proveedor aprobado | D cuenta de gasto (con su **centro de costo**) · D 40111 crédito fiscal · H 4212 (· H 40172 retención de 4.ª) |
| Pago a proveedor | D 4212 · H banco por el neto · H banco por el depósito de la detracción; en US$, la diferencia entre el tipo de cambio del comprobante y el del día del pago va a 6761/7761 |
| Gasto de caja chica | D cuenta de gasto · H 1011 |
| Comisión del extracto | D 6391 · H banco |
| Abono por identificar del extracto | D banco · H 122 |
| Transferencia (y reposición de caja) | D cuenta de destino · H cuenta de origen |
| Saldo de apertura de una cuenta | D banco o caja · H 5911 |

La cuenta contable de un banco o una caja es la que tiene escrita en Bancos y caja ("cuenta contable") si existe en el plan; si no, la de su tipo: caja 1011, banco en soles 1041, banco en dólares 10412, detracciones 1042.

Los documentos que ya existían antes de esta tanda se contabilizaron con la orden `php artisan accounting:post-pending` (ver "Datos de prueba"). La orden usa las mismas reglas, no duplica y se puede correr las veces que haga falta.

### Libro diario (`/journal`)

- Como el prototipo: botón **Asiento manual**; cuatro indicadores (**Asientos**, **Periodo AAAA-MM** con sus asientos, **Automáticos**, **Debe = Haber** "Cuadra"/"Revisar"); buscador "Buscar número, glosa o cuenta" y filtros **Periodo** y **Origen**; columnas **Asiento** (número y fecha), **Glosa** (con su origen), **Cuentas** ("1212 D · 40111 H · 7041 H"), **Importe** y **Estado** (Contabilizado / Extornado).
- Ordenar, filtrar por columna, seleccionar y exportar en el servidor; exportar al pie. Con `?q=` en la dirección llega buscado (es lo que usa el enlace del comprobante de proveedor).
- **Ficha del asiento** (una ventana sobre el listado, como las demás fichas del sistema; en el prototipo es un panel lateral): Fecha, Periodo, Glosa, Origen (con el número del documento), Registrado por ("Sistema" en los automáticos), Estado ("Extornado por AS-…", con enlace) y, si es un extorno, **Extorno de** y el **Motivo**. Debajo, sus líneas: cuenta (con su nombre y nota), centro de costo / tercero, debe y haber, con la fila de **Totales**, y exportar al pie.
- **Extornar asiento** (con motivo): escribe el asiento inverso en el periodo abierto y deja el original como Extornado. Un extorno no se extorna.
- **Asiento manual**: fecha, glosa y cuatro líneas (cuenta, debe, haber, centro de costo), como el prototipo; además se agregan o quitan líneas y se ve la suma del debe y del haber con su diferencia. Debe cuadrar, usar cuentas imputables y caer en un periodo abierto.

### Mayor y balance de comprobación (`/ledger`)

- Filtros **Desde**, **Hasta** (periodos) y **Centro de costo**.
- **Balance de comprobación**: cuenta, tipo, debe, haber, saldo deudor, saldo acreedor y la fila de totales; abajo "El balance cuadra…" o "Diferencia… revisar". Con un centro de costo elegido se muestra una nota neutra (las líneas de un solo centro no tienen por qué cuadrar).
- Clic en una cuenta abre su **Mayor** encima: fecha, asiento (abre la ficha), glosa, debe, haber y **saldo corrido**, la más reciente primero, paginado; **Cerrar mayor**.
- Las dos tablas exportan al pie.

### Estados financieros (`/financial-statements`)

- Filtros **Desde** / **Hasta**. Cuatro indicadores: **Ingresos del rango**, **Gastos del rango**, **Resultado**, **Margen**.
- **Estado de resultados** (ventas 70, ingresos financieros 77, personal 62, servicios de terceros 63, otros gastos de gestión 65, financieros 67 y el resultado; si la empresa agrega cuentas fuera de esos grupos aparecen como "Otros ingresos / gastos") y **Estado de situación financiera** al cierre del rango, con la comprobación "Activo = Pasivo + Patrimonio".
- **Ingresos y gastos por mes** (últimos seis periodos): barras en el azul del logo (ingresos) y el negro cálido del logo (gastos), en vez del verde y naranja del prototipo.

### Centros de costo y presupuestos (`/budgets`)

- **Nuevo centro de costo** (código, nombre, tipo Gastos / Ingresos, responsable y **sede**). Selector de **Año**.
- Aviso del presupuesto del año: nombre, estado, quién y cuándo lo aprobó, y **Aprobar presupuesto** / **Reabrir para ajuste** (con motivo). Si el año aún no tiene presupuesto: **Crear presupuesto AAAA**.
- Cuatro indicadores: **Presupuesto de gastos**, **Gasto ejecutado**, **Comprometido en OC**, **Ingresos vs meta anual**.
- **Ejecución por centro de costo**: presupuesto anual, a la fecha, ejecutado (del libro diario), comprometido (órdenes de compra aprobadas aún sin facturar; "—" en los de ingresos) y avance con su barra (azul; ámbar sobre 85 %; rojo sobre 100 %; verde en los de ingresos) y "Sobre lo previsto a la fecha".
- Clic en un centro abre su **Detalle**: por cuenta, presupuesto anual, a la fecha, ejecutado y avance; **Ajustar** y **Agregar cuenta** mientras está en borrador (el monto anual se reparte en doce meses iguales; los céntimos van a diciembre), **Editar centro** y **Cerrar**. Aparecen también las cuentas con gasto sin presupuesto.
- Las dos tablas exportan al pie.
- **Control en Compras**: la ficha de la orden de compra muestra el presupuesto anual del centro, lo ejecutado, lo comprometido en otras órdenes y el disponible. Como en el prototipo, una orden que supera el disponible se **aprueba con advertencia** y la advertencia queda guardada en la orden y en su historial. Un presupuesto en borrador no controla.

### Plan de cuentas, reglas y periodos (`/chart-of-accounts`)

- Tres pestañas, como el prototipo.
- **Plan de cuentas**: el subconjunto del PCGE con sangría por nivel, tipo, naturaleza, imputable ("Sí" / "Título") y "con movimientos". Además: buscador, **Nueva cuenta** y clic en la fila para cambiar el nombre o desactivarla. Exportar al pie.
- **Reglas automáticas**: la tabla de la sección de arriba. Exportar al pie.
- **Periodos**: periodo, asientos, movimientos bancarios sin conciliar, estado y **Cerrar periodo** / **Reabrir** (con motivo). Exportar al pie.

### Compras

- La fila **Asiento** de la ficha del comprobante de proveedor muestra el número del asiento con enlace al libro diario (o "Se genera al aprobar para pago.").
- Las **cuentas de gasto** de órdenes y comprobantes salen ahora del plan de cuentas de la empresa (sus cuentas de gasto imputables y activas), no de la lista fija.
- El alta rápida de centros de costo sigue igual (nace como centro de gastos).

## Lo que la base garantiza

- Un asiento **cuadra** y tiene **al menos dos líneas** (se comprueba al cerrar la operación).
- Cada línea es debe **o** haber, con una cuenta **imputable y activa** del plan de la empresa.
- Las líneas se escriben **junto con su asiento** y después no cambian ni se borran.
- Un asiento **no se modifica**: solo pasa una vez a Extornado, con su extorno y su motivo. Un asiento se extorna una sola vez.
- Un **periodo cerrado** no admite asientos.
- Un documento tiene **un solo asiento vigente**.
- El código, el tipo y la naturaleza de una cuenta no cambian; una cuenta con movimientos sigue siendo imputable. El código de un centro de costo no cambia.
- Las líneas de un presupuesto solo cambian en **borrador**; un presupuesto por año.
- Nada de esto se borra; todo con aislamiento por empresa.

## Permisos y alcance

| Permiso | Qué permite | Administrador | Gerencia | Finanzas | Operaciones |
| --- | --- | --- | --- | --- | --- |
| `accounting.view` | Ver las cuatro pantallas contables | ✔ | ✔ | ✔ | |
| `accounting.create` | Asiento manual | ✔ | | ✔ | |
| `accounting.manage` | Crear y editar cuentas del plan | ✔ | | ✔ | |
| `accounting.approve` | Extornar, cerrar y reabrir periodos | ✔ | | ✔ | |
| `accounting.export` | Exportar | ✔ | ✔ | ✔ | |
| `budgets.view` | Ver centros de costo y presupuestos | ✔ | ✔ | ✔ | ✔ (su sede) |
| `budgets.manage` | Crear y editar centros, preparar y reabrir el presupuesto (ya existía: crear centros) | ✔ | | ✔ | |
| `budgets.approve` | Aprobar el presupuesto | ✔ | ✔ | | |
| `budgets.export` | Exportar | ✔ | ✔ | | |

Comparado con la matriz del prototipo, **ningún rol recibe de más**: Gerencia "ver y exportar" contabilidad y "ver, aprobar, exportar" presupuestos; Finanzas "ver, crear, editar, aprobar, exportar" contabilidad y "ver, crear, editar" presupuestos; Operaciones "ver" presupuestos de su sede. Lo único que se juntó: el prototipo separa "crear" (centro de costo) de "editar" (presupuesto) y aquí van en `budgets.manage`, porque ningún rol tiene uno sin el otro.

- **Alcance**: los presupuestos se ven por centro de costo (recurso nuevo "Centros de costo y presupuestos" en Roles y permisos): lo propio es lo que la persona tiene como responsable o creó; con "Sede", los centros de sus sedes. La contabilidad (libro, mayor, estados, plan) no lleva alcance: es de toda la empresa.
- Todo cuelga del módulo **Finanzas** del plan.

## Datos de prueba que quedaron en Tenant A

- **31 asientos**: 28 de los documentos que ya existían (orden `accounting:post-pending`), 1 de la factura de prueba **F001-00000003** (S/ 5,310, cliente Minera Prueba CRM, al centro de ingresos), el asiento manual **AS-2026-10-0026** (S/ 150, "Provisión de energía de prueba") y su extorno **AS-2026-10-0027**.
- Centros de costo: CC-OPS y CC-ADM pasaron a la Sede principal (CC-OPS con responsable Óscar Ramos); nuevos **CC-VTA** (ingresos, Sede principal) y **CC-NOR** (gastos, Sede norte).
- **Presupuesto 2026 aprobado por Gerencia** (se reabrió una vez con motivo y se volvió a aprobar): CC-OPS 24,200 (6343 12,000; 6394 6,000; 6563 5,000; 6311 1,200), CC-ADM 8,600, CC-NOR 4,000, CC-VTA 60,000 (meta de ventas).
- El periodo **2026-08** se cerró y se reabrió (motivo "Prueba de cierre y reapertura").

Indicadores cuadrados a mano:

- Libro diario: 31 asientos; periodo 2026-10: 27; automáticos 30 (todo menos el manual; el prototipo también cuenta el extorno como automático); Cuadra.
- Balance de comprobación: S/ 39,218.03 al debe y al haber. La 4212 queda con saldo acreedor S/ 11,950.24, **igual al saldo de Cuentas por pagar**; la 1212 con S/ 6,273.59 = saldo de Cuentas por cobrar (S/ 6,423.59) menos el cobro sin aplicar (S/ 150).
- Estados (enero–octubre): ingresos 8,250.50; gastos 12,893 (63: 8,493 + 65: 4,400); resultado −4,642.50; margen −56 %; activo 13,360.99 = pasivo 12,503.49 + patrimonio 5,500 − 4,642.50.
- Presupuestos: gastos S/ 36.8 k; ejecutado S/ 12.8 k (CC-OPS 9,988 + CC-ADM 2,800); comprometido S/ 480 (OC-2026-0001: 1,200 − 720 ya facturado); ingresos 4,500 de 60,000 = 8 %. CC-OPS 43 %, CC-ADM 33 %.
- Orden OC-2026-0001 → Control de presupuesto: anual 24,200, ejecutado 9,988, comprometido en otras OC 0, **disponible 14,212**.

## Cómo se probó

- **Pruebas automáticas** (`AccountingTest`, 7): asientos de ventas, cobros, reembolsos y notas de crédito (uno solo por documento); compras con centro de costo, pago con detracción, recibo con retención de 4.ª, caja chica y transferencia; asiento manual, extorno y lo que la base rechaza (editar, borrar, línea fuera de su operación, asiento descuadrado o con una línea); periodos (no se cierra el en curso ni con movimientos sin conciliar; un documento de un periodo cerrado se contabiliza hoy); presupuesto (crear, líneas, aprobar otra persona, control de la orden con advertencia, comprometido → ejecutado, reabrir); permisos, exportar, alcance "Sede" y otra empresa; plan de cuentas; la orden de documentos pendientes. Además, al final de las pruebas de ventas y compras se dispara la comprobación que la base hace al confirmar sobre todos los asientos automáticos. **214 pruebas pasan.**
- **En el navegador** a 1366 × 768, en oscuro y en claro, y a 375 px: con **Finanzas** (las cinco pantallas, asiento manual, extorno con y sin motivo, enlace al extorno, filtros por periodo y origen, ordenar por importe, exportar Excel y CSV del listado y de las líneas, mayor de 1212 con saldo corrido, filtro por centro de costo, cerrar y reabrir 2026-08, reabrir el presupuesto, ajustar y agregar cuenta, editar centro, validaciones del plan de cuentas); con **Gerencia** (aprobar el presupuesto, exportar la ejecución, candados en asiento manual, extornar, nueva cuenta y periodos, control de presupuesto de la orden); con **Operaciones** (solo ve "Centros de costo y presupuestos" y solo los tres centros de su sede, KPIs de su alcance, candados); con **Ventas** (no ve Contabilidad); con **Administrador** (matriz de Roles y permisos con las filas nuevas y el asiento manual a 375 px).
- Arreglado durante la prueba: la comprobación "el asiento cuadra" de la base tenía un error que solo aparece al confirmar (las pruebas no confirman); el comprometido fallaba con tipo de cambio registrado; ceros "−0.00" en los estados; las barras de avance quedaban sin ancho a 375 px; la fila de Contabilidad no tenía nombre en Roles y permisos.

**No visto con capturas** (la ventana del navegador de pruebas se ocultaba): algunas ventanas salieron a medio aparecer; el contenido se verificó leyendo la página. El Excel no se abrió en Excel (se comprobó que el archivo es un .xlsx válido).

**Probado solo con pruebas automáticas:** baja aceptada → extorno (sin proveedor SUNAT no hay bajas), diferencia de cambio de cobros y pagos en dólares (no hay ventas en dólares cobradas en tus datos), y que un documento con fecha en un periodo cerrado se contabilice hoy.

## Decisiones tomadas en esta tanda (sin consultarte)

1. Contabilidad cuelga del módulo **Finanzas** del plan, como Compras; no se creó un módulo nuevo.
2. Los asientos se escriben **en la misma operación** que el documento (el kit los propone después, por una cola de eventos que el sistema no tiene).
3. Cada empresa tiene **su propio plan de cuentas**, sembrado con el subconjunto del PCGE del prototipo más la **40172 Renta de cuarta categoría** (para la retención de los recibos por honorarios). Se pueden **agregar cuentas** (pantalla nueva respecto del prototipo, la propone el kit), cambiar el nombre y desactivarlas; el código y el tipo no cambian. Una cuenta que recibe subcuentas pasa a título si no tiene movimientos. Las cuentas que usan las reglas automáticas **no se desactivan ni se subdividen**.
4. La cuenta contable de un banco o caja sale de su campo "cuenta contable" (Bancos y caja) o, si está vacío, de su tipo y moneda.
5. El **saldo de apertura** de una cuenta va contra **5911 Resultados acumulados**.
6. Un cobro baja la 1212 completo, aunque una parte quede como anticipo (como el prototipo); el **abono por identificar** del extracto va a la 122.
7. La **diferencia de cambio** se contabiliza al aplicar un cobro en dólares (como el prototipo) y también al **pagar a un proveedor en dólares** (el prototipo no lo hacía).
8. Un **gasto de caja chica** toma la cuenta y el centro de costo que tenga escritos si existen en el plan; si no, va a **6563 Suministros**. (La caja chica sigue con texto libre: ver Pendientes.)
9. Una **baja aceptada** extorna sola el asiento del comprobante.
10. Las ventas van al **centro de ingresos de su sede** si la empresa lo creó (como el prototipo).
11. **Periodos**: solo se cierra uno ya terminado (el prototipo dejaba cerrar el mes en curso, lo que habría bloqueado toda la facturación); reabrir pide **motivo** (el prototipo solo lo auditaba).
12. Un **extorno no se extorna**.
13. Numeración por periodo, como el prototipo: **AS-AAAA-MM-0001**.
14. Orden **`accounting:post-pending`** para contabilizar lo registrado antes de Contabilidad; ya se corrió en Tenant A.
15. **Presupuesto**: uno por año; botón **Crear presupuesto AAAA** y selector de **Año** (el prototipo tenía uno ya creado); aprobar exige al menos un monto; reabrir con motivo; un presupuesto en borrador no controla las órdenes; el estado "Cerrado" (cierre de ejercicio) no se ofrece, como en la pantalla del prototipo.
16. **Control de presupuesto**: avisa y deja aprobar (la regla por defecto del prototipo); la advertencia queda en la orden y en su historial. Lo comprometido en dólares se lleva a soles al tipo de cambio vigente.
17. **Centros de costo**: tipo, sede y responsable; el código no cambia; se editan desde su detalle (**Editar centro**, nuevo). Alcance propio = responsable o quien lo creó.
18. Permisos como en la tabla de arriba (`budgets.manage` junta crear y editar).
19. **Exportar** va al pie de cada tabla (el prototipo tenía "Exportar CSV" arriba en Mayor y balance), y se exporta en Excel y CSV.
20. La ficha del asiento es una **ventana** (el prototipo usa un panel lateral), como las demás fichas.
21. Las **cuentas de gasto** de Compras salen del plan de cuentas.
22. Colores del gráfico: azul y negro cálido del logo en vez de verde y naranja.
23. Balance filtrado por centro de costo: nota neutra en vez de "Diferencia… revisar".
24. Asiento manual: además de las cuatro líneas del prototipo, agregar / quitar líneas y la suma con su diferencia.
25. Arreglo de paso en Facturación electrónica: la lista de bajas desempata por estado cuando dos bajas son del mismo segundo (una prueba fallaba al azar).

## Pendientes

- Contabilidad: editar las reglas automáticas por empresa; cierre de ejercicio (asiento de cierre y presupuesto "Cerrado"); libros electrónicos para SUNAT (PLE); planilla (cuentas 62); PDF del asiento; importar asientos.
- Caja chica: elegir centro de costo y cuenta de gasto de listas (hoy texto libre).
- Datos viejos de Tenant A: los cobros PAG-2026-0001…0004 no tienen cuenta en Bancos y caja (diferencia entre la 1041/1011 y Bancos y caja).
- Si se escribe la dirección de una pantalla sin permiso, la pantalla abre y la API no entrega nada ("No se pudo cargar…"); pasa en todas las pantallas del sistema, no solo aquí.

## Archivos

- API: migración `2026_10_14_000100_accounting_journal_periods_and_budgets.php` (ya corrida); `app/Modules/Accounting/` (`Support/Chart`, `Ledger`, `Posting`, `Budgets`; `Models/CostCenter`; controladores `JournalController`, `LedgerController`, `ChartController`, `BudgetController`); orden `app/Console/Commands/PostPendingEntries.php`; ganchos en `InvoiceController`, `PaymentController`, `TreasuryController`, `FiscalGateway`, `SupplierInvoiceController`, `PayableController`; `PurchaseOrderController` (presupuesto), `Purchasing` (cuentas del plan), `CostCenterController`; `AccessScope`, `CheckRecordVersion`, `ListExport`, `AuditCatalog`; `routes/api.php`; prueba `tests/Feature/AccountingTest.php`.
- Web: `components/dashboard/accounting/` (`Journal`, `Ledger`, `Statements`, `Budgets`, `ChartOfAccounts`), `lib/accounting.ts`, `types/accounting.ts`, páginas `/journal`, `/ledger`, `/financial-statements`, `/budgets`, `/chart-of-accounts`; `AppShell` (menú), `proxy.ts`, `ScopeNote`, `RolesPermissions` (nombres de las filas), `PurchaseModals` (fila Asiento).
