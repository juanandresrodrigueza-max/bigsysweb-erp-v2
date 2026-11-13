---
titulo: App del celular (iPhone y Android)
modulo: alertas
rutas: /avisos-celular, /privacidad, /terminos
orden: 10
resumen: Qué es la app nativa de BigSysWeb, qué gana respecto del navegador, cómo se instala, cómo llegan los avisos, qué permisos pide y cómo se borra la cuenta.
---

# App del celular (iPhone y Android)

BigSysWeb se usa desde el navegador en cualquier computadora o celular. Además, está preparado como **app nativa** para iPhone (App Store) y Android (Google Play): es exactamente el mismo sistema, con las mismas pantallas y el mismo usuario, adentro de una app.

## Qué gana la app respecto del navegador

- **Avisos al toque**: las alertas, el parte de la mañana y las novedades llegan por el sistema de notificaciones del teléfono aunque el sistema esté cerrado. En iPhone, desde el navegador los avisos pueden demorar o no llegar; con la app llegan como un WhatsApp.
- **Bigcito escucha mejor**: usa el micrófono del teléfono directo, sin pasar por el navegador.
- **Los links abren la app**: un link a erp.bigsysweb.com (un mail de cobranza, un aviso) abre directo en la app, ya logueado.
- **Pantalla completa**, con el ícono de BigSys y sin la barra del navegador.

## Cómo se instala

Cuando la app esté publicada, al entrar desde el celular web aparece la barra **«Instalar la app»** con el link de tu tienda (App Store o Google Play). Se instala como cualquier app y se entra con el mismo mail y contraseña. Si cerrás la barra, vuelve a aparecer a los 30 días; adentro de la app nunca se muestra.

Mientras no esté publicada, podés seguir usando **Agregar a inicio** (iPhone, Safari → Compartir) o **Instalar app** (Android, Chrome → ⋮): queda con el ícono de BigSys y se abre como una app, aunque los avisos siguen siendo los del navegador.

## Avisos: cómo se registran los celulares

La app registra el celular sola la primera vez que entrás (pide el permiso de notificaciones, como cualquier app). En **Avisos al celular** (✨ arriba a la derecha) ves cada teléfono registrado con su modelo y la última vez que avisó, y podés quitar los que ya no uses. Un mismo usuario puede tener varios celulares. Si cambiás de teléfono, entrá desde el nuevo y listo.

Los avisos que llegan son los mismos que por el navegador: alertas críticas, el parte de la mañana a la hora que elija el dueño y las novedades de BigSys. Qué avisa cada cosa está en **Alertas, asistente y avisos al dueño**.

## Permisos que pide y para qué

- **Notificaciones**: para los avisos. Si decís que no, la app anda igual, sin avisos.
- **Micrófono**: sólo cuando le hablás a Bigcito.
- **Cámara**: sólo cuando sacás una foto para Bigcito o escaneás un código de barras.

No usa la ubicación ni lee tus contactos.

## Privacidad, términos y borrar la cuenta

Las páginas **/privacidad** y **/terminos** están linkeadas abajo del login y del registro. Dicen qué datos guarda el sistema (los de tu empresa: clientes, comprobantes, stock, plata), que no se venden ni se comparten salvo con quien hace falta para operar (ARCA, Mercado Pago, el envío de mails y WhatsApp) y cómo pedir una copia o la baja. **Borrar mi cuenta**: si sos el dueño, desde Configuración → Suscripción → Dar de baja la empresa, o escribiendo a privacidad@bigsys.com.ar; se borra la cuenta y, si sos el dueño, la empresa entera, salvo lo que la ley fiscal obliga a conservar.

## Si algo no anda

- **No llegan los avisos**: en Avisos al celular tocá **Probar**. Si el de prueba llega, el resto también; si no, fijate que la app tenga permiso de notificaciones en los ajustes del teléfono.
- **La app abre una pantalla en blanco**: cerrala del todo y volvé a abrirla; si sigue, actualizala desde la tienda.
- **Un link abre el navegador en vez de la app**: es normal hasta que la app esté instalada; con la app instalada abre adentro.

## Para quien administra BigSys

La configuración (cuenta de servicio de Firebase para los avisos, links de las tiendas, huella de Android y Team ID de Apple, botón Probar push) está en **Panel → Sistema → App del celular**, una sola vez para todas las empresas. Los pasos para compilar y publicar están en `docs/MOVIL.md` del repositorio.

## A Bigcito

«¿Hay app para el celular?», «¿por qué no me llegan los avisos?», «¿cómo borro mi cuenta?», «¿qué permisos pide la app?».
