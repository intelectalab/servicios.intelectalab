# 2026-10-08 — El menú lateral recuerda qué grupos están abiertos y cerrados

## Qué se pidió

"Hay un problema con el sidebar, no se guardan los módulos que están abiertos y cuáles están cerrados: cierro Finanzas, abro Operaciones, recargo la página y no aparece así."

## Causa

El menú nunca guardaba ese estado: cada vez que se cargaba la página volvía al de siempre (abiertos los grupos con alguna pantalla construida, plegados los demás). Solo se recordaba si la barra estaba desplegada o en modo íconos.

## Qué se cambió

| Archivo | Cambio |
| --- | --- |
| `web/src/components/layout/Sidebar.tsx` | Cada grupo que la persona abre o cierra se guarda en su navegador (`erp-sidebar-groups`) y al cargar la página manda sobre el estado de siempre. Un grupo que nunca tocó sigue con el comportamiento anterior. |

- Se recuerda **por navegador**, como el orden de las tarjetas, las columnas y la vista Tabla / Kanban; no viaja al servidor.
- Vale también para la consola del superadmin (usa la misma barra).
- La barra de escritorio, la de teléfono y otra pestaña abierta leen lo mismo: lo que se cambia en una se refleja en las otras.
- Si el navegador no deja guardar (modo privado con el almacenamiento bloqueado), los grupos se siguen abriendo y cerrando; solo no se recuerdan al recargar.

## Qué NO se tocó

- El modo íconos y su panel por grupo, los candados "Plan", los ítems "próximamente" y el recuerdo de barra desplegada / replegada.
- El grupo de la pantalla en la que estás no se abre solo si lo dejaste cerrado: sigue resaltado, pero se respeta lo que elegiste.

## Diferencias con el prototipo

Ninguna a la vista: el menú se ve igual. No se revisó si el prototipo recuerda los grupos al recargar.

## Base de datos

Nada.

## Cómo se probó

`tsc` y `eslint` sin errores. En el navegador (`app.localhost:3000`):

- **Ventas:** se cerró Finanzas y CRM y se abrió Operaciones; al recargar en otra pantalla siguieron así. Luego se cerró Comercial y se abrió Control, y al recargar también. Después, lo contrario (abrir Finanzas, cerrar Operaciones) y recargar: se respetó.
- Pasar a modo íconos y volver conserva los grupos como estaban.
- **Navegador que no deja guardar** (simulado): Soporte se abre y se cierra igual; al volver a permitirlo, se guarda de nuevo.
- **Superadmin:** se cerró "Administración SaaS", se recargó y siguió cerrado; al abrirlo aparecen sus cuatro enlaces. Sin errores de carga de la página en la consola.

## Segunda parte (2026-10-08): nombres largos en dos líneas, y lo que faltaba probar

Pediste que los nombres largos del menú no se corten con "…": que salga el nombre completo y pase a la línea siguiente.

- En `Sidebar.tsx`, el nombre de cada grupo y de cada pantalla (con la barra desplegada y en el panel del modo íconos) ya no se recorta: baja a la línea siguiente. La tarjeta del usuario y el nombre de la empresa, arriba, siguen en una línea.
- Hoy bajan a dos líneas: "Comprobantes de proveedor", "Centros de costo y presupuestos", "Plan de cuentas y periodos", "Seguridad y aislamiento" y "Calendario laboral y SLA". Los demás caben en una.

Probado (`tsc` y `eslint` sin errores), como Ventas:

- **Escritorio:** con todos los grupos abiertos, ninguno de los 58 nombres queda cortado y la barra no desborda a lo ancho (medido); visto con captura.
- **Modo íconos:** en el panel de Contabilidad, "Centros de costo y presupuestos" ocupa dos líneas y nada se sale del panel.
- **Teléfono (375 px):** la barra deslizante abre con los grupos tal como se dejaron, los nombres largos bajan de línea, abrir Soporte ahí se guarda, y la página no desborda.
- **Dos pestañas:** se abrió Compras en una pestaña y en la otra apareció abierto sin recargar.

### Lo que no se probó

- **La barra en teléfono, con captura:** la ventana de pruebas estaba oculta y la captura falló; se midió por código.
- **Los nombres largos como Administrador:** ve las mismas entradas con los mismos nombres; los candados "Plan" no cambiaron.
