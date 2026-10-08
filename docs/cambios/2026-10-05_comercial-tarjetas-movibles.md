# 2026-10-05 — Comercial: tarjetas en teléfono, padding de tablas y tarjetas que se pueden mover

Continúa [2026-10-05_comercial-responsive.md](2026-10-05_comercial-responsive.md).

## Qué se pidió

1. En la vista responsive, darle mejor vista a las tarjetas: más espacio y líneas.
2. Un poco de padding en "Versiones de precio" y "Usado en cotizaciones".
3. Que las tarjetas se puedan mover para que cada usuario las ordene como quiera.
4. Después: un efecto más suave al mover las tarjetas y al abrir los modales.

## Qué se cambió (solo web)

| Archivo | Cambio |
| --- | --- |
| `app/globals.css` | Bloque `.proto-tbl` de teléfono (hasta 639 px): cada fila es ahora una tarjeta con borde y esquinas redondeadas, separada de la siguiente; cada dato tiene más aire (9 px arriba y abajo) y una línea fina que lo separa del siguiente; las píldoras de estado y modalidad ya no se estiran a todo el ancho. |
| `components/ui/sortable-cards.tsx` (nuevo) | `SortableBoard`: tablero de tarjetas reordenables. Se arrastran desde el asa (⋮⋮) de su cabecera, con ratón o con el dedo, dentro de su zona o de una columna a otra; con el asa enfocada también se mueven con las flechas del teclado. El orden se guarda en el navegador (`localStorage`), por pantalla. Si el orden no es el original aparece "Restablecer orden de las tarjetas". |
| `components/ui/proto.tsx` | `Card` muestra el asa en su cabecera solo cuando está dentro de un `SortableBoard`; fuera de él queda igual que antes. |
| `components/dashboard/services/ServiceDetail.tsx` | Las cuatro tarjetas de la ficha (Ciclo de vida, Configuración, Versiones de precio, Usado en cotizaciones) van en un `SortableBoard`. Las dos tablas tienen ahora margen lateral (quedan alineadas con el título de la tarjeta) y margen inferior. |
| `components/dashboard/quotations/QuotationDetail.tsx` | Las cinco tarjetas de la ficha (Líneas, Estados, Trazabilidad, Aprobaciones, Historial) van en un `SortableBoard` de dos columnas: se pueden reordenar y pasar de una columna a la otra. |

### Efecto suave (segunda parte)

| Archivo | Cambio |
| --- | --- |
| `components/ui/sortable-cards.tsx` | Cada tarjeta es un `motion.div` de framer-motion (ya estaba instalado) con `layout="position"` y `layoutId`: al reordenar, las tarjetas se deslizan a su nuevo lugar con un resorte suave, también al pasar de una columna a otra. |
| `components/ui/proto.tsx` | La tarjeta que se arrastra se "levanta" (crece apenas, sombra más profunda y borde azul) con una transición de 0,2 s. `Modal` lleva las clases `proto-fade-in` (fondo) y `proto-pop-in` (cuadro). |
| `app/globals.css` | Animaciones `proto-fade-in` (0,2 s) y `proto-pop-in` (0,26 s: aparece subiendo 14 px y creciendo de 97 % a 100 %). Se desactivan si el sistema pide menos movimiento. |

Solo cambia la entrada de los modales de `proto.tsx` (servicio, precio, confirmaciones de cotización). Al cerrar desaparecen de inmediato, como antes.

## Qué NO se tocó

- La API, la base de datos y la lógica de las pantallas: el contenido de cada tarjeta es el mismo.
- Clientes, Proveedores, Empleados y Planes SaaS (no usan `proto-tbl`, `Card` ni `SortableBoard`).
- Los listados y el editor de cotización no tienen tarjetas movibles; solo heredan el nuevo aspecto de las filas en teléfono.

## Diferencias con el prototipo

- El prototipo en teléfono separa las filas solo con una línea; aquí cada fila es una tarjeta con borde y líneas entre datos, porque se pidió más espacio y más separación.
- El prototipo no permite mover tarjetas: es una función añadida.

## Base de datos

Sin cambios. El orden de las tarjetas se guarda en el navegador de cada persona (claves `erp.cards.service-detail` y `erp.cards.quotation-detail`), no en el servidor: no se comparte entre equipos ni navegadores, y es el mismo para todas las fichas de ese tipo.

## Cómo se probó

`tsc` y `eslint` sin errores. En el navegador con `admin@erp-saas.test`:

1. A 375 px: listado de servicios, listado de cotizaciones, ficha de servicio y ficha de cotización; filas como tarjetas con borde y líneas, sin desplazamiento horizontal.
2. A 1280 px, ficha de servicio: las tablas de "Versiones de precio" y "Usado en" empiezan a 20 px del borde (igual que el título) y dejan 10 px abajo.
3. Mover con teclado (ficha de servicio): "Versiones de precio" sube un lugar y el orden queda guardado.
4. Arrastrar con el ratón (ficha de cotización): "Trazabilidad" pasa a la otra zona, encima de "Estados"; al recargar se conserva; "Restablecer orden de las tarjetas" vuelve al original y borra lo guardado.

5. Efecto suave: a mitad del movimiento la tarjeta está desplazada (se desliza) y al terminar queda en su sitio sin desplazamiento; el modal de "Cancelar cotización" abre con las dos animaciones aplicadas y se cierra bien.

No se pudo ver correr la animación del modal: el panel del navegador de pruebas estaba oculto y ahí las animaciones CSS no avanzan (el modal se quedaba transparente hasta forzar el final; al forzarlo queda visible). En un navegador a la vista debe verse normal: **conviene mirarlo una vez a mano**.

No se probó: el arrastre con el dedo en un teléfono real (solo con ratón y teclado), ni el editor de cotización a 375 px con el nuevo aspecto de las filas.

## Pendiente

- Si se quiere que el orden siga a la persona entre equipos, hay que guardarlo en el servidor (preferencias de usuario).
- Los indicadores del listado de cotizaciones no son movibles.

## Ficha del servicio: espacio vacío en pantallas anchas (2026-10-08)

En una pantalla ancha (1900 px), "Ciclo de vida" y "Configuración" ocupaban solo la mitad izquierda y quedaba un hueco grande a su derecha. La rejilla abría tantas columnas como cupieran (3 o 4) y solo hay dos tarjetas arriba.

Ahora la ficha tiene **dos columnas como máximo** (una en pantallas angostas): las dos tarjetas de arriba se reparten todo el ancho, y "Versiones de precio" y "Usado en…" siguen a todo el ancho debajo.

Archivo: `components/dashboard/services/ServiceDetail.tsx` (una línea). Las demás fichas (cotización, contrato, orden, vista 360) usan otra distribución y no tenían el problema.

Probado en el navegador con Ventas a 1900 px, en "Cambio de frenos": las dos tarjetas miden 767 px cada una y juntas llenan el ancho; las de abajo, 1549 px. No se volvió a ver a 375 px (ahí sigue siendo una columna).
