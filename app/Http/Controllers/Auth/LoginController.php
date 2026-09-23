<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;

class LoginController extends Controller
{
    public function show()
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        // Bloqueo por intentos: 5 fallidos por email + IP → 5 minutos de espera. Cada fallo queda en la auditoría.
        $clave = 'login:' . mb_strtolower($credentials['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($clave, 5)) {
            $seg = RateLimiter::availableIn($clave);
            AuditLog::registrar('login_bloqueado', \App\Models\User::where('email', $credentials['email'])->first(), "Bloqueo por intentos ({$credentials['email']} desde {$request->ip()})");
            return back()->withErrors(['email' => "Demasiados intentos. Esperá " . ceil($seg / 60) . " minuto(s) y probá de nuevo."])->onlyInput('email');
        }
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($clave, 300);
            AuditLog::registrar('login_fallido', \App\Models\User::where('email', $credentials['email'])->first(), "Contraseña incorrecta ({$credentials['email']} desde {$request->ip()})");
            return back()->withErrors(['email' => 'Email o contraseña incorrectos.'])->onlyInput('email');
        }
        RateLimiter::clear($clave);

        $user = Auth::user();
        if ($user->status !== 'active') {
            Auth::logout();
            return back()->withErrors(['email' => 'Tu usuario está inactivo. Hablá con el administrador de tu empresa.']);
        }

        if ($user->two_factor_enabled_at) {
            // Segundo paso: se cierra la sesión provisoria y se pide el código antes de entrar.
            Auth::logout();
            $request->session()->put('2fa', ['user_id' => $user->id, 'remember' => $request->boolean('remember'), 'hasta' => now()->addMinutes(10)->timestamp]);
            return redirect('/login/verificar');
        }
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::registrar('login', $user, 'Inicio de sesión');

        return redirect()->intended($user->is_superadmin && ! $user->business_id ? '/admin' : '/dashboard');
    }

    public function verificar(Request $request)
    {
        $p = $request->session()->get('2fa');
        if (! $p || $p['hasta'] < now()->timestamp) return redirect('/login')->withErrors(['email' => 'Se venció el tiempo para el código. Entrá de nuevo.']);
        return Inertia::render('Auth/TwoFactor');
    }

    public function verificarStore(Request $request, \App\Services\Auth\TotpService $totp)
    {
        $d = $request->validate(['codigo' => 'required|string|max:12']);
        $p = $request->session()->get('2fa');
        if (! $p || $p['hasta'] < now()->timestamp) return redirect('/login')->withErrors(['email' => 'Se venció el tiempo para el código. Entrá de nuevo.']);
        $user = \App\Models\User::find($p['user_id']);
        if (! $user || ! $totp->validarLogin($user, $d['codigo'])) {
            AuditLog::registrar('login_fallido', $user, 'Código de dos pasos incorrecto');
            return back()->withErrors(['codigo' => 'Código incorrecto. Probá con el siguiente que muestre la app o usá un código de recuperación.']);
        }
        $request->session()->forget('2fa');
        Auth::login($user, $p['remember'] ?? false);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::registrar('login', $user, 'Inicio de sesión (dos pasos)');
        return redirect()->intended($user->is_superadmin && ! $user->business_id ? '/admin' : '/dashboard');
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }

    // ---- Recuperación de contraseña por mail ----------------------------------------------------
    public function recuperar()
    {
        return Inertia::render('Auth/Recuperar');
    }

    public function enviarRecuperacion(Request $request)
    {
        $d = $request->validate(['email' => 'required|email']);
        $estado = Password::sendResetLink(['email' => $d['email']]);
        AuditLog::registrar('recuperar_clave', \App\Models\User::where('email', $d['email'])->first(), "Pidió restablecer la contraseña ({$d['email']} desde {$request->ip()})");
        // Siempre la misma respuesta: no se revela si el email existe.
        return back()->with('success', 'Si el email está registrado, te mandamos un link para elegir una contraseña nueva. Revisá también el correo no deseado.')->with('estado', $estado);
    }

    public function restablecer(Request $request, string $token)
    {
        return Inertia::render('Auth/Restablecer', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function restablecerStore(Request $request)
    {
        $d = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => \App\Support\Clave::reglas()]);
        $estado = Password::reset($d, function ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            AuditLog::registrar('cambio_clave', $user, 'Restableció su contraseña por mail');
        });
        if ($estado !== Password::PASSWORD_RESET) return back()->withErrors(['email' => 'El link ya no sirve (venció o se usó). Pedí uno nuevo.']);
        return redirect('/login')->with('success', 'Contraseña cambiada. Ya podés ingresar.');
    }
}
