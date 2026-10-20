#!/usr/bin/env bash
# Respaldo de lo importante del ERP en el servidor: la base de datos, los .env,
# la configuración de nginx del sitio y los archivos subidos (storage/app).
#
# Uso (como root):  bash infrastructure/deploy/backup.sh [etiqueta]
# Cada respaldo queda en su propia carpeta dentro de /root/backups/erp y se
# conservan solo los últimos 14 (BACKUP_KEEP para cambiarlo).
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEST_ROOT="${BACKUP_DIR:-/root/backups/erp}"
KEEP="${BACKUP_KEEP:-14}"
DB_NAME="${DB_NAME:-erp_saas}"
SITE_NAME="${SITE_NAME:-$(basename "$APP_DIR")}"
LABEL="${1:-manual}"
DEST="$DEST_ROOT/$(date +%Y%m%d_%H%M%S)_${LABEL}"

if [ "$(id -u)" -ne 0 ]; then echo "Ejecútalo como root." >&2; exit 1; fi
case "$DEST_ROOT" in /|"") echo "BACKUP_DIR no válido." >&2; exit 1 ;; esac

umask 077
mkdir -p "$DEST"
echo "→ Respaldo en $DEST"

# 1. Base de datos (formato propio de pg_dump; se restaura con pg_restore).
sudo -u postgres pg_dump -Fc "$DB_NAME" > "$DEST/base_${DB_NAME}.dump"
pg_restore -l "$DEST/base_${DB_NAME}.dump" > /dev/null
echo "  base de datos  $(du -h "$DEST/base_${DB_NAME}.dump" | cut -f1)"

# 2. Configuración: .env de la API y de la web, y el sitio de nginx.
config=()
for f in apps/api/.env apps/web/.env apps/web/.env.local apps/web/.env.production; do
  [ -f "$APP_DIR/$f" ] && config+=("${APP_DIR#/}/$f")
done
[ -f "/etc/nginx/sites-available/$SITE_NAME" ] && config+=("etc/nginx/sites-available/$SITE_NAME")
tar -czf "$DEST/configuracion.tar.gz" -C / "${config[@]}"
echo "  configuración  ${#config[@]} archivos"

# 3. Archivos subidos por los usuarios.
if [ -d "$APP_DIR/apps/api/storage/app" ]; then
  tar -czf "$DEST/archivos_subidos.tar.gz" -C "$APP_DIR/apps/api/storage" app
  echo "  archivos       $(du -h "$DEST/archivos_subidos.tar.gz" | cut -f1)"
fi

# 4. Versión del código que estaba publicada.
git -C "$APP_DIR" rev-parse HEAD > "$DEST/commit.txt" 2>/dev/null || true

# 5. Borra los respaldos más antiguos (solo carpetas de este script).
mapfile -t old < <(find "$DEST_ROOT" -mindepth 1 -maxdepth 1 -type d -name '20[0-9][0-9][0-9][0-9][0-9][0-9]_*' | sort | head -n -"$KEEP")
for d in "${old[@]}"; do rm -rf -- "$d"; done
[ "${#old[@]}" -gt 0 ] && echo "  borrados ${#old[@]} respaldos antiguos (se conservan $KEEP)"

echo "✓ Respaldo listo ($(du -sh "$DEST" | cut -f1))"
