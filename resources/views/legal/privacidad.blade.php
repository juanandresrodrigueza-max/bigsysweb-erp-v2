@extends('legal.pagina', ['seccion' => 'privacidad', 'kicker' => 'Tus datos son tuyos', 'titulo' => 'Política de privacidad', 'bajada' => 'Qué datos guarda BigSys Web, para qué los usa, con quién los comparte y cómo los sacás cuando quieras. Vale para la web y para la app de iPhone y Android.'])
@section('contenido')
<h2>Quién es responsable</h2>
<p>BigSys S.R.L. (Mendoza, Argentina) es responsable del tratamiento de los datos de las cuentas de BigSys Web, el sistema de gestión para PyMEs, y de su app para celulares. Los datos que cada empresa carga sobre sus clientes, proveedores y empleados son de esa empresa, que es la responsable de esa base; BigSys los trata sólo por cuenta y orden de ella, para prestarle el servicio, como prestador de servicios de tratamiento de datos (artículo 25 de la Ley 25.326), y no los usa para ningún otro fin. Cumplimos la Ley 25.326 de Protección de Datos Personales de la República Argentina; la Agencia de Acceso a la Información Pública (AAIP) es el órgano de control.</p>

<h2>Qué datos guardamos</h2>
<ul>
  <li><b>De tu cuenta:</b> nombre, email, teléfono, contraseña (cifrada, nunca la vemos), empresa, CUIT y rol.</li>
  <li><b>De tu negocio:</b> lo que cargás para gestionarlo: clientes, proveedores, artículos, comprobantes, cobros, pagos, stock, caja, contabilidad, sueldos y lo demás que uses. Son tus datos: nosotros sólo los guardamos y procesamos por tu cuenta.</li>
  <li><b>De uso:</b> qué pantallas se usan, errores técnicos y un registro de quién hizo qué (auditoría) para que puedas controlar tu propio sistema.</li>
  <li><b>De la app del celular:</b> un identificador del dispositivo para mandarte notificaciones y el modelo del equipo. El micrófono se usa sólo cuando le hablás a Bigcito y la cámara sólo cuando sacás una foto; los audios y las fotos se procesan para interpretarlos y no se usan para otra cosa.</li>
</ul>

<h2>Para qué los usamos</h2>
<ul>
  <li>Para que el sistema funcione: facturar, cobrar, controlar stock, cerrar caja, llevar la contabilidad y avisarte lo importante.</li>
  <li>Para darte soporte y mejorar el producto (nunca vendemos datos ni los usamos para publicidad de terceros).</li>
  <li>Para cobrar la suscripción y cumplir obligaciones legales y fiscales.</li>
</ul>

<h2>Con quién se comparten</h2>
<p>Sólo con los servicios necesarios para que el sistema haga lo que le pedís, y sólo los datos que cada uno necesita:</p>
<ul>
  <li><b>ARCA (ex AFIP)</b> para emitir y consultar comprobantes electrónicos: con el certificado de BigSys, si autorizaste a BigSys a facturar en tu nombre, o con tu propio certificado. Se envían los datos que exige el comprobante (por ejemplo, CUIT o DNI del cliente e importes).</li>
  <li><b>Mercado Pago, Mercado Libre y Tienda Nube</b> si los conectás, para cobrar y vender por esos canales.</li>
  <li><b>Meta (WhatsApp Business)</b> si lo conectás, para mandar y recibir mensajes con tus clientes.</li>
  <li><b>Proveedores de inteligencia artificial</b> (Anthropic u OpenAI) para Bigcito, con la clave que carga cada empresa: se les envía lo justo para responder la consulta y no entrenan modelos con tus datos.</li>
  <li><b>Google Firebase y Apple</b> para entregar las notificaciones de la app.</li>
  <li><b>Nuestro proveedor de servidores</b>, donde la información está cifrada en tránsito y respaldada todos los días.</li>
</ul>
<p>No transferimos datos a terceros con otros fines y no los vendemos.</p>

<h2>Cuánto tiempo los guardamos</h2>
<ul>
  <li><b>Los datos de tu negocio</b> (clientes, comprobantes, stock y lo demás que cargás): mientras la cuenta esté activa y 90 días después de la baja, por si volvés o nos pedís una copia. Después se eliminan. Guardar los comprobantes y registros por el plazo que exige la ley es obligación de cada empresa: antes de darte de baja, bajá una copia completa desde Configuración → Datos (los comprobantes electrónicos, además, quedan en ARCA).</li>
  <li><b>Los datos de tu cuenta con BigSys</b> (quién contrató, qué plan, qué se te facturó): el tiempo que exigen las normas fiscales y contables a BigSys para su propia facturación.</li>
  <li><b>La constancia de que autorizaste a BigSys a facturar en tu nombre</b>: se conserva aunque la apagues o te des de baja, como prueba de lo que se autorizó y hasta cuándo.</li>
</ul>

<h2>Tus derechos</h2>
<p>Podés acceder, corregir, exportar y borrar tus datos cuando quieras. Desde el sistema exportás todo a Excel o por API en cualquier momento. Para cualquier pedido escribinos a <a href="mailto:{{ $email }}">{{ $email }}</a> y lo resolvemos en un plazo máximo de 10 días hábiles. Si no quedás conforme, podés reclamar ante la AAIP.</p>

<h2 id="baja">Borrar mi cuenta</h2>
<p>Si sos dueño de la empresa, podés pedir la baja completa de la cuenta y de todos sus datos de dos formas:</p>
<ul>
  <li>Dentro del sistema: Configuración → Suscripción → <b>Dar de baja la empresa</b>.</li>
  <li>Por email a <a href="mailto:{{ $email }}?subject=Baja%20de%20cuenta%20BigSys%20Web">{{ $email }}</a> desde el email con el que entrás, indicando el nombre de la empresa.</li>
</ul>
<p>Te confirmamos la baja en 10 días hábiles. Los datos de tu negocio quedan 90 días por si volvés o nos pedís una copia, y después se eliminan; guardar tus comprobantes por el plazo legal es obligación de tu empresa, así que bajá antes la copia completa. Si sólo querés quitar la app del celular, desinstalala: el identificador para notificaciones se borra solo.</p>

<h2>Seguridad</h2>
<p>Conexión cifrada (HTTPS), contraseñas con hash, doble factor opcional, registro de cambios, copias de seguridad diarias verificadas y acceso al servidor restringido. Si detectamos un incidente que afecte tus datos, te avisamos.</p>

<h2>Cookies</h2>
<p>Usamos sólo las cookies necesarias para mantener tu sesión y recordar tus preferencias. No usamos cookies de publicidad.</p>
@endsection
