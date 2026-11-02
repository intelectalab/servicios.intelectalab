# Seguridad y aislamiento (Administración)

Fecha: 2026-11-01.

## 1. Qué se pidió

La última de las cuatro pantallas de Administración que faltaban. En el prototipo es una demostración (botones que "intentan" leer datos de otra empresa, el pipeline y una lista fija de controles). Se pidió **sin demo**, con cuatro partes reales: prueba de aislamiento, estado de los controles, actividad de acceso y política de la empresa.

## 2. Qué se cambió

**Pantalla** (`/security`; `components/dashboard/admin/SecurityView.tsx`), en dos columnas como el prototipo:

- **Prueba de aislamiento entre empresas:**
  - "Probar aislamiento" toma, por cada tabla (Cotizaciones, Contratos, Comprobantes, Documentos, Credential Vault, Tickets, Clientes y actores), **un registro real de otra empresa**, e intenta leerlo con el usuario de la aplicación y el contexto de esta empresa.
  - Muestra Bloqueado ("NOT_FOUND: la base no lo entrega ni revela que existe"), **FUGA** si se pudiera leer, o "Sin datos para probar" si ninguna otra empresa tiene registros en esa tabla.
  - Nunca devuelve el dato ni el identificador ajeno.
  - Cada prueba queda en la auditoría y la pantalla muestra la última.
- **Controles de seguridad, comprobados en el momento:**
  - aislamiento en la base (cuántas tablas con datos de empresa tienen su regla, y cuáles faltan);
  - la empresa sale de la sesión;
  - código para el administrador (avisa si está desactivado en el servidor);
  - código para todos (cuántos usuarios lo tienen);
  - contraseñas con Argon2id;
  - bloqueo por intentos;
  - auditoría inalterable (permisos reales de la base);
  - archivos por enlace firmado;
  - credenciales cifradas;
  - planes y suscripciones protegidos;
  - HTTPS (avisa si no);
  - vencimiento de las sesiones.
- **Actividad de acceso (últimos 7 días):** ingresos y cuántos sin código, contraseñas incorrectas, bloqueos, cuentas bloqueadas ahora, sesiones abiertas y cuántas sin código. Debajo, los últimos 25 movimientos (ingreso, salida, contraseña incorrecta, bloqueo, cierre por inactividad), con su IP y exportar.
- **Política de la empresa** (la cambia quien tiene `tenant.manage`):
  - **código de verificación para todos** los usuarios, no solo el administrador;
  - **duración de la sesión** (1 a 12 h);
  - **permitir "Recordarme"** (30 días);
  - **cerrar por inactividad** (no, 15, 30, 60, 120 o 240 minutos).

**Dónde se aplica la política:**
- Al iniciar sesión: el código para todos, la duración y "Recordarme".
- En cada petición, la inactividad: la sesión sin uso más del tiempo elegido se cierra, queda en la auditoría y la persona vuelve al ingreso con el aviso.
- La duración aplica a las sesiones nuevas; la inactividad, también a las abiertas.

**Contraseña incorrecta:** ahora queda en la auditoría de la empresa ("Contraseña incorrecta"). Antes solo se registraba el bloqueo.

**Hallazgo corregido:** al contar las tablas con la regla de aislamiento salieron 150 de 151. Faltaba `tenant_status_log` (el historial de estados de cada empresa, de la consola). Ahora la tiene, y la consola escribe en ella dentro del contexto de la empresa.

**Permisos** (matriz del prototipo: la pantalla es de quien ve la auditoría): ver y probar, `audit.view` (Administrador, Gerencia); cambiar la política, `tenant.manage` (Administrador). Exportar la actividad pide `audit.export`.

## 3. Qué NO se tocó

- Las reglas de siempre: código obligatorio para el administrador (`ADMIN_MFA_REQUIRED`), bloqueo a los 5 intentos y "Mi perfil → Cerrar las demás sesiones".
- Superadmin y portal de clientes no siguen la política de la empresa: tienen la suya (siempre con código).

## 4. Diferencias con el prototipo

- La prueba de aislamiento y los controles son reales, no una simulación.
- No se trae el recuadro "Pipeline obligatorio de cada mutación": es documentación del prototipo, no un dato de la empresa.
- Mejoras: actividad de acceso y política de la empresa.

## 5. Base de datos

Migración `2026_11_01_001000_security_screen` (como `erp_user`):

- `personal_access_tokens.last_activity_at`: última actividad de la sesión, para la inactividad. Se anota como mucho una vez por minuto.
- `isolation_probe_targets()`: un identificador de otra empresa por tabla de la lista, solo para la prueba. La ejecuta solo `erp_app`.
- Regla de aislamiento en `tenant_status_log`.
- La política se guarda en `tenants.configuration.security`.

## 6. Cómo se probó

- `SecurityScreenTest` (3 pruebas nuevas):
  - con una cotización real de otra empresa, la prueba sale Bloqueado, sin ninguna FUGA y sin devolver su identificador, y queda en la auditoría;
  - los controles de aislamiento, auditoría, plataforma, archivos y contraseñas salen activos;
  - la política: sesión de 8 h sin ella; con 2 h y sin "Recordarme", la sesión dura 2 h aunque se marque;
  - 15 minutos sin uso cierran la sesión (401 `SESSION_IDLE`, y la sesión se borra);
  - valores inválidos se rechazan;
  - con código para todos, Ventas ya no entra sin él;
  - una contraseña incorrecta aparece en la actividad;
  - quien solo ve no cambia la política;
  - sin permiso no se abre.
- Las pruebas de la consola (estados de la empresa, con la nueva regla de aislamiento) pasan igual.
- En el sistema local, como Administrador:
  - la prueba de aislamiento dio Bloqueado en Clientes y actores, la única tabla con registros de otra empresa en esta base, y "Sin datos para probar" en las demás;
  - los controles salen bien, salvo dos avisos esperados en tu PC: el código del administrador está desactivado (`ADMIN_MFA_REQUIRED=false`) y no hay HTTPS;
  - la actividad muestra los ingresos de hoy;
  - en 375 px no hay desplazamiento horizontal.
  - No cambié la política de Tenant A.

## 7. Pendiente

- Cerrar una sesión concreta desde aquí (hoy, cada persona cierra las suyas en Mi perfil).
- Aviso por correo al administrador ante bloqueos repetidos.
