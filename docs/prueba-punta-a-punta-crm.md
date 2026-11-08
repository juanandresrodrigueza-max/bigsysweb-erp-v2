# Prueba de punta a punta ERP ↔ CRM (producción)

Para correr una vez publicados los dos lados de las Fases 68–75. Se hace con **una empresa de prueba** que exista en los dos sistemas
(mismo CUIT), un usuario dueño en el ERP con el mismo mail en el CRM, y un vendedor (mismo mail en los dos). Cada paso dice qué se hace,
dónde, y qué tiene que verse del otro lado. Si algo no se ve en 1 minuto, mirar primero Configuración → CRM (salud) en el ERP y
`/api/erp/salud` en el CRM: ahí queda anotado cada evento con su estado.

## 0. Conexión

| # | Dónde | Qué hacer | Qué tiene que pasar |
|---|---|---|---|
| 0.1 | ERP · Configuración → CRM | URL `https://crm.bigsysweb.com`, secreto compartido, API key del CRM, secreto del webhook. Guardar y **Probar conexión**. | "Conectado con <nombre de la empresa en el CRM>". El bloque de salud en verde. "Lo que ve el CRM" también en verde. |
| 0.2 | CRM · Integraciones → ERP | URL del ERP, mismo secreto, token MCP de *solo consultas*, URL de avisos `https://<erp>/api/crm/webhook/<id empresa>`. Probar. | Verde en el CRM. |
| 0.3 | ERP · Configuración → CRM | Ver el bloque del plan. | "Tu plan … incluye el CRM" (si el plan tiene el extra CRM). |

## 1. Identidad y SSO

| # | Dónde | Qué hacer | Qué tiene que pasar |
|---|---|---|---|
| 1.1 | ERP | Botón **Ir al CRM** (menú). | Entra al CRM ya logueado como la misma persona, con el rol equivalente. |
| 1.2 | CRM | Botón **Ir al ERP**. | Entra al ERP sin pedir clave. |
| 1.3 | ERP · Configuración → Usuarios | Crear un usuario vendedor `prueba.vendedor@…`. | En el CRM aparece como usuario *operator*, activo. |
| 1.4 | CRM · Usuarios | Cambiarle el rol a *supervisor*; después desactivarlo. | En el ERP pasa a "Administración de ventas"; después queda inactivo. El dueño no se puede tocar desde el CRM (409 con el texto del ERP). |
| 1.5 | CRM · Usuarios | Crear un usuario nuevo desde el CRM. | Nace en el ERP como vendedor, en la sucursal principal; entra al ERP por el botón del CRM. Si el plan está al máximo de usuarios, el CRM muestra el texto del ERP. |

## 2. Clientes y artículos

| # | Dónde | Qué hacer | Qué tiene que pasar |
|---|---|---|---|
| 2.1 | ERP · Clientes | Crear "Cliente Prueba E2E" con CUIT y teléfono. | Aparece como contacto en el CRM con etiqueta "Cliente ERP", CUIT y condición de IVA. |
| 2.2 | CRM · Contactos | Crear un contacto nuevo con mail y teléfono; después editarle el teléfono. | Nace como cliente en el ERP; el teléfono se actualiza. Lo fiscal (CUIT, IVA) no lo pisa el CRM. |
| 2.3 | ERP · Stock | Crear un artículo con precio en la lista que usa el CRM. | Aparece como producto en el CRM con ese precio. |
| 2.4 | CRM · Presupuesto | Agregar ese artículo al presupuestar. | Muestra stock y precio **del cliente** (lista + descuento) en vivo desde el ERP. |

## 3. Venta y cobro

| # | Dónde | Qué hacer | Qué tiene que pasar |
|---|---|---|---|
| 3.1 | CRM | Marcar el presupuesto como **aceptado**. | En el ERP nace el presupuesto (o la factura en borrador, según la configuración) con sus ítems; el CRM recibe el número. |
| 3.2 | ERP | Emitir la factura (cta. cte.) de ese presupuesto, con el vendedor asignado. | En el CRM: documento `comprobante.emitido` en la ficha 360 con número, total y **vendedor**. |
| 3.3 | CRM · Ficha 360 | Bloque **Cuenta en el ERP**. | Saldo, vencido, límite, riesgo y pendientes iguales a los de la ficha del ERP. |
| 3.4 | CRM · Ficha 360 | **Registrar cobro en el ERP** (transferencia, parcial), con `vendedor_email` del vendedor. | En el ERP: recibo con "Cobrado desde el CRM", imputado a la factura, modo `crm`, cobrador = ese vendedor. El CRM recibe `cobro.registrado` con el vendedor. |
| 3.5 | ERP · Estadísticas → Vendedores | Ver el embudo del CRM. | Oportunidades abiertas por etapa y, por vendedor, lo del CRM cruzado con lo facturado del ERP (la factura de 3.2). |
| 3.6 | CRM · Comisiones | Período del mes. | La base sale de `GET /api/comisiones/base` del ERP: facturado y cobrado reales (3.2 y 3.4), por mail del vendedor. |

## 4. Conversaciones, tareas y avisos

| # | Dónde | Qué hacer | Qué tiene que pasar |
|---|---|---|---|
| 4.1 | WhatsApp (número del CRM) | Escribirle a la empresa como el cliente. | La conversación aparece en el CRM. En el ERP, ficha del cliente → solapa **CRM**: la conversación con sus últimos mensajes; "Escribirle por el CRM" la abre. |
| 4.2 | WhatsApp (número del ERP, si Bigcito atiende) | Mandar "lo que me mandaron llegó roto". | Alerta de reclamo en el ERP **y** tarea de prioridad alta en el CRM para el vendedor del contacto ("tarea del CRM #N" en la alerta). |
| 4.3 | CRM · Tareas | Crear una tarea para ese contacto con vencimiento ayer. | En la campana del ERP: "Tarea vencida en el CRM: …" (al instante por `task.overdue`, o en la pasada de las 7:40). Cerrarla en el CRM la resuelve en el ERP. |
| 4.4 | ERP · Fondos → Cheques | Cargar un cheque de tercero del cliente y **rechazarlo**. | Tarea "Cheque rechazado: Cliente Prueba E2E" en el CRM, asignada al vendedor del contacto. |
| 4.5 | ERP · Bigcito | "¿Qué habló el vendedor con Cliente Prueba?", "¿quién atiende a Cliente Prueba?", "¿qué tenemos por cerrar?", "¿cuánto le toca al vendedor este mes?" | Responde con datos del CRM (conversaciones, vendedor, oportunidades) y del ERP (comisiones). |
| 4.6 | CRM · Bigcito | "¿Cuánto debe Cliente Prueba?", "¿hay stock del artículo X?" | Responde con datos del ERP; en el ERP la llamada queda auditada a nombre del usuario del CRM (cabecera `X-BigSys-Usuario`). Con un usuario sin permiso a fondos, "¿cuánto hay en caja?" lo rechaza. |

## 5. Plan y salud

| # | Dónde | Qué hacer | Qué tiene que pasar |
|---|---|---|---|
| 5.1 | ERP · Superadmin → Planes | Pasar la empresa a un plan **sin** el extra CRM. | El CRM recibe `plan.cambiado` con `incluye_crm: false`: queda en sólo lectura con el link a la suscripción del ERP. Volver al plan con CRM lo destraba. |
| 5.2 | CRM | Apagar el ERP de prueba un momento (o poner una URL inválida) y generar un evento. | En el CRM el evento queda en cola con reintentos; al volver, se entrega. En el ERP, "Lo que ve el CRM" pasa a amarillo/rojo mientras dura. |
| 5.3 | ERP | Lo mismo al revés: URL inválida del CRM un momento y emitir una factura. | Queda en la cola del ERP; alerta "La conexión con el CRM está caída" a los 15 min; al corregir, **Reintentar** la entrega. La reconciliación de las 6:30 no duplica nada. |

## Qué anotar

Por cada fila: ✅ / ❌ y, si falla, el evento tal como aparece en la salud de cada lado (evento, estado, HTTP, detalle). Con eso se corrige
del lado que corresponda sin adivinar.
