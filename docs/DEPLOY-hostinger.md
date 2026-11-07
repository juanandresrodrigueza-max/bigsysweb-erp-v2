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

## Para que el registro y los cobros anden de verdad

1. **Mails.** Sirven para el aviso de cada registro, el «Enviar a la empresa», las facturas por mail y la recuperación de contraseña. En el `.env` del servidor hoy figura `MAIL_MAILER=log`, y así no sale ningún mail. Cambialo por los datos SMTP de tu casilla. En Hostinger: `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.hostinger.com`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, `MAIL_USERNAME=` y `MAIL_PASSWORD=` con los de la casilla, y `MAIL_FROM_ADDRESS=` con esa misma casilla. Después corré otra vez `actualizar.sh`.
2. **Mercado Pago.** Entrá como superadmin a **Sistema** y pegá el access token de producción de la cuenta de BigSys. Mientras no esté cargado, el pago funciona en modo simulado.
3. **Captcha (opcional).** Creá un widget gratis en Cloudflare Turnstile para `erp.bigsysweb.com` y cargá `TURNSTILE_SITE_KEY` y `TURNSTILE_SECRET` en el `.env`. Si no lo cargás, el registro se protege igual con el campo trampa y el límite de 5 registros por hora por IP.
4. **Aviso de registros.** Llega al «email de soporte» configurado en Sistema y a todos los superadmin.

## Seguridad

- La base de datos y Redis no se publican a internet: sólo los ve la red interna de Docker.
- En hPanel → VPS → **Firewall** hoy no hay reglas. Conviene permitir sólo 22 (SSH), 80, 443 y el puerto de BigSysWeb.
