---
titulo: Facturar, presupuestar y remitir
modulo: comprobantes
rutas: /comprobantes, /comprobantes/nuevo
orden: 2
resumen: Cómo hacer una factura, un presupuesto o un remito, cargar una factura manual de talonario, qué pasa al emitir y cómo se corrige un error.
---

## La factura paso a paso

1. **Vender → Nueva factura** (o Alt+N).
2. Elegí el **cliente**. La letra sale sola de la condición de IVA de tu empresa y la del cliente (ver **Qué letra lleva cada factura**). Podés dejarlo en Consumidor Final.
3. Cargá los **ítems**: buscá el artículo por nombre o código, escribí la cantidad y Enter. El precio sale de la lista del cliente; se puede cambiar.
4. **Condición**: contado o cuenta corriente. En cuenta corriente, el vencimiento sale de los días de pago del cliente.
5. **Emitir**: pide el CAE a ARCA, descuenta el stock, carga la cuenta corriente y hace el asiento contable, todo junto. **Guardar borrador** lo deja para después.

Atajos: **Ctrl+Enter** emite, **Ctrl+S** guarda el borrador, Enter en cantidad y precio pasa a la fila siguiente. "Repetir la última factura de este cliente" carga sus ítems con los precios de hoy.

### Venta cruzada: qué ofrecer (Fase 63)

Debajo de los ítems aparece **"✦ Sumale:"** con hasta cuatro artículos para ofrecer, un toque y entran a la venta o al presupuesto. Salen de tres lugares, en este orden: lo que **este cliente lleva seguido** y hoy no pidió; lo que **se llevó junto** con lo que ya cargaste en los últimos seis meses ("los que llevan cemento también llevan hierro, 12 veces"); y, si todavía no hay historia, lo que la **IA** elige del catálogo como complemento (el consumible, el accesorio, lo que hace falta para instalarlo). Lo agotado nunca se ofrece. Lo mismo en **Vender → mostrador** ("✦ Ofrecé:") y preguntándole a Bigcito: "¿qué le ofrezco al que lleva un taladro?".

## Qué letra lleva cada factura (fase 102)

Es la regla de ARCA (RG 1415 y RG 5003), y el sistema la aplica solo:

| Vos sos | El cliente es | Sale |
|---|---|---|
| Responsable inscripto | Responsable inscripto | **Factura A** (IVA discriminado) |
| Responsable inscripto | Monotributista (también social o promovido) | **Factura A** con la leyenda de la ley 27.618 (desde julio de 2021 ya no va B) |
| Responsable inscripto | Exento, consumidor final, no categorizado, IVA no alcanzado | **Factura B** (IVA incluido) |
| Monotributista o exento | Cualquiera | **Factura C** (sin IVA) |
| Cualquiera | Del exterior | **Factura E** (exportación) |

Las notas de crédito y débito llevan la misma letra que la factura que corrigen. Lo que ARCA exige y el sistema controla antes de pedir el CAE:

- **Factura A: el cliente tiene que tener CUIT.** Si no lo tiene, cargalo en su ficha (el botón de ARCA lo trae del padrón con su condición).
- **Consumidor final desde $ 10.000.000** (RG 5700/2025): hay que identificarlo con CUIT o DNI. Por debajo puede ir anónimo.
- En cada pedido de CAE va la **condición frente al IVA del receptor** (RG 5616): inscripto, monotributista, exento, consumidor final, no categorizado o IVA no alcanzado. Por eso la condición del cliente tiene que estar bien cargada.

Si el cliente es monotributista y le vendés con A, el IVA va discriminado igual: él no lo computa como crédito salvo por el régimen de la ley 27.618, y el comprobante lo dice al pie.


**Padrón automático (fase 107)**: en la ficha del cliente, al salir del campo CUIT con los 11 dígitos, se consulta el padrón de ARCA solo (si la empresa tiene certificado): completa razón social, condición IVA y domicilio, y te dice si recibe factura A o B. Bigcito hace lo mismo cuando le decís «dá de alta al cliente con CUIT 30-…».

## Cliente nuevo desde la factura

Si el cliente no está cargado, no hace falta salir de la factura: tocá **+ Cliente nuevo o consumidor final con datos** debajo del buscador de clientes y completá nombre, **CUIT o DNI**, condición de IVA, domicilio, email y teléfono. Con un CUIT, el botón **ARCA** trae la razón social, la condición de IVA y el domicilio del padrón.

- **Guardar en Clientes** (viene tildado): al emitir se crea el cliente. Si ya existe con ese CUIT, DNI o nombre, no se duplica: se actualizan sus datos con lo que cargaste.
- Sin tildar: los datos quedan **solo en esa factura** (consumidor final identificado). Salen impresos y a ARCA va el DNI.
- Con condición **Responsable Inscripto** la factura sale **A** sola.

## Presupuestos y remitos

- **Presupuesto**: no mueve nada. Se manda al cliente por link o WhatsApp y desde ahí lo aprueba o rechaza. Aprobado, se convierte en factura o remito con un clic.
- **Remito**: descuenta stock sin facturar. Después se factura uno o varios remitos juntos (Vender → Facturación por lote). Para traslados en Provincia de Buenos Aires, el remito lleva datos de transporte y se pide el **COT** en ARBA desde el mismo remito.
- **Entrega pendiente**: se factura ahora y la mercadería sale después con remitos, en una o varias entregas.
- **Acopio**: el cliente paga ahora y retira en partes; el stock se descuenta con cada retiro.

### Presupuestos que vienen del CRM (Fases 69 a 74)

Con el CRM de BigSys conectado, el vendedor presupuesta desde el CRM viendo **stock y precio de la lista del cliente** en vivo. Cuando el cliente acepta, el presupuesto **nace acá** con sus artículos (emparejados por el id del ERP o por el código), su número y, según lo que elijas en Configuración → CRM, como presupuesto o como factura en borrador. La factura que emitís después vuelve al CRM con su número, total y vendedor, y aparece en la ficha del contacto.

## Notas de crédito y débito

Desde la factura, **Convertir → Nota de crédito** la anula total o parcialmente (ARCA exige asociarla a la factura original, el sistema lo hace solo). La nota de débito suma intereses o diferencias.

## Factura electrónica sin certificado: BigSys factura por vos (fase 121)

Igual que en el CRM, **no hace falta que generes ni subas ningún certificado**. BigSys tiene uno propio y factura en nombre de tu empresa, con tu CUIT, tu punto de venta y tu numeración. Vos sólo lo autorizás una vez en ARCA.

En **Configuración → Puntos de venta y ARCA** está la guía con los cuatro pasos:

1. **Datos fiscales**: el CUIT de la empresa cargado en Configuración → Empresa.
2. **Punto de venta para Web Services**: en ARCA → «Administración de puntos de venta y domicilios» → Agregar, y en «Sistema» elegí **«RECE para aplicativo y web services»**. El de «Comprobantes en línea» (el de facturar a mano en la web de ARCA) **no sirve**.
3. **Autorizá a BigSys**: en ARCA, con clave fiscal nivel 3 → **«Administrador de Relaciones de Clave Fiscal»** → **Nueva relación** → Buscar → ARCA → WebServices → **«Facturación Electrónica»** → en Representante poné el CUIT de BigSys que muestra la pantalla (tocalo para copiarlo) → Confirmar.
4. **Verificá y activá**: el botón **Verificar en ARCA** le pregunta a ARCA si los dos trámites quedaron bien (no emite ni reserva nada) y te dice qué puntos de venta tenés habilitados. Si alguno está en ARCA y no en el sistema, lo agregás con un toque. Cuando ARCA confirma la autorización, **el dueño de la cuenta** lee y tilda el texto de autorización (queda registrado quién, cuándo y con qué texto) y toca **Activar la facturación por BigSys**.

Listo: desde ahí las facturas salen con CAE. **Probar conexión con ARCA** hace una consulta de punta a punta sin emitir nada.

Lo que conviene saber:
- Si ya facturabas por web services con otro sistema y tenés tu certificado, podés seguir usándolo: está en «Tengo mi propio certificado (avanzado)». Si cargás uno, se usa ese.
- La **factura de exportación (E)** y la **consulta de padrón** para completar fichas por CUIT por ahora necesitan certificado propio.
- Si una **sucursal tiene CUIT propio**, ese CUIT hace su propia autorización en ARCA (entrando con su clave fiscal) y se activa en la misma pantalla, en «Sucursales con CUIT propio».
- Si la apagás (o das de baja la empresa), el sistema te muestra cómo revocar también la relación en ARCA.
- Si un día revocás la autorización en ARCA, ARCA rechaza las facturas con el aviso «todavía no autorizó a BigSys a facturar en su nombre».

## Si ARCA no responde

La factura queda **pendiente de CAE**: ya impactó en cuenta corriente y stock, y el sistema reintenta solo cada cinco minutos. No es válida como factura hasta tener CAE, y así lo dice el impreso.

## Factura manual de talonario

Para cargar una factura que ya se hizo en papel: un talonario preimpreso con CAI, o la factura manual de respaldo del día que ARCA no respondió por horas.

1. En la factura nueva tildá **Manual de talonario**.
2. Cargá el punto de venta y el número que figuran en el papel, y la fecha del papel.
3. Si el talonario tiene CAI, cargá los 14 dígitos y su vencimiento. El sistema no deja cargar una factura con fecha posterior al vencimiento del CAI.
4. Emití. No se pide CAE: entra en el libro de IVA, la cuenta corriente, el stock y la contabilidad igual que una electrónica.

Solo la cargan el dueño y los administradores. El sistema no deja repetir el mismo tipo, punto de venta y número. Si la cargaste por error, se da de baja desde el mismo comprobante. La numeración electrónica no se toca.

## Comprobante interno (fase 103)

Para la venta que querés registrar completa (stock, cuenta corriente, caja y valores) pero **sin informar a ARCA**: lo que en BigSys era la «E». Se llama **comprobante interno**: numeración propia por punto de venta, letra X, sin CAE, y el papel dice «no válido como factura».

- **Cómo se hace**: en la factura nueva, destildá **Informar a ARCA**; en el mostrador, tildá **Comprobante interno** al cobrar. Si a un cliente siempre se le hace así, marcá **Comprobante interno por defecto** en su ficha y sale solo (se puede cambiar en cada venta).
- **Qué mueve**: el stock, la cuenta corriente del cliente, los cobros con todos los medios (efectivo, transferencia, cheques, tarjeta) y las estadísticas de venta. Las notas de crédito sobre un interno también son internas.
- **Qué no toca**: no pide CAE, no entra en los libros de IVA ni en la posición de IVA, no genera asiento contable y no aparece en el panel, el informe ni las exportaciones del contador. Los recibos que sólo cancelan internos tampoco van a la contabilidad (si un recibo mezcla internos y fiscales, va entero).
- **Dónde se ven**: en Vender → Comprobantes, el filtro **Sólo internos / Sólo fiscales** y la etiqueta «Interno» en cada uno. Los números de la numeración fiscal no se gastan.
- **Facturar después** (fase 104): desde el comprobante interno, el botón **Facturar (pasar a fiscal)** le pide el CAE a ARCA con la fecha de hoy y le da número fiscal. El stock, la cuenta corriente y los cobros que ya tenía quedan como están; recién ahí entra en los libros de IVA y en la contabilidad, con sus recibos. El número interno queda anotado en las notas. No se puede deshacer (después, sólo con nota de crédito). Si el interno tiene notas de crédito, o al cliente hoy le corresponde otra letra, hay que hacer la factura a mano.
- **Aviso al cobrar con medio electrónico** (fase 104): si un interno se cobra con tarjeta, transferencia, Mercado Pago o billetera, en el mostrador y en el recibo aparece el aviso de que conviene facturarlo: ARCA recibe esos movimientos de bancos, tarjetas y billeteras.

**La forma de no tener problemas con ARCA**: todo lo que se cobra por un medio electrónico, factura fiscal; el interno, sólo para lo que no es una venta a un tercero que exija factura (presupuestos, remitos, consumos internos, muestras, control). Entregar un «no válido como factura» en lugar de la factura es la causal típica de multa y clausura.

A Bigcito: "hacele un comprobante interno a Pérez por 10 bolsas de cemento", "¿cuánto vendí este mes, fiscal e interno?".

## Errores comunes

- **"ARCA rechazó el comprobante"**: el mensaje explica qué revisar (CUIT del cliente, condición de IVA, fecha, punto de venta). Corregí y volvé a emitir.
- **"La factura A exige el CUIT del cliente"**: el cliente es inscripto o monotributista y no tiene CUIT cargado. Ficha del cliente → CUIT (o traelo de ARCA).
- **"Hay que identificar al consumidor final"**: la venta supera el tope de la RG 5700. Cargá el DNI o el CUIT del comprador.
- **Me equivoqué en una factura emitida**: no se edita; se anula con nota de crédito y se hace de nuevo. Un borrador sí se edita.
- **El cliente no aparece**: escribí parte del nombre o el CUIT en el buscador; si no existe, "+ Crear cliente nuevo".

## Percepciones

Si sos agente de percepción (IIBB, IVA, Ganancias), se configura en **Configuración → Impuestos** y se marca en cada cliente. La factura las calcula sola sobre el neto gravado y las informa a ARCA.
