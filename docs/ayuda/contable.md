---
titulo: Contabilidad y el contador
modulo: contable
rutas: /contable, /contador, /conjuntos
orden: 7
resumen: Asientos automáticos, libros de IVA, retenciones, SIFERE, exportación al sistema del contador y acceso para el estudio.
---

## Todo se asienta solo

Cada factura, cobro, compra, pago, sueldo y movimiento de fondos genera su asiento en el plan de cuentas. No hay que "pasar" nada. **Contable → Diario, Mayor y Balance** muestran el resultado, y **Estadísticas → Rentabilidad** lo cruza con costos fijos y variables.

## Libros de IVA y fiscal

- **Libro IVA ventas y compras** por período, con exportación al **Libro IVA Digital** (zip para subir a ARCA).
- **Retenciones y percepciones**: certificados emitidos, archivos SICORE y SIRCAR, percepciones de IIBB, IVA y Ganancias.
- **Cruce con "Mis Comprobantes"** de ARCA: subís el archivo y el sistema marca lo que falta de un lado o del otro.

## SIFERE: IIBB que le hicieron a la empresa (Contable → Fiscal)

Para la declaración de Convenio Multilateral. La tabla "IIBB que le hicieron a la empresa" junta las retenciones que te hicieron los clientes al pagar y las percepciones que te cobraron los proveedores en sus facturas. Los botones **SIFERE retenciones** y **SIFERE percepciones** bajan los archivos con el formato de importación de SIFERE WEB.

- **Retenciones**: al registrar el cobro, en el medio "Retención" elegí el impuesto (IIBB), la jurisdicción y el número de certificado.
- **Percepciones**: en la factura de compra, en "Percepción IIBB" elegí la jurisdicción.
- Si falta la jurisdicción, se usa la del cliente o proveedor, y si tampoco tiene, la de Configuración → Impuestos. Lo que no tenga ninguna queda marcado en rojo y no entra al archivo.

Revisá el primer archivo importándolo en SIFERE WEB antes de presentar. Las retenciones de Ganancias, IVA o SUSS que te hacen no van a SIFERE.

## Posición IVA (Contable → Posición IVA, fase 99)

La cuenta del mes como la arma el contador: **débito fiscal** de las ventas (por alícuota) menos **crédito fiscal** de las compras, menos el **saldo técnico a favor** del mes anterior; si da a pagar, se descuenta lo de **libre disponibilidad** (saldo libre anterior más percepciones y retenciones de IVA sufridas). Abajo, lo que se paga y cuándo **vence** (del 18 al 22 del mes siguiente según la terminación del CUIT) o el saldo a favor que **pasa al mes siguiente**, técnico y libre por separado.

- **Cerrar el mes** deja la posición fija y el arrastre al mes siguiente queda hecho. Un mes cerrado no se toca sin **Reabrir**.
- **Ajuste del contador**: para lo que el sistema no sabe (crédito no computable, una diferencia con el libro), con su motivo.
- **Copiar para el contador** arma el texto con toda la cuenta; también se baja en .txt. El botón **Posición IVA del mes** está en Libros IVA.
- Las percepciones de IVA sufridas salen de las facturas de compra (impuesto «Percepción IVA»); las retenciones, de los cobros con medio «retención» e impuesto IVA.

A Bigcito: "¿cuánto IVA pago este mes?", "¿qué saldo a favor tengo?", "pasame la posición de IVA de marzo".

## Indicadores (Contable → Indicadores, fase 99)

Lo que mira el contador antes de hablar con el dueño: doce meses de ventas, compras, gastos, IVA y cobrado, **margen bruto**, **resultado aproximado** (ventas − compras − gastos), gastos sobre ventas, **días de cobro** y **días de pago**, **liquidez** y capital de trabajo, IVA pagado y **presión fiscal**. Arriba, «antes de leer los números»: comprobantes sin asiento, renglones del banco sin conciliar, gastos sin categoría, comprobantes sin CAE y posiciones de IVA sin cerrar, con el link para arreglarlo.

A Bigcito: "¿cómo vienen los indicadores?", "¿cuántos días tardo en cobrar?", "¿qué margen tengo?".

## Conciliación automática y Mercado Pago (Contable → Conciliación, fase 99)

- **Reglas**: «si el renglón del banco dice X, hacé Y»: registrar el gasto en tal categoría, ignorarlo (transferencias entre cuentas propias) o hacerle recibo a tal cliente. Se crean con un clic marcando **Recordar** cuando registrás, ignorás o hacés el recibo a mano, o desde el botón **Reglas**. Actúan solas al importar el extracto o traer Mercado Pago, y se puede **Aplicar a los pendientes** en cualquier momento. Cada regla muestra cuántas veces actuó.
- **Mercado Pago sin extracto**: en la cuenta de billetera, marcá **«Esta es la cuenta de Mercado Pago»** (hace falta tenerlo conectado en Configuración → Mercado Pago). Desde ahí, **Traer de Mercado Pago** trae por API cada cobro aprobado (bruto, que cruza con el cobro del mostrador), su **comisión** (se registra sola como gasto de comisiones) y las **devoluciones** (quedan para mirar). Cada madrugada se traen solos. Lo que no cruza, lo resuelven las reglas o vos.

A Bigcito: "traé los movimientos de Mercado Pago", "¿qué quedó sin conciliar?".

## Panel del contador

**Contable → Contador** junta lo que el estudio pide cada mes: checklist, exportación de asientos a **Tango, Holistor, Bejerman** o CSV, y el alta del contador como usuario con rol de sólo lectura contable.

Si el contador ya usa BigSysWeb con otro cliente, se le da acceso con el **mismo email**: le aparece un selector para cambiar de empresa y una pantalla **Mis empresas** con el IVA del mes, comprobantes sin asiento y pendientes de cada una.

## Cierre de ejercicio

**Contable → Ejercicio**: ajuste por inflación con el índice IPC (se actualiza desde INDEC), asiento de cierre y apertura del ejercicio siguiente. El mes de cierre se configura en Impuestos.

## Centros de costo y presupuesto (Contable → Centros de costo, Fase 96)

Un **centro de costo** es a qué parte del negocio va un gasto: Administración, Depósito, Local Centro, una línea de productos. Los creás una vez y después, al cargar un **gasto en Plata** o una **compra**, elegís el centro (si no elegís, va a «Sin centro»).

- **Presupuesto**: por cada centro cargás cuánto pensás gastar cada mes del año (podés poner un importe y repetirlo en los 12 meses).
- **Real**: sale solo de lo que ya cargás: los egresos de Plata y las compras con ese centro.
- **Desvío**: mes a mes y a la fecha (sólo los meses ya transcurridos), en rojo cuando se pasa más de un 10 %. Arriba, el resumen dice qué centros se pasaron.
- Un centro que ya no se usa se deja **inactivo**: lo imputado antes se sigue viendo.
- A Bigcito: «¿cómo vamos contra el presupuesto?», «¿qué centro se pasó este año?».

## Errores comunes

- **Un comprobante sin asiento**: aparece en el panel del contador; "Regenerar asientos" del período lo resuelve.
- **El balance no cierra**: cada asiento balancea por construcción; si ves diferencia, hay un asiento manual desbalanceado en borrador.

## Conjuntos de empresas (Mis empresas → Conjuntos)

Armá un conjunto con nombre ("Todo el grupo") con las empresas que querés mirar juntas. El tablero del conjunto suma ventas, compras, lo que te deben, lo que debés y lo disponible, y **saca las ventas entre empresas del grupo** (las detecta por el CUIT del cliente), así no se cuentan dos veces. Arriba avisa qué empresa está **atrasada** (sin ventas cargadas hace más de una semana o sin compras hace más de un mes).

## El estudio contable (Mis empresas)

Si atendés clientes, marcá **Somos un estudio contable** y poné el nombre: aparece arriba del panel. Cada empresa tiene un **semáforo** de qué falta en el mes: comprobantes sin CAE (rojo), sueldos sin liquidar (rojo), libros de IVA vacíos, comprobantes esperando en borrador o sin asiento (amarillo). Las rojas van primero.

**Precio progresivo**: configurá los tramos (por ejemplo, hasta 5 empresas a un precio, de la 6 a la 20 a otro) y el panel calcula cuánto cobrás por mes por las empresas que administrás.

**Vencimientos**: la DDJJ de IVA de cada empresa según la terminación de su CUIT, en orden. Los días vienen cargados con el calendario habitual; como ARCA los cambia cada año, se corrigen en "configurar".

## Enviar a la empresa (Fase 45)

En Contable → Mis empresas, cada empresa tiene el botón **Enviar a la empresa**. Usarlo es opcional. Escribís o corregís el mail de la empresa y elegís cómo va: **PDF adjunto** o **Resumen en el mail**. Podés sumar un mensaje. Sale con el nombre y el logo de tu estudio, con las ventas, las compras y el IVA del mes. La próxima vez el mail ya aparece cargado. También se lo podés pedir a Bigcito: «mandale a López el resumen de octubre».

### El informe del mes en castellano (Fase 66)

Al enviar, dejá marcado **"Incluir el informe del mes en castellano"** y tocá **Ver** para la vista previa. Es el mes contado como lo haría el contador: cómo fueron las ventas y las compras y qué cambió contra el mes anterior, la posición de IVA en una línea (débito, crédito, a pagar o a favor, percepciones), los gastos pagados y las categorías más grandes, los sueldos del mes (bruto, neto, contribuciones), lo cobrado, lo vencido por cobrar y lo que se debe a proveedores, la plata en caja, bancos y cheques, y al final **"Qué conviene revisar"**: comprobantes sin CAE, sin asiento, anulados, meses sin compras cargadas, gastos sin categoría. Con clave de IA lo redacta; sin clave, lo arma con los números. Va en el cuerpo del mail y, si elegiste PDF, también en el PDF.

A Bigcito: "¿cómo le fue a Ferretería López en octubre?" o "armame el informe del mes" → te lo lee; "mandáselo" → sale con enviar_resumen_a_empresa.
