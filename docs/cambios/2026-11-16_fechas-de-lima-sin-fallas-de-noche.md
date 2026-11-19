# Fechas de Lima: validaciones, valores por defecto y pruebas que fallaban de noche

Fecha: 2026-11-16.

## 1. Qué se pidió

Corregir las 17 pruebas que fallaban entre las 19:00 y la medianoche de Lima. En ese tramo, la fecha UTC ya es la del día siguiente.

## 2. Qué se encontró

No eran solo las pruebas. Varios lugares del sistema todavía tomaban «hoy» en UTC, aunque las fechas de negocio salen del calendario de Lima desde el 2026-11-04. Por ejemplo, de noche:

- se aceptaba un tipo de cambio, un cobro o un gasto con la fecha de mañana;
- un contrato nuevo empezaba un día después;
- una cotización que vence hoy ya no se podía aprobar.

## 3. Qué se cambió

### En el sistema

- **Validaciones de fecha:** «no puede ser futura» y «debe ser posterior a hoy» comparan con la fecha de Lima. Aplica en:
  - tipo de cambio, cobros, constancia de detracción, Bancos y caja;
  - pagos y comprobantes de proveedor;
  - vencimiento de credenciales y avisos del portal.
- **Fechas por defecto con el calendario de Lima:**
  - inicio y fin del contrato;
  - validez de la cotización;
  - vencimiento del comprobante y fecha de la constancia de detracción;
  - cierre esperado en CRM y en las solicitudes del portal;
  - fecha requerida de la OC;
  - meses de Contabilidad (periodo actual, Libro diario, Mayor);
  - ejemplos de la plantilla de extracto bancario.
- **Cotización vencida:** se decide comparando su fecha de validez con la fecha de Lima.
- **Garantía:** cuenta desde el día, en Lima, en que se completó la última OT.
- **Filtros por columna:**
  - «Hoy», «Ayer», «Esta semana» y similares usan el calendario de Lima.
  - Las columnas con hora se filtran por su día en Lima: Auditoría, eventos por facturar, plantillas, actividades y campañas de CRM, leads, documentos, cobros, usuarios del portal, horas, garantías y órdenes de trabajo.

### En las pruebas

- Las fechas de negocio se arman con la fecha de Lima, igual que el sistema.
- `LocalTimeTest` sigue comprobando a propósito la diferencia entre UTC y Lima.

## 4. Pruebas

- 364 pruebas en verde, corridas a las 22:00 de Lima, el horario en que antes fallaban.

## 5. CI de la repo de la empresa (2026-11-19)

Tras publicar `eecebe1`, el CI salió en rojo por dos motivos ajenos a Personal:

- **Análisis de dependencias (`composer audit`):** hay avisos de seguridad nuevos (4 y 13 de noviembre) para Laravel y varias librerías de Symfony.
  - Se actualizaron solo las afectadas: Laravel 12.69.1 → 12.72.0; Symfony http-foundation, http-kernel y routing → 7.4.23; filesystem y yaml → 8.1.11.
  - No se tomaron versiones de los últimos días (Laravel 12.73 y Guzzle 7.16.3 tienen 2 días).
  - `composer audit` queda sin avisos y pasan las 364 pruebas.
- **E2E «App del técnico sin señal»:** la prueba cortaba la red antes de que el teléfono terminara de guardar la pantalla y la ruta (pasa un instante después de cargarlas). Ahora espera a que ambas estén guardadas.
  - La app sí funciona sin señal: se comprobó a mano contra la compilación de producción.
  - Las 4 E2E pasan tres veces seguidas contra la compilación de producción con Next.js 16.4.6.
