# Documentación del rediseño

Aquí queda escrito qué se va cambiando en el ERP al llevarlo al diseño del prototipo
("ERP SaaS de Servicios — Prototipo funcional 1.3"), para poder revisarlo sin leer el código.

## Qué hay en esta carpeta

| Archivo | Para qué sirve |
| --- | --- |
| [criterios.md](criterios.md) | Las reglas con las que se adapta cada pantalla y la paleta del logo. |
| [decisiones-y-pendientes.md](decisiones-y-pendientes.md) | Decisiones que no se deducen del código y lo que quedó pendiente. |
| [cambios/](cambios/) | Un archivo por tanda de trabajo: qué se tocó, por qué y cómo se probó. |

## Registro de cambios

| Fecha | Tema | Archivo |
| --- | --- | --- |
| 2026-10-04 / 05 | Login, sidebar, cabecera, superadmin | [cambios/2026-10-04_login-sidebar-cabecera.md](cambios/2026-10-04_login-sidebar-cabecera.md) |
| 2026-10-05 | Comercial: Servicios y Cotizaciones | [cambios/2026-10-05_comercial.md](cambios/2026-10-05_comercial.md) |
| 2026-10-05 | Comercial: campos y funciones que faltaban | [cambios/2026-10-05_comercial-campos-y-funciones.md](cambios/2026-10-05_comercial-campos-y-funciones.md) |
| 2026-10-05 | Comercial: selección de filas, Excel con formato y paginación | [cambios/2026-10-05_comercial-seleccion-exportar-paginacion.md](cambios/2026-10-05_comercial-seleccion-exportar-paginacion.md) |
| 2026-10-05 | Comercial: vista en teléfono como el prototipo | [cambios/2026-10-05_comercial-responsive.md](cambios/2026-10-05_comercial-responsive.md) |

## Cómo se escribe cada entrada

Cada archivo de `cambios/` sigue el mismo orden:

1. **Qué se pidió** — la tarea, con tus palabras.
2. **Qué se cambió** — archivo por archivo, separando web y API.
3. **Qué NO se tocó** — sobre todo tus módulos ya hechos.
4. **Diferencias con el prototipo** — qué se hizo distinto y por qué.
5. **Base de datos** — migraciones nuevas y quién debe correrlas.
6. **Cómo se probó** — qué se verificó en el navegador y con qué cuenta.
7. **Pendiente** — lo que quedó sin hacer.

Las entradas se escriben al terminar cada cambio, no por adelantado: lo que figura aquí está hecho.
