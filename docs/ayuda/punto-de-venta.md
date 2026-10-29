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

## Cobrar en la calle y rendir

Desde la ficha del cliente, **Cobrar**: efectivo, transferencia o cheque (número, banco de la lista del BCRA, fecha y titular; si ese cheque ya está cargado, avisa). Sale un **recibo numerado** que el cliente firma en la pantalla y se imputa a las facturas más viejas. La deuda baja en el momento, pero la plata queda a nombre del vendedor en su caja **"En mano"** hasta que rinde. El vendedor no puede anular ni cambiar un cobro.

En **Hoy** el vendedor ve lo cobrado, lo vendido y **Cerrar el turno y rendir**: se agrupa todo por medio. El encargado lo recibe en **Clientes → Vendedores → Rendiciones de la calle**: cuenta el efectivo (la diferencia queda como faltante o sobrante del vendedor), elige la caja y el banco, y la plata pasa a los fondos del negocio. Los cheques ya están en cartera.

**Devolución**: desde la ficha, qué vuelve y el motivo (fallado, vencido, no era lo que pidió, no lo pudo vender, error al cargar, otro). Sale como nota de crédito, vuelve al stock y se resta de la comisión del vendedor. **Confirmación de entrega**: en cada factura o remito, nombre de quien recibe, firma con el dedo y foto; queda en el comprobante como constancia.

## Lo que ve cada uno

**El vendedor**, en *Mi reporte* (hoy, 7 días, este mes, mes pasado): lo vendido, comprobantes, clientes y ticket promedio; su **objetivo del mes** con barra y si va adelantado o atrasado *para el día del mes en que está*; su comisión por ventas y por cobranzas con el mismo criterio que la liquidación; cuántos clientes de su cartera todavía no le compraron (tocás uno y te lleva a venderle) y lo que más vendió. Ve lo suyo y nada más.

**El encargado**, en **Estadísticas → Vendedores**: ranking con ticket y clientes, cómo viene la venta día por día, **cumplimiento de ruta** (asignados, visitados, con venta y quiénes faltan), **dónde estuvieron** (la ubicación se sella sólo al vender, cobrar o tocar Llegué; dice si quedó en el cliente o a cuántos metros) y por qué no se vendió.

**Avisos a los vendedores** (Clientes → Vendedores): les aparecen al abrir Vender, para todos o para uno, con vigencia; el urgente no se puede bajar. **Notas del cliente**: lo que el vendedor anota aparece la próxima vez que abre ese cliente; *Listo* la saca de la vista sin borrarla. **Relevar con fotos**: góndola, precio de la competencia o stock del cliente; la foto se achica en el teléfono y queda en Estadísticas → Vendedores y en la ficha del cliente.

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

## La caja fluida

- **Ventas en espera.** Con la venta armada, tocá **Dejar en espera** y ponele un nombre («el del gorro»). Atendés a otro y la retomás de un toque desde la franja de arriba. Hasta tres a la vez; quedan guardadas en esa computadora aunque cierres el navegador.
- **Frecuentes.** La pestaña **Frecuentes** trae lo más vendido en la caja en los últimos 30 días, en botones grandes: para el pan, el cigarrillo suelto o la fruta, lo que no tiene código de barras.
- **Cobrar con cheques.** En el cobro elegí **Cheque**, cargá número, banco, fecha de pago y quién lo firma; «+ otro cheque» para varios, y se mezcla con efectivo. Quedan en cartera en Fondos.
- **DNI del consumidor final.** Al cobrar a Consumidor Final podés poner el DNI y el nombre: salen en la factura sin crear un cliente.
- **Últimas ventas.** El botón de arriba lista las ventas de hoy de esta caja: **Reimprimir** el ticket, **Devolución** (marcás qué vuelve y por dónde devolvés la plata: sale la nota de crédito y la caja queda bien; con tarjeta no sale plata, se anula el cupón) y **Anular** la última venta (si todavía no tiene CAE se anula y el cobro vuelve; si ya tiene CAE, sale la nota de crédito sola por el total y la plata vuelve por donde entró). Anular pide el permiso de anular del módulo.

## La pantalla del cliente

Si tenés un segundo monitor mirando al cliente, en la caja (Vender → En la caja) tocá **Pantalla del cliente**: se abre en otra ventana y la arrastrás a ese monitor. Ahí se ve el **total en grande**, el último producto que pasaste con su precio y la oferta si tiene, el **vuelto** mientras escribís con cuánto paga, el «gracias» al terminar, y la **cartelera** del negocio. No hay nada que tocar: se actualiza sola con lo que vas pasando.

La cartelera se carga en **Configuración → Cartelería y promociones**: hasta 20 imágenes juntas (conviene vertical, 1080 × 1600) y videos de a uno; se turnan cada 8 segundos (se cambia en Punto de venta).

## Mercado Pago en el local: QR de la caja y terminal Point

En **Configuración → Mercado Pago**:
- **Conectar con Mercado Pago**: un botón te lleva a Mercado Pago, autorizás y volvés conectado. El token se renueva solo. (Si el botón no aparece, el panel de BigSys todavía no tiene cargada la aplicación: avisale a soporte. Mientras tanto se puede pegar el access token a mano.)
- **Caja QR**: «buscar cajas» trae las cajas de tu cuenta (Tu negocio → Sucursales y cajas); si hay una sola, queda elegida. Al cobrar con QR el cliente escanea **el cartel de siempre** y en su Mercado Pago le aparece el importe exacto; la factura sale sola cuando se acredita, al fondo de Mercado Pago, con el número de pago.
- **Point**: «buscar terminales» muestra las Point de la cuenta; «Usar ésta» la deja en **modo PDV** (desde ahí el aparato sólo cobra lo que le manda la caja). El botón de tarjeta de la caja le manda el importe; la factura sale aprobada la tarjeta, como **tarjeta** al fondo de débito o de crédito según lo que informe la Point (si tenés fondos de tarjeta con «débito» o «crédito» en el nombre, elige solo), con el número de pago y las cuotas anotadas en el cupón.

El posnet de SiPago queda para cuando tengamos su documentación técnica.
