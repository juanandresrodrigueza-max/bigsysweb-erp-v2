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
