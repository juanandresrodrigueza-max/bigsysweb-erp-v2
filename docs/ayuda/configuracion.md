---
titulo: Configuración, usuarios y seguridad
modulo: configuracion
rutas: /configuracion, /suscripcion
orden: 8
resumen: Empresa, sucursales, usuarios y roles, impuestos, tienda y canales, importación, copias de seguridad, dos factores y API.
---

## Empresa y sucursales

Datos fiscales, logo, verticales habilitados (comercio, gastronomía, servicio técnico, hotelería), avisos al dueño por WhatsApp, balanza e impresora del POS, tarjetas y cuotas, Mercado Pago. Cada **sucursal** tiene su caja, su depósito y su punto de venta; los usuarios se asignan a una o varias.

## Usuarios y roles

Un usuario por persona. Los **roles del sistema** (dueño, administrador, contador, vendedor, cajero, compras, depósito, técnico, recepción) ya vienen armados; se pueden copiar y ajustar permiso por permiso (ver, crear, editar, anular, exportar) y por módulo. En la lista de usuarios se ve el último acceso y la actividad de los últimos 30 días.

## Impuestos

Percepciones y retenciones de IIBB, IVA y Ganancias, padrones por jurisdicción, CBU para Factura de Crédito MiPyME, mes de cierre e índice IPC.

## Tienda y canales

Tienda online propia, portal de clientes, WhatsApp, Mercado Libre, Tiendanube, WooCommerce, Shopify, PedidosYa y Rappi. Los pedidos entran en **Comprobantes → Pedidos** y se confirman con un clic.

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
