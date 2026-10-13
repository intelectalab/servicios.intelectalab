# 2026-10-12 — Las pantallas del CRM tardaban en cargar

## Qué se pidió

"Cuando entro a cualquier módulo de CRM pasa lo mismo que pasaba en Comercial: se demora en cargar."

## Por qué tardaba

La API responde cada llamada en unos 0,33 s; lo lento era **cuántas llamadas hacía cada pantalla y en qué orden**, porque el servidor local las atiende de una en una:

- Cada listado del CRM pide tres cosas: los indicadores, las listas de los filtros y el listado. **El listado salía el último**, detrás de las otras dos.
- En desarrollo, React monta cada pantalla dos veces, así que **todo salía duplicado**: seis llamadas en fila, y la tabla esperaba a la sexta.

Medido en Leads: 6 llamadas, la tabla aparecía a los **2,0 s**. Cotizaciones, con dos cosas que pedir, tardaba 1,5 s.

## Qué se cambió

| Archivo | Cambio |
| --- | --- |
| `web/src/lib/api.ts` | **Una lectura repetida no se envía dos veces:** si la misma consulta se pide otra vez mientras la primera sigue en curso, las dos reciben la misma respuesta. Solo se comparte lo que está en curso; una lectura ya terminada se vuelve a pedir, y cualquier modificación (guardar, cambiar de estado) corta lo compartido para que nadie reciba un dato anterior al cambio. Además, `apiFetchLater`: una lectura secundaria que sale después de la principal. |
| `LeadsList`, `PipelineList`, `ActivitiesList`, `CampaignsList` (CRM) | Primero el listado; los indicadores y las listas de los filtros, después, y cada uno aparece cuando llega (antes los indicadores esperaban a los filtros). En el Pipeline van primero las etapas, porque el tablero las necesita para dibujar sus columnas. |
| `LeadDetail`, `OpportunityDetail`, `Activities` (tarjeta de las fichas) | Primero la ficha; las opciones del formulario de edición y las actividades, después. |
| `AuditList`, `QuotationsList`, `ExchangeRates` | Lo mismo: el listado primero. |

Sin cambios en la API ni en la base.

## Resultado (medido en el navegador, como Ventas, al entrar desde el menú)

| Pantalla | Antes | Ahora |
| --- | --- | --- |
| Leads | 6 llamadas, tabla a los 2,0 s | 3 llamadas, tabla a los **0,4 s** |
| Pipeline (tablero) | 6 llamadas | 3 llamadas, tablero a los **0,7 s** |
| Actividades | 4 llamadas | 2 llamadas, tabla a los **0,4 s** |
| Campañas | 4 llamadas | 2 llamadas, tabla a los **0,4 s** |
| Puntuación | 2 llamadas | 1 llamada, **0,6 s** |
| Ficha de un lead | 6 llamadas | 3 llamadas, ficha a los **0,4 s** |
| Cotizaciones | 4 llamadas, 1,5 s | 2 llamadas, **0,5 s** |

Los indicadores terminan de llenarse entre 0,3 y 0,6 s después de la tabla. Al recargar la página entera (F5) salen 4 llamadas en lugar de 8.

## Qué NO se tocó

- Qué pide cada pantalla: son los mismos datos, en otro orden y sin duplicados.
- El control de ediciones simultáneas y el de envíos repetidos del mismo archivo.

## A tener en cuenta

- **La primera vez que se abre una pantalla después de arrancar la web en desarrollo tarda más** (alrededor de 1 s): Next.js compila esa pantalla en ese momento. Publicado no ocurre.
- **Publicado, el efecto es menor:** un servidor real atiende varias llamadas a la vez y React no monta dos veces. El orden y el no duplicar siguen sirviendo.

## Propuesta (no se hizo: es la configuración de PHP de tu máquina)

Cada llamada tarda 0,33 s porque el PHP de XAMPP tiene **apagada la caché de código** (OPcache): en cada petición vuelve a leer y compilar todo Laravel. Activarla bajaría todas las llamadas de todo el sistema, no solo las del CRM. En `C:\xampp\php\php.ini`:

1. Quitar el `;` de `;zend_extension=opcache` (línea 964).
2. En la sección `[opcache]`, dejar `opcache.enable=1` (línea 1802, sin el `;`).
3. Reiniciar `php artisan serve`.

No lo cambié porque es un archivo de tu instalación, fuera del repositorio. Si quieres, lo hago y mido antes y después.

## Cómo se probó

`tsc` y `eslint` sin errores. La API no cambió (sus 131 pruebas no se volvieron a correr).

En el navegador (`app.localhost:3000`), como **Ventas**:

- Tiempos y número de llamadas de la tabla de arriba, medidos con el registro de red del navegador entrando a cada pantalla dos veces (la segunda, ya compilada).
- **Que una modificación siga refrescando la pantalla:** en Actividades se marcó una actividad como realizada y se reabrió; los indicadores y la fila cambiaron las dos veces.
- Las fichas siguen mostrando sus tarjetas (Datos, Ciclo, Actividades) y los listados sus indicadores.
- Sin errores en la consola.

### Lo que no se probó

- **Las demás cuentas y pantallas una por una** (Auditoría, Tipo de cambio, ficha de oportunidad): llevan el mismo cambio de una línea; se midieron Leads, Pipeline, Actividades, Campañas, Puntuación, la ficha del lead y Cotizaciones.
- **Publicado** (servidor real): no hay entorno; la medición es local.
