---
titulo: Stock, artículos y precios
modulo: stock
rutas: /stock
orden: 4
resumen: Artículos, listas de precios, movimientos de stock, mínimos, compras a proveedores y actualización de costos.
---

## Artículos

Cada artículo tiene código (SKU), código de barras, rubro, unidad, IVA, costo y hasta seis **listas de precios**. El precio puede ser un margen sobre el costo: al subir el costo, sube el precio. Los artículos con **stock controlado** avisan cuando bajan del mínimo.

Tipos: **producto** (mueve stock), **servicio** (no mueve stock), **insumo** (para producción) y **combo/kit**.

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

