# Comparación del sistema actual con el kit de desarrollo

Fecha: 2026-10-08. Kit revisado: `kit/erp-saas-kit-desarrollo`. **No se cambió nada del sistema para hacer esta comparación**: solo lectura de código, documentos y consultas de solo lectura a la base local.

## En una frase

El kit no es una lista de ajustes: es el plano para construir el sistema completo de cero, con otra base de datos, otro contrato de API y otra estructura de código. **En lo funcional lo que tenemos va en la misma dirección y parte del mismo prototipo; en lo técnico casi nada cumple "tal cual".**

## Qué trae el kit

- Base de datos: 138 tablas con prefijo por módulo (`com_quotations`, `core_parties`…), RLS, triggers y funciones.
- Contrato de API: 392 operaciones.
- 113 reglas de negocio, 49 máquinas de estado, 73 pantallas.
- 158 historias en 5 fases (F0 fundaciones, F1 comercial-operativo, F2, F3, F4).
- Sistema de diseño, pruebas, datos de demo y entorno.
- El prototipo: **es el mismo archivo** que hemos usado de referencia.

## Resultado por números

| Medida | Resultado |
| --- | --- |
| Historias de F0 (24) | 0 cumplen todos sus criterios · 14 parciales · 10 sin hacer |
| Historias de F1 (55) | 0 cumplen todos sus criterios · 26 parciales · 29 sin hacer |
| Pantallas (73) | 15 existen (todas con diferencias) · 51 están en el menú como "próximamente" · 7 no aparecen |
| Operaciones de API (392) | unas 97 tienen equivalente hoy (≈25 %), casi todas con otra forma |
| Reglas de seguridad, core y comercial (38) | 13 cumplen · 19 parciales · 6 no |
| Máquinas de estado comerciales | Contrato, orden y solicitud del portal: idénticas. Cotización y servicio: casi idénticas |

Por qué ninguna historia sale "hecha": casi todas incluyen criterios técnicos que hoy no existen en ningún sitio (identificadores de prueba en pantalla, contrato de API, punto único de cambio de estado, etc.). De las 26 parciales de F1, **12 (catálogo y comercial) están completas para quien usa el sistema** y solo les falta esa forma técnica.

---

## 1. Lo que YA CUMPLE

**Reglas de negocio y estados**
- Una identidad con varios roles, sin duplicar por documento (Clientes y actores).
- Servicio: sus 4 estados y transiciones; no se activa sin precio; no se cotiza lo que no está activo; cada precio nuevo cierra el anterior.
- Cotización: sus 6 estados; solo se edita en borrador; nueva versión; no se aprueba vencida; quien crea no aprueba (configurable); rechazar pide motivo; se convierte una sola vez; en dólares guarda el tipo de cambio.
- Contrato: sus 5 estados; activar pide permiso de aprobar, líneas y fecha; suspender y terminar piden motivo; al activar propone los servicios contratados; no factura.
- Orden: secuencia y cancelación con motivo, idénticas.
- Solicitud del portal: estados idénticos.

**Seguridad**
- La empresa se toma del usuario, nunca de lo que envía el navegador.
- Datos de otra empresa responden "no encontrado".
- Correo único en toda la plataforma; login sin selector de empresa.
- Mensaje genérico al fallar el login; 5 intentos bloquean 15 minutos.
- Código de recuperación de un solo uso (15 min; el kit pide 30, somos más estrictos).
- Matriz de roles editable y auditada; el Administrador no se recorta.
- La consola de plataforma no ve datos de negocio.
- Segundo paso obligatorio para superadmin, administrador y portal (a quién se exige coincide; el medio no, ver sección 4).

**Pantallas y experiencia**
- Botón sin permiso visible, deshabilitado, con candado y motivo.
- Confirmación con motivo en rechazos y cancelaciones.
- Tablas que pasan a tarjetas en teléfono.
- Paginación, orden y filtros hechos en el servidor.
- Exportar a Excel y CSV todas las filas filtradas.
- Colores de estado (verde, rojo, ámbar), radios y sombras: iguales a los del kit.
- Historial en cada ficha.
- Preferencias de pantalla guardadas en el navegador (el kit lo permite; lo que prohíbe es guardar datos de negocio).

---

## 2. Lo que NECESITA CAMBIOS (existe, pero con otra forma)

Son cosas que funcionan y que el kit pide de otra manera. Ninguna es un error de lo construido.

| Tema | Hoy | El kit pide |
| --- | --- | --- |
| Cambios de estado | Una ruta por acción (`/approve`, `/activate`, `/cancel`…) | Un solo punto `/transitions` con motor común, que además guarde cada cambio en una tabla propia |
| Respuestas de la API | El objeto directo y el paginador de Laravel | Todo dentro de `{data}` y listas con `{data, meta}` |
| Errores de la API | Formato de Laravel (`message`, `errors`) | Un formato único con código y número de seguimiento |
| Listados | `search`, `sort` + `dir`, `filters` | `q`, `sort=-campo`, `filter[estado]` |
| Verbos y nombres | `PUT`; `/login`; `/superadmin`; `quotation_number` | `PATCH`; `/auth/login`; `/platform`; `number` |
| Permisos | Por módulo: ver / gestionar / aprobar | Por recurso: ver / crear / editar / eliminar / aprobar / exportar |
| Tablas | `quotations`, `parties`, `services`… | `com_quotations`, `core_parties`, `svc_services`… |
| Identidades | `parties` + `persons` + `organizations` + `customers` / `suppliers` / `employees` | Una tabla de identidades + una de roles + una de relaciones + una de direcciones |
| Rol Contacto | Guardado dentro de la persona | Una relación entre dos identidades |
| Precio de servicio | Activo sí/no, solo soles | Versión numerada con estado, por moneda |
| Numeración | `COT-2026-0001`, generada por la aplicación | Función de la base, sin el año |
| Dinero en cálculos | Se guarda bien, pero se calcula con decimales de coma flotante | Nunca coma flotante |
| PDF y Excel | Se arman en el navegador | Los genera y guarda el servidor |
| Buscador global | Pantallas y actores | También cotizaciones, contratos, órdenes, tickets y facturas |
| Importación | Dentro de cada listado, al instante | Pantalla central, con validación previa e historial |
| Vista 360 | Muestra todas las pestañas | Oculta las pestañas sin permiso |
| Duplicados | Se detectan al guardar | Consulta previa antes de crear |
| Inactivar actor / servicio, cancelar cotización | No piden motivo | Piden motivo |
| Estados de la empresa (tenant) | Provisión, Activa, Suspendida, Mantenimiento, Cerrada | Alta, Activa, Suspendida, Bloqueada, Cancelada, Archivada |
| Estructura del código | Controladores con toda la lógica | Controladores delgados + casos de uso + políticas |
| Pruebas | 58 pruebas propias | Otra herramienta, plantillas del kit y el nombre de la regla en cada prueba |

---

## 3. Lo que NO CUMPLE (no existe hoy)

**Cimientos técnicos que el kit llama "no negociables"**
- **Alcance en los permisos** *(hecho el 2026-10-08 para cotizaciones, contratos, órdenes y solicitudes: [cambios/2026-10-08_alcance-en-permisos.md](cambios/2026-10-08_alcance-en-permisos.md); el portal del cliente no existe aún)*: hasta entonces quien podía ver cotizaciones veía todas las de la empresa. El kit pide que Ventas vea solo las suyas, cada sede las suyas, y el cliente del portal solo las propias.
- **Protección contra envíos repetidos** (clave de idempotencia) y **contra ediciones simultáneas** (número de versión del registro). *(Hecho el 2026-10-08.)*
- **Bandeja de eventos** (outbox) para avisos, contabilidad e integraciones.
- **Número de seguimiento** de cada petición, visible en los errores.
- **Identificadores de prueba en pantalla** (`data-testid`): no hay ninguno, así que ninguna prueba automática del kit puede correr.
- Contrato de API escrito y cliente generado a partir de él.

**Funciones**
- Portal del cliente (9 pantallas) y sus usuarios.
- Consola de plataforma: lista, alta, suspensión y cambio de plan de empresas (hoy solo Planes funciona; Tenants y Suscripciones dicen "en construcción").
- Pantallas de Configuración, Módulos y plan, Auditoría, Seguridad y aislamiento.
- Panel de control, Notificaciones (la campana no hace nada), Reportes.
- Sedes y equipos gestionables.
- Autorización interna por niveles y firma electrónica (fase F3 del kit).
- Tarea automática que venza las cotizaciones (hoy es un botón).
- Ruta CRM de las solicitudes del portal. *(El CRM empezó el 2026-10-08 con Leads y Pipeline: [cambios/2026-10-08_crm-leads-y-pipeline.md](cambios/2026-10-08_crm-leads-y-pipeline.md); esta ruta sigue pendiente.)*
- Operaciones, Finanzas, Soporte, Documentos: hay rutas de API sueltas, sin pantalla ni pruebas. *(Soporte se hizo el 2026-10-15 —Tickets y SLA, Garantías y Calendario laboral y SLA—: [cambios/2026-10-15_soporte-tickets-sla-garantias.md](cambios/2026-10-15_soporte-tickets-sla-garantias.md). Del kit no se adoptó la tabla aparte de políticas de garantía ni el factor Premium por prioridad.)*
- Compras, Tesorería, Contabilidad: nada. *(Tesorería se hizo el 2026-10-13 como Bancos y caja, y Compras ese mismo día: [cambios/2026-10-13_compras-ordenes-comprobantes-y-cuentas-por-pagar.md](cambios/2026-10-13_compras-ordenes-comprobantes-y-cuentas-por-pagar.md). Contabilidad se hizo el 2026-10-14: [cambios/2026-10-14_contabilidad.md](cambios/2026-10-14_contabilidad.md); sus asientos se escriben en la misma operación que el documento, no por la bandeja de eventos.)*
- Datos de demostración del kit (dos empresas): no son cargables, los nombres de tabla no coinciden.

---

## 4. Donde el kit CHOCA con decisiones que ya tomaste

Aquí no hay un "correcto": hay que elegir entre mantener lo tuyo o adoptar lo del kit.

| Tu decisión | Lo que pide el kit |
| --- | --- |
| Colores del logo (azul `#1B8FC9`) | Azul marino y naranja del prototipo |
| Rutas en inglés (`/actors`, `/quotations`) | Rutas en español (`/clientes`, `/cotizaciones`); sus pruebas navegan por ellas |
| Segundo paso con código al correo | Aplicación autenticadora (TOTP) |
| Módulo fuera del plan: visible con candado "Plan" | Oculto |
| Fichas con tarjetas movibles | Pestañas fijas (Detalle, Líneas, Documentos, Historial) |
| La orden se abre en una página | Panel lateral |
| Precio de catálogo siempre en soles | Precio versionado por moneda |
| Registro público de empresas desde la landing, que nacen activas | Las da de alta el administrador de la plataforma |
| Página pública en `/` y sistema en otro dominio | `/` es el panel de control |
| Planes con prueba, precio anual y suscripciones | Solo un código de plan en la empresa |
| 22 módulos activables | 5 módulos activables |
| Un usuario puede tener varios roles y sedes (en la base) | Un usuario, un rol, una empresa |
| Una orden por contrato; orden directa activable | Sin esos límites |
| Nueva versión de cotización con número nuevo | Mismo número con sufijo de versión |

**Lo que tenemos y el kit no contempla** (se perdería o habría que añadirlo al kit): columnas personalizadas, edición en la tabla, mover e inmovilizar columnas, filtrar por columna con operadores, acciones masivas, importación dentro de cada listado, tema oscuro, pantalla de empresa suspendida, desplegables con buscador.

---

## 5. Riesgos reales que salieron en la revisión (valen con o sin kit)

> **Actualización 2026-10-08:** resueltos los puntos 1, 2, 4, 7, 8 y 9, casi todo el 6 y el del usuario desactivado; el 3 quedó parcial; 5 y 10 pendientes. Detalle en [cambios/2026-10-08_protecciones-del-sistema.md](cambios/2026-10-08_protecciones-del-sistema.md).

No dependen de adoptar el kit: son puntos débiles de hoy.

1. **Nombres y documentos sin protección propia en la base.** Las tablas `persons` y `organizations` no tienen columna de empresa ni regla de aislamiento. Hoy no se filtra nada porque el código siempre consulta a través de `parties`, pero la base no lo garantiza.
2. **La auditoría se puede alterar.** El usuario de base con el que corre la aplicación puede modificar y borrar `audit_logs` (la auditoría de plataforma sí está protegida).
3. **Un solo usuario de base para todo.** La aplicación y la consola de plataforma comparten el mismo, que puede escribir en empresas, usuarios y permisos.
4. **Conversiones duplicables.** "Convertir en contrato" y "Orden directa" se protegen con una consulta previa; dos clics simultáneos podrían crear dos.
5. **Ediciones simultáneas se pisan** sin aviso.
6. **Acceso.**
   - `/login` y el registro de empresas no tienen límite por dirección.
   - Cambiar la contraseña no cierra las otras sesiones.
   - Las contraseñas usan bcrypt (el kit pide argon2id).
   - La sesión se guarda en una cookie que el navegador puede leer.
7. **Las rutas viejas de Clientes, Proveedores y Empleados siguen activas en la API**, aunque las pantallas estén desconectadas: por ahí todavía se pueden crear identidades repetidas.
8. **El candado de módulo es solo visual**: en la API únicamente CRM lo respeta.
9. **Rutas de prueba** (`/test/*`) siguen registradas en la API.
10. **Secretos de la bóveda** guardados cifrados en la propia base (el kit pide un gestor externo).

Según la revisión (sin confirmar con una prueba): un usuario desactivado en su empresa todavía recibiría sesión al iniciar, aunque luego no puede entrar a nada.

---

## 6. Caminos posibles

**A. Adoptar el kit completo.** Reconstruir sobre su base de datos, su API y su estructura. Lo ya hecho sirve como referencia y sus 58 pruebas como especificación, pero es rehacer, no ajustar. Se pierden las funciones de la lista "el kit no contempla" salvo que se agreguen al kit.

**B. Mantener lo construido y tomar del kit lo que suma.** Seguir con el sistema actual y adoptar, en este orden, lo que da seguridad sin romper nada: los riesgos de la sección 5, luego alcance en permisos, protección contra duplicados y ediciones simultáneas, identificadores de prueba, y las pantallas que faltan de F0. El contrato, los nombres de tablas y las rutas se quedan como están.

**C. Intermedio.** Como B, pero acercando la API al contrato del kit por partes (punto único de transiciones, formato de errores y respuestas), publicándolo en paralelo y migrando pantalla por pantalla.

Antes de elegir conviene saber de quien entregó el kit: **¿es obligatorio cumplirlo tal cual (tablas, rutas, pruebas), o es una guía?** De eso depende todo lo demás.

---

## 7. Lo que no se verificó

- No se ejecutó la aplicación ni las pruebas para este informe: es lectura de código y de la base.
- Lo visual (360 px, contraste) no se midió.
- De Operaciones, Finanzas, Soporte, Documentos y CRM solo se compararon rutas y nombres de tablas.
- Las relaciones entre tablas no se revisaron una por una.
- El contrato `api/openapi.yaml` se consultó por búsquedas, no completo.
- Una inconsistencia del propio kit: el filtro `filter[status]` aparece en sus documentos pero no en su contrato.
