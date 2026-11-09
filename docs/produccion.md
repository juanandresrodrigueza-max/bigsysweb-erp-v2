# BigSysWeb ERP · Puesta en producción y escala

Guía corta para correr el ERP con muchos datos (miles de clientes, decenas de miles de artículos, cientos de miles de comprobantes).

## Base de datos

- **Desarrollo**: SQLite (`DB_CONNECTION=sqlite`). Alcanza para probar y para empresas chicas.
- **Producción**: PostgreSQL 14 o superior (recomendado) o MySQL 8 / MariaDB 10.6.
  ```env
  DB_CONNECTION=pgsql
  DB_HOST=127.0.0.1
  DB_PORT=5432
  DB_DATABASE=bigsysweb
  DB_USERNAME=bigsysweb
  DB_PASSWORD=********
  ```
- Las migraciones y toda la SQL del sistema son portables. Lo que cambia por motor (formato de mes, hora, diferencia de días, `ILIKE`) está centralizado en `app/Support/Sql.php`.
- La suite de pruebas corre en CI contra SQLite **y** PostgreSQL 16, así que una migración o consulta que solo anda en SQLite no llega a `main`.
- La migración `2026_10_08_000001_escala_columnas_e_indices` crea índices para las consultas pesadas y **un índice por cada columna `*_id` que no lo tenga** (MySQL los crea solo; PostgreSQL y SQLite no). Si se agregan tablas nuevas, conviene indexar sus claves foráneas o volver a correr esa lógica.
- El código de artículo (`sku`) es único **por empresa**, no en todo el sistema.

## Colas y tareas programadas

- `QUEUE_CONNECTION=database` (o `redis`). Los trabajos pesados (copias de seguridad, analista semanal) se encolan y no bloquean la pantalla ni el scheduler.
- Correr un worker permanente:
  ```
  php artisan queue:work --tries=2 --timeout=900
  ```
- Scheduler (cada minuto, en cron):
  ```
  * * * * * cd /ruta/al/erp && php artisan schedule:run >> /dev/null 2>&1
  ```
- Ejemplo de Supervisor para el worker:
  ```ini
  [program:bigsysweb-worker]
  command=php /ruta/al/erp/artisan queue:work --tries=2 --timeout=900 --sleep=3
  autostart=true
  autorestart=true
  numprocs=2
  user=www-data
  ```
- Con `QUEUE_CONNECTION=sync` todo corre en el momento (útil en desarrollo, no en producción).

## Catálogos grandes en pantalla

Los formularios (factura, compra, orden de compra, fórmulas, POS) mandan el catálogo completo a la página solo si tiene hasta 1.500 registros (`App\Support\Catalogo::LIMITE`). Con más, envían solo lo que el formulario ya usa y el resto se busca en el servidor a medida que se escribe (`/buscar/{articulos|contactos}/{forma}` y `/retail/buscar`). Las listas (stock, clientes, comprobantes) están paginadas.

## Medir rendimiento

- Cargar datos sintéticos en una copia de la base (nunca en producción):
  ```
  php artisan erp:carga-masiva --empresa=1 --articulos=50000 --clientes=5000 --comprobantes=200000
  ```
- Medir páginas desde adentro (tiempo total, cantidad de consultas, las más lentas):
  ```
  php artisan erp:medir /dashboard /comprobantes /estadisticas/rentabilidad --umbral=300
  ```
- Fase 77: `php artisan erp:medir --nocturna` corre solo cada noche a las 4:40 sobre las 15 empresas más grandes (como su dueño) en las
  páginas clave, guarda la historia en `mediciones_paginas` y la muestra en Superadmin → Salud ("Velocidad de las páginas"). Una página por
  encima del umbral (300 ms, configurable con `umbral_ms` en la configuración del sistema) pone la salud en aviso; dos noches seguidas o un
  error, en crítico, y avisa por mail. El test `RendimientoFase77Test` frena en CI cualquier pantalla que vuelva a hacer una consulta por fila.
- Referencia con 50.000 artículos, 5.000 clientes y 200.000 facturas en SQLite (PostgreSQL es más rápido en los agregados):

  | Página | Tiempo | Consultas |
  |---|---|---|
  | Nueva factura | 70 ms | 37 |
  | Stock | 130 ms | 48 |
  | Fondos | 70 ms | 45 |
  | Contable | 160 ms | 66 |
  | Inicio | 370 ms | 51 |
  | Comprobantes (lista) | 800 ms | 32 |
  | Cobranzas | 830 ms | 34 |
  | Estadísticas (mes) | 920 ms | 98 |
  | Rentabilidad (mes) | 1,4 s | 49 |
  | Rentabilidad (año completo) | 2,4 s | 49 |

  La rentabilidad calcula la apertura por artículo al cargar y las demás (rubro, cliente, vendedor, sucursal, obra) al abrir cada solapa; los meses cerrados de la serie se cachean 6 horas.

## Caché

- `CACHE_STORE=database` funciona; con `redis` mejora. Se cachean: los meses cerrados de la serie de rentabilidad (6 horas) y datos de configuración.

## Copias de seguridad verificadas y fuera del servidor (Fase 78)

- `backups:diario` (03:00) genera la copia de cada empresa y la **verifica** en el acto (zip abierto, manifiesto, tablas, conteos).
- `backups:probar` (03:40) vuelve a comprobar la última copia de cada empresa; `backups:probar --ensayo` (domingos 05:10) además la
  restaura en una transacción que se deshace y confirma que cargó todas las filas.
- **Fuera del servidor**: las mismas variables que el CRM, `OFFSITE_S3_ENDPOINT`, `OFFSITE_S3_BUCKET`, `OFFSITE_S3_REGION`,
  `OFFSITE_S3_ACCESS_KEY_ID`, `OFFSITE_S3_SECRET_ACCESS_KEY` (y opcional `OFFSITE_S3_PREFIX`, por defecto `bigsysweb/erp/`). Si el CRM corre
  en el mismo servidor, el bloque se copia tal cual de su `.env` (el CRM sube a `bigsysweb/system/`, el ERP a `bigsysweb/erp/`, mismo bucket).
  Con `OFFSITE_S3_BUCKET` cargado, el disco `backblaze` queda activo solo; `BACKUP_DISK` permite elegir otro disco de `config/filesystems.php`.
  Después de cargarlo: `php artisan config:cache && php artisan backups:probar` sube las copias existentes y la salud pasa a verde.
  Sin disco externo, la salud queda en aviso: las copias viven en el mismo disco del servidor.
- **Mails de monitoreo** (error nuevo, salud crítica, resumen semanal): `MONITOREO_EMAIL=uno@mail.com, otro@mail.com` en el `.env`, o en
  Superadmin → Salud (lo cargado ahí manda).
