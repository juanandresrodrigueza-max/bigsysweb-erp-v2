---
titulo: Mandar tus datos a otras plataformas (API)
modulo: configuracion
rutas: /configuracion/seguridad, /api/docs
orden: 9
resumen: Paso a paso para sacar las tablas del sistema (clientes, artículos, comprobantes, cobros, stock, contabilidad) y mandarlas a un tablero de BI, al contador, a una tienda o a otro sistema, a mano o de forma automática.
---

## Qué se puede sacar

Todas las tablas del negocio salen por la API en formato JSON o CSV: clientes, proveedores, artículos, rubros, comprobantes de venta y de compra con sus ítems, cobros y pagos con medios e imputaciones, cuenta corriente, gastos, cajas y bancos con sus movimientos, movimientos de stock, depósitos, sucursales, plan de cuentas, asientos con sus líneas y cotizaciones. Siempre acotado a tu empresa: nadie ve datos de otra.

La lista viva, con las columnas exactas de cada tabla, está en `GET /api/exportar` (o en **Ayuda → Referencia de la API**).

## Paso 1: crear el token

1. Entrá a **Configuración → Seguridad y API → Tokens de API**.
2. Escribí un nombre que identifique a quién se lo das (por ejemplo "Power BI contador") y tocá **Crear token**.
3. Copiá el token en ese momento: por seguridad no se vuelve a mostrar. Si se pierde o se filtra, **Revocar** y crear otro.

El token hereda los permisos del usuario que lo creó. Para exportar hace falta permiso de **Configuración** o de **Estadísticas**; si querés un token que solo lea, creá un usuario con rol "solo lectura" y generá el token desde ese usuario.

## Paso 2: probar que funciona

En una terminal (o en Postman / Insomnia) pedí el catálogo:

```
curl -H "Authorization: Bearer TU_TOKEN" -H "Accept: application/json" https://tu-empresa.bigsysweb.com/api/exportar
```

Devuelve cada tabla con su nombre, descripción, columnas y la URL para bajarla. Después pedí una tabla:

```
curl -H "Authorization: Bearer TU_TOKEN" "https://tu-empresa.bigsysweb.com/api/exportar/articulos?per_page=500"
```

La respuesta trae `datos` (las filas), `total`, `pagina`, `ultima_pagina` y `siguiente` (la URL de la página que sigue, o `null` cuando no hay más).

## Paso 3: elegir cómo traer los datos

- **Todo de una vez (carga inicial)**: recorré las páginas hasta que `siguiente` sea `null`. Con `per_page=500` una tabla de 50.000 filas son 100 pedidos.
- **Solo lo nuevo (sincronización)**: guardá la fecha y hora en que corriste la última vez y mandala en `actualizado_desde=2026-09-01T00:00:00`. Vuelven solo las filas creadas o modificadas desde entonces (incluye anulaciones, porque cambian la fila). Para tablas sin fecha de modificación (ítems, imputaciones, líneas de asiento) usá `id_desde=ÚLTIMO_ID_QUE_TENÉS`.
- **Un período**: `desde=2026-09-01&hasta=2026-09-30` filtra por la fecha propia de la tabla (fecha del comprobante, del cobro, del movimiento).
- **Una sucursal**: `sucursal_id=3` en las tablas que tienen sucursal.
- **Para Excel, Google Sheets o el contador**: agregá `formato=csv` y baja un archivo CSV separado por punto y coma, con acentos correctos, listo para abrir o importar. En CSV no hay paginado: baja todo lo que cumpla el filtro.

## Paso 4: automatizarlo

Cualquier herramienta que haga pedidos HTTP puede traer las tablas sola:

- **Power BI / Looker Studio / Tableau**: conector "Web" o "JSON" con la URL de la tabla y el encabezado `Authorization: Bearer TU_TOKEN`. Poné `per_page=500` y configurá el conector para seguir `siguiente` (en Power BI, con `List.Generate`; en Looker Studio, con un conector comunitario o pasando por Google Sheets).
- **Google Sheets**: con Apps Script, `UrlFetchApp.fetch(url, {headers: {Authorization: 'Bearer TU_TOKEN'}})` y un disparador cada hora.
- **Make / Zapier / n8n**: módulo HTTP con el token y un paso "Iterar" sobre `datos`.
- **Un script propio (Python, Node, PHP)**: guardá `actualizado_desde` en un archivo y corré el script con cron cada 15 minutos.
- **Para no consultar: que el sistema avise**. En **Seguridad y API → Webhooks** registrás una URL tuya y los eventos (factura emitida, cobro, compra, stock bajo mínimo, cliente creado, artículo modificado). Cada vez que pasa algo, BigSysWeb hace un POST a tu URL con los datos, firmado con HMAC-SHA256 en el encabezado `X-BigSys-Firma`. Combiná las dos cosas: carga inicial por `exportar` y después webhooks para tener todo al instante.

## Cómo se relacionan las tablas

Todas las filas tienen `id`; las relaciones se hacen por columnas `*_id`:

- `comprobante_items.comprobante_id` → comprobantes; `comprobante_items.product_id` → artículos.
- `comprobantes.contact_id` → clientes o proveedores según `direccion` (venta / compra). `comprobantes.tipo` es FA, FB, FC, FE, NCA, REM, PRE… y `estado` es borrador, emitido o anulado.
- `cobro_imputaciones.cobro_id` → cobros y `.comprobante_id` → comprobantes; lo mismo para `pago_imputaciones`.
- `cuenta_corriente.contact_id` → cliente o proveedor; `comprobante_id`, `cobro_id` o `pago_id` dicen qué la originó.
- `movimientos_fondos.cuenta_fondos_id` → cuentas de fondos; `origen` y `origen_id` apuntan al cobro, pago o gasto.
- `asiento_lineas.asiento_id` → asientos; `.cuenta_id` → plan de cuentas.
- `articulos.rubro_id` → rubros; `movimientos_stock.product_id` → artículos y `.deposito_id` → depósitos.

Los importes están en pesos con dos decimales. Los comprobantes en dólares además traen `moneda`, `cotizacion`, `total_me` y `neto_me`.

## Límites y errores

- Hasta 500 filas por página y 240 pedidos por minuto por token. Si te pasás, responde 429: esperá un minuto.
- 401: el token no es válido o fue revocado. 403: el usuario del token no tiene permiso. 404: la tabla no existe (mirá el catálogo). 422: un filtro mal escrito (por ejemplo una fecha inválida); el detalle viene en `errors`.
- Todo lo que baja un token queda en **Configuración → Auditoría**, para saber quién sacó qué.
