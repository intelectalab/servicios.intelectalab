# Reglas de aprobación (Administración)

Fecha: 2026-11-01.

## 1. Qué se pidió

La tercera de las cuatro pantallas de Administración que faltaban. Decisión: **cada nivel tiene un nombre y los roles que la empresa elige** (por defecto, nivel 1 Gerencia y nivel 2 Administrador). Quien creó la cotización no la autoriza.

## 2. Qué se cambió

**Regla de negocio:** una cotización en **borrador** que supera una regla de la empresa necesita **autorización interna, nivel por nivel**, antes de "Enviar a aprobación".
- **Reglas:** por monto total en soles (las de dólares se convierten con su tipo de cambio) o por descuento de una línea en %. Cada regla exige el nivel 1 o el 2.
- **Quién decide:**
  - cada nivel lo autorizan las personas con los roles elegidos para él;
  - primero el 1 y después el 2;
  - **quien creó la cotización nunca la autoriza;**
  - una misma persona no decide dos niveles.
- **Rechazar** pide motivo.
- **Si la cotización cambia** de monto o descuentos después de pedir o conseguir la autorización, esta se anula y hay que pedirla de nuevo.
- **Sin reglas, todo sigue como antes.** La empresa nace sin reglas: no cambia nada hasta que las crea.
- Lo que ya existía no cambia: la aprobación posterior de Gerencia ("Aprobar", con segregación de funciones), la aprobación del cliente en el portal y el límite de crédito.

**Pantalla** (`/approval-rules`; `components/dashboard/admin/ApprovalRulesView.tsx`), con la disposición del prototipo:

- **Reglas vigentes:** nombre, criterio (Monto total o Descuento de línea), "Mayor a" y nivel. Se agregan y se quitan.
- **Quién autoriza cada nivel** (más que en el prototipo, por la decisión tomada): nombre del nivel y sus roles.
- **Simulador:** con un monto y un descuento, dice si requiere autorización, hasta qué nivel, por qué regla y quién autoriza. Usa las reglas que están en pantalla, aunque aún no se hayan guardado.
- **Cotizaciones esperando autorización:** número, cliente, total y a qué nivel le toca. Tiene exportar, y el clic abre la cotización.

**Ficha de la cotización:**
- **Tarjeta "Autorización interna"** (movible, como las demás):
  - estado: No requiere, Falta pedirla, Pendiente, Autorizada o Rechazada;
  - los motivos;
  - cada nivel con quién lo decidió y cuándo, o quién puede autorizarlo;
  - "Pedir autorización", "Autorizar nivel N" y "Rechazar" (con motivo). Si la persona no puede decidir, el botón dice por qué.
- **"Enviar a aprobación"** queda deshabilitado, con su motivo, mientras falte la autorización.

**Avisos** (campana y correo, con plantilla en Notificaciones):
- "Cotización por autorizar", a quienes autorizan el nivel que toca y ven la cotización;
- "Cotización autorizada" y "Autorización rechazada", a quien la creó y a quien la pidió.

**Permisos:**
- `approvals.view`: ver la pantalla. Administrador y Gerencia.
- `approvals.manage`: cambiar reglas y niveles. Administrador.
- Pedir la autorización pide `quotations.manage`; decidir un nivel depende del rol del nivel, no de un permiso.
- Del módulo Cotizaciones del plan.

## 3. Qué NO se tocó

- La aprobación de Gerencia después de enviar y la del cliente en el portal.
- Contratos y órdenes de compra no usan estas reglas (las órdenes de compra ya tienen su propia aprobación por otra persona).

## 4. Diferencias con el prototipo

- En el prototipo los niveles son fijos (Gerencia comercial y Gerencia general). Aquí la empresa elige el nombre y los roles de cada nivel.
- "Para quitar una regla, deja su nombre vacío" sigue valiendo, y además hay un botón para quitarla.
- Mejoras:
  - una persona no decide dos niveles;
  - la autorización se anula si la cotización cambia;
  - motivo obligatorio al rechazar;
  - exportar las pendientes.

## 5. Base de datos

Migración `2026_11_01_000900_quotation_authorizations` (como `erp_user`):

- `quotation_authorizations`: solicitudes, como mucho una pendiente por cotización, con la huella de montos y descuentos. Solo cambia su estado; anular pide motivo.
- `quotation_authorization_steps`: la decisión de cada nivel, de solo alta. **La base impide** que quien creó la cotización decida, que una persona decida dos niveles y que se decida una solicitud cerrada.
- Permisos `approvals.*`.
- Reglas y niveles en `tenants.configuration.approval`.

## 6. Cómo se probó

- `QuotationAuthorizationTest` (3 pruebas nuevas):
  - sin regla alcanzada, se envía como siempre;
  - 60 000 + IGV exige el nivel 2: no se envía sin autorización; quien la creó no autoriza; el nivel 2 no se salta; con los dos niveles se envía;
  - un descuento del 20 % exige su nivel; la misma persona no decide el nivel 2;
  - cambiar el descuento anula lo autorizado;
  - rechazar pide motivo;
  - la base rechaza la decisión de quien creó la cotización;
  - un nivel con reglas y sin roles no se guarda;
  - permisos de ver y de cambiar.
- En el sistema local, con una regla temporal (monto mayor a S/ 15 000):
  - Ventas creó **COT-2026-0018** (de prueba, "Prueba de reglas de aprobación", S/ 21 240); no se pudo enviar y pidió la autorización;
  - como Administrador, la vi en la pantalla de reglas y en la ficha (tarjeta, "Enviar a aprobación" deshabilitado con su motivo) y autoricé el nivel 1; se habilitó el envío;
  - **la regla temporal se quitó al terminar:** Tenant A quedó sin reglas, con el nivel 1 en Gerencia y el 2 en Administrador. COT-2026-0018 quedó en borrador, autorizada.

## 7. Pendiente

- Usar las mismas reglas en contratos u órdenes de compra, si se pide.
- Más de dos niveles, si alguna empresa lo necesita.
