# 2026-10-13 — Finanzas y CxC: Por facturar, Comprobantes, Cuentas por cobrar y Cobros

## Qué se pidió

"Módulo Finanzas y CxC. Especificación: docs/especificaciones/ del kit."

Referencias: prototipo (`fuente/js/ui/views/finance.js`: `events`, `invoices`, `invoiceDetail`, `receivables`, `payments`; `services/finance.js`; `core/rbac.js`) y kit (`docs/especificaciones/08-finanzas-cxc.md`, reglas RN-FIN-01 a 12).

No se te consultó antes de empezar. Lo decidido sin preguntarte está en "Decisiones tomadas en esta tanda".

Se hizo la **fase 1 del kit**: las cuatro pantallas del flujo evento → comprobante → cuenta por cobrar → cobro. Quedan para la siguiente tanda **Recurrencia**, **Facturación electrónica** (con detracciones y retenciones) y **Bancos y caja**.

## Cómo funciona

### Por facturar (`/billable-events`)

- Listado de **eventos facturables**: lo que ya se puede cobrar. Columnas del prototipo: Evento (con su origen y su clave), Cliente, Fecha, Base, Total c/IGV y Estado (con el comprobante que lo facturó).
- **De dónde salen:** al **completar una orden de trabajo** de un servicio que se cobra **Por evento** nace su evento, por el precio pactado en el servicio contratado. Uno por orden: completarla otra vez no crea otro (lo impide también la base). Además, **Nuevo evento** permite a Finanzas registrar uno a mano (cliente, contrato opcional, descripción, importe sin IGV y moneda).
- **Facturar:** se marcan los eventos y la barra de arriba muestra cuántos son, su total y su cliente, con **Generar factura** y **Generar boleta**, como el prototipo. Solo se facturan juntos eventos **por facturar, del mismo cliente y de la misma moneda**; si no, los botones se apagan y dicen por qué. La factura exige el RUC del cliente.
- Un clic en la fila abre la ficha del evento, desde donde se **anula** (pide motivo). Un evento facturado no se anula.

### Comprobantes (`/invoices` y `/invoices/{id}`)

- Listado con las pestañas del prototipo (Todos, Borradores, Emitidos, Rechazados SUNAT, Corregidos, cada una con su conteo), buscador, filtro por Tipo y columnas Comprobante, Cliente, Emisión, Total, SUNAT y Estado.
- **Ficha** con la disposición del prototipo (dos columnas, la izquierda más ancha) en tarjetas movibles: Detalle (líneas y totales; en dólares, el tipo de cambio y su equivalente), Emisión electrónica, Estados, Cuenta por cobrar con sus movimientos, Pagos aplicados, Corregido por / Corrige a, e Historial.
- **Emitir comprobante:** asigna serie y correlativo (`F001-00000001`, `B001-…`), fija la fecha y los datos del cliente a ese momento y crea su cuenta por cobrar. **Desde ahí no se modifica.**
- **Emitir nota de crédito** (pide motivo): crea el documento correctivo (`FC01-…` para facturas, `BC01-…` para boletas), deja el original en "Corregido" y anula su cuenta por cobrar. El original no se toca.
- **Descartar borrador:** el borrador queda "Descartado" y sus eventos vuelven a quedar por facturar.
- **Vista previa PDF / Descargar PDF**, con la composición de tus otros documentos.
- **Sin envío a SUNAT:** no hay proveedor de facturación electrónica conectado, así que todo comprobante queda **"Sin enviar"** y el sistema lo dice al emitir, en la ficha y en el PDF ("Documento interno"). **XML / CDR** aparece y avisa que aún no está disponible. "Reintentar envío SUNAT" y "Comunicación de baja" solo aparecen con un comprobante rechazado o aceptado: hoy ninguno llega ahí.

### Cuentas por cobrar (`/receivables`)

- Arriba, las dos tarjetas del prototipo: **Antigüedad del saldo** (Por vencer, 1–30, 31–60, 61–90 y > 90 días) y **Resumen** (saldo total pendiente en soles, vencido, documentos abiertos y clientes con deuda vencida).
- Listado: Comprobante, Cliente, Vence (con su tramo si está vencida), Original, Detracción / retención, Saldo y Estado. Filtros por Estado (con "Vencida") y por Antigüedad.
- **"Vencida" no es un estado**: se calcula por la fecha de vencimiento y el saldo. Una cuenta pagada deja de estar vencida.
- Un clic en la fila abre la ficha con **sus movimientos** (cargo, abonos, reversiones, nota de crédito): de ahí sale el saldo.
- **Registrar pago** abre el mismo formulario que Cobros. **Enviar recordatorios de cobranza** aparece y avisa que llegará con Notificaciones.

### Cobros (`/payments`)

- Listado: Recibo (con método, n.º de operación y origen), Cliente, Fecha, Monto (con lo reembolsado), Aplicado, Cuenta, Estado (con "Conciliado") y Acciones. Filtros por Estado y Conciliación. Debajo, la tarjeta **Reembolsos**.
- **Registrar pago:** Cliente, Monto, Moneda, Cuenta de destino, Método, N.º de operación, Fecha y "Aplicar automáticamente a las CxC más antiguas", como el prototipo. Lo que no se aplica queda como **anticipo**.
- **Aplicar:** reparte el saldo libre entre las cuentas pendientes del cliente **de la misma moneda**, la que vence antes primero. Un cobro puede cubrir varias cuentas.
- **Conciliar** con el extracto bancario (una sola vez).
- **Reembolsar** todo o parte (pide motivo): sale primero de lo que no estaba aplicado; si no alcanza, revierte aplicaciones y esas cuentas vuelven a tener saldo. **El cobro nunca se borra.**
- **Recibo** en PDF. Un clic en la fila abre la ficha del cobro (datos, aplicaciones, reembolsos e historial).

En las cuatro pantallas: ordenar, filtrar por columna y exportar se hacen en el servidor sobre todo el listado; clic en la fila abre la ficha; "/" lleva al buscador; lo principal se pide primero y los conteos, resúmenes y listas después.

### Lo que la base garantiza por sí sola

- Un origen (una orden de trabajo) da **un solo evento facturable**.
- **Un comprobante emitido no se modifica**, ni sus líneas (disparadores): ni importes, ni cliente, ni número, ni fecha. Tampoco se borra.
- Serie y correlativo **no se repiten**; una emisión bloquea su serie hasta terminar.
- **El saldo de una cuenta por cobrar es la suma de sus movimientos.** Nadie lo escribe a mano: cada movimiento lo actualiza (y el estado, y el saldo del comprobante). No baja de cero ni supera el importe original. Los movimientos no se editan ni se borran.
- Un comprobante se corrige **una sola vez**. Anular un evento y reembolsar llevan motivo. No se reembolsa más de lo cobrado. Cobros, reembolsos y cuentas por cobrar no se borran.

### Permisos y alcance

| Permiso | Qué permite |
| --- | --- |
| `billing.view` | Ver eventos facturables y comprobantes |
| `billing.create` | Registrar un evento y generar el borrador |
| `billing.manage` | Anular un evento y descartar un borrador |
| `billing.approve` | Emitir, nota de crédito y reintentar el envío |
| `billing.export` | Exportar eventos y comprobantes |
| `receivables.view` / `.manage` / `.export` | Ver cuentas por cobrar / recordatorios / exportar |
| `payments.view` / `.create` | Ver cobros / registrar un cobro |
| `payments.manage` | Aplicar un cobro |
| `payments.approve` | Conciliar y reembolsar |
| `payments.export` | Exportar cobros |
| `fiscal.view` / `fiscal.manage` | Ver / registrar el tipo de cambio (**antes era `billing.view` / `billing.manage`**) |

Todos cuelgan del módulo **Finanzas** del plan.

**Tipo de cambio ahora tiene su permiso.** Hasta hoy la pantalla Monedas y tipo de cambio se abría con `billing.view`. Al darle a Operaciones "ver comprobantes" (como el prototipo) habría recibido de más esa pantalla, así que se separó en `fiscal.*` (el recurso que usa el prototipo). Quien ya la veía o registraba tipos de cambio lo conserva.

**Reparto, comparado con la matriz del prototipo:**

| Rol | Facturación | Cuentas por cobrar | Cobros | Tipo de cambio | Alcance |
| --- | --- | --- | --- | --- | --- |
| Administrador | Todo | Todo | Todo | Ver, Gestionar | Toda la empresa |
| Gerencia | Ver, Aprobar, Exportar | Ver, Exportar | Ver, Exportar | Ver | Toda la empresa |
| Ventas | — | Ver | — | — | Propios |
| Operaciones (prueba) | Ver | — | — | — | Sede |
| Finanzas (prueba, nuevo) | Todo | Todo | Todo | Ver, Gestionar | Toda la empresa |
| Técnico (prueba) | — | — | — | — | — |

- Es el reparto del prototipo. **Gerencia gana** ver Monedas y tipo de cambio (el prototipo se lo da; antes no la veía) y **puede emitir comprobantes y notas de crédito** (el prototipo le da "Aprobar" en facturación).
- **De menos respecto del prototipo:** al rol Finanzas de prueba no se le dio editar Clientes y actores (el prototipo sí); solo los ve.
- **Alcance** (tres recursos nuevos en Roles y permisos): un evento o comprobante es "propio" de quien vendió el contrato que se factura y de quien lo preparó; una cuenta por cobrar, de quien vendió; un cobro, de quien lo registró. Heredan la sede del contrato o de la orden de trabajo.

## Qué se cambió

### API (`apps/api`)

- **Migración** `2026_10_13_000600_finance_billing_receivables_and_payments.php` (ver "Base de datos").
- **Modelos** (`app/Modules/Finance/Models`): nuevos `BillableEvent`, `ReceivableMovement`, `Refund`; `Invoice`, `Receivable` y `Payment` ahora filtran por alcance (`ScopedByAccess`); `InvoiceItem` y `PaymentAllocation` con sus campos nuevos.
- **`Support/Billing.php`**: IGV, series y correlativos, datos fiscales del cliente y el evento que nace al completar una orden de trabajo.
- **Controladores**: `BillableEventController` (nuevo), `InvoiceController` y `PaymentController` (rehechos), `ReceivableController` (nuevo). Rutas en `routes/api.php`.
- `WorkOrderController`: al completar la orden llama a `Billing::eventFromWorkOrder` y la ficha entrega su evento facturable.
- **Vista del cliente** (`PartyController`): sus comprobantes y su saldo se leían directo de las tablas, sin alcance. Ahora salen de sus modelos, así que cada rol ve ahí solo lo que alcanza; el saldo pide `receivables.view` (antes `billing.view`) y los borradores descartados no se listan.
- `AccessScope` (recursos `billing`, `receivables`, `payments`), `CheckRecordVersion` (eventos, comprobantes y cobros), `ListExport` (los cuatro listados) y `AuditCatalog` (textos de cada acción nueva).
- **Pruebas**: `tests/Feature/FinanceTest.php` (13). Se ajustaron `AccessScopeTest` (lista de recursos con alcance), `ComercialTest` (tipo de cambio pide `fiscal.*`) y `OperationsFixtures`.
- `database/seeders/LocalFinanceSeeder.php`: rol y cuenta de prueba de Finanzas (solo local).

### Web (`apps/web`)

- Pantallas en `components/dashboard/finance/`: `BillableEvents.tsx`, `InvoicesList.tsx`, `InvoiceDetail.tsx`, `Receivables.tsx`, `Payments.tsx` y `PaymentModals.tsx` (registrar, aplicar, reembolsar).
- `lib/finance.ts` (estados, textos y los dos PDF) y `types/finance.ts`.
- Rutas `app/(dashboard)/billable-events`, `invoices`, `invoices/[id]`, `receivables`, `payments`; añadidas a `proxy.ts` y al menú (`AppShell.tsx`: Por facturar, Comprobantes, Cuentas por cobrar y Cobros dejan de estar "próximamente").
- `ExchangeRates.tsx` y su ítem del menú piden `fiscal.*`. `RolesPermissions.tsx` y `ScopeNote.tsx`: nombres y avisos de los recursos nuevos. `lib/api.ts`: las altas nuevas llevan su clave contra envíos repetidos.
- `WorkOrderDetail.tsx`: "Evento facturable" muestra el estado y el importe del evento (antes decía siempre "Ninguno").
- `ActorDetail.tsx`: en la pestaña Finanzas del cliente, un clic en un comprobante abre su ficha.

## Qué NO se tocó

- Tus tablas `invoices`, `invoice_items`, `receivables`, `payments`, `payment_allocations` y `refunds` se conservan con sus datos: solo se les añadieron columnas y reglas. Tu comprobante `FAC-2026-0001` y tus cobros `PAG-2026-0001` y `0002` siguen ahí (ahora con sus movimientos y como "Aplicado").
- La pantalla Monedas y tipo de cambio (solo cambió el permiso que pide).
- Las tablas `fiscal_documents`, `fiscal_notes`, `recurring_service_*` y `financial_accounts`: sin cambios (son de las pantallas que siguen).
- El resto de Clientes y actores (de su vista del cliente solo cambió de dónde lee comprobantes y saldo).

## Diferencias con el prototipo

- **Sin la parte de demo:** las notas bajo cada título y el aviso "[NECESARIO · CHG-007]" no se trajeron.
- **Añadido:** "Nuevo evento", "Descartar borrador", exportar, filtros por columna, la ficha del evento, de la cuenta por cobrar y del cobro (un clic en la fila), la tarjeta Estados del comprobante y el aviso de alcance.
- **A la vista pero sin función todavía:** XML / CDR (proveedor de facturación electrónica), Enviar recordatorios de cobranza (Notificaciones), la columna Detracción / retención (siempre "—") y Cuenta de destino (sin cuentas hasta Bancos y caja).
- **No aparece hoy:** las tarjetas de Detracción / Retención de la ficha y los botones "Comunicación de baja" y "Reintentar envío SUNAT" (dependen de que SUNAT acepte o rechace).
- En la barra de selección de Por facturar las casillas sirven a la vez para facturar y para exportar (en el prototipo solo se marcan los pendientes).
- El comprobante dice "Sin enviar" en vez de simular el envío.
- La pantalla se titula "Pagos" y sus botones dicen "Registrar pago", como el prototipo; en tu menú el ítem ya se llamaba "Cobros" y así quedó.
- Junto al documento del cliente dice "RUC" si son 11 dígitos y "Doc." si no (el prototipo pone siempre "RUC").

## Base de datos

`2026_10_13_000600_finance_billing_receivables_and_payments.php` — **ya corrida** en tu base local (con el usuario dueño). Solo añade; no borra datos.

- Tablas nuevas: `billable_events` y `receivable_movements` (con su regla de aislamiento por empresa).
- Columnas nuevas en `invoices`, `invoice_items`, `receivables`, `payments`, `payment_allocations` y `refunds`.
- `invoices.invoice_number` e `issue_date` admiten vacío (un borrador no tiene número ni fecha).
- Disparadores: comprobante emitido inmutable, saldo por movimientos.
- A la aplicación se le quitó el permiso de **borrar** comprobantes, cuentas por cobrar, cobros y eventos, y de editar o borrar reembolsos y movimientos.
- Permisos nuevos y su reparto; `module_catalog` (Finanzas); alcance (`role_scopes`) admite `billing`, `receivables` y `payments`.
- Se reconstruyeron los movimientos de tu cuenta por cobrar existente (su cargo y sus dos abonos).

Después de escribirla se añadió que la hora de cada movimiento sea la real y no la del inicio de la operación (`clock_timestamp()`); en tu base se aplicó con un `ALTER` aparte y la migración ya lo trae.

Para la cuenta de prueba: `php artisan db:seed --class=LocalFinanceSeeder` (**ya corrido**).

## Cómo se probó

**Automáticas:** `php artisan test` → **172 pruebas pasan** (159 + 13 nuevas). Las nuevas cubren: un evento por orden de trabajo; evento manual y anulación; borrador con eventos de un cliente y una moneda, y que un evento no se factura dos veces; factura con RUC y boleta sin él; emisión, correlativo e inmutabilidad (intentando modificar y borrar directamente en la base); dólares y tipo de cambio; aplicación a la cuenta más antigua; antigüedad; reembolsos; nota de crédito; cada permiso y exportar; módulo fuera del plan; alcance por rol y otra empresa.

**Web:** `tsc` y `eslint` sin errores.

**Navegador** (`app.localhost:3000`):

- **Finanzas** (cuenta nueva): tres eventos a mano → barra de selección (clientes distintos apaga los botones; factura a un cliente con DNI la rechaza la API) → factura de dos eventos → ficha del borrador → emitir (`F001-00000001`, cuenta por cobrar con su cargo) → PDF → listado y pestañas → Cuentas por cobrar (tarjetas, ficha, recordatorios) → Registrar pago de S/ 1,000 aplicado solo → segundo pago en efectivo sin aplicar → Aplicar → Conciliar → Reembolsar S/ 200 (revirtió S/ 75.59 de la cuenta) → ficha y recibo PDF del cobro → nota de crédito bloqueada por los cobros aplicados → boleta: descartar borrador (el evento volvió a "Por facturar"), generarla de nuevo, emitir (`B001-00000001`) y nota de crédito (`BC01-00000001`, cuenta "Anulada") → anular un evento → ordenar por Base, buscar, "/" y exportar a CSV y Excel.
- **Ventas:** en el menú de Finanzas solo ve Cuentas por cobrar, con "Alcance: Propios"; Registrar pago y recordatorios con candado. Entrando por la dirección a Comprobantes o Cobros, no cargan.
- **Gerencia:** ve las cuatro pantallas y Monedas y tipo de cambio; candado en Nuevo evento, Generar factura / boleta, Registrar pago, Conciliar y Reembolsar; puede emitir nota de crédito y exportar.
- **Operaciones:** ve Por facturar y Comprobantes de su sede ("Alcance: Sede"), sin la tarjeta Pagos aplicados; candado en Nuevo evento, exportar y nota de crédito. No ve Monedas y tipo de cambio.
- **Administrador:** todo sin candados; en Roles y permisos aparecen los recursos nuevos (Eventos facturables y comprobantes, Cuentas por cobrar, Cobros, Monedas y tipo de cambio) y el rol Finanzas. Entré con un código nuevo y cerré solo esa sesión.
- **Oscuro:** medido por código en las cuatro pantallas, la ficha, el formulario de pago y la ficha del cobro: sin textos de bajo contraste (lo único bajo 4:1 es el blanco sobre el azul de los botones, igual en todo el sistema).
- **375 px:** sin desborde horizontal en las cuatro pantallas, la ficha (cargada a ese ancho) y el formulario de pago; las tablas pasan a tarjetas.
- Los dos PDF se generaron y se comprobó su texto por código (número, cliente, RUC, total, "Documento interno"; recibo con su aplicación y lo reembolsado).

### Lo que no se pudo probar así, y por qué

- **Capturas y paso del cursor:** la ventana del navegador de pruebas estuvo oculta toda la sesión; se probó por código. No vi las pantallas "con los ojos" ni los estados al pasar el cursor.
- **Orden de trabajo → evento, en el navegador:** tu empresa de prueba no tiene ningún servicio "Por evento" (son Único y Recurrente) y no quise cambiar tus servicios. Lo cubre la prueba automática.
- **Ventas viendo una cuenta por cobrar suya, en el navegador:** no hay comprobantes de sus contratos. Lo cubre la prueba automática de alcance.
- **Dólares, en el navegador:** cubierto solo por la prueba automática.
- **Dos emisiones exactamente a la vez:** la serie se bloquea en la base, pero no lo probé con dos conexiones simultáneas.
- **El aspecto del PDF:** comprobé su contenido, no su composición (usa la misma pieza que tus otros PDF).

## Datos de prueba que quedaron en "Tenant A - Prueba"

Rol **Finanzas** y cuenta `finanzas@erp-saas.test` (contraseña en `LocalFinanceSeeder`, sin código). Eventos: dos facturados, uno facturado en boleta y uno anulado. Comprobantes: `F001-00000001` (saldo S/ 75.59), `B001-00000001` (corregido), `BC01-00000001` y un borrador descartado. Cobros `PAG-2026-0003` (S/ 1,000, aplicado) y `PAG-2026-0004` (S/ 600: S/ 400 aplicados, S/ 200 reembolsados, conciliado). Los comprobantes emitidos y los cobros no se pueden borrar desde la aplicación.

## Decisiones tomadas en esta tanda

Lo que decidí sin preguntarte; dime cuáles cambio.

1. **Alcance de la tanda:** la fase 1 del kit (cuatro pantallas). Recurrencia, Facturación electrónica y Bancos y caja, después.
2. **Tus tablas se conservan** con sus nombres; no se adoptaron las del kit (`fin_…`).
3. **Tus rutas anteriores de la API se reemplazaron:** `POST /invoices` (emitía una factura directamente desde un contrato) y `POST /payments` (pedía repartir el cobro a mano). Ahora un contrato se factura registrando un evento con ese contrato; el cobro se aplica solo o desde "Aplicar". No tenían pantalla ni pruebas.
4. **Nada se envía a SUNAT** y el sistema lo dice. No se simuló la respuesta.
5. **Detracciones y retenciones quedan para Facturación electrónica** (necesitan su configuración). La columna está, vacía.
6. **Solo los servicios "Por evento" generan evento al completar la orden.** Uno "Único" se factura con un evento manual; uno "Recurrente", cuando exista Recurrencia.
7. **"Nuevo evento" a mano** y **"Descartar borrador"** no están en el prototipo: sin el primero una empresa sin órdenes por evento no podría facturar; sin el segundo, un borrador equivocado dejaba sus eventos atrapados.
8. **Vencimiento:** hoy + los días de crédito del cliente (Clientes y actores); sin ellos, 30 días. No se edita en el borrador.
9. **Nota de crédito:** siempre por el total; **no se emite si el comprobante tiene cobros aplicados** (primero se reembolsan), para que una cuenta anulada no retenga dinero del cliente. En el prototipo sí se podía. Sus eventos no vuelven a "por facturar".
10. **Series fijas** `F001`, `B001`, `FC01` y `BC01`, creadas solas la primera vez; ocho dígitos. Editarlas será parte de Facturación electrónica.
11. **Cobros:** se conserva tu numeración `PAG-AAAA-NNNN`; el efectivo no pide n.º de operación; la fecha no puede ser futura.
12. **Reembolso:** revierte primero la aplicación más reciente y, entre las de un mismo momento, la deuda más nueva (las cuentas antiguas se quedan pagadas).
13. **Dólares:** el comprobante guarda el tipo de cambio de venta del día de emisión y el cobro el de su fecha; la diferencia de cambio se guarda en cada aplicación, pero **no hay asiento contable** (no existe Contabilidad).
14. **Permisos:** `fiscal.*` separado para el tipo de cambio; `billing.manage` pasa a ser "anular un evento y descartar un borrador"; reparto de la tabla de arriba.
15. **Alcance:** tres recursos nuevos. La cuenta por cobrar se ve dentro de la ficha del comprobante aunque el rol no tenga Cuentas por cobrar (es un dato del comprobante); los pagos aplicados, solo con permiso de ver cobros.
16. **Fichas:** el comprobante, en una página con tarjetas movibles; el evento, la cuenta por cobrar y el cobro, en una ventana sobre el listado.
17. **PDF del comprobante** con la leyenda "Documento interno… no fue enviado a SUNAT".
18. **Antigüedad en soles:** los saldos en dólares se convierten con el tipo de cambio de venta vigente (no el de cada emisión).
19. **Rol y cuenta Finanzas de prueba** creados por seeder, como Operaciones y Técnico.
20. **En la vista del cliente** el saldo ("Situación financiera") pasa a pedir ver cuentas por cobrar y respeta el alcance: Ventas verá ahí el saldo de lo que vendió; Operaciones ya no lo verá (el prototipo no le da cuentas por cobrar).
21. Los comprobantes anteriores (`FAC-2026-0001`) muestran el nombre y el documento vigentes del cliente, porque no los guardaron al emitirse.

## Pendiente

- **Recurrencia** (instancias, ciclos, renovar) y que el ciclo genere su evento facturable.
- **Facturación electrónica:** proveedor (OSE/PSE), series editables, contingencia, comunicaciones de baja, **detracciones y retenciones**, XML y CDR.
- **Bancos y caja** (cuentas de destino, conciliación real con el extracto).
- Recordatorios de cobranza (con Notificaciones); eventos por horas aprobadas y por hitos de contrato; nota de crédito parcial; editar el vencimiento de un borrador; pago en línea y portal del cliente; asiento de la diferencia de cambio (con Contabilidad).
- La vista del cliente no se revisó en el navegador tras este cambio (la cubren las pruebas automáticas).
