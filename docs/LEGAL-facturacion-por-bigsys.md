# Factura electrónica a través de BigSys: borrador para revisión legal

**Estado: BORRADOR para que lo revise el abogado o el contador de BigSys.** Lo escribió el equipo de desarrollo a partir de cómo funciona el sistema; no es una opinión legal. Todo lo que está acá ya está publicado en el sistema (fase 121.1) y se puede corregir sin tocar código: los textos viven en los archivos indicados.

## 1. Cómo funciona (para que el revisor entienda el caso)

- BigSys S.R.L. tiene un certificado digital propio, emitido por ARCA a su CUIT.
- Cada empresa cliente que quiere facturar así le **delega a BigSys** el servicio «Facturación Electrónica» desde su Administrador de Relaciones de Clave Fiscal (con su clave fiscal nivel 3). Es el mecanismo de representación que ofrece ARCA.
- Cuando un usuario de la empresa emite una factura en el sistema, el ERP la manda a ARCA firmada con el certificado de BigSys, **con el CUIT de la empresa como emisor**. El comprobante sale a nombre de la empresa, con su punto de venta y su numeración.
- Además de la delegación en ARCA, la empresa **acepta dentro del sistema** un texto de autorización. Queda guardado: quién lo aceptó (nombre y mail), cuándo, IP, navegador, CUIT y razón social, a quién se autorizó y el **texto exacto** con su versión. Cada activación es un registro nuevo y al apagarla se marca como revocada (no se borra).
- La empresa puede apagarlo cuando quiera. Si se da de baja (o BigSys la elimina), el sistema lo apaga y le manda al dueño los pasos para revocar la relación en ARCA.
- El CRM de BigSys funciona igual. Usan certificados distintos (mismo CUIT) por una cuestión técnica de ARCA.

## 2. Texto que acepta la empresa al activar

Archivo: `app/Services/Afip/ArcaAutorizaciones.php` (`texto()`), versión `2026-11-20`. Si se cambia, subir `VERSION`: las activaciones siguientes aceptan el texto nuevo y las anteriores conservan el que aceptaron.

> [Razón social] (CUIT […]) autoriza a [BigSys S.R.L.] (CUIT […]) a emitir y consultar comprobantes electrónicos ante ARCA en su nombre, con el servicio «Facturación Electrónica» que le delegó en el Administrador de Relaciones de Clave Fiscal. BigSys emite sólo los comprobantes que generan los usuarios de la empresa en el sistema, con los datos que ellos cargan; la empresa sigue siendo el emisor y la responsable de esos datos y de sus obligaciones fiscales. Esta autorización se puede dejar sin efecto en cualquier momento, apagando la facturación por BigSys en el sistema y revocando la relación en ARCA. Rigen los Términos de uso de BigSys Web.

La acepta **sólo el dueño de la cuenta** (quien la creó). Apagarla, en cambio, la puede cualquier usuario con permiso de editar la configuración. **A revisar:** si el dueño de la cuenta en el sistema alcanza, o conviene pedir además que declare tener facultades para obligar a la empresa (la delegación en ARCA sólo la puede hacer quien tiene la clave fiscal de la empresa).

## 3. Cláusula nueva en los Términos de uso

Archivo: `resources/views/legal/terminos.blade.php`, sección «Factura electrónica a través de BigSys» (`/terminos#facturacion`). Puntos que cubre:

1. Qué hace BigSys y que la empresa sigue siendo el emisor.
2. Que los datos de cada comprobante y las obligaciones fiscales son de la empresa.
3. Que BigSys no responde por rechazos, demoras o caídas de ARCA (con contingencia: pendiente y reintento).
4. Custodia del certificado de BigSys y qué hace BigSys si se compromete.
5. Cómo se deja sin efecto y qué pasa en la baja.
6. Que lo emitido es de la empresa.

La limitación general de responsabilidad que ya existía («daños directos hasta el monto pagado en los últimos 12 meses») aplica también a esto. **A revisar:** si alcanza para este servicio o conviene algo específico (por ejemplo, multas o recargos de ARCA por errores de datos del cliente vs. errores del sistema).

## 4. Cambios en la Política de privacidad

Archivo: `resources/views/legal/privacidad.blade.php`.

- «Quién es responsable»: se separó lo que es de BigSys (las cuentas) de lo que es de cada empresa (los datos de sus clientes, proveedores y empleados), y se dijo que BigSys trata estos últimos **por cuenta y orden** de la empresa, como prestador de servicios de tratamiento de datos (**artículo 25 de la Ley 25.326**).
- «Con quién se comparten»: ARCA, con el certificado de BigSys o el propio, y qué datos se envían.

## 5. Aviso del cambio

Los Términos dicen que los cambios se avisan dentro del sistema con al menos 15 días. Se publicó una novedad («Actualizamos los términos de uso y la política de privacidad») que dice que rigen en 15 días. La cláusula de facturación sólo aplica a quien activa el servicio y lo acepta expresamente. **A revisar:** si alcanza con la novedad o conviene además un aviso por mail a los dueños.

## 6. Cosas para que verifique BigSys (no son de software)

- [ ] **Inscripción de las bases de datos ante la AAIP** (Registro Nacional de Bases de Datos, Ley 25.326). La política de privacidad nombra a la AAIP como órgano de control, pero el sistema no sabe si la inscripción está hecha.
- [ ] **Contrato o cláusula de encargo de tratamiento** (art. 25 Ley 25.326): ¿alcanza con los Términos aceptados al registrarse, o conviene un anexo firmado para clientes grandes?
- [ ] **Conservación**: la privacidad dice «hasta 10 años» para comprobantes y registros. Confirmar el plazo y qué se conserva cuando la empresa pide borrar todo (hoy: 90 días para volver; después se elimina, salvo lo que la ley obliga a guardar).
- [ ] **Quién puede aceptar la autorización** (punto 2): hoy, sólo el dueño de la cuenta.
- [ ] **Responsabilidad** por errores de facturación (punto 3).
- [ ] **Si existe algún registro o requisito** para BigSys como representante de terceros ante ARCA para web services. No conocemos ninguno, pero conviene confirmarlo.

## 7. Custodia del certificado (lo técnico, para que el revisor sepa qué se hace)

- La clave privada se genera **en el servidor** y no se descarga nunca. Se guarda cifrada con la clave de la aplicación (`APP_KEY`).
- Sólo los superadmins de BigSys ven el bloque del Panel; ninguna pantalla muestra la clave.
- El ERP y el CRM usan certificados distintos.
- El panel de Salud avisa por mail cuando el certificado está por vencer (30 días) y lo marca crítico a los 15 días o vencido.
- Procedimiento si se sospecha que se comprometió: está en `docs/ayuda/administracion-bigsys.md`, «Si el certificado de BigSys se compromete».
