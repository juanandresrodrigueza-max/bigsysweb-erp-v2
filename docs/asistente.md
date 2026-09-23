# BigSysWeb ERP · Asistente que hace las cosas, app del dueño y paneles por rol

## Asistente (botón ✦)

Responde sobre el sistema y el negocio, y **hace cosas** cuando se lo pedís. Toda acción se muestra primero como propuesta y recién se ejecuta cuando tocás *Confirmar*. Queda en Configuración → Auditoría como `ia_ejecuto`, con el usuario que confirmó.

| Pedido | Qué hace |
|---|---|
| "¿Cuánto me debe López?" | Saldo, vencido y últimas facturas del cliente |
| "Stock de cemento" | Stock, mínimo, precio y costo del artículo |
| "¿Quiénes me deben?" | Los 5 que más deben, con lo vencido |
| "¿Cuánto vendí hoy / ayer / esta semana / este mes?" | Ventas netas del período |
| "Cobrale 5000 a López en transferencia" | Propone el cobro imputado a las facturas más viejas; al confirmar lo registra (recibo, cuenta corriente, fondos, asiento) |
| "Gasté 2500 en nafta" | Propone el gasto desde la caja (o "desde banco"), con la categoría que mejor coincide |
| "Recordale a Pérez la deuda por WhatsApp" | Propone el recordatorio de la factura vencida más vieja |
| "Presupuesto para Pérez de 3 cemento y 2 hierro" | Arma un presupuesto en borrador con los precios de la lista del cliente |

- **Permisos**: el asistente solo ofrece lo que el rol puede hacer (cobros: clientes → crear; gastos: fondos → crear; presupuestos: comprobantes → crear; recordatorios: clientes → editar). Un vendedor no puede registrar gastos ni siquiera por chat.
- **Con IA** (`ANTHROPIC_API_KEY`): la IA entiende cualquier frase y usa las herramientas del sistema. **Sin IA**: se entienden las frases de la tabla.
- Nunca inventa importes: cliente o artículo que no encuentra → lo dice y pide el nombre como figura.

## Mi negocio (celular del dueño)

`/dueno`: una pantalla pensada para el teléfono con ventas de hoy (y contra ayer), cobrado, mes contra el mes pasado a la misma altura, caja/banco/billeteras, por cobrar y por pagar con lo vencido, lo que hay que atender (pedidos web, comprobantes pendientes de CAE, alertas), lo más vendido hoy y las últimas ventas. Acciones de un toque: Cobrar, Gasto, Factura, Rentabilidad.

- Se instala como app (PWA): "Agregar a inicio". Acceso directo "Mi negocio" en el ícono.
- En el celular hay una barra inferior en todo el sistema: Mi negocio · Ventas · Buscar · Cobrar · Menú.
- El resumen diario por WhatsApp (Configuración → Empresa → Avisos) sigue existiendo para quien no quiera abrir la app.

## Paneles por rol (Inicio)

Cada rol ve arriba del tablero lo suyo:

- **Vendedor**: vendido y facturas del período, comisión estimada, presupuestos sin respuesta y sus clientes que deben.
- **Cajero**: estado del turno (abrir/cerrar), efectivo esperado en la caja, sus ventas y cobros de hoy.
- **Depósito**: bajo mínimo, sin stock, transferencias pendientes, entregas pendientes y qué reponer.
- **Contador**: IVA débito y crédito del mes, posición, resultado, comprobantes sin asiento, pendientes de CAE y liquidaciones de sueldos sin pagar.

El dueño y el administrador ven el tablero completo.
