<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Email o contraseña incorrectos.'])->onlyInput('email');
        }

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
}
