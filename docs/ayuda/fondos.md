---
titulo: Caja, bancos, cheques y tarjetas
modulo: fondos
rutas: /fondos
orden: 5
resumen: Turnos de caja, arqueo, cuentas bancarias, conciliación, cheques propios y de terceros, cupones de tarjeta.
---

## Caja

Cada caja tiene **turnos**: se abre con el efectivo inicial, se registran ventas, cobros, pagos y retiros, y se cierra con el **arqueo** (contás y el sistema muestra la diferencia). El cierre queda impreso y auditado. Un cajero sólo ve su caja.

## Bancos

Cada cuenta bancaria tiene sus movimientos (transferencias recibidas, pagos, depósitos de cheques, comisiones). La **conciliación** compara con el extracto del banco: importás el archivo del home banking y marcás lo que coincide.

## Cheques

- **De terceros**: entran con un cobro; en cartera se depositan, se endosan a un proveedor o se rechazan.
- **Propios**: se emiten al pagar a un proveedor; el sistema avisa los que vencen esta semana para que haya fondos.
- **E-cheq**: mismo circuito, marcado como electrónico.

## Tarjetas

Cada cobro con tarjeta es un **cupón** pendiente de liquidación. Cuando llega la liquidación, la registrás con comisiones, retenciones e impuestos, y el neto entra al banco. El costo financiero se ve en **Estadísticas → Rentabilidad**.

En el punto de venta se cobra en **cuotas** con recargo por plan (Configuración → Empresa → Tarjetas).

## Errores comunes

- **El arqueo no cierra**: revisá los retiros y los cobros en efectivo del turno; el detalle está en el cierre.
- **Transferencia que no aparece en el banco**: puede haberse cargado en otra cuenta; buscala por importe en Fondos → Movimientos.
