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

