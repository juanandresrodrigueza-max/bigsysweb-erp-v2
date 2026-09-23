---
titulo: Configuración, usuarios y seguridad
modulo: configuracion
rutas: /configuracion, /suscripcion
orden: 8
resumen: Empresa, sucursales, usuarios y roles, impuestos, tienda y canales, importación, copias de seguridad, dos factores y API.
---

## Empresa y sucursales

Datos fiscales, logo, verticales habilitados (comercio, gastronomía, servicio técnico, hotelería), avisos al dueño por WhatsApp, balanza e impresora del POS, tarjetas y cuotas, Mercado Pago. Cada **sucursal** tiene su caja, su depósito y su punto de venta; los usuarios se asignan a una o varias.

- **Sucursal con CUIT propio**: si una sucursal es otra razón social (o factura aparte), en la ficha de la sucursal tildá **Factura con su propio CUIT**, cargá CUIT, razón social y condición de IVA, subí su certificado ARCA con **Certificado ARCA** y creale sus puntos de venta (Configuración → Puntos de venta, asignados a esa sucursal). Desde ese momento todo lo que se emite parado en esa sucursal sale con su CUIT, su numeración y su certificado; las facturas, tickets y recibos impresos muestran sus datos. En Contable → Fiscal, el Libro IVA Digital se baja por CUIT eligiendo la sucursal.
- **Casa central consolidada**: el dueño (o quien tenga permiso de estadísticas) puede elegir **Ver consolidado de todas** en el selector de sucursal: el tablero suma todas las sucursales. Estadísticas permite además filtrar por sucursal.

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

Los usuarios se dan de alta en el ERP; el CRM los espeja con el rol equivalente (dueño y administrador → admin; encargado y contador → supervisor; vendedor y cajero → operador; depósito, producción y solo lectura → consulta). El token que viaja dura 60 segundos y sirve una sola vez.

## Importar datos

Artículos, clientes, proveedores y saldos desde Excel o CSV, con vista previa y detección de duplicados.

## Copias de seguridad

Copia automática diaria (se puede bajar) y restauración con un clic. Antes de restaurar se guarda una copia de seguridad del estado actual.

## Seguridad y API

- **Verificación en dos pasos** con app del teléfono, por usuario.
- **Sesiones abiertas**: dónde está abierta tu cuenta; podés cerrarlas.
- **Tokens de API** para conectar otros sistemas (la documentación está en `/api/docs`) y **webhooks** que avisan a una URL tuya cuando pasa algo. Para sacar las tablas completas hacia un BI, el contador u otro sistema, seguí la guía **Mandar tus datos a otras plataformas (API)**.
- **Auditoría**: todo lo que hace cada usuario, con antes y después.

## Suscripción

Plan actual, usuarios y sucursales incluidos, renovación por Mercado Pago o transferencia.
