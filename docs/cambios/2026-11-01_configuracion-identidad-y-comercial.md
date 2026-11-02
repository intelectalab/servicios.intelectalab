# Configuración: Identidad y región, y Comercial

Fecha: 2026-11-01.

## 1. Qué se pidió

Agregar a Configuración lo que el prototipo muestra en esa pantalla (Identidad y región, Comercial, Fiscal, Portal y seguridad), salvo lo que ya esté en otra parte. **Sin eliminar ni cambiar nada de lo que ya tenía Configuración** (logo, colores, pie de los documentos, página de ingreso y vista previa).

## 2. Qué se cambió

Arriba de lo que ya había, que sigue igual (en `BrandingSettings.tsx` solo se agregaron 4 líneas para mostrar las tarjetas nuevas; `components/dashboard/settings/CompanySettingsCards.tsx`):

- **Identidad y región:**
  - **Nombre comercial**, editable (queda en la auditoría).
  - **Razón social** y **RUC**, de solo lectura, como en el prototipo: son los fiscales, que fija la plataforma al dar de alta la empresa.
  - **Zona horaria** y **moneda base**, de solo lectura: el sistema trabaja en hora de Perú y la contabilidad se lleva en soles (se cotiza también en dólares). Cambiarlas aquí no cambiaría nada, así que no se ofrece como si lo hiciera.
- **Comercial:** **Vigencia por defecto de cotizaciones (días)**, 15 si no se cambia (de 1 a 365).
  - Antes, el editor proponía siempre 15 días fijos; ahora propone los de la empresa.
  - Una cotización creada sin fecha (por ejemplo, desde la API) también nace con esa vigencia.
- **También se configura en:** enlaces a lo que el prototipo pone en esta pantalla y en el sistema ya existe en otra:
  - **Fiscal (Perú / SUNAT):** series, IGV, detracciones y proveedor, en Facturación electrónica.
  - **Segregación de funciones y orden directa:** en Roles y permisos → Reglas de la empresa.
  - **Código de verificación y sesiones:** en Seguridad y aislamiento. El portal siempre pide código.

**API:**
- `GET/PUT /v1/tenant/identity` (`tenant.manage`); el PUT solo cambia el nombre comercial.
- `quotation_validity_days` en `GET/PUT /v1/tenant/settings`.

## 3. Qué NO se tocó

- Todo lo que ya tenía Configuración.
- Las series y el IGV (Facturación electrónica), las reglas de la empresa (Roles y permisos) y la seguridad: no se duplican aquí.

## 4. Diferencias con el prototipo

- La tarjeta Fiscal y el interruptor "Exigir MFA a usuarios del portal" no se repiten: lo primero ya está en Facturación electrónica. Lo segundo no es opcional en este sistema (el portal siempre pide código), y apagarlo bajaría la seguridad.
- "Proveedor fiscal: OSE (simulado)" no se muestra: aquí no se simula; el estado real está en Facturación electrónica.

## 5. Base de datos

Sin migraciones. La vigencia se guarda en `tenants.configuration.quotation_validity_days`; el nombre comercial, en `companies.trade_name`.

## 6. Cómo se probó

- `CompanySettingsTest` (2 pruebas nuevas):
  - la identidad muestra los datos fiscales; solo cambia el nombre comercial y queda en la auditoría;
  - sin permiso no se ve ni se cambia;
  - la vigencia va de 1 a 365;
  - una cotización sin fecha nace con 30 días si la empresa eligió 30, y una con fecha conserva la suya.
- En el sistema local, como Administrador:
  - Configuración muestra las tarjetas nuevas arriba, y debajo todo lo de antes;
  - una cotización nueva propone hoy + 15 días;
  - en 375 px no hay desplazamiento horizontal.
  - No se guardó ningún cambio en Tenant A.

## 7. Después: dos pestañas (2026-11-02)

La página mezclaba los datos de la empresa con la marca y se veía desordenada. Se decidió separarla en **pestañas dentro de la misma Configuración** (el menú sigue con una sola entrada, como el prototipo):

- **General:** Identidad y región, Comercial y "También se configura en".
- **Marca de la empresa:** logo, colores, pie de los documentos, página de ingreso y la vista previa, con el botón "PDF de ejemplo". Su contenido y su disposición no cambiaron.

La pestaña elegida se recuerda en el navegador de cada persona; si el navegador no deja guardar, vale solo durante la visita. Se abre en General la primera vez.

**Probado:** como Administrador, cada pestaña muestra sus tarjetas, "Marca de la empresa" queda recordada al recargar, y en 375 px ninguna de las dos tiene desplazamiento horizontal.

**Corrección (2026-11-02): parpadeo al volver a General.** Al pasar de Marca a General se veía un instante el bloque de carga: General volvía a pedir sus datos cada vez. Ahora recuerda lo último cargado y lo muestra al instante, mientras lo actualiza por detrás. Lo recordado está atado a la sesión, así que otra persona que entre después en el mismo navegador no lo ve. Probado: en 3 cambios seguidos de Marca a General, el bloque de carga no apareció ninguna vez.
