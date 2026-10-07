# 2026-10-07 — Contratos: ajustes de la ficha

Continúa [2026-10-07_ordenes.md](2026-10-07_ordenes.md).

## Qué se pidió

Sobre la ficha del contrato:

1. Que aparezcan los botones **Enviar a firma** y **Adjuntar documento**, aunque todavía no tengan función.
2. Que el historial muestre **"Desde COT-…"**.
3. Que Servicios contratados muestre **OT / proyectos**.
4. Que estén las **Instancias recurrentes**.

## Qué se cambió

### API

| Archivo | Cambio |
| --- | --- |
| `Modules/Services/Http/Controllers/ContractController.php` | La ficha devuelve, por cada servicio contratado, cuántas órdenes de trabajo tiene, y la lista de instancias recurrentes del contrato (servicio, próximo ciclo, monto y estado). Al suspender, reactivar, terminar o vencer un contrato, sus instancias recurrentes lo siguen (suspendidas, activas de nuevo o canceladas), como en el prototipo. |

### Web

| Archivo | Cambio |
| --- | --- |
| `components/dashboard/contracts/ContractDetail.tsx` | **Enviar a firma** (solo en borrador, con el permiso `contracts.manage` y su candado) y **Adjuntar documento** (siempre): al pulsarlos avisan que aún no están disponibles. Columna **OT / proyectos** en Servicios contratados. Tarjeta **Instancias recurrentes** (Servicio, Próximo ciclo, Monto, Estado), que aparece cuando el contrato tiene alguna, como en el prototipo. Fila **Documentos** en Relacionado. El historial muestra **"Desde COT-…"** también en los contratos anteriores, que no lo habían guardado. |
| `types/commercial.ts` | Tipos de los datos nuevos. |

## Qué NO se tocó

Clientes, Proveedores, Empleados, Planes SaaS. El resto de la lógica de contratos.

## Diferencias con el prototipo

- **Enviar a firma** y **Adjuntar documento** no hacen nada todavía: muestran un aviso. La firma electrónica y la pantalla Documentos no están construidas.
- En el prototipo "Enviar a firma" solo sale si la empresa exige firma electrónica; ese ajuste no existe aquí, así que sale en todo contrato en borrador.
- **Instancias recurrentes:** hoy ningún contrato tiene, así que la tarjeta no se ve. En el prototipo nacen cuando Operaciones **activa un servicio contratado** recurrente, y esa pantalla (Servicios contratados) aún no existe.
- **OT / proyectos:** el número de proyectos sale siempre en 0, porque los proyectos se enlazan al contrato y no a un servicio contratado.

## Base de datos

Sin migraciones ni datos movidos.

## Cómo se probó

`tsc` y `eslint` sin errores; las pruebas de Comercial pasan. En el navegador con `admin@erp-saas.test`, a 1440 px:

1. **CTR-2026-0003 (borrador):** botones Editar, Enviar a firma, Activar contrato, Descargar PDF y Adjuntar documento; "Enviar a firma" muestra el aviso; el historial dice "Desde COT-2026-0008".
2. **CTR-2026-0002 (suspendido):** Descargar PDF y Adjuntar documento arriba; Servicios contratados con "0 OT · 0 proy."; Relacionado con la fila Documentos; historial con "Desde COT-2026-0002".
3. **Instancias recurrentes:** como no hay ninguna real, se comprobó la tarjeta con una fila simulada en el navegador (no se guardó nada): aparece bajo Servicios contratados con sus cuatro columnas.

No se probó: que las instancias recurrentes sigan el estado del contrato (no hay ninguna en la base), los botones nuevos a 375 px, ni el candado de "Enviar a firma" con un rol sin permiso.

## Pendiente

- Firma electrónica y adjuntar documentos de verdad.
- Pantalla **Servicios contratados** (Operaciones): al activar un servicio recurrente debe crear su instancia recurrente.
