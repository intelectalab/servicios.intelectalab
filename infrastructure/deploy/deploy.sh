#!/usr/bin/env bash
# Actualiza el ERP en el servidor con lo último de GitHub (rama main).
#
# Uso (como root, desde cualquier carpeta):
#   bash /var/www/servicios.intelectalab.com/infrastructure/deploy/deploy.sh
#
# Qué hace, en orden:
#   1. Se detiene si hay archivos cambiados a mano en el servidor.
#   2. Muestra los commits nuevos y pide confirmación (--si para no preguntar).
#   3. Hace un respaldo con backup.sh (base de datos, .env, nginx, archivos).
#   4. Trae el código (git, solo avance rápido).
#   5. API: composer si cambió composer.lock, migraciones pendientes (las muestra
#      y pide la contraseña del dueño de la base, erp_user), limpia cachés y
#      corrige permisos.
#   6. Web: npm ci si cambió package-lock.json, build y reinicio de su proceso
#      de pm2. Si el build falla, vuelve a dejar el anterior y no reinicia.
#   7. Comprueba que la web y la API respondan y anota el commit como desplegado.
# Si se detiene a medias, volver a correrlo retoma lo que faltó (dependencias,
# migraciones, compilación de la web) desde el último despliegue completo.
# Solo toca esta carpeta y su proceso de pm2; los demás sitios del servidor no.
set -euo pipefail

# Todo va entre llaves: bash lee el script completo antes de empezar, así que
# si el despliegue trae una versión nueva de este archivo no se mezclan.
{
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
API="$APP_DIR/apps/api"
WEB="$APP_DIR/apps/web"
BRANCH="${BRANCH:-main}"
PM2_NAME="${PM2_NAME:-servicios-web}"
SITE_URL="${DEPLOY_URL:-https://$(basename "$APP_DIR")}"
MIGRATE_USER="${MIGRATE_USER:-erp_user}"
NEXT_PREV="/var/tmp/erp-next-anterior"
# Último commit desplegado de punta a punta (dentro de .git: nunca se sube).
# Lo que cambió se mide desde ahí, no desde el commit que había en la carpeta:
# si un despliegue falla después de traer el código, el siguiente retoma
# dependencias, migraciones y la compilación de la web que quedaron sin hacer.
OK_FILE="$APP_DIR/.git/erp-deploy-ok"
YES=0
[ "${1:-}" = "--si" ] && YES=1

paso() { echo; echo "── $*"; }
falla() { echo; echo "✗ $*" >&2; exit 1; }
confirmar() {
  [ "$YES" -eq 1 ] && return 0
  read -r -p "$1 [s/N] " r
  [[ "$r" =~ ^[sS]$ ]]
}

[ "$(id -u)" -eq 0 ] || falla "Ejecútalo como root."

# Un solo despliegue a la vez.
exec 9>/var/lock/erp-deploy.lock
flock -n 9 || falla "Ya hay un despliegue en curso."

cd "$APP_DIR"

paso "1. Revisando cambios hechos a mano en el servidor"
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  git status --short --untracked-files=no
  falla "Hay archivos cambiados en el servidor. Súbelos a GitHub desde tu PC o descártalos antes de desplegar."
fi
echo "Ninguno."

paso "2. Buscando novedades en GitHub ($BRANCH)"
git fetch -q origin "$BRANCH"
OLD="$(git rev-parse HEAD)"
NEW="$(git rev-parse "origin/$BRANCH")"
git merge-base --is-ancestor "$OLD" "$NEW" || falla "La versión del servidor no es anterior a la de GitHub (¿historial reescrito?). Revísalo a mano."
BASE="$OLD"
# Primera vez con esta marca: lo que hay en la carpeta es el último despliegue completo.
[ -s "$OK_FILE" ] || echo "$OLD" > "$OK_FILE"
if [ -s "$OK_FILE" ]; then
  last_ok="$(cat "$OK_FILE")"
  if git cat-file -e "$last_ok^{commit}" 2>/dev/null && git merge-base --is-ancestor "$last_ok" "$OLD"; then
    BASE="$last_ok"
  fi
fi
if [ "$BASE" = "$NEW" ]; then
  echo "Ya está al día (${NEW:0:7}). No hay nada que desplegar."
  exit 0
fi
if [ "$BASE" != "$OLD" ]; then
  echo "El último despliegue (${OLD:0:7}) no terminó; se retoma desde ${BASE:0:7}, el último completo."
fi
git log --oneline "$BASE..$NEW"
confirmar "¿Desplegar estos cambios?" || falla "Cancelado. No se cambió nada."

paso "3. Respaldo antes de actualizar"
APP_DIR="$APP_DIR" bash "$SCRIPT_DIR/backup.sh" "antes_deploy_${OLD:0:7}"

trap 'echo; echo "✗ El despliegue se detuvo con un error. Al corregirlo, vuelve a correr este script: retoma lo que faltó desde ${BASE:0:7}. Para volver atrás: cd $APP_DIR && git reset --hard ${BASE:0:7} y correr el script de nuevo; el respaldo está en /root/backups/erp." >&2' ERR

paso "4. Trayendo el código"
git merge --ff-only -q "origin/$BRANCH"
CHANGED="$(git diff --name-only "$BASE" "$NEW")"
cambio() { grep -qE "$1" <<<"$CHANGED"; }
echo "$(wc -l <<<"$CHANGED") archivos cambiados. Ahora en ${NEW:0:7}."

artisan() { (cd "$API" && sudo -u www-data php artisan "$@"); }

paso "5. API"
if cambio '^apps/api/composer\.(json|lock)$'; then
  echo "Instalando dependencias de PHP…"
  (cd "$API" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress)
fi

# Permisos: PHP-FPM corre como www-data.
chown -R www-data:www-data "$API/storage" "$API/bootstrap/cache"
chmod -R a+rX "$API/vendor"
chown root:www-data "$API/.env" && chmod 640 "$API/.env"

# Limpia solo el código compilado (configuración, rutas, vistas y eventos) sin vaciar
# la memoria de caché (Redis), para no borrar invitaciones ni códigos activos.
artisan config:clear > /dev/null
artisan route:clear > /dev/null
artisan view:clear > /dev/null
artisan event:clear > /dev/null
pendientes="$(artisan migrate:status --pending --no-ansi || true)"
if grep -q "Pending" <<<"$pendientes"; then
  echo "$pendientes"
  if confirmar "¿Correr estas migraciones?"; then
    if [ -z "${DB_MIGRATE_PASSWORD:-}" ]; then
      read -r -s -p "Contraseña de $MIGRATE_USER (dueño de las tablas): " DB_MIGRATE_PASSWORD; echo
    fi
    (cd "$API" && DB_USERNAME="$MIGRATE_USER" DB_PASSWORD="$DB_MIGRATE_PASSWORD" \
      sudo -u www-data --preserve-env=DB_USERNAME,DB_PASSWORD php artisan migrate --force)
    unset DB_MIGRATE_PASSWORD
  else
    echo "⚠ Migraciones sin correr: la API puede fallar hasta que se corran."
  fi
else
  echo "Sin migraciones pendientes."
fi

paso "6. Web"
if cambio '^apps/web/'; then
  cd "$WEB"
  if cambio '^apps/web/package(-lock)?\.json$'; then
    echo "Instalando dependencias de Node…"
    npm ci --no-audit --no-fund
  fi
  rm -rf "$NEXT_PREV"
  [ -d .next ] && cp -a .next "$NEXT_PREV"
  echo "Compilando (tarda unos minutos)…"
  if ! npm run build; then
    if [ -d "$NEXT_PREV" ]; then rm -rf .next && mv "$NEXT_PREV" .next; fi
    falla "El build de la web falló. Se dejó el anterior y la web sigue con la versión vieja (la API ya está en ${NEW:0:7}). Al corregirlo, vuelve a correr este script."
  fi
  pm2 restart "$PM2_NAME" --update-env > /dev/null
  rm -rf "$NEXT_PREV"
  cd "$APP_DIR"
  echo "Web compilada y reiniciada."
else
  echo "Sin cambios en la web."
fi

paso "7. Comprobando"
sleep 3
web="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL/login" || true)"
api="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -H 'Accept: application/json' "$SITE_URL/api/v1/me" || true)"
echo "Web /login → $web (esperado 200)"
echo "API /api/v1/me → $api (esperado 401: responde y pide sesión)"
trap - ERR
if [ "$web" = "200" ] && [ "$api" = "401" ]; then
  echo "$NEW" > "$OK_FILE"
  echo; echo "✓ Desplegado ${NEW:0:7}."
else
  falla "El sitio no respondió como se esperaba. Revisa: tail -n 50 /var/log/nginx/error.log y pm2 logs $PM2_NAME --lines 50"
fi
exit 0
}
