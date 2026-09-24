---
titulo: Cobrar y cuenta corriente
modulo: clientes
rutas: /clientes
orden: 3
resumen: Cómo registrar un cobro, imputarlo a facturas, manejar cheques y tarjetas, y seguir a los que deben.
---

## Registrar un cobro

Desde la ficha del cliente, **Registrar cobro**, o desde la factura, **Registrar cobro**. Elegís uno o varios medios (efectivo, transferencia, cheque, tarjeta, Mercado Pago) y a qué facturas se imputa. Si el cliente paga de más, queda **a cuenta** para la próxima.

- **Recibo a cuenta (sin facturas)**: podés registrar el cobro sin imputarlo a ningún comprobante; queda como saldo a favor del cliente. Después, en la pestaña **Cobros** de la ficha, el botón **Aplicar a facturas** reparte ese saldo entre las facturas pendientes.
- **Facturas en dólares**: en la lista de pendientes aparece el saldo en USD y su valor en pesos a la cotización del recibo (que podés cambiar). Si cobrás a una cotización distinta de la de la factura, el sistema registra la **diferencia de cambio** sola: la factura queda cancelada en USD y la ganancia o pérdida va a resultado, sin ensuciar la cuenta corriente.

- **Efectivo** entra en la caja activa. **Transferencia** en el banco que elijas.
- **Cheque**: se carga con número, banco y fecha de pago; queda en cartera en **Fondos → Cheques** hasta que lo depositás o lo endosás.
- **Tarjeta**: queda como cupón pendiente en **Fondos → Tarjetas**; cuando la tarjeta liquida, registrás la liquidación con sus comisiones.
- **Mercado Pago**: por link de pago (el cliente paga desde el mail o WhatsApp) o, en el mostrador, con QR o Point.

Con **descuento por pronto pago** o **interés por mora** se ajusta en el mismo cobro y el sistema hace la nota correspondiente.

## Cobrador y comisión por cobranza

Si otra persona sale a cobrar, cargala en Clientes → Vendedores con su % de comisión por cobro. En la ficha del cliente se le asigna un cobrador, y en cada recibo el campo **Cobró** dice quién lo cobró: si lo dejás vacío, toma el cobrador del cliente. La comisión por cobranza es de quien cobró. Si el recibo no tiene cobrador, es del vendedor, como antes. La liquidación de comisiones muestra cada recibo y si se cobró como cobrador o como vendedor.

## Cuenta corriente

La ficha del cliente muestra el saldo, las facturas pendientes con su vencimiento y el historial. **Clientes → Cobranzas** lista todos los deudores por antigüedad de la deuda; desde ahí se mandan recordatorios por WhatsApp o mail (uno o todos).

Si un cliente supera el **límite de crédito**, la factura avisa antes de emitir.

## Recordatorios automáticos

En **Configuración → Empresa → Avisos** se activan los recordatorios de vencimientos: el sistema avisa al cliente unos días antes y el día del vencimiento.

## Errores comunes

- **Cobré en la caja equivocada**: anulá el cobro (queda en auditoría) y cargalo de nuevo.
- **El saldo no cierra**: revisá cobros anulados y notas de crédito en el historial; cada movimiento tiene un link al comprobante.
- **El cheque rebotó**: en Fondos → Cheques, "Rechazar": la deuda vuelve a la cuenta corriente del cliente.
