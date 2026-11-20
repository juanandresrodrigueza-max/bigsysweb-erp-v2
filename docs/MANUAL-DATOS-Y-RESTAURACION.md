# Manual de datos y restauración del ERP

Qué copias hay, dónde están, qué revisar y qué hacer, paso a paso, en cada tipo de problema. Pensado para el dueño de BigSys y para quien administre el servidor.

> Regla de oro: **ante cualquier problema con datos, primero no tocar nada y hacer una copia del estado actual** (aunque esté roto). Restaurar siempre se puede repetir; lo que se pisa sin copia, no.

---

## 1. Cómo están protegidos los datos

| Copia | Qué trae | Cuándo | Dónde | Cuánto tiempo |
|---|---|---|---|---|
| **Copia por empresa** | Todos los datos de UNA empresa (tablas en JSON y CSV, adjuntos, certificados) | Cada noche, 03:00 | En el servidor **y** en Backblaze B2 (`bigsysweb/erp/<empresa>/`) | Las últimas 10 de cada empresa |
| **Copia completa de la base** | TODO el ERP: todas las empresas, superadmins, planes, suscripciones, certificado de ARCA, claves de Panel → Sistema y novedades, más la configuración del servidor (`.env` con la `APP_KEY`) | Cada noche, 03:20 | Cifrada, en Backblaze B2 (`bigsysweb/erp/completa/AAAA/MM/`), y las 2 últimas también en el servidor | 14 días + la primera de cada mes durante 1 año |
| **Copia antes de restaurar** | La empresa tal como estaba antes de una restauración | Cada vez que se restaura una empresa | En el servidor, en sus copias | Hasta que se borre |
| **Copia antes de actualizar** | La base entera antes de subir una versión nueva | Cada actualización que hace la sesión del CRM | En el servidor (`/root/erp-backups/`) | Hasta que se borre |

**Controles automáticos.** A las 03:40 cada copia por empresa se abre y se comprueba, y los domingos se ensaya una restauración. La copia completa se comprueba descifrándola antes de subirla. Si algo falla, **Panel → Salud** se pone en rojo y llega un mail al superadmin.

**Cuánto se puede perder en el peor caso:** lo cargado desde la última copia, es decir, hasta un día de trabajo.

**Backblaze B2** es otra empresa, con otros servidores y en otro país. Si Hostinger pierde el servidor, las copias siguen ahí.

---

## 2. Lo que se hace UNA vez (al empezar)

1. **Generar la clave de las copias completas**: Panel → Sistema → «Copia completa de la base de datos» → **Generar la clave**.
   - Se muestra **una sola vez**. Anotala en el gestor de contraseñas **y** en un papel guardado en otro lugar (no en la oficina del servidor ni en la compu).
   - **Nunca** por chat ni por mail.
   - Sin esta clave, ninguna copia completa se puede abrir.
2. Tocá **Hacer una copia ahora**: tiene que decir «comprobada y subida fuera del servidor».
3. **Comprobar el acceso a Backblaze**: entrá a la cuenta de Backblaze y fijate que veas el bucket de las copias, con las carpetas `bigsysweb/erp/completa/` y una carpeta por empresa. Guardá el usuario y la clave de Backblaze junto con la clave de las copias: si se pierde el servidor, es lo único que hace falta para recuperarlo todo.
4. **Probar que tu clave abre una copia** (ver la sección 5, «Ensayo»).

---

## 3. La rutina

- **Todos los días: nada.** Si algo falla, llega el mail de Salud.
- **Una vez por mes (10 minutos):**
  1. Panel → **Salud**: «Copias de seguridad», «Copias verificadas», «Copias fuera del servidor» y «Copia completa de la base» tienen que estar en verde.
  2. Panel → Sistema → Copia completa: la lista tiene que mostrar copias de los últimos días, «comprobada ✓» y «Fuera del servidor ✓».
  3. Cada 3 meses, el **ensayo** de la sección 5.

---

## 4. Qué hacer según el problema

### Caso A · Una empresa borró o cargó mal datos (el resto anda bien)

Ejemplos: borraron clientes, importaron una lista equivocada, alguien hizo desastre ayer.

1. Preguntá **cuándo** pasó, para elegir una copia de **antes** de eso.
2. El dueño de esa empresa (o un superadmin dentro de la empresa) entra a **Configuración → Copias de seguridad**.
3. Primero **Generar copia ahora**: guarda el estado actual por si hay que volver.
4. **Restaurar** → elegir la copia de la fecha correcta → escribir `RESTAURAR` → Restaurar.
   - Se reemplazan **todos** los datos de esa empresa por los de la copia. Lo cargado después de esa fecha se pierde, pero queda en la copia «Antes de restaurar».
   - No toca a ninguna otra empresa.
5. Revisar con la empresa que esté bien: saldos, último comprobante, stock.
6. Lo que se cargó entre la copia y hoy (por ejemplo las facturas de esta mañana) hay que volver a cargarlo. Las facturas con CAE se pueden consultar en ARCA.

**Si la copia que necesitás tiene más de 10 días:** está en las copias completas, que llegan hasta 14 días más la primera de cada mes por un año. Eso lo hace quien administra el servidor: restaura la copia completa en una base **aparte** (nunca sobre la de producción), genera desde ahí la copia de esa empresa y la restaura en producción con el paso 4 («subí un archivo»).

### Caso B · Se dio de baja o se eliminó una empresa por error

1. **Panel → Empresas** → filtro de estado «Dada de baja» → abrir la empresa → **Restaurar empresa**. Sirve mientras no se haya eliminado definitivamente (los datos quedan 90 días).
2. Si ya se eliminó definitivamente: es el caso A con copia completa. La última copia por empresa también puede seguir en Backblaze, en `bigsysweb/erp/<empresa>/`.

### Caso C · Una actualización salió mal

Síntoma: después de actualizar, el ERP da error o algo se ve roto.

1. **No hacer más cambios.** Panel → Sistema → **Modo mantenimiento** → activarlo, con un mensaje («Volvemos en unos minutos»).
2. Avisar en la sesión de Claude del ERP qué se ve roto, con captura. Casi siempre se arregla con una corrección y una actualización nueva, **sin tocar los datos**.
3. Sólo si los **datos** quedaron mal: pedirle a la sesión del CRM que restaure la copia de antes de esa actualización (`/root/erp-backups/…-antes-<commit>.dump`) con el mismo `pg_restore` de la sección 6, y que vuelva el código a la versión anterior.
4. Desactivar el modo mantenimiento y revisar Panel → Salud.

### Caso D · La base de datos se rompió, pero el servidor anda

Síntoma: errores de base de datos en todas las pantallas, o Salud en rojo en «Base de datos».

1. Modo mantenimiento (si el panel abre). Si no abre, igual seguí.
2. **Copiar el estado actual**, aunque esté roto: `pg_dump -Fc … -f /root/erp-backups/rota-$(date +%Y%m%d-%H%M).dump`.
3. Restaurar la **última copia completa**. Las 2 últimas están en el servidor, en `storage/app/private/copias-completas/`, y si no, en Backblaze. Seguí la sección 6 desde el paso 2. El `.env` ya está en el servidor: no hace falta reemplazarlo.
4. `php artisan migrate --force` y `php artisan optimize:clear`.
5. Sacar el mantenimiento, revisar Salud y avisar a las empresas que vuelvan a cargar lo del día.

### Caso E · Se perdió el servidor entero (Hostinger caído, borrado, hackeado)

Es la sección 6 completa, en un servidor nuevo (en Hostinger o en cualquier otro proveedor).

Si el servidor fue **hackeado**: después de restaurar, cambiar todas las claves. Eso incluye la `APP_KEY` (con cuidado: descifra lo guardado), las de Backblaze, Mercado Pago, Meta, Google y ARCA, la clave de las copias y las contraseñas de los superadmins.

### Caso F · Se perdió la clave de las copias completas

1. Las copias completas hechas con esa clave **no se pueden abrir**. Nadie puede, tampoco BigSys ni Backblaze: así funciona el cifrado.
2. **Ya mismo**: Panel → Sistema → Copia completa → **Cambiar la clave**, anotarla bien y **Hacer una copia ahora**. Desde ahí, las copias nuevas sirven.
3. Las copias por empresa no dependen de esa clave y siguen sirviendo.

### Caso G · Se perdió el acceso a Backblaze (o hay que cambiar de proveedor)

1. Las copias siguen en el servidor: las 10 últimas de cada empresa y las 2 últimas completas.
2. Recuperar la cuenta de Backblaze, o crear un bucket nuevo (en Backblaze o en otro servicio compatible con S3).
3. Pedirle a la sesión del CRM que cambie en el `.env` del ERP los datos `OFFSITE_S3_*` (endpoint, bucket, claves).
4. Panel → Sistema → **Hacer una copia ahora**, y revisar en Panel → Salud que «Copias fuera del servidor» y «Copia completa» vuelvan a verde.

---

## 5. Ensayo: comprobar que la clave y las copias sirven (cada 3 meses)

En cualquier computadora con `openssl`. Linux y Mac lo traen; en Windows, instalá Git y usá «Git Bash».

1. En Backblaze, bajá el último `…dump.enc` de `bigsysweb/erp/completa/AAAA/MM/`.
2. Descifralo con tu clave:
   ```bash
   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in erp-AAAAMMDD-HHMMSS-N.dump.enc -out erp.dump
   ```
   - Si pide la clave y no da error: **la clave anotada es la correcta**.
   - Si dice `bad decrypt`: la clave no es la de esa copia. Revisá si se cambió y probá con la anterior. Si no aparece, hacé ya el caso F.
3. Si tenés PostgreSQL instalado: `pg_restore --list erp.dump | head`. Tiene que listar tablas.
4. **Borrá** `erp.dump` al terminar: tiene todos los datos sin cifrar.

---

## 6. Restaurar el ERP entero desde la copia completa

**Hace falta:** acceso a Backblaze, la clave de las copias y un servidor con PostgreSQL 16 (o más nuevo).

1. **Bajar la copia.** En Backblaze → Buckets → el bucket de las copias → `bigsysweb/erp/completa/` → año → mes. Bajá los dos archivos de la fecha elegida, normalmente la última: `…dump.enc` (la base) y `…env.enc` (la configuración).
2. **Descifrar** (te pide la clave):
   ```bash
   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in erp-AAAAMMDD-HHMMSS-N.dump.enc -out erp.dump
   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in erp-AAAAMMDD-HHMMSS-N.env.enc -out env-recuperado
   ```
3. **Instalar el ERP** en el servidor nuevo con `deploy/primera-vez.sh`, como en la primera instalación. Si es el mismo servidor (caso D), salteá este paso.
4. **Configuración.** Del `env-recuperado`, copiá al `.env` del servidor nuevo **sí o sí la `APP_KEY`**: sin ella, lo cifrado (certificados, claves, WhatsApp) no se lee. Copiá también las claves de servicios externos (Backblaze, Mercado Pago, mail…). Dejá los `DB_*` del servidor nuevo y ajustá `APP_URL` si cambió la dirección.
5. **Restaurar la base**, con el ERP parado o en mantenimiento:
   ```bash
   pg_restore --clean --if-exists --no-owner --no-privileges -h HOST -U USUARIO -d BASE erp.dump
   ```
   En Docker: `docker compose cp erp.dump db:/tmp/erp.dump` y el mismo `pg_restore` con `docker compose exec db …`.
6. **Terminar:**
   ```bash
   php artisan migrate --force
   php artisan optimize:clear
   ```
7. **Adjuntos** (logos, PDFs subidos, fotos): no viajan en la copia completa. Si hacen falta, están en la copia de cada empresa (carpeta `archivos/` del zip, en Backblaze).
8. **Comprobar:** entrar como superadmin y revisar:
   - Panel → Salud en verde;
   - Panel → Empresas, que estén todas;
   - en una empresa, el último comprobante y los saldos;
   - una factura de prueba.
9. **Borrar** `erp.dump` y `env-recuperado`: tienen todo sin cifrar.
10. Si cambió la IP o el servidor: apuntar el dominio `erp.bigsysweb.com` al servidor nuevo (DNS) y revisar el certificado HTTPS.

Lo probamos de punta a punta el 20/11/2026: copia completa → descifrado con la clave → restauración en una base vacía. Dio exactamente las mismas empresas, usuarios, comprobantes, artículos y configuración.
