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

<h2>Lo que no se puede</h2>
<p>Usar el sistema para actividades ilegales, intentar acceder a datos de otras empresas, revender el servicio sin acuerdo, o sobrecargarlo con uso automatizado abusivo. En esos casos podemos suspender la cuenta.</p>

<h2>Responsabilidad</h2>
<p>El sistema es una herramienta: las decisiones comerciales, fiscales y contables son de cada empresa y de sus asesores. Las respuestas de Bigcito (la IA) son sugerencias y cada acción importante se confirma antes de hacerse. Respondemos por los daños directos que cause una falla nuestra hasta el monto pagado en los últimos 12 meses.</p>

<h2>Cambios</h2>
<p>Si cambiamos estos términos, avisamos dentro del sistema con al menos 15 días. Seguir usándolo implica aceptarlos. Rige la ley argentina y los tribunales ordinarios de la ciudad de Mendoza.</p>
@endsection
