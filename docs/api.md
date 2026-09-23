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

## Webhooks salientes

Además de consultar, BigSysWeb puede avisarte: en **Seguridad y API → Webhooks** registrás una URL y los eventos (factura emitida, cobro, stock bajo, pedido nuevo…). Cada aviso es un POST firmado con HMAC-SHA256 en el encabezado `X-BigSys-Firma`; verificalo con el secreto del webhook.

## Pedidos entrantes

Marketplaces y sistemas de delivery mandan pedidos a `POST /api/canales/{tipo}/{token}` con el token del canal (Configuración → Tienda y canales). El formato genérico es `{external_id, cliente{nombre, email, telefono, direccion}, items[{sku, descripcion, cantidad, precio_unit}], entrega, pago, envio, total}`.

## Especificación

`GET /api/openapi.json` devuelve la especificación OpenAPI 3 generada desde las rutas reales, lista para importar en Postman, Insomnia o Swagger UI.
