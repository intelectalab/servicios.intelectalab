# Personal y conexión con el SaaS de RRHH

Fecha: 2026-11-16.

## 1. Qué se pidió

Empezar por el RRHH básico del kit v1.5 (decisiones D-44 y D-45) y dejar todo listo para conectarlo con el SaaS de RRHH propio de la empresa, que todavía está en desarrollo, tal como dice su contrato de integración. Antes se guardó una copia de la versión anterior: la etiqueta `antes-kit-v1.5` en GitHub y el código, el kit anterior y la base local en `respaldos/` (fuera de git).

Decisiones de la usuaria:

- **Planes:** Personal entra en Profesional y Enterprise.
- **Nada se borra:** se amplía la tabla `employees` que ya existía, y el costo por hora del usuario pasa a vivir también en el trabajador.

## 2. Qué se cambió

### 2.1. Base (migración `2026_11_16_000100_personal_module`)

- **Módulo:**
  - Módulo `PERSONAL` en el catálogo, incluido en Profesional y Enterprise.
  - Encendido en las empresas que tienen esos planes vigentes.
- **Trabajadores** (`employees`, ampliada):
  - Campos nuevos: puesto, sede, ingreso, cese con motivo, tipo de contrato, jornada semanal (hasta 48 h), costo por hora, moneda y código en planilla.
  - Para la conexión con RRHH: origen (`LOCAL` o `HR_SAAS`), `external_version` y versión de bloqueo.
  - Un trabajador por persona y por usuario. Nunca sueldo, AFP ni boletas.
  - Los cuatro empleados que ya había conservan su cargo, que pasó a ser un puesto. Además se les copió la sede y el costo de su usuario.
- **Tablas nuevas:**

  | Tabla | Para qué |
  |---|---|
  | `hr_positions` | Puestos, con costo por hora sugerido |
  | `hr_leave_types` | Tipos de ausencia. Cada empresa nace con Vacaciones, Descanso médico, Licencia con goce, Licencia sin goce y Permiso personal |
  | `hr_leave_requests` | Solicitudes de ausencia |
  | `hr_payroll_exports` | Envíos mensuales de horas a planilla |
  | `hr_connections` | Conexión con el SaaS de RRHH (una por empresa) |
  | `hr_inbound_events` | Mensajes recibidos de RRHH |
  | `hr_outbound_events` | Mensajes enviados a RRHH |
  | `hr_sync_runs` | Ejecuciones de sincronización |
  | `hr_sync_unmatched` | Bandeja de trabajadores sin pareja |

  Todas están aisladas por empresa (RLS), y la app no puede borrar nada de ellas.
- **Lo que garantiza la base:**
  - Dos ausencias aprobadas de la misma persona no se cruzan, ni siquiera si se aprueban a la vez.
  - Nadie aprueba ni rechaza su propia ausencia.
  - Un rechazo lleva motivo.
  - Una ausencia aprobada solo se anula.
  - Un envío a planilla ya generado no se reescribe: hay que generar una versión nueva.
  - Costo por hora mayor que cero.
- **Permisos:** `employees.*`, `leaves.*` y `hr_sync.*`, repartidos según la matriz del kit:

  | Rol | Trabajadores | Ausencias | Envíos y sincronización |
  |---|---|---|---|
  | Gerencia | ve y exporta | ve, pide, aprueba y exporta | ve, aprueba y exporta |
  | Operaciones | ve su sede | pide y aprueba las de su sede | — |
  | Finanzas | ve | pide las suyas | genera, gestiona, aprueba y exporta |
  | Ventas, Técnico y Soporte | — | piden las suyas | — |

  El Administrador tiene todo. Los roles base de las empresas que ya existían recibieron lo suyo, y hay alcance por rol en trabajadores y ausencias.

### 2.2. Trabajadores y puestos (`/hr/employees`)

- **Alta:**
  - Se puede tomar una persona de Clientes y actores o escribir una nueva.
  - Si el documento ya existe, se usa esa persona y no se duplica la identidad.
- **Costo por hora en un solo lugar:**
  - Lo que se escribe en el trabajador pasa a su usuario, y lo que se escribe en Usuarios pasa a su trabajador.
  - Horas y costos valoriza las horas con el costo de la ficha del trabajador. Si la persona no es trabajador, usa el de su usuario, como hasta ahora.
- **Cese:** con fecha y motivo. Si la persona tiene órdenes de trabajo abiertas, llega un aviso a quien gestiona OT para reasignarlas.

### 2.3. Vacaciones y ausencias (`/hr/leaves`)

- **Flujo:** Solicitada → Aprobada o Rechazada. Una aprobada se puede anular.
- **Quién pide qué:** cada quien pide las suyas; con alcance de sede o de empresa, también las de otros.
- **Avisos:**
  - Quien puede aprobar recibe un aviso cuando llega una solicitud.
  - La persona recibe un aviso cuando se decide la suya.
- **Al aprobar:** se muestra el trabajo que la persona ya tenía agendado en esas fechas, y Operaciones recibe un aviso para reasignarlo.
- **Bloqueo de la agenda:**
  - A una persona ausente no se le asigna una orden de trabajo ni se le agenda una cita, ni siquiera con «guardar igual».
  - La agenda muestra la ausencia. Solo quien puede ver ausencias ve el tipo; los demás ven «Ausente».

### 2.4. Envío de horas a planilla (`/hr/payroll-exports`)

- **Qué es:** cada mes se genera un envío versionado con las horas aprobadas. Si se regenera, sale la versión 2. El ERP no calcula planilla.
- **Sin conexión:** se genera un Excel con formato y con la marca de la empresa, para el proveedor de planilla. Tiene cuatro hojas: Horas, Trabajadores, Altas y ceses, y Ausencias.
  - El archivo sale siempre del contenido guardado, así que no cambia aunque después cambien las horas.
  - No se guarda en Documentos.
- **Con el SaaS de RRHH conectado:** se envía el evento `timesheet.period_approved` firmado, solo con los trabajadores que RRHH conoce, y se reenvía con la misma versión.
- **Sobretiempo:** todavía va en `false`, porque el ERP no lo distingue. Lo calcula la planilla con la jornada. Queda como decisión pendiente.

### 2.5. Conexión con el SaaS de RRHH (`/hr/sync`, contrato `14-INTEGRACION-RRHH.md`)

- **Registro de la conexión:**
  - Se guardan la dirección del SaaS de RRHH y el token de servicio que entrega RRHH. El token va cifrado al Credential Vault.
  - El ERP genera la clave de firma y la muestra una sola vez.
  - Junto con la clave entrega lo que RRHH tiene que cargar: la dirección del webhook, el ID de la conexión y el RUC.
- **Activar** exige la carga inicial (RN-HRM-07):
  - Se leen los puestos, los trabajadores y las ausencias de RRHH, y se empareja por tipo y número de documento.
  - Sin pareja o con más de una, el trabajador queda en la bandeja para vincularlo, crearlo o ignorarlo.
  - Si RRHH no responde, la conexión no se activa.
- **Con la conexión activa:**
  - Lo que viene de RRHH es de solo lectura (422 `HR_MASTERED`). Solo se cambia el usuario vinculado y la sede.
  - Las altas y los puestos nuevos se hacen en RRHH. Un alta nueva que llega después se crea sola.
- **Webhook** (`POST /api/v1/webhooks/hr-saas`):
  - Seguridad:
    - firma HMAC del cuerpo;
    - la empresa sale del encabezado `X-Connection-Id`;
    - el RUC del mensaje tiene que ser el de esa empresa;
    - `X-Event-Id` es único, y un mensaje repetido responde 202 sin cambiar nada;
    - solo se aceptan mensajes de los últimos 5 minutos.
  - Procesamiento:
    - Se procesa al responder y, si algo queda pendiente, lo toma la tarea `hr-saas:process`, que corre cada minuto.
    - Un cambio se aplica solo si su versión es mayor que la guardada.
- **Del ERP a RRHH:** van las horas del periodo y el vínculo de un trabajador con un usuario.
  - Se envían firmados y, si fallan, se reintentan a 1, 5, 30 y 120 minutos.
  - Tras el quinto intento quedan como fallidos y se pueden reenviar desde Sincronización.
- **Desactivar no borra nada:** lo que vino de RRHH vuelve a editarse en el ERP y conserva su código de RRHH, para poder volver a conectar.
- **Simulador del SaaS de RRHH** (`FakeHrSaasGateway`, con los ejemplos del contrato):
  - Lo usan las pruebas y, con `HR_SAAS_DRIVER=fake`, este entorno local.
  - En producción nunca se usa: allí `HR_SAAS_DRIVER` es `http` (llamadas reales, solo https a direcciones públicas, sin redirecciones).

## 3. Pruebas

- API: `PersonalTest` con 5 pruebas y 125 aserciones. Cubren las pruebas de contrato que pide el documento:
  - un webhook repetido no cambia nada;
  - una versión antigua se ignora;
  - un DNI repetido va a la bandeja;
  - editar un trabajador de RRHH da `422 HR_MASTERED`;
  - el reenvío de horas sale en versión 2.

  Además prueban:
  - firma, RUC y ventana de tiempo incorrectos;
  - reintentos hasta quedar fallido y el reenvío;
  - ausencias que bloquean la OT y la agenda;
  - que nadie aprueba lo propio;
  - la inmutabilidad del envío;
  - el módulo y los permisos.
- Además se ajustaron las que ya existían: `AccessScopeTest` incluye los alcances Trabajadores y Ausencias. Pasan las de Agenda, Proyectos y Módulos.
- Web: tipos y lint sin errores.
- `schema_reference.sql` se regeneró con el bloque de planes del repositorio (Personal agregado a Profesional y Enterprise, sin los límites de prueba locales). Se comprobó que se restaura en una base vacía, como en el CI.
- **En el navegador (app.localhost:3000, a 1366 px y a 375 px)**, con la cuenta de prueba `personal@erp-saas.test` (rol «Personal (RRHH)», creada por `LocalPersonalSeeder`):
  - **Trabajadores:**
    - la lista muestra sus indicadores;
    - la ficha muestra la ausencia de hoy.
  - **Ausencias:**
    - se aprobó una ausencia desde la bandeja;
    - el botón para aprobar la propia sale deshabilitado;
    - el calendario muestra el mes;
    - la agenda muestra «Ausente (Vacaciones)».
  - **Envío de horas:**
    - la vista previa avisa de las horas sin aprobar;
    - se generaron las versiones 1 y 2;
    - el Excel se descarga.
  - **Sincronización con el simulador:**
    - se registró la conexión y la clave se mostró una sola vez;
    - se activó con la carga inicial (6 registros, 3 a la bandeja);
    - se creó un trabajador desde la bandeja;
    - el modo RRHH maestro bloquea «Nuevo trabajador»;
    - el webhook real por HTTP aplicó un cambio firmado, ignoró el repetido y rechazó la firma mala;
    - al terminar se desactivó la conexión.
  - Las tablas de Solicitudes y Envíos se compactaron para que entren a 1366 px sin desplazarse.

## 4. Datos de prueba que quedaron en «Tenant AB - Prueba»

- **Puestos y trabajadores:**
  - Puestos nuevos: TEC «Técnico de campo» y RRHH «Analista de personal».
  - Trabajadores nuevos: Tomás Huamán (vinculado a `tecnico@`), Paola Ríos (vinculada a `personal@`) y Kevin Salazar (sin usuario).
  - Desde el simulador de RRHH: Elena Torres Vílchez (vino de la bandeja).
  - Datos completados: Geraldine Ramos, Ana Torres y Luis Mendoza recibieron fecha de ingreso, contrato, jornada, costo por hora y sede.
  - Luis Mendoza quedó vinculado a `operaciones@`, así que el costo por hora de esa cuenta pasó a S/ 21.
- **Ausencias:** vacaciones de Tomás (hoy), licencia de Luis, descanso médico de Geraldine (aprobado) y vacaciones de Paola (por aprobar).
- **Envíos:** envío de octubre 2026, versiones 1 y 2.
- **Conexión con RRHH:** registrada contra el simulador y desactivada.
- **Permisos:** los roles de prueba (Gerencia, Ventas, Operaciones, Técnico, Finanzas, Soporte) recibieron los permisos de Personal de la matriz.

## 5. Lo que queda

- **En el servidor:**
  - La migración corre al desplegar.
  - Revisar que `APP_URL` sea la dirección https pública: con ella se arma la dirección del webhook que se le da a RRHH.
  - `HR_SAAS_DRIVER` no hace falta: por defecto es `http`.
- **Con el equipo del SaaS de RRHH:**
  - Confirmar que manden el ID de conexión en la cabecera `X-Connection-Id`. El contrato no dice por dónde va.
  - Confirmar que la ventana de 5 minutos se cuenta desde `occurred_at`. Un mensaje más viejo responde 409 y se recupera con «Releer todo».
  - Confirmar el formato de sus listas paginadas: se aceptan `data` + `last_page` o `next_page`.
- **Decisiones del kit:**
  - Marcar sobretiempo en las horas.
  - Un tipo de ausencia «sin aprobación» se aprueba solo al pedirlo.
- **Pruebas que fallaban de noche:** corregidas el mismo día (ver `2026-11-16_fechas-de-lima-sin-fallas-de-noche.md`).

## 6. Ajuste tras la revisión (2026-11-19)

- En «Nueva solicitud» de ausencias, el selector de trabajador salía con filas vacías: la API mandaba los nombres en blanco, porque la consulta ignoraba la columna del nombre. Corregido, y la prueba ahora comprueba los nombres.
