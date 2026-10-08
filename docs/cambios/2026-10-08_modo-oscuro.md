# 2026-10-08 — Modo oscuro con colores propios

## Qué se pidió

Revisar cómo se ve el sistema en modo oscuro (se usa casi siempre en claro) y que tenga los colores adecuados.

## Qué estaba mal

1. **El inicio de sesión ignoraba el tema.** El tema oscuro se aplicaba recién al entrar (lo ponía la cabecera). En el inicio de sesión, recuperar contraseña y la pantalla de empresa bloqueada todo salía en claro, y con el equipo en modo oscuro el fondo de la página quedaba negro detrás de un panel blanco.
2. **Las pantallas nuevas usaban colores fijos pensados para fondo claro**: etiquetas de rol y de estado en pastel claro, enlaces en azul oscuro, texto de error en rojo oscuro, bordes de campos en gris claro. Sobre fondo oscuro, los enlaces y errores se leían mal (contraste 3 a 1) y las etiquetas brillaban como parches claros.
3. **Las casillas, los campos de fecha y las barras de desplazamiento** seguían en claro.
4. **Las pantallas con el estilo anterior** (consola del superadmin: Planes, Empresas, Suscripciones; y el aviso "Módulo no disponible en tu plan") quedaban con texto oscuro sobre fondo oscuro.
5. En el panel azul del inicio de sesión, las tres tarjetas translúcidas se volvían opacas en oscuro.

## Qué se cambió

| Archivo | Cambio |
| --- | --- |
| `app/layout.tsx` | El tema se aplica **antes de pintar** en todo el sistema, también antes de iniciar sesión: el elegido con el botón de la cabecera o, si nunca se eligió, el del equipo. La página pública (`/`) no tiene tema oscuro y no cambia. |
| `app/globals.css` | **Paleta con dos juegos de valores** (variables `--p-…`): en claro son exactamente los colores de siempre; en oscuro, su versión para fondo oscuro (fondos tenues oscuros con texto claro del mismo tono). Controles nativos en oscuro (`color-scheme`). Reglas para que los grises y pasteles de las pantallas con el estilo anterior tomen la paleta del tema en oscuro. Se quitó la regla que ponía el fondo negro según el equipo. |
| 26 componentes (`proto.tsx`, listados, fichas, modales, acceso, cabecera, buscador…) | 132 colores fijos pasan a usar esas variables. Es un cambio mecánico: `text-[#1679AB]` → `text-[color:var(--p-brand-text)]`, etc. |
| `components/auth/AuthShell.tsx`, `LoginForm.tsx`, `ResetPasswordForm.tsx` | El texto de los campos, el enlace "¿Olvidó su contraseña?", la casilla "Recordarme" y los fondos suaves siguen el tema. Las tarjetas del panel azul vuelven a ser translúcidas en oscuro. |
| `app/blocked/page.tsx` | El aviso "El acceso sigue sin estar disponible" sigue el tema. |

### La paleta en oscuro

| Uso | Claro | Oscuro |
| --- | --- | --- |
| Enlaces y texto en azul | `#1679AB` | `#7CCBF2` |
| Azul del logo como texto o ícono | `#1B8FC9` | `#4DB4E8` |
| Fondo suave azul (etiquetas, selección) / su texto | `#E3F2FA` / `#0F5A82` | `#1A4158` / `#B5E1F7` |
| Borde de campos | `#CFD5DE` | `#47626F` |
| Error: texto / fondo | `#D93A40` / `#FDECEC` | `#FF8F88` / `#4A2529` |
| Correcto: texto / fondo | `#1B8E4B` / `#E4F6EB` | `#7FDCA6` / `#1D4534` |
| Aviso: texto / fondo | `#B26E00` / `#FFF4DB` | `#F2BD6E` / `#47380F` |
| Neutro: texto / fondo | `#4B5566` / `#E6EAF0` | `#C6D5DC` / `#2B414C` |

Los botones azules (`#1B8FC9` con texto blanco), la barra lateral y el panel azul del inicio de sesión son iguales en los dos temas.

## Qué NO se tocó

- **El modo claro.** Las variables valen en claro lo mismo que los colores que reemplazan. Dos diferencias mínimas: el fondo al pasar el cursor por "Volver" en las pantallas de acceso (`#F3F5F8` → `#F2F8FA`) y el texto del aviso de la pantalla de bloqueo (`#8A5500` → `#6B4300`).
- La página pública (landing): sigue solo en claro.
- Tus módulos con el estilo anterior (consola del superadmin, Clientes, Proveedores, Empleados): no se editó ningún archivo suyo; el oscuro se resuelve desde `globals.css`.
- Los fondos del tema oscuro que ya existían (`--app-surface`, `--app-page`…) no cambiaron.

## Cómo se probó

La ventana del navegador de pruebas quedó oculta durante esta tanda y **no se pudieron tomar capturas**. Se verificó midiendo por código, en cada pantalla y con el tema oscuro puesto, el contraste de cada texto visible contra su fondo real y buscando superficies claras sin oscurecer:

- Antes del cambio, en el inicio de sesión: el enlace "¿Olvidó su contraseña?" medía 3,0 a 1.
- Después, **sin ningún texto por debajo del mínimo ni superficie clara** en: inicio de sesión (también con el mensaje de error), Clientes y actores (listado, ficha, modal Nueva organización, Importar, Filtros por columna), Catálogo de servicios (listado y ficha), Cotizaciones (listado, ficha aprobada, ficha rechazada, nueva cotización), Contratos (listado y ficha), Órdenes (ficha), Solicitudes del portal, Monedas y tipo de cambio, Usuarios (listado y modal Editar), Roles y permisos, Mi perfil. Lo único que queda por debajo de 4,5 es el texto blanco sobre el azul del logo (3,6), que es igual en claro.
- Valores reales medidos en oscuro: etiqueta de rol `#7CCBF2` sobre `#1A4158`; estado Activo `#7FDCA6` sobre `#1D4534`; casillas en modo oscuro.
- En claro, con el mismo método: las etiquetas conservan sus colores originales exactos.

`tsc` y `eslint` sin errores. No hay pruebas automáticas de la web.

### Lo que no se probó

- **Nada se vio con los ojos**: todo es medición. El contraste se puede medir; si un tono "se ve bien" hay que mirarlo. Conviene que lo revises tú en oscuro y me digas qué color no te convence.
- **La consola del superadmin**: no pude entrar (la contraseña de esa cuenta fue rechazada y no reintenté para no bloquearla). Sus colores se revisaron leyendo el código: todos los que usa están cubiertos por las reglas nuevas.
- El aviso "Módulo no disponible en tu plan" (no hay módulos con candado en el menú de la empresa de prueba), el modal Cambiar contraseña, el buscador global, los avisos emergentes y la pantalla de empresa bloqueada.
- A 375 px.
- Los módulos desconectados (Clientes, Proveedores, Empleados).

## Pendiente

- Para lo nuevo: no escribir colores fijos en las clases; usar las variables `--p-…` (o las `--app-…`) para que los dos temas salgan bien.
- Los avisos emergentes (fondo `#1C2533`) casi no se distinguen del fondo en oscuro; solo los separa la sombra.
- La landing no tiene modo oscuro.
