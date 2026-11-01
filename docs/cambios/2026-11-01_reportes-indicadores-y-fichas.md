# Reportes: indicadores con comparación y clic que abre la ficha

Fecha: 2026-11-01.

## 1. Qué se pidió

Dos mejoras a Reportes:

- tres indicadores arriba de cada reporte, comparados con el periodo anterior;
- que el clic en una fila abra la ficha del registro.

## 2. Qué se cambió

**Indicadores** (los calcula la API dentro del alcance de quien mira; `ReportController::kpis*`):

| Reporte | Indicadores |
|---|---|
| Ventas | Total cotizado en soles (sin las canceladas), cantidad de cotizaciones y % de aprobación (aprobadas sobre las enviadas). **Los tres se comparan con el periodo anterior.** |
| Pipeline | Pipeline abierto (con la cantidad de oportunidades), ponderado por probabilidad y tasa de cierre (ganadas sobre las cerradas) |
| Cobranza | Saldo por cobrar, vencido (con la cantidad de clientes) y % vencido |
| Rentabilidad | Ingreso presupuestado, costo real y margen (con su %), sin los proyectos cancelados |
| SLA | % de cumplimiento (a tiempo o cumplidos sobre los que tienen SLA), vencidos y en riesgo, dentro del rango de fechas |
| Renovaciones | Vencen en 30 días (con el monto por ciclo), vencen en 90 días y vigencia pasada sin renovar |
| Productividad | Completadas y tiempo promedio de atención (del inicio al cierre), **comparados con el periodo anterior**, y abiertas hoy |

- **Periodo de comparación:**
  - Sin rango: lo que va del mes frente a los mismos días del mes anterior.
  - Con meses completos (por ejemplo, octubre): frente a los mismos meses justo antes (septiembre).
  - Con otro rango: frente a la misma cantidad de días justo antes.
  - Un rango sin "hasta" llega hasta hoy; un rango sin "desde" toma los 30 días previos.
- La pantalla muestra el periodo comparado ("Rango elegido: 1 oct. – 31 oct. 2026, comparado con 1 set. – 30 set. 2026") y en cada tarjeta el cambio con flecha: en % o, si la cifra ya es un porcentaje, en puntos.
  - El cambio sale **verde si mejora y rojo si empeora**. En el tiempo de atención, bajar es mejorar.
  - Al pasar el cursor se ve la cifra del periodo anterior.
  - Si las dos cifras son iguales, dice "igual que el periodo anterior".
- Al exportar no se calculan los indicadores.

**Clic en la fila:**

- Ventas abre la cotización; Pipeline, la oportunidad; Rentabilidad, el proyecto; SLA, el ticket; Renovaciones, el servicio recurrente; Productividad, la OT.
- Cobranza abre la ventana de la cuenta por cobrar, que es la misma de Cuentas por cobrar (`ReceivableModal`, ahora exportado).
- El reporte elegido queda en la dirección (`/reports?r=ventas`): al volver de una ficha se abre el mismo reporte.

## 3. Qué NO se tocó

Las fichas de cada módulo, los gráficos y la exportación.

## 4. Diferencias con el prototipo

Las dos son mejoras: el prototipo no tiene indicadores en Reportes ni abre fichas desde sus filas.

## 5. Base de datos

Sin cambios.

## 6. Cómo se probó

- **Prueba nueva en `ReportsTest`:**
  - En Ventas, los últimos 11 días frente a los 11 anteriores: total, cantidad y aprobación del periodo y del anterior, sin las canceladas.
  - Un mes completo se compara con el mes anterior; sin rango, con lo que va del mes.
  - Los 7 reportes traen sus 3 indicadores; al exportar, ninguno.
- **API completa: 312 pruebas pasan.** `tsc` y ESLint sin errores.
- **Navegador como Administrador** (1366 × 768 y 375 px):
  - Octubre frente a septiembre: S/ 13.9 k, +684 %; 15 cotizaciones; aprobación 60 %, −40 pts en rojo.
  - Los indicadores de los demás reportes salen con los datos de prueba.
  - Clic en una fila de cada uno de los 7 reportes abre su ficha (Cobranza, su ventana con la detracción y el saldo), y "atrás" vuelve al mismo reporte.
  - Sin desplazamiento lateral en el móvil.

## 7. Pendiente

Nada de esta mejora. Queda propuesto para otra vez el resumen semanal por correo.
