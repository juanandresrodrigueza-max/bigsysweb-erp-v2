<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use App\Services\Suscripciones\SuscripcionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;

// Fase 46: "Registrate gratis". La empresa se da de alta sola con 15 días de Prueba (todo activado) y después sigue en Gratis.
class RegistroController extends Controller
{
    public const DIAS_PRUEBA = 15;

    public function show()
    {
        return Inertia::render('Auth/Registro', ['rubros' => Business::VERTICALES, 'marca' => Crypt::encryptString((string) now()->timestamp), 'dias' => self::DIAS_PRUEBA, 'captcha' => config('services.turnstile.site_key')]);
    }

    public function store(Request $request, SuscripcionService $s)
    {
        // Contra robots: el campo trampa tiene que venir vacío y el formulario no se completa en menos de 3 segundos.
        try { $t = (int) Crypt::decryptString((string) $request->input('marca')); } catch (\Throwable) { $t = 0; }
        // Fase 113.1: con error en el formulario (no un 422 pelado, que el panel mostraba como «Oops! An Error Occurred»).
        if (filled($request->input('sitio_web')) || ! $t || now()->timestamp - $t < 3) return back()->withErrors(['empresa' => 'No pudimos validar el formulario. Esperá unos segundos y volvé a tocar «Crear mi cuenta gratis».'])->withInput($request->except('password', 'password_confirmation'));

        if ($secret = config('services.turnstile.secret')) {
            $ok = \Illuminate\Support\Facades\Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => $secret, 'response' => (string) $request->input('cf-turnstile-response'), 'remoteip' => $request->ip()])->json('success');
            if (! $ok) return back()->withErrors(['captcha' => 'Confirmá que no sos un robot.'])->withInput($request->except('password', 'password_confirmation'));
        }
        $d = $request->validate([
            'empresa' => 'required|string|max:120', 'nombre' => 'required|string|max:120', 'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:8|confirmed', 'telefono' => 'nullable|string|max:40', 'rubro' => 'nullable|in:' . implode(',', array_keys(Business::VERTICALES)),
            'cuit' => ['nullable', 'string', 'max:20', fn($a, $v, $fail) => $v && ! \App\Support\Cuit::valido($v) ? $fail('El CUIT no es válido.') : null], 'acepto' => 'accepted',
        ], ['email.unique' => 'Ya hay una cuenta con ese email. Ingresá o recuperá la contraseña.', 'acepto.accepted' => 'Tenés que aceptar los términos.']);

        $plan = Plan::where('slug', 'prueba')->first() ?? Plan::orderByDesc('id')->firstOrFail();
        $b = $s->crearEmpresa(['name' => $d['empresa'], 'dueno_nombre' => $d['nombre'], 'dueno_email' => $d['email'], 'dueno_password' => $d['password'], 'dueno_mobile' => $d['telefono'] ?? null,
            'phone' => $d['telefono'] ?? null, 'cuit' => $d['cuit'] ?? null, 'vertical' => $d['rubro'] ?? 'otro', 'plan_id' => $plan->id, 'modo' => 'trial', 'dias_prueba' => self::DIAS_PRUEBA, 'notas_internas' => 'Alta desde Registrate gratis']);

        // Fase 106: entra al toque, pero queda pendiente de aprobación (acceso provisional). Avisa a BigSys por mail, WhatsApp y celular.
        $aprob = app(\App\Services\Suscripciones\AprobacionService::class);
        $aprob->marcarPendiente($b);
        $aprob->avisarAlta($b->fresh('owner'));
        Auth::login(User::findOrFail($b->owner_id));
        $request->session()->regenerate();
        return redirect('/primeros-pasos')->with('success', '¡Listo! Tenés ' . self::DIAS_PRUEBA . ' días con todo activado. Empezá por estos pasos.');
    }

    // Fase 106: página de espera cuando el alta no se aprobó dentro del plazo provisional. Si ya se aprobó, sigue adentro.
    public function esperando(Request $request)
    {
        $b = $request->user()->business;
        $aprob = app(\App\Services\Suscripciones\AprobacionService::class);
        if (! $b || $b->aprobacion !== 'pendiente' || ! $aprob->vencida($b)) return redirect('/dashboard');
        return Inertia::render('Auth/EsperandoAprobacion', ['empresa' => $b->name, 'mensaje' => \App\Services\Suscripciones\AprobacionService::MENSAJE_ESPERANDO,
            'soporte' => ['whatsapp' => \App\Models\SistemaConfig::get('soporte_whatsapp'), 'email' => \App\Models\SistemaConfig::get('soporte_email')]]);
    }
}
