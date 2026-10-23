# 2026-10-23 — Hallazgos del inventario de botones

## Qué se pidió

Al armar el inventario de botones de todas las pantallas para la presentación, aparecieron cosas que no cumplían las reglas del sistema. Pediste: "corrige los hallazgos del inventario de botones: los controles sin candado, las exportaciones sin auditoría, el enlace roto de Cambiar de plan y lo que haga falta; pregúntame lo de Solo lectura en el portal".

Sobre Solo lectura respondiste: **solo ver tickets**.

## Qué se cambió

### Controles que, sin permiso, desaparecían o quedaban sin candado

Ahora se ven deshabilitados, con candado y el mensaje "Tu rol no tiene permiso …":

| Dónde | Control | Permiso |
|---|---|---|
| Tableros de Leads, Pipeline, Campañas, Proyectos, tareas del proyecto y Órdenes de trabajo | "Mover a…" de cada tarjeta (pieza `MoveLocked` de `kanban.tsx`) | `leads.manage`, `opportunities.manage`, `campaigns.manage`, `projects.manage`, `tasks.manage`, `work_orders.manage` |
| Clientes y actores | "Agregar columna nueva" | `core.manage` |
| Clientes y actores | Lápiz de edición en la celda (candado al pasar el cursor) | `core.manage` |
| Ficha de la oportunidad | Botones de etapa | `opportunities.manage` |
| Ficha del proyecto | Lápiz de editar tarea | `tasks.manage` |
| Roles y permisos | Matriz: etiqueta "Solo lectura" con candado | `users.manage` |
| Roles y permisos | Reglas de la empresa y tolerancia del cruce | `tenant.manage` |
| Calendario laboral y SLA | Etiqueta "Solo lectura" con candado en Horario y Tiempos; candado en lugar de la × de cada feriado | `sla.manage` |
| Ficha del ticket | Casilla "Nota interna" | `tickets.manage` |

### Exportar

- **Avisos del portal** (Usuarios del portal → Avisos): la exportación ahora queda registrada en la auditoría, como las demás.
- **Exportar al pie en las tablas que no lo tenían:**
  - Usuarios → **Sedes** y **Equipos** (`users.export`);
  - **Roles y permisos** (la matriz del rol abierto, un recurso por fila, con su alcance; `users.export`);
  - las **versiones de un documento** (`documents.export`).

  Todas quedan en la auditoría.
- **Sin cambio:** Monedas y tipo de cambio sigue exportando sin permiso propio, porque así lo decidiste el 2026-10-08. Si quieres que pida permiso, hace falta un permiso nuevo (`fiscal.export`).

### "Módulo no disponible en tu plan" (menú lateral)

- "Cambiar de plan" llevaba a `/settings/billing`, que no existe. Ahora el botón es **"Ver planes"**: abre en otra pestaña los planes de la página pública (`SITE_URL/#plans`). El otro botón pasa de "Cancelar" a "Entendido".
- La ventana usaba colores fijos (blanco y gris). Ahora usa los del tema, así que también se ve bien en modo oscuro, y tiene el ícono de candado.

### Portal: Solo lectura solo ve los tickets

- Nueva sección interna del portal, `support_write` ("Crear y responder tickets"), solo para Portal Admin.
- La API rechaza con 403 que Solo lectura cree o responda un ticket. Ver sus tickets y la conversación sigue igual.
- En el portal, "Crear ticket" y "Enviar" salen deshabilitados con candado y "Tu rol del portal solo puede ver los tickets".
- En Roles y permisos → Cliente (Portal), la nota de Tickets lo dice.

### Usuarios: desactivar se confirma

"Desactivar" o "Activar" un usuario ahora pide confirmación, como en Usuarios del portal. La ventana dice que se cierran sus sesiones y que se conservan su cuenta y su historial.

## Cómo se probó

- **`php artisan test`: 280 pruebas pasan.** La prueba de tickets del portal ahora comprueba que Solo lectura lista y abre sus tickets, y que crear y responder le devuelven 403.
- **`tsc` y `eslint` sin errores.** Solo quedan dos avisos que ya estaban en `lib/api.ts`.
- **Navegador** (`app.localhost:3000`):
  - Finanzas: 40 lápices con candado y "Agregar columna nueva" con candado en Clientes y actores; "Mover a…" con candado en el tablero de Proyectos.
  - Soporte: "Mover a…" con candado en las 4 tarjetas del tablero de OT.
  - Un módulo simulado fuera del plan: la ventana nueva, con "Ver planes" hacia `http://localhost:3000/#plans` (la sección existe) en otra pestaña.
  - Portal (Rosa, Portal Admin): crea tickets. Con el rol Solo lectura simulado, "Crear ticket" y "Enviar" salen con candado y la ficha del ticket se abre.
- **No probado en el navegador:** Roles y permisos, Sedes, Equipos y la confirmación de Usuarios, porque piden una cuenta con `users.view` (Administrador o Gerencia) y sus contraseñas no estaban en la sesión. Lo mismo para el exportar de las versiones de un documento.
