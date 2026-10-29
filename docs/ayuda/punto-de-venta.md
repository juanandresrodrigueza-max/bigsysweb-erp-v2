---
titulo: Vender (calle, mostrador y caja)
modulo: comprobantes
rutas: /vender, /retail, /minimarket
orden: 6
resumen: Una sola pantalla para vender: en la calle con la ruta del día, en el mostrador o en la caja. Sin conexión, con ticket, cuotas y Mercado Pago.
---

## Dónde vendés hoy

**Comprobantes → Vender** abre en el modo del vendedor (se configura en **Clientes → Vendedores**) y se cambia con un toque en la franja de arriba:

- **En la calle**: arranca en tus clientes. La ruta del día los agrupa en *Agendados para hoy*, *Les toca comprar*, *Dejaron de comprar*, *Compran casi todos los días* y *Sin historial*. Al abrir uno ves su cuenta, sus condiciones (lista, descuentos pactados), lo que suele llevar, lo que hace rato no lleva y un pedido sugerido. Marcás productos, ajustás cantidades y lo mandás como **presupuesto, factura o remito** (lo decide el encargado en Clientes → Vendedores → *Cómo se vende en la calle*). Anda sin señal: descargás el paquete con wifi, vendés y se manda solo cuando vuelve la conexión. Cada venta y cada visita (*Llegué*, *No vendí* con motivo) queda registrada con la ubicación.
- **En el local**: arranca buscando productos, consumidor final, cobrás y sale el ticket.
- **En la caja**: el mismo mostrador con pantalla ancha (ticket a la izquierda, total grande). /retail y /minimarket entran acá.

Los precios en la calle los pone el sistema con las condiciones del cliente; cambiar un precio o hacer más descuento pide el permiso de editar comprobantes.

## Descuentos: hasta dónde puede el vendedor solo

El tope está en **Clientes → Vendedores → Cómo se vende en la calle**. Hasta ahí el vendedor descuenta solo. Por encima, al confirmar la app pide el **OK del encargado**: el encargado pone su usuario y su clave en el mismo teléfono del vendedor. Autoriza cualquiera que pueda anular comprobantes (dueño, administrador); nadie puede autorizarse a sí mismo. El OK vale 15 minutos, para una sola venta, y queda atado a ese vendedor, ese cliente y ese porcentaje. Para autorizar hace falta señal; sin señal la venta queda en la cola y pide el OK al mandarla.

Las **promociones por cantidad**, los **precios pactados** y los **descuentos de la lista** los decidió el dueño, no el vendedor: no piden permiso ni cuentan para el tope, y nunca se suman entre sí (se toma el mayor). En *Clientes → Vendedores* queda el cuadro *Descuentos por encima del tope* con quién lo pidió, de cuánto, a qué cliente, quién lo autorizó y cuántas autorizaciones se pidieron sin usar. Si el cliente se pasa del límite de crédito o tiene deuda vencida, avisa y podés vender igual: queda registrado quién y por qué.

## Vender en el mostrador

Escaneá o buscá el artículo (F2), ajustá cantidades, **Cobrar** (F9). Elegís el medio (efectivo, tarjeta, transferencia, Mercado Pago) y sale la factura y el ticket. Con efectivo, los botones de billetes calculan el vuelto.

- **Cliente**: por defecto Consumidor Final. Si elegís un responsable inscripto, sale Factura A con IVA discriminado.
- **Pago mixto**: parte efectivo y parte tarjeta.
- **Queda a cuenta**: si falta cobrar y el cliente tiene cuenta corriente.

## Tarjeta en cuotas

Al elegir tarjeta se elige plan (Visa 3 cuotas, 6, 12…). El recargo del plan entra como ítem de la factura, así el ticket cierra con lo que paga el cliente. Los planes se configuran en **Configuración → Empresa → Tarjetas y planes de cuotas**.

## Mercado Pago en el mostrador

Con las credenciales cargadas (Configuración → Empresa → Mercado Pago), el medio Mercado Pago ofrece **QR de mostrador** (el cliente escanea el QR fijo de la caja) o **Point** (el importe va al lector). El POS espera el pago y emite solo cuando se aprueba.

## Balanza e impresora

- **Balanza**: los códigos de peso variable (EAN-13 que empieza con el prefijo configurado) cargan el artículo por su **PLU** y el peso o el importe. El PLU se carga en la ficha del artículo pesable y el archivo para la balanza se baja en Stock → Carnicería y pesables.
- **Números de serie**: escaneá la etiqueta de la serie de la unidad y se suma ese artículo con esa serie; queda registrada la venta y la garantía.
- **Impresora térmica**: por la ventana del navegador o directo por USB/serie (WebSerial, sin driver). Se puede imprimir automáticamente al cobrar.

## Cierre de turno

Al cerrar la caja se cuenta la plata por medio de pago y, para los artículos marcados **Contar en el cierre de turno**, el stock que queda. El sistema compara lo que salió con lo facturado y con lo cobrado (ver la guía de Fondos).

## Sin conexión

Si se corta internet, el POS sigue vendiendo: las ventas quedan guardadas en el navegador y se suben solas cuando vuelve la conexión. Mientras tanto el ticket sale como "sin conexión".
