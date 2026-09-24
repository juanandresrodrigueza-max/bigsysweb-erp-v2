---
titulo: Tienda online, WhatsApp y marketplaces
modulo: configuracion
rutas: /configuracion/tienda
orden: 8
resumen: Tienda propia con carrito y link de pago, pedidos por WhatsApp, Mercado Libre, Tiendanube, WooCommerce, Shopify, PedidosYa y Rappi, y el programa de puntos.
---

## Tienda online propia

**Configuración → Tienda y canales → Tienda online**: activala, elegí el nombre del link, qué artículos se publican (por rubro o uno por uno), retiro o envío, costo de envío y formas de pago (link de Mercado Pago, transferencia, efectivo al recibir). Tu tienda queda en un link para compartir por WhatsApp o redes. Los pedidos entran en **Comprobantes → Pedidos**.

También salen de acá el link del **menú QR** (gastronomía) y el de **reservas** (turnos, hotelería).

## Pedidos por WhatsApp

Con la API de WhatsApp Cloud cargada, los mensajes de tus clientes llegan al sistema y se interpretan solos (con IA si está activa). Sin API, pegás el mensaje en Pedidos → "Pedido por WhatsApp".

## Marketplaces y delivery

**Conectar canal**: Mercado Libre, Tiendanube, WooCommerce, Shopify, PedidosYa, Rappi. Cada uno pide sus credenciales (la pantalla explica dónde conseguirlas). Se importan los pedidos, se empuja stock y precio si lo activás, y los pedidos entran como los demás. Los artículos se cruzan por SKU: usá el mismo código en ambos lados.

## Programa de puntos

Cuántos pesos por punto, cuánto vale cada punto y el canje mínimo. El cliente suma con cada factura y canjea en la próxima; ve sus puntos en su portal.

## Errores comunes

- **No entran pedidos**: en Tienda y canales se ve el último sincronizado y el último error del canal. Suele ser credencial vencida.
- **El stock no se actualiza en el canal**: activá "sincronizar stock" en el canal y verificá el SKU.
