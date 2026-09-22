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

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::registrar('login', $user, 'Inicio de sesión');

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
