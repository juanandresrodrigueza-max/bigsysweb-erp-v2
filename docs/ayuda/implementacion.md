---
titulo: Guía de implementación: cómo arrancar paso a paso
modulo: dashboard
rutas: /primeros-pasos
orden: 0
resumen: Cómo funciona el sistema y el paso a paso para que una empresa empiece a trabajar: qué tener a mano, en qué orden configurar, cómo traer los datos desde BigSys de escritorio y cuándo hacer el corte.
---

Esta guía sigue el mismo orden que **Primeros pasos** (menú → Inicio → Primeros pasos). Arriba de esa pantalla siempre está el **Siguiente paso**: qué es, para qué sirve, cuánto lleva y un botón para empezar. Lo que hacés en otra pantalla se tilda solo. Lo mínimo para empezar a facturar está marcado con ★; lo demás se puede hacer después.

## Cómo funciona, en un minuto

- **Está en internet.** Se entra desde la compu o el celular en erp.bigsysweb.com, sin instalar nada. Cada persona tiene su usuario y ve sólo lo que su rol permite.
- **Todo sale de la venta.** Cuando hacés una factura o una venta de mostrador, el sistema descuenta el stock, carga la cuenta corriente del cliente, registra la plata en la caja o el banco y arma el asiento contable. Nada se carga dos veces.
- **La factura electrónica la hace BigSys por vos.** Le das permiso a BigSys en ARCA con tu clave fiscal, una sola vez. No hay certificados que generar, subir ni renovar.
- **Si venís de BigSys de escritorio, no perdés nada.** Con BIG Connect se traen tus datos. El escritorio sigue andando hasta el día que elijas para cambiar.
- **Bigcito te acompaña.** El asistente violeta responde preguntas, hace tareas y te avisa lo importante. El **?** del encabezado abre la ayuda de la pantalla en la que estás.

## Antes de empezar: qué tener a mano

- CUIT, razón social, condición frente al IVA, domicilio comercial, número de Ingresos Brutos y fecha de inicio de actividades: ARCA exige que salgan en la factura.
- La **clave fiscal** de quien factura (nivel 3 o más), para dar el permiso en ARCA.
- El número de **punto de venta para web services** en ARCA. Si no tenés uno, se crea en ARCA en «Administración de puntos de venta y domicilios». Si sos responsable inscripto, es del tipo «RECE para aplicativo y web services». Si sos monotributista, es «Factura Electrónica - Monotributo - Web Services». El de «Comprobantes en línea» (el de facturar a mano en la web de ARCA) no sirve para un sistema.
- **Si venís de BigSys de escritorio:** que BIG Connect esté instalado y sincronizando. Si no lo tenés, pedíselo a Soporte de BigSys.
- **Si venís de otro sistema:** la lista de artículos, la de clientes y proveedores y los saldos, exportados a Excel o CSV. Si no hay exportación, alcanza con las planillas que usás hoy.
- El logo en PNG o JPG, y el celular con **WhatsApp Business** si querés conectar el número de la empresa.

## Paso 1 · Tu empresa

1. ★ **Datos de la empresa** (Configuración → Empresa): nombre comercial, razón social, CUIT, condición de IVA, email y teléfono. En «Datos fiscales de la factura impresa» van el domicilio, Ingresos Brutos e inicio de actividades. En **Diseño de comprobantes** subís el logo y elegís los colores.
2. ★ **Punto de venta** (Configuración → Puntos de venta y ARCA): crealo con el mismo número que en ARCA y marcalo como electrónico.
3. **Factura electrónica**, en la misma pantalla. La guía tiene cuatro pasos, con un tilde verde en cada uno que ya está bien:
   1. **Datos fiscales**: el CUIT de la empresa cargado.
   2. **Punto de venta para web services** dado de alta en ARCA (ver «Antes de empezar»).
   3. **Autorizá a BigSys**: con tu clave fiscal, en ARCA → **Administrador de Relaciones de Clave Fiscal** → **Nueva relación**, elegís el servicio «Facturación Electrónica» y ponés como representante a BigSys. La pantalla te muestra el CUIT exacto, con un botón para copiarlo.
   4. **Verificá y activá**: **Verificar en ARCA** le pregunta a ARCA si los trámites quedaron bien, sin emitir nada. Después el dueño lee la autorización, la acepta y toca **Activar la facturación por BigSys**.

   Si ARCA todavía no ve la relación, esperá unos minutos y verificá de nuevo. Hasta que lo actives, las facturas salen **simuladas** (sin CAE): sirven para probar todo lo demás. Si ya tenés tu propio certificado, también se puede usar (en «Tengo mi propio certificado»).
4. Si tenés más de un local: **Configuración → Sucursales**, cada uno con su depósito y su punto de venta. Si sos agente de percepción o retención: **Configuración → Impuestos**.

**Prueba**: en la pantalla de ARCA tiene que decir «Facturás a través de BigSys» (o, con certificado propio, que la conexión anda bien con **Probar conexión con ARCA**).

## Paso 2 · Tus datos

### Si venís de BigSys de escritorio (lo más rápido)

1. En BIG Connect → **Importar y exportar → API**, generá una clave con acceso completo (empieza con `bk_`) y copiá la dirección del Hub. Si no sabés dónde está, pedíle a Bigcito «¿cómo conecto BIG Connect?» o escribile a Soporte.
2. En el ERP → **Configuración → Importar**, tarjeta **Traer todo desde BIG Connect**: pegá la dirección y la clave, elegí la empresa (`e01`, `e02`…) si hay varias y tocá **Conectar**.
3. **Probar sin guardar**: muestra cuántos clientes, proveedores, artículos, saldos, cajas y cheques entrarían, y cuáles tienen errores. No guarda nada. Corregí en el escritorio lo que haga falta y probá de nuevo.
4. **Migrar de verdad**: guarda todo. Se puede repetir cuantas veces quieras: lo que ya está se actualiza, no se duplica.
5. **Control de cierre**: compara los totales del escritorio con los del ERP (saldos de clientes y proveedores, inventario valorizado, cheques en cartera, el saldo de cada caja y banco) y dice qué cierra y qué no.

Se traen proveedores y clientes con su código de BigSys, CUIT, condición de IVA, lista, descuento, vendedor y límite de crédito. También los artículos con las 6 listas, costo, IVA, stock y mínimo, y lo impago de cada cuenta, comprobante por comprobante. Y además rubros y departamentos, vendedores con comisiones, empleados, cajas y bancos con su saldo, cheques en cartera, el último precio de cada proveedor y las ventas de los últimos 12 meses. Cada parte tiene su tilde.

**Prueba**: abrí la ficha de tres clientes con deuda y compará el saldo con el escritorio. Tienen que coincidir.

### Si venís de otro sistema o de Excel

1. ★ **Configuración → Importar → Artículos**: bajá la plantilla, pegá tu lista (código, descripción, rubro, costo, precios, IVA, stock) y subila. Si venís de Tango, Bejerman o Colppy, elegí el perfil en «Viene de» y las columnas se reconocen solas. Revisá la vista previa y confirmá.
2. ★ **Importar → Clientes** e **Importar → Proveedores**. El CUIT y la condición de IVA son importantes porque definen si sale factura A o B.
3. ★ **Importar → Saldos de clientes** y **Saldos de proveedores**: el detalle de cada comprobante pendiente al día del corte, para que la cuenta corriente y los vencimientos arranquen al día.

**Prueba**: buscá tres artículos con Ctrl+K y verificá el precio y el stock. Abrí un cliente con deuda y compará el saldo.

### Después, en los dos casos

- **Stock → Precios**: si tus precios son un margen sobre el costo, poné el margen por rubro; así, cuando sube el costo, sube el precio.
- **Stock → Más → Preferencias de stock → Rubros**: lo que heredan los artículos de cada rubro (IVA, perecedero, con número de serie, pesable).
- Los artículos de servicio (mano de obra, flete) van como «servicio» para que no muevan stock.
- **Clientes → ficha → Precios pactados**: los precios o descuentos fijos que tenés acordados con un cliente.

## Paso 3 · La plata y el equipo

1. ★ **Caja y banco** (Plata): una caja en efectivo y la cuenta bancaria con el saldo al día del corte. Si migraste desde BigSys, ya están. Si usás Mercado Pago, creá también la billetera.
2. ★ **Usuarios** (Configuración → Usuarios): un usuario por persona, con su rol (vendedor, cajero, depósito, compras, contador). No compartan contraseñas: queda registrado quién hizo cada cosa. Si un rol necesita más o menos permisos, ajustalo en **Roles y permisos**.
3. **WhatsApp de la empresa** (Configuración → Tienda y canales → WhatsApp): tocás **Conectar WhatsApp**, escaneás el QR con la app WhatsApp Business y listo. El número sigue andando en el celular. Desde ahí salen las facturas, los recordatorios de deuda y los avisos, y Bigcito puede atender consultas.
4. **Punto de venta del mostrador** (Configuración → Empresa → Punto de venta): balanza, impresora térmica, tarjetas y cuotas, y el QR o el Point de Mercado Pago, si los usás.

**Prueba**: que un vendedor haga una factura con su usuario y que un cajero la cobre. En Plata, el cobro tiene que aparecer en la caja correcta.

## Paso 4 · A vender

★ **Primera factura**: Vender → Nueva factura, a Consumidor Final, con un artículo cualquiera. Mirá cómo bajó el stock, cómo entró la plata y cómo se armó el asiento. Si fue de prueba, anulala con una nota de crédito.

Con esto ya estás trabajando. Lo que sigue en Primeros pasos son los pasos **para tu negocio** que sumó Bigcito (reparto, tienda online, producción, sueldos, el acceso del contador…) y los de **quedarte tranquilo**: la verificación en dos pasos, la copia automática y el plan.

## El corte con el sistema anterior

1. Elegí un día de corte, si se puede a principio de mes. Hasta ese día se trabaja en el sistema anterior; desde ese día, todo acá.
2. **Si venís de BigSys de escritorio**: al cierre del último día, corré otra vez **Migrar de verdad** y después **Control de cierre**. Cuando todo cierra, dejás de usar el escritorio. Si algo no cierra, el control te dice qué.
3. **Si venís de otro sistema**: los saldos que importes tienen que ser los del cierre del día anterior al corte.
4. Si en los días de transición facturaste en papel, cargá esas facturas como **Manual de talonario**, con su número y su fecha: entran en el libro de IVA sin pedir CAE.

## La primera semana

- Cada tarde: **Plata → Cerrar caja** y **Entregar → Por entregar** (lo entregado sin facturar y lo facturado sin entregar).
- Dale acceso al contador en **Contable → Contador** con su email: ve los libros y las exportaciones sin pedirte nada.
- A fin del primer mes, el contador corre el checklist de cierre. Si algo no cierra, aparece ahí.

## Cuándo pedir ayuda

Todo lo de esta guía se puede hacer sin nadie. Si algo se traba: el **?** de cada pantalla abre su guía, Bigcito responde «¿cómo hago…?», y **Soporte** (menú) abre un ticket que se responde ahí y por mail.
