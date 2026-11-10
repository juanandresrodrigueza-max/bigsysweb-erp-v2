# Memo: fases 57 a 78 (IA de gestión, ERP ↔ CRM en permanencia y producción sólida)

Estado: **los tres bloques completos**. Fases 57 a 75 cerradas y mergeadas en `main` (PRs #82 a #100), más los ajustes y hallazgos de la
prueba de punta a punta (PRs #101 a #105). Pruebas: 420+ tests en verde en SQLite y PostgreSQL. El lado CRM de las fases 68 a 75 está
publicado en **crm.bigsysweb.com**, y el guion `docs/prueba-punta-a-punta-crm.md` se corrió completo contra producción con todo ✅.

## Bloque IA de gestión (57–67): Bigcito hace el trabajo que nadie quiere hacer

| Fase | Tema | Lo principal | PR |
|---|---|---|---|
| 57 | Cobranzas con cabeza | Riesgo de mora explicable por cliente, recordatorios escritos a medida, plan de pago propuesto | #82 |
| 58 | Gastos y banco clasificados solos | Cada movimiento del extracto y cada gasto cae en su categoría; se corrige una vez y aprende | #83 |
| 59 | Preguntarle a tus documentos | Contratos, listas de proveedores y PDFs adjuntos se consultan en castellano | #84 |
| 60 | Bigcito atiende por WhatsApp | Pedidos, consultas de stock y precio, estado de cuenta, reclamos y "quiero hablar con alguien" | #85 |
| 61 | Alta de artículos con IA | Desde el nombre o una foto: rubro, unidad, IVA y precio sugerido | #86 |
| 62 | Comparar cotizaciones | Lee las cotizaciones de varios proveedores y arma el pedido dividido por el mejor precio | #87 |
| 63 | Venta cruzada | "Qué ofrecer con" en Vender, factura y presupuesto, según lo que se vende junto | #88 |
| 64 | Lo raro del día | Analista diario: anomalías de ventas, cobros, stock y caja, en la campana | #89 |
| 65 | Sueldos por texto | Novedades del mes en castellano → liquidación en borrador; el recibo explicado al empleado | #90 |
| 66 | Informe mensual para el contador | Ventas, compras, IVA, resultado y lo que llama la atención, en castellano | #91 |
| 67 | Soporte con el manual | Los tickets de soporte los responde Bigcito leyendo el manual; escala lo que no sabe | #92 |

## Bloque ERP ↔ CRM en permanencia (68–75): los dos sistemas conviven

Los dos productos se venden juntos y se complementan: el ERP factura, cobra y controla; el CRM conversa, vende y agenda. Se hablan por API
firmada; ninguno escribe en la base del otro. El contrato completo entre los dos está en `docs/integracion-crm.md`.

| Fase | Tema | Lo principal (lado ERP) | PR |
|---|---|---|---|
| 68 | Salud de la conexión | Bitácora de todo lo que viaja, cola con reintentos, ping cada 15 min con alerta, reconciliación diaria 6:30, "Lo que ve el CRM" | #93 |
| 69 | Cuenta del cliente en vivo | `GET /api/contacts/{id}/cuenta` para la ficha 360 del CRM; cobrar desde el CRM registra el recibo en el ERP | #94 |
| 70 | Stock y precio al presupuestar | `GET /api/products/para-presupuestar` con stock y precio de la lista del cliente | #95 |
| 71 | Conversaciones en la ficha | Solapa CRM del cliente, "Escribirle por el CRM"; reclamos de WhatsApp como tarea del CRM | #96 |
| 72 | Bigcito compartido | El del ERP responde con datos del CRM; el del CRM pregunta al ERP con los permisos de la persona (`X-BigSys-Usuario`) | #97 |
| 73 | Avisos cruzados | Cheque rechazado, deuda vieja, límite pasado y "dejó de comprar" al vendedor en el CRM; tareas vencidas del CRM en la campana | #98 |
| 74 | Tablero y comisiones | Embudo del CRM en Estadísticas → Vendedores; `GET /api/comisiones/base` con lo facturado y cobrado real | #99 |
| 75 | Identidad y un solo plan | Altas, roles y bajas desde el CRM hacia el ERP; `GET /api/plan` y `plan.cambiado`: el plan del ERP habilita los dos | #100 |

Ajustes posteriores: dominio del CRM (#101), "Lo que ve el CRM" y el guion de prueba (#102), cobrador del recibo desde el CRM e ítems por
`external_id` (#103, #104), dueño intocable, ping en vivo y el filtro de la cola (#105).

## Bloque Producción sólida (76–78): que lo hecho aguante el uso real

| Fase | Tema | Lo principal | PR |
|---|---|---|---|
| 76 | Lo que rompe se ve y se arregla | Mail inmediato por error nuevo (o que vuelve) con código; página de error amigable con "Avisar a soporte" que abre el ticket con el error pegado; Bigcito del superadmin `salud_del_sistema`; resumen semanal del sistema por mail; raíz del flaky de reparto | #107 |
| 77 | Rendimiento | Test con techo de consultas por pantalla (frena N+1); comparativo mensual de Estadísticas en una consulta; medición nocturna `erp:medir --nocturna` con historia, panel y control de salud; índices faltantes | #108 |
| 78 | Respaldo verificado | Cada copia se abre y cuadra; ensayo de restauración en transacción que se deshace (domingos); copia fuera del servidor con `BACKUP_DISK`; `backups:probar`; controles de salud y tabla por empresa | #109 |

Pendiente de configurar en producción: `BACKUP_DISK` con un bucket S3 compatible (hasta entonces la salud avisa que las copias viven en el
mismo disco) y el mail de monitoreo en Superadmin → Salud (sin él no salen ni el aviso de error nuevo ni el resumen semanal).
El bucket de Backblaze es compartido con el CRM (misma clave de aplicación): si alguna vez se rota la clave, hay que cambiarla en los dos
`.env` (`OFFSITE_S3_*`). El ERP poda la copia remota junto con la local (quedan las últimas 10 automáticas por empresa).
Flaky a vigilar: `DocumentosPreguntasFase59Test` falló una vez en PostgreSQL en la corrida completa y pasa solo.

## Bigcito y MCP

Herramientas nuevas (`app/Mcp/Modulos/*.php`): `riesgo_de_mora`, `proponer_plan_pago`, `registrar_gastos_banco`, `preguntar_documentos`,
`reclamos_whatsapp`, `crear_articulo` con IA, `comparar_cotizaciones`, `que_ofrecer_con`, `anomalias_del_dia`, `liquidar_sueldos`,
`explicar_recibo`, `informe_mensual_contador`, `estado_crm`, `conversaciones_crm`, `cliente_en_crm`, `oportunidades_crm`,
`pendientes_crm`, `comisiones_vendedores`. Las acciones siguen pidiendo confirmación y quedan auditadas.

El CRM consulta al ERP por MCP con una conexión de **solo consultas** y la cabecera `X-BigSys-Usuario` (mail de quien pregunta): el ERP
responde con los permisos de esa persona y audita a su nombre.

## Manuales

- Guías de usuario: `docs/ayuda/*.md`. Lo del CRM está en `configuracion.md` (sección CRM, fases 68 a 75) y, donde se usa, en `cobrar.md`,
  `facturar.md`, `estadisticas.md`, `alertas-y-asistente.md` y `conectar-ia.md`.
- Guías técnicas: `docs/integracion-crm.md` (el contrato entre los dos sistemas), `docs/prueba-punta-a-punta-crm.md` (el guion),
  `docs/api.md` (endpoints para el CRM), `docs/asistente.md`.

## Para publicar

- ERP: ver `docs/DEPLOY-hostinger.md`. Mismo VPS que el CRM (2.25.209.3), contenedores propios en `/opt/bigsysweb`.
- CRM: `bash scripts/publicar.sh` en `/var/www/bigsysweb2` (pruebas e2e + build + reinicio rolling). El remoto va por SSH con deploy key
  propia del repo; una deploy key sirve para un solo repo, así que el ERP necesita la suya con alias `Host github.com-erp` si se pasa a SSH.
- El dominio del CRM es **crm.bigsysweb.com**, único. El ERP no lo tiene grabado: cada empresa carga la URL en Configuración → CRM.

## Para probar a mano una vez publicado

- Correr `docs/prueba-punta-a-punta-crm.md` con la empresa "Prueba E2E" (mismo CUIT en los dos sistemas). Los bloques 0, 2, 3 y casi todo 1, 4 y 5
  se pueden correr desde el servidor por API; lo único que necesita una persona es tocar "Ir al CRM" / "Ir al ERP" y mandar un WhatsApp real.
- Si algo no llega en un minuto: Configuración → CRM (salud, últimos eventos, Reintentar) en el ERP y `/api/erp/salud` en el CRM.

## Mantenimiento

- Pendiente del lado CRM: `PUT /api/v1/tasks/:id` acepta un estado inválido sin quejarse; el cobro desde el CRM debería ir al vendedor dueño
  del contacto aunque lo registre un admin.
- Los porcentajes de comisión de una ficha de vendedor creada automáticamente al cobrar desde el CRM nacen en 0 %: se cargan en Clientes → Vendedores.
