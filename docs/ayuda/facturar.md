---
titulo: Facturar, presupuestar y remitir
modulo: comprobantes
rutas: /comprobantes
orden: 2
resumen: Cómo hacer una factura, un presupuesto o un remito, qué pasa al emitir y cómo se corrige un error.
---

## La factura paso a paso

1. **Comprobantes → Nueva factura** (o Alt+N).
2. Elegí el **cliente**. Si es responsable inscripto sale Factura A; si no, B (o C si vos sos monotributista). Podés dejarlo en Consumidor Final.
3. Cargá los **ítems**: buscá el artículo por nombre o código, escribí la cantidad y Enter. El precio sale de la lista del cliente; se puede cambiar.
4. **Condición**: contado o cuenta corriente. En cuenta corriente, el vencimiento sale de los días de pago del cliente.
5. **Emitir**: pide el CAE a ARCA, descuenta el stock, carga la cuenta corriente y hace el asiento contable, todo junto. **Guardar borrador** lo deja para después.

Atajos: **Ctrl+Enter** emite, **Ctrl+S** guarda el borrador, Enter en cantidad y precio pasa a la fila siguiente. "Repetir la última factura de este cliente" carga sus ítems con los precios de hoy.

## Presupuestos y remitos

- **Presupuesto**: no mueve nada. Se manda al cliente por link o WhatsApp y desde ahí lo aprueba o rechaza. Aprobado, se convierte en factura o remito con un clic.
- **Remito**: descuenta stock sin facturar. Después se factura uno o varios remitos juntos (Comprobantes → Facturar en lote). Para traslados en Provincia de Buenos Aires, el remito lleva datos de transporte y se pide el **COT** en ARBA desde el mismo remito.
- **Entrega pendiente**: se factura ahora y la mercadería sale después con remitos, en una o varias entregas.
- **Acopio**: el cliente paga ahora y retira en partes; el stock se descuenta con cada retiro.

## Notas de crédito y débito

Desde la factura, **Convertir → Nota de crédito** la anula total o parcialmente (ARCA exige asociarla a la factura original, el sistema lo hace solo). La nota de débito suma intereses o diferencias.

## Si ARCA no responde

La factura queda **pendiente de CAE**: ya impactó en cuenta corriente y stock, y el sistema reintenta solo cada cinco minutos. No es válida como factura hasta tener CAE, y así lo dice el impreso.

## Errores comunes

- **"ARCA rechazó el comprobante"**: el mensaje explica qué revisar (CUIT del cliente, condición de IVA, fecha, punto de venta). Corregí y volvé a emitir.
- **Me equivoqué en una factura emitida**: no se edita; se anula con nota de crédito y se hace de nuevo. Un borrador sí se edita.
- **El cliente no aparece**: escribí parte del nombre o el CUIT en el buscador; si no existe, "+ Crear cliente nuevo".

## Percepciones

Si sos agente de percepción (IIBB, IVA, Ganancias), se configura en **Configuración → Impuestos** y se marca en cada cliente. La factura las calcula sola sobre el neto gravado y las informa a ARCA.
