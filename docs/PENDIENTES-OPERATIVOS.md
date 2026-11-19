# Lo que falta para dejar el ERP 100 % operativo (lo hace Juan, no el código)

Escrito el 15/11/2026 y actualizado el 19/11: producción en `erp.bigsysweb.com` está en `11266aa` (hasta la fase 118.2). **`main` (`71f21f1` o posterior) tiene completas la fase 119 (Bigcito maneja el sistema) y la fase 120 (plantillas de WhatsApp, Banco Central, casilla de facturas, conversación en vivo, cobrador y encargos) y falta desplegarlas.**
El software está. Lo que sigue son cuentas, claves, certificados y decisiones que sólo puede hacer el dueño de las cuentas.
**Regla de siempre: ninguna clave, token, certificado ni contraseña va por el chat.** Todo se carga en los formularios del ERP o en el servidor desde la sesión «CRM Producción».

Orden sugerido: primero el bloque A (mañana a la mañana), después B y C en el día, D y E cuando arranque el primer cliente.

---

## A · Mañana, antes que nada (15 minutos)

- [x] **Salud**: `ok` desde el 15/11 a la noche (CRM Producción volvió a medir las páginas, 14 a 89 ms, y resolvió 6 errores que eran de su propia consola).
- [ ] **Probar en producción «Facturar después»** con la empresa de BigSys o una de prueba: hacer una venta tildada como comprobante interno, y desde el comprobante apretar **Facturar** (fase 104). Tiene que salir con numeración fiscal y CAE (o simulado si la empresa no tiene certificado). Es la única función nueva que no se probó con datos reales.
- [x] **Contraseña del superadmin** (`super@bigsys.com.ar`) cambiada el 16/11; la de fábrica ya no sirve.
- [x] **Clave de aprobación cargada** el 16/11 (Panel → Sistema → Altas con aprobación), con mail y celular de aviso. Falta la prueba de registro de abajo.
- [ ] **Avisos de alta por WhatsApp**: no llegó el 16/11 porque la empresa de BigSys no tiene WhatsApp conectado. Desde la fase 118 se conecta con el QR: (1) la sesión del CRM pasa el App ID y el Configuration ID de la app de Meta y agrega `erp.bigsysweb.com` a los dominios de la app; el App Secret lo pega Juan en Panel → Sistema → «WhatsApp con un botón»; (2) entrar como dueño de «Bigsys Web ERP» → Configuración → Tienda y canales → Pedidos por WhatsApp → **Conectar WhatsApp (escaneás un QR)**; (3) Panel → Sistema → «Enviar aviso de prueba por los tres canales». Mientras tanto, las altas se ven en Panel → Pendientes.
- [x] **Probar el circuito de altas** (cerrado el 17/11: «Prueba Alta» se registró, entró con acceso provisional y se eliminó definitivamente desde el panel; el aviso por WhatsApp sigue pendiente del punto anterior). Detalle: hecho el registro el 16/11 (empresa «Prueba Alta», `prueba.alta@bigsysweb.com`, entra con el aviso de acceso provisional hasta el 19/11). Falta: aprobarla en Panel → Pendientes con la clave, y después borrarla con «Eliminar definitivamente». (Antes decía: cargar la clave de aprobación en Panel → Sistema → Altas con aprobación (fase 106) y, si querés WhatsApp, los celulares. Después probar: registrarte con un mail de prueba en `/registro`, ver que te llega el aviso y aprobarla en Panel → Pendientes.
- [ ] **Desplegar la fase 119** (`main b438b9f`): con la sesión «CRM Producción», el comando de siempre. Trae dos tablas nuevas (`bigcito_rutinas`, `bigcito_memorias`) y dos novedades; el programador que ya corre cada minuto empieza a hacer las rutinas solo. Después de desplegar, cargar la **clave de voz** (bloque B) y probar lo de abajo.
- [ ] **Probar la fase 119 en producción** (10 minutos, con «Bigsys Web ERP»): (1) Alt+B y decir «llevame a cerrar caja» y «abrime la ficha de (un cliente)»: tiene que navegar y la charla seguir abierta. (2) Con una ficha abierta, «¿cuánto me debe?». (3) Tocar la oreja 👂 y decir «Hola Bigcito, ¿cuánto vendí hoy?». (4) Desde el iPhone, tocar el micrófono, hablar y dejar de hablar: tiene que cortar sola y contestar con voz natural (necesita la clave de voz). (5) «Todos los días a las 9 pasame cuánto vendí ayer» → confirmar → verla en Inicio → Rutinas y tocar «Probar ahora». (6) «Acordate que (apodo) es (cliente)» y después usar el apodo. (7) «Cobrale 1.000 a (cliente A) y 1.000 a (cliente B)» → «Confirmar las 2».
- [ ] **Desplegar la fase 120** (va junto con la 119, es el mismo comando de siempre desde «CRM Producción»). Suma las tablas `whatsapp_ventanas`, `facturas_entrantes`, `acuerdos_pago` y `encargos`, las columnas `contacts.bcra`, `businesses.casilla_token` y `alertas.user_id`, y tres tareas del programador (`whatsapp:plantillas`, `casilla:revisar`, `encargos:recordar`). No agrega dependencias. Después, en este orden:
  - [ ] **Plantillas de WhatsApp (120.1)**: con el WhatsApp de «Bigsys Web ERP» conectado, Configuración → Tienda y canales → **Pedir las plantillas a Meta**. Meta las aprueba en minutos u horas; sin eso, lo que el sistema manda «en frío» (pasadas 24 horas sin que la persona escriba) no llega.
  - [ ] **Banco Central (120.2)**: abrir un cliente con CUIT → «Situación en el Banco Central» → **Consultar**. **Probado el 19/11 contra la API real del BCRA** desde desarrollo (un CUIT con deuda en 21 entidades, uno que no figura y uno mal escrito: los tres bien). Lo único sin ver con datos reales es el detalle de cheques rechazados (no tuve un CUIT que los tenga). En producción sólo falta comprobar que el servidor llegue a `api.bcra.gob.ar` (si da error de certificado, ver `docs/ayuda/administracion-bigsys.md`, «Banco Central»).
  - [ ] **Casilla de facturas (120.3)**: crear en Hostinger una casilla dedicada (por ejemplo `facturas@bigsysweb.com`) y cargar en Panel → Sistema → «Casilla de facturas» el servidor IMAP (`imap.hostinger.com`, 993), el usuario y la clave → **Probar la casilla**. Después, desde Comprar → Facturas de compra, copiar la dirección de la empresa y mandarle un mail con el PDF de una factura: en unos 5 minutos tiene que aparecer «Para revisar». Probar también «Subir varias facturas». **No se probó contra un buzón real**; si Hostinger no acepta el «+código» en la dirección, igual funciona reenviando desde el mail de un usuario.
  - [ ] **Conversación en vivo (120.4)**, opcional y con costo por minuto: necesita la clave de voz de **OpenAI** (bloque B). Panel → Sistema → Voz de Bigcito → tildar «Conversación en vivo» → Guardar; abrir Bigcito, tocar 📞 y pedirle algo. **No se pudo probar contra OpenAI**: si no conecta, destildarla y avisar con el mensaje que muestre.
  - [ ] **Bigcito cobrador (120.5)**: Cobrar → Cobranzas → ⋯ → **Bigcito cobrador**: prenderlo con los límites (cuotas, interés, cuota mínima, plazo). Probar escribiendo «no llego a pagar todo» al WhatsApp de la empresa desde un celular que esté cargado como cliente con una factura vencida; aceptar un plan y aprobarlo desde Cobranzas.
  - [ ] **Encargos (120.6)**: cargarle el celular a los usuarios (Configuración → Usuarios) y probar «decile a (alguien) que…» con Bigcito; la otra persona contesta HECHO por WhatsApp.
- [ ] **Probar las propuestas de Bigcito** (fase 107): en Inicio → Propuestas, botón «Revisar ahora»; aprobar una de cobranza y una de precios y ver que ejecuta. Abrir «Resumen hablado» en el celular y tocar Escuchar.
- [x] **Producción actualizada** el 19/11 a las 09:28 (commit `11266aa`, fases 118 a 118.2: WhatsApp con el QR de Meta, consulta al CRM por el número, ronda nocturna con superadmins; verificado por hash de assets desde la sesión del ERP). Antes, el 17/11 a las 10:09 (commit `8d6249b`, fases 117 a 117.2: débito automático, pago único por la API de Orders, reintento ante 419; verificado desde la sesión del ERP: assets, novedad en /conocer y `/csrf-renovar`). Antes, el 17/11 (commit `d2ed233`, fases 115.1 y 116: convivencia CRM-ERP; el CRM ya tiene el contrato para engancharse). Antes, el 17/11 a las 07:08 (commit `9252f84`, fase 115: el superadmin entra a «Bigsys Web ERP» y salta al panel con el botón). Antes, el 17/11 a la madrugada (commit `5c024bd`, fases 112 a 114: eliminar usuarios y empresas, registro, diagnóstico de avisos, historia del plato). Copia previa en `/root/erp-backups/bigsyserp-20261117-0001-antes-5c024bd.dump` (10 empresas, 20 usuarios).
- [x] **Borrar las empresas demo de producción** (hecho el 17/11: quedó solo «Bigsys Web ERP»; Corralón Demo borró 1863 filas en 75 tablas) desde Panel → Empresas → ficha → «Eliminar definitivamente» (nombre exacto + clave de aprobación): Corralón Demo, Prueba E2E, Ferretería Norte, Taller Ruiz Hnos., Estudio Contable Vera, Panadería La Espiga, Kiosco 24 Horas, Ferretería Nueva Prueba y, cuando termines de probar, Prueba Alta. Dejar «Bigsys Web ERP». Para las próximas: siempre con la sesión «CRM Producción», `curl -fsSL https://raw.githubusercontent.com/juanandresrodrigueza-max/bigsysweb-erp-v2/main/deploy/actualizar.sh | bash`.

## B · Plataforma BigSys (Superadmin → Sistema, una sola vez)

Todo esto se carga en **Panel → Sistema**. Queda cifrado y no se vuelve a mostrar. Lo que ya está cargado dice «(dejar vacío para no cambiar)».

| Bloque del formulario | Qué cargar | De dónde sale | Sin esto, qué no anda |
|---|---|---|---|
| Inteligencia artificial (Claude) ✅ verificada el 18/11 («La clave de IA funciona», modelo claude-haiku-4-5; clave «bigsys-web-bigcito» de la consola) | API key y modelo | console.anthropic.com | Bigcito y la IA de cada empresa (las empresas pueden poner la suya en Configuración → IA) |
| Correo saliente (SMTP) ✅ cargado el 18/11 (Hostinger, SSL 465, soporte@bigsysweb.com; la prueba llegó, pero a Spam: ver SPF/DKIM/DMARC abajo) | servidor, puerto, usuario, contraseña, cifrado, remitente | el proveedor de mail (Hostinger / Google Workspace) | Invitaciones, recibos por mail, avisos, recuperar contraseña. Botón **Enviar prueba** |
| Cobros de BigSys (Mercado Pago) ✅ cargado el 17/11 (app «Bigsys Web ERP» de BIGSYS S.R.L., Checkout Pro + API de Orders, webhook configurado) | Public key y Access token de la cuenta de BigSys | Mercado Pago → Tus integraciones → Crear aplicación (Checkout Pro, **API de Orders**) → credenciales de producción. Webhook: `/api/webhooks/mercadopago/suscripcion` con eventos Órdenes, Pagos y Planes y suscripciones | Cobro de la suscripción de los clientes (pago único y débito automático) |
| Voz de Bigcito (fase 119.2) y conversación en vivo (120.4) | clave de OpenAI o Gemini; para la conversación en vivo tiene que ser OpenAI, y se prende con un tilde aparte | platform.openai.com → API keys (o aistudio.google.com) | Sin clave, Bigcito sólo dicta donde el navegador lo permite y habla con la voz del navegador. Sin el tilde no aparece el 📞 |
| Casilla de facturas (fase 120.3) | servidor IMAP, puerto, usuario y clave de una casilla dedicada | el proveedor de mail (Hostinger: `imap.hostinger.com`, 993) | Las empresas no tienen dirección de mail para sus facturas de compra (igual pueden subir varias juntas) |
| Transferencia bancaria | titular, CBU, alias | banco de BigSys | Alternativa de pago de la suscripción |
| Ciclo de suscripción | días de prueba (15), gracia, avisos | decisión comercial | Ya tiene valores por defecto |
| Soporte y avisos | WhatsApp y email de soporte, mensaje global | — | Botón Soporte de las empresas |
| **Altas con aprobación** (fase 106) | **clave de aprobación** (mínimo 6 caracteres), horas de acceso provisional (72), mails y celulares a los que avisar, y quiénes más pueden cambiar la clave | la elegís vos; sólo en el formulario, nunca por chat. **Cargala vos primero**: el primero que la carga queda como dueño y sólo él y los que designe la pueden cambiar | **Sin clave no se puede aprobar ninguna empresa nueva** y a las 72 h se bloquean. Es lo primero del bloque B |
| Entrar con Google | Client ID y Client secret | console.cloud.google.com → OAuth, con `https://erp.bigsysweb.com` como origen autorizado | Botón «Entrar con Google» (sin esto el login con usuario y clave anda igual) |
| App del celular | JSON de la cuenta de servicio de Firebase, links de Play y App Store, huella SHA-256, Team ID de Apple | ver `docs/MOVIL.md` «Lo que hace falta una sola vez» | Avisos push a la app; **Probar push** confirma |
| Aplicaciones para conectar tiendas | App ID y Secret de Mercado Pago (cobros en el local), MercadoLibre y Tienda Nube | cada plataforma, panel de desarrolladores, con la URL de retorno del ERP | El botón «Conectar» de cada empresa en Configuración → Tienda y canales |
| La empresa de BigSys en el ERP | crear o elegir la empresa propia | — | Facturar la suscripción a los clientes desde el ERP |

Y en el servidor (sesión «CRM Producción», archivo `.env` de `/opt/bigsysweb`):

- [x] **Copias fuera del servidor**: hecho (Panel → Salud el 19/11: «Todas las copias están también en backblaze», ensayo de restauración ✓).
- [x] **SPF, DKIM y DMARC de bigsysweb.com**: verificados el 18/11 en el DNS de Hostinger (TXT `v=spf1 include:_spf.mail.hostinger.com ~all`, CNAME `hostingermail-a/b/c._domainkey`, TXT `_dmarc` `v=DMARC1; p=none`). La prueba del 18/11 cayó en Spam de Gmail por reputación de dominio nuevo, no por DNS: marcar «No es spam» y agregar `soporte@bigsysweb.com` a contactos; mejora sola con el uso.
- [x] **Mails de monitoreo**: cargados (Panel → Salud: juanandresrodriguez.a@gmail.com y gerencia@bigsys.com.ar reciben error nuevo, salud crítica y resumen semanal).
- [ ] **Sentry** (opcional, se puede dejar así): sin DSN; los errores llegan por mail y quedan en Panel → Salud.

## C · App del celular (publicarla)

Está compilable y con la ficha redactada (`docs/MOVIL.md`). Falta lo que cuesta plata y requiere identidad:

- [ ] **Firebase**: proyecto, app Android `ar.com.bigsys.erp` (`google-services.json`), app iOS mismo bundle (`GoogleService-Info.plist`), clave APNs. Cuenta de servicio → JSON → Panel → Sistema → App del celular.
- [ ] **Google Play Console** (25 USD una vez): keystore con `keytool`, secretos del repo `ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`, `GOOGLE_SERVICES_JSON`. El workflow deja el AAB. Ficha, política de privacidad (`https://erp.bigsysweb.com/privacidad`, ya publicada), formulario de seguridad de datos (respuestas en `docs/MOVIL.md`).
- [ ] **Apple Developer** (99 USD/año, pide DUNS a nombre de la empresa): App ID con Push, certificado de distribución, perfil. Compilar con Mac o Codemagic. Notas para la revisión en `docs/MOVIL.md`.
- [ ] Cuando estén publicadas: pegar los links de Play y App Store en Panel → Sistema para que el ERP los muestre.

## D · Por cada empresa cliente (lo guía `docs/ayuda/implementacion.md`, 5 días)

Lo que sigue es lo que **no** puede hacer el sistema solo. El resto (artículos, clientes, roles) está en la guía.

### Bigcito por WhatsApp (fase 111)

- [ ] Cargar el **celular de cada usuario** en Configuración → Usuarios (con código de área).
- [ ] WhatsApp Cloud API de la empresa en Configuración → Tienda y canales → Pedidos por WhatsApp (phone ID, token, webhook) y dejar tildado «Bigcito atiende al equipo».
- [ ] **Voz de Bigcito para todas las empresas** (fase 119.2): cargar una clave de **OpenAI o Gemini** en Panel → Sistema → «Voz de Bigcito». Con eso Bigcito escucha en iPhone/Safari/Firefox, entiende audios de WhatsApp y contesta con voz natural, sin que cada empresa cargue nada (antes era por empresa en Configuración → Conexiones IA).
- [ ] Probar: mandar «cuánto vendí hoy» y después un audio «cobrale 1000 a (cliente) en efectivo» y responder SÍ.

### ARCA (fase 102 a 104 ya contemplan letras y condiciones)

- [ ] **Certificado**: clave fiscal → Administrador de Relaciones → adherir **WSFE** (y `ws_sr_padron_a13`/`a5` para padrón). CSR con `openssl` (comando en `docs/arca.md`), bajar el `.crt`. Para homologación, certificado del entorno de homologación.
- [ ] **Cargarlo**: Configuración → Puntos de venta → Factura electrónica: `.crt` y `.key`. Punto de venta dado de alta en ARCA como *Web Services* con el mismo número.
- [ ] **Probar conexión** y emitir los **comprobantes de prueba** que ARCA pide para habilitar producción: A a responsable inscripto, B a consumidor final y a monotributista, NC A asociada, ND, una con servicios. El ERP los arma solo.
- [ ] **Pasar a producción**: certificado de producción, tildar *Modo producción* en Configuración → Empresa, probar de nuevo, primera factura real y **Verificar en ARCA**.
- [ ] **Clientes**: revisar la condición IVA de cada uno (los monotributistas reciben **A** con la leyenda de la ley 27.618; sin CUIT, consumidor final). Marcar **«Comprobante interno por defecto»** sólo en los clientes que corresponda (fase 103).
- [ ] **Impuestos**: Configuración → Impuestos sólo si la empresa es agente de percepción o retención.
- [ ] **Comprobantes internos** (los que no van a ARCA, letra X, fases 103 y 104): ya están hechos. Sólo hay que marcar el tilde en la venta o en el cliente, y usar **Facturar** después si esa venta tiene que pasar a fiscal. No hay nada que configurar.

### Plata

- [ ] **Mercado Pago de la empresa**: Configuración → Mercado Pago → Conectar (necesita el App ID de BigSys del bloque B). Después, en Contable → Conciliación, en la cuenta de billetera tildar **«Esta es la cuenta de Mercado Pago»** para que **Traer de Mercado Pago** cargue cobros, comisiones y devoluciones (fase 99).
- [ ] **Banco**: crear la cuenta en Fondos con saldo inicial al día del corte; importar el primer extracto y dejar armadas las **reglas** de conciliación con «Recordar».
- [ ] **Mercado Libre, Tiendanube, WhatsApp**: Configuración → Tienda y canales, botón Conectar de cada uno.

### Contador

- [ ] Darle acceso en **Contable → Contador** con su email (si ya usa el ERP con otro cliente, el mismo email).
- [ ] **Posición IVA**: cerrar los meses ya presentados para que el arrastre de saldo técnico y libre disponibilidad arranque bien (fase 99). Cargar con «Ajuste del contador» el saldo a favor inicial.
- [ ] **Sueldos**: tabla de **Ganancias 4ta** vigente en Preferencias de sueldos (la actualiza ARCA por semestre); **feriados** puente del año (los fijos vienen cargados); primera presentación del **Libro de Sueldos Digital** comparada con la que hizo el estudio; firma del empleador en Sueldos → Firmas y recibos (fase 98).

### Migración desde el BigSys de escritorio

- [ ] **Vía A, con BIG Connect**: en BIG Connect → Importar y exportar → API, clave `bk_` con acceso completo + dirección del Hub, cargadas en Configuración → Importar (nunca por chat). **Probar sin guardar**, revisar, **Migrar de verdad**, y el día del corte repetir y correr el **Control de cierre** (fase 101). Trae proveedores, clientes, artículos, saldos, rubros, vendedores, empleados, cajas y bancos, cheques, precios por proveedor y ventas de 12 meses.
- [ ] **Vía B, subir la carpeta `.tps`** (para clientes sin BIG Connect): no está construida. Para hacerla necesito **un juego de `.tps` de prueba** (`dat/eNN` + `gen`, puede ser demo o anonimizado): subirlo al Hub o compartir la carpeta con Remote Control. Estudio completo en `docs/MIGRACION-BIGSYS-LEGACY.md`.
- [ ] **El corte**: día de corte a inicio de mes, saldos del cierre del día anterior, facturas en papel de la transición como *Manual de talonario*, la primera semana revisar Entregar → Por entregar y Plata → Cerrar caja cada tarde (guía de implementación).

## E · Lo que no es software (fase 100 del plan)

- [ ] **Primer cliente facturando con ARCA** en producción (el bloque D completo con una empresa real). Es la prueba que falta.
- [x] **Página de ventas**: hecha en el ERP, `erp.bigsysweb.com/conocer` (y la raíz sin sesión): funciones, Bigcito de noche, rubros, planes con precio real y registro. Privacidad y términos ya publicados en `erp.bigsysweb.com/privacidad` y `/terminos` (sirven para las tiendas). Lo que queda tuyo: que `bigsys.com.ar/bigsys-web` apunte a `erp.bigsysweb.com/conocer` o al registro.
- [ ] **Videos cortos**: los cinco guiones, segundo a segundo, están en `docs/VIDEOS.md`. Falta grabarlos (pantalla + celular + voz en off).
- [ ] **App publicada** (bloque C).

## F · Lo que yo sigo haciendo (software), para no mezclarlo

- Factura de exportación con CAE (sólo si algún día una empresa vende al exterior; no tiene que ver con los comprobantes internos). CAEA para contingencia larga.
- Vía B de migración cuando tenga los `.tps`.
- Pruebas de PostgreSQL que fallan por orden (`GruposFase82Test`, `MenuFase79Test`, `GastronomiaHorariosReporteFase52Test`): pasan solas, no afectan producción.
- Lo que aparezca de usar el bloque D con el primer cliente.

---

Cuando algo de esta lista quede hecho, tildarlo acá (o decírmelo y lo tildo yo). El plan en el documento de Claude tiene esta misma lista en la sección «Repaso 100 % operativo».
