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

Archivo: `app/Services/Afip/ArcaAutorizaciones.php` (`texto()`), versión `2026-11-21`. Si se cambia, subir `VERSION`: las activaciones siguientes aceptan el texto nuevo y las anteriores conservan el que aceptaron.

> [Razón social] (CUIT […]) autoriza a [BigSys S.R.L.] (CUIT […]) a emitir y consultar comprobantes electrónicos ante ARCA en su nombre, con el servicio «Facturación Electrónica» que le delegó en el Administrador de Relaciones de Clave Fiscal. BigSys emite sólo los comprobantes que generan los usuarios de la empresa en el sistema, con los datos que ellos cargan; la empresa sigue siendo el emisor y la responsable de esos datos y de sus obligaciones fiscales. Esta autorización se puede dejar sin efecto en cualquier momento, apagando la facturación por BigSys en el sistema y revocando la relación en ARCA. Rigen los Términos de uso de BigSys Web. Quien la acepta declara tener facultades suficientes para obligar a la empresa.

La acepta **sólo el dueño de la cuenta** (quien la creó). Apagarla, en cambio, la puede cualquier usuario con permiso de editar la configuración. **A revisar:** si el dueño de la cuenta en el sistema alcanza, o conviene pedir además que declare tener facultades para obligar a la empresa (la delegación en ARCA sólo la puede hacer quien tiene la clave fiscal de la empresa).

## 3. Cláusula nueva en los Términos de uso

Archivo: `resources/views/legal/terminos.blade.php`, sección «Factura electrónica a través de BigSys» (`/terminos#facturacion`). Puntos que cubre:

1. Qué hace BigSys y que la empresa sigue siendo el emisor.
2. Que los datos de cada comprobante y las obligaciones fiscales son de la empresa.
3. Que BigSys no responde por rechazos, demoras o caídas de ARCA (con contingencia: pendiente y reintento).
4. Custodia del certificado de BigSys y qué hace BigSys si se compromete.
5. Cómo se deja sin efecto y qué pasa en la baja.
6. Que lo emitido es de la empresa.

La limitación general de responsabilidad («daños directos hasta el monto pagado en los últimos 12 meses») aplica también a esto, y desde la revisión del punto 6.5 dice que no se aplica en caso de dolo o culpa grave. Además hay una sección nueva sobre el tratamiento de datos por cuenta de la empresa (`/terminos#datos`, punto 6.2).

## 4. Cambios en la Política de privacidad

Archivo: `resources/views/legal/privacidad.blade.php`.

- «Quién es responsable»: se separó lo que es de BigSys (las cuentas) de lo que es de cada empresa (los datos de sus clientes, proveedores y empleados), y se dijo que BigSys trata estos últimos **por cuenta y orden** de la empresa, como prestador de servicios de tratamiento de datos (**artículo 25 de la Ley 25.326**).
- «Con quién se comparten»: ARCA, con el certificado de BigSys o el propio, y qué datos se envían.

## 5. Aviso del cambio

Los Términos dicen que los cambios se avisan dentro del sistema con al menos 15 días. Se publicó una novedad («Actualizamos los términos de uso y la política de privacidad») que dice que rigen en 15 días. La cláusula de facturación sólo aplica a quien activa el servicio y lo acepta expresamente. **A revisar:** si alcanza con la novedad o conviene además un aviso por mail a los dueños.

## 6. Los seis puntos: qué se averiguó y qué se hizo

Investigado el 20/11/2026. **Nada de esto es una opinión legal**: son las fuentes que se encontraron y lo que se cambió en el sistema a partir de ellas. Lo marcado «Para el abogado» es lo que queda por confirmar.

### 6.1 Inscripción de las bases de datos ante la AAIP

- La Ley 25.326 (art. 21) obliga a inscribir las bases de datos privadas destinadas a dar informes en el Registro Nacional de Bases de Datos. Según la guía oficial de la AAIP, una base no inscripta es ilícita.
- El trámite se hace por **Trámites a Distancia (TAD)** con clave fiscal nivel 2 o superior: primero «Inscripción de responsable de bases de datos privadas» (se adjunta el estatuto o contrato social; llega por mail el código de responsable) y después la inscripción de cada base. Ya no se renueva todos los años: se modifica cuando la base cambia. Tiene carácter de declaración jurada. Consultas: registrobasesdedatos@aaip.gob.ar.
- **Qué inscribiría BigSys como responsable**: su propia base de **clientes y usuarios** de BigSys Web (nombre, mail, teléfono, CUIT y datos de facturación de las empresas que contratan). Los datos que cada empresa carga sobre sus clientes, proveedores y empleados son de **esa empresa** (responsable); BigSys los trata por su cuenta (punto 6.2). Ninguna fuente encontrada dice que el encargado tenga que inscribirlos por separado.
- **Borrador del trámite**: `docs/LEGAL-inscripcion-AAIP.md`, con el contenido de cada campo para la base de clientes y usuarios de BigSys Web.
- **No se hizo nada en el sistema**: es un trámite de BigSys con su clave fiscal. **Para el abogado**: confirmar qué bases inscribir y si conviene recomendarles a las empresas clientes que inscriban las suyas.

Fuentes: [Trámites de la AAIP](https://www.argentina.gob.ar/aaip/datospersonales/tramites) · [Instructivo TAD responsable bases privadas](https://www.argentina.gob.ar/sites/default/files/instructivo_tad_inscripcionymodificacionresponsable_basesprivadas_3.pdf) · [Instructivo TAD bases privadas](https://www.argentina.gob.ar/sites/default/files/instructivo_tad_inscripcionymodificacion_basesprivadas_3.pdf) · [Obligaciones de los responsables (AAIP)](https://www.argentina.gob.ar/node/53779) · [Marval: resolución sobre registro de bases](https://www.marval.com/Publicacion/nueva-resolucion-sobre-registro-de-bases-de-datos-personales-13255)

### 6.2 Encargo de tratamiento (art. 25 Ley 25.326)

- El art. 25 regula el tratamiento de datos por cuenta de terceros: el prestador no puede usarlos para otra finalidad ni cederlos, **ni siquiera para conservarlos**; y cumplido el servicio, **tiene que destruirlos**, salvo que el responsable lo autorice expresamente (la guía oficial menciona hasta dos años si hay posibilidad razonable de nuevos encargos). Las obligaciones de seguridad y confidencialidad del art. 9 también alcanzan al encargado. La subcontratación necesita autorización del responsable.
- **Qué se hizo**: sección nueva en los Términos, **«Los datos de tu empresa: los tratamos por tu cuenta»** (`/terminos#datos`): finalidad única, no cesión, proveedores autorizados (los de la política de privacidad), seguridad y secreto, ayuda con los pedidos de las personas, aviso de incidentes, y qué pasa al terminar (90 días y eliminación; conservar más sólo si la empresa lo pide por escrito).
- **Para el abogado**: si alcanza con esta sección dentro de los Términos aceptados al registrarse, o conviene un anexo firmado para clientes grandes. Confirmar el texto oficial del art. 25 y del Decreto 1558/2001 (la búsqueda no trajo el texto oficial completo; hay además un proyecto de ley que reemplazaría la 25.326, cuyo estado conviene verificar).

Fuentes: [El encargado de tratamiento en el derecho argentino (abogados.com.ar)](https://abogados.com.ar/el-encargado-de-tratamiento-de-datos-personales-en-el-derecho-argentino/30096) · [Guía oficial de datos personales](https://www.argentina.gob.ar/sites/default/files/guia_elecciones.pdf) · [Decreto 1558/2001](https://www.argentina.gob.ar/normativa/nacional/70368/normas-modifican) · [Proyecto de reforma (HCDN)](https://rest.hcdn.gob.ar/web/proyectos/290271/adjuntos/104800)

### 6.3 Conservación

- El Código Civil y Comercial (art. 328) fija **10 años** para libros y documentación respaldatoria, salvo plazos mayores de leyes especiales. Para lo fiscal, las fuentes secundarias hablan también de 10 años (una cita 11 años para el art. 33 de la Ley 11.683). Una fuente menciona que la **Ley 27.799** (vigente desde el 2/1/2026) acortó la prescripción de ciertos tributos a 3 años; no se pudo verificar ni saber si cambia el plazo de conservación.
- Esa obligación de conservar es **del contribuyente** (cada empresa), no de BigSys. Y por el art. 25 (punto 6.2), BigSys, como encargado, **no debería** quedarse con esos datos después de terminado el servicio salvo pedido expreso.
- **Qué se hizo**:
  - La privacidad decía que BigSys guardaba «hasta 10 años» los comprobantes de las empresas dadas de baja. Se corrigió: los datos del negocio quedan **90 días** y después se eliminan; conservar es obligación de cada empresa, que antes de irse baja la copia completa (Configuración → Datos); los comprobantes electrónicos además quedan en ARCA. Los datos propios de BigSys (qué contrató cada cliente, qué se le facturó) se guardan lo que exija la ley a BigSys; la constancia de autorización de facturación también se conserva.
  - La pantalla de baja y el mensaje final ahora lo dicen claro, con un link a la copia completa.
  - **Se encontró que el sistema no cumplía lo que prometía**: nada eliminaba los datos a los 90 días (sólo se borra a mano desde el Panel), y además el botón «Eliminar definitivamente» del Panel **no funcionaba con las empresas ya dadas de baja** (daba «no encontrado»). Se corrigió el botón, y el panel de **Salud avisa** las empresas con más de 90 días de baja pendientes de eliminar. La eliminación sigue siendo manual (con nombre y clave de aprobación), a propósito: es irreversible.
- **Para el contador**: confirmar el plazo de conservación vigente (y el efecto de la Ley 27.799) para avisarles bien a las empresas.

Fuentes: [Art. 328 CCyC](https://www.conceptosjuridicos.com/ar/articulos/codigo-civil-y-comercial-articulo-328/) · [Conservación de documentación (Consejo Salta)](https://consejosalta.org.ar/wp-content/uploads/DOCUMENTACION-DEL-ENCARGO.-PAPELES-DE-TRABAJO.pdf) · [Plazo del art. 33 Ley 11.683 (abogados.com.ar)](https://abogados.com.ar/en-un-juicio-contra-la-afip-exigen-guardar-comprobantes-por-un-plazo-superior-al-establecido-en-la-ley/1923) · [Ley 11.683 t.o. 1998 (biblioteca ARCA)](https://biblioteca.afip.gob.ar/dcp/TOR_C_011683_1998_07_13) · [Instrucción General 3/2026 (KPMG)](https://assets.kpmg.com/content/dam/kpmg/ar/pdf/2026/Instruccion-General-3-2026.pdf)

### 6.4 Quién puede aceptar la autorización

- La delegación en ARCA sólo la puede hacer quien tiene la clave fiscal nivel 3 de la empresa, así que ya hay un filtro fuerte del lado de ARCA.
- **Qué se hizo**: la acepta sólo el **dueño de la cuenta**, y el texto ahora incluye que **quien acepta declara tener facultades suficientes para obligar a la empresa** (versión `2026-11-21`; las constancias anteriores conservan el texto que aceptaron).
- **Para el abogado**: si alcanza con esa declaración.

### 6.5 Responsabilidad

- Los Términos son un contrato por adhesión. El Código Civil y Comercial tiene por no escritas las cláusulas abusivas en esos contratos (art. 988) y, según el art. 1743 («Dispensa anticipada de la responsabilidad»), son inválidas las cláusulas que eximen o limitan la obligación de indemnizar cuando afectan derechos indisponibles, atentan contra la buena fe, las buenas costumbres o leyes imperativas, o son abusivas; tampoco pueden liberar anticipadamente del daño causado por dolo.
- **Qué se hizo**: la limitación general («hasta lo pagado en los últimos 12 meses») ahora dice que **no se aplica si el daño se debe a dolo o culpa grave de BigSys**. En la sección de facturación se agregó: si un comprobante sale mal por una **falla del sistema**, BigSys la corrige sin cargo y ayuda con la nota de crédito o débito; las multas o intereses que se originen en **datos cargados por la empresa** son de la empresa.
- **Para el abogado**: confirmar la redacción y si conviene un tope distinto para la facturación.

Fuentes: [Art. 988 CCyC](https://www.conceptosjuridicos.com/?p=201084) · [Contrato por adhesión (abogados.com.ar)](https://abogados.com.ar/contrato-por-adhesion-a-clausulas-generales-predispuestas/17080) · [Art. 1743 CCyC](https://codigocivilonline.com.ar/articulo-1743/) · [Comentario al art. 1743](https://www.dateas.com/es/comment/reply/2949)

### 6.6 ¿Hace falta algún registro para facturar en nombre de terceros?

- El manual oficial de ARCA sobre la delegación de web services prevé expresamente **autorizar a un tercero para que opere el web service** en nombre del contribuyente, desde el Administrador de Relaciones. No se encontró ningún registro ni requisito especial para el representante.
- Es el modelo que usan varios sistemas de facturación del mercado (por ejemplo Facturante, DUX, Contagram, Set-Api y Cactus publican la misma guía de delegación).
- Dos detalles prácticos que salieron de la búsqueda y **se corrigieron en el sistema**:
  - Los **monotributistas** dan de alta otro tipo de punto de venta: «Factura Electrónica - Monotributo - Web Services» (no «RECE para aplicativo y web services», que es para responsables inscriptos). La guía de la pantalla, la ayuda y los mensajes de error decían sólo el segundo.
  - Delegar «Facturación Electrónica» **no** habilita otros servicios (exportación, constatación, etc.). Ya estaba así en el sistema; ahora la ayuda lo dice.
- **Para el abogado**: confirmar que no hay requisitos adicionales.

Fuentes: [Manual de ARCA: delegar web services](https://www.arca.gob.ar/ws/WSAA/ADMINREL.DelegarWS.pdf) · [Facturante](https://facturante.ladesk.com/086854-C%C3%B3mo-delegar-el-servicio-de-Facturaci%C3%B3n-Electr%C3%B3nicaen-la-AFIP/086854-C%C3%B3mo-delegar-el-servicio-de-Facturaci%C3%B3n-Electr%C3%B3nicaen-la-AFIP?r=1) · [DUX](https://ayuda.duxsoftware.com.ar/es/articles/7856080-activar-facturacion-electronica-en-dux) · [Contagram](https://help.contagram.com/es/articles/9596200-pasos-para-el-alta-de-facturacion-electronica-en-arca) · [Set-Api](https://www.set-api.com/docs/delegacion-arca) · [Cactus](https://docs.cactus.do/recursos/delegacion-factura-electronica-arca/)

### Lo que queda para el abogado y el contador, en una lista

- [ ] Inscripción ante la AAIP: qué bases inscribe BigSys (6.1). **Es lo más urgente.**
- [ ] Si la sección de encargo dentro de los Términos alcanza o conviene un anexo firmado (6.2).
- [ ] Plazo de conservación vigente para avisarles a las empresas (6.3, contador).
- [ ] Si alcanza la declaración de facultades al aceptar (6.4).
- [ ] Redacción de la responsabilidad (6.5).
- [ ] Que no haya requisitos extra para representar ante ARCA (6.6).
- [ ] Si alcanza la novedad dentro del sistema para avisar el cambio de términos o conviene también un mail (punto 5).

## 7. Custodia del certificado (lo técnico, para que el revisor sepa qué se hace)

- La clave privada se genera **en el servidor** y no se descarga nunca. Se guarda cifrada con la clave de la aplicación (`APP_KEY`).
- Sólo los superadmins de BigSys ven el bloque del Panel; ninguna pantalla muestra la clave.
- El ERP y el CRM usan certificados distintos.
- El panel de Salud avisa por mail cuando el certificado está por vencer (30 días) y lo marca crítico a los 15 días o vencido.
- Procedimiento si se sospecha que se comprometió: está en `docs/ayuda/administracion-bigsys.md`, «Si el certificado de BigSys se compromete».
