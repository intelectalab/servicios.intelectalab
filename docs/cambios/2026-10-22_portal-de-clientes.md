# 2026-10-22 — Portal de clientes

## Qué se pidió

El módulo Portal de clientes del kit (capítulo 13) con las pantallas del portal del prototipo. Antes había solo la bandeja interna "Solicitudes del portal": un usuario del portal ni siquiera podía iniciar sesión (el login buscaba su empresa solo entre los usuarios del sistema).

Respuestas que diste antes de construir:

1. **Alcance:** todo el prototipo (las 9 secciones del portal y la pantalla interna Usuarios del portal); lo que no tiene motor se ve y avisa.
2. **Plan:** módulo nuevo "Portal de clientes" (`PORTAL`) en Base, Profesional y Enterprise.
3. **Documentos:** los entregables aprobados de sus proyectos, solos, más los documentos marcados como visibles en el portal.
4. **Solicitudes con CRM:** oportunidad en el embudo y, además, la solicitud en la bandeja.

## Cómo entra el cliente

- **Mismo inicio de sesión** (`/login`). La API reconoce la cuenta del portal, pide **siempre** la verificación en dos pasos y la sesión vuelve marcada como del portal: la web la lleva a `/portal` y guarda el tipo de sesión `portal`.
- **Invitación:** la empresa (Administración → Usuarios del portal) o el Portal Admin del cliente (Portal → Usuarios) invitan con nombre, correo y rol. Llega un correo con el diseño de los demás, un **código de 8 caracteres válido 7 días** y el botón "Activar mi acceso" (`/portal/activate`): correo + código + contraseña nueva. "Reenviar invitación" da un código nuevo y anula el anterior. El login tiene el enlace "¿Lo invitaron al portal de clientes? Activar mi acceso", y quien intenta entrar sin haber activado recibe ese mismo aviso.
- **Separación total:** una cuenta del portal no abre ninguna pantalla ni ruta interna (las rutas internas usan su contexto de siempre, que no reconoce a las cuentas del portal), y una cuenta del sistema no entra al portal. `proxy.ts` también lleva a cada tipo de sesión a su consola.
- El cliente y la empresa **salen siempre de la cuenta**, nunca de un dato que envíe el navegador. Lo de otro cliente "no existe" (404).
- Cambiar la contraseña desde la barra del portal ("Contraseña") cierra las otras sesiones, como en el sistema.

## Pantallas del portal (como el prototipo, con los colores del logo)

Barra superior propia (no el menú del sistema) con la marca de la empresa, las secciones del rol, la persona con su cliente y rol, "Contraseña" y "Salir"; debajo, la franja "Vista de cliente: solo información de…". Si el rol no abre una sección y se entra por su dirección: "Sin acceso". Si la empresa no tiene el módulo: "Portal desactivado".

| Sección | Qué hace |
| --- | --- |
| **Inicio** | Indicadores del prototipo (cotizaciones por aprobar, saldo pendiente, tickets abiertos, servicios por renovar en 60 días; en ámbar si hay vencidos o renovaciones), avisos (los 3 vigentes más recientes), trabajos en curso y el aviso de comprobantes vencidos. |
| **Cotizaciones** | Listado sin borradores; ficha con líneas, totales, condiciones, **Aprobar cotización** (comentario opcional) y **Rechazar** (motivo obligatorio), **Descargar PDF** (el mismo PDF del sistema) y las decisiones ("desde el portal" / "por la empresa"). Una vencida no se aprueba. |
| **Mis servicios** | Contratos (sin borradores, cada uno con su **PDF**) y servicios contratados con su renovación, chip "Próxima" a 60 días y **Solicitar renovación** (una sola vez mientras no se atienda). |
| **Firmas** | El contrato por firmar con su documento y su huella; firmar (nombre, código al correo, aceptación) o rechazar con motivo; historial de firmas. Ver "Firma electrónica de contratos". |
| **Solicitudes** | "Solicitar un servicio" (servicio del catálogo + qué necesita) y "Mis solicitudes" con su estado, el motivo si se rechazó y "Ver cotización" cuando ya se le envió. |
| **Documentos** | Sus **contratos** (no borradores, salvo los ya firmados) con **Descargar PDF**: el mismo PDF del sistema, firmado con sus sellos, sin las IP. Y los entregables aprobados o entregados de sus proyectos y documentos marcados para el portal, con **enlace temporal de 5 minutos**, que queda en auditoría. Un archivo en cuarentena no se descarga. |
| **Facturas y pagos** | Pendientes de pago (vencido, detracción por depositar en el BN), selección con total y **Pagar en línea** (avisa que la pasarela no está conectada), comprobantes con su PDF, pagos con su recibo en PDF y **Estado de cuenta (PDF)**. |
| **Soporte** | Nuevo ticket (entra por el canal Portal), mis tickets con la atención del prototipo (Recibido, En plazo, Demorado, Resuelto a tiempo) y la conversación **sin notas internas** (solo dice cuántas hay). Responder un ticket que esperaba al cliente lo devuelve a "En atención" y su reloj del SLA sigue, con la pausa sumada. |
| **Usuarios** | (Portal Admin) su equipo, invitar, cambiar rol, desactivar o reactivar, reenviar invitación y los roles disponibles. |

Todas las tablas del portal tienen "Exportar a Excel" y CSV al pie (no se paginan: son pocas filas por cliente). En teléfono, las tablas pasan a tarjetas y la sección abierta siempre queda a la vista en la barra.

## Pantalla interna: Administración → Usuarios del portal

Indicadores del prototipo (activos, invitaciones pendientes, clientes con portal, con verificación en dos pasos), buscador, filtro de estado, tabla completa con orden y filtros por columna en el servidor, selección, paginación y exportar con su permiso. Acciones: Invitar (eligiendo el cliente, con cuántos usuarios tiene de 10), Cambiar rol, Desactivar / Reactivar y Reenviar invitación. Pestaña **Avisos**: publicar un aviso para un cliente o para todos (texto, enlace opcional, vencimiento opcional), retirarlo (no se borra) y exportar.

## Reglas (lo que garantiza la base, además del código)

- Una cuenta es del sistema **o** del portal, nunca de los dos ni de la plataforma; el correo es único en la plataforma.
- Un usuario del portal no cambia de cliente ni de empresa; su cliente es de la misma empresa.
- Como mucho **10 usuarios vigentes** (invitados o activos) por cliente.
- Siempre queda **un Portal Admin activo** por cliente; nadie se desactiva ni se cambia el rol a sí mismo (código, con bloqueo).
- Desactivar cierra sus sesiones y anula la invitación pendiente. Ningún usuario del portal se borra; los avisos se retiran.
- Una solicitud que llegó al CRM tiene su oportunidad.

## Roles del portal (matriz del prototipo)

| Rol | Secciones |
| --- | --- |
| Portal Admin | Todas, aprobar cotizaciones y usuarios |
| Portal Finanzas | Inicio, Facturas y pagos (con pagar), Documentos |
| Portal Solo lectura | Inicio, Cotizaciones (sin aprobar), Mis servicios, Documentos, Facturas, Soporte |

Además, una sección solo se abre si la empresa tiene el módulo del que salen sus datos (sin Finanzas no hay Facturas; sin Soporte o Tickets no hay Soporte; sin Contratos no hay Mis servicios ni Firmas; sin Cotizaciones no hay Cotizaciones ni Solicitudes).

## Permisos internos

Nuevos `portal_users.view`, `portal_users.create` (invitar), `portal_users.manage` (rol, estado, reenviar, avisos) y `portal_users.export`, en el módulo `PORTAL`. Reparto del prototipo: **Administrador todo; Gerencia solo ve.** Comparado con la matriz (`rbac.js`): Gerencia tiene `portal_users: 'v:TENANT'` y nadie más (Ventas, Operaciones, Técnico, Finanzas, Soporte) lo tiene; así quedó. La bandeja de solicitudes sigue con `portal.*` en Cotizaciones.

## "Cliente (Portal)" en Roles y permisos (solo lectura)

Después de entregar preguntaste por el rol "Cliente (Portal)" que el prototipo muestra en Roles y permisos, y pediste mostrarlo de solo lectura. Quedó como una pestaña más, "Cliente (Portal) 🔒 · N usuarios · solo lectura", después de los roles de la empresa:

- La matriz con las mismas columnas, **todas las casillas bloqueadas**, el alcance "Solo su empresa" y, bajo cada recurso, qué ve el cliente. Lo que el prototipo le da y aquí no existe va sin marcar y con su nota: **Proyectos** (solo ve sus entregables aprobados) y **Garantías** (los reclamos los registra la empresa).
- El aviso "Este rol no se edita aquí…" con el enlace a Usuarios del portal, y la tarjeta **Roles del portal** con las secciones de Portal Admin, Portal Finanzas y Solo lectura.
- Si la empresa no tiene el módulo Portal de clientes, lo avisa.
- No es un rol de la empresa (no está en `roles`): la API lo entrega como `customer_role` en `GET /v1/roles`, desde `PortalAccess::CUSTOMER_MATRIX`, junto a las reglas que aplica el portal. Por qué de solo lectura: si fuera editable en la misma matriz, una casilla marcada por error le daría a un cliente un permiso interno.
- En la matriz de los roles internos, el recurso `portal_users` ahora se llama "Usuarios del portal".

## Ajustes tras tu revisión

- **Tema claro / oscuro:** botón luna/sol en la barra del portal, como en el sistema; se recuerda en el navegador. Sin tema guardado, el portal sigue al del equipo (por eso te salió oscuro).
- **Fuera los textos obvios del prototipo:** la franja "Vista de cliente: solo información de…", "Los precios que ves… no cambian sin una nueva versión", "Los enlaces son temporales… El portal no guarda copias", "Ninguna solicitud crea contratos sin tu aprobación" (queda "Tu ejecutivo te enviará una cotización"), "N notas internas… no se muestran aquí" (la API ya no envía ese número) y "Como Portal Admin invitas…" (queda "1 de 10 usuarios"); en Roles y permisos, la descripción de "Cliente (Portal)". Se quedan los avisos que informan algo real.
- **Marca:** la barra muestra la de cada empresa: su nombre con sus iniciales (aún no hay dónde guardar su logo; irá con la pantalla Configuración). GSCreativas solo al pie, pequeño: "Desarrollado por GSCREATIVAS". El inicio de sesión sigue con el logo de GSCreativas porque es el mismo para el sistema y el portal.
- **El error de hidratación** que viste (`bis_skin_checked="1"`) lo pone una extensión del navegador (Bitdefender, Avast…), no el sistema; solo aparece en desarrollo.
- **Inicio:** la tarjeta "Contratos por firmar" (en el prototipo sale solo si hay pendientes; aquí siempre, en 0, porque la firma electrónica aún no existe) y "Trabajos en curso" y "Avisos" con la lista del prototipo (filas separadas por una línea, "Programado …" debajo). Las cinco tarjetas caben en una fila a 1366 px.
- **Menú:** se queda horizontal arriba, como el prototipo (te lo recomendé: pocas secciones, más ancho para el contenido y se distingue del sistema).

## Firma electrónica de contratos (pedida después, "como dice el kit")

Kit: RN-COM-09 y RN-COM-13 (fase 3); prototipo: ficha del contrato y Portal → Firmas. Proveedor: **firma electrónica simple dentro del sistema** (`SIMPLE`); el kit deja abierto un proveedor acreditado (D-06), que se conectaría en `ContractSignatures`.

- **Enviar a firma** (ficha del contrato en borrador, `contracts.manage`): se guarda la **huella SHA-256** del contenido (número, cliente, vigencia, SLA, condiciones, moneda, total y líneas); el firmante del cliente es un **Portal Admin activo** (se elige si hay varios; sin ninguno, no deja y pide invitarlo); el cliente recibe un correo "Contrato … por firmar" con el botón al portal. Una sola solicitud pendiente por contrato (lo garantiza la base); **vence a los 15 días** (se marca al consultarla).
- **El cliente firma en Portal → Firmas:** ve el documento (partes, vigencia, SLA, líneas, total, condiciones y la huella) y firma con su **nombre completo exacto**, un **código de 6 dígitos a su correo** (10 minutos, 5 intentos) y la **aceptación**. O **rechaza con motivo**. Si el contrato cambió después del envío, no se firma (la ficha y el portal lo avisan).
- **La empresa firma** con "Firmar por la empresa" (`contracts.approve`), también con código a su correo y aceptación. El orden da igual; con las dos firmas la solicitud queda **Firmada**.
- Cada firma guarda fecha, IP, navegador, código verificado y una **evidencia** (huella de la firma). Una firma registrada no cambia y nada se borra (base). El portal no recibe la IP de la empresa.
- **Anular solicitud** (pendiente o rechazada) para editar y volver a enviar.
- **Firmar contrato** (portal) queda deshabilitado hasta marcar "Leí el contrato y acepto sus términos y condiciones", como en la ventana de la empresa (la API también lo exige).
- **Enviado a firma, no se activa** (pedido después): aunque la regla esté apagada, un contrato con la firma pendiente o rechazada no pasa a vigente hasta que firmen las dos partes o se anule la solicitud (API y botones "Activar contrato" deshabilitados con el motivo). Las empresas que no usan la firma activan como siempre.
- **Regla nueva en Roles y permisos → Reglas de la empresa:** "Exigir la firma electrónica para activar un contrato" (apagada si no se cambia). Encendida, "Activar contrato" pide las dos firmas.
- Inicio del portal: la tarjeta "Contratos por firmar" ya cuenta las pendientes.
- Base: `2026_10_22_000200_contract_signatures` (tablas `contract_signature_requests` y `contract_signature_signers`, `contracts.signature_status`). API: `Services/Support/ContractSignatures`, `ContractSignatureController`, rutas del portal `/portal/signatures…`, correos `ContractSignatureMail` y `ContractSignatureCodeMail`. Web: `contracts/ContractSignature.tsx`, `portal/PortalSignatures.tsx`. Pruebas: `ContractSignatureTest` (4).
- Probado en el navegador (con `MAIL_MAILER=log` mientras probaba, devuelto a `smtp`): CTR-2026-0007 (desde COT-2026-0014, de Minera) enviado a firma, firmado por la empresa con su código, firmado por Rosa en el portal (nombre equivocado → aviso; correcto → Firmada), ficha con las dos evidencias e historial. El contrato quedó en borrador y firmado, listo para activarse.
- **PDF del contrato firmado:** con la solicitud firmada por las dos partes, "Descargar PDF" ya no lleva la marca BORRADOR (aunque el contrato siga en borrador hasta activarlo); bajo cada línea de firma va quién firmó, cuándo, "código verificado", la IP y su evidencia, y una nota "Firma electrónica" con la fecha y la huella completa. Revisado abierto con CTR-2026-0007.
- **Sello visual de la firma** (pedido después): sobre cada línea de firma firmada, un recuadro con un check, "FIRMADO ELECTRÓNICAMENTE" y el nombre de quien firmó en letra manuscrita (Great Vibes, licencia libre OFL, en `apps/web/public/fonts/` con su licencia; descargada del repositorio oficial de Google Fonts con tu permiso). La letra se carga solo al generar un PDF con firmas y solo se incluyen sus caracteres usados (el PDF de CTR-2026-0007 pesa 71 KB). Sin la letra (sin conexión) sale en cursiva normal. Es solo visual: la validez la dan el código, la huella y la evidencia.
- Pendiente: aviso al ejecutivo cuando el cliente rechaza (Notificaciones); proveedor acreditado si se decide.

## Solicitudes por la ruta CRM

Con el módulo CRM, la solicitud (y la renovación) crea una oportunidad en la primera etapa, con fuente "Portal cliente", el monto del precio vigente y como responsable **quien le vende al cliente** (el autor de su contrato o, si no, de su cotización más reciente, si sigue en la empresa); sin vendedor, queda sin responsable. En la bandeja se ve con "Ver oportunidad OP-…" y no se toma ni se cotiza allí: **cotizar la oportunidad la deja Cotizada** y **perderla la deja Rechazada** con el mismo motivo. Sin CRM, todo sigue como antes.

## Lo que difiere del prototipo y por qué

- Sin la pantalla de demo ("elige una cuenta demo"): se entra con correo, contraseña y código.
- "Simular aceptación" (demo) se reemplazó por **Reenviar invitación**; el código de la invitación no se muestra en la tabla (solo viaja por correo).
- **Pagar en línea** se ve y avisa que no está disponible (no hay pasarela); no se simula nada. Por eso el aviso de vencidos del Inicio dice "Revisa tus facturas y pagos" en lugar de "Revisa y paga en línea".
- El indicador "Con verificación en dos pasos" cuenta las cuentas activas: en el portal la verificación es obligatoria para todas.
- La barra del portal va en dos filas en un portátil (los nueve enlaces no caben junto a la marca y la cuenta a 1366 px); en pantallas muy anchas, en una.
- Rutas en inglés (`/portal/quotations`, `/portal/invoices`…), como el resto del sistema.

## Archivos

- **Base:** `database/migrations/2026_10_22_000100_customer_portal.php` (ya corrida en la PC con `erp_user`; el módulo `PORTAL` se activó a mano en Tenant A, como los anteriores).
- **API:** `Http/Middleware/EnsurePortalUser.php` (contexto del portal) y `EnsurePortalSection.php`; `Modules/Portal/Support/{PortalAccess, PortalCustomer, PortalInvitations, PortalRequests}`; controladores `Portal{,Commercial,Finance,Support,Team,User,Notice}Controller`; `Mail/PortalInvitationMail` (plantilla `mail/code` con vencimiento y botón opcionales); login (`AuthController`: empresa de la cuenta del portal, marca `portal`), restablecer contraseña; `SupportTicket::resumeClock` (lo usan Soporte y el portal); cotizar o perder una oportunidad cierra su solicitud del portal; `AuditCatalog`, `ListExport`, `CheckRecordVersion`.
- **Web:** `components/portal/*`, `app/portal/*`, `components/dashboard/portal-users/PortalUsersAdmin.tsx`, `lib/portal.ts`; `proxy.ts`, `LoginForm`, `lib/auth.ts`, `lib/api.ts`, `lib/document-pdf.ts` (en el portal la cabecera del PDF sale de `/portal/me`), `AppShell` (menú), bandeja de solicitudes (enlace a la oportunidad), `globals.css` (`--portal-top`, `--portal-line`).
- **Datos de prueba:** `database/seeders/LocalPortalSeeder.php` (cuenta del portal de Minera Prueba CRM S.A.C., ya activa; contraseña en el seeder).

## Pruebas

- `tests/Feature/PortalTest.php` (12) y `ContractSignatureTest.php` (4): invitación, reenvío, activación y entrada con verificación; el cliente solo ve lo suyo y ninguna ruta interna (ni el sistema el portal); roles y módulo del plan; aprobar y rechazar (motivo, vencida); solicitudes por bandeja o CRM, renovación sin repetir, bandeja y oportunidad perdida; tickets sin notas internas y reanudación del reloj; equipo del Portal Admin (tope de 10, último Portal Admin, no a sí mismo, otro cliente 404); permisos de la pantalla interna, indicadores, exportar y avisos; documentos, facturas y estado de cuenta del cliente; reglas de la base; el rol "Cliente (Portal)" en `GET /v1/roles` (solo lectura, no es un rol de la empresa).
- `php artisan test`: 254 pruebas pasan.
- **Navegador** (`app.localhost:3000`, 1366 × 768, con `MAIL_MAILER=log` mientras probaba y devuelto a `smtp` al terminar): como Administrador, invitar a Rosa (Minera) desde la pantalla interna y publicar dos avisos; activar con código equivocado (mensaje) y con el correcto; entrar con el código de verificación; recorrer las 9 secciones; aprobar COT-2026-0014 con comentario; rechazar sin motivo (no deja); los 4 PDF (cotización, factura con detracción, recibo y estado de cuenta) revisados abiertos; renovación de Soporte mensual → SOL-2026-0004 y OP-2026-0006 a nombre de Lucía Paredes; nueva solicitud → SOL-2026-0005 y OP-2026-0007, cotizada desde la oportunidad (COT-2026-0015, borrador) → "Cotizada"; enlace temporal del entregable (descarga desde MinIO); ticket nuevo TKT-2026-0009 y respuesta; el ticket TKT-2026-0002 sin su nota interna; invitar a Luis como Solo lectura y pasarlo a Portal Finanzas; no desactivarse a sí misma; contraseña actual equivocada; "Salir"; sin sesión `/portal/…` lleva al login; con sesión del portal `/actors` lleva a `/portal`; Ventas ve la bandeja con "Ver oportunidad" y no ve Usuarios del portal; teléfono (375 px) sin desplazamiento lateral; modo oscuro.
- Simulado en el navegador (envolviendo las respuestas, sin tocar datos): factura vencida con detracción por depositar, renovación próxima, indicadores en ámbar, rol Portal Finanzas abriendo Cotizaciones ("Sin acceso"), portal desactivado y una cotización de otro cliente ("No encontrada").

## Datos de prueba que quedaron en Tenant A

Cuenta del portal de Rosa Quispe (Portal Admin de Minera) e invitación pendiente de Luis Rojas (Portal Finanzas); COT-2026-0013 (enviada) y 0014 (aprobada desde el portal), 0015 (borrador, desde OP-2026-0007); SOL-2026-0004 (renovación, en CRM) y 0005 (cotizada); OP-2026-0006 y 0007; TKT-2026-0009; dos avisos vigentes; el entregable "Informe de prueba (prueba)" de PRY-2026-0003, aprobado.

## En el VPS (2026-10-22)

Desplegado `a61323e` con `deploy.sh` (respaldo `/root/backups/erp/20261022_203718_antes_deploy_8e21ed7`): 87 archivos, las migraciones `2026_10_22_000100` y `000200` corridas, la web compilada y reiniciada. Solo se tocó este ERP. La migración activó el módulo Portal de clientes en las 7 empresas con suscripción vigente (Base, Profesional o Enterprise). Comprobado: los 7 sitios del servidor responden igual que antes (`estado.sh`); `/login` y `/portal/activate` 200, `/portal` sin sesión lleva al inicio de sesión, `/api/v1/me` y `/api/v1/portal/me` 401. En el VPS no hay cuentas del portal todavía (la de Rosa es solo de la PC).

## Pendiente

- **VPS:** correr la migración (con `deploy.sh`, que la incluye) y activar el módulo en las empresas que lo necesiten (la migración ya lo hace con las que tienen suscripción vigente).
- Pago en línea (pasarela): la pantalla ya avisa.
- Avisos por correo o en la campana (Notificaciones) cuando la empresa responde un ticket, envía una cotización o publica un aviso.
- Una pantalla Documentos para marcar documentos como visibles en el portal (hoy se ven solos los entregables aprobados).
- Reclamos de garantía desde el portal (el prototipo los permite; en "Cliente (Portal)" figuran como "aún no en el portal").
- En la ficha del cliente (Clientes y actores), ver sus usuarios del portal.
