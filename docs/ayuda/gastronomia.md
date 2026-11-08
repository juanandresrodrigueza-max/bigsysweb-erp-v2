---
titulo: Big Restó: salón, cocina, reservas y menú QR
modulo: gastronomia
rutas: /gastronomia
orden: 6
resumen: Mesas y comandas, pantalla de cocina, cuenta y cobro, reservas por la web y pedidos desde el QR de la mesa.
---

**Big Restó** es el sistema de gastronomía de BigSys web ERP, para bares, restaurantes, cafeterías y rotiserías. Vive en el menú como «Big Restó» y usa el mismo stock, la misma caja, la misma facturación y la misma contabilidad que el resto del sistema.


## Salón

**Big Restó → Salón** muestra las mesas; tocás una para abrirla o ver su comanda. Se cargan los ítems (con observaciones: "sin cebolla"), se **envía a cocina** y se pueden mover comensales de mesa o juntar mesas.

## Cocina

**Cocina** es la pantalla para el sector: las comandas entran en orden, cada ítem se marca en preparación y listo, y el mozo lo ve en el salón. Se actualiza sola.

## Cuenta y cobro

**Cuenta**: imprime el detalle; **Cerrar**: cobra (efectivo, tarjeta, Mercado Pago, mixto) y emite el ticket o la factura. Se puede dividir la cuenta.

## Reservas

**Reservas** carga las que llegan por teléfono y muestra las que entran por la web (link de reservas de tu tienda). Recordatorio por WhatsApp el día anterior.

## Menú QR

Cada mesa tiene su **código QR**: el cliente lo escanea, ve el menú y pide desde el celular. El pedido entra directo a la comanda de esa mesa.

## Stock y recetas

Los platos pueden tener **fórmula** (ver Producción): al vender, se descuentan los insumos y se calcula el costo del plato.

## Opciones de cada plato y dividir la cuenta (Fase 49)

**Carta (Big Restó → Carta):** para cada plato elegís si va a cocina y armás sus opciones en grupos. Hay modelos listos para Punto, Guarnición, Extras y Sin. Un grupo puede ser **obligatorio**, y entonces el mozo no puede cargar el plato sin elegir, o de **varias opciones**. Cada opción puede tener precio extra, por ejemplo «+ Queso» a $ 1.200. Cuando el mozo toca el plato, elige las opciones: el precio se suma solo y la cocina las ve en la comanda.

**Dividir la cuenta:** al cobrar, elegís cómo:
- **Todo junto:** como siempre.
- **Cada uno lo suyo:** marcás qué ítems paga esa persona; se puede pagar una parte de un ítem, como media pizza.
- **Partes iguales:** dividís lo que falta en N partes.

Cada parte sale con su factura y su cobro, con la propina que deje esa persona, y la mesa queda abierta mostrando «Falta cobrar». Cuando se paga lo último, la mesa se cierra sola.

## Estaciones de cocina (Fase 50)

En Big Restó → Carta armás las **estaciones** (por ejemplo Cocina, Parrilla, Barra y Postres) y para cada una ponés a los cuántos minutos avisa. A cada plato le elegís a qué estación va. En la pantalla de cocina, arriba, elegís la estación: cada una ve solo lo suyo, con el tiempo de cada plato. Cuando un plato pasa los minutos de su estación se pone en rojo y dice «demorado». Al lado de cada estación figura el tiempo promedio de hoy. Desde la comanda, **Imprimir: Parrilla / Barra…** saca el ticket de 80 mm de esa estación.

## Carta QR: opciones, llamar al mozo y pedir la cuenta (Fase 51)

El QR de cada mesa, que se imprime en Big Restó → Reservas y QR, abre la carta en el celular del cliente:
- **Opciones del plato:** si un plato tiene opciones (el punto, la guarnición, los extras), el cliente las elige igual que el mozo, con las obligatorias y los precios extra.
- **Estaciones:** el pedido va a la estación de cada plato.
- **Llamar al mozo y pedir la cuenta:** cada uno tiene su botón. En el salón, la mesa muestra «🙋 Llama al mozo» o «🧾 Pide la cuenta», con los minutos que lleva esperando, y además llega un aviso. Cuando el mozo abre la comanda, el llamado se borra.
- **Confirmación del mozo:** en Big Restó → Carta podés tildar que los pedidos del QR los confirme el mozo antes de ir a cocina. Así quedan «sin enviar» hasta que él los mande.

## Happy hour, menú del día y números del salón (Fase 52)

- **Happy hour:** en Big Restó → Carta armás franjas con su nombre, el % de descuento, el horario (puede cruzar la medianoche), los días y los rubros. Por ejemplo: Happy hour, 50 % en Tragos, de lunes a viernes de 18 a 20. En esa franja el precio baja solo, en el salón y en el QR, y la comanda lo marca «[Happy hour]».
- **Menú del día y combos:** en las opciones del plato tildás «Sólo se sirve en un horario» (por ejemplo, de lunes a viernes de 12 a 16). Fuera de ese horario no aparece. Para un combo de entrada, principal y postre, armá un grupo obligatorio por paso.
- **Estadísticas → Big Restó:** lo vendido, las mesas, el ticket promedio, los cubiertos, lo vendido por cubierto, las propinas y el tiempo de permanencia. Además, la venta por mozo, a qué hora se vende, salón contra mostrador o delivery, los platos más pedidos y los tiempos de cocina por estación. A Bigcito se lo podés preguntar: «¿qué plato sale más?», «¿cuánto tarda la parrilla?».

## Big Restó para cafeterías: recetas, costo por plato y llamador (Fase 53)

- **Cada venta descuenta la receta:** en Big Restó → Carta, cada plato tiene su **Receta** (lo que lleva una porción: 18 g de café, 200 ml de leche, un vaso). Con «Cada venta descuenta los insumos de la receta» activado, cada café vendido baja esos insumos del stock, y una nota de crédito los devuelve. Viene activado para los locales gastronómicos; se apaga ahí mismo.
- **Receta sugerida con IA:** en la receta, «✦ Sugerir con IA» la arma con los insumos que ya tenés cargados y te dice qué falta cargar. Vos la ajustás y la guardás. Necesita la clave de IA de la empresa.
- **Costo y margen por plato:** la carta muestra el costo (de la receta si tiene), el margen sobre el precio neto y el **margen objetivo** que le pongas. Cuando una compra sube el costo de un insumo y un plato queda por debajo de su objetivo, llega una alerta: «Café subió 100 %: Café con leche queda con 66 % de margen (objetivo 80 %) · precio sugerido $ X».
- **Llamador (Big Restó → Llamador):** la pantalla para la tele del local con los pedidos de mostrador «Preparando» y «Listos para retirar», con el número grande. Cuando el último plato queda listo, al cliente le llega un WhatsApp si dejó su número. «Lo retiró» lo saca de la pantalla.

## IA en Big Restó: carta desde una foto, dictar la comanda y pedidos por WhatsApp (Fase 53)

- **Cargar la carta desde una foto:** en Big Restó → Carta, «📷 Cargar carta desde foto». Sacale una foto a la carta, la pizarra o subí el PDF: Bigcito arma los rubros, los platos, los precios y las opciones (tamaños, variantes). Lo revisás en una vista previa, destildás lo que no quieras, y se carga. Lo que ya existía en tu carta no se duplica: solo se le actualiza el precio. Después, pasá por las recetas y por qué va a cocina.
- **El mozo le dicta a Bigcito:** «mesa 4, dos bifes jugosos con papas y una coca sin hielo». Bigcito cruza las opciones con la carta del plato, te muestra lo entendido para confirmar y lo manda a cocina. Si falta una opción obligatoria (el punto de la carne), te la pregunta en vez de inventarla. Si la mesa ya está abierta, agrega a esa comanda.
- **Pedidos por WhatsApp directo a Big Restó:** con «Los pedidos por WhatsApp entran directo como comanda» activado (viene así en los locales gastronómicos), el cliente escribe como habla y entra una comanda de delivery o para retirar con las opciones de cada plato. Le contesta con el número de pedido, y cuando el pedido queda listo le avisa solo. Si falta una opción obligatoria, se la pregunta por WhatsApp y no carga el plato hasta que conteste. En el salón llega el aviso «Pedido por WhatsApp». Necesita la clave de IA y el WhatsApp configurados.

## Big Restó se adelanta: la semana que viene, qué comprar, menú del día y picos de cocina (Fase 55)

- **La semana que viene** (Estadísticas → Big Restó): cuántos platos se esperan cada día, según lo que vendés cada día de la semana en las últimas 8 semanas, y cuáles son los que más salen ese día.
- **Qué comprar para cubrirla:** pasa ese pronóstico por las recetas, lo resta del stock y te dice qué insumos faltan, agrupados por proveedor con el costo estimado. A Bigcito: «¿qué tengo que comprar para la semana?».
- **Menú del día sugerido** (Big Restó → Carta → «✦ Menú del día»): junta lo que está por vencer en 5 días y lo que está quieto hace 14 días, te dice qué platos de la carta ya lo usan y, con la clave de IA, propone tres menús con el texto para la pizarra. Lo copiás y lo creás como plato con horario.
- **Picos de cocina:** con lo que mandaste a cocina por hora en las últimas 4 semanas, la pantalla de cocina avisa si la próxima hora viene pesada en alguna estación (1,5 veces lo normal y al menos 8 platos), y llega una alerta para adelantar la mise en place.
