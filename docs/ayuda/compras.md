---
titulo: Proveedores, compras y pagos
modulo: proveedores
rutas: /proveedores
orden: 4
resumen: Órdenes de compra, carga de facturas (a mano, con IA o desde ARCA), cuenta corriente de proveedores, pagos con retenciones y cheques propios.
---

## Órdenes de compra (Proveedores → Órdenes)

Lo que le pediste a cada proveedor y qué falta recibir. Se arman a mano o desde **Stock → Informes → Armar órdenes de compra**, que sugiere cantidades por lo que se vende y lo que hay. Al recibir la mercadería, la factura de compra se arma sola desde la orden.

## Cargar una factura de compra

**Proveedores → Compras → Nueva**. Tres formas:
- **A mano**: cargá los datos tal como vienen en el comprobante (tipo, punto de venta, número, fecha, CAE) y los ítems.
- **Con IA**: subí la foto o el PDF y el sistema lee proveedor, ítems e importes; revisás y confirmás.
- **Desde ARCA**: importá el archivo de "Mis Comprobantes recibidos" y las facturas entran todas juntas.

Al registrar: entra el stock, se actualiza el costo del artículo (y el precio si tiene margen), queda la deuda en la cuenta corriente del proveedor y se hace el asiento con el IVA crédito.

## Compras en dólares

En el formulario de compra elegí **Moneda: Dólares** y cargá la cotización del día. Los precios se cargan en USD; el sistema los guarda en pesos a esa cotización (costo del artículo incluido) y la deuda con el proveedor queda expresada en USD. Al pagar, si la cotización cambió, se registra la diferencia de cambio automáticamente.

## Pagar

Desde la ficha del proveedor, **Registrar pago**: elegís facturas, medio (transferencia, efectivo, cheque propio, e-cheq) y el sistema **sugiere las retenciones** que corresponden (Ganancias con el mínimo mensual acumulado, IIBB según padrón, IVA). Se emite el certificado de retención y queda para el SICORE.

Los cheques propios quedan en Fondos → Cheques hasta que se debitan; el sistema avisa los que vencen esta semana.

- **Pago a cuenta**: podés pagar sin elegir facturas (anticipo). Después, en la pestaña **Pagos**, **Aplicar a facturas** lo imputa a las facturas que llegaron.

## Cuenta corriente y vencimientos

La ficha del proveedor muestra saldo, facturas pendientes por vencimiento y el historial. **Proveedores → por pagar** ordena lo que vence esta semana y este mes para armar el flujo de fondos.

## Precios de proveedores

**Stock → Importar precios**: subí la lista del proveedor (Excel, PDF); el sistema cruza cada fila con tu catálogo (con IA si está activa), muestra qué sube y cuánto, y aplicás lo que quieras.

## Contactos del proveedor

En la ficha del proveedor, pestaña **Contactos**: vendedor, cobranzas, depósito… Marcá a quién le llegan las **órdenes de compra y de pago** y el envío lo sugiere solo.

## Errores comunes

- **Factura del proveedor con CUIT distinto al cargado**: corregí el CUIT en la ficha; el cruce con ARCA usa el CUIT.
- **Pagué sin retener y correspondía**: anulá el pago y cargalo de nuevo con la retención; el certificado se emite en ese momento.

## Leer mejor la factura y entender al proveedor

Si la foto la sacás desde el **celular**, el sistema lee el **QR de ARCA** de la factura: CUIT, tipo, punto de venta, número, fecha, total y CAE salen **exactos** (cartel verde "Los datos de la factura salieron del código QR", y te dice si corrigió algo). Los renglones siempre salen de la foto: ésos se revisan igual. Con el CUIT exacto el proveedor se reconoce aunque el nombre se haya leído mal.

Después de leer, la factura se **entiende** como la entiende el que la carga a mano:
- **Lo aprendido del proveedor manda**: su código y su descripción apuntan a tu artículo desde la segunda factura (se aprende al registrar).
- **Bultos a unidades**: si la ficha dice cuántas trae el bulto (campo *Unidades por bulto*), si el nombre lo dice entre paréntesis —*AZUCAR X1KG (10)*, prendiendo *El número entre paréntesis es el bulto* en la ficha del proveedor— o si el precio del renglón es como N veces el costo, la cantidad se multiplica y el precio se divide, y te lo avisa.
- **Bonificación en mercadería**: el mismo artículo en $0 entra sin cargo y no baja el costo.
- **Renglones negativos** son descuentos: se reparten entre los renglones que nombran (o entre todos). La **bonificación del pie** se aplica a todos.
- **Conceptos raros del pie** ("Gastos administrativos", "Perc. IIBB CABA"): se pregunta **una vez por proveedor** qué es (percepción, descuento, flete o nada) y queda aprendido para ese CUIT en todos los negocios.

**Revisar antes de mandar** muestra cada renglón con el costo del sistema contra el de la factura, el % que cambia (en rojo si es más del 15 %), el margen que queda con el precio de venta y un tilde por renglón para decidir qué costo se escribe. Y controla que cierre: si los renglones no suman el total de la factura, hay repetidos o un costo queda arriba del precio, lo dice y no deja registrar hasta arreglarlo.

**Remito y factura**: tildá *Es un remito sin precios* y entra **sólo al stock** (sin cuenta ni asiento). Cuando llega la factura, elegís *¿Es la factura de un remito que ya entró?* y entra **sólo a contabilidad y a la cuenta**, sin mover el stock otra vez, y recién ahí se escribe el costo. **Comparar con orden de compra** cruza la factura contra lo pedido con una tolerancia de precio (2 % por defecto, se cambia en la ficha del proveedor) y arma el reclamo para copiar. **Dejarla para después** guarda la factura a medio cargar (la lectura, lo que corregiste) y aparece arriba en Compras para retomarla desde cualquier computadora sin volver a leerla.

## Controlar la recepción con la pistola

Es opcional. Sirve para asegurarte de que lo que bajó del camión es lo que dice la factura, antes de que entre al stock.

- **Arranca desde la factura.** En la compra (borrador o ya registrada) tocá **Controlar recepción**. Los renglones y las cantidades ya están: cada uno se cuenta contra su código de barras.
- **El campo del lector siempre tiene el foco.** Funciona con una pistola USB o Bluetooth (se comporta como un teclado) y con la cámara del celular. Cada lectura suena: agudo corto si entró bien, grave doble si algo anda mal.
- **La barra de arriba** arranca en rojo, pasa a amarillo y termina en verde cuando llegó todo. Se ve de lejos.
- **Si vienen cajas, usá el multiplicador** ×6 ×12 ×24 (o escribí el tuyo). Si el bulto trae el código largo GS1-128, la pistola lee el GTIN, el lote, el vencimiento y la cantidad del bulto, y los anota sola.
- **Un código que «no está en esta factura»** casi siempre es el código interno de la caja. Elegís de qué renglón es y tocás **Es éste**: se cuenta y queda aprendido para ese artículo en tu negocio (sin tocar la ficha). Si de verdad llegó algo que no está facturado, **Agregarlo para reclamar**; si fue un disparo al pasar, **No llegó**.
- **Lo que no tiene código se cuenta a mano**, con los botones de cada renglón. Si un código está en dos renglones, el sistema te pregunta cuál es.
- **Podés dejarlo a medias.** Se guarda solo: al volver a abrir la misma compra, retoma donde ibas (también desde otro celular).
- **Cuando algo no cierra**, al terminar decidís renglón por renglón: entra **lo que contaste**, entra **lo que dice la factura**, o queda **pendiente** (entra lo contado y el resto se reclama). Hay «aplicar a todos».
- **El reclamo al proveedor** aparece sólo si hubo diferencias, ya escrito con el detalle y con los botones de WhatsApp, mail y copiar. No se manda solo. En Compras → **Reclamos** está la lista; cada uno se cierra solo cuando registrás la nota de crédito del proveedor con esos artículos, o a mano con **Resuelto**.
- **Si querés que el control sea obligatorio**, en Compras → Reclamos tildá **Exigir el control con la pistola antes de registrar**. Las notas de crédito y las facturas de un remito no lo piden. Si la compra ya estaba registrada cuando la controlás, el stock se corrige en el momento por la diferencia.

## Qué me falta cargar

Una pregunta concreta: **¿hay comprobantes de proveedores que ARCA tiene y tu sistema no?** Cada uno es IVA que no te estás tomando.

- Entrá a **ARCA → Mis Comprobantes → Recibidos**, elegí el período y bajá el CSV. En Compras tocá **Qué me falta cargar** y subilo. El archivo se cruza contra lo cargado y se borra a la media hora.
- Te contesta cinco cosas: **las que faltan** (ordenadas de la que más IVA tiene a la que menos, con aviso de los proveedores que no tenés), **las cargadas sin el CUIT del proveedor** (por eso el libro de IVA no cruza solo; se arregla poniendo el CUIT en la ficha), **las cargadas por otro importe**, **las cargadas dos veces** (IVA computado de más) y **las que no cierran** (neto + exento + IVA + percepciones no da el total).
- Tildás las que faltan y **Crear las que faltan**: quedan como borrador para completar los artículos, o registradas directamente si así lo elegís. Los proveedores nuevos se crean por CUIT.
- Si al leer una factura con la cámara el proveedor tiene **una sola orden de compra abierta**, la comparación con la orden se hace sola. El pedido al proveedor sale por WhatsApp o mail desde la orden (también desde el pedido sugerido).
