# Integración ERP ↔ CRM: contrato entre los dos sistemas

Los dos sistemas se venden juntos y conviven: el ERP (`bigsysweb-erp-v2`, Laravel) factura, cobra, maneja stock y plata; el CRM
(`bigsysweb-saas`, Node/TS, crm.vixiweb.com) conversa, vende y agenda. Se hablan por API firmada; ninguno escribe en la base del otro.
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
