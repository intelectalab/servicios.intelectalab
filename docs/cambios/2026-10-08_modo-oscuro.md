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

- En la primera parte nada se vio con los ojos (solo medición); en la segunda se vieron cuatro pantallas (ver abajo). El resto sigue solo medido: conviene que lo revises tú en oscuro y me digas qué color no te convence.
- La consola del superadmin no se pudo probar en la primera parte (contraseña rechazada); se probó después, ver "Tercera parte".
- El aviso "Módulo no disponible en tu plan" (no hay módulos con candado en el menú de la empresa de prueba), el modal Cambiar contraseña, el buscador global, los avisos emergentes y la pantalla de empresa bloqueada.
- A 375 px.
- Los módulos desconectados (Clientes, Proveedores, Empleados).

## Pendiente

- Para lo nuevo: no escribir colores fijos en las clases; usar las variables `--p-…` (o las `--app-…`) para que los dos temas salgan bien.
- La landing no tiene modo oscuro.

## Segunda parte: avisos emergentes y revisión a la vista

- **Avisos emergentes** (los de "Permiso actualizado", "Usuario creado"…): en oscuro tenían casi el mismo color que el fondo y solo los separaba la sombra. Ahora, en oscuro, van en un tono más claro que las tarjetas (`#2C4C5B`), con un borde fino (`#5A8194`) y una sombra más marcada. En claro quedan exactamente igual (`#1C2533`, sin borde). Cambios en `proto.tsx` (el aviso usa variables) y `globals.css` (`--p-toast-…`).
- Al volver a funcionar las capturas se revisó **a la vista**, en oscuro: el inicio de sesión (panel derecho oscuro, logo blanco, campos y enlace legibles, tarjetas translúcidas en el panel azul), Clientes y actores (etiquetas de rol y estado en tono oscuro, casillas oscuras), Cotizaciones (indicadores, pestañas, etiqueta de alcance) y la ficha de un actor (tarjetas, etiquetas, botones con candado). El aviso emergente se vio sobre el inicio de sesión con sus clases reales; no se disparó uno desde una acción.

## Tercera parte: consola del superadmin

Con la contraseña correcta de la cuenta de plataforma se revisó la consola en oscuro, sin cambiar ningún archivo:

- **Planes SaaS** (listado, indicadores, pestañas, buscador) y su **panel de edición** en las siete pestañas (Información, Precio y suscripción, Módulos, Límites, Consumo, Soporte, Características): vistos con captura (listado, Información y Módulos) y medidos. Sin textos por debajo del mínimo ni superficies claras; los campos toman fondo oscuro y texto claro. Se cerró sin guardar.
- **Dashboard, Tenants / Empresas, Suscripciones y Mi perfil**: medidos, sin nada por debajo del mínimo. De estas cuatro no hay captura (la ventana volvió a ocultarse): no se vieron con los ojos.
