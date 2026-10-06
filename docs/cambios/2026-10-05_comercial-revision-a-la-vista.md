# 2026-10-05 — Comercial: revisión de lo que quedó sin ver

Continúa [2026-10-05_comercial-tarjetas-movibles.md](2026-10-05_comercial-tarjetas-movibles.md).

## Qué se pidió

1. Revisar lo que la tanda anterior dejó sin comprobar a la vista: la animación al abrir los modales, el arrastre de tarjetas con el dedo en teléfono y el editor de cotización a 375 px.
2. Después: arreglar los dos detalles que salieron de esa revisión (asa pequeña para el dedo y líneas de cotización desalineadas en teléfono).

## Qué se cambió

La revisión no necesitó cambios. Los dos detalles sí (solo web):

| Archivo | Cambio |
| --- | --- |
| `components/ui/proto.tsx` | El asa de las tarjetas movibles se ve igual (32 × 32 px) pero responde al toque en 44 × 44 px: lleva dentro un `span` invisible que sobresale 6 px por cada lado. |
| `components/dashboard/quotations/QuotationDetail.tsx` y `QuotationEditor.tsx` | En teléfono, la tabla de líneas ya no llega hasta el borde de la tarjeta (`-mx-3` en vez de `-mx-5`; desde 640 px sigue igual): la tarjeta de cada línea queda a 20 px del borde, alineada con el título. |

## Qué NO se tocó

La API, la base de datos, la lógica de las pantallas y los módulos ya hechos (Clientes, Proveedores, Empleados, Planes SaaS). En escritorio las tablas de líneas quedan exactamente como estaban.

## Diferencias con el prototipo

Ninguna nueva.

## Base de datos

Sin cambios.

## Cómo se probó

En el navegador con `admin@erp-saas.test`, sobre la cotización COT-2026-0005 (borrador). No se guardó ni se cambió ningún dato.

1. **Animación del modal** (1280 px, "Cancelar cotización"): las dos animaciones corren solas hasta terminar (fondo 0,2 s; cuadro 0,26 s). Se capturó el modal a mitad de camino (semitransparente, entrando) y terminado (opaco, en su sitio). Se cerró con "Volver", sin cancelar la cotización.
2. **Editor de cotización a 375 px** (`/quotations/[id]/edit`): sin desplazamiento horizontal; Cabecera en una columna; cada línea como tarjeta con sus datos separados por líneas (Servicio, Cantidad, Precio unit., Desc. %, IGV, Total y el botón de quitar); "Agregar servicio" y los totales a todo el ancho; botones "Guardar borrador" y "Guardar y enviar a aprobación" a todo el ancho.
3. **Arrastre táctil a 375 px** (ficha de cotización): con eventos de puntero de tipo táctil, "Estados" sube por encima de "Líneas cotizadas", el orden se guarda y aparece "Restablecer orden de las tarjetas"; al pulsarlo vuelve el orden original y se borra lo guardado. El asa tiene `touch-action: none` (el dedo sobre el asa no desplaza la página) y mide 32 × 32 px.

Límite de la prueba 3: es un toque **simulado** en el navegador de escritorio. Con un dedo en un teléfono real sigue sin probarse.

Tras los dos arreglos (`tsc` y `eslint` sin errores), de nuevo a 375 px:

4. **Líneas alineadas**: en la ficha y en el editor, el título de la tarjeta y la tarjeta de cada línea empiezan a 20 px del borde, con 20 px también a la derecha (antes la línea empezaba a 12 px). Sin desplazamiento horizontal. A 1280 px la tabla sigue de borde a borde como antes.
5. **Asa**: tamaño visible 32 × 32 px, área táctil 44 × 44 px; un toque 5 px fuera del asa por cualquiera de sus cuatro lados la alcanza, a 8 px ya no. Arrastrando desde ese margen con un toque simulado, "Estados" sube encima de "Líneas cotizadas"; luego se restableció el orden.

## Pendiente

- Probar el arrastre una vez en un teléfono real.
