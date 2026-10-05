# Criterios del rediseño

## Reglas para adaptar una pantalla

- **Mismo estilo y mismos textos que el prototipo**, con los colores del logo en lugar del azul marino y el naranja del prototipo.
- **Sin la parte de demo**: no se trae el selector de empresa o rol de demostración, las cuentas precargadas, "Flujos end-to-end", el inspector de API, "Restablecer demo" ni "Documentación". Tampoco las notas explicativas que aparecen bajo el título de cada pantalla (encima del buscador).
- **No se cambia la lógica que ya funciona.** Los módulos ya hechos (Clientes, Proveedores, Empleados, Planes SaaS) se tocan lo mínimo.
- **No se quita lo que ya existía**: la hamburguesa (deja la barra en modo íconos), los tooltips y el botón de tema claro/oscuro se estilizan, no se eliminan.
- Lo que el prototipo muestra y todavía no existe se implementa de verdad, no solo de apariencia.
- Cada cambio se prueba en el navegador contra la app local (`localhost:3000`), no solo compilando.
- Cuando algo queda distinto del prototipo, se dice cuál es la diferencia y el motivo.

## Paleta del logo

Medida sobre `apps/web/public/logo.png`.

| Uso | Color |
| --- | --- |
| Azul de marca (trazo y "grupo de soluciones") | `#1B8FC9` |
| Negro cálido ("creativas") | `#1D1D1B` |
| Blanco (fondo; logo invertido en `logo-blanco.png`) | `#FFFFFF` |

Tonos derivados del mismo azul:

| Uso | Color |
| --- | --- |
| Hover, enlaces, texto sobre blanco | `#1679AB` |
| Fondo profundo | `#0F5A82` |
| Fondo suave / foco | `#E3F2FA` |
| Énfasis sobre azul | `#BFE6F8` |

- Fondo del sidebar y del panel del login: degradado `#1B8FC9 → #1679AB → #0F5A82`.
- Donde el prototipo usa naranja (barra del ítem activo, contadores) se usa **blanco**: el logo no tiene naranja.

### A tener en cuenta en el código

- `apps/web/src/app/globals.css` conserva los azules anteriores (`--app-brand: #0c74a6`, `--app-brand-deep: #064d6e`), que siguen usando las pantallas aún no rediseñadas. No se han cambiado.
- En tema oscuro, `globals.css` repinta cualquier clase que contenga `bg-white` (también `bg-white/10` o `hover:bg-white/…`). Sobre fondos de marca hay que escribir `bg-[rgb(255_255_255/0.1)]` o `bg-[#fff]`.

## Prototipo de referencia

Un solo HTML (~900 KB). Paleta original: azul marino `#073C7A`, celeste `#4A93CB`, naranja `#F5A524`, cian `#10AFC3`, tipografía Poppins. De ahí se toman estructura, medidas y textos; los colores se sustituyen por los de arriba.
