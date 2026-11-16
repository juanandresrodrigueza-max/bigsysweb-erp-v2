---
titulo: Alertas, asistente y avisos al dueño
modulo: alertas
rutas: /alertas, /dueno, /soporte, /avisos-celular, /mejoras, /novedades
orden: 10
resumen: Qué avisa el sistema y por dónde, cómo usar el asistente para consultar y hacer cosas, y qué recibe el dueño en el celular.
---

## Bigcito trabaja de noche (fase 107)

Todas las madrugadas, a las 05:45, cinco agentes de Bigcito revisan la empresa y dejan **propuestas** en **Inicio → Bigcito te dejó N propuestas** (y en `/propuestas`). Nada se hace solo: la regla de la casa vale también para los agentes. Vos aprobás de a un toque y recién ahí se ejecuta, con los mismos circuitos de siempre, y queda en la auditoría quién aprobó qué.

| Agente | Qué mira | Qué pasa al aprobar |
|---|---|---|
| **Cobrador** | Quién debe con vencido, cuántos días, su riesgo de mora y cuándo fue el último aviso (no insiste antes de 5 días). Redacta el mensaje para ese cliente: suave con el que siempre paga, firme con el reincidente (con la IA de la empresa; sin IA, la plantilla de Cobranzas con todas las vencidas). | Sale por WhatsApp (o mail si no tiene celular). Sin la API de WhatsApp, queda el link listo para mandarlo. |
| **Reposición** | Lo que está bajo mínimo o sin stock, agrupado por proveedor habitual, con la cantidad (hasta el doble del mínimo, o lo que se vendía por mes si viene de BigSys) y la plata que hace falta. Si ya hay una orden abierta con ese proveedor, no la pisa. | Crea la orden de compra **en borrador** en Comprar → Órdenes, para revisarla y mandarla. Lo sin proveedor queda como aviso. |
| **Precios** | Artículos a los que les subió el costo en los últimos 3 días y el precio no se tocó, y los que están bajo su margen mínimo. Propone el precio nuevo respetando el margen que corresponde (el objetivo del artículo, el que tenía antes de la suba, o el mínimo) y lo redondea (a 10, 50 o 100 según el precio). Los artículos con cadena de márgenes cargada ya se recalculan solos. | Cambia la lista 1; cada cambio queda en el historial de precios. |
| **Cierre del mes** | Los primeros 10 días del mes revisa el mes anterior: comprobantes sin asiento, gastos sin categoría (propone la que usaste antes para el mismo concepto), cierres de caja con diferencia y lo que el informe del contador marca para revisar. También a pedido con **Revisar ahora**. | Genera los asientos que faltan y aplica las categorías. El resto es para que el contador decida. |
| **Control interno** | Compara a cada usuario con el resto del equipo en los últimos 30 días: anulaciones, descuentos regalados, cajas que no cuadran, ventas más de 30 % abajo de la lista, cargas a deshora. Si alguien está al menos dos veces y media por encima del promedio de los demás (y pasa un piso que importe), te lo cuenta con la evidencia. | Sólo avisa: «Lo vi». Si es grave, además llega como alerta crítica al celular. Mirá los comprobantes en Estadísticas → Qué hacer → Control de usuarios antes de hablar con la persona. |

- **Rechazar** una propuesta (con motivo, opcional) hace que Bigcito no insista con lo mismo por una semana. Lo que el agente deja de proponer vence solo a los 7 días.
- **Configuración**: en la misma pantalla, el dueño apaga el agente que no quiera y el aviso al celular. Cada mañana con propuestas nuevas llega un push: «Bigcito te dejó N propuestas».
- **Resumen hablado** (`/resumen-hablado`, también desde el parte de la mañana): el parte del día más las propuestas, leído en voz alta por el celular con su propia voz (sin servicios externos, sin pesos con símbolos raros). Desde ahí le repreguntás a Bigcito con un toque: «¿por qué vendí menos que ayer?», «¿a quién le cobro primero?».

A Bigcito: «¿qué me dejaste para aprobar?», «aprobá la propuesta 12», «rechazá la 8, ya hablé con él», «revisá ahora la reposición», «¿alguien se sale de lo normal?» (control interno, sólo el dueño), «leeme el resumen del día».

## Alertas

La campana del encabezado y **Alertas** juntan lo que necesita atención: stock bajo mínimo, artículos vencidos, facturas vencidas, cheques por vencer, cajas con diferencia, pedidos nuevos, presupuestos sin respuesta, facturas pendientes de CAE, respuestas de soporte. Cada alerta lleva a la pantalla donde se resuelve; se marca leída o resuelta.

## Asistente

El botón violeta abajo a la derecha. Escribí o **dictá**:
- Consultas: "¿cuánto me debe López?", "¿qué stock hay de cemento?", "¿cuánto vendimos esta semana?".
- Acciones: "cobrale 5000 a Pérez en efectivo", "gasté 2500 en nafta", "recordale a Gómez la deuda por WhatsApp", "presupuesto para Díaz de 3 bolsas de cemento". Muestra la propuesta con todo resuelto y la ejecuta cuando confirmás. Queda en la auditoría.

Respeta los permisos: un vendedor no puede registrar gastos ni por chat.

### Todo lo del día se puede pedir hablando (fase 109)

La regla es una sola: **si lo podés hacer en pantalla, se lo podés pedir a Bigcito**; si no tenés el permiso, tampoco por acá. Toda acción se muestra primero como propuesta y se ejecuta recién cuando decís «dale». Queda en la auditoría como hecha por vos a través del asistente. Preguntale «¿qué podés hacer?» y te lista lo que tu usuario puede pedir.

| Querés… | Decile, por ejemplo | Permiso |
|---|---|---|
| Vender y facturar | «facturale a Pérez dos bolsas de cemento», «emití el borrador 12», «mandale la factura por WhatsApp», «facturá el interno 8» | Ventas crear |
| Nota de crédito o remito | «hacé la nota de crédito de la factura 27 por 10.000», «nota de crédito total de la 27», «remito desde la factura 27» | Ventas crear |
| Cobrar | «cobrale 50.000 a López por transferencia», «recordale la deuda a Gómez», «proponele un plan de pago» | Clientes crear |
| Clientes | «cargá el cliente Juan López, monotributista, CUIT 20-…» (el padrón lo completa), «cambiale el teléfono a López», «ponele 30 días de pago», «marcalo con comprobante interno» | Clientes crear / editar |
| Proveedores y compras | «dá de alta el proveedor Aceros Sur», «cargá la factura A 0003-123 de Aceros Sur: 10 hierros a 1.100 más IVA», «asentá el pago de 50.000 a Norte por transferencia», «armá la orden de compra» | Compras crear |
| Artículos y stock | «creá el artículo Tornillo 3 mm a 120», «cambiale el precio», «ponele rubro Herrajes y proveedor Norte», «ajustá el stock a 40», «registrá la merma» | Stock crear / editar |
| Plata | «gasté 2.500 en nafta», «pasá 100.000 de caja a banco», «depositá el cheque 123456 en Galicia», «abrí la caja con 10.000», «cerrá la caja con 85.300» | Plata crear / editar |
| Dar de baja | «dá de baja el cliente López» (con movimientos se desactiva; sin movimientos se borra), «borrá el artículo…», «anulá el recibo 15, se cargó dos veces», «anulá la orden de pago 8», «borrá el gasto de nafta», «cancelá la orden de compra 3», «cancelá el turno de Ana» | Anular del módulo |
| Anular una factura emitida | «anulá la factura 27» (sin CAE) o «nota de crédito total de la 27» (con CAE) | Ventas anular / crear |
| Usuarios | «creá el usuario Caja Uno, cajero, caja1@…» (te da una contraseña provisoria), «pasalo a vendedor», «desactivalo» | Configuración editar |
| Sueldos | «dá de alta a Marta Gómez, CUIL 27-…, básico 900.000», «cargale vacaciones del 2 al 15», «liquidá octubre», «mandá los recibos a firmar» | Sueldos crear |
| Agenda | «agendá a Gómez el martes a las 10 con Marta, 45 minutos», «cancelá el turno de Gómez» | Agenda crear / editar |
| Bigcito de noche | «¿qué me dejaste para aprobar?», «aprobá la propuesta 12», «revisá ahora la reposición» | Configuración editar |

Lo que no se pide por acá, a propósito: cambiar la configuración de ARCA, claves, planes y suscripción, y borrar comprobantes emitidos.

## Mi negocio (celular)

**Mi negocio** es la pantalla del dueño en el teléfono: ventas de hoy contra ayer, cobrado, caja y bancos, por cobrar y por pagar con lo vencido, pedidos web, alertas, lo más vendido y las últimas ventas, con Cobrar, Gasto y Factura a un toque. Instalá la app desde el navegador ("Agregar a la pantalla de inicio").

## Avisos por WhatsApp

**Configuración → Empresa → Avisos al dueño**: a la hora que elijas llega el resumen del día, y al momento las alertas críticas (caja con faltante, cheque rechazado, pedido grande).

## Soporte

**Soporte** (menú) abre un ticket con el equipo de BigSys: se responde ahí y por mail, y la respuesta aparece como alerta.

**Bigcito responde primero (Fase 67).** Al abrir el ticket, Bigcito busca en las guías del sistema y, si está, te deja la respuesta con los pasos y los links a la guía, al instante. Debajo tenés dos botones: **"Me sirvió, cerrar el ticket"** o **"No me sirvió, que me responda una persona"**: con el segundo (o si escribís "no me sirvió", "quiero hablar con alguien") pasa al equipo de BigSys. Lo que las guías no cubren, los errores del sistema, las sugerencias y lo de facturación y planes van directo a una persona, y Bigcito te lo dice. Con clave de IA (la de BigSys) redacta los pasos para tu caso; sin clave, te pasa los pedazos de las guías que coinciden. También podés abrirlo por voz: "Bigcito, escribile a soporte que no me deja emitir la factura A".

## Hablarle a Bigcito

Tocá el micrófono y hablá: Bigcito entiende, busca y **te contesta en voz alta** (el parlante de arriba lo silencia). Cuando le pedís que haga algo, te dice qué va a hacer y pregunta "¿Lo hago?": respondé **"sí"** o **"dale"** y lo hace, o **"no"** y lo deja. Nada se graba sin que confirmes.

Lo que le podés pedir, además de ventas, cobros, stock y gastos:

- **Precios y datos en masa**: "subime 10% el rubro bebidas", "poneles ubicación góndola 3 a todos los de Ayudín", "deshacé el último cambio masivo".
- **Pases entre sucursales**: "¿qué pases están en viaje?", "llegó el pase 12, está todo".
- **Conciliaciones**: "¿cómo están las tarjetas?", "cargá los impuestos del banco".
- **Reparto y acopios**: "¿qué hay para entregar?", "armá el viaje de Obra Norte y Ferretería Sur con Carlos", "¿por dónde va el camión?", "¿qué le queda por retirar a Constructora Sur?".
- **Taller**: "¿qué sigue en el taller?", "la 15 queda esperando el repuesto", "¿a quién le toca el service?", "¿cuánto le pago a cada mecánico este mes?".
- **Sueldos**: "¿quién falta firmar el recibo?", "mandá los recibos a los que faltan".
- **Varias empresas**: "¿cómo están mis empresas?", con el semáforo y los vencimientos.

Lo mismo funciona desde Claude o ChatGPT conectados por MCP (Configuración → Conexiones IA): son las mismas herramientas y las acciones también se confirman.

## Respuestas que sirven y consejos

Bigcito siempre te dice **el período** que usó y, si hizo una cuenta, **cómo la hizo**. Cuando la respuesta es una lista, abajo aparece **Bajar la lista a Excel**; cuando ayuda, un **gráfico de barras**. Y al final te sugiere 2 o 3 preguntas para seguir: tocalas y las pregunta.

Consejos que cruzan los datos:

- **"¿Qué repongo?"**: lo que está bajo el mínimo o no llega a cubrir los próximos días.
- **"¿A quién le cobro primero?"**: en orden por lo vencido, lo viejo que es y si hace mucho que no le avisaste, con el teléfono.
- **"¿Qué precio reviso?"**: lo que se vende bajo el costo o el margen mínimo, y lo que hace mucho que no tocás.
- **"¿Quién dejó de comprarme?"**: los que compraban seguido y hace 60 días que no.
- **"¿Cómo va el día?"**: ventas hasta ahora contra la semana pasada, lo cobrado, los gastos y lo que hay que mirar.
- **"¿Cómo cierra el mes?" / "¿cuánto voy a vender los próximos meses?"**: la proyección con el método explicado; con un año de ventas tiene en cuenta la estacionalidad.
- **"¿Cuánto me deben por antigüedad?"**: por vencer, hasta 30, 60, 90 y más de 90 días.

## Fotos y audios para Bigcito

- **📷 Foto**: tocá la cámara, sacá o elegí una foto (una factura, un remito, un producto, una pantalla con error) y escribí qué querés saber. Necesita la clave de IA de la empresa.
- **Audio**: en los celulares donde el micrófono no dicta, Bigcito graba el audio y lo pasa a texto. Para eso cargá una clave de **OpenAI** o de **Gemini** en Configuración → Conexiones IA → *Audios para Bigcito* (la clave de Claude no transcribe audio).
- **Comprobantes sin cliente**: preguntale "¿hay ventas a consumidor final que sean de un cliente?". Busca por el DNI o CUIT del receptor, por el nombre igual y, con IA, por nombres escritos distinto. Si le decís "vinculalos", te muestra la lista y, cuando confirmás, pasa cada venta a la cuenta de su cliente. El comprobante fiscal no cambia.

## La ayuda que aprende

- En el **Centro de ayuda** podés repreguntar ("¿y si es en cuotas?"): la ayuda se acuerda de lo que preguntaste antes.
- Cada respuesta tiene **👍 / 👎**. El dueño ve, en el inicio de la ayuda, *lo que su gente preguntó y no encontró*, para saber qué explicar o qué pedirle a soporte.
- **Bajar el manual para leer sin internet** descarga todas las guías en un solo archivo.
- **WhatsApp a soporte (con el contexto)** abre el chat con el mensaje ya escrito: quién sos, de qué empresa, en qué guía estabas y qué preguntaste.

## Avisos al celular

En **Avisos al celular** (desde la ✨ de novedades, arriba a la derecha) tocá **Activar en este dispositivo** y aceptá el permiso del navegador. Desde ahí te llegan, aunque no tengas el sistema abierto:

- las **alertas críticas** (además del WhatsApp, si lo tenías);
- el **parte de la mañana**: cómo cerró ayer y qué vence hoy para pagar y cobrar, a la hora que elijas (al celular y/o por WhatsApp). Lo configura el dueño;
- las **novedades de BigSys** que se manden también al celular.

En iPhone, primero agregá BigSysWeb a la pantalla de inicio (Compartir → Agregar a inicio) y abrilo desde ahí. Podés quitar un dispositivo cuando quieras.

**Con la app nativa** (iPhone y Android, cuando esté en las tiendas): los avisos llegan por el sistema de notificaciones del teléfono, al toque y con el sistema cerrado; no hay que activar nada: la app registra el celular sola al entrar. En **Avisos al celular** ves cada teléfono registrado (modelo y última vez) y lo podés quitar. Más en el artículo **App del celular**.

## Novedades y mejoras

- La **✨** muestra lo nuevo del sistema. Cada novedad se marca leída o se pospone **7 o 30 días**.
- **Mejoras** lista los módulos que tu plan no tiene y qué hace cada uno. Con **Me interesa** le avisás a BigSys para que te escriba (lo pide el dueño o un administrador).
- A Bigcito: “mandame el parte a las 7 y media”, “¿qué hay de nuevo en BigSys?”, “¿qué módulos no tengo?”, “me interesa Sueldos”.
- Las últimas novedades de la ✨: el **diseño nuevo** con los colores de BigSys (fase 92), la **app del celular** en camino (fase 93) y el **repaso de todas las pantallas** para que nada quede apretado (fase 94). Están contadas en **Qué cambió en el menú y en las pantallas**.

## Alerta de condición de IVA (Fase 44)

Te avisa cuando hay clientes con la condición de IVA mal cargada: Consumidor Final con un CUIT de 11 dígitos, o Responsable Inscripto sin CUIT. Así la factura no sale con la letra equivocada. Para verlos, entrá en Clientes y elegí el filtro «IVA para revisar».

## La campana y Bigcito con el CRM conectado (Fases 71 a 73)

- **Tareas vencidas del CRM en la campana**: si el equipo de ventas tiene una tarea vencida en el CRM (un llamado, un seguimiento), aparece acá como alerta del módulo Clientes, con el nombre del cliente y del vendedor y el link al CRM. Las de prioridad alta son críticas. Se resuelve sola cuando el vendedor la cierra en el CRM.
- **Reclamos**: lo que un cliente reclama por WhatsApp y atiende Bigcito queda como alerta acá **y** como tarea del CRM para su vendedor; la alerta dice el número de tarea.
- **Bigcito compartido**: el Bigcito del ERP contesta con datos del CRM ("¿qué habló el vendedor con López?", "¿quién atiende a López y en qué anda?", "¿qué tenemos por cerrar?", "¿qué tiene pendiente ventas?") y el del CRM contesta con datos del ERP ("¿cuánto debe López?", "¿hay stock del hierro del 8?"), cada uno con los permisos de la persona que pregunta. El contexto de Bigcito ya sabe cuándo el CRM está conectado.
