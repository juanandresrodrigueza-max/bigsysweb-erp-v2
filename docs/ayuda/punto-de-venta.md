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

- **Balanza**: los códigos de peso variable (EAN-13 que empieza con el prefijo configurado) cargan el artículo y el peso o el importe.
- **Impresora térmica**: por la ventana del navegador o directo por USB/serie (WebSerial, sin driver). Se puede imprimir automáticamente al cobrar.

## Sin conexión

Si se corta internet, el POS sigue vendiendo: las ventas quedan guardadas en el navegador y se suben solas cuando vuelve la conexión. Mientras tanto el ticket sale como "sin conexión".
