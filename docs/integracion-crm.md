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

**Lo que el ERP expone para el CRM:** `GET /api/v1/crm/salud` (con la API key del ERP) → `{ data: { estado: verde|amarillo|rojo|inactiva,
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
