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

## Conectar una tienda con un botón

En **Configuración → Tienda y canales → Marketplaces**, cada tienda tiene su tarjeta. **MercadoLibre** y **Tienda Nube**: tocás *Conectar*, entrás con tu usuario, autorizás y volvés conectado; no se copia ninguna clave (si el botón dice que falta configurar la aplicación, es un dato del sistema que carga BigSys una sola vez). **WooCommerce**: escribís la dirección de tu tienda (en HTTPS) y tu WordPress te pide aprobar el acceso; o pegás una clave de lectura/escritura. **Shopify**: una app a medida en tu administrador, con la dirección `.myshopify.com`, el token y la clave secreta (obligatoria: con ella se verifica cada aviso de venta). Antes de guardar claves, el sistema prueba que sirvan.

Al conectar se crean solos los **avisos de venta** en la tienda. Si un aviso se pierde, cada 10 minutos el sistema le pregunta a la tienda qué ventas pagas hubo. *Desconectar* saca los avisos y borra las claves; lo publicado y los ajustes quedan.

**Ajustes de cada tienda**: la cuenta (cliente) a la que van las ventas, el **colchón** de stock que te guardás, la **lista de precios** de la tienda (con IVA), un recargo sólo para esa tienda, desde qué fecha entran las ventas, si entran solas, si se pausa la publicación al quedar sin stock, qué partes del producto le manda el ERP (precio, stock, nombre, descripción, foto, categoría) y, en MercadoLibre, **quién factura** (la factura de ML → la venta entra como remito; la del ERP → entra como factura y apagás la automática en ML).

## Publicar desde el catálogo (Stock → Tienda online)

Elegís la tienda, un rubro (o buscás productos en todos), tildás y tocás **Revisar y publicar**: cada producto con su precio, cuánto se va a ofrecer y de qué rubro es, con una ✕ para sacar lo que no quieras. La lista de precios se decide en tres lugares, del más general al más fino: la de la tienda, la del rubro en esa tienda y la del artículo al revisar; queda guardada con la publicación. **Sin precio en esa lista no se publica** (lo ves marcado). Lo que ya estaba en la tienda con el mismo código se **actualiza** en vez de duplicarse.

En **MercadoLibre** cada rubro necesita su **categoría**: ML la propone a partir del producto más vendido del rubro, o escribís qué vendés con tus palabras. La categoría dice qué datos exige (marca, modelo…) y Mercado Envíos pide medidas y peso: se cargan **una vez por rubro**. Si ML rechaza algo, el panel lo dice en castellano con el paso a seguir ("esa categoría no admite Mercado Envíos" casi nunca es tu cuenta: probá con otro rubro). En WooCommerce, Tienda Nube y Shopify la categoría se crea sola con el nombre del rubro, o unís el rubro a una que la web ya tiene.

**Se mantiene solo**: cada 5 minutos (y al instante al mover stock) se compara precio y stock con lo publicado y se corrige lo que cambió. Un artículo sin stock se pausa y vuelve cuando entra mercadería. En *Lo publicado* ves cada una con su estado, la lista con la que sale y el último error si lo hubo.

## Lo que ya tenés en la tienda: atar, adoptar, desatar

En **Stock → Tienda online → Las que ya tenés en la tienda** se leen todas las publicaciones (activas y pausadas) con foto, precio, stock y cuántas vendieron. Cada una viene con el artículo **propuesto**: por código (si el SKU de la tienda es tu código), por código de barras, por memoria ("ya lo ataste antes") o por nombre. **Segura**: un botón *Es «tal artículo»*. **Dudosa**: dos o tres candidatos (una cantidad distinta, "25 KG" contra "5 kg", baja la sugerencia a dudosa). **Ninguna**: *Decir cuál artículo es* abre un buscador por nombre, descripción, código o barras. **Nada se ata solo.**

- **Atar**: sus ventas entran con el artículo puesto y se pueden facturar; la web no se toca. Al atar, la foto de la tienda pasa al catálogo si el artículo no tenía, las ventas pendientes de esa publicación quedan con el artículo, y el sistema se acuerda del título para la próxima.
- **Adoptar**: además, el precio (lista de la tienda, con IVA) y el stock (menos el colchón) los manda el ERP desde ese momento; lo que la web tenía se pisa. *Emparejar las N seguras* ata y adopta de un clic; *Adoptar las N atadas* hace todas juntas. Una publicación **con variantes** se ata pero no se adopta.
- **Desatar**: deshace y olvida el título.
