# Credential Vault (Control)

Fecha: 2026-11-01.

## 1. Qué se pidió

Construir el submódulo **Credential Vault** de Control según el prototipo, con estas decisiones:

- "Usar" copia el valor al portapapeles sin mostrarlo.
- Viene con el plan base, como Auditoría.
- Permisos por rol según la matriz del prototipo.

## 2. Qué se cambió

**Pantalla** (`/credentials`, menú Control → Credential Vault; `components/dashboard/credentials/`):

- Botón **Nueva credencial**: nombre, tipo, pertenece a, fecha de vencimiento y valor secreto (oculto, con un ojo para revisar lo escrito).
- Leyenda "permisos de tu rol" con ✓ o ✕ para Ver metadatos, Usar, Revelar, Rotar y Revocar.
- Filtros: buscador, Tipo y Estado, más los filtros por columna. Exportar a Excel o CSV al pie y por selección. **La exportación nunca lleva los valores.**
- Columnas:
  - **Credencial:** nombre, con el tipo y a quién pertenece debajo.
  - **Valor:** siempre `••••`.
  - **Versión:** con la fecha de la última rotación.
  - **Expira:** con la etiqueta "Pronto" si faltan menos de 30 días.
  - **Estado:** Activa, Expirada o Revocada.
  - **Acciones.**
- Acciones:
  - **Revelar:** el valor aparece en la fila 20 segundos con cuenta regresiva y una ✕ para ocultarlo antes.
  - **Usar:** copia el valor al portapapeles sin mostrarlo.
  - **Rotar:** pide el valor nuevo y la fecha de vencimiento. Si la fecha anterior sigue vigente, viene puesta.
  - **Revocar.**
  - **Historial.**
- **Todas las acciones piden motivo.** Sin el permiso, el botón sale con candado. Si el estado no lo permite, sale desactivado con el motivo: una revocada no se revela, no se usa ni se rota; una expirada solo se rota o se revoca.
- Clic en la fila o en Historial: ficha con los datos, las **versiones** (quién, cuándo y por qué) y los **accesos** (quién, qué hizo, cuándo, motivo y si fue rechazado). Nunca muestra valores.

**API** (`CredentialController`):

- Listado con búsqueda, filtros por tipo y estado, filtros por columna, orden y exportación (`credentials.export`).
- Acción nueva `POST /credentials/{id}/use`, con permiso `credentials.use`.
- Historial nuevo `GET /credentials/{id}/history`.
- Revelar, usar, rotar y revocar exigen motivo. Queda en el registro de accesos y en Auditoría, nunca el valor.
- Revelar y usar tienen un límite de 30 por minuto.
- Una revocada no se rota ni se revoca dos veces. Rotar y revocar se guardan completos o no se guardan.
- Un intento rechazado (expirada o revocada) también queda en el registro de accesos.
- **Vencimiento:** "Expira el 15/12" vale todo ese día en hora de Lima. Pasada la fecha, la credencial se muestra Expirada de inmediato. La tarea diaria `credentials:expire` (00:15, hora de Lima, con el cron existente) la marca en la base y deja el registro en Auditoría.
- Textos de Auditoría para cada acción. El filtro Entidad ya mostraba "Credencial".

**Base de datos** (migración `2026_11_01_000200_credential_vault_screen`):

- Columna `owner_label` ("Pertenece a"). Los tipos quedan limitados a los del prototipo, con una restricción en la base; los tipos anteriores se pasaron a su equivalente.
- Motivo en el registro de accesos y en cada versión.
- **Candados:** la aplicación ya no puede cambiar ni borrar versiones ni accesos, ni borrar credenciales (se revocan).
- Permiso nuevo `credentials.export`, y nombres de los permisos según las acciones.
- Permisos por rol en las empresas existentes:

| Rol | Permisos |
|---|---|
| Administrador | Todo |
| Gerencia y Finanzas | Ver |
| Operaciones y Técnico | Ver y usar |

- Los permisos del Vault entran en `ModulePermissions::BASE`, así que el administrador de una empresa nueva los recibe y se pueden asignar en Roles y permisos.

## 3. Qué NO se tocó

- Documentos y Auditoría.
- El cifrado: sigue con la `APP_KEY` del servidor. **Si alguna vez se cambia la `APP_KEY`, las credenciales guardadas dejan de poder leerse.** Antes de cambiarla habría que descifrar y volver a cifrar todo.

## 4. Diferencias con el prototipo

- **"Usar"** en el prototipo pasa la credencial a una integración. Aquí copia el valor al portapapeles sin mostrarlo, porque todavía no hay integraciones.
  - Para que el navegador pueda copiarlo, el valor sí llega a la pantalla. Quien solo tiene "Usar" no lo ve en pantalla, pero alguien con conocimientos técnicos podría leerlo con las herramientas del navegador. Cada uso queda registrado.
- **Exportar** es una mejora (regla de exportar al pie en toda tabla).
- **"Pertenece a"** es texto libre, como en el prototipo. No se vincula a una ficha de cliente.

## 5. Base de datos

Migración aditiva: columnas, una restricción, un índice, permisos y candados. Se corre como `erp_user`. `apps/api/database/schema_reference.sql` se regeneró para la prueba de GitHub. Ahora el comando quita bien las líneas `\restrict`; se corrigió en la nota de la prueba automática.

## 6. Cómo se probó

- **Pruebas nuevas en `CredentialVaultTest`** (5 pruebas, 108 comprobaciones):
  - Guardar, revelar y usar con motivo, rotar con versión y motivo, revocar una sola vez y bloqueos por estado.
  - Historial y Auditoría sin el valor.
  - Vencimiento: se muestra al instante, la tarea diaria lo marca y rotar reactiva.
  - Cada acción es un permiso distinto.
  - Otra empresa → 404 y sin permiso → 403.
  - Candados de la base y valor cifrado.
  - Permisos en el plan base.
- **API completa: 307 pruebas pasan.** `tsc` y ESLint sin errores.
- **Navegador (1366 × 768 y 375 px)** con la cuenta Operaciones, a la que di por un rato los permisos de crear, revelar, rotar, revocar y exportar, y luego se los quité:
  - Errores de campos obligatorios.
  - Alta con tipo Hosting y vencimiento en 15 días ("Pronto").
  - Revelar con motivo obligatorio: se ve 20 s y se oculta.
  - Usar copia el valor exacto sin mostrarlo.
  - Rotar con la fecha anterior ya puesta pasa a v2.
  - Revocar desactiva todo con su motivo.
  - Historial completo.
  - CSV sin valores.
  - Filtro Estado.
  - Una expirada: Revelar y Usar desactivados, Rotar disponible.
- **Ya como Operaciones real** (ver y usar): candados en Revelar, Rotar, Revocar, Nueva credencial y exportar. Sin desplazamiento lateral en el móvil.
- **Ventas:** no ve el Vault en el menú y la dirección muestra "Tu rol no tiene permiso".
- **Datos de prueba que quedan en tenant-a:**
  - "Hosting cPanel Plaza Sol" (revocada).
  - "Certificado digital SUNAT" (activa).
  - "DNS del dominio principal", con el vencimiento adelantado a mano en la base para verla Expirada.

## 7. Pendiente

- Ver la pantalla con la cuenta de Administrador y la matriz de Roles y permisos.
- Desplegar: la migración y el cron diario ya existente (la tarea nueva entra sola en `schedule:run`).
