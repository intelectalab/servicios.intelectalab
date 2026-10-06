# 2026-10-05 — Ficha de cotización: precio versión, Trazabilidad, Aprobaciones e Historial como el prototipo

Continúa [2026-10-05_cotizaciones-dolares-y-buscador.md](2026-10-05_cotizaciones-dolares-y-buscador.md).

## Qué se pidió

1. Que en el editor el precio del catálogo se vea siempre en soles ("precio vigente hoy"), aunque no haya cambiado.
2. En la vista de una cotización falta el "precio versión" de cada línea.
3. Trazabilidad, Aprobaciones e Historial no se parecen a los del prototipo: revisar.

## Qué se cambió

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/quotations/QuotationEditor.tsx` | "precio vigente hoy S/ …" aparece siempre bajo cada servicio: en gris si coincide con la línea y en ámbar si el catálogo ya cambió. |
| `components/dashboard/quotations/QuotationDetail.tsx` | **Líneas:** cada línea muestra "precio v2" (la versión del precio del catálogo con la que se cotizó). En dólares, los totales añaden "Equivalente" en soles. **Trazabilidad:** queda como en el prototipo: Origen (Cotización directa ERP / Oportunidad CRM / Solicitud del portal), Versión anterior, Contrato con su estado en píldora y Responsable con su círculo de iniciales; se quitaron las filas Estado y Versión, que el prototipo no tiene (el estado se ve en "Estados" y la versión en el listado). **Aprobaciones:** cada decisión indica "· backoffice". **Historial:** cada movimiento se lee por el estado en que deja la cotización (píldora "Borrador", "Enviada", "Aprobada"… con "desde …"), también la creación y los movimientos antiguos que antes salían como texto; "Borrador editado" sigue como texto porque no cambia el estado. |
| `components/ui/proto.tsx` | `Kv` (las listas de dato y valor) usa una línea más tenue entre filas, como el prototipo. Afecta también a la ficha de servicio y a los totales del editor. |
| `types/commercial.ts` | `QuotationItem.price_version`. |

### API

| Archivo | Cambio |
| --- | --- |
| `Modules/Services/Http/Controllers/QuotationController.php` | El detalle de la cotización devuelve en cada línea `price_version`: cuántas versiones de precio tenía el servicio cuando se guardó la línea (la más antigua es la 1). |
| `tests/Feature/ComercialTest.php` | La prueba de "nueva versión" comprueba que la línea salga con la versión 2 del precio. |

## Qué NO se tocó

Clientes, Proveedores, Empleados, Planes SaaS; la lógica de cotizaciones; la base de datos.

## Diferencias con el prototipo

- **Sin "Visible en portal"** en Trazabilidad: el portal todavía no muestra cotizaciones, así que decir "Sí: el cliente puede verla y aprobarla" sería falso.
- Sin filas Oportunidad ni Orden, ni la tarjeta "Autorización interna": dependen de CRM, Órdenes y reglas de aprobación, que no existen aún.
- La línea conserva el código del servicio y la recurrencia además de "unidad · precio vN".
- El editor muestra el precio vigente siempre (se pidió así); el prototipo solo cuando difiere.
- "precio vN" se calcula por fecha: si un borrador se vuelve a guardar después de un cambio de precio en el catálogo, pasa a mostrar la versión nueva aunque la línea conserve el precio anterior (en ese caso también aparece "hoy el catálogo dice…").

## Base de datos

Sin cambios.

## Cómo se probó

`tsc` y `eslint` sin errores; `php artisan test --filter=ComercialTest`: 13 pruebas pasan. En el navegador con `admin@erp-saas.test`, a 1440 px:

1. COT-2026-0007 (dólares, borrador): línea "SRV-002 · visita · precio v2", "Equivalente S/ 448.39", Trazabilidad con Origen y Responsable (con iniciales), Historial con la píldora "Borrador".
2. COT-2026-0002 (aprobada, con contrato): Contrato "CTR-2026-0002" con píldora "Borrador"; el historial antiguo sale con píldoras tras el ajuste.
3. COT-2026-0003 (rechazada): Aprobaciones "Rechazada · Usuario de Prueba · backoffice" con fecha y motivo; Historial "Rechazada desde Enviada", "Enviada desde Borrador", "Borrador editado", "Borrador".
4. Editor de COT-2026-0005: "precio vigente hoy S/ 134.00" en gris (precio sin cambios).

A 375 px solo se comprobó que la ficha no tiene desplazamiento horizontal y que Trazabilidad muestra los mismos datos (por medición, sin captura).

## Pendiente

- "Visible en portal", Oportunidad, Orden y Autorización interna, cuando existan esas pantallas.
