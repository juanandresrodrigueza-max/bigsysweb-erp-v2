---
titulo: Conectar Claude, ChatGPT u otra IA (MCP)
modulo: dashboard
rutas: /conexiones-ia
orden: 13
resumen: Usá tu ERP desde Claude o ChatGPT: consultas al instante y acciones que solo se hacen cuando confirmás.
---

## Qué es

El ERP funciona como **servidor MCP**, el estándar con el que las IA (Claude, ChatGPT, Cursor, Claude Code) se conectan a otros sistemas. Una vez conectada, le podés pedir en tu idioma cosas como:

- "¿Cuánto vendí este mes contra el anterior?"
- "¿Quiénes me deben más de 30 días? Mandales el recordatorio."
- "Armá la orden de compra a Loma Negra con lo que está bajo mínimo."
- "Haceme el resumen de IVA del mes pasado para el contador."

La IA usa **tu usuario**: ve y hace solo lo que tu rol permite.

## Conectar Claude

1. Entrá a **Configuración → Conexiones IA** y copiá la dirección del servidor MCP (termina en `/mcp`).
2. En Claude: **Configuración → Conectores → Agregar conector personalizado**, pegá la dirección y tocá **Conectar**.
3. Se abre el ERP: iniciá sesión y elegí qué puede hacer la conexión. Listo.

## Conectar ChatGPT

En ChatGPT: **Configuración → Conectores** (modo desarrollador) → **Crear**. Pegá la dirección, elegí autenticación **OAuth** y autorizá en la ventana del ERP.

## Claude Code, Cursor u otros programas

Creá un **token personal** en Conexiones IA (se muestra una sola vez) y usalo así:

```
claude mcp add --transport http bigsys https://erp.bigsysweb.com/mcp --header "Authorization: Bearer TU_TOKEN"
```

## Qué puede hacer cada conexión

- **Solo consultas**: ventas, clientes, cuentas corrientes, stock, lotes, series, cajas, cheques, IVA, resultado, sueldos, órdenes de servicio. No cambia nada.
- **Consultas y acciones con confirmación**: además, dar de alta clientes y artículos, registrar cobros y gastos, armar presupuestos y facturas en borrador, órdenes de compra, cambiar precios, bloquear lotes, crear órdenes de servicio y mandar recordatorios.
- **También acciones delicadas**: emitir facturas en ARCA, anular comprobantes, pagar a proveedores, transferir entre cuentas y ajustar stock.

## Las acciones siempre se confirman

Cuando le pedís algo que cambia datos, la IA **no lo hace directamente**: el ERP arma una **propuesta** con el detalle (por ejemplo "Cobrar $ 50.000 a Ferretería López en transferencia; cancela la FA 0001-00000123") y la IA te la muestra. Solo si decís que sí, se ejecuta. Las propuestas vencen a los 30 minutos.

Todo queda registrado: en **Conexiones IA** ves las últimas llamadas y las acciones propuestas, y en **Auditoría** cada acción ejecutada con quién la confirmó.

## Atajos listos

La conexión trae tareas armadas que aparecen en el menú de la IA: **Cierre del día**, **Qué cobrar esta semana**, **Qué reponer** y **Resumen para el contador**.

## Desconectar

En **Conexiones IA** tocá **Desconectar** en la conexión: deja de funcionar al instante. El dueño ve y puede desconectar las conexiones de todos los usuarios de la empresa; también puede cambiarles el modo.
