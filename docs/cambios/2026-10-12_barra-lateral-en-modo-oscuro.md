# 2026-10-12 — La barra lateral baja de tono en modo oscuro

## Qué se pidió

"¿Crees que al pasarlo al modo oscuro, el sidebar debería oscurecerse un poco?" — te propuse que sí y dijiste que lo hiciera.

Hasta hoy la barra era igual en los dos temas. En oscuro quedaba como la zona más brillante de la pantalla, junto a un contenido azul noche.

## Qué se cambió

| Archivo | Cambio |
| --- | --- |
| `web/src/app/globals.css` | Variables nuevas de la barra (`--side-bg`, `--side-flyout`, `--side-active`, `--side-group-active`, `--side-ring`), con un valor para claro y otro para oscuro. |
| `web/src/components/layout/Sidebar.tsx` | El fondo de la barra, el del panel de la barra replegada, la pantalla activa y el borde del punto verde del usuario usan esas variables en vez de colores fijos. |

En oscuro:

- **Fondo:** el mismo degradado del logo, más profundo (de `#14658F` a `#0A344D`; en claro sigue de `#1B8FC9` a `#0F5A82`). Sigue siendo la barra azul, no un panel gris.
- **Pantalla activa:** se pinta con el azul vivo del logo, así que resalta más que antes (en claro sigue con el velo blanco de siempre).
- El panel que se abre desde la barra replegada baja de tono igual. El menú en teléfono es la misma pieza.

## Qué NO se tocó

- **El modo claro:** mismos valores que antes (comprobado en el navegador).
- Los botones azules y el panel azul del inicio de sesión: iguales en los dos temas.
- El contenido del menú, los grupos que se recuerdan abiertos y los candados de plan.

## Diferencias con el prototipo

El prototipo no tiene modo oscuro; es una decisión propia.

## Base de datos

Sin cambios.

## Cómo se probó

`tsc` y `eslint` sin errores. Sin cambios en la API.

En el navegador (`app.localhost:3000`), como Gerencia:

- **Claro:** el degradado y la pantalla activa tienen los mismos valores que antes; captura.
- **Oscuro:** degradado nuevo y pantalla activa en azul vivo; captura.
- **Legibilidad en oscuro**, calculada con los colores reales: texto del menú 5,1 a 9,9; pantalla activa 4,4 a 5,8; pantallas "próximamente" 3,4 a 5,7 (van atenuadas a propósito).
- **Barra replegada:** el panel de un módulo sale con el degradado oscuro.

### Lo que no se probó

- **El menú en teléfono (cajón):** es el mismo componente con las mismas variables; no se abrió aparte.
- **La consola del superadmin:** usa la misma barra; no se entró con esa cuenta.

## Pendiente

- Si el tono te parece poco o mucho, se ajusta cambiando solo los tres colores de `--side-bg` en el bloque oscuro de `globals.css`.
