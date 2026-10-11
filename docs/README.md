# Documentación del rediseño

Aquí queda escrito qué se va cambiando en el ERP al llevarlo al diseño del prototipo
("ERP SaaS de Servicios — Prototipo funcional 1.3"), para poder revisarlo sin leer el código.

## Qué hay en esta carpeta

| Archivo | Para qué sirve |
| --- | --- |
| [criterios.md](criterios.md) | Las reglas con las que se adapta cada pantalla y la paleta del logo. |
| [decisiones-y-pendientes.md](decisiones-y-pendientes.md) | Decisiones que no se deducen del código y lo que quedó pendiente. |
| [comparacion-con-el-kit.md](comparacion-con-el-kit.md) | Comparación del sistema con el kit de desarrollo recibido (que se usa como guía): qué cumple, qué no y qué choca con decisiones ya tomadas. |
| [cambios/](cambios/) | Un archivo por tanda de trabajo: qué se tocó, por qué y cómo se probó. |

## Registro de cambios

| Fecha | Tema | Archivo |
| --- | --- | --- |
| 2026-10-04 / 05 | Login, sidebar, cabecera, superadmin | [cambios/2026-10-04_login-sidebar-cabecera.md](cambios/2026-10-04_login-sidebar-cabecera.md) |
| 2026-10-05 | Comercial: Servicios y Cotizaciones | [cambios/2026-10-05_comercial.md](cambios/2026-10-05_comercial.md) |
| 2026-10-05 | Comercial: campos y funciones que faltaban | [cambios/2026-10-05_comercial-campos-y-funciones.md](cambios/2026-10-05_comercial-campos-y-funciones.md) |
| 2026-10-05 | Comercial: selección de filas, Excel con formato y paginación | [cambios/2026-10-05_comercial-seleccion-exportar-paginacion.md](cambios/2026-10-05_comercial-seleccion-exportar-paginacion.md) |
| 2026-10-05 | Comercial: vista en teléfono como el prototipo | [cambios/2026-10-05_comercial-responsive.md](cambios/2026-10-05_comercial-responsive.md) |
| 2026-10-05 | Comercial: tarjetas en teléfono, padding de tablas y tarjetas movibles | [cambios/2026-10-05_comercial-tarjetas-movibles.md](cambios/2026-10-05_comercial-tarjetas-movibles.md) |
| 2026-10-05 | Comercial: revisión de lo que quedó sin ver, asa táctil más grande y líneas alineadas en teléfono | [cambios/2026-10-05_comercial-revision-a-la-vista.md](cambios/2026-10-05_comercial-revision-a-la-vista.md) |
| 2026-10-05 | Cotizaciones: indicadores a todo el ancho, pestañas en teléfono, columna y filtro Origen, avatar del responsable | [cambios/2026-10-05_cotizaciones-listado-ajustes.md](cambios/2026-10-05_cotizaciones-listado-ajustes.md) |
| 2026-10-05 | Cotizaciones en dólares, pantalla Monedas y tipo de cambio y desplegables con buscador | [cambios/2026-10-05_cotizaciones-dolares-y-buscador.md](cambios/2026-10-05_cotizaciones-dolares-y-buscador.md) |
| 2026-10-05 | Ficha de cotización: precio versión, Trazabilidad, Aprobaciones e Historial como el prototipo | [cambios/2026-10-05_cotizacion-ficha-como-prototipo.md](cambios/2026-10-05_cotizacion-ficha-como-prototipo.md) |
| 2026-10-05 | Cotización: permiso de aprobar y segregación de funciones, Descargar PDF, Visible en portal | [cambios/2026-10-05_cotizacion-aprobacion-pdf-portal.md](cambios/2026-10-05_cotizacion-aprobacion-pdf-portal.md) |
| 2026-10-05 | PDF de cotización completo (RUC, dirección, correo) y pantalla Roles y permisos | [cambios/2026-10-05_roles-y-permisos-pdf-completo.md](cambios/2026-10-05_roles-y-permisos-pdf-completo.md) |
| 2026-10-05 | Pantalla Usuarios (Administración) | [cambios/2026-10-05_usuarios.md](cambios/2026-10-05_usuarios.md) |
| 2026-10-05 | Botones sin permiso: a la vista, con candado y mensaje | [cambios/2026-10-05_botones-sin-permiso.md](cambios/2026-10-05_botones-sin-permiso.md) |
| 2026-10-05 | Cotización: botón Orden directa | [cambios/2026-10-05_orden-directa.md](cambios/2026-10-05_orden-directa.md) |
| 2026-10-05 | Cotización: botones por estado, revisados contra el prototipo | [cambios/2026-10-05_cotizacion-botones-por-estado.md](cambios/2026-10-05_cotizacion-botones-por-estado.md) |
| 2026-10-06 | Barra lateral replegada: un ícono por módulo con su panel de pantallas | [cambios/2026-10-06_sidebar-replegado-grupos.md](cambios/2026-10-06_sidebar-replegado-grupos.md) |
| 2026-10-07 | Comercial: Contratos (listado, ficha, estados, orden, PDF) | [cambios/2026-10-07_contratos.md](cambios/2026-10-07_contratos.md) |
| 2026-10-07 | Comercial: Órdenes (listado, ficha, transiciones, PDF) | [cambios/2026-10-07_ordenes.md](cambios/2026-10-07_ordenes.md) |
| 2026-10-07 | Contratos: botones de firma y adjuntar, OT / proyectos, instancias recurrentes, historial | [cambios/2026-10-07_contratos-ajustes.md](cambios/2026-10-07_contratos-ajustes.md) |
| 2026-10-07 | Comercial: Solicitudes del portal (bandeja, cotizar, rechazar) | [cambios/2026-10-07_solicitudes-portal.md](cambios/2026-10-07_solicitudes-portal.md) |
| 2026-10-07 | Comercial: Clientes y actores (módulo nuevo de prueba, junto a Clientes, Proveedores y Empleados) | [cambios/2026-10-07_clientes-y-actores.md](cambios/2026-10-07_clientes-y-actores.md) |
| 2026-10-08 | Importar desde Excel/CSV en Clientes y actores y en Catálogo de servicios | [cambios/2026-10-08_importar-actores-y-servicios.md](cambios/2026-10-08_importar-actores-y-servicios.md) |
| 2026-10-08 | Clientes, Proveedores y Empleados desconectados; queda Clientes y actores | [cambios/2026-10-08_desconectar-clientes-proveedores-empleados.md](cambios/2026-10-08_desconectar-clientes-proveedores-empleados.md) |
| 2026-10-08 | Dos dominios: la página pública y el sistema (`SITE_URL` / `APP_URL`) | [cambios/2026-10-08_dos-dominios-landing-y-sistema.md](cambios/2026-10-08_dos-dominios-landing-y-sistema.md) |
| 2026-10-08 | Tablas de los listados como la de Clientes y actores: cabecera y casillas fijas, mover e inmovilizar columnas | [cambios/2026-10-08_tablas-de-listado-como-actores.md](cambios/2026-10-08_tablas-de-listado-como-actores.md) |
| 2026-10-08 | Orden real desde la cabecera, paginación que responde y carga rápida de Clientes y actores | [cambios/2026-10-08_orden-real-paginacion-y-carga.md](cambios/2026-10-08_orden-real-paginacion-y-carga.md) |
| 2026-10-08 | Filtrar por columna en Cotizaciones, Contratos, Órdenes, Servicios y Solicitudes | [cambios/2026-10-08_filtrar-por-columna-en-los-listados.md](cambios/2026-10-08_filtrar-por-columna-en-los-listados.md) |
| 2026-10-08 | Protecciones del sistema: aislamiento, auditoría inalterable, módulos del plan en la API, acceso y conversiones únicas | [cambios/2026-10-08_protecciones-del-sistema.md](cambios/2026-10-08_protecciones-del-sistema.md) |
| 2026-10-08 | Alcance en los permisos: lo propio, equipo, sede o toda la empresa; sedes y equipos en Usuarios | [cambios/2026-10-08_alcance-en-permisos.md](cambios/2026-10-08_alcance-en-permisos.md) |
| 2026-10-08 | Modo oscuro con colores propios: tema desde el inicio de sesión y paleta para fondo oscuro | [cambios/2026-10-08_modo-oscuro.md](cambios/2026-10-08_modo-oscuro.md) |
| 2026-10-08 | Buscador de los listados: "/" para entrar y Escape para salir, en todos | [cambios/2026-10-08_buscador-de-listados-barra-y-escape.md](cambios/2026-10-08_buscador-de-listados-barra-y-escape.md) |
| 2026-10-08 | CRM, flujo principal: Leads y Pipeline de oportunidades (tabla y tablero), ganar / perder y convertir a cliente sin duplicar | [cambios/2026-10-08_crm-leads-y-pipeline.md](cambios/2026-10-08_crm-leads-y-pipeline.md) |
| 2026-10-08 | Exportar como permiso aparte en los nueve listados, comprobado en la API, con tope de filas por página y auditoría | [cambios/2026-10-08_permiso-de-exportar.md](cambios/2026-10-08_permiso-de-exportar.md) |
| 2026-10-08 | Mi perfil y seguridad: cuenta, cambiar contraseña, sesiones recientes (cerrar las demás) y preferencias de notificación | [cambios/2026-10-08_mi-perfil.md](cambios/2026-10-08_mi-perfil.md) |
| 2026-10-08 | Sedes adicionales por persona: amplían lo que se ve con el alcance "Sede"; lo creado nace en la sede principal | [cambios/2026-10-08_sedes-adicionales.md](cambios/2026-10-08_sedes-adicionales.md) |
| 2026-10-08 | Pantalla Auditoría (Control): movimientos de la empresa con buscar, filtrar, ordenar, detalle con antes y después, y exportar con su permiso | [cambios/2026-10-08_auditoria.md](cambios/2026-10-08_auditoria.md) |
| 2026-10-08 | El menú lateral recuerda qué grupos están abiertos y cerrados al recargar | [cambios/2026-10-08_menu-recuerda-grupos.md](cambios/2026-10-08_menu-recuerda-grupos.md) |
| 2026-10-09 | CRM: Actividades (con alcance), Campañas (miembros con consentimiento, resultados dentro del alcance) y Puntuación automática de leads | [cambios/2026-10-09_crm-actividades-campanas-puntuacion.md](cambios/2026-10-09_crm-actividades-campanas-puntuacion.md) |

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
