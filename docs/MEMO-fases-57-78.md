# Memo: fases 57 a 91 (IA de gestión, ERP ↔ CRM en permanencia, producción sólida, Intuitivo y la empresa de BigSys)

Estado: **los cuatro bloques completos** (el de Intuitivo, fases 79 a 86, está al final). Fases 57 a 75 cerradas y mergeadas en `main` (PRs #82 a #100), más los ajustes y hallazgos de la
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

## Bloque Intuitivo (79–83): lo que dijo el relevamiento UX

Sale del doc "Relevamiento UX del ERP BigSysWeb". Reglas: las rutas viejas redirigen un año, Bigcito entiende los nombres viejos, aviso en la campanita por cada cambio visible, se mide con `uso_diario`.

| Fase | Qué | Cómo quedó | PR |
|---|---|---|---|
| 79 | Menú nuevo y nombres | `config/erp.php` → `menu` (orden del día: Inicio · Vender · Cobrar · Entregar · Clientes · Comprar · Stock · Plata · Contable · Sueldos · Estadísticas · Mi rubro · Configuración) y `nombres_viejos`; las claves de módulo y los permisos no cambian; Alertas vive en la campana | #116 |
| 80 | Sub-navegación y cabecera única | `config/erp.php` → `secciones` (por entrada del menú; `exacto`, `accion`, `modulo`, `grupo`) y `secciones_sin`; `HandleInertiaRequests::subnav()` comparte la sección de la URL actual (gana la ruta más larga: `/stock/pases` es de Entregar); `AppLayout` pone `SubNav` a la izquierda (chips en el celular) cuando hay sección y las fichas/formularios van a pantalla completa; `PageHeader` (título, una acción principal, a lo sumo una secundaria, menú Más); `ConfigTabs` y `ContableTabs` desaparecen; los botones dicen Nuevo/a, Registrar o Guardar (Cargar sólo para archivos) | #117 |
| 81 | Estados vacíos y primera semana | `EstadoVacio` (texto de "no hay nada" + acción, o "Nada con estos filtros" + Limpiar filtros según el reactive `f` de la pantalla) en los ~70 listados; `AyudaService::tipsPrimeraSemana()` / `tipDelDia()` y prop compartida `tip`: barra bajo el encabezado con un consejo por día los primeros 7 días (Ctrl K, ?, asistente, Más, atajos, campanita, celular), se cierra con Entendido (localStorage) | #118 |
| 82 | Grupos y buscador | `secciones.configuracion` en 4 grupos (Mi empresa · Ventas y fiscal · Integraciones · Datos y cuenta) y `secciones.contable` en 3 (Lo diario · Para el contador · Puesta a punto) con el grupo Sueldos adentro (ítems con `modulo => sueldos`); la entrada `sueldos` del menú lleva `solo_sin => contable` (sólo para quien no ve Contable, que conserva la sección `sueldos` sola); `palabras` por ítem alimentan el buscador de `SubNav` (aparece con 10 secciones o más); las secciones viajan en `nav[].secciones` para que Ctrl K las encuentre | #119 |
| 83 | Canales, reposición, pagos, Mi rubro | Vender: Mostrador (`solo_link`: se lista pero no adueña la URL) + grupo Canales (Pedidos web, Tienda online —sigue en /stock/tienda-online—, Configurar canales); Comprar: `/proveedores/reponer` (`ReposicionController`: bajo mínimo por proveedor habitual, sugerido = hasta el doble del mínimo, botón que abre la orden con `contact_id` y `sugerir=1`) y `/proveedores/pagos` (`PagosController::index`, modal que lleva a la ficha con `?pago=1`); el menú junta los verticales en una entrada `rubro` (`entradas()` en el middleware: nombre real si es uno, "Mi rubro" si son varios, Agenda adentro) con `secciones.rubro` por vertical; la paleta mira `modulo` (desde la Fase 79 las claves son vender/cobrar… y `tiene('comprobantes')` no encontraba nada) y busca el mostrador entre las secciones. Ninguna URL cambió, así que no hubo que redirigir nada | #120 |
| 84 | Lo que faltaba del relevamiento, parte 1 | Inicio con la fila de accesos del día (Vender · Cobrar · Entregar · Cerrar caja) según módulos y permisos; `/fondos?cerrar=1` abre el cierre del turno (y `?nuevo=egreso|ingreso` que usaba la paleta y no andaba); Cobranzas con `PageHeader` y "Registrar cobro" (modal que lleva a la ficha con `?cobro=1`); Comprar con "Nueva orden" como principal; ficha del cliente con Ficha primera y Acopios/Precios pactados sólo si la empresa los usa (`usaAcopios`/`usaPactados`); Estadísticas con Analista IA segundo; encabezado sin selector con una sola sucursal y "Mi negocio" para el dueño en la compu; Entregar → "Por entregar" | #121 |
| 85 | Lo que faltaba del relevamiento, parte 2 | Plata: "Cerrar caja" (la página de cierres con el botón `/fondos?cerrar=1`), "Cheques y valores" como una sola sección (`alias` en el ítem: `/fondos/valores` marca la misma sección; las dos páginas llevan solapas) y "Vencimientos previstos"; Stock: "Pesables y cortes" y "Simulador de precios" con `usa => pesables|consultor` → `Business::usa()` (vertical minimarket/gastronomía/comercio o uso real: artículos pesables, despieces, cotizaciones del consultor); Alertas con `sin_permiso => true` en `erp.modulos`: `User::puede('alertas')` siempre, `Role::modulosVisibles()` lo incluye, la matriz de roles no lo ofrece, `/alertas` sin middleware de permiso; títulos de pestaña (Plata, Empresa, Cerrar caja…) | #122 |
| 86 | Cierre del bloque | `UsoService::navegacion()` (pantallas por persona y día, acciones por cada 100 vistas, tickets de "no encuentro"; ventana de 7 días contra la anterior) en el resumen semanal del sistema y en Superadmin → Uso; guía `docs/ayuda/que-cambio.md` (nombres viejos → nuevos, qué se movió) enlazada desde las siete novedades del bloque y desde Bigcito; migración que pone `tour_visto_en` en null una vez para que el tour se vuelva a ofrecer | #123 |
| 86.1 | Barrido con los guiones de navegador | Los 57 guiones `tests/e2e/*.mjs` corridos contra la base demo tras el bloque: se arreglaron los seeders (`SuperadminSeeder`/`DemoSeeder` buscaban los planes `starter`/`pro` renombrados en #57 y `migrate:fresh --seed` fallaba), la planilla de caja vuelve a estar visible en Cerrar caja, `PageHeader` respeta el `data-e2e` propio de la acción principal, 35 rutas de menú de la ayuda pasan a los nombres nuevos y seis guiones se actualizan a lo que se movió a "Más" o cambió de nombre (Conceptos, Preferencias de stock, Guardar, Vencimientos previstos, Bigcito, Dónde) | #124 |
| 87 | La empresa de BigSys en el ERP | `EmpresaBigSysService` (`crear`: empresa con el superadmin como dueño, todo activado, suscripción de cortesía de 20 años; `vincular`: `SistemaConfig bigsys_business_id` y suma a todos los superadmins; `sumar`: rol dueño y casa central, nunca mueve a un superadmin que ya es de otra empresa); Sistema → tarjeta "La empresa de BigSys en el ERP" (crear o usar una existente); el superadmin siempre entra por `/admin` y se lo suma al loguearse; botón con el nombre de la empresa en el panel y botón "Panel" en el encabezado del ERP (sin modo soporte); el MCP acepta superadmins con empresa; la empresa de BigSys no cuenta como cliente en Uso | #125 |
| 88 | Las secciones, en el menú | `MenuLateral.vue` reemplaza a la columna `SubNav` (borrada): cada módulo del menú despliega sus secciones debajo (grupos como títulos chicos, flecha para plegar, el activo arranca desplegado, `data-ir-*`/`data-e2e=ir-*` en los sub-enlaces); el mismo componente en el cajón del celular; la página va a todo el ancho. `nav[].secciones` viaja con grupo y ayuda; `subnav` sigue compartiéndose sólo para saber la sección activa. Cada rubro habilitado es una entrada del menú sin grupo "Mi rubro" (`secciones.gastronomia/servicios/produccion/…` por módulo; Hotelería, Obras, Agenda con una sola pantalla no despliegan). Novedad en la campanita; tour, Bigcito y la ayuda actualizados | #127 |
| 89 | Big Restó a la altura de Fudo: salón | Plano del salón por sector (`Mesas.vue`: Plano/Lista, «Acomodar mesas» arrastrando, `pos_x/pos_y/forma`, POST `/gastronomia/mesas/plano`). Pedir por asiento (`comanda_items.asiento`, chips en `Comanda.vue`, «Cobrar el asiento» en Cada uno lo suyo). Cortesías (POST `comandas/{id}/items/{item}/cortesia`: precio 0 + `Merma` motivo cortesía con costo). Tabla `mermas` + `MermasService` + pantalla Stock → Mermas y cortesías (`/stock/mermas`, motivos vencido/roto/tirado/error_cocina/cortesia/consumo_personal/otro, baja stock) + MCP `registrar_merma`. Arqueo ciego: `businesses.caja_config.arqueo_ciego` (POST `/fondos/config`, Más en Cerrar caja): el cajero sin `fondos.editar` recibe `esperado` en null y `ciego: true`. Test `SalonFase89Test`. PR #129 |
| 90 | Big Restó a la altura de Fudo: mozo de bolsillo | `/gastronomia/mozo` (`Gastronomia/Mozo.vue`, sección «Mozo de bolsillo»): mis comandas con sin_enviar/en_cocina/listos, lo listo para llevar con Entregado (POST `items/{id}/estado`), llamados, mesas libres → abrir con cubiertos; se refresca cada 15 s. `Comanda.vue`: barra fija abajo en celular (total, Enviar, Cobrar). `CalorSalonService::datos(locationId, dias)` (por mesa: comandas, total, cubiertos, ticket, rotación, minutos, calor 0-1; top, frías, sin_uso, franjas desayuno/almuerzo/merienda/cena) como prop `calor` perezosa (`Inertia::optional`) de `/gastronomia`; vista «Calor» en `Mesas.vue` (plano pintado + tablas). MCP `mapa_de_calor_mesas`. Test `MozoFase90Test`. PR #130 |
| 91 | Big Restó a la altura de Fudo: reservas y delivery propio | `ReservasService` (interpretar texto en español sin IA: personas/fecha/hora/nombre; `reservar` → `Booking` confirmed o **waitlist** según `reservas_capacidad` ±90 min; `liberar` al cancelar: pasa el primero que entra y avisa por WhatsApp; `responderWhatsapp` enganchado en `Api/WhatsappController` antes de interpretar el pedido). Reservas.vue: lista de espera + KPI. `DeliveryService`: `gastronomia_config.zonas[{nombre,costo,palabras,demora}]` y `repartidores`; `detectar(direccion)`; `aplicarZona` = renglón «Envío · zona» sin artículo (se factura con todo); `salio` (repartidor, WhatsApp con demora), `entregado` (cobra lo pendiente con el medio y cierra), `tablero`. Columnas `comandas.zona/costo_envio/repartidor/salio_en/entregado_en`. Pantalla `/gastronomia/delivery` (`Gastronomia/Delivery.vue`, sección «Delivery»), zona en el modal de nuevo delivery y en la comanda. MCP `reservar_mesa` y `delivery_en_curso`. Test `ReservasDeliveryFase91Test`. PR #131 |

Flaky a vigilar (PostgreSQL, corrida completa, pasan solos): `GastronomiaHorariosReporteFase52Test` (404 en /estadisticas/gastronomia) e `InductorBigcitoTest` (mostrar-modulo). Son de orden entre tests, no del cambio de la Fase 80. En la corrida de la Fase 84 le tocó a `MenuFase79Test` (el dueño sin `contable` en el menú: el módulo depende del plan y otro test lo toca); también pasa solo.

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
- Bloque Intuitivo (fases 79 a 86, main en `fa38a62` o posterior): `git pull`, `composer install --no-dev`, `npm run build`, `php artisan migrate --force`, `php artisan config:cache`. Las migraciones nuevas sólo cargan siete novedades para la campanita y ponen `tour_visto_en` en null una vez (el tour se vuelve a ofrecer y se saltea con un clic). Ninguna URL cambió.

## Para probar a mano una vez publicado

- Correr `docs/prueba-punta-a-punta-crm.md` con la empresa "Prueba E2E" (mismo CUIT en los dos sistemas). Los bloques 0, 2, 3 y casi todo 1, 4 y 5
  se pueden correr desde el servidor por API; lo único que necesita una persona es tocar "Ir al CRM" / "Ir al ERP" y mandar un WhatsApp real.
- Si algo no llega en un minuto: Configuración → CRM (salud, últimos eventos, Reintentar) en el ERP y `/api/erp/salud` en el CRM.

## Mantenimiento

- Pendiente del lado CRM: `PUT /api/v1/tasks/:id` acepta un estado inválido sin quejarse; el cobro desde el CRM debería ir al vendedor dueño
  del contacto aunque lo registre un admin.
- Los porcentajes de comisión de una ficha de vendedor creada automáticamente al cobrar desde el CRM nacen en 0 %: se cargan en Clientes → Vendedores.
