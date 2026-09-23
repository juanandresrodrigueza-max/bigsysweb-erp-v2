---
titulo: Guía de implementación: de cero a operar en una semana
modulo: dashboard
rutas: /primeros-pasos
orden: 0
resumen: El plan día por día para poner BigSysWeb en marcha sin ayuda externa: qué cargar, en qué orden, qué probar y cuándo cortar con el sistema anterior.
---

Esta guía está pensada para que el dueño o el administrativo la sigan solos. Cada día tiene un objetivo, una lista de tareas y una prueba para saber que quedó bien. Los pasos marcados con ★ son los mínimos para facturar; el resto se puede hacer después.

## Antes de empezar (30 minutos)

Tené a mano:
- CUIT, razón social, condición frente al IVA y el número de punto de venta electrónico dado de alta en ARCA.
- El certificado digital de ARCA (archivo `.crt` y clave `.key`), o la clave fiscal para generarlo (ver la guía *ARCA y factura electrónica*).
- La lista de artículos y la lista de clientes y proveedores del sistema anterior, exportadas a Excel o CSV. Si no tenés exportación, alcanza con las planillas que usás hoy.
- Los saldos de cuenta corriente de clientes y proveedores al día del corte.
- El logo en PNG o JPG.

## Día 1 · La empresa queda armada

1. ★ **Configuración → Empresa**: nombre comercial, razón social, CUIT, condición IVA, email, teléfono y logo. Todo eso sale impreso en las facturas.
2. ★ **Configuración → Puntos de venta y AFIP**: creá el punto de venta con el mismo número que en ARCA y marcá "electrónico". Subí el certificado y la clave; probá con "Probar conexión". Si todavía no tenés certificado, seguí igual: las facturas salen simuladas hasta que lo cargues.
3. ★ **Fondos**: verificá que exista una caja en efectivo y creá la cuenta bancaria (banco, CBU, saldo inicial al día del corte). Si usás Mercado Pago, creá también la billetera.
4. **Configuración → Sucursales**: si tenés más de un local, creá cada sucursal con su depósito y su punto de venta.
5. **Configuración → Impuestos**: si sos agente de percepción o retención, activá lo que corresponda. Si no, dejalo como está.

**Prueba del día**: entrá a Comprobantes → Nueva factura, elegí Consumidor Final, cargá un ítem cualquiera a mano y emití. Tiene que salir un comprobante (simulado o con CAE) que se pueda imprimir. Después anulalo con nota de crédito o dejalo: es la prueba.

## Día 2 · Artículos y precios

1. ★ **Configuración → Importar datos → Artículos**: bajá la plantilla, pegá tu lista (código, descripción, rubro, costo, precios, IVA, stock) y subila. Si venís de Tango, Bejerman o Colppy, elegí el perfil en "Viene de" y el mapeo se arma solo. Revisá la vista previa y confirmá.
2. **Stock → artículo**: revisá que los rubros hayan quedado bien y que los artículos de servicio (mano de obra, flete) estén como "servicio" para que no muevan stock.
3. **Stock → Precios**: si tus precios son un margen sobre el costo, configurá el margen por rubro; así al subir el costo sube el precio.
4. **Stock → Etiquetas**: imprimí códigos de barras para lo que no tiene.

**Prueba del día**: en el buscador (Ctrl+K) escribí parte del nombre de tres artículos y verificá precio y stock. Escaneá uno con el lector en Punto de venta.

## Día 3 · Clientes, proveedores y saldos

1. ★ **Importar → Clientes** y **Importar → Proveedores** con la plantilla. CUIT y condición IVA son importantes: definen si sale factura A o B.
2. ★ **Importar → Saldos de clientes** y **Saldos de proveedores**: el detalle por comprobante pendiente al día del corte. Así la cuenta corriente arranca al día y los vencimientos se calculan bien.
3. **Clientes → tipos de cliente**: si manejás listas o descuentos por tipo (mayorista, minorista), crealos y asignalos.
4. **Clientes → Vendedores**: si liquidás comisiones, cargá los vendedores y su porcentaje.

**Prueba del día**: abrí la ficha de un cliente con deuda y compará el saldo con el sistema anterior. Tienen que coincidir.

## Día 4 · Usuarios, roles y forma de trabajo

1. ★ **Configuración → Usuarios**: un usuario por persona, con su rol (vendedor, cajero, depósito, compras, contador). No compartan claves: la auditoría registra quién hizo cada cosa.
2. **Configuración → Roles y permisos**: si un rol necesita más o menos de lo que trae, copialo y ajustalo permiso por permiso.
3. **Configuración → Empresa → Punto de venta**: balanza, impresora térmica, tarjetas y planes de cuotas, Mercado Pago QR o Point si los usás.
4. **Configuración → Seguridad**: activá la verificación en dos pasos, al menos para el dueño y el administrador.
5. Cada usuario entra por primera vez: ve el tour y su pantalla de inicio según el rol.

**Prueba del día**: que un vendedor haga una factura desde su usuario y que un cajero la cobre. Fondos tiene que mostrar el cobro en la caja correcta.

## Día 5 · Canales, avisos y contador

1. **Configuración → Tienda y canales**: tienda online propia, WhatsApp, Mercado Libre, Tiendanube o el que uses. Los pedidos entran en Comprobantes → Pedidos.
2. **Configuración → Empresa → Avisos al dueño**: resumen diario por WhatsApp y alertas críticas.
3. **Contable → Contador**: dale acceso al estudio con su email. Ve libros, exportaciones y todo lo que necesita cada mes sin pedirte nada.
4. **Configuración → Copias de seguridad**: verificá que la copia automática esté encendida y bajá una para guardarla afuera.

## El corte con el sistema anterior

- Elegí un día de corte (idealmente inicio de mes). Hasta ese día se opera en el sistema viejo; desde ese día, todo acá.
- Los saldos que importaste tienen que ser los del cierre del día anterior al corte.
- Durante la primera semana revisá cada tarde **Comprobantes → Pendientes** (entregado sin facturar, facturado sin entregar) y **Fondos → Control de cajas**.
- A fin del primer mes, el contador entra a **Contable → Contador**, corre el checklist y exporta los asientos. Si algo no cierra, aparece ahí.

## Cuándo pedir ayuda

Todo lo de esta guía se puede hacer sin nadie. Si algo se traba, el botón **?** de cada pantalla abre la guía de esa pantalla, y **Soporte** (menú) abre un ticket que se responde ahí y por mail.
