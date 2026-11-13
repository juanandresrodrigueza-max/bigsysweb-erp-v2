# La app de BigSys para iPhone y Android (Fase 93)

El ERP corre adentro de una app nativa hecha con **Capacitor**: la app abre `https://erp.bigsysweb.com` en una vista web
propia y le suma lo que el navegador no da en el celular: **notificaciones de verdad** (APNs en iPhone, FCM en Android),
el **micrófono y la cámara** con permisos nativos para Bigcito y las fotos, barra de estado y splash con la marca, y el botón
"atrás" de Android. Cada mejora del ERP llega sola a la app: no hay que republicar para cambiar una pantalla.

## Qué hay en el repo

- `capacitor.config.json`: id `ar.com.bigsys.erp`, nombre **BigSys**, la URL del servidor y los dominios permitidos (ERP y Mercado Pago).
- `movil/www/index.html`: la pantalla "Sin conexión" que se ve si no llega al servidor.
- `android/` y `ios/`: los proyectos nativos (generados con `npx cap add`, con permisos y textos de uso ya puestos).
- `assets/`: ícono, ícono adaptativo y splash en 1024/2732 px; `npx capacitor-assets generate` los vuelca a los dos proyectos.
- `resources/js/util/movil.js`: el puente. Adentro de la app pide permiso de notificaciones, registra el token en `POST /movil/dispositivo`,
  abre la pantalla del aviso cuando lo tocan, maneja el botón atrás y refresca al volver del fondo. Afuera de la app no hace nada.
- Servidor: tabla `dispositivos_movil`, `PushNativoService` (FCM HTTP v1 con la cuenta de servicio de Firebase), y
  `CelularService::enviar` manda a la vez por web push y por la app. Todo aviso que ya existía (críticas, parte de la mañana,
  "te llaman de la mesa", pedido listo) llega a la app sin tocar nada más.
- `.github/workflows/movil.yml`: en cada cambio compila el APK de Android (y el AAB firmado si están los secretos) y verifica que el proyecto de iPhone compile.

## Lo que hace falta una sola vez (lo hace el dueño de las cuentas, no va por chat)

1. **Firebase** (gratis): crear un proyecto, agregar una app Android con el paquete `ar.com.bigsys.erp` y bajar `google-services.json`;
   agregar una app iOS con el mismo bundle id y bajar `GoogleService-Info.plist`. En *Cloud Messaging* subir la **clave APNs** (.p8) que se
   genera en Apple Developer → Keys. Después: *Configuración del proyecto → Cuentas de servicio → Generar nueva clave privada*: ese JSON se pega en
   **Panel → Sistema → App del celular** del ERP (queda cifrado). Con eso los avisos ya salen.
2. **Google Play Console** (25 USD una vez): crear la app, subir el AAB firmado, completar la ficha (textos abajo), política de privacidad
   (`https://bigsys.com.ar/privacidad`), clasificación de contenido y el formulario de seguridad de datos. La firma: generar un keystore
   (`keytool -genkey -v -keystore bigsys.keystore -alias bigsys -keyalg RSA -keysize 2048 -validity 10000`) y cargarlo como secretos del repo
   (`ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`) y `GOOGLE_SERVICES_JSON` (base64).
   El workflow deja el AAB listo para subir.
3. **Apple Developer** (99 USD por año, a nombre de la empresa pide número DUNS): crear el App ID `ar.com.bigsys.erp` con *Push Notifications*,
   la clave APNs, un certificado de distribución y un perfil de App Store. Con una Mac (o un servicio en la nube como Codemagic): `npx cap sync ios`,
   abrir `ios/App/App.xcworkspace` en Xcode, poner el equipo de firma, agregar la *capability* Push Notifications y archivar → App Store Connect.
   `GoogleService-Info.plist` va en `ios/App/App/` (está en `.gitignore`).

## Cómo se compila

```bash
npm ci
npx cap sync                      # copia movil/www y actualiza los plugins en android/ e ios/
cd android && ./gradlew assembleDebug    # APK para probar (android/app/build/outputs/apk/debug/)
npx cap open ios                  # en una Mac: abre Xcode
```

Para apuntar a otro servidor (pruebas), cambiar `server.url` en `capacitor.config.json` y volver a `npx cap sync`.

## Ficha para las tiendas

- **Nombre**: BigSys · **Subtítulo**: Tu negocio en el bolsillo.
- **Descripción corta**: Vendé, facturá con ARCA, cobrá, controlá el stock y cerrá la caja desde el celular. Con Bigcito, tu asistente con IA.
- **Descripción**: BigSys Web es el ERP 100 % web para PyMEs argentinas. Con la app tenés en el celular lo que más usás: Mi negocio de un vistazo,
  vender en la calle, cobrar y mandar recibos, el mozo de bolsillo de Big Restó, las alertas al instante y Bigcito, que entiende voz, fotos y audios.
  Las acciones importantes se confirman y queda registrado quién hizo qué. Necesitás una cuenta de BigSys Web (prueba gratis de 15 días en bigsys.com.ar).
- **Categoría**: Negocios. **Edad**: 4+ / Todos. **Pagos**: la suscripción se contrata en la web, la app no vende nada adentro.
- **Capturas**: Mi negocio, Mozo de bolsillo, Vender en la calle, Bigcito, Alertas (390 × 844 en claro).

## Por qué Apple la aprueba

No es "sólo una web": trae notificaciones nativas, micrófono y cámara con permisos propios, funciona con cuenta existente (apps de negocio,
guía 3.1.3(b) y 4.2), y no cobra nada adentro. Si piden una cuenta de prueba, usar la demo (`demo@bigsys.com.ar` / `password`).
