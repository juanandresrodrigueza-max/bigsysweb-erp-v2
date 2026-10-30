#!/usr/bin/env bash
# SÓLO para un servidor NUEVO. Si el ERP ya está instalado, usá deploy/actualizar.sh.
# Preparar el VPS para BigSysWeb. Se corre UNA sola vez, como root, desde la "Consola web" de Hostinger:
#   curl -fsSL https://raw.githubusercontent.com/juanandresrodrigueza-max/bigsysweb-erp-v2/main/deploy/primera-vez.sh | bash
# (si el repo es privado, pegá el contenido de este archivo en la consola).
# No toca lo que ya corre en el servidor: usa su propia carpeta, sus propios contenedores y un puerto libre.
set -euo pipefail
DIR=/opt/bigsysweb
[ "$(id -u)" = 0 ] || { echo "Corrélo como root."; exit 1; }

echo "== 1. Docker"
if ! command -v docker >/dev/null; then curl -fsSL https://get.docker.com | sh; fi
docker compose version >/dev/null || { echo "Falta 'docker compose'. Instalalo desde el Docker manager de Hostinger y volvé a correr esto."; exit 1; }
command -v rsync >/dev/null || apt-get install -y rsync

echo "== 2. Carpeta $DIR"
mkdir -p "$DIR"/storage/{app/public,framework/{cache,sessions,views},logs} "$DIR"/bootstrap/cache

echo "== 3. Puerto"
libre() { ! ss -ltn "( sport = :$1 )" | grep -q LISTEN; }
PUERTO=""; for p in $(seq 8080 8099); do if libre "$p"; then PUERTO=$p; break; fi; done
[ -n "$PUERTO" ] || { echo "No hay puertos libres entre 8080 y 8099."; exit 1; }
echo "   Usa el $PUERTO. Lo que ya escucha en el 80/443 (no se toca):"; ss -ltnp | grep -E ':(80|443) ' || echo "   (nada)"

IP=$(curl -fsS https://api.ipify.org || hostname -I | awk '{print $1}')
if [ ! -f "$DIR/.env" ]; then
  echo "== 4. Configuración (.env)"
  cat > "$DIR/.env" <<ENV
APP_NAME=BigSysWeb
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_TIMEZONE=America/Argentina/Buenos_Aires
APP_URL=http://$IP:$PUERTO
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
APP_PORT=$PUERTO
LOG_CHANNEL=daily
LOG_LEVEL=warning
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=bigsysweb
DB_USERNAME=bigsysweb
DB_PASSWORD=$(openssl rand -hex 24)
SESSION_DRIVER=database
SESSION_LIFETIME=480
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local
REDIS_CLIENT=phpredis
REDIS_HOST=redis
MAIL_MAILER=log
MAIL_FROM_ADDRESS=no-responder@bigsys.com.ar
MAIL_FROM_NAME=BigSysWeb
ENV
  chmod 600 "$DIR/.env"
else
  echo "== 4. Ya había un .env: se deja como está."
fi

echo "== 5. Clave para que GitHub publique"
K=/root/.ssh/bigsysweb_deploy
mkdir -p /root/.ssh && chmod 700 /root/.ssh
[ -f "$K" ] || ssh-keygen -t ed25519 -N "" -C "github-deploy-bigsysweb" -f "$K" >/dev/null
grep -qf "$K.pub" /root/.ssh/authorized_keys 2>/dev/null || cat "$K.pub" >> /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys

cat <<FIN

=====================================================================
 Listo. Cargá estos 4 secrets en GitHub:
 repo → Settings → Environments → production (crealo si no está) → Add secret

   DEPLOY_HOST     $IP
   DEPLOY_USER     root
   DEPLOY_PATH     $DIR
   DEPLOY_SSH_KEY  (todo el bloque de abajo, incluidas las líneas BEGIN y END)

FIN
cat "$K"
cat <<FIN

 Después: GitHub → Actions → Deploy → Run workflow.
 Cuando termine, el sistema queda en  http://$IP:$PUERTO
 Y para crear tu usuario administrador:
   cd $DIR && docker compose -f docker-compose.prod.yml exec app php artisan erp:primer-admin tu@email.com
=====================================================================
FIN
