# 2026-10-14 — Tesorería (Bancos y caja): lo que pedía el kit y faltaba

## Qué se pidió

"Módulo Tesorería. Especificación: docs/especificaciones/ del kit."

**Tesorería no es otro módulo.** En el kit es un capítulo de la especificación (`10-tesoreria.md`) con una sola pantalla, `P-TRE-01` "Bancos y caja" (`#/finanzas/bancos` en el prototipo). En el prototipo su permiso no pertenece a un módulo propio del plan. Esa pantalla ya estaba hecha desde el 2026-10-13 ([cambios/2026-10-13_finanzas-recurrencia-bancos-y-facturacion-electronica.md](2026-10-13_finanzas-recurrencia-bancos-y-facturacion-electronica.md)). Se comparó con la especificación y elegiste hacer cinco cosas que faltaban:

1. Caja chica: centro de costo y cuenta de gasto obligatorios, elegidos de listas.
2. Historial de extractos.
3. Exportar la tabla "Extracto" de Conciliación.
4. Guardar el archivo del extracto.
5. Transferencias protegidas también en la base.

Queda fuera, porque depende de Notificaciones: avisar a Gerencia cuando un arqueo tiene diferencia.

## Cómo funciona

### 1. Caja chica: centro de costo y cuenta de gasto del plan

- En "Gasto de caja chica", **Centro de costo** y **Cuenta de gasto** ahora son obligatorios y se eligen de listas con buscador. Los centros de costo son los activos de la empresa. Las cuentas de gasto son las imputables del plan de cuentas.
- El asiento del gasto va a esa cuenta y a ese centro de costo. Antes los tomaba solo si el texto escrito coincidía con el plan.
- En la tabla de gastos se ven como "CC-ADM · Administración (prueba)" y "6563 · Suministros y repuestos".
- **Los gastos anteriores no cambian:** se quedan con el texto que tenían (por ejemplo CCH-2026-0001 "Ventas" / "Movilidad").
- **La base exige** centro de costo y cuenta del plan en todo gasto nuevo. La regla se marcó para que no revise los gastos anteriores.

### 2. Historial de extractos (Conciliación)

- La tarjeta del extracto muestra por defecto el último importado. Si la cuenta tiene más de uno, un desplegable permite abrir **cualquier extracto anterior** con sus líneas. Cada opción muestra el nombre del archivo, cuándo se importó y cuántas líneas tiene.
- Lo elegido vale para esa cuenta. Al importar uno nuevo, vuelve a mostrarse el último.

### 3. Exportar el extracto

- La tabla del extracto exporta a Excel y CSV **al pie**, con el permiso `treasury.export` y registro en auditoría. Columnas: fecha, descripción del banco, n.º de operación, monto, estado, regla y movimiento en libros.

### 4. Archivo del extracto

- Al importar, el archivo se guarda en **Documentos** por `DocumentStore` (tipos permitidos, huella, carpeta de la empresa) y queda enlazado al extracto.
- Botón **Descargar archivo** en la tarjeta del extracto (enlace temporal). En los extractos importados antes de este cambio sale deshabilitado: "Este extracto se importó antes de que se guardaran los archivos".
- Si todas las líneas del extracto ya se habían importado, se rechaza **antes** de guardar el archivo, para no dejar archivos sueltos.

### 5. Transferencias con registro propio

- Cada transferencia y cada reposición de caja queda en una tabla propia (`bank_transfers`), con origen, destino, monto, moneda, referencia, fecha y tipo (transferencia o reposición).
- **La base garantiza:**
  - cuentas distintas, de esta empresa, de la misma moneda, activas y que no sean de detracciones;
  - saldo suficiente en la cuenta de origen (bloquea las dos cuentas mientras lo comprueba);
  - cada movimiento de una transferencia corresponde a una registrada: el egreso exacto en el origen y el ingreso exacto en el destino, uno solo por cuenta;
  - una transferencia no se edita ni se borra.
- Las dos transferencias que ya existían en Tenant A (TRF-PRUEBA-1 y REPOSICION-2026-10-13) se registraron en la tabla nueva al migrar.

## Base de datos

- Migración `2026_10_14_000200_treasury_cash_centers_statement_files_and_transfers.php` (ya corrida):
  - `cash_expenses.cost_center_id`, con la regla de centro y cuenta obligatorios;
  - `bank_statements.document_id`;
  - la tabla `bank_transfers` con sus dos controles.
- Solo añade; no borra datos.

## Cómo se probó

- **Pruebas automáticas** (`FinanceTreasuryTest`, ahora 9):
  - el gasto sin centro, con una cuenta que no es de gasto, con texto libre, con un centro inactivo o inexistente se rechaza;
  - el asiento usa esa cuenta y ese centro;
  - la base rechaza gastos nuevos sin centro o con una cuenta inventada;
  - prueba nueva de historial de extractos, archivo guardado y enlazado, descarga, extracto repetido que no guarda archivo, y lo que la base rechaza en transferencias (otra moneda, sin saldo, la misma cuenta, un movimiento sin transferencia o repetido, editar o borrar).
  - Se ajustó la prueba de Contabilidad que registraba gastos de caja con texto libre.
  - **215 pruebas pasan.**
- **En el navegador**, con Finanzas, a 1366 px y a 375 px:
  - gasto **CCH-2026-0002** (S/ 15, CC-ADM, 6563): primero sin listas (avisa "Selecciona el centro de costo / la cuenta de gasto") y luego con ellas; su asiento AS-2026-10-0028 va a 6563 con CC-ADM;
  - extracto nuevo en BBVA (`extracto-bbva-octubre.csv`, una línea de ITF de S/ 0.35): el historial muestra los tres de la cuenta; "Descargar archivo" baja el CSV original y está deshabilitado en los anteriores; exportar el extracto da su CSV; se registró la comisión desde la pantalla;
  - transferencia BBVA → BCP: con S/ 999,999 avisa "Saldo insuficiente"; con S/ 50 (**TRF-PRUEBA-2**) queda registrada con sus dos movimientos y su asiento AS-2026-10-0030.
- Arreglado durante la prueba: a 375 px el desplegable de extractos desbordaba la cabecera de la tarjeta; ahora va en su propia fila, encima de la tabla.
- Una lectura mostró "Descargar archivo" deshabilitado en el extracto nuevo justo después de importarlo por fuera de la pantalla. No se repitió al recargar ni al cambiar de cuenta, y la API respondía bien. Es probable que fuera la lectura anterior en caché.

## Decisiones tomadas en esta tanda (sin consultarte)

1. Los gastos de caja chica anteriores se conservan con su texto; la regla nueva vale para los nuevos.
2. Las listas de caja chica salen de un endpoint propio de Tesorería (`/cash-expenses/options`), para que quien opera la caja no necesite permisos de Compras.
3. El archivo del extracto se guarda antes de registrar el extracto (como pide `DocumentStore`). Si la importación falla después de guardarlo, el archivo queda en Documentos sin extracto. Solo se evita ese caso cuando todas las líneas ya estaban importadas.
4. La reposición de caja también queda en `bank_transfers`, con tipo "Reposición".
5. La tabla del extracto exporta todas sus líneas (las muestra todas: hasta 2,000, el máximo de una importación).

## Pendiente

- Avisar a Gerencia cuando un arqueo tiene diferencia (con Notificaciones).
- Corregir el centro de costo o la cuenta de un gasto ya registrado (hoy un gasto no se modifica).
