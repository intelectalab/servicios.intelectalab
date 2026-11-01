# Reportes en PDF con la marca de la empresa

Fecha: 2026-11-01.

## 1. Qué se pidió

Poder descargar cada reporte en PDF con la marca de la empresa, para enviarlo a un cliente, a un socio o a la gerencia.

## 2. Qué se cambió

- **Botón "PDF"** al pie de cada reporte, junto a "Exportar a Excel" y "CSV". Pide el mismo permiso (`reports.export`); sin él sale con candado. Queda en Auditoría y en "Mis exportaciones" con el formato "PDF".
- **El PDF** (`lib/report-pdf.ts`, hoja A4 apaisada, se arma en el navegador) lleva:
  - **Cabecera:** la franja de los colores de la marca, el logo (o las iniciales) con la razón social y el RUC; a la derecha "REPORTE", el nombre del reporte, el periodo con su comparación, los filtros aplicados (rango, filtros por columna, orden) y cuántas filas son.
  - **Los 3 indicadores**, con el cambio frente al periodo anterior en verde o en rojo, y su nota.
  - **En lugar del gráfico, un resumen en tabla:** el total por mes (Ventas), el saldo por tramo (Cobranza), los tickets por estado del SLA (SLA) o las órdenes por estado (Productividad).
  - **La tabla con todas las filas filtradas.** Los montos van con su moneda (S/ o US$) y los porcentajes con "%". Las columnas numéricas van a la derecha, cabecera incluida, y la cabecera se repite en cada página.
  - **Pie en cada página:** empresa y RUC, teléfono, correo y web de la marca, "Generado el … por …" y el número de página.
- `document-pdf.ts` solo exporta ahora su función `plain` (cambia los signos que la fuente del PDF no trae). El PDF de cotizaciones y contratos no cambió.
- La API acepta el formato `pdf` al registrar una exportación (`ListExport::record`).

## 3. Qué NO se tocó

- El PDF de cotizaciones, contratos y Libro diario.
- La exportación a Excel y CSV.

## 4. Diferencias con el prototipo

Es una mejora: el prototipo solo exporta CSV.

## 5. Base de datos

Sin cambios.

## 6. Cómo se probó

- `ReportsTest`: una exportación en PDF queda en "Mis exportaciones" con su formato, y un formato que no existe se rechaza. Las 5 pruebas pasan.
- `tsc` y ESLint sin errores.
- **Navegador como Administrador:** se generaron PDF reales y se revisaron página por página.
  - Cobranza: indicadores, resumen por tramo y 4 cuentas.
  - Ventas de octubre frente a septiembre: +684 % y +1400 % en verde, −40 pts en rojo; 16 filas en 2 páginas con la cabecera repetida; montos en S/ y US$.
  - Rentabilidad: porcentajes con "%".
  - Las tres exportaciones aparecieron en "Mis exportaciones".
- Se corrigieron dos detalles que salieron en la prueba:
  - Decía "Con filtros por columna" sin haber ninguno.
  - Las cabeceras de las columnas numéricas no iban a la derecha.

## 7. Pendiente

- El PDF no lleva el gráfico, solo su resumen en tabla.
- La barra de "filas marcadas" exporta a Excel y CSV, no a PDF.
