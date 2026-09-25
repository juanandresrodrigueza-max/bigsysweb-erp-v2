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
