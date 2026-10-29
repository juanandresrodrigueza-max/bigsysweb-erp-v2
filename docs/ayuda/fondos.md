---
titulo: Caja, bancos, cheques y tarjetas
modulo: fondos
rutas: /fondos
orden: 5
resumen: Turnos de caja, arqueo, cuentas bancarias, conciliación, cheques propios y de terceros, cupones de tarjeta.
---

## Caja

Cada caja tiene **turnos**: se abre con el efectivo inicial, se registran ventas, cobros, pagos y retiros, y se cierra con el **arqueo** (contás y el sistema muestra la diferencia). El cierre queda impreso y auditado. Un cajero sólo ve su caja.

## Cierre de turno con stock final

Para kioscos, almacenes, rotiserías y todo lo que se vende de mostrador: en la ficha del artículo tildá **Contar en el cierre de turno**. Al **Cerrar turno** aparece la planilla **Stock final** con esos artículos y contás lo que queda de cada uno.

- **Inicial**: el stock final contado en el turno anterior de esa caja (o el del sistema si es el primero).
- **Entró**: compras, traslados y ajustes durante el turno.
- **Facturado**: lo que salió por ventas.
- **Salió**: inicial + entró − final contado.
- **Sin facturar**: lo que salió y no está en ninguna venta. Es el faltante de mercadería, en unidades y en pesos.

Abajo se compara **lo que salió por conteo**, a precio de venta, con **lo recaudado en el turno** (efectivo, tarjetas, Mercado Pago, transferencias, cheques, cuenta corriente). Si todo lo que se vende se cuenta, tiene que dar cero. Con **Dejar el stock del sistema igual a lo contado**, el stock queda corregido con un ajuste que referencia al turno. Todo sale en la rendición impresa del turno.

## Bancos

Cada cuenta bancaria tiene sus movimientos (transferencias recibidas, pagos, depósitos de cheques, comisiones). La **conciliación** compara con el extracto del banco: importás el archivo del home banking y marcás lo que coincide.

## Cheques

- **De terceros**: entran con un cobro; en cartera se depositan, se endosan a un proveedor o se rechazan.
- **Propios**: se emiten al pagar a un proveedor; el sistema avisa los que vencen esta semana para que haya fondos.
- **E-cheq**: mismo circuito, marcado como electrónico.

## Tarjetas

Cada cobro con tarjeta es un **cupón** pendiente de liquidación. Cuando llega la liquidación, la registrás con comisiones, retenciones e impuestos, y el neto entra al banco. El costo financiero se ve en **Estadísticas → Rentabilidad**.

En el punto de venta se cobra en **cuotas** con recargo por plan (Configuración → Empresa → Tarjetas).

## Previsiones: lo que se repite todos los meses (Fondos → Previsiones)

Alquiler, seguros, monotributo, cuotas, servicios, un abono que cobrás por fuera: cargalos una vez con el importe, el día del mes y cada cuántos meses (todos los meses, cada 2, 3, 6 o 12). El sistema:

- Te avisa en la campana unos días antes de cada vencimiento (configurás cuántos).
- Los mete en el **cash flow de 13 semanas** en la semana que vencen, como egreso o ingreso previsto.
- Con **Registrar** los pasa a la caja o banco que elijas como gasto o ingreso, y corre al vencimiento siguiente. Si tildás **Registrar solo**, lo hace él el día del vencimiento.
- El calendario de 12 meses muestra cuánto se paga y cuánto entra cada mes.

## Recordatorios para facturar lo recurrente

Los **abonos** (Comprobantes → Abonos) generan la factura de cada cuota solos, todos los días a las 7. Además, la campana avisa tres días antes de cada vencimiento ("Facturar: …") y, si la factura quedó en borrador para revisar, insiste hasta que la emitas. Así nada recurrente queda sin facturar.

## Errores comunes

- **El arqueo no cierra**: revisá los retiros y los cobros en efectivo del turno; el detalle está en el cierre.
- **Transferencia que no aparece en el banco**: puede haberse cargado en otra cuenta; buscala por importe en Fondos → Movimientos.

## Conciliar tarjetas y billeteras (Fondos → Conciliar)

El cupón entra al fondo de la tarjeta cuando cobrás, pero cuando la procesadora acredita nadie registra la liquidación. Acá subís el archivo que te manda la procesadora y el sistema lo cruza contra tus cupones.

**Qué es cada fondo** (a la derecha): marcá qué fondos se concilian, qué procesadora liquida en cada uno (Fiserv, Naranja X, GOcuotas, Mercado Pago, NAVE…), en qué banco deposita y lo pactado (comisión % y plazo en días). Se sugiere por el nombre del fondo.

**Subir**: el Excel o CSV tal como viene. Se reconoce de quién es, se busca el encabezado aunque haya títulos arriba, y se compara con el total del archivo cuando lo trae ("Leí todo"). Si la procesadora cambia el formato, te avisa qué columna falta y cuál es nueva. Payway, Getnet y MODO esperan un archivo real para armar el lector.

**El resultado**, en cuatro grupos:
- **Listo para tildar**: cada operación con su cupón (por importe y fecha a ±3 días; si hay varios iguales, decide el número de cupón). Los cupones ya liquidados aparecen como "ya tildado" y no se cuentan dos veces.
- **Todavía no te pagaron**: cupones del período que no vinieron en el archivo.
- **Comisiones e impuestos**: por cada acreditación, con todas las letras: "salen $X del fondo tarjeta, entran $Y al banco, y $Z son comisión, IVA y retenciones". Si cargaste lo pactado, te dice cuántas vinieron con comisión de más y cuánto, y cuántas se pagaron tarde.
- **A revisar**: lo que el sistema no se animó a decidir, con los candidatos. Nunca asigna a la fuerza: elegís con un clic o marcás "no es de este sistema". Se puede volver atrás.

**Asentar**: la previa muestra cada liquidación en formato contable (banco, comisiones, IVA crédito, retenciones contra cupones a cobrar) con debe = haber. "Ver qué escribiría" corre todo sin grabar y es obligatorio. "Asentar" registra las liquidaciones en una sola operación (todo o nada): los cupones pasan a liquidados y el neto entra al banco. "Deshacer" las anula y los cupones vuelven a cartera.
