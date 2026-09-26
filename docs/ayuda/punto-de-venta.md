---
titulo: Punto de venta (mostrador)
modulo: retail
rutas: /retail, /minimarket
orden: 6
resumen: Vender rápido con lector de barras, balanza, ticket, cuotas, Mercado Pago y sin conexión.
---

## Vender

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
