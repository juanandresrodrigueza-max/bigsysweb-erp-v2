---
titulo: Preguntas frecuentes
modulo:
rutas:
orden: 12
resumen: Las dudas que más llegan a soporte, con la respuesta corta.
---

## ¿Puedo probar sin certificado de ARCA?

Sí. Las facturas salen **simuladas** (dicen "sin CAE, no válido como factura"). Cuando cargues el certificado, todo lo demás queda igual.

## ¿Cómo anulo una factura emitida?

Con una **nota de crédito** (Convertir → Nota de crédito desde la factura). ARCA no permite borrar facturas con CAE. Un borrador sí se borra.

## ¿Qué pasa si se corta internet?

El punto de venta sigue vendiendo y sincroniza después. Las facturas pendientes de CAE se reintentan solas cada cinco minutos.

## ¿Puedo tener varias sucursales?

Sí, según el plan. Cada una con su caja, depósito y punto de venta; el dueño ve todo junto o por sucursal.

## ¿El contador puede entrar?

Sí, con rol **Contador**: ve contabilidad, libros, fondos y comprobantes sin poder facturar ni tocar stock. Si atiende varios clientes con BigSysWeb, con un solo usuario cambia de empresa.

## ¿Cómo cambio un precio en muchos artículos?

**Stock → Precios**: por rubro, proveedor o lista, un porcentaje o desde el costo nuevo. Con vista previa antes de aplicar.

## ¿Dónde veo quién hizo qué?

**Configuración → Auditoría**: cada alta, cambio, anulación e inicio de sesión, con usuario, fecha y antes/después.

## ¿Puedo usarlo en el celular?

Sí. Es una app web: en el navegador del teléfono, "Agregar a la pantalla de inicio". La pantalla **Mi negocio** está pensada para el celular del dueño.

## ¿Cómo conecto mi tienda o mi sistema?

**Configuración → Tienda y canales** para Mercado Libre, Tiendanube, WooCommerce, Shopify, PedidosYa y Rappi. Para sistemas propios, tokens de API y webhooks en **Seguridad y API**; la documentación está en `/api/docs`.

## ¿Me avisan si algo pasa?

Alertas en el sistema (campana) y, si lo activás, un resumen diario por WhatsApp al dueño con avisos críticos al momento.

## ¿Se hacen copias de seguridad?

Todas las noches, automáticas. Se descargan y se restauran desde **Configuración → Copias de seguridad**.

## ¿Puedo pasar mis datos del BigSys viejo?

Sí. En **Configuración → Importar** elegí "Viene de: BigSys (Clarion)". Se reconocen solas las columnas de clientes, proveedores, artículos con sus 6 listas y los saldos. Está explicado paso a paso en *Configuración, usuarios y seguridad*.

## ¿Puedo poner mi logo y mis colores en las facturas?

Sí, en **Configuración → Empresa → Diseño de comprobantes**. También los recibos, remitos, presupuestos, tickets y el catálogo salen con tu identidad. Lo que exige ARCA sale siempre.

## ¿Cómo le mando la lista de precios a un cliente?

Con un catálogo: **Stock → Catálogos**. Se manda por WhatsApp o por mail, y cada cliente ve sus propios precios.

## Hice una factura a mano en papel, ¿cómo la cargo?

En la factura nueva tildá **Manual de talonario** y cargá el punto de venta, el número y la fecha del papel. No pide CAE y queda en el libro de IVA.


## ¿Puedo liquidar los sueldos de Comercio acá?

Sí. En **Sueldos → Conceptos → Cargar Comercio** quedan listos todos los conceptos del CCT 130/75, como los liquida un estudio: días, feriados, vacaciones, antigüedad, presentismo, sumas del acuerdo, aportes, sindicato, FAECYS y contribuciones. En cada empleado cargás el básico, la jornada y, si corresponde, el seguro CEC. El recibo legal sale con el último depósito de aportes y tu logo. Las sumas del acuerdo cambian con cada paritaria: actualizalas en Conceptos.

## ¿Cómo sé qué se me vence?

En **Stock → Lotes y vencimientos** ves lo vencido y lo que vence en los próximos días, con lo que vale. Además llega una alerta todos los días. Lo vencido no se vende.

## Un laboratorio retiró un lote, ¿qué hago?

Buscá el lote en **Stock → Lotes y vencimientos**, tocá **Retiro del mercado** y cargá el motivo. Queda bloqueado en todos los depósitos y te muestra a qué clientes se vendió, con cuánto y cómo contactarlos.

## ¿Puedo seguir cada equipo por su número de serie?

Sí. En **Stock → Números de serie** buscás la serie y ves de qué proveedor vino, a quién se vendió, hasta cuándo tiene garantía y sus services. Desde ahí lo ingresás a servicio técnico.

## Tengo carnicería, ¿sirve?

Sí: artículos pesables con el PLU de la balanza (la caja y la factura leen la etiqueta), y el **despiece** de media res con el rinde de cada corte y su costo real por kilo, en **Stock → Carnicería y pesables**.

## ¿Puedo ordenar el depósito por estanterías?

Sí, en **Stock → Almacén**: ubicaciones por pasillo, estante y nivel con código de barras. Con la pistola guardás, movés, buscás y contás, y la hoja de preparación te dice de qué estante sacar cada cosa.

## ¿Cómo pongo el precio por kilo en la góndola?

Cargá el **contenido neto** del envase en la ficha del artículo (500 g, 1,5 l) y en **Stock → Etiquetas** elegí el diseño **Góndola**. Con **Precios cambiados (7 días)** reimprimís solo lo que cambió.

## ¿Puedo tenerlo como app en el celular?

Sí. Abrí el sistema en el navegador del celular y elegí **Agregar a inicio** (iPhone, desde Safari → Compartir) o **Instalar app** (Android, desde Chrome → ⋮). Queda con el ícono de BigSys y se abre como una app.

Además hay una **app nativa para iPhone y Android** en camino: cuando esté en las tiendas, en el celular aparece la barra **«Instalar la app»** con los links. Con la app, los avisos llegan al toque aunque el sistema esté cerrado y Bigcito escucha mejor. Más en el artículo **App del celular**.

## No me llegan los avisos al celular, ¿qué hago?

1. En **Avisos al celular** (✨ arriba a la derecha) fijate que este dispositivo figure como activo; si no, tocá **Activar en este dispositivo** y aceptá el permiso.
2. En iPhone, desde el navegador los avisos sólo funcionan si abriste BigSysWeb desde el ícono de la pantalla de inicio (no desde Safari suelto) y pueden demorar: es una limitación de Apple. Con la **app nativa** llegan al toque.
3. Tocá **Probar** en esa misma pantalla: si el aviso de prueba llega, el resto también.
4. Si seguís sin recibir nada, escribinos desde **Soporte**: decinos el modelo del teléfono.

## ¿Cómo borro mi cuenta y mis datos?

Si sos el dueño, desde **Configuración → Suscripción → Dar de baja la empresa**, o escribí a privacidad@bigsys.com.ar desde el mail con el que entrás. Se borra la cuenta y, si sos el dueño, la empresa con todos sus datos; lo que la ley obliga a guardar (comprobantes fiscales) se conserva el plazo legal y después se elimina. Los detalles están en **/privacidad** y **/terminos**, abajo del login.

## ¿Cómo hago para que los clientes pidan desde la mesa?

Big Restó → Reservas y QR: imprimí el QR de cada mesa (desde la fase 95 lleva un código secreto por mesa, así que si tenías QR impresos antes, reimprimilos). El cliente lo escanea, elige y pide; no descarga nada. En Big Restó → Carta elegís si el pedido va directo a cocina o si el mozo lo confirma antes. Todo está en **Big Restó → Pedido desde la mesa, seguro y en vivo**.

## Un cliente dice que la carta le pide un código, ¿qué es?

Es el **código de la mesa**: cuando el primer celular pide, la mesa queda abierta con un código de 4 números. Si otro celular escanea el mismo QR, lo tiene que poner para pedir a la misma cuenta. Lo tiene quien pidió primero (en su pantalla de «pedido enviado» y en «Lo que pidieron») y lo ve el mozo en Mozo de bolsillo, en el salón y en la comanda. A Bigcito: «¿cuál es el código de la mesa 5?».

## ¿Cómo saco un plato que se acabó?

En Big Restó → Carta, el botón **Se acabó** del plato; en Cocina, cada plato en preparación tiene el suyo; o por voz, «se acabó el pulpo». Desaparece de la carta QR y del salón al instante, el pedido que lo traía se rechaza con aviso, y vuelve solo al otro día (o antes tocando **Agotado hoy**).

## ¿Cómo sé qué mesa me está esperando?

En el salón, el color de cada mesa lo dice: rojo llama al mozo o pide la cuenta, violeta cuenta pedida, ámbar pedido del QR sin confirmar, verde plato listo sin llevar, lila en cocina, gris comiendo. Arriba del plano está la referencia. A Bigcito: «¿qué mesa me llama?», «¿qué espera cada mesa?». Y al celular del mozo le llegan los avisos de pedido, llamado y cuenta.

## ¿Puedo entrar con mi cuenta de Google?

Sí, si el botón «Entrar con Google» aparece en el login. Tu usuario tiene que existir con ese mismo mail (lo crea tu empresa en Configuración → Usuarios); Google no crea cuentas nuevas. Si tenés la verificación en dos pasos, te la pide igual.

## ¿Puedo mandarles un aviso a todos mis clientes?

Sí: Clientes → **Mensajes a clientes**. Elegís a quién (todos, los que deben, un tipo), escribís el texto con {cliente} o {saldo} si querés, y sale por mail o WhatsApp. O a Bigcito: «mandale a todos los clientes que el lunes cambia la lista».

## ¿Cómo sé cuánto gasta cada área contra lo presupuestado?

Contable → **Centros de costo**: creás los centros (Administración, Depósito, un local), cargás el presupuesto de cada mes y, al cargar gastos y compras, elegís el centro. La pantalla muestra presupuesto, real y desvío mes a mes.

## ¿Puedo hacer que las compras grandes las apruebe alguien?

Sí: Órdenes de compra → engranaje. Ponés el monto y quiénes autorizan. Desde ese importe la orden espera autorización, el autorizador la recibe en el celular y la aprueba o la rechaza desde la orden o por Bigcito («autorizá la orden 12»).

## Importo mercadería, ¿cómo saco el costo real?

Comprar → **Importaciones**: cargás el despacho con los artículos en FOB, la cotización y los gastos (flete, seguro, derechos, despachante). El sistema prorratea por valor y te da el costo nacionalizado por unidad; al nacionalizar entra al stock con ese costo.

## ¿Cuántos días de vacaciones le corresponden a un empleado?

Sueldos → **Ausencias y vacaciones** lo calcula por antigüedad como dice la LCT (14, 21, 28 o 35 días; con menos de 6 meses, 1 cada 20 trabajados) y muestra tomados y saldo. Cargás las vacaciones ahí y al liquidar el mes ya vienen puestas. O preguntale a Bigcito: "¿cuántos días le quedan a Juan?".

## ¿El sistema arma el Libro de Sueldos Digital?

Sí. Con la liquidación confirmada, en Sueldos está el botón **Libro de Sueldos Digital**: baja el archivo de importación de ARCA (registros 01 a 04). Los códigos de concepto se declaran una vez en el LSD y se mapean. Revisá la primera presentación con el contador.

## ¿Cuánto IVA tengo que pagar este mes?

Contable → **Posición IVA**: débito menos crédito, menos el saldo a favor que venía, menos percepciones y retenciones sufridas; te dice cuánto y cuándo vence, o qué saldo pasa al mes siguiente. Cerrá cada mes y mandale el texto al contador. O preguntale a Bigcito: "¿cuánto IVA pago?".

## ¿Tengo que subir el extracto de Mercado Pago?

No. En Contable → Conciliación, elegí la cuenta de billetera y marcá «Esta es la cuenta de Mercado Pago»: los cobros, las comisiones y las devoluciones entran solos por API cada madrugada (o con el botón **Traer de Mercado Pago**) y se concilian con los cobros del mostrador.

## ¿Cómo sé que la migración desde BigSys quedó bien?

Configuración → Importar → **3. Control de cierre**: compara los totales de BigSys con los del ERP (cantidades, saldos de clientes y proveedores, inventario, cheques, saldo de cada caja y banco) y marca en rojo lo que no cierra. Se puede repetir las veces que haga falta; cuando todo da ✓, se apaga el viejo.

## ¿Por qué se ve distinto que antes?

Renovamos el diseño con los colores de la web de BigSys (ciruela y magenta) y después repasamos todas las pantallas para que nada quede apretado ni cortado, sobre todo en el celular. No se movió nada de lugar: cada pantalla tiene título y subtítulo, un botón principal magenta y lo demás en **Más ▾**. Está contado en **Qué cambió en el menú y en las pantallas**.
