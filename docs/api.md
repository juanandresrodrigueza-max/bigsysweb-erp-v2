# API de BigSysWeb

Para conectar una tienda, una app propia, un tablero de BI o cualquier sistema con tu empresa en BigSysWeb. Todo es JSON sobre HTTPS.

## Autenticación

1. Creá un token en **Configuración → Seguridad y API → Tokens de API** (o con `POST /api/auth/login` con email y contraseña, que devuelve el token).
2. Mandalo en cada pedido:

```
Authorization: Bearer TU_TOKEN
Accept: application/json
```

El token hereda los permisos del usuario que lo creó y trabaja sobre su empresa. Revocalo desde la misma pantalla si se filtra.

## Convenciones

- Fechas: `YYYY-MM-DD`. Importes: números con dos decimales, en pesos.
- Listados: paginados con `page` y `per_page` (máximo 100); la respuesta trae `data`, `total`, `current_page`.
- Errores de validación: HTTP 422 con `message` y `errors` por campo. Sin token: 401. Sin permiso: 403.
- Límite: 120 pedidos por minuto por token; los webhooks públicos tienen su propio límite.

## Ejemplos

Listar artículos con stock:

```
curl -H "Authorization: Bearer TU_TOKEN" -H "Accept: application/json" \
  "https://tu-empresa.bigsysweb.com/api/products?per_page=50"
```

Crear un cliente:

```
curl -X POST -H "Authorization: Bearer TU_TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"López SRL","cuit":"30-71234567-1","condicion_iva":"Responsable Inscripto","email":"admin@lopez.com"}' \
  https://tu-empresa.bigsysweb.com/api/customers
```

Resumen de ventas de un período:

```
curl -H "Authorization: Bearer TU_TOKEN" "https://tu-empresa.bigsysweb.com/api/reports/sales?from=2026-09-01&to=2026-09-30"
```

## Mandar tus tablas a otra plataforma

Para alimentar un tablero de BI, pasarle todo al contador o sincronizar con otro sistema, usá el grupo **Exportar tablas**:

1. `GET /api/exportar` lista las tablas disponibles (clientes, proveedores, artículos, comprobantes de venta y compra con ítems, cobros y pagos con medios e imputaciones, cuenta corriente, gastos, fondos y movimientos, stock, depósitos, sucursales, plan de cuentas, asientos y líneas, cotizaciones) con sus columnas.
2. `GET /api/exportar/{tabla}` devuelve filas planas paginadas: `datos`, `total`, `pagina`, `ultima_pagina` y `siguiente` (URL de la página que sigue o `null`).
3. Filtros: `per_page` (hasta 500), `desde` y `hasta` (fecha propia de la tabla), `actualizado_desde` (solo lo creado o modificado desde esa fecha y hora, para sincronizar de forma incremental), `id_desde` (cursor por id para tablas sin fecha de modificación), `sucursal_id`, y `formato=csv` para bajar un archivo CSV completo (separado por `;`, UTF-8 con BOM, listo para Excel).

```
curl -H "Authorization: Bearer TU_TOKEN" \
  "https://tu-empresa.bigsysweb.com/api/exportar/comprobantes_venta?desde=2026-09-01&hasta=2026-09-30&per_page=500"

curl -H "Authorization: Bearer TU_TOKEN" \
  "https://tu-empresa.bigsysweb.com/api/exportar/articulos?actualizado_desde=2026-09-20T00:00:00"

curl -H "Authorization: Bearer TU_TOKEN" -o asientos.csv \
  "https://tu-empresa.bigsysweb.com/api/exportar/asientos?formato=csv&desde=2026-01-01"
```

Receta para sincronizar: carga inicial recorriendo páginas hasta `siguiente = null`; después, cada X minutos, `actualizado_desde` con la hora de la corrida anterior. Si querés enterarte al instante en vez de consultar, sumá webhooks (abajo). La guía paso a paso con ejemplos para Power BI, Google Sheets, Make/Zapier y scripts está en **Ayuda → Mandar tus datos a otras plataformas**.

## Webhooks salientes

Además de consultar, BigSysWeb puede avisarte: en **Seguridad y API → Webhooks** registrás una URL y los eventos (factura emitida, cobro, stock bajo, pedido nuevo…). Cada aviso es un POST firmado con HMAC-SHA256 en el encabezado `X-BigSys-Firma`; verificalo con el secreto del webhook.

## Pedidos entrantes

Marketplaces y sistemas de delivery mandan pedidos a `POST /api/canales/{tipo}/{token}` con el token del canal (Configuración → Tienda y canales). El formato genérico es `{external_id, cliente{nombre, email, telefono, direccion}, items[{sku, descripcion, cantidad, precio_unit}], entrega, pago, envio, total}`.

## Especificación

`GET /api/openapi.json` devuelve la especificación OpenAPI 3 generada desde las rutas reales, lista para importar en Postman, Insomnia o Swagger UI.
