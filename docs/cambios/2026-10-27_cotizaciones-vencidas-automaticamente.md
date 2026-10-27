# Cotizaciones enviadas que pasan a Vencida solas

Fecha: 2026-10-27.

## 1. Qué se pidió

La tarea diaria del kit (RN-COM-05): una cotización **Enviada** cuya fecha "válida hasta" ya pasó debe quedar **Vencida** sin que nadie pulse "Marcar vencida". Hasta hoy seguía como Enviada (no se podía aprobar, pero contaba como cotización en curso en el listado y en el panel).

## 2. Qué se cambió

**API**
- `app/Console/Commands/ExpireQuotations.php` (nuevo): orden `php artisan quotations:expire`. Recorre las empresas activas (entra al contexto de cada una por la RLS, igual que `recurring:run-cycles`) y pasa a `EXPIRED` las cotizaciones `SUBMITTED` con `valid_until` anterior a hoy. La cotización vale **hasta el día indicado inclusive**: vence desde el día siguiente, igual que la regla de aprobar. Cada cambio deja en auditoría `quotation.expire` (de Enviada a Vencida), sin usuario y con la nota "Vencida automáticamente". Correrla dos veces no repite nada.
- `routes/console.php`: programada cada día a las **00:10 hora de Lima** (05:10 UTC). El sistema trabaja en UTC; a esa hora la fecha UTC ya es la misma de Perú, así que no vence una cotización que aún vale ese día.

**Web**
- Nada. La ficha ya mostraba el historial sin usuario como "Sistema": queda "Vencida · desde Enviada · Sistema · fecha · Vencida automáticamente".

## 3. Qué NO se tocó

- El botón "Marcar vencida" y el bloqueo al aprobar una vencida siguen igual.
- Las cotizaciones sin fecha de validez no vencen nunca.
- Borradores, aprobadas, rechazadas y canceladas no cambian.
- No se envía aviso a quien hizo la cotización (los avisos de `Notifier` necesitan una petición de una persona; desde una tarea programada habría que adaptarlo).

## 4. Diferencias con el prototipo

El prototipo no tiene tareas programadas; la regla viene del kit.

## 5. Base de datos

Sin migraciones.

## 6. Cómo se probó

- `php artisan test`: nueva `ComercialTest › the daily command expires submitted quotations past their validity` (vence la de ayer; no toca la que vale hasta hoy ni un borrador vencido; deja la nota en el historial; correrla otra vez no duplica). **286 pruebas pasan.**
- Base local: antes de correrla, la única Enviada con fecha pasada era **COT-2026-0009** (Tenant A, válida hasta el 22 oct.); COT-2026-0013 no tiene fecha. `quotations:expire` → "tenant-a: 1 vencidas"; la segunda vez, nada. `schedule:list` muestra `10 5 * * *`.
- Navegador, cuenta Ventas: COT-2026-0009 en Vencida, sin transiciones, con la línea de "Sistema" en el historial.

## 7. Cron del VPS (preparado, sin activar)

Hasta hoy el VPS no ejecutaba ninguna tarea programada del ERP (ni esta, ni `recurring:run-cycles`, ni la limpieza de sesiones).

- **Qué se preparó:** `/root/backups/erp_cron_20261027/` con el archivo `servicios-erp` listo, el estado de antes (`crontab_root_antes.txt`, `cron.d_antes.txt`, `huellas_antes.txt`) y `LEEME.txt` (activar, comprobar y volver atrás). Línea: cada minuto, como `www-data` y con `/usr/bin/php8.4`, `artisan schedule:run` en `apps/api`.
- **A quién afecta:** solo al ERP. Es un archivo propio en `/etc/cron.d/`; el crontab de root (donde están Tecsur y DemoTecsur) no se toca.
- **Medido antes (05:46 UTC):** carga 0.09, 6,163 MB disponibles, cron activo, sitio 200. La línea corrida una vez a mano como `www-data`: "No scheduled commands are ready to run", y las huellas del cron no cambian.
- **Qué cambiará la primera vez:** EMP-52F1DB "Empresa" tiene un ciclo mensual pendiente desde el 24/10 (S/ 130) → un evento por facturar a las 05:00 UTC. En el VPS ninguna cotización enviada está vencida.
- **Activar (después de desplegar este cambio):** `install -o root -g root -m 644 /root/backups/erp_cron_20261027/servicios-erp /etc/cron.d/servicios-erp`. **Volver atrás:** `rm /etc/cron.d/servicios-erp`.

## 8. Pendiente

- Activar el cron en el VPS cuando esté desplegado.
- Aviso al responsable cuando su cotización vence (opcional).
