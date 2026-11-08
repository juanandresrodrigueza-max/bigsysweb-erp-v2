---
titulo: Configuración, usuarios y seguridad
modulo: configuracion
rutas: /configuracion, /suscripcion
orden: 8
resumen: Empresa y diseño de comprobantes, sucursales, usuarios y roles, impuestos, tienda y canales, importación (también desde BigSys), copias de seguridad, dos factores y API.
---

## Empresa y sucursales

Datos fiscales, logo, verticales habilitados (comercio, gastronomía, servicio técnico, hotelería), avisos al dueño por WhatsApp, balanza e impresora del POS, tarjetas y cuotas, Mercado Pago. Cada **sucursal** tiene su caja, su depósito y su punto de venta; los usuarios se asignan a una o varias.

- **Sucursal con CUIT propio**: si una sucursal es otra razón social (o factura aparte), en la ficha de la sucursal tildá **Factura con su propio CUIT**, cargá CUIT, razón social y condición de IVA, subí su certificado ARCA con **Certificado ARCA** y creale sus puntos de venta (Configuración → Puntos de venta, asignados a esa sucursal). Desde ese momento todo lo que se emite parado en esa sucursal sale con su CUIT, su numeración y su certificado; las facturas, tickets y recibos impresos muestran sus datos. En Contable → Fiscal, el Libro IVA Digital se baja por CUIT eligiendo la sucursal.
- **Casa central consolidada**: el dueño (o quien tenga permiso de estadísticas) puede elegir **Ver consolidado de todas** en el selector de sucursal: el tablero suma todas las sucursales. Estadísticas permite además filtrar por sucursal.

## Diseño de comprobantes (Configuración → Empresa)

Facturas, notas, remitos, presupuestos, recibos, órdenes de pago, tickets y el catálogo salen con la identidad de tu empresa.

- **Logo**: PNG, JPG o WEBP de hasta 1 MB, mejor con fondo transparente. En el ticket sale en gris.
- **Color principal y secundario**: el principal va en el nombre, la letra y el total; el secundario en los títulos de la tabla. El texto encima se pone blanco o negro solo, para que siempre se lea.
- **Estilo del encabezado**: clásico con recuadro de la letra, banda de color arriba o mínimo con una línea.
- **Datos extra**: lo que quieras debajo de la empresa, una cosa por línea (web, redes, CBU o alias).
- **Leyenda al pie**: política de cambios, agradecimiento, horarios.
- **Qué mostrar**: logo, código del artículo, bonificación, vendedor, saldo de cuenta corriente y firma "recibí conforme" en los remitos. También la validez del presupuesto.

Lo que exige ARCA sale siempre y no se puede sacar: razón social, domicilio comercial, CUIT, Ingresos Brutos, inicio de actividades, condición IVA, letra y código, número, CAE con su vencimiento y QR. En las facturas B a consumidor final se agrega el IVA contenido (Ley 27.743). Cargá el domicilio, los Ingresos Brutos y el inicio de actividades en "Datos fiscales de la factura impresa". El botón "Ver una factura con este diseño" abre la última factura emitida, o una de ejemplo si todavía no hay.

## Usuarios y roles

Un usuario por persona. Los **roles del sistema** (dueño, administrador, contador, vendedor, cajero, compras, depósito, técnico, recepción) ya vienen armados; se pueden copiar y ajustar permiso por permiso (ver, crear, editar, anular, exportar) y por módulo. En la lista de usuarios se ve el último acceso y la actividad de los últimos 30 días.

## Impuestos

Percepciones y retenciones de IIBB, IVA y Ganancias, padrones por jurisdicción, CBU para Factura de Crédito MiPyME, mes de cierre e índice IPC.

## Tienda y canales

Tienda online propia, portal de clientes, WhatsApp, Mercado Libre, Tiendanube, WooCommerce, Shopify, PedidosYa y Rappi. Los pedidos entran en **Comprobantes → Pedidos** y se confirman con un clic.

## CRM (integración con el CRM de BigSys)

Si tu empresa usa el ERP y el CRM de BigSys, se pasan de uno al otro con un botón, ya logueados, y se hablan por API sin que ninguno escriba en la base del otro.

1. Cargá el **CUIT** de la empresa acá y el mismo en el CRM: es lo que une las dos empresas.
2. En **Configuración → CRM** poné la URL del CRM, tocá **Generar** para crear el secreto compartido y pegalo en el CRM (Integraciones → ERP BigSys). Pegá acá la clave de API que crea el CRM.
3. Activá y **Probar conexión**. Aparece **CRM** en el menú: al tocarlo entrás al CRM sin volver a loguearte. En el CRM aparece **ERP BigSys** para volver.

**Clientes y artículos**: tocá **Sincronizar clientes y artículos** una vez (carga inicial). Después, cada cliente o artículo que se crea o cambia en el ERP viaja solo al CRM, y cada contacto que nace en el CRM (por ejemplo un WhatsApp nuevo) aparece en el ERP como cliente consumidor final, enlazado por CUIT o email si ya existía. Lo fiscal (CUIT, condición IVA, domicilio, lista de precios, límite de crédito) lo administra el ERP y el CRM no lo pisa; los artículos y precios quedan en solo lectura en el CRM, con el precio de la lista que elijas.

**Ventas**: los presupuestos se arman en el CRM. Cuando el vendedor lo marca aceptado (o toca **Mandar al ERP**), nace en el ERP como presupuesto numerado, con los artículos enlazados por código, y avisa en la campana; si preferís, en Configuración → CRM podés elegir que nazca como factura en borrador. **Factura solo el ERP**: en el CRM la facturación queda apagada para esa empresa, y cada factura, nota o recibo que emitís acá aparece en la ficha del cliente del CRM con su saldo y un link para abrirlo. Una venta ganada en el CRM sin presupuesto también avisa en la campana.

**Agenda y recordatorios**: las tareas y turnos del CRM aparecen en la Agenda del ERP (marcados "Tarea CRM" / "Turno CRM") y al tocarlos se abre el CRM ya logueado. En Cobranzas podés elegir el canal **Por el CRM**: el recordatorio queda como tarea de cobranza para el vendedor, que lo manda desde la conversación del cliente.

**Usuarios**: cada alta, cambio de rol o baja que hacés en Configuración → Usuarios viaja al CRM, que crea o actualiza el usuario espejo (y cierra su sesión si lo diste de baja).

Los usuarios se dan de alta en el ERP; el CRM los espeja con el rol equivalente (dueño y administrador → admin; encargado y contador → supervisor; vendedor y cajero → operador; depósito, producción y solo lectura → consulta). El token que viaja dura 60 segundos y sirve una sola vez.

### Salud de la conexión (Fase 68)

Arriba de la configuración hay un semáforo: **verde** (el CRM responde y todo viajó), **amarillo** (hubo errores en las últimas 24 horas que se reintentan solos, o la cola está larga) o **rojo** (el CRM no responde, o hay envíos que agotaron los reintentos). Al lado, qué fue lo último que viajó en cada sentido, cuánto hay en la cola y la lista de los últimos eventos con su resultado. Si el CRM se cae te llega una **alerta** y lo que se genere mientras tanto queda en la cola; cuando vuelve, se manda solo y la alerta se resuelve. **Reintentar** vuelve a la cola lo que falló del todo; **Reconciliar ahora** vuelve a mandar lo de las últimas 48 horas (del lado del CRM no se duplica nada), y eso mismo pasa solo todos los días a las 6:30. A Bigcito: "¿anda la conexión con el CRM?".

Además, el bloque de salud muestra **"Lo que ve el CRM"**: su propia cola de avisos hacia el ERP, sus caídos y sus errores. Si de este lado está todo verde pero del lado del CRM hay envíos caídos, el semáforo acá se pone amarillo con el texto del CRM, para que nadie se entere tarde.

### El CRM en la ficha del cliente (Fase 71)

Con el CRM conectado, cada cliente tiene la solapa **CRM**: las conversaciones que el equipo de ventas tuvo con él por WhatsApp, mail o redes (la más reciente con sus últimos mensajes) y los botones **Escribirle por el CRM** y **Ver en el CRM**, que abren el CRM ya logueado. Es sólo lectura: se escribe desde el CRM. Además, los **reclamos por WhatsApp** y los "quiero hablar con alguien" que atiende Bigcito (Fase 60) quedan también como **tarea del CRM** para el vendedor, con prioridad alta, y la alerta del ERP dice el número de tarea. A Bigcito: "¿qué habló el vendedor con López?".

### Bigcito compartido (Fase 72)

Con el CRM conectado, **el Bigcito del ERP contesta con datos del CRM** y **el del CRM con datos del ERP**, sin que el usuario cambie de sistema:

- En el ERP: "¿quién atiende a López y en qué anda?" (`cliente_en_crm`: vendedor, etapa, oportunidades abiertas, tareas, próxima cita), "¿qué habló el vendedor con López?" (`conversaciones_crm`), "¿qué tenemos por cerrar?" / "¿qué tiene Juan en el embudo?" (`oportunidades_crm`) y "¿qué tiene pendiente ventas?" (`pendientes_crm`).
- En el CRM: "¿cuánto debe López?", "¿hay stock del hierro del 8?", "¿qué le facturamos el mes pasado?" los responde el Bigcito del CRM consultando al ERP por su conexión MCP de **solo consultas** (Configuración → Conexiones IA).
- **Cada uno con sus permisos**: cuando el CRM pregunta, dice qué persona está preguntando y el ERP responde con lo que esa persona puede ver en el ERP (un vendedor sin acceso a costos no los ve tampoco desde el CRM) y lo audita a su nombre. Si esa persona no es usuaria del ERP, responde con los permisos del usuario dueño de la conexión.

### Avisos cruzados (Fase 73)

Con el CRM conectado, **lo crítico del ERP le llega al vendedor que atiende al cliente** como tarea del CRM, sin que nadie se lo tenga que contar: un **cheque rechazado** (al instante), **deuda vencida hace más de 30 días**, **pasó su límite de crédito** y **dejó de comprar** (una pasada por día, a las 7:40; un aviso por cliente y motivo cada 30 días). Sólo para clientes que están en el CRM. Y al revés: las **tareas vencidas del CRM** aparecen en la **campana** del ERP (módulo Clientes; las de prioridad alta como críticas, con el nombre del cliente y del vendedor) y se resuelven solas cuando el vendedor las cierra en el CRM. A Bigcito: "¿qué tareas del CRM están vencidas?" (`pendientes_crm`), y `alertas_activas` las incluye.

### Tablero y comisiones cruzados (Fase 74)

- **El embudo del CRM en Estadísticas → Vendedores**: oportunidades abiertas por etapa, ganadas y perdidas en el período con la tasa de cierre, tareas vencidas, y una tabla por vendedor que cruza lo que tiene en el CRM (abiertas, ganadas) con lo que vendió de verdad en el ERP. Sólo con el CRM conectado.
- **Comisiones con lo real**: la base de comisiones de cada vendedor sale del ERP (facturado menos notas de crédito, y cobrado, con sus porcentajes de Clientes → Vendedores). El CRM la lee por la API (`GET /api/comisiones/base`) y liquida sobre eso, no sobre el valor que el vendedor cargó en la oportunidad. Los avisos de factura emitida y cobro registrado al CRM ya dicen de qué vendedor son. A Bigcito: "¿cuánto le toca a Juan este mes?" (`comisiones_vendedores`).

### Identidad completa y un solo plan (Fase 75)

- **Un solo plan**: el plan se contrata y se paga en el ERP (Suscripción) y habilita los dos sistemas. En Configuración → CRM se ve si tu plan incluye el CRM, cuántos usuarios permite y cuándo vence; cada cambio de suscripción (alta, renovación, gracia, suspensión, baja) le llega al CRM al instante, y el CRM se frena solo si la suscripción se corta. No hay dos facturas ni dos vencimientos.
- **Identidad en los dos sentidos**: ya el alta, el rol y la baja de un usuario hechos en el ERP llegaban al CRM. Ahora también al revés: si en el CRM dan de alta a alguien, le cambian el rol o lo dan de baja, el ERP lo espeja (admin → Administrador, supervisor → Administración de ventas, operator → Vendedor, viewer → Solo mirar). El dueño del ERP es intocable desde el CRM, y el CRM no puede pasar el máximo de usuarios del plan. El que nace desde el CRM entra al ERP por el botón del CRM (no conoce su clave hasta recuperarla).

## Importar datos

Artículos, clientes, proveedores y saldos desde Excel o CSV, con vista previa y detección de duplicados.

## Migrar desde BIG Connect (lo más rápido)

Si el cliente ya usa BIG Connect, sus datos se traen directo, sin exportar planillas.

1. En BIG Connect → **Importar y exportar → API**, generá una clave con acceso completo (empieza con `bk_`) y copiá la dirección del Hub.
2. En el ERP → **Configuración → Importar**, tarjeta **Traer todo desde BIG Connect**: pegá la dirección, la clave y, si tiene varias, la empresa (`e01`, `e02`…). **Conectar** prueba que la clave sirva.
3. **Probar sin guardar**: trae todo y muestra cuántos proveedores, clientes, artículos y saldos entrarían, nuevos o actualizados, y los que tienen error. No guarda nada.
4. **Migrar de verdad**: lo mismo, guardando. Se puede repetir el día del corte: lo que ya está se actualiza, no se duplica.

Qué trae: proveedores y clientes con su código de BigSys, CUIT, condición de IVA, lista, descuento, vendedor y límite de crédito; artículos con las 6 listas (netas), costo, IVA, stock y mínimo; y lo impago de cada cuenta comprobante por comprobante, con su vencimiento. La cuenta de contado no se trae porque en el ERP ya existe. También se le puede pedir a Bigcito: «probá la migración desde BIG Connect».

## Migrar desde BigSys (Clarion)

En Configuración → Importar elegí "Viene de: BigSys (Clarion)". Los nombres de las columnas del BigSys viejo se reconocen solos. Conviene importar en este orden: clientes, proveedores, artículos y por último los saldos.

- **Clientes y proveedores**: se sube la misma tabla de cuentas (cta). Se toman solo las de tipo C para clientes y P para proveedores; empleados y agenda quedan afuera. Se guarda el código de cada cuenta (codcta), que después sirve para buscarlos por ese número y para cruzar los saldos. También pasan el CUIT, la condición de IVA (RI, MT, CF, EX), la lista de precios, el descuento y el límite de crédito.
- **Artículos**: pasan el código, el código de barras, el rubro, las 6 listas, el costo, el stock, el mínimo y el estado (A activo, B o I de baja). BigSys guarda los precios con IVA: con el tilde "Los precios del archivo son finales con IVA" se pasan a neto con el IVA de cada artículo.
- **Saldos**: se sube la tabla de movimientos (mov). El saldo de cada cuenta se calcula sumando el debe y restando el haber, sin los anulados. No se usa el saldo que guarda cada comprobante (totsal) porque no descuenta los pagos a cuenta.

Las fechas en formato Clarion y los acentos de los archivos de Windows se convierten solos. Hacé la prueba con una copia de la carpeta de datos, nunca con la que está en uso.

## Copias de seguridad

Copia automática diaria (se puede bajar) y restauración con un clic. Antes de restaurar se guarda una copia de seguridad del estado actual.

## Seguridad y API

- **Verificación en dos pasos** con app del teléfono, por usuario.
- **Sesiones abiertas**: dónde está abierta tu cuenta; podés cerrarlas.
- **Tokens de API** para conectar otros sistemas (la documentación está en `/api/docs`) y **webhooks** que avisan a una URL tuya cuando pasa algo. Para sacar las tablas completas hacia un BI, el contador u otro sistema, seguí la guía **Mandar tus datos a otras plataformas (API)**.
- **Auditoría**: todo lo que hace cada usuario, con antes y después.

## Suscripción

Plan actual, usuarios y sucursales incluidos, renovación por Mercado Pago o transferencia. También muestra las **facturas emitidas en el mes** y los **artículos cargados** contra lo que incluye el plan (en rojo desde el 80 %). Al llegar al máximo, la factura siguiente del mes o el artículo nuevo no se pueden cargar hasta pasar a un plan mayor; presupuestos, remitos y notas de crédito no cuentan.

## Perfiles listos (Configuración → Usuarios)

Al crear o editar un usuario, elegí un **perfil** y quedan tildados todos sus permisos de una: **Vendedor**, **Administrador de ventas**, **Depósito**, **Administrador**, **Sólo mirar** (ve todo y no cambia nada), Contador, Cajero, Compras o Técnico. Si necesitás algo distinto, armá un rol a mano en Roles. Aparte, dos tildes: ver costo y margen, y ver a qué proveedores comprar.

El **Inicio** de cada persona muestra sólo lo que tiene habilitado: alguien de depósito ve el stock, no las ventas ni la plata. Cuando alguien intenta algo que no puede, el mensaje dice **qué permiso le falta** y a quién pedírselo.

## Otras empresas por persona

Si manejás varias empresas, en la ficha del usuario aparece **Otras empresas a las que entra**: tildá las que quieras y elegí el perfil en cada una. Lo demás no lo ve: el servidor lo controla al cambiar de empresa, no sólo la pantalla.
