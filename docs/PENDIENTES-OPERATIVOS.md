# Lo que falta para dejar el ERP 100 % operativo (lo hace Juan, no el código)

Escrito el 15/11/2026 y actualizado el 16/11: producción en `erp.bigsysweb.com` al día con `main` (`0c6bfc4`, todo hasta la fase 111: Bigcito por WhatsApp).
El software está. Lo que sigue son cuentas, claves, certificados y decisiones que sólo puede hacer el dueño de las cuentas.
**Regla de siempre: ninguna clave, token, certificado ni contraseña va por el chat.** Todo se carga en los formularios del ERP o en el servidor desde la sesión «CRM Producción».

Orden sugerido: primero el bloque A (mañana a la mañana), después B y C en el día, D y E cuando arranque el primer cliente.

---

## A · Mañana, antes que nada (15 minutos)

- [x] **Salud**: `ok` desde el 15/11 a la noche (CRM Producción volvió a medir las páginas, 14 a 89 ms, y resolvió 6 errores que eran de su propia consola).
- [ ] **Probar en producción «Facturar después»** con la empresa de BigSys o una de prueba: hacer una venta tildada como comprobante interno, y desde el comprobante apretar **Facturar** (fase 104). Tiene que salir con numeración fiscal y CAE (o simulado si la empresa no tiene certificado). Es la única función nueva que no se probó con datos reales.
- [x] **Contraseña del superadmin** (`super@bigsys.com.ar`) cambiada el 16/11; la de fábrica ya no sirve.
- [x] **Clave de aprobación cargada** el 16/11 (Panel → Sistema → Altas con aprobación), con mail y celular de aviso. Falta la prueba de registro de abajo.
- [ ] **Probar el circuito de altas**: hecho el registro el 16/11 (empresa «Prueba Alta», `prueba.alta@bigsysweb.com`, entra con el aviso de acceso provisional hasta el 19/11). Falta: aprobarla en Panel → Pendientes con la clave, y después borrarla con «Eliminar definitivamente». (Antes decía: cargar la clave de aprobación en Panel → Sistema → Altas con aprobación (fase 106) y, si querés WhatsApp, los celulares. Después probar: registrarte con un mail de prueba en `/registro`, ver que te llega el aviso y aprobarla en Panel → Pendientes.
- [ ] **Probar las propuestas de Bigcito** (fase 107): en Inicio → Propuestas, botón «Revisar ahora»; aprobar una de cobranza y una de precios y ver que ejecuta. Abrir «Resumen hablado» en el celular y tocar Escuchar.
- [x] **Producción actualizada** el 16/11 al mediodía (commit `0c6bfc4`, fases 109 a 111; `/salud` ok y `/conocer` con la novedad de Bigcito por WhatsApp). Para las próximas: siempre con la sesión «CRM Producción», `curl -fsSL https://raw.githubusercontent.com/juanandresrodrigueza-max/bigsysweb-erp-v2/main/deploy/actualizar.sh | bash`.

## B · Plataforma BigSys (Superadmin → Sistema, una sola vez)

Todo esto se carga en **Panel → Sistema**. Queda cifrado y no se vuelve a mostrar. Lo que ya está cargado dice «(dejar vacío para no cambiar)».

| Bloque del formulario | Qué cargar | De dónde sale | Sin esto, qué no anda |
|---|---|---|---|
| Inteligencia artificial (Claude) | API key y modelo | console.anthropic.com | Bigcito y la IA de cada empresa (las empresas pueden poner la suya en Configuración → IA) |
| Correo saliente (SMTP) | servidor, puerto, usuario, contraseña, cifrado, remitente | el proveedor de mail (Hostinger / Google Workspace) | Invitaciones, recibos por mail, avisos, recuperar contraseña. Botón **Enviar prueba** |
| Cobros de BigSys (Mercado Pago) | Public key y Access token de la cuenta de BigSys | Mercado Pago → Tus integraciones → credenciales de producción | Cobro de la suscripción de los clientes |
| Transferencia bancaria | titular, CBU, alias | banco de BigSys | Alternativa de pago de la suscripción |
| Ciclo de suscripción | días de prueba (15), gracia, avisos | decisión comercial | Ya tiene valores por defecto |
| Soporte y avisos | WhatsApp y email de soporte, mensaje global | — | Botón Soporte de las empresas |
| **Altas con aprobación** (fase 106) | **clave de aprobación** (mínimo 6 caracteres), horas de acceso provisional (72), mails y celulares a los que avisar, y quiénes más pueden cambiar la clave | la elegís vos; sólo en el formulario, nunca por chat. **Cargala vos primero**: el primero que la carga queda como dueño y sólo él y los que designe la pueden cambiar | **Sin clave no se puede aprobar ninguna empresa nueva** y a las 72 h se bloquean. Es lo primero del bloque B |
| Entrar con Google | Client ID y Client secret | console.cloud.google.com → OAuth, con `https://erp.bigsysweb.com` como origen autorizado | Botón «Entrar con Google» (sin esto el login con usuario y clave anda igual) |
| App del celular | JSON de la cuenta de servicio de Firebase, links de Play y App Store, huella SHA-256, Team ID de Apple | ver `docs/MOVIL.md` «Lo que hace falta una sola vez» | Avisos push a la app; **Probar push** confirma |
| Aplicaciones para conectar tiendas | App ID y Secret de Mercado Pago (cobros en el local), MercadoLibre y Tienda Nube | cada plataforma, panel de desarrolladores, con la URL de retorno del ERP | El botón «Conectar» de cada empresa en Configuración → Tienda y canales |
| La empresa de BigSys en el ERP | crear o elegir la empresa propia | — | Facturar la suscripción a los clientes desde el ERP |

Y en el servidor (sesión «CRM Producción», archivo `.env` de `/opt/bigsysweb`):

- [ ] **Copias fuera del servidor**: `OFFSITE_S3_ENDPOINT`, `OFFSITE_S3_BUCKET`, `OFFSITE_S3_REGION`, `OFFSITE_S3_ACCESS_KEY_ID`, `OFFSITE_S3_SECRET_ACCESS_KEY`. Si el CRM ya sube a Backblaze, se copia el bloque tal cual (mismo bucket, el ERP usa el prefijo `bigsysweb/erp/`). Después `php artisan config:cache && php artisan backups:probar`. Hasta que esté, la salud queda en aviso. Detalle en `docs/produccion.md`.
- [ ] **Mails de monitoreo**: `MONITOREO_EMAIL` en el `.env` o en Superadmin → Salud (manda lo del panel). Recibe error nuevo, salud crítica y resumen semanal.
- [ ] **Sentry** (opcional): DSN en Superadmin → Salud.

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
- [ ] Para audios: clave de **OpenAI o Gemini** en Configuración → Conexiones IA (Claude no escucha audio).
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
