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
