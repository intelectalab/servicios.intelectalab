# 2026-10-21 — Actualización de PHP en el VPS por PHP2Shell

## Qué se pidió

Salió la noticia de PHP2Shell: dos fallas críticas en PHP (CVE-2026-109618 y CVE-2026-109619, 9.2/10) que permiten ejecutar código sin contraseña. Ella pidió:
- actualizar PHP 8.3 y 8.4 en el VPS (con autorización de su equipo, porque el servidor es compartido);
- documentar cada cambio para poder volver atrás;
- comprobar que todo siga funcionando.

## Qué se cambió (solo en el servidor; no hay cambios de código)

| Versión | Antes | Ahora | Sitios |
| --- | --- | --- | --- |
| PHP 8.3 | 8.3.31 | 8.3.36 | crm, tecsur, demotecsur |
| PHP 8.4 | 8.4.26 | 8.4.27 | este ERP (servicios.intelectalab.com), cubo-proyecto, erp_servicios (commpe), gestion_proyectos |

- Se actualizaron 26 paquetes `php8.3-*` y `php8.4-*`, conservando la configuración (`/etc/php` quedó idéntica).
- La instalación la terminó otra persona del equipo entre las 23:47 y las 23:55. Antes, desde aquí se guardaron el estado, la configuración y los paquetes, y se descargaron los nuevos. El repositorio de PHP estaba saturado (503).
- Se instaló `dpkg-repack` para guardar una copia de los paquetes anteriores: ya no están en el repositorio.

## Respaldo y cómo volver atrás

Todo está en `/root/backups/php_update_20261021/` del VPS, con un `LEEME.txt` que lo explica:
- el estado de los sitios antes y después;
- las versiones exactas de cada paquete;
- la copia de `/etc/php`;
- los 32 paquetes anteriores (`debs_antes/`) y los 26 nuevos.

Para volver atrás (solo si algo falla, porque PHP vuelve a quedar vulnerable):

```
cd /root/backups/php_update_20261021
apt-get install -y --allow-downgrades -o Dpkg::Options::=--force-confold ./debs_antes/*.deb
bash estado.sh
```

## Qué NO se tocó

Ni el código, ni las bases de datos, ni nginx, ni la configuración de PHP, ni los demás paquetes del sistema (hay otras 56 actualizaciones pendientes que no son de PHP).

## Cómo se probó

- Los 7 sitios responden igual que antes, en la raíz y en sus rutas que pasan por PHP: crm 200/404, cubo 200/403, demotecsur 200/404, commpe 302/404, control 302/404, este ERP 200/404 y tecsur 200/404.
- PHP-FPM 8.3 y 8.4 están activos, reiniciados a las 23:55:16 con la versión nueva y con los mismos módulos (55 y 56).
- No hay errores nuevos en los logs de PHP-FPM ni de nginx.
- En este ERP: `/login` 200, `/api/v1/me` 401 y `/api/v1/saas-plans` 200, y Laravel informa PHP 8.4.27.

## Después: PHP de la PC (XAMPP)

XAMPP oficial no trae PHP 8.4: su última versión sigue con 8.2.12. En esta PC se había cambiado a mano a 8.4.24.

- **Instalación:** se descargó el PHP oficial `php-8.4.27-Win32-vs17-x64.zip` (Thread Safe, x64, VS17, igual que el anterior) de `downloads.php.net`. Su SHA-256 coincide con el publicado (`7902fe89…4e6b`).
- **Cambio de carpeta:** se detuvo el `php artisan serve` que corría en la terminal de VS Code; Apache estaba apagado. `C:\xampp\php` se renombró a `C:\xampp\php_8.4.24`, el zip se descomprimió en `C:\xampp\php` y se copió el `php.ini` anterior.
- **Para volver atrás:** borrar `C:\xampp\php` y renombrar `C:\xampp\php_8.4.24` a `php`.
- **Comprobado:**
  - `php -v` da 8.4.27, con los mismos 44 módulos y sin avisos al arrancar;
  - `httpd -t` responde "Syntax OK", cargando `php8apache2_4.dll` nuevo;
  - `php artisan test` pasa las 237 pruebas;
  - con `artisan serve`, la API respondió 401 en `/api/v1/me` y 200 en `/api/v1/saas-plans`, y la web 200 en `/login`.
- **Pruebas:** dos de ellas fallaban porque leían `ADMIN_MFA_REQUIRED=false` del `.env` local. `phpunit.xml` ahora fija `ADMIN_MFA_REQUIRED=true` para las pruebas, que así no dependen de ese ajuste.

## Pendiente

Volver a encender `php artisan serve` en la terminal de VS Code.
