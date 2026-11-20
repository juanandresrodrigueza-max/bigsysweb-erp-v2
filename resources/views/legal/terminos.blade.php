@extends('legal.pagina', ['seccion' => 'terminos', 'kicker' => 'Claro y sin letra chica', 'titulo' => 'Términos de uso', 'bajada' => 'Las condiciones para usar BigSys Web y su app. Son las mismas para la prueba gratis, el plan Gratis y los planes pagos.'])
@section('contenido')
<h2>El servicio</h2>
<p>BigSys Web es un sistema de gestión (ERP) en la nube para empresas, ofrecido por BigSys S.R.L. Se usa desde el navegador y desde la app para iPhone y Android con la misma cuenta. La app no vende nada por su cuenta: la suscripción se contrata y se paga en la web.</p>

<h2>Tu cuenta</h2>
<ul>
  <li>Quien crea la empresa es el dueño de la cuenta y decide quién entra y con qué permisos.</li>
  <li>Cada persona usa su propio usuario y es responsable de lo que hace con él. No compartas la contraseña; activá el doble factor si manejás plata.</li>
  <li>Los datos que cargás tienen que ser tuyos o tener derecho a usarlos.</li>
</ul>

<h2>Prueba, plan Gratis y planes pagos</h2>
<ul>
  <li>La prueba dura 15 días con todo activado y no pide tarjeta. Al terminar, si no elegís un plan, seguís en el plan Gratis con sus límites y no perdés tus datos.</li>
  <li>Los planes pagos se cobran por mes o por año en pesos, a la cotización del día del precio en dólares publicado, más IVA. Podés cambiar de plan o darte de baja cuando quieras desde Configuración → Suscripción; la baja rige al final del período pagado.</li>
  <li>Si una suscripción vence, hay días de gracia; después la empresa queda suspendida (se puede entrar a exportar los datos) hasta que se regularice.</li>
</ul>

<h2>Lo que nos comprometemos</h2>
<ul>
  <li>Mantener el servicio disponible, con copias de seguridad diarias y la información cifrada en tránsito.</li>
  <li>Avisar con anticipación los mantenimientos programados.</li>
  <li>Que tus datos sean tuyos: los exportás cuando quieras, a Excel o por API, y los borramos si lo pedís.</li>
</ul>

<h2 id="facturacion">Factura electrónica a través de BigSys</h2>
<p>Si la empresa lo elige, BigSys S.R.L. emite y consulta sus comprobantes electrónicos ante ARCA en su nombre, con un certificado digital de BigSys. Para eso la empresa le delega el servicio «Facturación Electrónica» desde el Administrador de Relaciones de Clave Fiscal de ARCA y acepta, dentro del sistema, la autorización correspondiente. Queda registrado quién la aceptó, cuándo, desde dónde y con qué texto.</p>
<ul>
  <li><b>La empresa sigue siendo el emisor.</b> Los comprobantes salen con su CUIT, su punto de venta y su numeración. BigSys es el medio técnico: emite sólo lo que generan los usuarios de la empresa en el sistema, con los datos que ellos cargan, y no emite nada por su cuenta.</li>
  <li><b>Los datos y las obligaciones fiscales son de la empresa.</b> Es responsable de que los datos de cada comprobante sean correctos (cliente, importes, alícuotas, condición frente al IVA) y de cumplir sus obligaciones ante ARCA y los demás organismos.</li>
  <li><b>Si el sistema se equivoca.</b> Si un comprobante sale mal por una falla del sistema (por ejemplo, un cálculo equivocado), BigSys la corrige sin cargo y ayuda a emitir la nota de crédito o de débito que corresponda. Las multas, intereses o reclamos que se originen en datos cargados por la empresa son de la empresa.</li>
  <li><b>Lo que depende de ARCA.</b> BigSys no responde por rechazos, demoras o caídas de los servicios de ARCA. Si ARCA no responde, el comprobante queda pendiente y el sistema lo reintenta solo; si lo rechaza, el sistema muestra el motivo para corregirlo.</li>
  <li><b>El certificado de BigSys.</b> BigSys lo guarda cifrado, no lo comparte y lo usa sólo para emitir y consultar comprobantes de las empresas que lo autorizaron. Si sospecha que fue comprometido, lo revoca ante ARCA y avisa a las empresas.</li>
  <li><b>Cómo se deja sin efecto.</b> La empresa puede apagar la facturación a través de BigSys cuando quiera, desde Configuración → Puntos de venta y ARCA, y revocar la relación en ARCA. Si la empresa se da de baja, BigSys la apaga y le explica al dueño cómo revocar la relación en ARCA.</li>
  <li><b>Lo emitido queda.</b> Los comprobantes emitidos son de la empresa: los puede consultar y descargar mientras tenga la cuenta, y también están en ARCA.</li>
</ul>

<h2 id="datos">Los datos de tu empresa: los tratamos por tu cuenta</h2>
<p>Los datos que tu empresa carga sobre sus clientes, proveedores y empleados son de tu empresa, que es la responsable de esa base. BigSys los trata por cuenta y orden de ella, como prestador de servicios de tratamiento de datos (artículo 25 de la Ley 25.326). Al usar el sistema, tu empresa nos encarga ese tratamiento en estas condiciones:</p>
<ul>
  <li><b>Sólo para prestarte el servicio.</b> No usamos esos datos para otra finalidad, no los vendemos y no los cedemos a terceros, ni siquiera para conservarlos.</li>
  <li><b>Servicios que usamos para eso.</b> Tu empresa nos autoriza a apoyarnos en los proveedores que figuran en la <a href="/privacidad">política de privacidad</a> (servidores, ARCA, y los que vos conectes: Mercado Pago, WhatsApp, inteligencia artificial, notificaciones), cada uno sólo con lo que necesita. Si sumamos uno nuevo, lo publicamos ahí.</li>
  <li><b>Seguridad y confidencialidad.</b> Aplicamos medidas técnicas y organizativas para proteger los datos (conexión cifrada, claves cifradas, accesos restringidos, copias verificadas) y quienes trabajan en BigSys guardan secreto sobre ellos.</li>
  <li><b>Te ayudamos con los pedidos de las personas.</b> Si un cliente o empleado tuyo pide acceder, corregir o borrar sus datos, el sistema te deja hacerlo; si hace falta, te damos una mano.</li>
  <li><b>Incidentes.</b> Si detectamos un incidente que afecte los datos de tu empresa, te avisamos sin demora con lo que sepamos y lo que estamos haciendo.</li>
  <li><b>Cuando termina el servicio.</b> Al darte de baja, los datos quedan 90 días por si volvés o nos pedís una copia; después los eliminamos. Guardar los comprobantes y registros por el plazo que exige la ley es obligación de tu empresa: antes de irte, bajá una copia completa desde Configuración → Datos. Si nos lo pedís por escrito, podemos acordar conservarlos por más tiempo.</li>
</ul>

<h2>Lo que no se puede</h2>
<p>Usar el sistema para actividades ilegales, intentar acceder a datos de otras empresas, revender el servicio sin acuerdo, o sobrecargarlo con uso automatizado abusivo. En esos casos podemos suspender la cuenta.</p>

<h2>Responsabilidad</h2>
<p>El sistema es una herramienta: las decisiones comerciales, fiscales y contables son de cada empresa y de sus asesores. Las respuestas de Bigcito (la IA) son sugerencias y cada acción importante se confirma antes de hacerse. Respondemos por los daños directos que cause una falla nuestra hasta el monto pagado en los últimos 12 meses. Esta limitación no se aplica si el daño se debe a dolo o culpa grave de BigSys.</p>

<h2>Cambios</h2>
<p>Si cambiamos estos términos, avisamos dentro del sistema con al menos 15 días. Seguir usándolo implica aceptarlos. Rige la ley argentina y los tribunales ordinarios de la ciudad de Mendoza.</p>
@endsection
