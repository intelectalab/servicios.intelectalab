# 2026-10-14 — Pendientes resueltos: permisos, carga, tablas, contratos, plantillas de checklist, contabilidad y correcciones

## Qué se pidió

"Resuelve lo que quedó pendiente; si es algo que todavía no respondo, pregúntame en el camino."

Antes de empezar se te preguntó lo que seguía sin respuesta. Respondiste:

| Pregunta | Respuesta |
|---|---|
| ¿Separar "pagar a proveedor" de "aprobar comprobantes" (Gerencia)? | Separar |
| ¿Tiempo límite de ~30 s en las lecturas, con Reintentar? | Sí |
| ¿Cabeceras de las tablas en dos líneas? | Sí, en todas |
| ¿Suspender o terminar un contrato arrastra sus servicios contratados? | Sí |
| ¿Retirar las rutas antiguas `/tasks`? | Desconectarlas (el código queda) |
| ¿Qué pendientes grandes hacer hoy? | Plantillas de checklist, Contabilidad pendiente, Tesorería y Compras menor |
| Plantillas de checklist (no están en el prototipo): ¿dónde? | Operaciones, pantalla propia con permisos propios |
| ¿Qué más llevan? | Servicio asociado, evidencia obligatoria, duplicar e importar desde Excel |

Además se hicieron dos arreglos pequeños que ya cubrían tus reglas: la pantalla abierta por su dirección sin permiso y el "+" de cada día de la Agenda con candado.

## Cómo funciona

### 1. Pagar a un proveedor es un permiso propio (`payables.pay`)

- **Aprobar** un comprobante de proveedor (`payables.approve`) ya no deja pagar. **Pagar** (registrar y revertir pagos) es `payables.pay`, y lo comprueba la API.
- Reparto: Administrador y Finanzas pagan. **Gerencia aprueba pero no paga**. Así quien aprueba el gasto no es quien saca el dinero.
- En Roles y permisos aparece la columna **Pagar**. "Pagar seleccionadas", "Registrar pago" y "Revertir pago" salen con candado y su motivo para quien no tiene el permiso.

### 2. Una lectura que no responde se corta a los 30 s

- Toda lectura (GET) que el servidor no contesta en 30 s se corta. La pantalla muestra su aviso "No se pudo cargar…" con **Reintentar**, en vez de quedarse cargando.
- **Los guardados no se cortan**: podrían haber entrado y quedar a medias.
- Si lo que no responde es la sesión al entrar (el menú en gris que reportaste), sale "No se pudo cargar tu sesión: el servidor no respondió" con Reintentar.

### 3. Pantalla abierta por su dirección sin permiso

- Antes, la pantalla abría y luego decía "No se pudo cargar…". Ahora dice **"Tu rol no tiene permiso para abrir esta pantalla"** (o "no está incluida en el plan de tu empresa"), con un botón a la primera pantalla que sí abre. Vale para todas las pantallas del menú.

### 4. Cabeceras de tabla en dos líneas

- En la tabla compartida, una cabecera larga baja a dos líneas en vez de ensanchar la tabla. El botón de opciones (⋮) de cada columna ya no ocupa ancho: va sobre el borde de la cabecera.
- A 1366 px, sin desplazamiento lateral: Órdenes de compra, Recurrencia, Comprobantes de proveedor y Cuentas por pagar (medido).
- **Cobros sigue con 107 px de más.** No es por la cabecera: sus celdas son anchas (el número del recibo con su detalle, el monto con "− S/ 200.00 reembolsado" y los cuatro botones de Acciones). Arreglarlo es tocar las celdas de esa pantalla, que no se pidió. Queda propuesto.

### 5. El contrato arrastra a sus servicios contratados

- **Suspender** el contrato suspende sus servicios contratados activos y su cobro recurrente. El motivo queda en cada servicio y en su historial.
- **Reactivar** el contrato reactiva solo los servicios que se suspendieron con él. Un servicio que Operaciones había suspendido por su cuenta sigue suspendido.
- **Terminar** cancela sus servicios abiertos. **Marcar vencido** completa los que estaban en marcha y cancela los que no empezaron.
- No se puede terminar ni marcar vencido un contrato si alguno de sus servicios tiene órdenes de trabajo abiertas. El mensaje dice cuál.
- Las ventanas de confirmación del contrato explican esto antes de confirmar.
- Quien tenga abierta la ficha de un servicio que cambió con el contrato recibe el aviso de que otra persona lo modificó: no lo pisa.

### 6. Rutas antiguas `/tasks` desconectadas

- `GET/POST /tasks` y el checklist de tareas sueltas ya no están en la API. Las tareas se trabajan solo dentro de su proyecto. El controlador sigue en el repo.

### 7. "+" de cada día de la Agenda

- Sin permiso de agendar, el "+" ya no se esconde: sale con candado y "Tu rol no tiene permiso agenda.create".

### 8. Plantillas de checklist (Operaciones)

**No están en el prototipo:** solo trae dos plantillas de ejemplo en sus datos. Se construyó con lo que elegiste. Va en el menú de Operaciones, debajo de Órdenes de trabajo (`/checklist-templates`).

- **Indicadores:** plantillas activas (con cuántas inactivas y cuántas con servicio), ítems obligatorios de las activas, plantillas con evidencia obligatoria y órdenes de trabajo abiertas que usan una (a tu alcance).
- **Listado** con buscador (plantilla, ítem o servicio), filtros de Estado y Servicio (desplegable con buscador), filtros por columna, orden en el servidor, casillas, exportar al pie y clic en la fila para abrir la ficha.
- **Ficha:** datos, uso ("copiada en N órdenes de trabajo, M abiertas") y los ítems en una tabla con exportar al pie. Tiene **Editar**, **Duplicar** (crea "Nombre (copia)") y **Desactivar/Reactivar**, más su historial.
- **Formulario:** nombre, servicio asociado, descripción e ítems. Cada ítem lleva texto, *Obligatorio* y *Evidencia obligatoria*, y se puede subir, bajar y quitar. Enter en el último agrega otro. Admite hasta 100 ítems.
- **Importar** con tu ImportModal y la plantilla Excel con formato (`plantilla_checklists.xlsx`). Va una fila por ítem, y las filas con el mismo nombre forman una plantilla. Una plantilla con un nombre que ya existe no se importa, y se avisa por fila.
- **Servicio asociado:** al crear una OT de ese servicio, el formulario **propone la plantilla sola** ("Propuesta por el servicio") y se puede cambiar.
- **Evidencia obligatoria:** la OT copia la marca. Ese ítem **no se marca a mano**: queda hecho al adjuntar su foto en la App del técnico. En la ficha de la OT sale "Foto obligatoria" y su casilla deshabilitada con el motivo. En la App del técnico la casilla queda bloqueada con "Foto obligatoria: tómala con la cámara para marcarlo", y el botón general "Adjuntar foto de evidencia" va primero a ese ítem.
- **Cambiar una plantilla no toca las órdenes que ya la usan**: cada OT guarda su copia. El formulario lo avisa.
- **Permisos propios**, con el reparto de "Entregables y checklists" del prototipo: `checklists.view`, `checklists.manage` y `checklists.export`. Operaciones ve y gestiona. Gerencia solo ve. El Administrador tiene todo. El Técnico no la ve, porque es configuración: él solo usa las plantillas dentro de sus órdenes.

### 9. Contabilidad

**Cierre de ejercicio** (Plan de cuentas, reglas y periodos → pestaña Periodos → tarjeta nueva **Ejercicios**):
- Cada año muestra sus periodos cerrados, su resultado (utilidad o pérdida), su asiento de cierre y su estado, con exportar al pie.
- **Cerrar ejercicio** registra al **31 de diciembre** el asiento de cierre. Cada cuenta de ingreso y gasto con saldo se salda, y la diferencia va a **5911 Utilidades acumuladas** o a **5921 Pérdidas acumuladas** (cuenta nueva del plan). Además cierra diciembre y pasa a **Cerrado** el presupuesto aprobado del año.
- Solo se cierra un año **terminado**, **después del anterior** y con **enero a noviembre cerrados**. También pide diciembre abierto y su conciliación bancaria al día. Mientras no se puede, el botón sale deshabilitado con el motivo.
- **Reabrir** (con motivo, solo el último cerrado): extorna el asiento de cierre el mismo 31 de diciembre y reabre diciembre y el presupuesto.
- El asiento de cierre **no se extorna desde el Libro diario**: se deshace reabriendo el ejercicio.
- El **estado de resultados**, su gráfico y lo **ejecutado del presupuesto** no cuentan el asiento de cierre ni su extorno. Así el resultado del año se sigue viendo después de cerrarlo.
- En la situación financiera, "Resultados acumulados" suma lo que los cierres llevaron al patrimonio más lo aún no cerrado. "Capital" se queda con lo demás (aportes y aperturas): **tus números de hoy se ven igual que antes**.

**"Cerrar ejercicio" del presupuesto** (Centros de costo y presupuestos), como en el prototipo:
- Lo hace quien aprueba presupuestos, sobre un presupuesto aprobado de un año terminado. Lo deja cerrado (solo consulta), sin asiento: el asiento lo registra Contabilidad al cerrar el ejercicio.

**PDF del asiento:** en la ficha del asiento, **Descargar PDF**, con la composición de los demás documentos: datos, líneas, totales, firmas y marca de agua EXTORNADO si corresponde.

**Importar asientos** (Libro diario → **Importar**):
- Usa tu ImportModal con la plantilla Excel con formato (`plantilla_asientos.xlsx`). Va una fila por línea, y las filas con el mismo número de "asiento" forman uno.
- Cada asiento se revisa entero: que cuadre, que use cuentas de detalle activas, que su periodo esté abierto, que su fecha no sea futura y que el centro de costo exista. El que tiene un error no entra y se avisa; los demás quedan contabilizados con origen **Importado**, cada uno con su registro en auditoría.

### 10. Tesorería y Compras

**Corregir el centro o la cuenta de un gasto de caja** (Bancos y caja → Caja chica):
- Clic en la fila abre la ficha del gasto, con **Corregir centro o cuenta**. Requiere `treasury.manage` y pide el motivo.
- La corrección queda registrada con lo de antes, lo de después y el motivo. El asiento del gasto se **extorna** y se **contabiliza de nuevo** con lo correcto, en el periodo del gasto si sigue abierto (si no, hoy). El importe, la fecha y la caja no cambian.
- **La base** solo deja cambiar la clasificación si la corrección quedó registrada en la misma operación. Las correcciones no se borran.

**Revertir un pago a proveedor** (Cuentas por pagar → Pagos a proveedores):
- Clic en la fila abre la **ficha del pago**: comprobantes pagados (con enlace a cada uno), movimientos de banco o caja, asiento e historial.
- **Revertir pago** (con `payables.pay` y motivo): cada cuenta por pagar recupera lo pagado con un movimiento "Pago revertido", el dinero vuelve a la cuenta con un movimiento "Reversión de pago" y el asiento se extorna. El pago queda **Revertido**: no se borra, y en la tabla lleva su etiqueta.
- **Un pago que incluyó el depósito de la detracción no se revierte** (la constancia es de SUNAT). La base tampoco lo deja.

**Tolerancia del cruce de 3 vías** (Roles y permisos → Reglas de la empresa, junto a las otras dos reglas):
- Va de 0 % a 10 %. Mientras no se cambie es 2 %. Requiere `tenant.manage` y queda en auditoría.

## Qué se cambió

**API**
- `routes/api.php`: rutas de pagar (`payables.pay`), plantillas de checklist, ejercicios, cierre de presupuesto, plantilla e importación de asientos, corrección de gasto de caja, ficha y reversión de pago. Se quitaron las rutas `/tasks`.
- `Operations/Http/Controllers/ChecklistController.php` (reescrito), `Models/Checklist.php`, `ChecklistItem.php`, `WorkOrderChecklistItem.php`, `WorkOrderController.php` (copia la evidencia, opciones con servicio, no marca sin foto), `FieldController.php`, `ContractedServiceController.php` y `Models/ContractedService.php` (marca "suspendido con el contrato").
- `Services/Http/Controllers/ContractController.php`: los servicios contratados siguen al contrato.
- `Accounting`: `FiscalYearController.php` (nuevo), `JournalController.php` (importar y origen Importado), `LedgerController.php`, `BudgetController.php` (cerrar), `Support/Ledger.php` (`withoutYearClose`, orígenes nuevos), `Support/Budgets.php` y `Support/Chart.php` (5921).
- `Finance/Http/Controllers/TreasuryController.php` (corregir gasto) y `Finance/Support/Treasury.php`.
- `Purchasing/Http/Controllers/PayableController.php` (ficha y reversión de pago) y `Purchasing/Support/Purchasing.php` (la tolerancia de la empresa).
- `Core/Http/Controllers/TenantSettingsController.php` (`match_tolerance`), `Services/AuditCatalog.php` (textos nuevos), `Http/Middleware/ListExport.php` y `CheckRecordVersion.php`.
- Seeders `LocalFinanceSeeder` y `LocalOperationsSeeder`, con los permisos nuevos.

**Web**
- `lib/api.ts` (tiempo límite), `components/layout/AppShell.tsx` (sesión con Reintentar, pantalla sin permiso, menú) y `components/ui/proto.tsx` (cabeceras).
- `operations/ChecklistTemplates.tsx` (nuevo) y su página, `WorkOrderModals.tsx`, `WorkOrderDetail.tsx`, `FieldApp.tsx`, `Agenda.tsx` y `proxy.ts`.
- `accounting/Journal.tsx`, `ChartOfAccounts.tsx` y `Budgets.tsx`.
- `finance/Banks.tsx` y `BankModals.tsx`.
- `purchasing/Payables.tsx` y `PurchaseModals.tsx`.
- `contracts/ContractDetail.tsx` y `admin/RolesPermissions.tsx`.
- Tipos y `lib/purchasing.ts`.

## Qué NO se tocó

- Tus módulos y pantallas fuera de lo listado.
- El `TaskController` (solo se desconectaron sus rutas) y los datos de las tareas antiguas.
- La configuración de PHP.

## Diferencias con el prototipo

- **Plantillas de checklist**: el prototipo no tiene esta pantalla. Se hizo con las opciones que elegiste.
- **Pagar** es un permiso aparte. En el prototipo, Gerencia aprobaba y pagaba.
- **Cobros** sigue con desplazamiento lateral a 1366 px (ver punto 4).
- La ficha del gasto de caja y la del pago a proveedor no existen en el prototipo. Se agregaron porque son el lugar natural de corregir y revertir, y abren con el clic en la fila (tu regla).

## Base de datos

Migraciones nuevas, **ya corridas** en tu base local. Todas suman reglas o columnas y ninguna borra datos:

- `2026_10_14_000300_separate_supplier_payment_permission`: permiso `payables.pay` y su reparto.
- `2026_10_14_000400_contract_cascades_to_contracted_services`: marca `suspended_by_contract`.
- `2026_10_14_000500_checklist_templates_screen`: servicio, versión y creador en la plantilla; evidencia obligatoria; ítems retirados en vez de borrados; permisos `checklists.*`.
  - Cambia el índice único de posición de los ítems por uno parcial (solo los vigentes).
  - La base ya no deja borrar plantillas ni ítems.
- `2026_10_14_000600_accounting_year_close`: tabla `acc_fiscal_years`, orígenes `YEAR_CLOSE` e `IMPORT` y cuenta 5921.
- `2026_10_14_000700_cash_reclassification_and_supplier_payment_reversal`: correcciones de gasto de caja, reversión de pago y tipos de movimiento nuevos.

## Cómo se probó

- **Pruebas automáticas: 226 pasan**, 11 de ellas nuevas:
  - `OperationsChecklistTemplatesTest`: alta, edición, base, duplicar, desactivar, importar la propia plantilla, evidencia, servicio y permisos.
  - `AccountingYearCloseTest`: cierre con utilidad y con pérdida, reapertura, presupuesto e importación de asientos con la propia plantilla.
  - `TreasuryAndPayablesCorrectionsTest`.
  - Una más en `OperationsTest` (el contrato arrastra a sus servicios) y otra en `PurchasingTest` (aprobar no da para pagar).
  - Una prueba antigua de reembolsos (`FinanceTest`) falló una vez y pasó tres veces seguidas: es intermitente por empate de segundo, no por estos cambios.
- **En el navegador (1366 × 768):**
  - **Operaciones:** Plantillas de checklist en claro, oscuro y a 375 px. Se descargó la plantilla Excel y se subió ese mismo archivo (2 plantillas, 6 ítems), se editó para asociar un servicio y la OT nueva propuso la plantilla sola. La foto obligatoria quedó bloqueada en la ficha de la OT y en la App del técnico ("Ver como" Tomás), y la foto la marcó.
  - **Gerencia:** candados en Pagar seleccionadas, Revertir pago, Cerrar ejercicio (2026 no termina), Importar y Nueva plantilla y "+" de Agenda. Tiempo límite: la lectura simulada sin respuesta se cortó a los 30 s con Reintentar, y Reintentar volvió a cargar.
  - **Finanzas:**
    - Pantalla sin permiso por su dirección.
    - Corrección de CCH-2026-0001 (de "Ventas / Movilidad" a CC-VTA / 6311), con extorno AS-2026-10-0031 y asiento nuevo AS-2026-10-0032.
    - Pago de prueba PP-2026-0004 revertido.
    - Plantilla de asientos descargada y subida tal cual (AS-2026-10-0036 y 0037, luego extornados).
    - PDF del asiento revisado.
    - Tarjeta Ejercicios (2026: pérdida S/ 4,757.85, que cuadra con Estados financieros).
  - **Administrador:** columna Pagar en la matriz, fila Plantillas de checklist, y tolerancia guardada en 2.5 % y devuelta a 2 %.
- **Indicadores cuadrados a mano:**
  - Plantillas: 3 activas; 5 ítems obligatorios (1 + 2 + 2); 2 con evidencia; 2 OT abiertas (OT-0004 y OT-0006).
  - Situación financiera: 13,345.64 = 12,603.49 + 5,500 − 4,757.85.
- **Cómo se ven las plantillas Excel:** se revisaron convertidas a HTML, no abiertas en Excel.

## Datos de prueba que quedaron

- Plantillas "Mantenimiento preventivo" (asociada a SRV-002, con evidencia obligatoria) y "Cierre de proyecto", importadas con la plantilla Excel.
- OT-2026-0006 "Mantenimiento preventivo mensual (prueba de plantilla)", en ejecución, asignada a Tomás y con la foto de "Medición de parámetros".
- CCH-2026-0001 corregido (asientos AS-2026-10-0017 extornado por 0031 y nuevo 0032).
- Comprobante de proveedor F001-9001 de "Actor de Prueba S.A.C." (S/ 118) y su pago PP-2026-0004 revertido (asientos 0033, 0034 y 0035): su cuenta por pagar quedó **pendiente** con S/ 118.
- Asientos importados AS-2026-10-0036 y 0037, extornados por 0038 y 0039 ("Prueba de importación con la plantilla: se deshace").

## Pendiente

- **Lo que no se probó en el navegador:**
  - El cierre real de un ejercicio: tu único año es 2026 y aún no termina. Solo se probó con pruebas automáticas, que adelantan el reloj a 2027.
  - La cascada del contrato: no se suspendió ninguno de tus contratos, porque tienen OT y servicios en uso. Solo con pruebas automáticas.
  - El aviso "No se pudo cargar tu sesión": requiere que falle la carga de la sesión al entrar, y eso no se puede simular sin recargar la página.
- Cobros a 1366 px (ver punto 4): propuesto.
- Las cuentas del asiento de cierre (5911 / 5921) son una decisión mía: valídalas con tu contador junto con las demás reglas.
- Revertir un pago con detracción, nota de crédito de proveedor, pagos parciales y programados, consulta de validez SUNAT: siguen pendientes.

## Decisiones tomadas sin preguntarte

1. Gerencia **no** recibe `payables.pay`. Quien tenía `payables.approve` (Administrador, Finanzas) lo conserva.
2. Terminar un contrato **cancela** sus servicios; marcarlo vencido los **completa** (y cancela los que no empezaron). Los dos se bloquean con OT abiertas.
3. Plantillas: el Técnico no ve la pantalla, Gerencia solo ve (como "Entregables y checklists" del prototipo), y quien gestionaba plantillas por la API (`operations.manage`) conserva ver y gestionar.
4. Plantillas: el nombre no se repite en la empresa; los ítems no se editan ni se borran, se retiran al editar; la plantilla se desactiva, no se borra.
5. Un ítem con evidencia obligatoria se marca solo al adjuntar su foto (la evidencia es una foto, como ya era).
6. El asiento de cierre usa 5911 (utilidad) y 5921 (pérdida), sin pasar por la 89.
7. Para cerrar un ejercicio se exige enero a noviembre cerrados; diciembre lo cierra la misma operación.
8. El presupuesto se cierra solo con el ejercicio o con su botón; cerrado, no se reabre para ajuste salvo reabriendo el ejercicio.
9. Los asientos importados tienen origen propio, "Importado" (no cuentan como automáticos).
10. El PDF del asiento lo descarga quien ve el asiento (como los demás PDF), sin permiso de exportar.
11. Corregir un gasto de caja pide `treasury.manage` (quien concilia), no `treasury.create`.
12. Un pago con depósito de detracción no se revierte.
13. La tolerancia del cruce va en las Reglas de la empresa de Roles y permisos, mientras no exista Configuración.
14. Gasto de caja y pago a proveedor tienen ficha nueva (clic en la fila), para corregir y revertir.
