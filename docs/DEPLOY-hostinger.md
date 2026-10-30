# Publicar en el VPS de Hostinger

Servidor: VPS KVM 4 · Debian 12 · `srv1759967.hstgr.cloud` · IP `2.25.209.3` · usuario `root`.
BigSysWeb se instala en `/opt/bigsysweb`, con sus propios contenedores (proyecto `bigsysweb`) y en un puerto libre del 8080 al 8099. No toca lo que ya corre en el servidor.

## Actualizar el que ya está instalado (lo habitual)

El ERP y el CRM ya corren en este VPS. Para actualizar el ERP, en hPanel → VPS → **Consola web**:

`curl -fsSL https://raw.githubusercontent.com/juanandresrodrigueza-max/bigsysweb-erp-v2/main/deploy/actualizar.sh | bash`

Busca dónde está instalado (no toca el CRM), guarda una copia del `.env`, baja la última versión, instala dependencias, compila las pantallas, migra la base y limpia la caché.

## Servidor nuevo (una sola vez)

1. En hPanel → VPS → **Consola web**, pegá y ejecutá el contenido de [`deploy/primera-vez.sh`](../deploy/primera-vez.sh). Si el repo es público, alcanza con esta línea:
   `curl -fsSL https://raw.githubusercontent.com/juanandresrodrigueza-max/bigsysweb-erp-v2/main/deploy/primera-vez.sh | bash`
2. Al terminar, el script muestra 4 datos. Cargalos en GitHub → repo → **Settings → Environments → production → Add secret**: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH` y `DEPLOY_SSH_KEY` (la clave completa, con las líneas BEGIN y END).
3. GitHub → **Actions → Deploy → Run workflow**.
4. Cuando termine, creá tu usuario en la Consola web:
   `cd /opt/bigsysweb && docker compose -f docker-compose.prod.yml exec app php artisan erp:primer-admin tu@email.com`
   Muestra la contraseña una sola vez.

## Después

Cada merge a `main` se publica solo: compila, sube el código, levanta los contenedores y corre las migraciones. El `.env` y `storage/` (archivos subidos, logs) viven en el servidor y el deploy no los toca.

## Para que anden avisos al celular, micrófono y cámara: dominio con HTTPS

Los navegadores sólo los permiten con `https://`. Apuntá un dominio (o el gratis que ofrece Hostinger) a `2.25.209.3` y poné adelante un proxy con certificado. Si en el servidor ya hay uno en el 80/443 (Traefik, Nginx Proxy Manager, Caddy), agregale una regla hacia `127.0.0.1:<APP_PORT>`. Después cambiá `APP_URL` en `/opt/bigsysweb/.env` y volvé a correr el Deploy.

## Seguridad

- La base de datos y Redis no se publican a internet: sólo los ve la red interna de Docker.
- En hPanel → VPS → **Firewall** hoy no hay reglas. Conviene permitir sólo 22 (SSH), 80, 443 y el puerto de BigSysWeb.
