---
titulo: Stock, artículos y precios
modulo: stock
rutas: /stock
orden: 4
resumen: Artículos, listas de precios, catálogos para clientes, movimientos de stock, mínimos, compras a proveedores y actualización de costos.
---

## Artículos

Cada artículo tiene código (SKU), código de barras, rubro, unidad, IVA, costo y hasta seis **listas de precios**. El precio puede ser un margen sobre el costo: al subir el costo, sube el precio. Los artículos con **stock controlado** avisan cuando bajan del mínimo.

Tipos: **producto** (mueve stock), **servicio** (no mueve stock), **insumo** (para producción) y **combo/kit**.

## Centro de etiquetas (Stock → Etiquetas)

Todas las etiquetas en un lugar, en hoja A4 (65, 24 u 8 por hoja), en rollo para impresora de etiquetas o en **ZPL** para impresoras **Zebra** (Bajar ZPL y mandarlo a la impresora).

- **Diseños**: estándar (nombre, código y precio); **góndola** con el precio grande y el **precio por kilo o por litro** que pide la ley de góndolas (cargá el **contenido neto** del envase en la ficha del artículo: 500 g, 1,5 l…); **oferta** con el precio anterior tachado, el precio nuevo y el % de descuento; **lote y vencimiento**; **número de serie** (con el código de barras de la serie, para escanear en la caja).
- **De dónde salen**: buscás artículos o un rubro y elegís la lista de precios; **Precios cambiados (7 días)** trae lo que cambió de precio para renovar la góndola; desde una **compra registrada** (botón Etiquetas) sale una por unidad, con su lote o su serie; desde **Lotes** y **Números de serie** imprimís la del lote o la del equipo.
- **Con el color de la empresa** usa el color de la marca de Configuración → Empresa.
- El formato que elegís queda guardado en ese navegador.

## Carnicería y pesables (Stock → Carnicería y pesables)

Para carnicerías, fiambrerías, verdulerías, panaderías y todo lo que se vende por peso.

- **Pesables**: en la ficha del artículo tildá **Pesable (balanza)** y poné su **PLU** (el número que se carga en la balanza) y a los cuántos días vence. **Exportar PLU a la balanza** baja el archivo con PLU, nombre, precio por kg con IVA y vencimiento, para cargar la balanza.
- **Etiqueta de la balanza**: la balanza imprime un código que empieza con el prefijo (por ejemplo 2), sigue el PLU y el peso o el importe. La **caja** y la **factura** (casilla "Escanear código o balanza") lo leen y suman el artículo con sus kilos. El prefijo, si trae peso o importe, los decimales y los dígitos del PLU se configuran en Configuración → Empresa → Punto de venta.
- **Plantillas de despiece**: la materia prima (media res, cuarto trasero, cerdo, pollo) y sus cortes con el **rinde esperado** en %.
- **Despiece**: cargás los kilos que entran y los kilos reales de cada corte. El sistema saca la materia prima del stock, suma cada corte, calcula la **merma** y reparte el costo **por valor de venta** (cada corte carga costo según lo que vale), así el costo por kilo de cada corte es el real. Ves el rinde real contra el esperado (en rojo si rindió menos) y el margen de cada corte y del despiece entero. Opcionalmente actualiza el costo de los cortes.

## Números de serie (Stock → Números de serie)

Para electrónica, celulares, herramientas y todo lo que se identifica por unidad. En la ficha del artículo tildá **Con número de serie** y poné la **garantía en meses**.

- **Compra**: en cada renglón cargás las series recibidas (una por unidad, separadas por coma o una por línea; la pistola las carga seguidas).
- **Venta**: el artículo pide **una serie por unidad** y tiene que estar en stock. Si no está, la factura avisa por qué (por ejemplo "se vendió a López"). En el **punto de venta** alcanza con escanear la etiqueta de la serie: suma ese artículo con esa unidad.
- Al vender, cada serie queda con **el cliente, el comprobante, la fecha y la garantía** (fecha de venta + meses de garantía).
- **Ficha del equipo**: buscás la serie (o el cliente, o el artículo) y ves de qué proveedor vino, a quién se vendió, hasta cuándo tiene garantía y sus services anteriores.
- **Service**: desde la ficha, **Ingresar a servicio técnico** crea la orden con el cliente, el equipo y la serie, y aclara si está **en garantía**.
- Las **notas de crédito** y las **anulaciones** devuelven la serie al stock y la liberan.
- Si no querés exigir la serie en cada venta, se desactiva en Stock → Lotes y vencimientos → Configuración.

## Almacén: ubicaciones y pistola (Stock → Almacén)

Cada depósito se arma en **ubicaciones** (pasillo, estante, nivel) con su código de barras, y el sistema sabe qué hay en cada una. Lo que todavía no está en ningún lugar aparece en **Sin ubicar**.

- **Crear las ubicaciones**: pestaña Ubicaciones → **Generar**: pasillos `A-D` (o `1-5`, o una lista `A,B,FRIO`), cuántos estantes y niveles. Queda A-01-1, A-01-2… con el **orden de recorrido** en serpentina (un pasillo de ida, el siguiente de vuelta). También se cargan de a una (RECEPCION, DESPACHO, CUARENTENA, CAMARA…).
- **Etiquetas**: **Etiquetas de ubicaciones** imprime el código grande con el código de barras, en A4 o en rollo de impresora térmica. Pegá cada una en su estante.
- **Pistola**: la pistola escribe el código y un Enter, como un teclado. En la pestaña Pistola elegís:
  - **Consultar**: escaneás una ubicación y ves qué tiene; escaneás un artículo y ves en qué ubicaciones está (y cuánto queda sin ubicar).
  - **Guardar**: ubicación → artículo (cada lectura suma 1, o escribís la cantidad) → Guardar. Sirve al recibir una compra.
  - **Mover**: ubicación de origen → artículo → ubicación de destino → cantidad.
  - **Contar**: ubicación → escaneás cada unidad → Guardar conteo. Es el inventario por sector.
- **Ventas**: cuando sale mercadería, primero se descuenta de lo sin ubicar y después de las ubicaciones, empezando por Despacho y siguiendo el recorrido.
- **Preparar pedido**: elegís la factura, el remito o el presupuesto y el sistema arma la **hoja de preparación**: de qué ubicación sacar cada cosa y cuánto, en el orden del recorrido y primero lo que vence antes. Se imprime con casilleros para tildar.
- En la ficha de cada artículo aparece **Dónde está**.

## Lotes y vencimientos (Stock → Lotes y vencimientos)

Para supermercados, farmacias, alimentos y todo lo que vence. Marcá el artículo como **Perecedero** (o todo el rubro) y al cargar la compra poné el **lote** y el **vencimiento** de cada renglón.

- **Tablero**: lo vencido con stock, lo que vence en los próximos días (elegís 7, 15, 30… días) y lo bloqueado, con lo que vale al costo. Buscás por artículo, código o número de lote.
- **La venta sale primero de lo que vence antes**. En la factura, debajo del artículo, podés elegir un lote puntual.
- **No se venden vencidos ni bloqueados**: si no alcanza el stock vendible, la factura avisa qué lotes están trabados. Se puede desactivar en Configuración.
- **Bloquear** un lote (control de calidad, envase dañado) lo saca de la venta hasta que lo habilites.
- **Retiro del mercado** (disposición de ANMAT, alerta del proveedor): bloquea ese lote en todos los depósitos y te muestra **a qué clientes se vendió**, con cuánto y cómo contactarlos. La **Planilla de clientes** baja esa lista.
- **Dar de baja** saca del stock lo vencido o roto, con el lote en el kardex.
- **Trazabilidad**: en cada lote ves de qué proveedor y compra vino, cada venta, devolución y baja.
- Las **notas de crédito** y las **anulaciones** devuelven la mercadería al mismo lote del que salió.
- Todos los días aparece una **alerta** por cada lote vencido o por vencer.

## Rubros y lo que heredan los artículos

En **Stock → ⚙ Rubros y depósitos → editar**, cada rubro tiene **Datos que heredan los artículos**: IVA, tipo (producto, servicio…), controla stock, perecedero, con número de serie, se cuenta en el cierre de turno, sale en la tienda, **cuenta contable de ventas**, **percepción especial de IVA e IIBB** y la foto que muestra la tienda.

- Lo que queda en **Hereda** lo toma del rubro de arriba: si "Almacén" tiene IVA 10,5 %, "Almacén › Lácteos" también, salvo que le pongas otro.
- Un artículo nuevo, al elegir el rubro, arranca con esos datos.
- **Aplicar a sus artículos** lleva IVA, tipo y marcas a los artículos que ya existen en ese rubro y en sus subrubros.
- **Cuenta de ventas**: las ventas de los artículos del rubro se imputan a esa cuenta en el asiento (el resto va a Ventas).
- **Percepción especial**: si sos agente y el cliente está alcanzado, los artículos del rubro llevan esa alícuota en vez de la general (la del padrón o la de la ficha del cliente siguen mandando en IIBB).

## Documentos del artículo

Al pie de la ficha del artículo, **Documentos**: fichas técnicas, certificados, manuales y fotos del producto. Se abren con un clic y entran en la copia de seguridad.

## Movimientos

El stock se mueve solo con cada factura, remito, compra y producción. Para ajustes a mano: **Stock → Movimiento** (entrada, salida, ajuste de inventario, transferencia entre depósitos). Cada movimiento queda con quién lo hizo y por qué.

**Inventario**: contás físicamente, cargás las diferencias y el sistema ajusta y valoriza.

## Compras

**Proveedores → Nueva compra**: cargás la factura del proveedor con sus ítems. Entra el stock, se actualiza el costo (y el precio si tiene margen) y queda la deuda en la cuenta corriente del proveedor. Al pagar, el sistema sugiere las **retenciones** que corresponden (Ganancias, IIBB, IVA) y emite el certificado.

Podés cargar la compra con **IA** desde una foto o PDF de la factura: la lee y arma los ítems para que revises.

## Precios

- **Actualización masiva**: Stock → Precios: por rubro, por proveedor o por lista, un porcentaje o desde el nuevo costo.
- **Precios en dólares**: los artículos pueden tener costo en USD; se pesifican con la cotización del día.
- **Lista por cliente**: cada cliente tiene su lista y su descuento; la factura los aplica sola.

## Errores comunes

- **Stock negativo**: el sistema deja vender sin stock (configurable), pero lo marca en rojo. Un inventario lo corrige.
- **Dos artículos iguales**: unificá desde la ficha ("Fusionar con…"): los movimientos pasan al que queda.
- **El costo no se actualizó**: sólo lo hace la compra emitida; un borrador no toca nada.

## Catálogos para mandar a los clientes (Stock → Catálogos)

Un catálogo con tus artículos, tu logo y tus colores, armado con una lista de precios.

1. Tocá **Nuevo catálogo**, elegí la lista (1 a 6) y, si querés, los rubros. Un rubro incluye sus subrubros.
2. Elegí si los precios van con IVA, si se ven las fotos, el código y el stock, y si se muestran solo los artículos con stock.
3. La nota de portada sirve para vigencia, pedido mínimo o condiciones de envío.

Cada catálogo tiene un link público que se abre en el celular, con buscador y botón para bajar el PDF.

- **Mandar a un cliente**: por WhatsApp o por mail con el PDF adjunto. El cliente ve sus propios precios: su lista, su descuento, sus precios pactados y sus descuentos por rubro, marcados como "Precio especial".
- **Mandar a toda la lista**: le llega a cada cliente activo de esa lista, con sus precios. Por mail siempre; por WhatsApp solo con la API de WhatsApp configurada.
- **Link nuevo**: el link anterior deja de abrir. Sirve si el catálogo llegó a quien no correspondía.
- Se ve cuántas veces se abrió y cuándo fue la última.

Los colores y el logo salen de Configuración → Empresa → Diseño de comprobantes. Los insumos no aparecen en el catálogo.

## Mínimos sugeridos estadísticos (Stock → Informes → Mínimos sugeridos)

Con "Cálculo: Estadístico (95 %)" el mínimo cubre la variación de la venta, no solo el promedio: venta diaria × días de reposición + 1,65 × desvío de la venta diaria × raíz de los días de reposición. Es el mismo criterio del BigSys viejo. Un artículo que se vende parejo pide casi lo mismo que con el cálculo simple. Uno que se vende a los saltos pide bastante más, para no quedarte sin stock el 95 % de las veces. Marcá los que quieras y aplicá: el pedido sugerido de las órdenes de compra usa esos mínimos.


## Precios y datos en masa (Stock → Precios y datos en masa)

Para tocar cientos de artículos de una vez sin ir uno por uno. Son tres preguntas:

1. **¿Qué querés hacer?** Cambiar precios, cambiar datos (rubro, ubicación, IVA, stock mínimo, estado, moneda, marca, proveedor), crear artículos desde la lista de un proveedor (te lleva a Importar) o comparar proveedores (te lleva al Consultor).
2. **¿A qué artículos?** Un rubro (con sus subrubros), un proveedor, una marca, los que dicen cierto texto, los comprados entre dos fechas, una selección a mano o todo el catálogo. Abajo ves cuántos son y algunos ejemplos.
3. **¿Cómo?** Qué precio (el costo o una lista del 1 al 6) y el cambio: un porcentaje para arriba o para abajo, un margen sobre el costo, un precio exacto, sumar o restar un monto fijo, o copiar otra lista más un porcentaje. Podés redondear a $1, $10 o $100. Si cambiás el costo, podés recalcular las listas con los márgenes de cada artículo.

Antes de grabar aparece **la pantalla que salva**: cada artículo con el antes y el después. Lo raro viene en rojo y destildado para que lo revises: precios que quedan bajo el costo, en cero, o que cambian más del límite que pusiste (50 % por defecto). Se graba todo o nada.

Todo queda en el **Historial** de la derecha: quién, cuándo, qué, y el valor de antes y después de cada artículo. Cualquier cambio masivo se puede **deshacer** hasta 30 días después (una sola vez).

Desde la lista de Stock, si tildás varios artículos, el botón "Actualizar precios de estos" abre el asistente con esos ya elegidos.

Por el asistente de IA también podés pedirlo con palabras: "subime 15 % el rubro bebidas". Te muestra cuántos artículos son y algunos ejemplos, y aplica recién cuando confirmás.

## Importar la lista del proveedor: preparar, reconocer y revisar

Las listas reales vienen feas. En **Importar lista de precios**, debajo de la tabla de columnas, está **Preparar la lista**:

- **Renglones a importar**: del X al Y (para saltear la portada o el pie).
- **Títulos como rubro**: los renglones con texto y sin precio ("DISCOS", "LIJAS") se toman como rubro de los artículos que siguen.
- **Quitar el encabezado repetido**: cuando la planilla lo repite en cada hoja.
- **Filtros por columna**: contiene, no contiene, igual, vacía, mayor que… (por ejemplo, sacar lo "discontinuado").
- **Descripción armada**: pegar marca + modelo + medida a la descripción.
- **Precios con IVA**: se les quita el IVA (con la columna de IVA si hay, o el que elijas). **Multiplicar por**: un factor para toda la lista.
- **Celdas combinadas**: repetir el valor de arriba en las columnas que marques (marca o rubro que viene una sola vez).

Si elegís el proveedor, al terminar queda **recordado** el mapeo y la preparación: la próxima lista de ese proveedor se abre ya armada.

**Cómo reconoce cada fila**, en orden: el código del proveedor que ya anotamos (compras, recepciones o importaciones anteriores), tu código, el código de barras, el nombre exacto, un código de fabricante dentro de la descripción, y por último el parecido (que te pide confirmación). Cada vez que importás con proveedor, el código del proveedor queda anotado para la próxima.

**La pantalla que salva**: antes de importar, lo raro viene en rojo y destildado: sube o baja más del límite que pongas (50 % por defecto), precio de venta bajo el costo, cero o texto en vez de número, repetido en el archivo, o no encontrado cuando no se crean nuevos. Vos lo revisás y lo tildás sólo si está bien. Con la revisión hecha, se importa **todo o nada**: si una fila tildada falla, no se importa ninguna.

Parámetros: **Sólo el precio de compra** (actualiza el costo y no toca los precios de venta), **Crear los artículos que no existan** (destildado: sólo actualiza), y el margen para la lista 1.

## Pases entre sucursales (Stock → Movimientos → Pases)

El pase se cuenta dos veces. **Armar**: elegí de dónde sale y a dónde va, y escaneá lo que va (`3*código` suma tres; las etiquetas de balanza traen el peso). **Enviar**: sale el stock del origen y el pase queda **en viaje** (no está en ninguna de las dos sucursales). **Recibir**: la otra sucursal cuenta lo que llegó y entra eso. Si llegó distinto, queda **con diferencias**, a la vista de las dos; lo que faltó y no va a aparecer se puede devolver al stock del origen.

Cada pase tiene su **PDF de control** y un link para mandarlo por WhatsApp a quien lo recibe. La transferencia directa (de un depósito a otro en el momento) sigue estando.
