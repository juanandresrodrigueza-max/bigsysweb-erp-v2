# Inscripción de las bases de datos de BigSys ante la AAIP: borrador

**Es un borrador para que lo revise el abogado antes de cargarlo.** El trámite tiene carácter de **declaración jurada**. Lo que figura acá sale de lo que el sistema guarda de verdad (revisado en el código el 20/11/2026) y de la política de privacidad publicada en `/privacidad`. Lo marcado **[completar]** lo tiene que poner BigSys. Lo marcado **[abogado]** es una decisión o una duda para él.

Los nombres de los campos del formulario de TAD pueden no ser exactamente estos. El contenido de cada campo sí es el que corresponde.

## 1 · Cómo es el trámite

Se hace por **Trámites a Distancia (TAD)**, con la clave fiscal de BIGSYS S.R.L. (nivel 2 o superior), en dos pasos:

1. **«Inscripción de responsable de bases de datos privadas»**: los datos de la empresa y el **estatuto o contrato social** en PDF. Al terminar llega por mail el **código de responsable**.
2. **«Inscripción de bases de datos privadas»**: un trámite por cada base, con el código del paso 1.

No se renueva todos los años. Se **modifica** cuando la base cambia (por ejemplo, si se suma un proveedor en otro país). Consultas: registrobasesdedatos@aaip.gob.ar.

## 2 · Paso 1: el responsable

| Campo | Qué va |
|---|---|
| Razón social | BIGSYS S.R.L. |
| CUIT | 30-71438573-5 |
| Domicilio legal | **[completar]** (el del estatuto), Mendoza |
| Domicilio donde se ejercen los derechos | **[completar]** (puede ser el mismo) |
| Representante legal | **[completar]** nombre y DNI de quien firma |
| Mail de contacto | privacidad@bigsys.com.ar (es el que publica la política de privacidad; tiene que existir y leerse) |
| Teléfono | **[completar]** |
| Documentación | Estatuto o contrato social y la última designación de autoridades, en PDF |

## 3 · Qué bases inscribir

BigSys es **responsable** sólo de sus propias bases. Lo que cada empresa cliente carga en el ERP (sus clientes, proveedores y empleados) es de **esa empresa**: BigSys lo trata por su cuenta, como encargado (art. 25 de la Ley 25.326), y no lo inscribe como propio. Ver `docs/LEGAL-facturacion-por-bigsys.md` §6.2.

| N.º | Base | ¿Se inscribe? |
|---|---|---|
| 1 | **Clientes y usuarios de BigSys Web** (las cuentas del ERP) | Sí: el detalle está en el punto 4 |
| 2 | **Clientes y prospectos comerciales de BigSys** (el CRM, el BigSys de escritorio y la empresa BigSys dentro del ERP) | **[abogado]** Probablemente sí, pero es de otros sistemas: se completa con lo que guarda el CRM |
| 3 | **Personal de BigSys** (empleados y colaboradores) | **[abogado]** Si BigSys tiene empleados, sí (ficha, sueldos, legajo) |
| 4 | **Proveedores de BigSys** | **[abogado]** Si incluye personas humanas (no sólo empresas) |

## 4 · Base 1: Clientes y usuarios de BigSys Web

### Identificación

- **Nombre de la base**: Clientes y usuarios de BigSys Web.
- **Finalidad**: prestar el servicio de software de gestión en internet (ERP) a las empresas que lo contratan: crear y administrar las cuentas de acceso, cobrar la suscripción, dar soporte, avisar por mail, WhatsApp y notificaciones del celular, llevar el registro de quién hizo qué dentro de cada empresa (auditoría) y cumplir las obligaciones fiscales y contables de BigSys.
- **Finalidades que NO tiene**: no se ceden ni venden datos, no se usan para publicidad de terceros y no se arman perfiles para terceros.
- **Categorías de titulares**: dueños, socios y empleados de las empresas clientes que tienen un usuario; contadores invitados por una empresa; personas que se registran para probar el sistema.

### Datos que contiene

| Categoría | Datos | De dónde salen en el sistema |
|---|---|---|
| Identificatorios y de contacto | Nombre, mail, celular, foto de perfil (opcional), idioma | Usuarios (`users`) |
| De acceso | Contraseña (sólo el hash, nunca el texto), segundo factor (el secreto cifrado), si entró con Google, último ingreso | Usuarios |
| De la empresa cliente | Razón social, nombre comercial, CUIT, condición de IVA, domicilio, teléfono, mail, logo | Empresas (`businesses`) |
| Comerciales y de pago | Plan contratado, períodos, importes, medio de pago, número de operación de Mercado Pago, comprobantes que BigSys le emitió | Suscripciones y pagos (`pagos_suscripcion`) |
| De soporte | Asunto y mensajes de los pedidos de soporte | Tickets |
| Técnicos y de uso | Registro de acciones (qué, cuándo, quién) con dirección IP, pantallas usadas, errores técnicos, identificador del celular para notificaciones y modelo del equipo | Auditoría (`audit_logs`), avisos push, monitoreo |
| De la autorización de facturación | Quién aceptó, cuándo, desde qué IP y el texto aceptado | Constancias de autorización a BigSys en ARCA |

- **Datos sensibles** (art. 2: origen racial, opiniones políticas, salud, vida sexual, etc.): **ninguno**.
- **Audios y fotos** que se le mandan al asistente: se procesan para interpretarlos y no se guardan como base. **[abogado]** confirmar que no hace falta declararlos.

### Origen y forma de recolección

- El **propio titular**, al registrarse o cuando el dueño de la empresa lo da de alta como usuario, desde la web o la app.
- **Google**, si el usuario elige «Entrar con Google» (nombre y mail).
- **Mercado Pago**, el resultado de cada pago de la suscripción.

### Cesiones (destinatarios que reciben datos para usarlos ellos)

- **ARCA**: los datos que exige cada comprobante que BigSys le emite a su cliente. Es una obligación legal.
- **Ninguna otra.**

### Encargados del tratamiento (proveedores que procesan por cuenta de BigSys)

| Proveedor | Para qué | País | Estado |
|---|---|---|---|
| Hostinger | Servidor donde corre el sistema y está la base | **[completar]** (el país del centro de datos del VPS figura en el panel de Hostinger) | |
| Backblaze | Copias de seguridad cifradas fuera del servidor | **[completar]**: Estados Unidos o Unión Europea, según la región de la cuenta (`us-…` o `eu-…` en el endpoint) | Las copias viajan cifradas con AES-256 y la clave no está en Backblaze |
| Mercado Pago | Cobro de la suscripción | Argentina | |
| Hostinger (correo) | Envío de mails | **[completar]** | |
| Meta (WhatsApp Business) | Avisos por WhatsApp | Estados Unidos | |
| Google (Firebase) y Apple | Notificaciones del celular | Estados Unidos | |
| Google | Entrar con Google | Estados Unidos | |
| Anthropic y OpenAI | Asistente (Bigcito) y voz | Estados Unidos | Se envía lo justo para cada consulta y no entrenan modelos con esos datos |
| Sentry (si se activa) | Registro de errores técnicos | Estados Unidos | Hoy no está activado |

### Transferencias internacionales

**[abogado] Es el punto más delicado.** El art. 12 de la Ley 25.326 prohíbe transferir datos a países sin protección adecuada, salvo excepciones. Según la Disposición 60-E/2016 de la Dirección Nacional de Protección de Datos Personales, la Unión Europea tiene protección adecuada y **Estados Unidos no**. Para esos casos la misma disposición aprobó **contratos modelo**. Hay que definir:

- si alcanza con los términos de cada proveedor (casi todos ofrecen un anexo de tratamiento de datos) o hay que firmar el contrato modelo;
- si conviene elegir la región europea en Backblaze y un centro de datos europeo en Hostinger, para que las dos bases de datos completas queden en la Unión Europea;
- cómo declararlo en el formulario.

### Dónde está la base

- **Base en uso**: PostgreSQL en el servidor VPS de Hostinger, **[completar país]**.
- **Copias**: Backblaze B2, **[completar región]**. Son copias de toda la base, cifradas, una por noche.

### Conservación

- Mientras la cuenta esté activa.
- Después de la baja, los datos de la empresa quedan **90 días** y se eliminan (la política de privacidad lo dice así, y el panel avisa cuando hay empresas por eliminar).
- Lo que BigSys necesita para su propia contabilidad y sus impuestos (qué contrató cada cliente, qué se le facturó y cobró) se guarda el plazo legal: **10 años** (art. 328 del Código Civil y Comercial). **[contador]** confirmar el plazo.
- Las constancias de autorización de facturación se conservan aunque la empresa se dé de baja.

### Derechos de los titulares

- **Cómo**: por mail a privacidad@bigsys.com.ar, o desde el sistema (cada usuario ve y corrige sus datos; el dueño exporta todo y da de baja la empresa en Configuración → Suscripción).
- **Plazos**: la Ley 25.326 fija **10 días corridos** para el acceso (art. 14) y **5 días hábiles** para rectificar, actualizar o suprimir (art. 16). **[abogado] La política de privacidad hoy dice «10 días hábiles» para todo: es más que lo que da la ley. Conviene corregirla a los plazos legales antes de inscribir.**

### Medidas de seguridad

Lo que el sistema hace hoy (sirve para la parte de seguridad del formulario y para el documento de seguridad que recomienda la Resolución AAIP 47/2018):

- **Acceso**: usuario y contraseña por persona (sin usuarios compartidos), contraseñas guardadas con hash, segundo factor opcional, roles y permisos por pantalla, y cada empresa ve sólo sus propios datos.
- **Registro**: cada acción queda en la auditoría, con usuario, fecha e IP.
- **Cifrado**: conexión HTTPS siempre. Las claves y los certificados que se guardan en la base (tokens de Mercado Pago, WhatsApp, certificado de ARCA, contraseñas de casillas) están cifrados con la llave del servidor.
- **Copias**:
  - Una copia completa por noche, cifrada (AES-256), comprobada y guardada fuera del servidor.
  - Copias por empresa, con ensayo de restauración cada domingo.
  - El procedimiento está en `docs/MANUAL-DATOS-Y-RESTAURACION.md`.
- **Servidor**: acceso restringido a quienes administran, actualizaciones por un proceso fijo y monitoreo de errores y de salud con aviso por mail.
- **Incidentes**: si se detecta uno que afecte datos, se avisa a las empresas afectadas, como dice la política de privacidad. **[abogado]** si hay que fijar un plazo y un procedimiento por escrito.

## 5 · Lo que queda, en una lista

- [ ] **[completar]** Domicilio, representante legal, teléfono y el estatuto en PDF.
- [ ] **[completar]** El país del VPS de Hostinger (panel de Hostinger → VPS → ubicación) y la región de Backblaze.
- [ ] **[abogado]** Qué bases inscribir además de la 1 (punto 3).
- [ ] **[abogado]** Las transferencias a Estados Unidos: contrato modelo, términos de cada proveedor o mudar las bases a la Unión Europea.
- [ ] **[abogado]** Corregir los plazos de la política de privacidad (10 días corridos para el acceso y 5 hábiles para corregir o borrar).
- [ ] **[abogado]** Si los audios y fotos del asistente se declaran.
- [ ] **[contador]** El plazo de conservación de lo contable de BigSys.
- [ ] Comprobar que privacidad@bigsys.com.ar exista y que alguien lo lea.
- [ ] Con todo revisado: TAD → paso 1 → esperar el código → paso 2 con la base 1 (y las demás que diga el abogado).
- [ ] Cuando llegue la constancia, guardarla y tildar el punto en `docs/PENDIENTES-OPERATIVOS.md`.

## Fuentes

- [Trámites de la AAIP](https://www.argentina.gob.ar/aaip/datospersonales/tramites)
- [Instructivo TAD: responsable de bases privadas](https://www.argentina.gob.ar/sites/default/files/instructivo_tad_inscripcionymodificacionresponsable_basesprivadas_3.pdf)
- [Instructivo TAD: bases privadas](https://www.argentina.gob.ar/sites/default/files/instructivo_tad_inscripcionymodificacion_basesprivadas_3.pdf)
- [Obligaciones de los responsables (AAIP)](https://www.argentina.gob.ar/node/53779)
- Ley 25.326, arts. 2, 9, 12, 14, 16, 21 y 25. Disposición DNPDP 60-E/2016 (países adecuados y contratos modelo). Resolución AAIP 47/2018 (medidas de seguridad recomendadas).

Las tres normas de la última línea están citadas de memoria: **[abogado]** confirmar los textos vigentes. Hay además un proyecto de ley que reemplazaría la 25.326 (ver `docs/LEGAL-facturacion-por-bigsys.md` §6.2).
