---
titulo: Ventas: pedidos, entregas, lotes, abonos y control
modulo: comprobantes
rutas: /comprobantes/pedidos, /comprobantes/entregas, /comprobantes/lote, /comprobantes/pendientes, /comprobantes/abonos, /comprobantes/novedades
orden: 2
resumen: Pedidos que entran por canales, hojas de reparto, facturación por lote, abonos mensuales y los controles de lo que falta entregar o facturar.
---

## Pedidos (Comprobantes → Pedidos)

Acá caen los pedidos de la tienda online, el portal de clientes, WhatsApp, Mercado Libre, Tiendanube, WooCommerce, Shopify, PedidosYa, Rappi y el menú QR. Cada uno muestra cliente, ítems, forma de entrega y de pago.

- **Confirmar y facturar**: crea y emite la factura (o un presupuesto si preferís). El pedido pasa a "confirmado".
- **Preparando → Salió → Entregado**: el cliente lo sigue desde su link y recibe un WhatsApp si lo activás.
- **Pedido por WhatsApp**: pegá el mensaje (o dictalo) y el sistema identifica artículos y cantidades; revisás y creás el pedido.
- Con Mercado Envíos, desde el pedido se baja la etiqueta y se consulta el estado del envío.

## Hojas de reparto (Comprobantes → Entregas)

Agrupá las entregas del día por repartidor, imprimí la hoja con direcciones y bultos, y al volver marcá cada entrega como hecha o con novedad. Cada remito o factura con entrega pendiente aparece hasta que se entrega.

## Rendición del reparto (Comprobantes → Entregas → la hoja)

Cierra la caja del camión al volver del viaje.

1. En cada entrega, **+ Cobro** anota lo que cobró el chofer: efectivo, cheque (banco y número), transferencia o Mercado Pago. Puede cobrar más de lo que salía: el resto queda a cuenta del cliente.
2. Marcá cada entrega como entregada o no entregada.
3. **Rendir viaje**: elegí la caja donde entra el efectivo, cargá los viáticos (nafta, peajes, comida) y el efectivo que entrega el chofer. La pantalla muestra si hay faltante o sobrante antes de confirmar.

Al rendir, cada entrega cobrada genera su recibo imputado a la factura. El efectivo entra a la caja, los cheques a la cartera y las transferencias al banco. Los viáticos salen como gasto de la caja y la diferencia queda como faltante o sobrante de caja. Rinde quien puede cargar movimientos de fondos. Una hoja rendida no se puede volver a rendir ni sumar cobros, y la hoja impresa muestra la rendición.

## Facturación por lote (Comprobantes → Facturar en lote)

Elegí presupuestos aprobados o remitos emitidos de uno o varios clientes y facturalos todos juntos. Cada remito queda vinculado a su factura y el pendiente de facturar baja a cero.

## Abonos (Comprobantes → Abonos)

Cuotas, alquileres, mantenimientos, servicios mensuales: se define cliente, ítems, importe y día del mes, y la factura sale sola ese día (con aviso por mail o WhatsApp al cliente si lo activás). Se pueden pausar, cambiar el importe o terminar.

## Pendientes (Comprobantes → Pendientes)

Dos listas que deberían tender a cero: lo **entregado sin facturar** (remitos sin factura) y lo **facturado sin entregar** (facturas con entrega pendiente). Es el control diario del dueño o del encargado.

## Precios pactados por cliente (Clientes → ficha → Precios pactados)

Para el cliente que tiene un precio acordado en ciertos artículos o un descuento en un rubro entero.

- **Precio fijo por artículo**: ese es el precio final para el cliente. No se le suma el descuento general ni el del rubro, salvo que cargues un descuento en la misma condición.
- **Descuento por artículo**: el artículo sale a la lista del cliente con ese descuento.
- **Descuento por rubro**: vale para todo el rubro y sus subrubros. Un subrubro con descuento propio pisa al del rubro padre.
- **Vigente hasta**: opcional. Cuando vence, la condición deja de aplicarse y queda gris en la lista.
- **Recordar el último precio facturado**: se activa en Editar cliente. Cada artículo que se le factura queda con ese precio para la próxima vez. Nunca pisa un precio pactado a mano.

Al facturar, el precio se elige en este orden: pactado del artículo, descuento del rubro, lista y descuento del cliente. La línea muestra una marca que dice de dónde salió ("pactado", "último precio" o "dto. rubro"). Facturar al precio pactado no aparece en Novedades de facturación; facturar por debajo sí.

## Novedades de facturación

Cada vez que alguien factura con un precio distinto al de lista queda registrado: quién, cuánto, en qué comprobante. Sirve para revisar descuentos fuera de lo acordado.

## Portal de clientes

Cada cliente tiene un link propio (ficha del cliente → Portal) donde ve sus facturas, su saldo, descarga PDFs y paga por link. No necesita usuario ni contraseña.

## Errores comunes

- **El pedido no tiene artículo**: el canal mandó un código que no coincide con tu SKU. Corregí el SKU en el artículo o en el canal; mientras tanto el ítem se muestra como "sin artículo" y podés elegirlo a mano.
- **Se facturó dos veces un remito**: desde la factura se ve el remito de origen; anulá la factura duplicada con nota de crédito.
