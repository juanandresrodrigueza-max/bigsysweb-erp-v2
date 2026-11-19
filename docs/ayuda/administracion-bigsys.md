---
titulo: Panel de BigSys: alta de empresas, planes y sistema
modulo:
rutas: /admin
orden: 11
resumen: Para quien administra el servicio: dar de alta empresas, planes y cobros de suscripción, soporte, claves del sistema, métricas de uso y mantenimiento.
---

Esta guía es para el equipo que opera BigSysWeb como servicio (el superadmin), no para las empresas cliente.

## Alta de una empresa

**Panel → Empresas → Nueva empresa**: datos de la empresa, dueño (nombre, email y contraseña inicial), plan y modo (prueba de N días o activa con ciclo mensual o anual y medio de pago). Se crean la empresa, la sucursal, el punto de venta, la caja, el plan de cuentas y los roles. El dueño entra y ve "Primeros pasos" y la guía de implementación.

## Altas con aprobación (fase 106)

Como en el CRM: **nadie queda adentro sin que alguien de BigSys lo apruebe con la clave**.

- Quien se registra solo en `/registro`, o la empresa que da de alta un empleado de BigSys desde **Panel → Empresas → Nueva empresa**, entra al toque, pero la empresa y su dueño quedan **pendientes**: tienen acceso provisional por las horas que diga **Sistema → Altas con aprobación** (72 por defecto). El dueño ve arriba «Acceso provisional hasta el…».
- Si en ese plazo nadie la aprueba, **se bloquea sola**: también la sesión que ya estaba abierta, no sólo el próximo ingreso. La persona ve una pantalla de espera con el WhatsApp y el mail de soporte, y sus datos quedan guardados. Al aprobarla entra al instante.
- **Panel → Pendientes** lista las que esperan (quién, de dónde salió el alta, cuántas horas le quedan, si ya se bloqueó) y ahí se **aprueba** o se **rechaza** (queda suspendida con el motivo; desde la ficha se puede reactivar). Las dos acciones piden la **clave de aprobación**. Cinco intentos fallidos la traban 15 minutos y cada fallo queda en la auditoría. La ficha de la empresa tiene el mismo botón.
- **La clave** la carga el dueño de BigSys en **Sistema → Altas con aprobación**: se guarda como hash, no se vuelve a mostrar y **nunca va por chat**. Sin clave cargada no se puede aprobar nada. La misma clave se pide para crear otro superadmin.
- **Quién la cambia**: sólo quien la cargó primero (queda como dueño) y las personas que ese dueño designe en la misma pantalla, y siempre escribiendo la clave actual. Los demás superadmins la pueden usar para aprobar, pero ven «La clave la administran X, Y» y no pueden tocarla. Si se pierde o no queda ningún dueño, se resetea desde el servidor con `php artisan aprobacion:reset-clave` (hace falta acceso al servidor, no alcanza con el panel) y la próxima carga define al nuevo dueño.
- **Avisos a BigSys**: al registrarse, 12 horas antes del corte y cuando una queda bloqueada. Van por mail (a los que diga Sistema; vacío = superadmins + mail de soporte), por **WhatsApp** a los celulares cargados (sale por la línea de la empresa de BigSys, fase 87) y al **celular de cada superadmin** por la app. La revisión diaria de las 06:00 manda además un resumen: qué pruebas terminaron hoy (siguen en Gratis) y cuáles vencen pronto, para llamarlos antes de que se enfríen.
- Los usuarios que una empresa crea para su propia gente **no** pasan por BigSys: los maneja el dueño de la empresa, con el límite de su plan.

A Bigcito (superadmin): «¿qué empresas están pendientes de aprobar?». Aprobar se hace en el panel: Bigcito no pide ni recibe la clave.

### Por dónde llega el aviso de cada alta (fase 113.2)

En Panel → Sistema → Altas con aprobación, el recuadro **«Cuando alguien se registra, el aviso sale por»** muestra con ✓ o ✗ cada canal y por qué:

- **Mail**: necesita el correo saliente (bloque SMTP de la misma pantalla). Va a los mails de «Avisar por mail a» o, si está vacío, a soporte y a todos los superadmins.
- **WhatsApp**: sale por la **línea de WhatsApp Cloud de la empresa de BigSys** en el ERP. Hacen falta las dos cosas: los celulares en «Avisar por WhatsApp a» y, entrando como dueño de esa empresa, la API conectada en Configuración → Tienda y canales → Pedidos por WhatsApp.
- **Celular**: a los superadmins que tengan la app o el navegador suscriptos a los avisos.

El botón **«Enviar aviso de prueba por los tres canales»** manda uno de verdad y cuenta, canal por canal, qué salió y qué no (por ejemplo «Meta respondió 401: token inválido»).

## Dar de baja o eliminar una empresa (fase 113)

En la ficha de la empresa hay dos cosas distintas:

- **Dar de baja**: deja de operar, se cancela la suscripción y se desactivan sus usuarios. **Los datos quedan** y se puede **Restaurar** después. Es lo normal cuando un cliente se va.
- **Eliminar definitivamente**: borra la empresa con **todo** lo suyo (comprobantes, clientes, stock, plata, contable, usuarios, archivos). Antes muestra cuántas filas se van a borrar, pide escribir el **nombre exacto** de la empresa y la **clave de aprobación** (si está cargada). No se puede eliminar la empresa de BigSys ni la empresa en la que estás. Después no hay vuelta atrás salvo la copia de seguridad. Sirve para empresas de prueba o de demostración.

## Planes y cobros

**Débito automático (fase 117).** Con las credenciales de producción de Mercado Pago cargadas en Sistema → Cobros de BigSys (la misma aplicación sirve para el link y para el débito), las empresas pueden dejar la tarjeta y el ERP cobra solo cada período. Los cobros llegan por el webhook de Mercado Pago (`/api/webhooks/mercadopago/suscripcion`, avisos de pagos, de cobros de suscripción y de estado del débito) y, por si un aviso se pierde, la revisión diaria de las 06:00 consulta a Mercado Pago antes de dar por vencida una suscripción con débito. En la ficha de la empresa, el badge «Débito automático» dice si lo tiene.

**Cómo crear la aplicación en Mercado Pago (fase 117.1).** En Mercado Pago → Tus integraciones → Crear aplicación, con la cuenta de BigSys: solución **Checkout Pro**, tipo de API **API de Orders** (la de Preferences está en retirada; el ERP usa Orders y, si fallara, cae solo a Preferences). Después, en la misma aplicación: **Credenciales de producción** → Public key y Access token van a Sistema → Cobros de BigSys. En **Webhooks** → modo productivo: URL `https://erp.bigsysweb.com/api/webhooks/mercadopago/suscripcion` y tildar los eventos **Órdenes**, **Pagos** y **Planes y suscripciones** (así llegan el pago único, los cobros del débito y los cortes). No hace falta otra aplicación para el débito automático.

**Banco Central (fase 120.2).** La consulta a la Central de Deudores usa la API pública del BCRA (`api.bcra.gob.ar`), sin clave. El servidor tiene que poder salir a ese host. Si las consultas fallan con «no se pudo validar el certificado» (al BCRA a veces le falta un certificado intermedio), se agrega `BCRA_VERIFICAR_SSL=false` al `.env` y se corre `php artisan config:cache`: apaga la verificación sólo para esa consulta de datos públicos. Cada CUIT se guarda 12 horas para no repetir llamadas.

**Voz de Bigcito (fase 119.2).** En Sistema → «Voz de Bigcito (para todas las empresas)» se carga una clave de OpenAI o de Gemini (y, si se quiere, se elige la voz). Con eso Bigcito escucha por servidor en cualquier navegador y celular, entiende los audios de WhatsApp y contesta con voz natural en todas las empresas que no cargaron su propia clave en Configuración → Conexiones IA (la de la empresa, si existe, tiene prioridad). El costo de transcribir y de hablar va a esa cuenta: las respuestas habladas se cortan a 700 caracteres y las frases repetidas se guardan para no pagarlas dos veces. Sin clave, Bigcito dicta sólo donde el navegador lo permite y habla con la voz del navegador.

**WhatsApp con un botón (fase 118).** En Sistema → «WhatsApp con un botón (aplicación de Meta)» van el App ID, el App Secret y el Configuration ID del Embedded Signup de la **misma aplicación de Meta que usa el CRM**. Con eso, cada empresa conecta su WhatsApp escaneando el QR desde la app WhatsApp Business (coexistencia), sin copiar claves. En la app de Meta hay que tener `erp.bigsysweb.com` en «Dominios de la app» y en «Dominios permitidos para el SDK de JavaScript». Cada número que se conecta desde el ERP suscribe su propia URL de avisos (`/api/whatsapp/entrante/<empresa>`), por lo que los avisos no pasan por la URL general de la app (que es la del CRM). Los avisos de esos números llegan firmados con el App Secret y el ERP rechaza los que no lo estén. Antes de guardar una conexión, el ERP le pregunta al CRM si ese número ya está conectado allá (línea oficial o por QR) y, si lo está, no lo conecta. Si falta alguno de los tres datos, las empresas ven el aviso «todavía no está habilitado» y sólo pueden conectar a mano.


**Planes**: nombre, precio mensual y anual, usuarios, sucursales, artículos y **facturas por mes** incluidos (-1 = sin límite), módulos. **Cobros**: pagos por Mercado Pago (automáticos) o transferencia (se aprueban a mano). La revisión diaria avisa vencimientos, aplica gracia y suspende; se puede correr a mano desde Sistema.

## Entrar a una empresa

Desde la ficha de la empresa, **Entrar como dueño** para dar soporte. Todo lo que se haga queda auditado y marcado como hecho por el superadmin.

## Cómo entra el superadmin (fase 115)

El superadmin entra por el **mismo login que todos** (`/login`). Si ya existe la empresa de BigSys en el ERP, cae **dentro de esa empresa**, con todo el sistema como cualquier dueño: ahí se factura la suscripción a los clientes, se cargan los gastos de BigSys y se usa el ERP de verdad. Arriba a la derecha tiene el botón **Panel BigSys**, que lo lleva al panel de todas las empresas; desde el panel, el botón **Ir a Bigsys Web ERP** lo trae de vuelta. Se puede ir y venir las veces que haga falta sin volver a entrar. Si todavía no hay empresa de BigSys, cae directo en el panel.

Adentro de su empresa, el superadmin **ve sólo esa empresa** (sus ventas, clientes, alertas), como cualquier dueño; los datos de los clientes de BigSys se ven desde el panel, no mezclados en el tablero propio.

## La empresa de BigSys en el ERP (Fase 87)

Como en el CRM, los superadmins tienen **su propia empresa** dentro del ERP para la gestión de BigSys: facturar a los clientes, cobrar, la plata, la contabilidad. Se define una vez en **Sistema → La empresa de BigSys en el ERP**: se crea nueva ("BigSysWeb", con todo activado y suscripción de cortesía) o se usa una que ya existe. Desde ese momento todos los superadmins, los de ahora y los que se creen después, entran a esa empresa como dueños.

Para saltar: en el panel, el botón con el nombre de la empresa (arriba a la derecha) la abre; adentro de la empresa, el botón **Panel** del encabezado vuelve al panel de control. No es el modo soporte: no hay cartel de "estás dentro de…", no cambia el usuario y nada queda marcado como hecho por soporte. El modo soporte (**Entrar como dueño** desde la ficha de otra empresa) sigue igual y al volver te deja en el panel con tu empresa.

## Sistema

- Días de prueba y gracia, avisos.
- Credenciales de Mercado Pago de BigSys (para cobrar suscripciones), CBU y alias para transferencias.
- **Inteligencia artificial**: API key y modelo; sin clave, todo sigue en modo básico.
- **Correo saliente**: SMTP con prueba de envío.
- Mensaje global, contacto de soporte y **modo mantenimiento**.
- **Entrar con Google** (fase 96): Client ID y Client secret de un «ID de cliente OAuth» de Google Cloud, con la URI de redirección que muestra la tarjeta. Habilita el botón en el login para todas las empresas.
- **App del celular (iPhone y Android)** (fase 93): una sola tarjeta para todas las empresas. Se pega la **cuenta de servicio de Firebase** (el JSON de Firebase → Configuración del proyecto → Cuentas de servicio → Generar clave privada; queda cifrada y nunca se vuelve a mostrar), los **links de Google Play y App Store** (cuando existen, en el celular web aparece la barra «Instalar la app»), la **huella SHA-256** del certificado de Android y el **Team ID** de Apple (para que los links a erp.bigsysweb.com abran la app). **Probar push** manda un aviso a los celulares del superadmin. Sin Firebase la app anda igual, sólo que sin avisos nativos. Los pasos para compilar y publicar están en `docs/MOVIL.md` del repositorio.

## Salud y monitoreo de errores (Panel → Salud)

Una pantalla con semáforo de todo lo que tiene que estar vivo en el servidor: base de datos, caché, cola de trabajos (worker), tareas programadas (cron), disco, copias de seguridad, comprobantes pendientes de CAE, cotización del dólar, correo, IA y errores de las últimas 24 horas. Cada control dice qué pasa y, al lado, qué hacer si está en rojo.

- **Errores**: cada error real del sistema (no los "página no encontrada" ni las validaciones) queda agrupado por lugar, con cuántas veces pasó, en qué empresa y la traza completa. Marcalos como resueltos cuando los arregles.
- **Sentry o compatible**: pegá el DSN de tu proyecto (Sentry, GlitchTip, Bugsink o cualquiera que hable el mismo protocolo) y los errores se mandan allá con la traza y la ruta, sin datos personales. **Enviar evento de prueba** confirma que llega. No hace falta instalar nada más.
- **Aviso por mail**: cargá un correo y, cuando un control pasa a crítico, llega un mail (una vez cada 6 horas por control). La revisión corre sola cada hora.
- **Uptime externo**: `https://tu-servidor/salud` responde 200 si está bien y 503 si hay algo crítico; ponelo en UptimeRobot, Better Stack o el monitor que uses. Con `?token=` (el token que cargues en la pantalla) devuelve el detalle de cada control.

## Enterarse al toque y la semana en un mail (Fase 76)

- **Error nuevo, aviso inmediato**: cuando aparece un error que nunca se había visto (o vuelve uno que marcaste resuelto), llega un mail al instante al mail de monitoreo (Panel → Salud), con la clase, dónde, la ruta, la empresa y un **código** de 8 letras. A lo sumo 10 por hora: si algo explota en cadena, el panel lo muestra entero.
- **La pantalla de error habla claro**: si a un usuario le falla algo, ve "Algo falló de nuestro lado", el mismo código, y un botón **Avisar a soporte** que abre un ticket con el error ya pegado (no tiene que explicar nada técnico). El ticket llega como "Algo no anda", prioridad alta.
- **Bigcito**: "¿cómo está el sistema?", "¿hubo errores hoy?" (`salud_del_sistema`, sólo superadmin): controles con problemas, errores de 24 h con sus códigos, empresas activas y en riesgo, copias atrasadas.
- **Resumen semanal**: los lunes a las 7:50 llega al mail de monitoreo cómo le fue al sistema la semana pasada: empresas que lo usaron y las que más, las en riesgo (sin uso 7 días o más), módulos más usados, errores con sus códigos, salud, copias y tickets. También a mano: `php artisan sistema:resumen-semanal`.

## Velocidad de las páginas (Fase 77)

Cada noche el sistema mide solo las pantallas clave (Inicio, Comprobantes, Clientes, Stock, Estadísticas, Fondos, Alertas) de las empresas más grandes, entrando como su dueño, y guarda cuánto tardó cada una y cuántas consultas hizo. En **Panel → Salud** está la tabla de la última noche con el peor tiempo, el promedio, la noche anterior y la consulta más lenta. Una página por encima del umbral pone la salud en aviso; dos noches seguidas, en crítico y avisa por mail. Así una pantalla que se va poniendo lenta con los datos se ve antes de que un cliente se queje.

## Copias que sirven (Fase 78)

En **Panel → Salud**, la tabla "Copias de seguridad por empresa" dice de cada una: cuándo fue la última copia, si **se abre y cuadra** (se verifica al generarla y de nuevo a las 3:40), si el **ensayo de restauración** del domingo anduvo (se restaura en una transacción que se deshace, sin tocar datos), y si está **fuera del servidor**. Dos controles de salud nuevos: "Copias verificadas" (crítico si una copia no abre, no cuadra o no se pudo restaurar) y "Copias fuera del servidor" (aviso mientras no haya disco externo; crítico si hay y una copia no subió). Para el disco externo: las variables `OFFSITE_S3_*` en el `.env` (las mismas que usa el CRM; si está en el mismo servidor se copian tal cual), y las copias se suben a `bigsysweb/erp/<empresa>/` en el mismo bucket. A mano: `php artisan backups:probar` y `php artisan backups:probar --ensayo`.

## Uso y soporte

**Uso**: qué empresas usan el sistema, cuáles se están enfriando (7 y 14 días sin uso), ranking, módulos más usados y detalle por empresa y usuario. **Soporte**: los tickets de todas las empresas por prioridad; al responder, la empresa recibe una alerta.

## Antes de salir a producción

Ver `docs/produccion.md` (servidor, PostgreSQL, colas, cron, HTTPS, backups) y `docs/arca.md` (homologación de WSFE y WSFEX con certificado real).
