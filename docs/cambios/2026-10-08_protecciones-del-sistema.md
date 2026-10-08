# 2026-10-08 — Protecciones del sistema (riesgos de la comparación con el kit)

Viene de [../comparacion-con-el-kit.md](../comparacion-con-el-kit.md), sección 5.

## Qué se pidió

Tras comparar el sistema con el kit de desarrollo: el kit es una guía, lo que choca con decisiones ya tomadas se queda como está, y se arreglan los riesgos encontrados. "Todo cambio debe buscar la protección del sistema." También: excluir la carpeta `kit/` del repositorio.

## Qué se cambió

### Base de datos (migración `2026_10_08_000000_harden_isolation_audit_and_conversions`)

No cambia ningún dato. Ya está corrida en la base local, con el usuario dueño.

| Protección | Antes | Ahora |
| --- | --- | --- |
| Nombres y documentos (`persons`, `organizations`) | Sin regla de aislamiento propia: dependían de que el código consultara siempre a través de `parties` | La base solo deja ver y escribir las de una identidad de la empresa en curso. Sin empresa en curso no se ve ninguna |
| Auditoría (`audit_logs`) | La aplicación podía modificar y borrar registros | Solo puede agregar y leer |
| Catálogo de permisos (`permissions`) | La aplicación podía crear, cambiar y borrar permisos | Solo lectura; los permisos se crean por migración |
| Cotización → contrato | Se evitaba el duplicado con una consulta previa | Además, la base admite un solo contrato por cotización |
| Cotización → orden y contrato → orden | Igual | Además, la base admite una sola orden por cotización y una por contrato |

### API

| Archivo | Cambio |
| --- | --- |
| `app/Http/Middleware/CheckPermission.php` | **El candado de módulo ya no es solo visual.** Un permiso solo vale si la empresa tiene el módulo al que pertenece; si no, responde 403 con el código `MODULE_DISABLED`. Antes solo CRM lo respetaba. Los permisos que no dependen de un módulo (usuarios, roles, ajustes) no cambian. Sin permiso responde ahora con el código `PERMISSION_DENIED`. |
| `routes/api.php` | Límite por dirección en `/login` (20 por minuto) y en el registro público de empresas (5 cada 10 minutos). Cada límite lleva su propio contador. Las rutas de prueba `/test/*` solo existen en desarrollo y en las pruebas. **Se desactivaron el alta y la importación de `/customers`, `/suppliers` y `/employees`** (quedan comentadas): creaban identidades sin revisar duplicados. Consultar, editar y activar siguen funcionando. |
| `app/Http/Controllers/Auth/AuthController.php` | Un usuario al que se le quitó el acceso a su empresa ya no recibe sesión. Cambiar la contraseña cierra las demás sesiones (la actual sigue). Las contraseñas guardadas con el algoritmo anterior se actualizan solas al iniciar sesión. |
| `app/Http/Controllers/Auth/PasswordResetController.php` | Restablecer la contraseña cierra todas las sesiones de esa cuenta. |
| `config/hashing.php` (nuevo) | Las contraseñas se guardan con **Argon2id**. Las anteriores (bcrypt) siguen sirviendo y se convierten al entrar. |
| `bootstrap/app.php` | Un identificador mal formado responde "no encontrado" (404) en vez de fallo interno. Una operación repetida que la base rechaza responde 409 con un aviso claro. |
| `config/cors.php` | Los dominios que pueden llamar a la API se definen con `CORS_ALLOWED_ORIGINS` (sin definir, cualquiera, como hasta hoy). |
| `app/Modules/Core/Http/Controllers/UserController.php` | La contraseña provisional de un usuario nuevo usa el mismo algoritmo. |

### Web

| Archivo | Cambio |
| --- | --- |
| `src/lib/auth.ts` | En un sitio con https, la cookie de sesión solo viaja cifrada. |

### Repositorio

- `.gitignore`: se excluye la carpeta `/kit/`.

## Qué NO se tocó

- Nada de lo que choca con el kit por decisión tuya (colores, rutas, código al correo, tarjetas, etc.).
- Los módulos Clientes, Proveedores y Empleados siguen en el repo; solo se desactivaron sus altas en la API.
- Los datos: ninguna fila cambió, salvo las contraseñas, que se van convirtiendo al iniciar sesión.

## Riesgos del informe: cómo quedó cada uno

| # | Riesgo | Estado |
| --- | --- | --- |
| 1 | Nombres y documentos sin protección propia | **Resuelto** |
| 2 | La auditoría se podía alterar | **Resuelto** |
| 3 | Un solo usuario de base para todo | **Parcial**: la aplicación ya no puede tocar la auditoría ni el catálogo de permisos. Separar la consola de plataforma en su propio usuario de base queda pendiente (requiere una segunda conexión y revisar la consola) |
| 4 | Conversiones duplicables | **Resuelto** |
| 5 | Ediciones simultáneas se pisan | **Pendiente**: va en la siguiente tanda (protecciones), porque la web debe enviar la versión del registro al guardar |
| 6 | Acceso: límites, sesiones, algoritmo de contraseña | **Resuelto**, salvo la cookie legible por el navegador (ver abajo) |
| 7 | Rutas viejas que duplicaban identidades | **Resuelto** |
| 8 | Candado de módulo solo visual | **Resuelto** |
| 9 | Rutas de prueba | **Resuelto** |
| 10 | Secretos de la bóveda en la base | **Pendiente**: necesita elegir un gestor de secretos externo; la pantalla de la bóveda aún no existe |
| — | Usuario desactivado que recibía sesión | **Confirmado y resuelto** |

**Sobre la cookie de sesión:** sigue siendo legible por el navegador. Hacerla invisible exige que la sesión la entregue y la lea el servidor web, es decir, cambiar cómo inicia sesión y cómo llama a la API toda la aplicación. Es un cambio grande que conviene hacer aparte y con su propia prueba.

## Efectos que vas a notar

- Si un rol tiene un permiso de un módulo que la empresa no tiene activado, la API ahora lo rechaza. En "Tenant A - Prueba" los módulos activos son los mismos que ya se veían en el menú, así que no cambia nada visible.
- La primera vez que cada persona inicie sesión, su contraseña se convierte al algoritmo nuevo sin que lo note.
- Cambiar la contraseña desde un equipo cierra la sesión en los demás.
- Más de 20 intentos de inicio de sesión por minuto desde la misma dirección reciben "demasiadas solicitudes".

## Cómo se probó

`php artisan test`: **71 pruebas pasan** (58 anteriores más 13 nuevas en `tests/Feature/ProtectionsTest.php`). Las nuevas comprueban, contra la base real y como el usuario de la aplicación:

1. Una organización y una persona de una empresa no se ven desde otra consultando las tablas directamente, ni sin empresa en curso; y no se puede colgar una persona de una identidad ajena.
2. La aplicación no puede modificar ni borrar un registro de auditoría.
3. La aplicación no puede crear un permiso.
4. Con el permiso pero sin el módulo, la API responde `MODULE_DISABLED`; al activar el módulo, entra.
5. Un usuario desactivado en su empresa no recibe sesión.
6. Cambiar la contraseña deja solo la sesión actual.
7. Una contraseña antigua se convierte a Argon2id al entrar.
8. La base rechaza un segundo contrato y una segunda orden de la misma cotización.
9. Un identificador mal formado responde 404 (cotización, servicio, actor).
10. Las altas de los módulos desconectados ya no responden.
11. El inicio de sesión y el registro público se limitan por dirección.

Se ajustaron tres archivos de pruebas existentes para las reglas nuevas (sus empresas de prueba ahora tienen módulos activados y sus usuarios pertenecen a una empresa); no se aflojó ninguna comprobación.

Contra la API local en marcha, con `ventas@erp-saas.test`: inicio de sesión, listados de actores, cotizaciones, servicios y solicitudes con sus nombres, buscador, 403 con código en Contratos y 404 con un identificador inválido. Su contraseña quedó convertida a Argon2id.

No se probó en el navegador pantalla por pantalla, ni con la cuenta de superadmin, ni el registro de una empresa nueva desde la landing.

## Pendiente

- La integración continua (`.github/workflows/ci.yml`) carga `database/schema_reference.sql` y **no corre las migraciones**: ahí faltan estas protecciones y varias migraciones anteriores, así que las pruebas nuevas fallarían en CI. Hay que regenerar ese archivo o hacer que CI migre.
- `role_permissions` sigue sin regla de aislamiento propia (guarda qué permiso tiene cada rol, sin datos personales).
- Lo que sigue según lo acordado: alcance en permisos, protección contra envíos repetidos y ediciones simultáneas; después, las pantallas que faltan de fundaciones.

## Segunda parte (2026-10-08): ediciones simultáneas y envíos repetidos

Era el riesgo 5 del informe y la otra mitad de la tanda acordada (el alcance en permisos espera tus respuestas).

### Ediciones simultáneas

**El problema:** dos personas abren la misma ficha; una guarda; la otra guarda después con lo que tenía en pantalla y pisa el cambio de la primera sin que nadie se entere.

**Ahora:** cada registro lleva un número de versión que sube con cada cambio. Al guardar, la web envía la versión que la persona tenía a la vista; si ya no es la vigente, el guardado se rechaza con el aviso *"Otra persona modificó este registro mientras lo tenías abierto. Actualiza la página para ver la versión vigente y vuelve a intentarlo."* y no se cambia nada.

- Aplica a: Clientes y actores, Servicios, Cotizaciones, Contratos, Órdenes y Solicitudes del portal. Cuenta editar, cambiar de estado y convertir.
- Un guardado que falla (por ejemplo, por un dato inválido) no gasta la versión: se corrige y se reintenta.
- La comprobación y la subida de versión son una sola operación en la base, así que dos guardados en el mismo instante no pasan los dos.
- De paso, toda modificación de esos registros corre ahora en una transacción: si algo falla a mitad, no queda nada a medias.

### Envíos repetidos

**El problema:** un doble clic en "Guardar", o un reintento por mala conexión, podía crear dos registros.

**Ahora:** cada alta sale de la web con una clave. Si el mismo envío vuelve a salir en los siguientes 10 segundos, usa la misma clave y la API devuelve la respuesta del primero sin repetir la operación.

- Aplica a las altas (crear cotización, actor, servicio, usuario, rol, contacto…) y a las conversiones (nueva versión, contrato, orden, nuevo precio, agregar rol).
- La misma clave con otros datos se rechaza. Un envío que falló no queda recordado: se puede reintentar.
- No aplica a activar / desactivar ni a casillas, donde repetir la acción es intencional.

### Archivos

| Archivo | Cambio |
| --- | --- |
| Migración `2026_10_08_000100_add_lock_version_to_editable_records` | Columna `lock_version` en `parties`, `services`, `quotations`, `contracts`, `orders`, `portal_requests`. Ya corrida en la base local. No cambia datos |
| `app/Http/Middleware/CheckRecordVersion.php` (nuevo) | La comprobación de versión, para cualquier ruta que modifique uno de esos registros |
| `app/Http/Middleware/Idempotency.php` (nuevo) | Las claves de alta, sobre la tabla `idempotency_keys` que ya existía sin uso |
| `bootstrap/app.php`, `routes/api.php` | Los dos controles se aplican a todas las rutas de empresa |
| `PartyController.php` | La fila del actor incluye su versión |
| `apps/web/src/lib/api.ts` | El cliente recuerda la versión de cada registro que carga y la envía al modificarlo; y pone la clave en las altas. **No hubo que tocar ninguna pantalla** |

### Cómo se probó

`php artisan test`: **73 pruebas pasan** (dos nuevas):

1. Dos personas con la versión 0: la primera guarda; la segunda recibe 409 y su cambio no se aplica; con la versión vigente sí guarda; cambiar de estado también respeta la versión; un guardado inválido no gasta la versión.
2. Un alta enviada dos veces con la misma clave crea un solo registro y devuelve la misma respuesta; la misma clave con otros datos se rechaza; sin clave sigue el aviso de duplicado de siempre; un envío fallido se puede reintentar.

En el navegador con admin, en Clientes y actores: se editó el sector de "Actor de Prueba S.A.C." (guardó, versión 1); se simuló que otra persona lo cambiaba (versión 2); al editar de nuevo desde la pantalla, que seguía con la versión 1, apareció el aviso de "Otra persona modificó este registro…" y en la base quedó el cambio de la otra persona. El sector se devolvió a "Tecnología".

No se probó en el navegador: el doble clic real en un alta, ni el aviso de conflicto en las fichas de cotización, contrato, orden y servicio (usan el mismo control y el mismo cliente).

### A tener en cuenta

- Tras el aviso de conflicto hay que actualizar la página; la pantalla no se recarga sola.
- Si una pantalla modifica un registro y la respuesta no trae su versión nueva, el siguiente guardado va sin comprobación hasta que la ficha se vuelva a cargar. Es para no dar avisos falsos.
