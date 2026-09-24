---
titulo: Producción y fórmulas
modulo: produccion
rutas: /produccion
orden: 6
resumen: Fórmulas de productos elaborados, órdenes de producción que consumen insumos y cargan producto terminado, y costo calculado.
---

## Fórmulas (Producción → Fórmulas)

Para cada producto elaborado, qué insumos lleva y en qué cantidad (por unidad o por lote), más un porcentaje de merma si corresponde. El **costo del producto** se calcula solo con el costo actual de los insumos y se puede volcar al precio.

Los insumos se cargan como artículos de tipo **insumo**: no se venden y solo se mueven por compras y producción.

## Órdenes de producción

**Producción → Nueva orden**: producto, cantidad y depósito. Al **terminar** la orden, se descuentan los insumos según la fórmula y entra el producto terminado al stock, valorizado al costo real de esa orden. Si faltó o sobró insumo, se ajusta en la orden antes de cerrar.

## Qué controlar

- **Stock → Informes** muestra insumos bajo mínimo con la producción prevista.
- Cada orden terminada queda con su costo unitario real; la diferencia contra la fórmula es el desvío a revisar.
