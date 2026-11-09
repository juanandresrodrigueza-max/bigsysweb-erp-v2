---
titulo: Facturar, presupuestar y remitir
modulo: comprobantes
rutas: /comprobantes, /comprobantes/nuevo
orden: 2
resumen: Cómo hacer una factura, un presupuesto o un remito, cargar una factura manual de talonario, qué pasa al emitir y cómo se corrige un error.
---

## La factura paso a paso

1. **Comprobantes → Nueva factura** (o Alt+N).
2. Elegí el **cliente**. Si es responsable inscripto sale Factura A; si no, B (o C si vos sos monotributista). Podés dejarlo en Consumidor Final.
3. Cargá los **ítems**: buscá el artículo por nombre o código, escribí la cantidad y Enter. El precio sale de la lista del cliente; se puede cambiar.
4. **Condición**: contado o cuenta corriente. En cuenta corriente, el vencimiento sale de los días de pago del cliente.
5. **Emitir**: pide el CAE a ARCA, descuenta el stock, carga la cuenta corriente y hace el asiento contable, todo junto. **Guardar borrador** lo deja para después.

Atajos: **Ctrl+Enter** emite, **Ctrl+S** guarda el borrador, Enter en cantidad y precio pasa a la fila siguiente. "Repetir la última factura de este cliente" carga sus ítems con los precios de hoy.

### Venta cruzada: qué ofrecer (Fase 63)

Debajo de los ítems aparece **"✦ Sumale:"** con hasta cuatro artículos para ofrecer, un toque y entran a la venta o al presupuesto. Salen de tres lugares, en este orden: lo que **este cliente lleva seguido** y hoy no pidió; lo que **se llevó junto** con lo que ya cargaste en los últimos seis meses ("los que llevan cemento también llevan hierro, 12 veces"); y, si todavía no hay historia, lo que la **IA** elige del catálogo como complemento (el consumible, el accesorio, lo que hace falta para instalarlo). Lo agotado nunca se ofrece. Lo mismo en **Vender → mostrador** ("✦ Ofrecé:") y preguntándole a Bigcito: "¿qué le ofrezco al que lleva un taladro?".

## Cliente nuevo desde la factura

Si el cliente no está cargado, no hace falta salir de la factura: tocá **+ Cliente nuevo o consumidor final con datos** debajo del buscador de clientes y completá nombre, **CUIT o DNI**, condición de IVA, domicilio, email y teléfono. Con un CUIT, el botón **ARCA** trae la razón social, la condición de IVA y el domicilio del padrón.

- **Guardar en Clientes** (viene tildado): al emitir se crea el cliente. Si ya existe con ese CUIT, DNI o nombre, no se duplica: se actualizan sus datos con lo que cargaste.
- Sin tildar: los datos quedan **solo en esa factura** (consumidor final identificado). Salen impresos y a ARCA va el DNI.
- Con condición **Responsable Inscripto** la factura sale **A** sola.

## Presupuestos y remitos

- **Presupuesto**: no mueve nada. Se manda al cliente por link o WhatsApp y desde ahí lo aprueba o rechaza. Aprobado, se convierte en factura o remito con un clic.
- **Remito**: descuenta stock sin facturar. Después se factura uno o varios remitos juntos (Comprobantes → Facturar en lote). Para traslados en Provincia de Buenos Aires, el remito lleva datos de transporte y se pide el **COT** en ARBA desde el mismo remito.
- **Entrega pendiente**: se factura ahora y la mercadería sale después con remitos, en una o varias entregas.
- **Acopio**: el cliente paga ahora y retira en partes; el stock se descuenta con cada retiro.

### Presupuestos que vienen del CRM (Fases 69 a 74)

Con el CRM de BigSys conectado, el vendedor presupuesta desde el CRM viendo **stock y precio de la lista del cliente** en vivo. Cuando el cliente acepta, el presupuesto **nace acá** con sus artículos (emparejados por el id del ERP o por el código), su número y, según lo que elijas en Configuración → CRM, como presupuesto o como factura en borrador. La factura que emitís después vuelve al CRM con su número, total y vendedor, y aparece en la ficha del contacto.

## Notas de crédito y débito

Desde la factura, **Convertir → Nota de crédito** la anula total o parcialmente (ARCA exige asociarla a la factura original, el sistema lo hace solo). La nota de débito suma intereses o diferencias.

## Si ARCA no responde

La factura queda **pendiente de CAE**: ya impactó en cuenta corriente y stock, y el sistema reintenta solo cada cinco minutos. No es válida como factura hasta tener CAE, y así lo dice el impreso.

## Factura manual de talonario

Para cargar una factura que ya se hizo en papel: un talonario preimpreso con CAI, o la factura manual de respaldo del día que ARCA no respondió por horas.

1. En la factura nueva tildá **Manual de talonario**.
2. Cargá el punto de venta y el número que figuran en el papel, y la fecha del papel.
3. Si el talonario tiene CAI, cargá los 14 dígitos y su vencimiento. El sistema no deja cargar una factura con fecha posterior al vencimiento del CAI.
4. Emití. No se pide CAE: entra en el libro de IVA, la cuenta corriente, el stock y la contabilidad igual que una electrónica.

Solo la cargan el dueño y los administradores. El sistema no deja repetir el mismo tipo, punto de venta y número. Si la cargaste por error, se da de baja desde el mismo comprobante. La numeración electrónica no se toca.

## Errores comunes

- **"ARCA rechazó el comprobante"**: el mensaje explica qué revisar (CUIT del cliente, condición de IVA, fecha, punto de venta). Corregí y volvé a emitir.
- **Me equivoqué en una factura emitida**: no se edita; se anula con nota de crédito y se hace de nuevo. Un borrador sí se edita.
- **El cliente no aparece**: escribí parte del nombre o el CUIT en el buscador; si no existe, "+ Crear cliente nuevo".

## Percepciones

Si sos agente de percepción (IIBB, IVA, Ganancias), se configura en **Configuración → Impuestos** y se marca en cada cliente. La factura las calcula sola sobre el neto gravado y las informa a ARCA.
