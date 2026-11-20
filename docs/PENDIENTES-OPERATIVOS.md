# Repaso: lo que falta para dejar el ERP 100 % operativo

Actualizado el **20/11/2026**. Producción (`erp.bigsysweb.com`) está al día con `main` (`d299a5d`, hasta la Fase 123.4). El software está. Lo que sigue son cuentas, claves, aprobaciones de terceros y pruebas con datos reales, y eso sólo lo puede hacer el dueño de las cuentas.

**Cuando Juan pregunte «¿qué me falta?», la respuesta sale de esta lista**: lo que está sin tildar, en este orden.

**Regla de siempre: ninguna clave, token, certificado ni contraseña va por el chat.** Se cargan en los formularios del ERP o en el servidor desde la sesión «CRM Producción».

Referencias: ✅ hecho · ⏳ falta probarlo con datos reales · ❌ falta hacerlo.

---

## 1 · Lo que quedó hecho (para no repetirlo)

- ✅ **Factura electrónica por BigSys** (20/11): certificado propio del ERP, alias `bigsyserp`, BIGSYS S.R.L. 30-71438573-5, **producción**, vence el **19/11/2028**. La relación «Facturación Electrónica» está hecha en ARCA y **Probar** dio los dos tildes verdes. El aviso de vencimiento sale solo a los 30, 7 y 1 día antes.
- ✅ **La empresa BigSys factura en el ERP** con el **punto de venta 0008** («BIGSYS WEB ERP», RECE). Los puntos 0001, 0005, 0006 y 0007 son del CRM, de Odoo y de otros sistemas: **no sumarlos al ERP**.
- ✅ **Copia completa de la base** (20/11): clave generada a las 10:36 y primera copia de 0.84 MB, 198 tablas, comprobada y en Backblaze. Corre sola cada noche a las 03:20. El manual de cada caso está en `docs/MANUAL-DATOS-Y-RESTAURACION.md` y publicado como doc.
- ✅ **Copias por empresa** fuera del servidor (Backblaze) y verificadas, con ensayo de restauración los domingos.
- ✅ **Entrar con Google** (20/11): proyecto propio «Bigsys Web ERP» en Google Cloud, en producción y sin logo, separado del proyecto del CRM. Se probó que Google acepta el cliente.
- ✅ **Casilla de facturas** (20/11): `facturacion@bigsysweb.com`, IMAP de Hostinger, «La casilla contesta bien».
- ✅ **IA (Claude)**, **correo saliente (SMTP)**, **SPF, DKIM y DMARC**, **cobros de BigSys por Mercado Pago**, **altas con aprobación**, **mails de monitoreo**, **aplicación de Meta para WhatsApp** («Estado: lista»).
- ✅ **Primeros pasos por etapas** y **guía de implementación** para clientes que vienen de BigSys de escritorio (Fase 122).

## 2 · Esta semana (lo más importante)

- [ ] ⏳ **Primera factura real de BigSys**: con «Bigsys Web ERP», Vender → Nueva factura → Consumidor Final → importe chico. Tiene que salir con **CAE** y número **0008-00000001**. Si es sólo de prueba, anularla con nota de crédito.
- [ ] ⏳ **Probar la casilla con un PDF**: copiar la dirección de una empresa en Comprar → Facturas de compra (`facturacion+código@bigsysweb.com`), mandarle una factura y fijarse que en 5 minutos aparezca «Para revisar». Si no llega, puede ser que Hostinger no acepte el «+código»; avisar.
- [ ] ❌ **Clave de las copias guardada afuera**: confirmar que la clave generada el 20/11 está en el gestor de contraseñas y en papel. Si no, Panel → Sistema → **Cambiar la clave** y hacer una copia nueva. Después hacer el **ensayo** de la sección 5 del manual: bajar una copia de Backblaze y abrirla con la clave.
- [ ] ❌ **WhatsApp de la empresa BigSys**: entrar a «Bigsys Web ERP» → Configuración → Tienda y canales → **Conectar WhatsApp** y escanear el QR. Después **Pedir las plantillas a Meta**. Sin esto no salen por WhatsApp los avisos de altas nuevas ni de vencimiento del certificado, ni los recordatorios a los clientes de BigSys.
- [ ] ❌ **Celulares de aviso**: cargar los celulares en Panel → Sistema → «Altas con aprobación» → «Avisar por WhatsApp a», y activar los avisos en el celular de al menos un superadmin (la campanita del navegador o la app).
- [ ] ⏳ **Probar la contingencia con CAEA** (Fase 126), primero en homologación:
  - dar de alta en ARCA un punto de venta para CAEA para BigSys (por ejemplo el 0009);
  - agregarlo en el ERP con modo «CAEA (contingencia)» y tildar «Activa» en Configuración → Puntos de venta y ARCA. Tiene que mostrar el CAEA de la quincena.
  
  La prueba de una factura con ARCA caído la hacemos juntos.
- [ ] ⏳ **Probar «Facturar después»** (Fase 104): una venta tildada como comprobante interno y, desde el comprobante, **Facturar**. Tiene que salir con CAE.

## 3 · Conexiones de la plataforma (Panel → Sistema, una sola vez)

Son las aplicaciones de BigSys en cada plataforma. Con eso, **cada empresa conecta la suya con un botón**, sin copiar claves. **El paso a paso, con cada dirección exacta, está en `docs/GUIA-conexiones-plataforma.md`** (y la misma tarjeta del panel muestra las direcciones para copiar).

- [ ] ❌ **Mercado Pago para las empresas** (cobros en el local, QR, Point y conciliación): en Mercado Pago Developers, una aplicación de BigSys para «conectar cuentas de terceros» (OAuth). Su **App ID** y **Secret** van en Panel → Sistema → «Aplicaciones para conectar tiendas». Es otra cosa que el Mercado Pago con el que BigSys cobra sus suscripciones, que ya está hecho.
- [ ] ❌ **Tienda Nube**: alta como socio en partners.tiendanube.com, crear la aplicación y cargar su **App ID** y **Secret**. Tienda Nube revisa la aplicación antes de publicarla; los avisos de datos personales (LGPD) que pide ya están hechos (Fase 125).
- [ ] ❌ **Mercado Libre**: aplicación en developers.mercadolibre.com.ar, con la URL de retorno y la de notificaciones que muestra el panel. Cargar su **App ID** y **Secret**.
- [ ] ⏳ **Voz de Bigcito** (OpenAI): la clave está cargada. Falta probar el micrófono desde un iPhone y la **conversación en vivo** (📞). Si no conecta, destildarla y avisar con el mensaje que muestre.
- [ ] Opcional: **Sentry** (DSN en Panel → Salud). Sin él, los errores igual llegan por mail y quedan en el panel.

WooCommerce y Shopify no necesitan aplicación de BigSys: cada empresa pega sus propias claves.

## 4 · Delivery para restaurantes: PedidosYa, Rappi y Uber Eats

Los conectores ya están hechos (Fase 54): toman cada pedido solo, lo mandan a la cocina de Big Restó, avisan cuando está listo, suben la carta y pausan lo agotado. Sin las claves de cada plataforma, igual se puede recibir pedidos por webhook a través de un integrador. Lo que falta es **que cada plataforma apruebe a BigSys como integrador**. Ese trámite lo hace BigSys una vez con cada una, y después cada restaurante conecta su local.

- [ ] ❌ **PedidosYa** (Delivery Hero): pedir el alta como integrador de punto de venta en el portal de socios. Dan credenciales de prueba y, después de certificar la integración, las de producción.
- [ ] ❌ **Rappi**: pedir el alta como integrador en el portal de aliados de Rappi. Dan `client_id` y `client_secret`, primero de prueba.
- [ ] ❌ **Uber Eats**: pedir acceso a las «Marketplace APIs» de Uber Eats en developer.uber.com. Uber revisa el caso de uso y habilita primero un entorno de prueba.
- [ ] ⏳ Con las credenciales de prueba de cada una, **probarlo con un restaurante piloto**. Esa prueba la hacemos juntos: puede que haya que ajustar detalles del conector a lo que pida cada plataforma en su certificación.

Los pasos exactos de cada portal cambian seguido. Cuando arranques uno, mandame captura y lo seguimos juntos.

## 5 · Big Restó (restaurantes), antes del primer cliente

- [ ] ⏳ **Prueba completa en un local**: mesas y salón, comandas a cocina, pedido por QR desde la mesa, cobro y cierre de caja. Si hay impresora de comandas, probarla con la del local.
- [ ] ⏳ **Carta con opciones** (guarniciones, puntos de cocción): armarla una vez y revisar que salga bien en el QR y en las plataformas de delivery.

## 6 · App del celular (Android y iPhone)

Está compilable y la ficha de la tienda ya está redactada (`docs/MOVIL.md`). Falta lo que cuesta plata y requiere identidad:

- [ ] ❌ **Firebase** (avisos push): proyecto en console.firebase.google.com; app Android `ar.com.bigsys.erp` (`google-services.json`); app iOS con el mismo identificador (`GoogleService-Info.plist`) y clave APNs de Apple. La **cuenta de servicio (JSON)** va en Panel → Sistema → «App del celular» → **Probar push**. Hoy dice «Sin Firebase: la app anda, pero sin avisos».
- [ ] ❌ **Google Play Console** (25 USD, una vez): keystore y secretos del repositorio (`ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`, `GOOGLE_SERVICES_JSON`). El workflow deja el AAB listo para subir.
- [ ] ❌ **Apple Developer** (99 USD por año, pide DUNS a nombre de la empresa): App ID con Push, certificado y perfil de distribución. Se compila con Mac o Codemagic.
- [ ] ❌ Cuando estén publicadas: pegar los **links de Play y App Store**, la **huella SHA-256** y el **Team ID** en Panel → Sistema.

## 7 · Legal y comercial

- [ ] ❌ **Inscribir las bases de datos de BigSys ante la AAIP**, por TAD, con la clave fiscal de BigSys y el estatuto. **El borrador de qué cargar en cada campo está en `docs/LEGAL-inscripcion-AAIP.md`**: falta completar domicilio, representante, el país del servidor y la región de Backblaze, y que lo revise el abogado (sobre todo las transferencias a Estados Unidos y los plazos de la política de privacidad).
- [ ] ❌ **Abogado y contador**: revisar `docs/LEGAL-facturacion-por-bigsys.md`. Al final está la lista de lo que les queda confirmar.
- [ ] ❌ **Revisar en ARCA quién representa a BIGSYS S.R.L.**: además de Juan figuran 20-16117738-6, 20-26314728-7 y 20-21877471-8. Quitar a quien ya no deba tener acceso.
- [ ] ❌ **Primer cliente piloto** facturando con ARCA y usando el ERP de verdad (el bloque 8 completo).
- [ ] ❌ **Videos cortos**: los cinco guiones están en `docs/VIDEOS.md`. Falta grabarlos.
- [ ] ⏳ **Probar las propuestas de Bigcito**: Inicio → Propuestas → «Revisar ahora»; aprobar una de cobranza y una de precios.

## 8 · Por cada empresa cliente

Lo guía la pantalla **Primeros pasos**, con el siguiente paso siempre a la vista, y `docs/ayuda/implementacion.md`. Lo que no puede hacer el sistema solo:

- [ ] **Datos de BigSys de escritorio** con BIG Connect: clave `bk_` y dirección del Hub en Configuración → Importar → **Probar sin guardar** → **Migrar de verdad** → el día del corte, repetir y correr el **Control de cierre**.
- [ ] **ARCA**: punto de venta para web services y, en el «Administrador de Relaciones», Nueva relación → «Facturación Electrónica» → Representante: el **CUIT de BigSys** (30-71438573-5). Después **Verificar en ARCA**, el dueño acepta y activa, y la primera factura real.
- [ ] **WhatsApp** de la empresa con el QR, **plantillas** pedidas a Meta y el **celular de cada usuario** cargado.
- [ ] **Mercado Pago** de la empresa (cuando esté el punto 3) y el **banco** con su primer extracto y las reglas de conciliación.
- [ ] **Contador** con acceso; **posición IVA** con los meses ya presentados cerrados; **sueldos** con la tabla de Ganancias vigente, si liquida en el ERP.
- [ ] **El corte**: un día de corte a principio de mes, los saldos del cierre del día anterior y, la primera semana, revisar cada tarde Por entregar y Cerrar caja.

## 9 · Lo que sigue siendo software (lo hago yo cuando haga falta)

- **Migración vía B**, subiendo la carpeta `.tps` del BigSys de escritorio, para clientes sin BIG Connect. Necesito un juego de `.tps` de prueba (puede ser de una empresa demo o con datos anonimizados).
- **Factura de exportación con CAE** por BigSys (hoy sólo con certificado propio). El **CAEA** para cortes de ARCA ya está (Fase 126); falta probarlo en homologación (ver punto 2).
- Lo que surja de las pruebas de los puntos 2, 4, 5 y del primer cliente.

---

Cuando algo quede hecho, decímelo y lo tildo acá.
