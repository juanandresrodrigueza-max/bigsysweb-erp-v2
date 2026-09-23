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
