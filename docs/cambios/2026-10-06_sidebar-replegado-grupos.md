# 2026-10-06 — Barra lateral replegada: muestra los mismos módulos que desplegada

Continúa [2026-10-05_cotizacion-botones-por-estado.md](2026-10-05_cotizacion-botones-por-estado.md).

## Qué se pidió

Con la barra lateral replegada solo salen algunos íconos y se ignoran los módulos que sí aparecen con la barra desplegada.

## Qué pasaba

Replegada, la barra mostraba un ícono por cada **pantalla ya construida** (Clientes, Proveedores, Cotizaciones…) y descartaba todo lo demás. Por eso desaparecían los módulos sin pantallas todavía (Inicio, CRM, Operaciones, Compras, Contabilidad, Soporte, Control) y los que sí tenían quedaban como una fila de íconos sueltos, sin relación con el menú desplegado.

## Qué se cambió (solo web)

| Archivo | Cambio |
| --- | --- |
| `components/layout/Sidebar.tsx` | Replegada, la barra muestra **un ícono por módulo**, los mismos y en el mismo orden que desplegada (Inicio, CRM, Comercial, Operaciones, Finanzas, Compras, Contabilidad, Soporte, Control, Administración). Al pasar el cursor sale el nombre del módulo. Al pulsar un ícono se abre a su lado un panel con las pantallas de ese módulo, igual que en el menú desplegado: las construidas como enlaces, las que requieren otro plan con su etiqueta "Plan" y las pendientes atenuadas con el reloj. El módulo de la pantalla actual queda resaltado. El panel se cierra al elegir una pantalla, al pulsar fuera, con Esc o al desplazar la barra. |

No se tocó la barra desplegada ni el menú en teléfono (que siempre se abre desplegado). Se conservan la hamburguesa, los tooltips y el aviso de cambio de plan.

## Diferencias con el prototipo

El prototipo no tiene modo replegado: es una función propia de la app.

## Base de datos

Sin cambios.

## Cómo se probó

`tsc` y `eslint` sin errores. En el navegador como `ventas@erp-saas.test`, a 1440 px:

1. Desplegada, la barra lista 10 módulos; replegada muestra los mismos 10 íconos, con Comercial resaltado estando en Clientes.
2. Pulsar Comercial abre el panel "COMERCIAL" con Clientes (resaltado), Proveedores, Empleados, Catálogo de servicios, Cotizaciones y, atenuados, Contratos, Órdenes y Solicitudes del portal.
3. Esc cierra el panel. El panel de Administración (abajo) se abre sin salirse de la pantalla.
4. Elegir "Cotizaciones" en el panel navega a la pantalla, cierra el panel y deja Comercial resaltado.
5. La barra se dejó desplegada otra vez al terminar.

Hubo una captura del panel de Comercial; el resto se comprobó por medición porque el panel del navegador de pruebas dejó de dibujar.

## Pendiente

- En el panel no se navega con las flechas del teclado (sí con Tab).
