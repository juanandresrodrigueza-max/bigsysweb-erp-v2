#!/usr/bin/env bash
# Actualizar el BigSysWeb que YA está instalado en el VPS (no instala nada nuevo, no toca el CRM).
# En la Consola web de Hostinger, como root:
#   curl -fsSL https://raw.githubusercontent.com/juanandresrodrigueza-max/bigsysweb-erp-v2/main/deploy/actualizar.sh | bash
set -euo pipefail
REPO=https://github.com/juanandresrodrigueza-max/bigsysweb-erp-v2.git

echo "== Buscando dónde está instalado el ERP…"
# Es el proyecto Laravel que tiene el catálogo de Bigcito (app/Mcp/Catalogo.php): así no se confunde con el CRM.
DIRS=$(find / -xdev \( -path /proc -o -path /var/lib/docker -o -path /var/lib/containerd -o -path '*/node_modules' \) -prune -o -name Catalogo.php -path '*/app/Mcp/*' -print 2>/dev/null | sed 's#/app/Mcp/Catalogo.php##' | grep -v '/vendor/' | sort -u)
[ -n "$DIRS" ] || { echo "No encontré el ERP en el disco. Mandale esta pantalla a Claude."; exit 1; }
if [ "$(echo "$DIRS" | wc -l)" -gt 1 ]; then echo "Encontré más de una copia:"; echo "$DIRS"; echo "Mandale esta pantalla a Claude."; exit 1; fi
DIR=$DIRS; cd "$DIR"; echo "   Está en: $DIR"

echo "== Bajando la última versión"
cp -a .env "/root/env-erp-$(date +%Y%m%d-%H%M).bak" 2>/dev/null || true   # copia de seguridad de la configuración
if [ -d .git ]; then
  git config --global --add safe.directory "$DIR"
  git remote set-url origin "$REPO" 2>/dev/null || git remote add origin "$REPO"
  git fetch -q origin main && git reset -q --hard origin/main   # el código queda igual a main; .env y storage/ no se tocan (no están en git)
else
  T=$(mktemp -d); git clone -q --depth 1 "$REPO" "$T"
  rsync -a --exclude .env --exclude storage --exclude .git "$T"/ "$DIR"/; rm -rf "$T"
fi

# ¿Corre en Docker o directo en el servidor?
CONT=$(docker ps --format '{{.Names}} {{.Mounts}}' 2>/dev/null | awk '{print $1}' | while read c; do docker inspect -f '{{range .Mounts}}{{.Source}} {{end}}' "$c" 2>/dev/null | grep -qw "$DIR" && docker exec "$c" test -f /var/www/html/artisan 2>/dev/null && docker exec "$c" which php >/dev/null 2>&1 && echo "$c" && break; done || true)
if [ -n "$CONT" ]; then
  echo "== Corre en Docker (contenedor $CONT)"
  run() { docker exec -u www-data "$CONT" sh -c "cd /var/www/html && $*"; }
  docker exec "$CONT" sh -c "cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction" 
else
  echo "== Corre directo en el servidor"
  run() { sh -c "$*"; }
  composer install --no-dev --optimize-autoloader --no-interaction
fi

echo "== Pantallas (frontend)"
if command -v npm >/dev/null; then npm ci --no-audit --no-fund && npm run build
else docker run --rm -v "$DIR":/app -w /app node:20-alpine sh -c "npm ci --no-audit --no-fund && npm run build"; fi

echo "== Base de datos y caché"
run php artisan migrate --force
run php artisan optimize:clear
run php artisan optimize
run php artisan queue:restart || true
[ -n "$CONT" ] && docker restart $(docker ps --format '{{.Names}}' | grep -E 'queue|scheduler|horizon' ) 2>/dev/null || true
command -v supervisorctl >/dev/null && supervisorctl restart all >/dev/null 2>&1 || true
systemctl reload php*-fpm 2>/dev/null || true

echo "== Tareas programadas (parte de la mañana, avisos, tiendas)"
if [ -z "$CONT" ] && ! crontab -l 2>/dev/null | grep -q "schedule:run"; then
  (crontab -l 2>/dev/null; echo "* * * * * cd $DIR && php artisan schedule:run >> /dev/null 2>&1") | crontab -
  echo "   Agregado el cron."
else echo "   Ya estaba (o corre en Docker)."; fi

echo; echo "✓ Listo. ERP actualizado en $DIR a la versión $(git -C "$DIR" log -1 --format='%h %s' 2>/dev/null || echo 'de main')."
