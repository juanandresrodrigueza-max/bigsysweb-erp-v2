# Integración ERP ↔ CRM: contrato entre los dos sistemas

Los dos sistemas se venden juntos y conviven: el ERP (`bigsysweb-erp-v2`, Laravel) factura, cobra, maneja stock y plata; el CRM
(`bigsysweb-saas`, Node/TS, crm.bigsysweb.com) conversa, vende y agenda. Se hablan por API firmada; ninguno escribe en la base del otro.
Este archivo es el contrato: qué manda cada uno, qué espera recibir y qué tiene que exponer. Cada fase suma su sección.

## Lo que ya existe (fases anteriores)

| Sentido | Qué | Cómo |
|---|---|---|
| ambos | Entrar al otro ya logueado | Token HMAC-SHA256 de 60 s, un solo uso (`/integraciones/crm/entrar` ↔ `/api/erp/sso`) |
| ERP → CRM | Clientes, artículos, usuarios | `POST /api/v1/{contacts,products,users}/sync` con la API key del CRM; idempotente por `external_id` |
| ERP → CRM | `comprobante.emitido`, `comprobante.anulado`, `cobro.registrado` | `POST /api/erp/webhook` firmado con el secreto compartido (`X-BigSys-Firma`); el CRM guarda en `erp_documents` |
| CRM → ERP | `contact.created`, `contact.updated`, `quote.accepted`, `deal.won` | `POST /api/crm/webhook/{business}` firmado con el secreto del webhook (`X-BigSysweb-Signature`) |
| ERP ← CRM | Tareas y turnos para la Agenda; tarea de cobranza | `GET /api/v1/tasks`, `GET /api/v1/appointments`, `POST /api/v1/tasks` |

## Fase 68 · Salud de la conexión

**Lado ERP (hecho):** bitácora `crm_eventos` con todo lo que sale y entra (ok / error / rechazado / ignorado, HTTP, ms, detalle);
semáforo en Configuración → CRM; alerta `crm_conexion` al dueño cuando el CRM no responde o hay envíos caídos (se resuelve sola);
botón **Reintentar** (vuelve a la cola lo que agotó los reintentos) y **Reconciliar** (vuelve a mandar lo de las últimas 48 h);
comando `crm:salud` cada 15 min y `crm:salud --reconciliar` a las 6:30; Bigcito `estado_crm`.

**Lo que el ERP expone para el CRM:** `GET /api/crm/salud` (con la API key del ERP) → `{ data: { estado: verde|amarillo|rojo|inactiva,
texto, ping: {ok, detalle, ms, cuando}, cola: {pendientes, fallidos}, errores_24h, ok_24h, ultimo_enviado, ultimo_recibido, ultimos[] } }`.

**Lo que el ERP espera del CRM (a hacer del lado CRM):**
1. Que **todo lo que el CRM manda al ERP** (`enviarAlErp`) vaya por una **cola con reintentos** (hoy es un `fetch` directo: si el ERP
   está reiniciando, el `quote.accepted` se pierde) y quede en una bitácora propia (`erp_eventos`: dirección, evento, estado, http, ms).
2. Que el CRM registre también **lo que recibe** del ERP en `/api/erp/webhook` (ok / firma inválida / ignorado) y en `/api/v1/*/sync`.
3. Un panel en **Integraciones → ERP** con el mismo semáforo: ping al ERP (`GET /api/business` con la API key), último enviado/recibido,
   cola, errores 24 h, últimos eventos, botón Reintentar y **Reconciliar** (vuelve a mandar contactos y presupuestos aceptados de las
   últimas 48 h; el ERP es idempotente: `crm_external_id` y `quote.id`).
4. Un endpoint `GET /api/v1/erp/salud` (API key del CRM) con la misma forma que el del ERP, para que cada lado pueda mostrar lo que
   dice el otro.
5. Un trabajo programado cada 15 min: si el ERP no responde, **notificación al admin** del CRM; se resuelve sola cuando vuelve. Y a las
   6:30 la reconciliación.
6. El ERP manda ahora en cada aviso `datos.reconciliacion: true` cuando es un reenvío: el CRM lo trata igual (upsert), sólo lo marca.

## Fase 69 · Saldo y riesgo en el CRM, cobrar desde el CRM

**Lado ERP (hecho):**
- `GET /api/contacts/{id}/cuenta` (API key del ERP; `id` = el `external_id` que el CRM ya guarda del contacto) → `{ data: { saldo,
  vencido, a_vencer, limite_credito, disponible, excede_limite, dias_pago, lista_precios, descuento, riesgo: {puntaje 0-100, nivel, motivos[]},
  pendientes: [{id, tipo, numero, fecha, vence, total, saldo, vencida, dias_vencida, url}], ultimo_cobro, ultimas_facturas[], medios_de_cobro,
  actualizado } }`.
- `POST /api/contacts/{id}/cobros` → crea el recibo en el ERP (cuenta corriente, caja/banco y asiento). Cuerpo:
  `{ fecha?, medios: [{medio: efectivo|transferencia|cheque|mercadopago|billetera|tarjeta|retencion, monto, referencia?, cuenta_fondos_id?, moneda?, cotizacion?}],
  imputaciones?: [{comprobante_id, monto}], descuento?, interes?, notas?, crm_ref? }` → `201 { data: { id, numero, fecha, total, a_cuenta,
  saldo_cliente, url_imprimir } }`. Sin imputaciones el cobro queda a cuenta y el ERP lo imputa a lo más viejo según su regla. El recibo
  vuelve al CRM por el aviso `cobro.registrado` (ya existente), así `erp_documents` se actualiza solo.

**Lo que el ERP espera del CRM:**
1. En la **ficha 360** del contacto y en el **panel lateral de la conversación**, un bloque "Cuenta en el ERP" que consulte `GET .../cuenta`
   (caché de 60 s; si el ERP no responde, mostrar el último dato con la hora): saldo, vencido en rojo, límite y disponible, semáforo de riesgo
   con los motivos, y las facturas pendientes con link al ERP (`/api/erp/ir?a=/comprobantes/{id}`).
2. Botón **"Registrar cobro en el ERP"** en ese bloque y en Cobranzas: medio, importe, referencia, qué facturas imputar (prellenado con las
   vencidas más viejas) → `POST .../cobros`; mostrar número de recibo y link para imprimir. Si el contacto no tiene `external_id`, ofrecer
   mandarlo primero al ERP (ya existe el sync).
3. En Cobranzas del CRM, cuando el contacto tiene `external_id`, la deuda se lee del ERP (`saldo`/`vencido`) en vez de la propia.
4. Bigcito del CRM: la herramienta `ficha_cliente` del MCP ya trae esto; sumar la pregunta "¿cuánto debe X?" al vendedor.

## Fase 70 · Stock y precio en vivo al presupuestar en el CRM

**Lado ERP (hecho):**
- `GET /api/products/para-presupuestar?q=caño&contact_id=12&cantidad=10&solo_con_stock=1&limit=30` (también `ids=1,2,3`) → por artículo:
  `{ id, nombre, sku, codigo_barras, unidad, iva, rubro, marca, imagen, en_tienda, stock: {controla, total, hay, poco, minimo, por_deposito:[{deposito, cantidad}],
  alcanza}, precio: {lista_1, lista, precio_lista, descuento, origen: lista|rubro|pactado|ultimo|cantidad, final, final_con_iva, moneda}, descuento_por_cantidad }`
  más `cliente: {id, nombre, lista, descuento}` y `actualizado`. El precio es **el que el ERP le cobraría a ese cliente hoy**: su lista, sus
  precios pactados, el descuento del rubro o el general y el descuento por cantidad.
- `GET /api/products/{id}/para-presupuestar?contact_id=&cantidad=` → lo mismo para uno.

**Lo que el ERP espera del CRM:**
1. En el **presupuesto** del CRM, al buscar un artículo, consultar `para-presupuestar` con el `external_id` del contacto (si lo tiene) y usar
   `precio.final` (o `final_con_iva` según cómo cotice la empresa); mostrar al lado el stock (`hay` / `poco` / `total`) y marcar en rojo si
   `alcanza` es falso para la cantidad pedida.
2. Los artículos siguen sincronizándose como hasta ahora (catálogo local para buscar rápido); el ERP es la verdad en el momento de cotizar.
3. Si el ERP no responde, usar el precio local sincronizado y avisar "precio sin confirmar con el ERP".
4. Bigcito del CRM: "¿hay stock de caño de 4 m?" y "¿a cuánto le sale el cemento a López?" con estos endpoints (o con `stock_articulo` /
   `ficha_cliente` del MCP del ERP).

## Fase 71 · Conversaciones del CRM en el ERP; reclamos como tarea del CRM

**Lado ERP (hecho):** solapa **CRM** en la ficha del cliente (`GET /clientes/{id}/crm` lee `GET /api/v1/conversations?contact_id={external}` y los
últimos mensajes de la más reciente, caché 2 min) con los botones "Escribirle por el CRM" (`/integraciones/crm/ir?a=/conversations?contact={id}`)
y "Ver en el CRM" (`a=/contacts/{id}`); los reclamos y pedidos de atención por WhatsApp que atiende Bigcito crean una **tarea** en el CRM
(`POST /api/v1/tasks`, `task_type: call`, prioridad alta, `contact_id` del CRM); Bigcito `conversaciones_crm`.

**Lo que el ERP espera del CRM:**
1. `GET /api/v1/conversations` acepta **`contact_id`** como filtro (hoy no filtra; el ERP descarta las de otro contacto, pero paginar sin filtro
   no sirve en empresas grandes).
2. El SSO (`/api/erp/sso?t=…&a=…`) abre los destinos `/conversations?id={id}`, `/conversations?contact={contactId}` (abre o crea la
   conversación de WhatsApp de ese contacto, lista para escribir) y `/contacts/{id}`.
3. Las tareas que crea el ERP (reclamo, hablar con alguien, cobranza) le llegan al **vendedor asignado** al contacto; si no tiene, al admin.
4. En `GET /conversations/:id/messages` cada mensaje trae `created_at`, `direction`, `content` y `message_type` (confirmar que para un adjunto
   `content` venga vacío y `message_type` diga el tipo).

## Fase 72 · Bigcito compartido

**Lado ERP (hecho):** herramientas de Bigcito que leen del CRM: `cliente_en_crm` (`GET /api/v1/contacts/{id}` + `deals?contact_id&status=open` +
`tasks?contact_id&status=pending` + `appointments?contact_id&from`), `oportunidades_crm` (`GET /api/v1/deals?status=open`), `pendientes_crm`
(`GET /api/v1/tasks?status=pending&to=hoy`), más `conversaciones_crm` (Fase 71). Caché 2 min; si el CRM no responde, Bigcito lo dice.
El MCP del ERP (`POST {erp}/mcp`) acepta la cabecera **`X-BigSys-Usuario: <mail>`** en conexiones de modo *solo consultas*: si es un usuario
activo de la misma empresa, responde con sus permisos y audita a su nombre; si no, con los del dueño de la conexión. El contexto que el ERP
le da a su Bigcito (`instructions`) ya le cuenta que el CRM está conectado y qué herramientas usar.

**Lo que el ERP espera del CRM:**
1. El Bigcito del CRM puede usar, además de `ficha_cliente`, estas consultas del MCP del ERP (todas de lectura): `cuenta_corriente_cliente`,
   `riesgo_de_mora`, `deudores`, `stock_articulo`, `buscar_articulos`, `presupuestos_abiertos`, `pendiente_de_entregar`, `ver_comprobante`,
   `buscar_comprobantes`, `que_ofrecer_con`, `quien_dejo_de_comprar`. `tools/list` devuelve las que la conexión permite con su esquema.
2. En cada llamada al MCP del ERP mandar **`X-BigSys-Usuario`** con el mail del usuario del CRM que pregunta.
3. `GET /api/v1/contacts/{id}` incluye **`owner`** `{id, name, email}` (vendedor asignado) y `last_contact_at`.
4. `GET /api/v1/deals` y `GET /api/v1/tasks` incluyen **`stage_name`**, **`assigned_to_name`** y **`contact_name`** (hoy sólo ids).
5. `GET /api/v1/appointments` acepta `contact_id`.
6. El SSO abre `/deals?id={id}` y `/tasks?id={id}`.

## Fase 73 · Avisos cruzados

**Lado ERP (hecho):** `crm:avisos` (diario 07:40) crea en el CRM una **tarea** (`POST /api/v1/tasks`, `task_type: call`, `contact_id`) por cada
hecho crítico de un cliente que está en el CRM: deuda vencida hace más de 30 días y límite de crédito pasado (prioridad `high`), dejó de comprar
(`medium`); un cheque rechazado la crea al instante. Un aviso por cliente y motivo cada 30 días; la descripción termina en "Aviso automático del
ERP BigSys". Del lado de vuelta, el ERP lee `GET /api/v1/tasks?status=pending&to=hoy` y pone las vencidas en su campana, resolviéndolas cuando dejan
de estar pendientes; además acepta los webhooks **`task.overdue`** y **`task.completed`** (`data.task` con `id`, `title`, `priority`, `due_date`,
`contact_id`, `assigned_to_name`) para no esperar a la pasada diaria.

**Lo que el ERP espera del CRM:**
1. Las tareas que crea el ERP quedan **asignadas al vendedor dueño del contacto** (si no tiene, al admin) y se le notifican como cualquier tarea.
2. Mostrarlas con una marca "Del ERP" (reconocibles por la descripción) y, en la ficha 360, como actividad del contacto.
3. Mandar los webhooks `task.overdue` (cuando una tarea pasa a vencida) y `task.completed` (cuando la cierran) a `POST /api/crm/webhook/{business}`
   firmados como los demás (`X-BigSysweb-Signature`).

## Fase 74 · Tablero y comisiones cruzados

**Lado ERP (hecho):** Estadísticas → Vendedores muestra el embudo del CRM (`GET /api/v1/deals?status=open`, `status=won|lost&from&to`,
`tasks?status=pending`) cruzado con lo vendido en el ERP por nombre o mail del vendedor. Nueva API para el CRM: **`GET /api/comisiones/base?desde&hasta&detalle=1`**
→ por vendedor (`email`, `nombre`, `comision_venta_pct`, `comision_cobro_pct`, `facturado`, `notas_credito`, `vendido`, `cobrado`,
`comision_por_ventas`, `comision_por_cobros`, `comision_total`, y el detalle de comprobantes y cobros con `crm_contact_id` y `crm_quote_id`).
Los avisos `comprobante.emitido` y `cobro.registrado` ahora traen **`vendedor`** `{id, nombre, email}`. `POST /api/contacts/{id}/cobros` (Fase 69)
acepta **`vendedor_email`** (o `vendedor_id`) para decir quién cobró: ese cobro le comisiona a él. Bigcito: `comisiones_vendedores`.

**Lo que el ERP espera del CRM:**
1. `GET /api/v1/deals` acepta `from`/`to` para `status=won|lost` (filtra por fecha de cierre) y devuelve **`won_at`**/`closed_at`.
2. El módulo de **Comisiones del CRM**, con el ERP conectado, liquida sobre `GET /api/comisiones/base` del ERP (mismo período), identificando
   al vendedor por `email`; el valor de la oportunidad queda como estimado. Si no hay ERP, sigue como hoy.
3. Al recibir `comprobante.emitido` / `cobro.registrado` con `vendedor`, la actividad del contacto en la ficha 360 dice quién lo vendió/cobró.

## Fase 75 · Identidad completa y un solo plan

**Lado ERP (hecho):** webhooks CRM → ERP **`user.created`**, **`user.updated`**, **`user.deactivated`**, **`user.deleted`** (`data.user` con `email`,
`name`, `role` admin|supervisor|operator|viewer, `is_active`): el ERP da de alta (perfil según el rol, sucursal principal, clave aleatoria), cambia
nombre/rol/estado o da de baja al usuario de la misma empresa por mail. Responde `{ok, user_id, accion}` o `{ok:false, error}` (dueño intocable,
máximo de usuarios del plan). **`GET /api/plan`** → `{plan, plan_slug, estado, motivo, vence, incluye_crm, max_usuarios, usuarios_activos,
url_suscripcion}`; y el aviso **`plan.cambiado`** (mismo cuerpo) en cada cambio de suscripción. Un plan incluye el CRM cuando tiene el extra
"CRM BigSys" (Superadmin → Planes) o la empresa lo tiene contratado aparte.

**Lo que el ERP espera del CRM:**
1. Mandar los webhooks `user.*` cuando en el CRM se crea, edita (rol/nombre), desactiva o borra un usuario de una empresa con ERP conectado.
2. Con el ERP conectado, la empresa del CRM **se habilita con el plan del ERP**: al recibir `plan.cambiado` (o leyendo `GET /api/plan` una vez
   por día) guarda `incluye_crm`, `estado`, `vence`, `max_usuarios`; si `incluye_crm` es false o `estado` es `bloqueada`, el CRM muestra el
   motivo y el link a `url_suscripcion` del ERP y no deja operar (sólo leer); no cobra una suscripción aparte.
3. Al crear un usuario en el CRM con ERP conectado, si el ERP responde `ok:false` por el máximo del plan, el CRM lo dice con el mismo texto.

## Ajustes tras la publicación de los dos lados

- El ERP lee **`GET /api/v1/erp/salud`** del CRM (caché 5 min) y lo muestra en Configuración → CRM como "Lo que ve el CRM"; si de nuestro lado
  está verde pero el CRM tiene envíos hacia el ERP caídos o errores, el semáforo del ERP pasa a amarillo con el texto del CRM. `estado_crm` lo
  devuelve en `lo_que_ve_el_crm`.
- Reconciliación del CRM (`contact.updated` / `quote.accepted` con `data.reconciliacion: true`): el ERP ya es idempotente (contacto por
  `crm_external_id`, presupuesto por `crm_quote_id`), así que no duplica nada.
- Pendiente del lado CRM: 69.3 (Cobranzas del CRM con saldo y vencido del ERP).
- Hallazgos de la prueba de punta a punta: `POST /api/contacts/{id}/cobros` con `vendedor_email` de un usuario del ERP que todavía no era
  vendedor dejaba `cobrador_id` en null; ahora le crea la ficha de vendedor (0 % hasta que el dueño cargue el porcentaje) y la respuesta trae
  `cobrador` y, si el mail no es de nadie, `aviso`. `quote.accepted` empareja los ítems primero por `external_id` (id del artículo en el ERP) y
  después por `code`/barras.
- Segunda tanda de hallazgos (bloques 1, 4 y 5): `user.updated` del **dueño** con rol o baja se rechaza (`ok:false`, 422), el nombre sí se
  acepta; `GET /api/crm/salud?ping=1` y el botón Probar refrescan en el acto el ping y "Lo que ve el CRM"; **Reintentar** también adelanta los
  envíos que esperan su próximo intento (backoff) y, de paso, se corrigió el filtro con que el ERP busca sus envíos en la cola (no coincidía con
  el payload real, así que la cola contaba cero desde la Fase 68).

## Fase 116 · Todo convive: facturación, stock, cobranzas y pedidos (decisiones del 17/11/2026)

Decisiones de Juan: con el ERP conectado **se factura sólo en el ERP**; el **stock manda el ERP** (el CRM lo muestra y lo empuja a las
tiendas, no lo edita); la **deuda vive en el ERP** y la **gestión del cobro en el CRM** (todo pago anotado en el CRM termina como recibo
en el ERP); los **pedidos de tiendas** pasan al ERP como venta; lo que la empresa ya tenía en el CRM pasa al ERP (carga inicial).

### A · Pedidos de tiendas → ERP

`POST /api/crm/pedidos` (Bearer: token de API de la empresa). Idempotente por `crm_order_id`.

```json
{"crm_order_id": "woo-1001", "origen": "woo", "numero": "1001", "fecha": "2026-11-17", "pagado": true, "medio_pago": "Mercado Pago",
 "total": 23500, "envio": 1500, "entrega": "envio",
 "cliente": {"crm_contact_id": 55, "nombre": "Marta Pérez", "cuit": null, "email": "marta@mail.com", "telefono": "351 555 0000", "direccion": "Av. Colón 100"},
 "items": [{"sku": "CEM50", "external_id": 812, "descripcion": "Cemento x 50 kg", "cantidad": 2, "precio_unitario": 10000},
           {"sku": "ZZZ-NO", "descripcion": "Pala ancha", "cantidad": 1, "precio_unitario": 2000}]}
```

`origen`: `woo` | `woocommerce` | `tiendanube` | `shopify` | `mercadolibre` | `empretienda` | `tienda` | `otro`. El cliente se empareja igual que
`recibirContacto` (id del CRM → CUIT → mail → teléfono) y si no existe se crea. Los ítems se emparejan por `external_id` (id del artículo en el
ERP), después por `sku`/`code` (SKU o barras); **sin artículo entran como renglón libre** (no mueven stock) y sale una alerta en el ERP
(`crm_pedido_sin_articulo`); el pedido no se rechaza. Los precios son los que cobró la tienda; si `total` difiere, la diferencia queda como descuento.

Qué hace: crea el **pedido web del canal** y lo **confirma al toque como comprobante interno** (`sin_arca`, numera aparte): **descuenta el
stock** y queda **para facturar** con «Facturar» (fase 104), que lo pasa a fiscal con CAE sin tocar nada más. Si la confirmación falla (por
ejemplo, falta el punto de venta), el pedido queda en Vender → Pedidos web para confirmarlo a mano y la respuesta lo dice en `aviso`.
`confirmar: false` lo deja sólo como pedido.

Respuesta `201` (y `200` con `duplicado: true` si ya había entrado):

```json
{"data": {"id": 41, "numero": "PW-00041", "crm_order_id": "woo-1001", "canal": "woocommerce", "estado": "confirmado", "total": 23500,
  "cliente": {"id": 120, "nombre": "Marta Pérez"},
  "comprobante": {"id": 905, "tipo": "FB", "numero": "X 0001-00000012", "interno": true, "estado": "emitido", "total": 23500, "url": "https://erp.bigsysweb.com/comprobantes/905"},
  "duplicado": false, "items_sin_articulo": ["Pala ancha (ZZZ-NO)"], "aviso": null}}
```

`comprobante.emitido` ahora trae `crm_order_id` (además de `crm_quote_id`) e `interno: true|false`. Al facturar el interno, el ERP manda
**otro** `comprobante.emitido` por el **mismo id** de comprobante, con `interno: false` y `fiscal_desde_interno: "X 0001-00000012"`:
el CRM actualiza el documento en vez de duplicarlo.

### B · Deudas para gestionar en el CRM

`GET /api/crm/deudores?por_pagina=100&page=1&solo_vencidos=1` (paginado, hasta 200 por página; `solo_vencidos` opcional):

```json
{"data": [{"contact_id": 120, "crm_external_id": "55", "nombre": "Atrasado SA", "cuit": "30-70012345-6", "email": "…", "telefono": "…",
   "saldo": 13310, "vencido": 12100, "dias_mayor_atraso": 10,
   "comprobantes_vencidos": [{"id": 900, "tipo": "FA", "numero": "A 0001-00000007", "fecha": "2026-10-08", "vence": "2026-11-07", "saldo": 12100, "dias": 10}],
   "ultimo_cobro": {"id": 31, "numero": "REC-00031", "fecha": "2026-11-02", "total": 5000}, "dias_pago": 30, "limite_credito": null,
   "url": "https://erp.bigsysweb.com/clientes/120"}],
 "meta": {"total": 2, "pagina": 1, "por_pagina": 100, "paginas": 1, "saldo_total": 15730, "actualizado": "2026-11-17T10:00:00-03:00"}}
```

`POST /api/contacts/{id}/cobros` acepta **varios medios** en `medios[]` (`efectivo`, `transferencia` con `cuenta_fondos_id`, `cheque`,
`mercadopago`, `tarjeta`, …) y ahora **`crm_payment_id`**: si el CRM reintenta el mismo pago, responde `200` con el recibo que ya existía y
`duplicado: true` (no se registra dos veces). Un pago anulado libera el id. `cobro.registrado` trae `crm_payment_id`.

Evento nuevo ERP → CRM: **`cobro.anulado`** (mismo `datos` que `cobro.registrado`, con `estado: "anulado"`, `motivo` y `crm_payment_id`).
`payment.received` del CRM no hace falta: el CRM manda todo cobro por `POST /api/contacts/{id}/cobros`.

### C · Stock y productos siempre al día

- Los cambios masivos ahora avisan al CRM: aplicar datos del rubro a sus artículos (Stock → Rubros → Aplicar), publicar/ocultar artículos
  en la tienda, y las ventas y compras por la API común (`/api/sales`, `/api/purchases`).
- `sincronizarTodo` (botón Sincronizar todo) manda clientes **y proveedores**, artículos **y usuarios**. El hook de contactos y la
  reconciliación de 48 h también mandan proveedores (etiqueta `Proveedor`, sin `lifecycle_stage`) y usuarios.

### D · Carga inicial CRM → ERP (botón «Pasar todo al ERP»)

Cuatro endpoints en lote (hasta **500 ítems por llamada**), idempotentes, con el mismo token de API. Todos responden
`{"data": {"summary": {"total", "creados", "actualizados", "existentes", "errores"}, "results": [{"ok", "accion", "id", "numero", "motivo", "indice"}]}}`;
un ítem con error no frena a los demás.

- **`POST /api/crm/importar/contactos`** `{items: [contacto como en contact.created]}`. Empareja por `crm_external_id` → `external_id` (id del
  ERP) → CUIT → mail; crea si no existe. `tipo: "proveedor"` lo da de alta como proveedor (o «ambos» si ya era cliente).
- **`POST /api/crm/importar/presupuestos`** `{items: [{quote, contact?}]}` (mismo payload que `quote.accepted`). Idempotente por `crm_quote_id`.
- **`POST /api/crm/importar/comprobantes-historicos`**: facturas y notas que el CRM **ya emitió con CAE** antes de conectar. Sólo historial:
  **no se re-emiten, no piden CAE, no tocan la numeración del ERP, no mueven stock ni contabilidad**. Quedan `afip_estado: "crm"`,
  `origen_carga: "crm"`, nota «Emitida en el CRM». Se ven en la ficha del cliente y en `/api/contacts/{id}/cuenta` como cualquier comprobante.

  ```json
  {"items": [{"crm_document_id": "d1", "tipo": "FA", "punto_venta": 6, "numero": 120, "fecha": "2026-09-10", "vencimiento": "2026-10-10",
    "cae": "76123456789012", "cae_vto": "2026-09-20", "cliente": {"crm_contact_id": 1, "nombre": "López SRL", "cuit": "30-70012345-6"},
    "items": [{"sku": "CEM50", "descripcion": "Cemento", "cantidad": 10, "precio_unitario": 1000, "alicuota_iva": 21}],
    "total": 12100, "saldo": 4100},
   {"crm_document_id": "d2", "tipo": "NCA", "punto_venta": 6, "numero": 3, "fecha": "2026-09-15", "origen_crm_document_id": "d1",
    "cliente": {"crm_contact_id": 1}, "items": [...], "total": 1210}]}
  ```

  `tipo`: FA, FB, FC, FE, NCA, NCB, NCC, NDA, NDB, NDC (remitos y presupuestos no). Idempotente por `crm_document_id` y también por
  tipo + punto de venta + número. **Sí se imputa a la cuenta corriente sin pedir CAE**: `saldo` es lo que todavía incide (factura: lo
  pendiente de cobro; nota de crédito: el crédito sin aplicar). Con `saldo` la cuenta corriente recibe el debe por el total y un haber
  «Cobrado en el CRM antes de conectar» por la diferencia, así el cliente arranca debiendo exactamente eso. Sin `saldo` (o 0) es sólo historial.
  `origen_crm_document_id` ata la nota a su factura. Precios sin IVA (`alicuota_iva` por renglón, 21 por defecto).
- **`POST /api/crm/importar/saldos-iniciales`**: la alternativa cuando el CRM no tiene el detalle por comprobante. Un comprobante **interno**
  por cliente, «Saldo inicial desde el CRM»: positivo = nos debe (nota de débito interna), negativo = a favor (nota de crédito interna).
  Sin ARCA, sin stock, sin contabilidad; sí cuenta corriente. Idempotente por cliente.

  ```json
  {"items": [{"crm_contact_id": 10, "saldo": 15000, "fecha": "2026-11-01", "detalle": "deuda al 31/10"},
             {"cliente": {"nombre": "A Favor SA", "cuit": "27-12345678-0"}, "saldo": -2000}]}
  ```

  **Elegir una de las dos** para la deuda abierta: o los comprobantes históricos con `saldo`, o los saldos iniciales. Las dos juntas la
  duplican. Recomendación: históricos con `saldo` si el CRM tiene el detalle (la cobranza del CRM ve después qué factura está vencida);
  saldos iniciales si no.

### E · Eventos CRM → ERP que antes se ignoraban

- **`contact.deleted`** `{contact: {id}}`: el ERP **no borra** (lo fiscal queda): lo desactiva y anota «Borrado en el CRM». Responde
  `{ok, accion: "desactivado"|"no_estaba", id, con_movimientos}`.
- **`contact.merged`** `{contact: {id: <el que sobrevive>, ...datos}, merged_ids: [..]}` (también acepta `survivor_id`/`keep_id` y
  `merged`/`duplicates`): el que sobrevive se queda con el id del CRM; los fusionados quedan inactivos sin id del CRM y con la nota. Los
  comprobantes **no se mueven** (lo fiscal queda con quien se emitió); si el fusionado tenía saldo, alerta `crm_fusion_saldo` para revisar la
  cuenta corriente. Responde `{ok, sobrevive, desactivados: [...], con_saldo: [...]}`.
- **`quote.rejected`** `{quote: {id}}`: anula el presupuesto (o descarta el borrador) si todavía no se facturó; si ya se facturó responde
  `ok: false` con el motivo. `{ok, accion: "anulado"|"no_estaba", id, numero}`.
- **`quote.updated`** `{quote, contact?}`: si el del ERP no se facturó, lo anula y entra el nuevo con los ítems de ahora
  (`{ok, accion: "creado", id, numero, reemplazo: "<número anulado>"}`); si no estaba y el status no es `accepted`, `accion: "ignorado"`.
- **`deal.lost`** `{deal: {id, contact_id?, title?, value?, lost_reason?}}`: cierra el aviso de «venta ganada» del mismo deal y deja uno
  informativo (`crm_venta_perdida`). `{ok, accion: "avisado"}`.

El webhook descarta un cuerpo idéntico repetido dentro de 10 minutos (`{ok: true, duplicado: true}`): para reintentar a propósito,
cambiar algo del cuerpo (por ejemplo `data.reintento`).

### Al conectar la empresa BigSys

La empresa #3 del CRM factura sola las suscripciones (punto de venta 6): eso es facturación de la plataforma y **no** pasa al ERP. En el ERP
la empresa propia es «Bigsys Web ERP» (Panel → Sistema); los superadmins entran ahí por el login común (fase 115).
