# Mercado argentino: percepciones, remito electrónico, cuotas, Mercado Pago presencial y Mercado Envíos

Qué agrega la Fase 21 y cómo se usa. Todo es por empresa y se configura desde **Configuración**.

## Percepciones de IVA y de Ganancias (agente de percepción)

- **Dónde**: Configuración → Impuestos → "Percepción de IVA en ventas (RG 2408)" y "Percepción de Ganancias en ventas". Alícuota, mínimo no percibible y, para IVA, si se aplica solo a responsables inscriptos.
- **A quién**: solo a los clientes marcados en su ficha ("Aplica percepción IVA" / "Aplica percepción Ganancias"). Nunca a monotributistas en Ganancias.
- **Base**: el neto gravado de la factura (no el exento). Se calcula al emitir; en el formulario aparece un aviso.
- **ARCA**: van como tributos en el pedido de CAE, id 6 (percepción IVA) e id 9 (percepción Ganancias); IIBB sigue como id 7. El total informado cierra: neto + exento + IVA + tributos.
- **Impresión y pantalla**: la factura muestra cada percepción con su alícuota. Contable → Fiscal las lista (columna IVA / GAN / jurisdicción) y la exportación de percepciones las incluye con la misma etiqueta.
- **Contabilidad**: se imputan a "percepciones cobradas" como las de IIBB.

## Remito electrónico y COT (ARBA)

- En un remito, el bloque **Transporte** guarda domicilio de entrega, transportista y CUIT, patente, bultos y peso. Sale impreso al pie del remito.
- Desde el remito emitido: **"Descargar archivo para COT (ARBA)"** genera el archivo con el diseño de registro del Remito Electrónico de ARBA (registros 01 encabezado, 02 remito, 03 productos, 04 pie), con el nombre `TB_<cuit>_<pv>_<fecha>_<nro>.txt`. Se sube en la web de ARBA (o por web service) y el **COT** que devuelve se pega en el remito ("Guardar COT"). Queda en auditoría y sale impreso.
- Obligatorio para traslados en Provincia de Buenos Aires que superen los montos vigentes; otras jurisdicciones tienen regímenes similares (el archivo sirve de base).

## Tarjetas y planes de cuotas

- **Dónde**: Configuración → Empresa → "Tarjetas y planes de cuotas". Cada tarjeta tiene planes (cantidad de cuotas y % de recargo; un recargo negativo es descuento). Sin configurar, hay planes de ejemplo.
- **En el punto de venta**: al cobrar con tarjeta se elige tarjeta y cuotas; el importe pasa a incluir el recargo y se muestra el valor de cada cuota. El servidor agrega el recargo como **ítem de la factura** ("Recargo Visa 3 cuotas (10%)"), así el comprobante cierra con lo que paga el cliente y la comisión se ve en rentabilidad. El ticket muestra tarjeta y cuotas; el cobro guarda `datos = {tarjeta, cuotas, recargo}`.
- Con pago mixto se puede combinar efectivo + tarjeta en cuotas; el recargo se calcula sobre lo que va con tarjeta.

## Mercado Pago: QR de mostrador y Point

- **Dónde**: Configuración → Empresa → "Mercado Pago". Access token (cifrado, no se vuelve a mostrar), user id del cobrador, id externo de la caja QR y, para Point, id del dispositivo. El mismo token sirve para los links de pago.
- **QR**: en el POS, medio "MercadoPago" → "QR de mostrador". Se crea la orden en la caja QR (`/instore/orders/qr/seller/collectors/{user}/pos/{caja}/qrs`), se muestra el QR y el POS consulta cada 3 segundos (`merchant_orders/search` por referencia). Al aprobarse, se emite la venta con la referencia del pago.
- **Point**: "Point (lector)" manda el importe en centavos al dispositivo (`/point/integration-api/devices/{id}/payment-intents`) y se consulta el intent hasta `FINISHED`; se puede cancelar.
- Sin credenciales o sin conexión, el POS avisa con un mensaje claro y se puede cobrar por otro medio.

## Mercado Envíos

- Los pedidos que entran de Mercado Libre guardan el envío (`envio_datos`: shipment id, estado, logística).
- En Comprobantes → Pedidos: **"Etiqueta Mercado Envíos"** baja el PDF de la etiqueta (`/shipment_labels`) para pegar en el paquete, y **"Estado del envío"** consulta `/shipments/{id}` y guarda estado y tracking. Si Mercado Envíos lo despachó, el pedido pasa a "enviado"; entregado → "entregado".
- Usa las credenciales del canal Mercado Libre (Configuración → Canales).

## Pruebas

`tests/Feature/MercadoArgentinoTest.php`: percepciones (cálculo, exclusiones, ARCA, pantalla fiscal y export), remito/COT (archivo, descarga, guardado, impresión), cuotas (recargo como ítem, cobro con datos), configuración, Mercado Pago QR y Point (HTTP simulado), Mercado Envíos (alta con envío, etiqueta, estado).
