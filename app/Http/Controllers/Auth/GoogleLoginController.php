<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SistemaConfig;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Fase 96: entrar con Google (OAuth 2, sin librerías). Sólo entra quien ya tiene usuario activo con ese mail: no crea cuentas,
// así la empresa sigue decidiendo quién entra. Respeta el doble factor igual que la contraseña.
class GoogleLoginController extends Controller
{
    private function credenciales(): array
    {
        $id = SistemaConfig::get('google_client_id'); $secret = SistemaConfig::get('google_client_secret');
        abort_unless($id && $secret, 404, 'Entrar con Google no está habilitado.');
        return [$id, $secret];
    }

    public function ir(Request $request)
    {
        [$id] = $this->credenciales();
        $state = Str::random(32); $request->session()->put('google_state', $state);
        return redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $id, 'redirect_uri' => url('/login/google/volver'), 'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account']));
    }

    public function volver(Request $request)
    {
        [$id, $secret] = $this->credenciales();
        if (! $request->code || ! $request->state || ! hash_equals((string) $request->session()->pull('google_state'), (string) $request->state)) return redirect('/login')->withErrors(['email' => 'No se pudo entrar con Google. Probá de nuevo.']);
        try {
            $tok = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', ['code' => $request->code, 'client_id' => $id, 'client_secret' => $secret, 'redirect_uri' => url('/login/google/volver'), 'grant_type' => 'authorization_code']);
            $info = $tok->ok() ? Http::withToken($tok->json('access_token'))->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo')->json() : null;
        } catch (\Throwable) { $info = null; }
        $email = mb_strtolower(trim((string) ($info['email'] ?? '')));
        if (! $email || empty($info['email_verified'])) return redirect('/login')->withErrors(['email' => 'Google no confirmó tu email. Entrá con tu contraseña.']);
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) { AuditLog::registrar('login_fallido', null, "Google: no hay usuario con {$email}"); return redirect('/login')->withErrors(['email' => "No hay una cuenta con {$email}. Pedile a tu empresa que te dé de alta, o registrate."]); }
        if ($user->status !== 'active') return redirect('/login')->withErrors(['email' => 'Tu usuario está inactivo. Hablá con el administrador de tu empresa.']);
        if ($user->two_factor_enabled_at) {
            $request->session()->put('2fa', ['user_id' => $user->id, 'remember' => true, 'hasta' => now()->addMinutes(10)->timestamp]);
            return redirect('/login/verificar');
        }
        Auth::login($user, true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::registrar('login', $user, 'Inicio de sesión con Google');
        if ($user->is_superadmin) app(\App\Services\Suscripciones\EmpresaBigSysService::class)->sumar($user);
        return redirect()->intended($user->is_superadmin ? '/admin' : ($user->empresas()->count() > 0 ? '/contador/empresas' : '/dashboard'));
    }
}
