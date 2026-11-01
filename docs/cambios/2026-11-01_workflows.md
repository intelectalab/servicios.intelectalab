# Workflows (Control)

Fecha: 2026-11-01.

## 1. Qué se pidió

Construir **Workflows** según el prototipo: reglas automáticas que arma cada empresa, del tipo "cuando pasa X → si se cumple Y → entonces Z". Decisiones tomadas:

- La condición se elige de una lista de campos, no escribiendo el nombre técnico.
- Sin la acción "crear evento facturable": la OT completada ya lo crea sola.
- Sin reglas de ejemplo cargadas: van como plantillas.
- Permisos según la matriz del prototipo.
- Incluido en el plan base.

## 2. Qué se cambió

**Pantalla** (`/workflows`, menú Control → Workflows; `components/dashboard/workflows/`):

- **Una tarjeta por regla:** nombre, interruptor para activarla o desactivarla, la cadena "Cuando → Si → Entonces", cuántas veces se ejecutó y cuándo fue la última.
  - Si el módulo del disparador está apagado, avisa "inactivo porque el módulo está apagado".
  - Botones "Ver ejecuciones" (filtra el historial) y "Editar".
- **Nuevo workflow / Editar:**
  - **Cuando:** los 12 disparadores, con buscador; los de módulos apagados lo dicen.
  - **Si:** "Siempre" o un campo del disparador, con su operador y su valor (número, lista o Sí/No).
  - **Entonces:** de 1 a 3 acciones, cada una con su texto. Las acciones son: avisar a un rol de la empresa, avisar al responsable o publicar un aviso en el portal del cliente (no se ofrece en los disparadores sin cliente).
- **Plantillas** (las del prototipo, cambiando la del evento facturable por un aviso):
  - Ticket crítico: avisar a Gerencia.
  - Cotización aprobada: preparar el contrato.
  - Pago aplicado: avisar al cliente en el portal.
  - Oportunidad ganada de alto valor: avisar a Gerencia.
  - OT completada: avisar a Finanzas.

  "Usar" abre el formulario lleno, con el rol ya elegido si la empresa lo tiene.
- **Últimas ejecuciones:** workflow, disparador, registro, condición (Cumplida o No cumplida), estado (Ejecutada, Omitida o Falló), resultado y fecha. Tiene filtros por workflow y por estado, filtros por columna, orden y exportación (`workflows.export`).

**Disparadores (12):** OT completada, ticket creado, ticket clasificado, cotización aprobada, cotización rechazada, pago aplicado, horas aprobadas, oportunidad ganada, contrato activado, comprobante emitido, ciclo recurrente procesado y lead creado.

| Disparador | Campos para la condición |
|---|---|
| OT | Prioridad |
| Tickets | Prioridad, categoría y canal |
| Cotizaciones, pago, oportunidad, contrato y ciclo recurrente | Monto en soles |
| Comprobante | Total y tipo |
| Horas | Horas y facturable |
| Lead | Puntuación |

**Motor** (`App\Services\Workflows`):

- **Se conecta en un solo punto: la auditoría.** Cuando se registra una de las 12 acciones (por ejemplo `ticket.triage`, `work_order.complete` o `quotation.approve`, también desde el portal), las reglas activas de ese disparador corren **después de que la operación se guardó**. Si la operación se deshace, no corren.
- **Si una regla falla, la operación no se pierde:** la ejecución queda como "Falló" y el error en el log.
- **Una sola vez por evento:** cada ejecución guarda el registro de auditoría que la disparó, y la base no deja repetirlo. Antes de actuar se comprueba que no haya corrido.
- **Sin bucles:** lo que hace una regla no dispara otras reglas.
- **Los avisos llegan solo a quien puede ver el registro**, es decir, a quien tiene el permiso del módulo y el alcance.
  - "Avisar a un rol" avisa a los del rol que lo ven. Si nadie lo ve, la ejecución dice "Nadie de Gerencia puede ver este registro".
  - "Avisar al responsable" no avisa a quien hizo la acción.
  - Los avisos van por la campana y por correo, según lo que cada persona eligió en Mi perfil.
- El aviso en el portal dura 30 días.
- Los montos se comparan en soles; lo que está en dólares se convierte con el tipo de cambio guardado o el vigente.

**API** (`WorkflowController`):

- `GET /v1/workflows`: reglas, catálogo (disparadores con sus campos, operadores y acciones) y roles de la empresa.
- `POST /v1/workflows` y `PUT /v1/workflows/{id}`: valida que el campo sea del disparador, el operador sea de su tipo, el valor sea válido, el rol sea de la empresa y que no haya aviso en el portal sin cliente.
- `POST /v1/workflows/{id}/toggle`: activar o desactivar.
- `GET /v1/workflow-runs`.
- Todo queda en Auditoría (`workflow.create`, `.update`, `.activate`, `.deactivate`).

**Base de datos** (migración `2026_11_01_000400_workflows`, sobre las tablas que ya existían):

- `workflow_definitions`: quién creó y quién editó la regla.
- `workflow_runs`: el evento que disparó la ejecución (`event_key`, único por regla), disparador, registro, si se cumplió la condición y resultado. El estado solo puede ser DONE, SKIPPED o FAILED.
- **Candados:** la aplicación no puede cambiar ni borrar ejecuciones, ni borrar reglas (se desactivan).
- Permisos `workflows.view`, `workflows.manage` y `workflows.export` (plan base): Administrador todo; Gerencia ve.

**Corrección del día:** el PDF de Reportes había dejado que el registro de exportaciones aceptara "pdf" en cualquier listado. Ahora solo lo acepta en Reportes, como espera la prueba `ExportPermissionTest`.

## 3. Qué NO se tocó

- Los avisos fijos que ya existían (cotización por aprobar, ticket asignado…). Siguen igual; los workflows se suman a ellos.
- La creación automática del evento facturable de la OT.

## 4. Diferencias con el prototipo

- La condición se elige de una lista; en el prototipo se escribe el nombre técnico del campo.
- No está la acción "crear evento facturable", porque ya ocurre sola.
- Las reglas del prototipo van como plantillas, no cargadas.
- Mejoras: hasta 3 acciones por regla, editar, filtros y exportación del historial.

## 5. Base de datos

Migración aditiva: columnas, una restricción única, un CHECK, índices, permisos y candados. `schema_reference.sql` regenerado; se restaura sin errores en una base vacía.

## 6. Cómo se probó

- **Pruebas nuevas en `WorkflowsTest`** (5 pruebas, 101 comprobaciones):
  - Validación del formulario.
  - Ticket clasificado P1 con aviso a Gerencia:
    - con alcance "Propios" no llega ("Nadie de Gerencia puede ver este registro");
    - con alcance "Empresa" llega a los dos del rol, con su texto y enlace;
    - no llega a alguien de otro rol.
  - P3 queda como "Condición no cumplida". Desactivada no corre.
  - Aviso en el portal y "el responsable hizo la acción: no se le avisa".
  - El mismo evento no corre dos veces ni repite el aviso. Esto se encontró en la prueba y se corrigió.
  - Disparador sin cliente sin aviso en el portal.
  - Condiciones por tipo de campo.
  - Gerencia solo ve; sin permiso, 403; otra empresa, 404.
  - Candados de la base.
- **API completa: 317 pruebas pasan.** `tsc` y ESLint sin errores.
- **Navegador como Administrador** (1366 × 768 y 375 px):
  - La plantilla "Ticket crítico" llena el formulario con Gerencia elegida.
  - Con un ticket P1 real, Gerencia recibió el aviso con el texto y el enlace al ticket; un P4 quedó como "No cumplida / Omitida".
  - Interruptor, "Ver ejecuciones", editar (P1 → P2 → P1) y una regla nueva desde cero (cotización aprobada, monto mayor que 5,000, aviso en el portal).
- **Datos de prueba que quedan en tenant-a:**
  - Las dos reglas, **desactivadas** para que no envíen avisos mientras trabajas.
  - Dos tickets de prueba: TKT-2026-0012 y TKT-2026-0013.

## 7. Pendiente

- Ver la pantalla con la cuenta de Gerencia (solo lectura), que tiene contraseña propia; está cubierto por las pruebas.
- Desplegar la migración.
