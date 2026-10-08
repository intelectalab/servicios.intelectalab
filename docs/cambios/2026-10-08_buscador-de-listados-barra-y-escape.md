# 2026-10-08 — Buscador de los listados: "/" para entrar y Escape para salir

## Qué se pidió

El buscador de Clientes y actores se selecciona con "/". Que también se pueda deseleccionar con Escape, y que "/" y Escape funcionen igual en todos los listados.

## Qué se cambió

| Archivo | Cambio |
| --- | --- |
| `components/ui/proto.tsx` (`SearchInput`) | El atajo vive ahora en el buscador compartido, así que lo tienen todos los listados que lo usan y los que vengan. **"/"** lleva el cursor al buscador y selecciona lo escrito. **Escape** borra lo escrito; con el campo ya vacío, lo suelta. Al pasar el cursor por el campo se lee "Pulsa / para buscar; Esc para salir". |
| `components/dashboard/actors/ActorsList.tsx` | Se quitó su atajo propio de "/": ya lo da el buscador compartido. |

Cuándo **no** se activa "/": al escribir en otro campo (texto, lista, desplegable), con un modal o el buscador global abierto, con Ctrl / Alt pulsados, o si el buscador está en una pestaña que no se ve.

Listados que lo tienen: Clientes y actores, Catálogo de servicios, Cotizaciones, Contratos, Órdenes, Solicitudes del portal y Usuarios. Antes solo lo tenía Clientes y actores, y sin Escape.

## Qué NO se tocó

- El buscador global de la cabecera (Ctrl+K) y su Escape.
- Los módulos desconectados (Clientes, Proveedores, Empleados), que conservan su propio "/".
- Monedas y tipo de cambio no tiene buscador.

## Diferencias con el prototipo

El prototipo no tiene estos atajos.

## Base de datos

Sin cambios.

## Cómo se probó

Como Ventas, enviando las teclas por código (la ventana de pruebas estaba oculta y no admitía teclas reales):

- **Cotizaciones**, el recorrido completo: "/" enfoca el buscador; al escribir "zzz" la tabla queda sin filas; el primer Escape borra el texto, deja el cursor en el campo y vuelve la fila; el segundo Escape suelta el campo. Con el foco en el desplegable "Origen", "/" no hace nada. Con el buscador global abierto, "/" no hace nada.
- **Clientes y actores, Servicios, Contratos, Órdenes y Solicitudes**: "/" enfoca y Escape suelta.

`tsc` y `eslint` sin errores.

Después, como Administrador y **con teclas reales**, en Usuarios:

- "/" enfoca el buscador; al escribir "lucia" quedan 1 de 3 filas; el primer Escape borra y vuelven las 3; el segundo suelta el campo.
- Con el modal Nuevo usuario abierto: "/" con el cursor en el campo Nombre escribe "/" en ese campo, no salta al buscador; "/" sin ningún campo enfocado no hace nada; Escape cierra el modal (no se guardó nada).
- En la pestaña Sedes, que no tiene buscador, "/" no hace nada.

### Lo que no se probó

- Con teclas reales en los otros seis listados (ahí se probó por código; usan la misma pieza).

## Pendiente

Nada.
