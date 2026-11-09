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

Los precios pactados con cada cliente se cargan en su ficha, pestaña **Precios pactados** (ver *Ventas: pedidos, entregas, lotes, abonos y control*).

## Cuenta corriente

La ficha del cliente muestra el saldo, las facturas pendientes con su vencimiento y el historial. **Clientes → Cobranzas** lista todos los deudores por antigüedad de la deuda; desde ahí se mandan recordatorios por WhatsApp o mail (uno o todos).

Si un cliente supera el **límite de crédito**, la factura avisa antes de emitir.

## Recordatorios automáticos

En **Configuración → Empresa → Avisos** se activan los recordatorios de vencimientos: el sistema avisa al cliente unos días antes y el día del vencimiento.

## Errores comunes

- **Cobré en la caja equivocada**: anulá el cobro (queda en auditoría) y cargalo de nuevo.
- **El saldo no cierra**: revisá cobros anulados y notas de crédito en el historial; cada movimiento tiene un link al comprobante.
- **El cheque rebotó**: en Fondos → Cheques, "Rechazar": la deuda vuelve a la cuenta corriente del cliente.

## Recibos desde las transferencias (Fase 47)

**Desde el comprobante:** en Clientes → Cobranzas, tocá **Recibo desde comprobante** y subí la foto o el PDF de la transferencia que te mandó el cliente. La IA lee el importe, la fecha, quién la mandó, su CUIT y el número de operación. Si el CUIT, el DNI o el nombre completo coinciden con un cliente, ya aparece elegido. Si no, lo elegís vos: nunca se asigna por parecido. El recibo cancela las facturas de la más vieja a la más nueva, y lo que sobra queda a cuenta. Si ese número de operación ya se cargó, te avisa.

**Desde el extracto:** en Contable → Conciliación, en «Está en el banco y no en el sistema», cada transferencia que entró tiene el botón **Recibo a [cliente]** cuando se lo reconoce. Si no se lo reconoce, aparece un selector para elegir el cliente. El recibo queda hecho y conciliado con ese renglón del banco.

**Los dos caminos se cruzan:** si hiciste el recibo desde la foto, cuando importes el extracto ese renglón se concilia solo. También se lo podés pedir a Bigcito: «¿qué transferencias entraron sin recibo?», y después «hacele el recibo».

## Cobranzas con cabeza: riesgo de mora, recordatorios a medida y plan de pago (Fase 57)

- **Riesgo de mora:** en Cobranzas, cada deudor tiene un puntaje de 0 a 100 (bajo, medio, alto) con sus motivos a la vista: cuántos días tarda en pagar en promedio, cuántas veces pagó tarde, si viene empeorando, lo vencido hoy, si pasó su límite o si tiene un plan de pago con cuotas vencidas. No es una caja negra: siempre dice por qué.
- **Para llamar antes de que venza:** los clientes que suelen atrasarse y tienen facturas que vencen en los próximos 7 días. Una llamada ahora ahorra el reclamo después. También por voz: «¿quién tiene riesgo de mora?».
- **Recordatorios escritos a medida** (con la clave de IA): en vez de la misma plantilla para todos, cada aviso se escribe para ese cliente: cordial y liviano con el que siempre paga o en el primer aviso, claro y firme con el reincidente (pidiendo fecha concreta), nombrando todas sus facturas vencidas con el total, y ofreciendo un plan si la deuda es vieja o el riesgo es alto. Siempre con el link para pagar. Se apaga en «Avisos automáticos» y vuelve la plantilla.
- **Plan de pago propuesto:** en Refinanciar, «✦ Sugerir plan según cómo paga» arma las cuotas que el cliente puede pagar (la mitad de lo que te compra por mes), con el interés de su ficha y la primera cuota a 10 o 20 días según el riesgo, y el mensaje listo para mandar por WhatsApp o mail. Cuando el cliente dice que sí, «Usar estos valores» y «Crear plan». Por voz: «proponele un plan a López».

## Cobrar y cobrar mejor con el CRM conectado (Fases 69 y 73)

Si tu empresa usa también el CRM de BigSys (Configuración → CRM):

- **El vendedor ve la cuenta del cliente en el CRM**: saldo, vencido, límite de crédito y riesgo de mora, en vivo desde el ERP, en la ficha del contacto y al lado de la conversación de WhatsApp. No necesita entrar al ERP para saber si le vende a cuenta.
- **Cobrar desde el CRM**: el botón "Registrar cobro en el ERP" del CRM crea el recibo acá, imputado a las facturas, con el medio de pago y la caja o banco. Queda marcado "Cobrado desde el CRM" y dice quién cobró: ese cobro le comisiona a él. Si quien cobra es usuario del ERP pero todavía no tiene ficha de vendedor, el ERP se la crea con 0 % hasta que le cargues el porcentaje.
- **Recordatorio por el CRM**: en Cobranzas, el canal "CRM" deja el recordatorio como tarea de cobranza para el vendedor, que se lo manda al cliente desde la conversación.
- **Lo crítico le llega al vendedor**: un cheque rechazado, una deuda vencida hace más de 30 días o un cliente que pasó su límite de crédito aparecen como tarea en el CRM para el vendedor que lo atiende, sin que nadie se lo tenga que contar.
