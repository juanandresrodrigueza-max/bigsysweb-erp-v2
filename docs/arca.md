# BigSysWeb ERP · Factura electrónica ARCA (ex AFIP)

Cómo dejar una empresa facturando de verdad, qué prueba el sistema y qué hacer cuando ARCA no responde.

## Pasos para homologar una empresa

1. **Certificado**: en ARCA con clave fiscal → *Administrador de Relaciones de Clave Fiscal* → adherir el servicio **WSFE - Facturación Electrónica** (y opcionalmente **ws_sr_padron_a13** y **a5** para la consulta de padrón). Generar el certificado con el CSR (`openssl req -new -key private.key -subj "/C=AR/O=Empresa/CN=bigsysweb/serialNumber=CUIT 30xxxxxxxxx" -out pedido.csr`) y descargar el `.crt`.
2. **Cargarlo**: Configuración → Puntos de venta → *Factura electrónica*: subir `.crt` y `.key`. Quedan cifrados en el servidor.
3. **Modo**: Configuración → Empresa → *Modo producción* desmarcado = homologación (servidores `wsaahomo` / `wswhomo`), marcado = producción. En homologación hay que usar un certificado generado en el entorno de homologación de ARCA.
4. **Punto de venta**: darlo de alta en ARCA como *Web Services* con el mismo número que en el ERP (modo electrónico).
5. **Probar conexión**: botón *Probar conexión con ARCA*. Verifica los servidores WSFE, el último número autorizado por punto de venta y tipo (A/B o C según la empresa) y la consulta de padrón del propio CUIT. Cada problema viene explicado con qué hacer.
6. **Comprobantes de prueba** en homologación (lo que ARCA suele pedir para habilitar producción): factura A a un responsable inscripto, factura B a consumidor final (sin CUIT) y a un monotributista, nota de crédito A asociada a la factura, nota de débito, y una factura con servicios (concepto 2/3). Todo eso lo genera el ERP con los datos que hoy exige ARCA.
7. **Pasar a producción**: certificado de producción, marcar *Modo producción*, probar conexión de nuevo, emitir la primera factura real y verificarla con *Verificar en ARCA*.

## Qué manda el ERP en cada pedido de CAE

- Condición frente al IVA del receptor (**RG 5616**, obligatoria desde 2025): responsable inscripto 1, exento 4, consumidor final 5, monotributista 6, no responsable/no categorizado 7.
- Concepto 1 (productos), 2 (servicios) o 3 (ambos) según los ítems; con servicios, período del mes y fecha de vencimiento de pago.
- IVA por alícuota (21, 10,5, 27, 5, 2,5) con base e importe; **el neto y el IVA informados se recalculan desde las bases por alícuota para que los importes cierren al centavo**.
- Tributos: percepciones de ingresos brutos como tributo 7 con base, alícuota e importe.
- Factura C (monotributista): sin IVA discriminado, total completo en neto.
- Notas de crédito/débito: comprobante asociado (tipo, punto de venta, número, CUIT emisor, fecha) o, si no tienen origen, el período asociado (ARCA exige uno de los dos).
- FCE MiPyME: CBU (opcional 2101), SCA (27) y fecha de vencimiento de pago; en notas, opcional 22 = N.
- Moneda: se informa siempre en pesos (MonId PES). Las facturas en dólares se guardan y se imprimen con el equivalente en pesos a la cotización del día.
- **QR** de la RG 4892 en la factura, el ticket y el PDF que se envía: URL `https://www.afip.gob.ar/fe/qr/?p=` con el JSON en base64 (versión, fecha, CUIT, punto de venta, tipo, número, importe, moneda, cotización, tipo y número de documento del receptor, tipo de autorización E y CAE).

## Contingencia: ARCA no responde

- Si al emitir falla la conexión (no un rechazo), el comprobante sale **emitido y pendiente de CAE**: impacta cuenta corriente y stock, se puede imprimir con la leyenda "Pendiente de CAE, no válido como factura" y aparece marcado en rojo en la lista.
- Cada 5 minutos `afip:reintentar` vuelve a pedir el CAE; también hay un botón *Reintentar ahora* en el comprobante. Al autorizarse recibe su número fiscal (el que ARCA asigna en ese momento) y el CAE.
- Si pasa más de una hora pendiente, se genera un aviso para el dueño.
- Si ARCA responde que el número ya existe (la conexión se cortó después de autorizar), el ERP consulta ese comprobante en ARCA y adopta el CAE siempre que el total coincida.
- Alternativa legal para cortes largos: cambiar el punto de venta a *manual / talonario* y facturar con comprobantes preimpresos (o usar CAEA, que el ERP no gestiona).

## Errores típicos, traducidos

El ERP traduce los códigos de ARCA a una explicación y una acción (`App\Services\Afip\AfipErrores`):

| Situación | Qué pasa | Qué hacer |
|---|---|---|
| 10016 / número ya registrado | Otro sistema o un corte dejó el número usado | El ERP adopta el CAE de ARCA; revisar que nadie más facture en ese punto de venta |
| 10015 / fecha | Fecha fuera del rango permitido | Corregir la fecha del comprobante |
| 10048 / DocNro | CUIT/DNI del cliente inválido | Verificar en la ficha o consultar el padrón |
| CondicionIVAReceptorId | Falta la condición IVA del receptor | Revisar la condición de IVA del cliente |
| Importes no coinciden | Redondeo | Reabrir como borrador y volver a emitir |
| PtoVta | Punto de venta no habilitado en ARCA | Darlo de alta como Web Services |
| cms.cert.expired | Certificado vencido | Generar y cargar uno nuevo |
| no autorizado | Certificado sin el servicio WSFE | Asociarlo en Administrador de Relaciones |
| Could not connect / timeout | ARCA caído o sin internet | Queda pendiente y se reintenta solo |

## Verificaciones

- *Verificar en ARCA* en cada comprobante aprobado: consulta el comprobante en ARCA y compara CAE y total.
- Consulta de padrón desde la ficha del cliente (botón *Padrón*): trae razón social, domicilio fiscal y condición frente al IVA. Requiere el servicio de padrón autorizado para el certificado.
- Validación del dígito verificador del CUIT al guardar clientes.

## Lo que todavía no hace

- Factura E de exportación (WSFEX): se emite sin CAE. Si una empresa exporta, hay que integrar WSFEX.
- CAEA (autorización anticipada para contingencia larga).
- Comprobantes en moneda extranjera informados en esa moneda (se informan en pesos).
