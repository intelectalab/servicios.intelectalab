# 2026-10-05 — Cotizaciones en dólares, tipo de cambio y desplegables con buscador

Continúa [2026-10-05_cotizaciones-listado-ajustes.md](2026-10-05_cotizaciones-listado-ajustes.md).

## Qué se pidió

1. Agregar lo de la moneda en cotizaciones: faltaban los dólares, guiándose del prototipo.
2. A los desplegables con muchas opciones (Cliente, Agregar servicio) añadirles un buscador.

## Cómo lo hace el prototipo (y cómo quedó aquí)

- El catálogo de servicios siempre está en soles. La moneda (PEN o USD) se elige en la cotización.
- Al pasar a dólares, el precio de cada línea se divide entre el **tipo de cambio de venta** vigente; al volver a soles se multiplica. El texto de Condiciones cambia "soles" por "dólares americanos" y viceversa.
- La cotización guarda el tipo de cambio con el que se emitió y lo muestra en su cabecera: "en dólares (T.C. 3.750)".
- El tipo de cambio se registra por día en Finanzas → **Monedas y tipo de cambio**.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `database/migrations/2026_10_05_000400_create_exchange_rates_table.php` (nuevo) | Tabla `exchange_rates` (fecha, moneda, compra, venta, fuente; una fila por empresa, moneda y día; aislada por empresa con RLS) y columna `quotations.exchange_rate`. |
| `Modules/Finance/Models/ExchangeRate.php` (nuevo) | Modelo; `ExchangeRate::current()` devuelve el tipo de cambio más reciente hasta hoy. |
| `Modules/Finance/Http/Controllers/ExchangeRateController.php` (nuevo) | `GET /v1/exchange-rates` (historial paginado), `GET /v1/exchange-rates/current` (vigente), `GET /v1/exchange-rates/summary` (indicadores), `POST /v1/exchange-rates` (registrar; si ya hay uno ese día, lo corrige). Queda en la auditoría. |
| `routes/api.php` | Rutas nuevas. Ver el historial pide `billing.view`; registrar, `billing.manage`; el vigente lo lee cualquier usuario de la empresa (lo necesita quien cotiza). |
| `Modules/Services/Http/Controllers/QuotationController.php` | Crear y editar aceptan la moneda y guardan el tipo de cambio de venta vigente si es USD (sin tipo de cambio registrado, no deja cotizar en dólares). Editar un borrador sin cambiarle la moneda conserva su tipo de cambio. "Nueva versión" de una cotización en dólares convierte el precio vigente del catálogo al tipo de cambio de hoy. |
| `Modules/Services/Http/Requests/StoreQuotationRequest.php` | La moneda solo puede ser PEN o USD. |
| `Modules/Services/Models/Quotation.php` | Campo `exchange_rate`. |
| `tests/Feature/ComercialTest.php` | Dos pruebas nuevas: cotizar en dólares (exige y conserva el tipo de cambio, corrige el del mismo día, vuelve a soles, nueva versión convertida) y permisos del tipo de cambio. |

**Lógica que cambió:** antes "editar" no tocaba la moneda (siempre soles); ahora la guarda. Lo demás de cotizaciones sigue igual.

### Web

| Archivo | Cambio |
| --- | --- |
| `components/ui/proto.tsx` | Pieza nueva `SearchSelect`: desplegable con buscador (ignora tildes y mayúsculas, busca también en el dato secundario), flechas / Enter / Esc, se abre hacia arriba si no hay sitio abajo y no se recorta dentro de las tarjetas. |
| `components/dashboard/quotations/QuotationEditor.tsx` | Cliente y Agregar servicio usan `SearchSelect` (cliente: nombre + documento; servicio: nombre + código y precio). El campo Moneda ya permite "Dólares (USD)", con la nota "T.C. venta 3.750: los precios del catálogo se convierten"; convierte las líneas y el texto de Condiciones; líneas y totales se muestran en la moneda elegida. |
| `components/dashboard/quotations/QuotationDetail.tsx` | La cabecera muestra "en dólares (T.C. …)". El aviso "hoy el catálogo dice…" compara en la moneda de la cotización. |
| `components/dashboard/finance/ExchangeRates.tsx` y `app/(dashboard)/exchange-rates/page.tsx` (nuevos) | Pantalla **Monedas y tipo de cambio**: indicadores (USD venta hoy, Variación 30 días, Cotizaciones en USD, Comprobantes en USD), "Registrar tipo de cambio" (Fecha, Compra, Venta) e Historial (USD) con casillas, exportar a Excel/CSV y paginación. |
| `components/layout/AppShell.tsx`, `proxy.ts` | "Monedas y tipo de cambio" deja de estar atenuado en el menú de Finanzas (módulo FINANZAS, permiso `billing.view`) y la ruta queda protegida como las demás. |
| `types/commercial.ts` | `Quotation.exchange_rate`, `ExchangeRate`, `ExchangeRateSummary`. |

### Ajuste posterior: opciones ordenadas

Se pidió ordenar los desplegables porque con textos largos se desordenaban (el dato secundario se encimaba o saltaba de línea a la derecha). Ahora cada opción tiene siempre la misma forma: el nombre arriba (máximo dos líneas) y el dato secundario debajo en gris (documento del cliente; código y precio del servicio), con una línea fina entre opciones y barra de desplazamiento delgada. Solo cambió `SearchSelect` en `components/ui/proto.tsx`. Visto en el navegador a 1440 y 375 px con la lista de clientes.

### Ajuste posterior: "precio vigente hoy" en las líneas del editor

Se pidió porque el prototipo lo muestra bajo el nombre del servicio. En soles ya existía, pero solo aparece cuando el catálogo cambió después de agregar la línea (por eso no se veía con los datos de prueba). Lo que faltaba era el caso de dólares: el prototipo muestra ahí siempre el precio del catálogo en soles, y ahora el editor también ("precio vigente hoy S/ 134.00" junto a US$ 35.73). Solo cambió `QuotationEditor.tsx`.

Probado en el navegador: el borrador en dólares COT-2026-0006 muestra el aviso y al pasarlo a soles desaparece (no se guardó). El caso de soles con precio cambiado se probó alterando solo en el navegador la respuesta del catálogo (S/ 180.00 frente a una línea de S/ 134.00), sin tocar datos.

## Qué NO se tocó

- Clientes, Proveedores, Empleados y Planes SaaS.
- El catálogo de servicios: los precios siguen en soles y sin IGV.
- Contratos, comprobantes y cobros: el contrato generado copia la moneda de la cotización, pero no guarda tipo de cambio ni hay diferencia de cambio (esas pantallas no existen todavía).
- Los indicadores del listado de cotizaciones: siguen mostrando el monto por moneda ("S/ … · US$ …"); el prototipo los convierte a soles.

## Diferencias con el prototipo

- **Sin "Actualizar desde SUNAT".** En el prototipo es simulado; hacerlo de verdad requiere contratar o conectar un servicio externo. El tipo de cambio se registra a mano.
- Sin tipo de cambio registrado, el prototipo usa 3.75 por defecto; aquí no deja cotizar en dólares y avisa dónde registrarlo.
- Al cambiar de moneda, una línea que sigue al precio del catálogo se recalcula desde el catálogo (no arrastra céntimos de redondeo al ir y volver).
- Los desplegables del prototipo son listas simples; el buscador es añadido.
- No se trajo la nota explicativa bajo el título de "Monedas y tipo de cambio" (regla de siempre).

## Base de datos

- Migración `2026_10_05_000400` **ya corrida** en la base local (solo añade una tabla y una columna).
- En la empresa de prueba "Tenant A - Prueba" se activó el módulo `FINANZAS` en `tenant_modules`, para que el menú no salga con candado.
- Datos de prueba creados: tipo de cambio del 05/10/2026 (compra 3.720, venta 3.750, **valor inventado**) y la cotización COT-2026-0006 en dólares (borrador).
- `database/schema_reference.sql` sigue sin regenerar.

## Cómo se probó

`tsc` y `eslint` sin errores. `php artisan test --filter=ComercialTest`: 13 pruebas pasan. En el navegador con `admin@erp-saas.test`:

1. Sin tipo de cambio: al elegir Dólares el editor avisa y se queda en soles.
2. Monedas y tipo de cambio: registrar 3.720 / 3.750 → aparece en "USD venta (hoy)" y en el historial.
3. Buscador de Cliente: "perez" encuentra a los seis "Pérez"; "ana tor" + Enter elige a Ana Torres.
4. Buscador de servicio: se abre hacia arriba al no haber sitio; "srv-004" + flecha + Enter elige "Cambio de frenos"; el servicio sin precio sale deshabilitado.
5. Moneda: S/ 134.00 → US$ 35.73 (IGV 6.43, total 42.16); de vuelta a soles, S/ 134.00; Condiciones cambia "soles" ↔ "dólares americanos".
6. Guardar borrador: la ficha muestra "en dólares (T.C. 3.750)" y los montos en US$; al editarla abre en dólares con su tipo de cambio.
7. A 375 px: editor (desplegable dentro de la pantalla, opciones sin encimarse) y Monedas y tipo de cambio (indicadores apilados, filas como tarjeta), sin desplazamiento horizontal.

No se probó en el navegador: enviar/aprobar una cotización en dólares, "Nueva versión" en dólares (sí en las pruebas automáticas), convertir a contrato en dólares, ni el Excel del historial.

## Pendiente

- Registrar el tipo de cambio real (el de prueba es inventado) y decidir si se conecta una fuente automática.
- Una empresa necesita el módulo Finanzas y el permiso `billing.manage` para registrar el tipo de cambio; sin eso no puede cotizar en dólares.
- Dólares en contratos, comprobantes y cobros (diferencia de cambio), cuando existan esas pantallas.
- `SearchSelect` está disponible para otros desplegables largos; hoy solo lo usa el editor de cotización.
