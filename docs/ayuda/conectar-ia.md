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

## La IA de tu empresa (asistente del ERP)

El asistente del ERP (el botón violeta de abajo a la derecha) y la IA del sistema (presupuestos desde WhatsApp, lectura de facturas de compra, analista del negocio) usan **la clave de Anthropic de tu empresa**: el consumo lo paga tu cuenta, no la de otros.

1. Entrá a **console.anthropic.com**, creá tu cuenta y en **Billing** cargá crédito (con 5 o 10 dólares alcanza para arrancar). Conviene poner un límite de gasto mensual en **Limits**.
2. En **API Keys → Create Key** creá una clave y copiala (empieza con `sk-ant-`).
3. En el ERP: **Configuración → Conexiones IA → IA de tu empresa**, pegá la clave, elegí el modelo y tocá **Probar y guardar**.

Haiku es rápido y económico; Sonnet razona mejor los pedidos complicados. Sin clave, el asistente funciona en modo básico (entiende frases simples como cobros, gastos, saldos, stock y ventas). Solo el dueño o quien tenga permiso de editar la configuración puede cargar o quitar la clave. Se guarda cifrada.

Conectar Claude o ChatGPT por MCP (lo que sigue) no usa esta clave: la IA la paga cada uno con su propia cuenta de Claude o ChatGPT.

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

El token de una conexión de IA sirve **solo para la IA** (la dirección `/mcp`): la API del ERP lo rechaza. Así, un token de «Solo consultas» no puede cambiar nada por otro camino. Para integrar otro sistema por la API, creá un token de API en **Seguridad y API → Tokens**.

## Las acciones siempre se confirman

Cuando le pedís algo que cambia datos, la IA **no lo hace directamente**: el ERP arma una **propuesta** con el detalle (por ejemplo "Cobrar $ 50.000 a Ferretería López en transferencia; cancela la FA 0001-00000123") y la IA te la muestra. Solo si decís que sí, se ejecuta. Las propuestas vencen a los 30 minutos.

Todo queda registrado: en **Conexiones IA** ves las últimas llamadas y las acciones propuestas, y en **Auditoría** cada acción ejecutada con quién la confirmó.

## Atajos listos

La conexión trae tareas armadas que aparecen en el menú de la IA: **Cierre del día**, **Qué cobrar esta semana**, **Qué reponer** y **Resumen para el contador**.

## Desconectar

En **Conexiones IA** tocá **Desconectar** en la conexión: deja de funcionar al instante. El dueño ve y puede desconectar las conexiones de todos los usuarios de la empresa; también puede cambiarles el modo.
