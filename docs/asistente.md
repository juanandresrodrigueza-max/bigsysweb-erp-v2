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

## Con el CRM conectado (Fases 71 a 74)

Si la empresa tiene el CRM de BigSys conectado (`CrmService::activo`), el asistente suma herramientas de consulta que leen de la API del CRM
(caché de 2 minutos; si el CRM no responde, lo dice sin romper):

| Pedido | Herramienta | Qué hace |
|---|---|---|
| "¿Qué habló el vendedor con López?" | `conversaciones_crm` | Últimas conversaciones (WhatsApp, mail, redes) y los mensajes de la más reciente |
| "¿Quién atiende a López y en qué anda?" | `cliente_en_crm` | Vendedor, etapa, oportunidades abiertas, tareas pendientes, próxima cita |
| "¿Qué tenemos por cerrar?", "¿qué tiene Juan en el embudo?" | `oportunidades_crm` | El embudo con total y por etapa, filtrable por cliente o vendedor |
| "¿Qué tiene pendiente ventas?" | `pendientes_crm` | Tareas del CRM vencidas o de hoy |
| "¿Cuánto le toca a Juan este mes?" | `comisiones_vendedores` | Base de comisiones con lo facturado y cobrado real |
| "¿Anda el CRM?" | `estado_crm` | Semáforo de la conexión, cola, lo que ve el CRM y el plan |

El contexto que recibe el modelo (`Recursos::instrucciones`) le avisa que el CRM está conectado y qué herramienta usar para lo comercial.
En sentido inverso, el CRM consulta al ERP por `POST /mcp` con una conexión de solo consultas y la cabecera `X-BigSys-Usuario` (mail de
quien pregunta): el ERP resuelve al usuario de la misma empresa y responde con sus permisos (`McpController::autenticar`).
