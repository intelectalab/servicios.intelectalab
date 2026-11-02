# Documentación del rediseño

Aquí queda escrito qué se va cambiando en el ERP al llevarlo al diseño del prototipo
("ERP SaaS de Servicios — Prototipo funcional 1.3"), para poder revisarlo sin leer el código.

## Qué hay en esta carpeta

| Archivo | Para qué sirve |
| --- | --- |
| [criterios.md](criterios.md) | Las reglas con las que se adapta cada pantalla y la paleta del logo. |
| [decisiones-y-pendientes.md](decisiones-y-pendientes.md) | Decisiones que no se deducen del código y lo que quedó pendiente. |
| [comparacion-con-el-kit.md](comparacion-con-el-kit.md) | Comparación del sistema con el kit de desarrollo recibido (que se usa como guía): qué cumple, qué no y qué choca con decisiones ya tomadas. |
| [propuesta-antivirus.md](propuesta-antivirus.md) | Opciones de antivirus real para los archivos (para revisar con quien administra el VPS) y la recomendada. |
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
| 2026-10-12 | Carga de las pantallas del CRM: el listado primero y las lecturas repetidas compartidas | [cambios/2026-10-12_carga-de-pantallas-crm.md](cambios/2026-10-12_carga-de-pantallas-crm.md) |
| 2026-10-12 | Operaciones, primera parte: Servicios contratados y Órdenes de trabajo (tablero, ficha, checklist, asignación sin choques de agenda, alcance por rol) | [cambios/2026-10-12_operaciones-servicios-contratados-y-ot.md](cambios/2026-10-12_operaciones-servicios-contratados-y-ot.md) |
| 2026-10-12 | La barra lateral baja de tono en modo oscuro; el azul vivo queda para la pantalla activa | [cambios/2026-10-12_barra-lateral-en-modo-oscuro.md](cambios/2026-10-12_barra-lateral-en-modo-oscuro.md) |
| 2026-10-13 | Operaciones, segunda parte: Proyectos (tareas, entregables, margen) y Horas y costos (sin solapes, nadie aprueba las suyas); arreglo del cambio de vista en los listados con tablero | [cambios/2026-10-13_operaciones-proyectos-horas-y-costos.md](cambios/2026-10-13_operaciones-proyectos-horas-y-costos.md) |
| 2026-10-13 | Ficha del proyecto como el prototipo: cinco indicadores y pestañas (Tareas, Horas, Costos, Entregables, Resumen y estados); margen de sus tablas y archivo real del entregable ("Subir documento" con versiones) | [cambios/2026-10-13_proyecto-ficha-con-pestanas.md](cambios/2026-10-13_proyecto-ficha-con-pestanas.md) |
| 2026-10-13 | Operaciones: Agenda semanal (eventos, órdenes de trabajo programadas, aviso de cruces, alcance por rol); vista de mes y filtro por persona | [cambios/2026-10-13_operaciones-agenda.md](cambios/2026-10-13_operaciones-agenda.md) |
| 2026-10-13 | Operaciones: App del técnico (visitas del día, mapa y ruta, checklist, fotos de evidencia, trabajo sin conexión con sincronización; instalable en el teléfono) | [cambios/2026-10-13_operaciones-app-del-tecnico.md](cambios/2026-10-13_operaciones-app-del-tecnico.md) |
| 2026-10-13 | Finanzas y CxC, primera parte: Por facturar (eventos facturables), Comprobantes (emisión, nota de crédito, PDF), Cuentas por cobrar (antigüedad, movimientos) y Cobros (aplicar, conciliar, reembolsar); permiso propio para el tipo de cambio | [cambios/2026-10-13_finanzas-facturacion-cxc-y-cobros.md](cambios/2026-10-13_finanzas-facturacion-cxc-y-cobros.md) |
| 2026-10-13 | Finanzas: comprobantes listos para conectar el proveedor de SUNAT (contrato del proveedor, envío tras emitir, respuesta guardada, reintento) | [cambios/2026-10-13_finanzas-listo-para-proveedor-sunat.md](cambios/2026-10-13_finanzas-listo-para-proveedor-sunat.md) |
| 2026-10-13 | Finanzas: Recurrencia (ciclos y renovaciones), Bancos y caja (cuentas, movimientos, conciliación por extracto, caja chica) y Facturación electrónica (configuración, series, contingencia, bajas, detracciones y retenciones) | [cambios/2026-10-13_finanzas-recurrencia-bancos-y-facturacion-electronica.md](cambios/2026-10-13_finanzas-recurrencia-bancos-y-facturacion-electronica.md) |
| 2026-10-13 | Compras: Órdenes de compra (aprobación con segregación, recepción, PDF), Comprobantes de proveedor (sin duplicados, cruce de 3 vías, detracción y retención de 4.ª) y Cuentas por pagar (vencimientos y pago con depósito de detracción); centros de costo y cuenta BN del proveedor | [cambios/2026-10-13_compras-ordenes-comprobantes-y-cuentas-por-pagar.md](cambios/2026-10-13_compras-ordenes-comprobantes-y-cuentas-por-pagar.md) |
| 2026-10-14 | Contabilidad: asientos automáticos de cada documento, Libro diario (asiento manual y extorno), Mayor y balance de comprobación, Estados financieros, Centros de costo y presupuestos (control en las órdenes de compra) y Plan de cuentas, reglas y periodos | [cambios/2026-10-14_contabilidad.md](cambios/2026-10-14_contabilidad.md) |
| 2026-10-14 | Tesorería (Bancos y caja): caja chica con centro de costo y cuenta del plan, historial de extractos, exportar el extracto, archivo del extracto guardado y transferencias protegidas en la base | [cambios/2026-10-14_tesoreria-caja-chica-extractos-transferencias.md](cambios/2026-10-14_tesoreria-caja-chica-extractos-transferencias.md) |
| 2026-10-14 | Pendientes resueltos: pagar como permiso propio, tiempo límite de lecturas, pantalla sin permiso, cabeceras en dos líneas, contrato que arrastra sus servicios, plantillas de checklist, cierre de ejercicio, PDF e importación de asientos, corrección de gasto de caja, reversión de pago y tolerancia del cruce | [cambios/2026-10-14_pendientes-resueltos.md](cambios/2026-10-14_pendientes-resueltos.md) |
| 2026-10-15 | Soporte: Tickets y SLA (triage, SLA en horario hábil con feriados y Premium, pausa, escalar, conversación con notas internas, OT desde el ticket), Garantías (cobertura, aprobar o rechazar) y Calendario laboral y SLA en Administración; rol Soporte | [cambios/2026-10-15_soporte-tickets-sla-garantias.md](cambios/2026-10-15_soporte-tickets-sla-garantias.md) |
| 2026-10-20 | Despliegue en el VPS: arreglo de la web con un solo dominio, datos copiados, carpeta conectada a GitHub y scripts de despliegue y respaldo |
| 2026-10-20 | Interruptor `ADMIN_MFA_REQUIRED` para el MFA obligatorio del Administrador y casilla con candado en Usuarios | [cambios/2026-10-20_mfa-administrador-interruptor.md](cambios/2026-10-20_mfa-administrador-interruptor.md) |
| 2026-10-21 | Diseño de los correos con código (verificación y contraseña): logo, código destacado y versión en texto | [cambios/2026-10-21_correo-con-diseno.md](cambios/2026-10-21_correo-con-diseno.md) |
| 2026-10-21 | Módulos de los planes de menor a mayor: Base 9, Profesional 19, Enterprise con los 22 (PC y VPS) | [cambios/2026-10-21_planes-modulos-escalonados.md](cambios/2026-10-21_planes-modulos-escalonados.md) |
| 2026-10-21 | Correo de bienvenida y aviso al superadmin al registrarse; en el login, "Volver al inicio" y "Ver planes" | [cambios/2026-10-21_correos-de-registro-y-enlaces-del-login.md](cambios/2026-10-21_correos-de-registro-y-enlaces-del-login.md) |
| 2026-10-21 | VPS: PHP 8.3 → 8.3.36 y 8.4 → 8.4.27 por PHP2Shell, con respaldo y cómo volver atrás | [cambios/2026-10-21_actualizacion-php-php2shell.md](cambios/2026-10-21_actualizacion-php-php2shell.md) | [cambios/2026-10-20_despliegue-en-el-vps.md](cambios/2026-10-20_despliegue-en-el-vps.md) |
| 2026-10-22 | Documentos en el disco del servidor (`DOCUMENTS_DRIVER=local`) con descarga por enlace firmado; los 8 archivos pasados al VPS | [cambios/2026-10-22_documentos-en-el-disco-del-servidor.md](cambios/2026-10-22_documentos-en-el-disco-del-servidor.md) |
| 2026-10-22 | Portal de clientes: entrada con invitación y verificación, las 9 secciones del prototipo, roles del portal, solicitudes por bandeja o CRM, y Administración → Usuarios del portal con avisos | [cambios/2026-10-22_portal-de-clientes.md](cambios/2026-10-22_portal-de-clientes.md) |
| 2026-10-22 | Documentos (Control): subir con vínculo, versiones, antivirus simulado con cuarentena, enlace temporal, visible en el portal; conectados Adjuntar documento del contrato, Evidencias de la OT y la pestaña Documentos del cliente | [cambios/2026-10-22_documentos.md](cambios/2026-10-22_documentos.md) |
| 2026-10-22 | Panel de la consola de plataforma: totales de empresas, suscripciones, uso, salud y actividad; usuario de base propio (`erp_console`), acceso solo con 2FA y auditoría de exportaciones y accesos rechazados | [cambios/2026-10-22_panel-consola-de-plataforma.md](cambios/2026-10-22_panel-consola-de-plataforma.md) |
| 2026-10-23 | Consola de plataforma: Tenants (lista y ficha con estados, plan y módulos), Aprovisionar tenant con invitación al administrador, Auditoría de plataforma, Suscripciones y módulos de los planes según el catálogo del ERP | [cambios/2026-10-23_consola-tenants-aprovisionar-auditoria-modulos.md](cambios/2026-10-23_consola-tenants-aprovisionar-auditoria-modulos.md) |
| 2026-10-23 | Planes SaaS con tarjetas como el prototipo y la tabla como segunda vista; catálogo de módulos según el menú real del ERP, con Compras y Contabilidad aparte de Finanzas | [cambios/2026-10-23_planes-saas-y-modulos-del-erp.md](cambios/2026-10-23_planes-saas-y-modulos-del-erp.md) |
| 2026-10-23 | Panel de control por rol (indicadores, Requiere tu atención, gráficos, actividad; inicio del sistema) y Notificaciones: campana con contador, centro de notificaciones, avisos por permiso y alcance, correo según Mi perfil | [cambios/2026-10-23_panel-de-control-y-notificaciones.md](cambios/2026-10-23_panel-de-control-y-notificaciones.md) |
| 2026-10-23 | Hallazgos del inventario de botones: "Mover a…" y otros controles con candado, exportar con auditoría en Sedes, Equipos, Roles y versiones de documentos, "Ver planes" en lugar del enlace roto, Solo lectura del portal sin crear tickets, confirmar al desactivar usuarios | [cambios/2026-10-23_hallazgos-del-inventario-de-botones.md](cambios/2026-10-23_hallazgos-del-inventario-de-botones.md) |
| 2026-10-23 | Configuración → Marca de la empresa: logo (limpiado en el servidor), colores sacados del logo y contacto del pie; PDF con diseño corporativo y la marca, y la marca también en Excel, portal y correos al cliente | [cambios/2026-10-23_marca-de-la-empresa-y-documentos-corporativos.md](cambios/2026-10-23_marca-de-la-empresa-y-documentos-corporativos.md) |
| 2026-10-23 | Usuarios: invitación propia al crear un usuario (empresa, rol, botón "Crear mi contraseña", código por 7 días) y "Reenviar invitación" mientras no haya entrado | [cambios/2026-10-23_invitacion-de-usuarios-internos.md](cambios/2026-10-23_invitacion-de-usuarios-internos.md) |
| 2026-10-27 | Cotizaciones enviadas que pasan a Vencida solas cada madrugada (RN-COM-05), con su registro en el historial | [cambios/2026-10-27_cotizaciones-vencidas-automaticamente.md](cambios/2026-10-27_cotizaciones-vencidas-automaticamente.md) |
| 2026-10-27 | Límite de crédito del cliente: aviso con motivo o bloqueo (regla de la empresa) al aprobar cotizaciones y activar contratos, solo aviso al emitir, permiso para aprobar sobre el límite, barra de crédito en la ficha y "Contado" | [cambios/2026-10-27_limite-de-credito-del-cliente.md](cambios/2026-10-27_limite-de-credito-del-cliente.md) |
| 2026-10-28 | Dar o quitar el acceso de Super Administrador con un comando del servidor, con invitación por correo y registro en la auditoría de plataforma | [cambios/2026-10-28_dar-acceso-de-superadmin.md](cambios/2026-10-28_dar-acceso-de-superadmin.md) |
| 2026-10-28 | Editar el propio nombre en Mi perfil (ERP y consola), con auditoría y la cabecera al día | [cambios/2026-10-28_editar-nombre-en-mi-perfil.md](cambios/2026-10-28_editar-nombre-en-mi-perfil.md) |
| 2026-10-29 | Suscripciones: registrar pagos por transferencia y extender la vigencia, aviso de vencimiento, plan y soporte en Mi perfil y Configuración de la plataforma (canales de soporte) | [cambios/2026-10-29_suscripciones-vigencia-pagos-offline-y-renovacion-whatsapp.md](cambios/2026-10-29_suscripciones-vigencia-pagos-offline-y-renovacion-whatsapp.md) |
| 2026-10-31 | Correcciones de lo del 29: historial de pagos visible y en su propia tabla, extender sin fechas pasadas, configuración de plataforma solo para la consola y sin datos de ejemplo, aviso solo para quien administra y colores del sistema | [cambios/2026-10-31_correcciones-suscripciones-y-configuracion.md](cambios/2026-10-31_correcciones-suscripciones-y-configuracion.md) |
| 2026-11-01 | Página de ingreso con la marca de cada empresa (/e/{nombre-corto}): logo, color y bienvenida; se configura en Marca y en la consola; invitaciones con el enlace | [cambios/2026-11-01_pagina-de-ingreso-por-empresa.md](cambios/2026-11-01_pagina-de-ingreso-por-empresa.md) |
| 2026-11-01 | Repo pública de la empresa sin secretos, librerías sin vulnerabilidades (PhpSpreadsheet, Symfony…) y prueba automática de GitHub con la base actual | [cambios/2026-11-01_prueba-automatica-de-github.md](cambios/2026-11-01_prueba-automatica-de-github.md) |
| 2026-11-01 | Buscador en los filtros desplegables con muchas opciones (Entidad y Usuario de Auditoría, Categoría de Documentos…) | [cambios/2026-11-01_buscador-en-filtros-largos.md](cambios/2026-11-01_buscador-en-filtros-largos.md) |
| 2026-11-01 | Credential Vault (Control): guardar cifrado, revelar 20 s, usar (copiar sin mostrar), rotar, revocar e historial, cada acción con motivo y permiso propio | [cambios/2026-11-01_credential-vault.md](cambios/2026-11-01_credential-vault.md) |
| 2026-11-01 | Reportes (Control): 7 reportes con el alcance de cada rol, gráficos, rango de fechas, exportación y "Mis exportaciones" | [cambios/2026-11-01_reportes.md](cambios/2026-11-01_reportes.md) |
| 2026-11-01 | Reportes: tres indicadores por reporte comparados con el periodo anterior, y el clic en la fila abre la ficha | [cambios/2026-11-01_reportes-indicadores-y-fichas.md](cambios/2026-11-01_reportes-indicadores-y-fichas.md) |
| 2026-11-01 | Reportes en PDF con la marca de la empresa: indicadores, resumen y la tabla filtrada | [cambios/2026-11-01_reportes-en-pdf.md](cambios/2026-11-01_reportes-en-pdf.md) |
| 2026-11-01 | Workflows (Control): reglas cuando-si-entonces con 12 disparadores, avisos a rol, responsable o portal, plantillas e historial | [cambios/2026-11-01_workflows.md](cambios/2026-11-01_workflows.md) |
| 2026-11-01 | Módulos y plan (Administración): plan y vigencia, apagar o encender módulos del plan, uso frente a límites, pagos y pedido de cambio | [cambios/2026-11-01_modulos-y-plan.md](cambios/2026-11-01_modulos-y-plan.md) |
| 2026-11-01 | Integraciones (Control): estado real de las conexiones y webhooks salientes firmados con cola, reintentos, fallidos y protección de direcciones | [cambios/2026-11-01_integraciones.md](cambios/2026-11-01_integraciones.md) |
| 2026-11-01 | Planes, catálogo de módulos y suscripciones protegidos en la base: la app no los borra y solo los cambia una sesión de superadmin verificada; el registro solo acepta planes públicos | [cambios/2026-11-01_tablas-de-plataforma-protegidas.md](cambios/2026-11-01_tablas-de-plataforma-protegidas.md) |

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
