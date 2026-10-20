# 2026-10-20 — Despliegue en el VPS (servicios.intelectalab.com)

## Qué se pidió

Revisar por qué servicios.intelectalab.com no funcionaba en el VPS. Después: pasar los datos de la base local al servidor y conectar la carpeta del servidor a GitHub para actualizar con `git pull`.

## Qué se cambió

**Web**
- `apps/web/src/proxy.ts`: las dos redirecciones por dominio solo se aplican cuando hay dos dominios distintos (`SPLIT_DOMAINS`). Antes, con un solo dominio y sin `SITE_URL` ni `APP_URL`, `host`, `SITE_HOST` y `APP_HOST` valían `null`. Como `null === null` es verdadero, el código armaba `new URL(ruta, undefined)` y la web respondía 500 en `/`, `/login` y el resto. El arreglo se probó primero en el servidor; aquí queda igual.

**Servidor (fuera del repo)**
- Permisos: PHP-FPM (www-data) puede leer `vendor`, `bootstrap/cache` y `.env`.
- Base de datos: se copiaron los datos locales (solo los datos; el esquema ya era igual), con respaldo previo en `/root/backups/`.
- La carpeta `/var/www/servicios.intelectalab.com` quedó conectada a `origin` (este repo).

**Scripts del servidor** (`infrastructure/deploy/`, se corren como root en el VPS)
- `backup.sh [etiqueta]`: guarda en `/root/backups/erp/<fecha>_<etiqueta>/` la base (`pg_dump`), los `.env` de la API y la web, el sitio de nginx y los archivos subidos (`storage/app`), además del commit publicado. Conserva los últimos 14.
- `deploy.sh [--si]`:
  - Se detiene si hay archivos cambiados a mano en el servidor.
  - Muestra los commits nuevos y pide confirmación.
  - Respalda con `backup.sh` y trae el código (solo avance rápido).
  - API: corre `composer install` si cambió `composer.lock`. Muestra las migraciones pendientes y, si se confirman, las corre como `erp_user` (pide su contraseña; no se guarda). Después limpia las cachés y corrige los permisos.
  - Web: corre `npm ci` si cambió `package-lock.json`, compila y reinicia solo `servicios-web` en pm2. Si el build falla, deja el anterior.
  - Al final comprueba que `/login` responda 200 y la API 401.
- `.gitattributes`: los `.sh` se guardan con saltos de línea de Linux.

## Qué NO se tocó

Los demás sitios del VPS (crm, tecsur, demotecsur, cubo-proyecto, erp_servicios) ni su configuración de nginx.

## Base de datos

No hay migraciones nuevas.

## Cómo se probó

- `https://servicios.intelectalab.com/` y `/login` responden 200, y la API responde desde Laravel.
- Tras la copia, cada tabla tiene la misma cantidad de filas en local y en el VPS.
- En el servidor, `git status` no muestra archivos distintos de este commit.

## Pendiente

- Correo real: `MAIL_MAILER=log` deja los códigos de verificación en el log.
- Documentos: el disco `documents` apunta a MinIO, que no está en el VPS. Se decidió guardarlos en el disco del servidor; falta hacerlo y copiar los 8 archivos locales.
- Cuentas y empresas de prueba copiadas al servidor: desactivarlas o cambiarles la contraseña.
- `LOG_LEVEL=debug` en producción.
