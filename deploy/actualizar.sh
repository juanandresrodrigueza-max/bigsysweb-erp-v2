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
  # PHP corre con el usuario del servidor web (www-data), no con root: si artisan
  # escribe la caché o las vistas como root, después www-data no las puede pisar.
  U=$(stat -c %U storage 2>/dev/null || echo www-data); [ "$U" = root ] && U=www-data
  id "$U" >/dev/null 2>&1 || U=root
  run() { if [ "$U" = root ]; then sh -c "$*"; else runuser -u "$U" -- sh -c "$*"; fi; }
  composer install --no-dev --optimize-autoloader --no-interaction
fi

echo "== Pantallas (frontend)"
if command -v npm >/dev/null; then npm ci --no-audit --no-fund && npm run build
else docker run --rm -v "$DIR":/app -w /app node:20-alpine sh -c "npm ci --no-audit --no-fund && npm run build"; fi

echo "== Base de datos y caché"
[ -z "$CONT" ] && [ "$U" != root ] && chown -R "$U":"$U" storage bootstrap/cache
run php artisan migrate --force
run php artisan optimize:clear
run php artisan optimize
run php artisan queue:restart || true
[ -n "$CONT" ] && docker restart $(docker ps --format '{{.Names}}' | grep -E 'queue|scheduler|horizon' ) 2>/dev/null || true
command -v supervisorctl >/dev/null && supervisorctl restart all >/dev/null 2>&1 || true
systemctl reload php*-fpm 2>/dev/null || true

echo "== Tareas programadas (parte de la mañana, avisos, tiendas)"
# Una sola vez y con el usuario de la web. Ojo con set -e: `crontab -l` falla si
# el usuario todavía no tiene ninguna, por eso va con `|| true`.
tiene() { crontab -u "$1" -l 2>/dev/null | grep -q "schedule:run"; }
if [ -n "$CONT" ]; then echo "   Corre en Docker (contenedor del cron)."
elif tiene "$U"; then
  echo "   Ya estaba (usuario $U)."
  if [ "$U" != root ] && tiene root; then
    echo "   OJO: también está en el cron de root, así corre dos veces. Sacá esa línea con: crontab -e"
  fi
elif tiene root; then echo "   Ya estaba (en el cron de root)."
else
  { crontab -u "$U" -l 2>/dev/null || true; echo "* * * * * cd $DIR && php artisan schedule:run >> /dev/null 2>&1"; } | crontab -u "$U" -
  echo "   Agregado el cron (usuario $U)."
fi

echo; echo "✓ Listo. ERP actualizado en $DIR a la versión $(git -C "$DIR" log -1 --format='%h %s' 2>/dev/null || echo 'de main')."
