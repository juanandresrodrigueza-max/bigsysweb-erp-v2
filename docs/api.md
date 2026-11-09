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
3. Filtros: `per_page` (hasta 5000), `buscar` (texto sin importar mayúsculas, tildes ni ñ: `buscar=pena` encuentra «Peña»), `columnas` (solo las que pedís, ej. `columnas=id,name,stock`), `desde` y `hasta` (fecha propia de la tabla), `actualizado_desde` (solo lo creado o modificado desde esa fecha y hora, para sincronizar de forma incremental), `id_desde` (cursor por id para tablas sin fecha de modificación), `sucursal_id`, y `formato=csv` para bajar un archivo CSV completo (separado por `;`, UTF-8 con BOM, listo para Excel).

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

## Para el CRM de BigSys (Fases 68 a 75)

Endpoints que usa el CRM con un token de API del ERP (sirven para cualquier otro sistema que quiera lo mismo):

| Método y ruta | Para qué |
|---|---|
| `GET /api/crm/salud` (`?ping=1` refresca) | Semáforo de la conexión con el CRM: cola, errores, último enviado y recibido, lo que ve el CRM |
| `GET /api/contacts/{id}/cuenta` | Saldo, vencido, límite de crédito, riesgo de mora y comprobantes pendientes del cliente, en vivo |
| `POST /api/contacts/{id}/cobros` | Registrar un recibo (medios, imputaciones, `vendedor_email` o `vendedor_id` de quien cobró); responde con el recibo y el cobrador |
| `GET /api/products/para-presupuestar?q=&contact_id=&ids=` | Artículos con stock y el precio de la lista del cliente (descuentos incluidos) |
| `GET /api/products/{id}/para-presupuestar?contact_id=` | Lo mismo para un artículo |
| `GET /api/comisiones/base?desde&hasta&detalle=1` | Base de comisiones por vendedor: facturado, notas de crédito, cobrado, porcentajes y comisión, con detalle |
| `GET /api/plan` | El plan contratado: si incluye el CRM, estado, vencimiento, usuarios máximos y activos |

Lo que el ERP **le avisa** al CRM va a `POST {crm}/api/erp/webhook` firmado con `X-BigSys-Firma` (HMAC-SHA256 del cuerpo con el secreto
compartido): `comprobante.emitido`, `comprobante.anulado`, `cobro.registrado` (con `vendedor`), `plan.cambiado`. Lo que el CRM le avisa al
ERP entra por `POST /api/crm/webhook/{empresa}` firmado con `X-BigSysweb-Signature`: `contact.created`, `contact.updated`, `quote.accepted`,
`deal.won`, `task.overdue`, `task.completed`, `user.created`, `user.updated`, `user.deactivated`, `user.deleted`. El contrato completo, con
campos y respuestas, está en `docs/integracion-crm.md`.

## Especificación

`GET /api/openapi.json` devuelve la especificación OpenAPI 3 generada desde las rutas reales, lista para importar en Postman, Insomnia o Swagger UI.

### Tokens limitados (Fase 44)

Al crear un token en Configuración → Seguridad podés completar «Solo estas tablas». Ejemplo: `products:id,name,stock; contacts`. Ese token solo puede exportar esas tablas, y en `products` solo esas columnas. Si pide otra tabla, recibe un 403. Si dejás el campo vacío, el token puede exportar todo.
