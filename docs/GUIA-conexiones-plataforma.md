# Guía: las aplicaciones de BigSys en Mercado Pago, Mercado Libre y Tienda Nube

Se hace **una sola vez, para todas las empresas**. Cada plataforma tiene que conocer a BigSys como «aplicación». Una vez hecho eso, cada empresa conecta su cuenta desde el ERP con un botón (Configuración → Tienda y canales, o Configuración → Empresa → Mercado Pago), entra con su usuario de la plataforma y acepta. Nadie copia claves.

Cada aplicación te da dos datos: un **App ID** (también lo llaman *Client ID*) y un **Secret** (*Client Secret* o *Secret Key*). Los dos se cargan en **Panel → Sistema → «Aplicaciones para conectar tiendas con un botón»** y se toca **Guardar**. El Secret queda cifrado y no se vuelve a mostrar.

**El Secret no va por el chat ni en capturas.** Si sale en una captura, se regenera desde la misma plataforma.

Las pantallas de cada portal cambian seguido. Si un nombre no coincide, buscá el más parecido o mandame captura y lo seguimos juntos.

## Las direcciones que te van a pedir

Son las mismas que muestra la tarjeta del panel (con el botón de copiar al lado):

| Plataforma | Para qué | Dirección |
|---|---|---|
| Mercado Pago | Redirección (vuelta después de autorizar) | `https://erp.bigsysweb.com/conectar/mercadopago/volver` |
| Mercado Pago | Notificaciones (webhook) | `https://erp.bigsysweb.com/api/mercadopago/webhook` |
| Mercado Libre | Redirect URI | `https://erp.bigsysweb.com/conectar/mercadolibre/volver` |
| Mercado Libre | Notificaciones | `https://erp.bigsysweb.com/api/canales/mercadolibre/notificaciones` |
| Tienda Nube | Redirección después de instalar | `https://erp.bigsysweb.com/conectar/tiendanube/volver` |
| Tienda Nube | LGPD: borrar la tienda | `https://erp.bigsysweb.com/api/tiendanube/lgpd/store-redact` |
| Tienda Nube | LGPD: borrar un comprador | `https://erp.bigsysweb.com/api/tiendanube/lgpd/customers-redact` |
| Tienda Nube | LGPD: datos de un comprador | `https://erp.bigsysweb.com/api/tiendanube/lgpd/customers-data-request` |

Las tres tienen que ser exactamente así: `https`, sin barra al final y sin espacios. Si no coinciden letra por letra, la plataforma rechaza la vuelta con un error del estilo «redirect_uri mismatch».

---

## 1 · Mercado Pago (cobros de las empresas: QR, Point y conciliación)

Es **otra cosa** que el Mercado Pago con el que BigSys cobra las suscripciones, que ya está hecho. Ésta es la aplicación que deja a cada empresa conectar **su** cuenta de Mercado Pago.

1. Entrá a **developers.mercadopago.com.ar** con la cuenta de Mercado Pago **de BigSys** (BIGSYS S.R.L.).
2. Arriba a la derecha: **Tus integraciones** → **Crear aplicación**.
3. Nombre: `BigSys ERP`. Tipo de solución: **Pagos presenciales** (es lo que usan las empresas: QR y Point). Si pregunta si usás una plataforma de e-commerce, **No**. Producto: **Código QR** (si deja elegir más, sumá **Point**). Aceptá los términos y **Crear aplicación**.
4. Adentro de la aplicación: **Editar aplicación** (o «Configuración»).
   - En **URLs de redireccionamiento**, pegá `https://erp.bigsysweb.com/conectar/mercadopago/volver` y guardá. Sin esto, el botón «Conectar Mercado Pago» de las empresas falla.
5. Menú de la izquierda: **Credenciales de producción**. Si pide completar datos del negocio (rubro, sitio web `https://erp.bigsysweb.com`), completalos y activá las credenciales.
   - Copiá el **Client ID** y el **Client Secret** (no el «Access Token» ni la «Public Key»).
6. Opcional: **Webhooks** → **Configurar notificaciones** → modo productivo: `https://erp.bigsysweb.com/api/mercadopago/webhook`, eventos **Pagos** y **Órdenes comerciales**. El ERP ya le indica esta dirección a Mercado Pago en cada cobro, así que no es imprescindible.
7. En el ERP: **Panel → Sistema → «Aplicaciones para conectar tiendas»** → *Mercado Pago · App ID* = el Client ID, *Mercado Pago · Secret* = el Client Secret → **Guardar**.

**Prueba**: entrá a la empresa BigSys → Configuración → Empresa → Mercado Pago → **Conectar con Mercado Pago**. Te lleva a Mercado Pago, aceptás y volvés con «Conectado como …». El permiso se renueva solo.

## 2 · Mercado Libre (publicaciones, stock y ventas)

1. Entrá a **developers.mercadolibre.com.ar** con la cuenta de Mercado Libre **de BigSys** (la cuenta tiene que tener los datos completos y estar validada).
2. **Mis aplicaciones** → **Crear nueva aplicación**.
3. Completá:
   - **Nombre**: `BigSys ERP`. **Nombre corto**: `bigsyserp`. **Descripción**: «ERP de BigSys: sincroniza stock, precios y ventas de cada empresa».
   - **Logo**: el de BigSys (opcional).
   - **URIs de redirect**: `https://erp.bigsysweb.com/conectar/mercadolibre/volver`.
   - **PKCE**: dejalo **destildado** (el ERP guarda el secreto en el servidor y no usa PKCE).
   - **Unidades de negocio**: Mercado Libre.
   - **Scopes / permisos**: **Lectura**, **Escritura** y **Acceso offline** (*offline_access*). El acceso offline es el que deja renovar el permiso solo cada 6 horas; sin él, la conexión se corta al rato.
   - **Tópicos**: **Orders v2** (con *Orders feedback* alcanza; *Items* y *Questions* no hacen falta).
   - **URL de notificaciones (callbacks)**: `https://erp.bigsysweb.com/api/canales/mercadolibre/notificaciones`.
4. **Crear**. En la lista de aplicaciones aparecen el **ID de la aplicación** (App ID) y la **Secret Key**.
5. En el ERP: Panel → Sistema → *MercadoLibre · App ID* y *MercadoLibre · Secret* → **Guardar**.

**Prueba**: en la empresa BigSys (o una de prueba) → Configuración → Tienda y canales → **Conectar con MercadoLibre**. Aceptás con tu usuario y vuelve conectado. Una venta nueva en Mercado Libre tiene que aparecer sola en Vender → Pedidos web.

## 3 · Tienda Nube (tienda online)

Tienda Nube **revisa la aplicación** antes de publicarla en su tienda de aplicaciones, y la revisión lleva días. Mientras tanto, la aplicación ya se puede instalar en tiendas de prueba.

1. Entrá a **partners.tiendanube.com** y creá la cuenta de **socio tecnológico** a nombre de BIGSYS S.R.L. (piden CUIT, datos de contacto y sitio web).
2. **Aplicaciones** → **Crear aplicación** → tipo **Para la tienda de aplicaciones** (pública, para que cualquier empresa la instale). La opción «para un cliente específico» sólo sirve para una tienda.
3. **Datos básicos**: nombre `BigSys ERP`, descripción, logo, mail de soporte, sitio `https://erp.bigsysweb.com`.
4. **URL de redirección después de la instalación**: `https://erp.bigsysweb.com/conectar/tiendanube/volver`.
5. **Permisos** (scopes): **Products** lectura y escritura (stock y precios), **Orders** lectura y escritura (ventas y avisos de venta) y **Customers** lectura (los datos del comprador para la factura).
6. **Webhooks de LGPD (datos personales)**: Tienda Nube los pide para aprobar la aplicación. Cargá:
   - *Store redact*: `https://erp.bigsysweb.com/api/tiendanube/lgpd/store-redact`
   - *Customers redact*: `https://erp.bigsysweb.com/api/tiendanube/lgpd/customers-redact`
   - *Customers data request*: `https://erp.bigsysweb.com/api/tiendanube/lgpd/customers-data-request`
7. Guardá. En los datos de la aplicación aparecen el **ID de la aplicación** (App ID) y el **Client secret**.
8. En el ERP: Panel → Sistema → *Tienda Nube · App ID* y *Tienda Nube · Secret* → **Guardar**. El Secret es también el que usa el ERP para comprobar que cada aviso viene de verdad de Tienda Nube.
9. **Probala antes de mandarla a revisión**: en el portal de socios, creá una **tienda demo**. En el ERP, entrá a la empresa BigSys → Configuración → Tienda y canales → **Conectar con Tienda Nube**. Instalala en la tienda demo, hacé una compra de prueba y fijate que aparezca en Vender → Pedidos web. Para probar los avisos de LGPD, el portal tiene una herramienta de prueba de webhooks LGPD.
10. **Enviar a revisión**. Piden capturas, un video corto de la instalación y una tienda de prueba con la aplicación andando. Cuando la aprueben, avisame y lo tildamos.

### Qué hace el ERP con los avisos de datos personales

- **Borrar la tienda** (llega 48 horas después de que la tienda desinstala la aplicación): el ERP borra el acceso a esa tienda y le avisa a la empresa. Los pedidos y las ventas que ya están en el ERP quedan.
- **Borrar un comprador** o **pedido de datos de un comprador**: el ERP no borra nada solo. Le deja a la empresa un aviso con quién es, cuál es su cliente en el ERP (si lo encuentra por mail o documento) y qué pedidos de Tienda Nube son. La empresa es la responsable de los datos de sus clientes, y lo que figura en una factura se conserva 10 años por obligación fiscal.

---

## Cuando estén las tres

- Las empresas ven el botón **Conectar** en cada una y el paso queda tildado en Primeros pasos.
- WooCommerce y Shopify no necesitan aplicación de BigSys: cada empresa pega sus propias claves.
- Si una plataforma pide renovar o cambiar el Secret, se carga el nuevo en el mismo lugar del panel. Las empresas ya conectadas siguen andando con Mercado Pago y Mercado Libre mientras el App ID sea el mismo.
