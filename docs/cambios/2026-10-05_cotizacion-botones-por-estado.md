# 2026-10-05 — Cotización: botones por estado, revisados contra el prototipo

Continúa [2026-10-05_orden-directa.md](2026-10-05_orden-directa.md).

## Qué se pidió

Revisar cada estado de la cotización en el prototipo y comprobar que arriba aparecen todos los botones que corresponden a cada uno.

## Resultado de la revisión

Botones de la cabecera de la ficha (arriba), en el orden del prototipo:

| Estado | Prototipo | App |
| --- | --- | --- |
| Borrador | Editar · Descargar PDF (y "Autorizar nivel N" / "Observar" si hay autorización interna pendiente) | Editar · Descargar PDF |
| Enviada | Aprobar · Rechazar · Nueva versión · Descargar PDF | igual |
| Aprobada | Convertir en contrato · Orden directa · Nueva versión · Descargar PDF | igual |
| Rechazada | Nueva versión · Descargar PDF | igual |
| Vencida | Nueva versión · Descargar PDF | igual |
| Cancelada | Descargar PDF | igual |

Condiciones, iguales en los dos: "Nueva versión" no aparece si la cotización ya fue reemplazada por otra versión o ya tiene contrato; "Orden directa" solo si la empresa la permite; "Convertir en contrato" y "Orden directa" quedan deshabilitados con su motivo si ya se hizo la conversión; "Aprobar" queda deshabilitado si la cotización es tuya (segregación) o está vencida.

**Lo único que falta arriba:** "Autorizar nivel N" y "Observar" en borrador. Son de la autorización interna por niveles (por monto o descuento), que depende de la pantalla "Reglas de aprobación", aún sin construir.

Botones de la tarjeta "Estados" (transiciones):

| Estado | Prototipo | App antes | App ahora |
| --- | --- | --- | --- |
| Borrador | Enviar a aprobación · Cancelar | igual | igual |
| Enviada | Aprobar · Rechazar · Marcar vencida · Cancelar | Marcar vencida · Cancelar | igual al prototipo |
| Resto | sin transiciones | igual | igual |

## Qué se cambió

| Archivo | Cambio |
| --- | --- |
| Web `components/dashboard/quotations/QuotationDetail.tsx` | En la tarjeta "Estados", una cotización enviada muestra también Aprobar y Rechazar (además de arriba), como el prototipo. "Marcar vencida" pide ahora `quotations.approve`. |
| API `routes/api.php` | "Marcar vencida" pasa del permiso `quotations.manage` a `quotations.approve`: en el prototipo es una decisión de quien aprueba. |
| API `tests/Feature/ComercialTest.php` | Sin `quotations.approve` tampoco se puede marcar vencida. |

**Lógica que cambió:** quien solo cotiza (rol Ventas) ya no puede marcar vencida una cotización enviada; lo hace quien aprueba.

## Qué NO se tocó

El resto de la ficha, la base de datos y los demás módulos.

## Cómo se probó

`tsc` y `eslint` sin errores; las 16 pruebas de Comercial pasan. En el navegador como `admin@erp-saas.test`, a 1440 px, se abrió la ficha en cada uno de los seis estados y se listaron los botones de la cabecera y de "Estados": coinciden con las tablas de arriba.

Para ver los seis estados sin alterar datos, el estado se simuló en el navegador sobre una cotización real (sin contrato, sin orden, sin versión posterior y vigente). Los casos con condiciones (ya convertida, reemplazada, propia, vencida) se habían visto con datos reales en las tandas anteriores.

## Pendiente

- "Autorizar nivel N" y "Observar", con la pantalla "Reglas de aprobación".
