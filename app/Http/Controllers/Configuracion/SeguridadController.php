<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Webhook;
use App\Services\Auth\TotpService;
use App\Services\Integraciones\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

// Seguridad e integraciones: dos factores, sesiones abiertas, tokens de API y webhooks.
class SeguridadController extends Controller
{
    public function index(Request $request, TotpService $totp)
    {
        $u = $request->user();
        $sesiones = config('session.driver') === 'database' ? DB::table('sessions')->where('user_id', $u->id)->orderByDesc('last_activity')->get()->map(fn($s) => ['id' => $s->id, 'actual' => $s->id === $request->session()->getId(), 'ip' => $s->ip_address, 'agente' => $this->agente($s->user_agent), 'activa' => \Carbon\Carbon::createFromTimestamp($s->last_activity)->diffForHumans()]) : collect();
        return Inertia::render('Configuracion/Seguridad', [
            'dosFactores' => ['activo' => (bool) $u->two_factor_enabled_at, 'desde' => $u->two_factor_enabled_at?->format('d/m/Y'), 'recuperacion' => $u->two_factor_enabled_at ? count($totp->codigosRecuperacion($u)) : 0],
            'setup' => session('totp_setup'), 'codigosNuevos' => session('totp_codigos'),
            'sesiones' => $sesiones, 'sesionesDb' => config('session.driver') === 'database',
            'tokens' => $u->tokens()->orderByDesc('id')->get()->map(fn($t) => ['id' => $t->id, 'nombre' => $t->name, 'creado' => $t->created_at->format('d/m/Y'), 'ultimo_uso' => $t->last_used_at?->diffForHumans() ?? 'nunca']),
            'tokenNuevo' => session('token_nuevo'),
            'webhooks' => Webhook::with(['entregas' => fn($q) => $q->latest()->limit(5)])->orderBy('nombre')->get()->map(fn($w) => ['id' => $w->id, 'nombre' => $w->nombre, 'url' => $w->url, 'eventos' => $w->eventos, 'secreto' => $w->secreto, 'activo' => $w->activo, 'fallos' => $w->fallos, 'ultimo' => $w->ultimo_envio_en?->diffForHumans(), 'entregas' => $w->entregas->map(fn($e) => ['id' => $e->id, 'evento' => $e->evento, 'status' => $e->status, 'ms' => $e->ms, 'fecha' => $e->created_at->format('d/m H:i'), 'respuesta' => $e->respuesta])]),
            'eventos' => Webhook::EVENTOS,
            'apiUrl' => url('/api'),
        ]);
    }

    private function agente(?string $ua): string
    {
        $ua = (string) $ua;
        $so = match (true) { str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone/iPad', str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => 'Otro' };
        $nav = match (true) { str_contains($ua, 'Edg') => 'Edge', str_contains($ua, 'Chrome') => 'Chrome', str_contains($ua, 'Safari') => 'Safari', str_contains($ua, 'Firefox') => 'Firefox', default => 'Navegador' };
        return "{$nav} en {$so}";
    }

    // --- Dos factores ---
    public function iniciar2fa(Request $request, TotpService $totp)
    {
        $secreto = $totp->generarSecreto();
        $request->session()->put('totp_pendiente', $secreto);
        return back()->with('totp_setup', ['secreto' => $secreto, 'uri' => $totp->uri($request->user(), $secreto)]);
    }

    public function confirmar2fa(Request $request, TotpService $totp)
    {
        $d = $request->validate(['codigo' => 'required|string|min:6|max:7']);
        $secreto = $request->session()->get('totp_pendiente');
        if (! $secreto || ! $totp->verificar($secreto, $d['codigo'])) return back()->withErrors(['codigo' => 'El código no coincide. Revisá la hora del teléfono y probá de nuevo.'])->with('totp_setup', $secreto ? ['secreto' => $secreto, 'uri' => $totp->uri($request->user(), $secreto)] : null);
        $codigos = $totp->activar($request->user(), $secreto);
        $request->session()->forget('totp_pendiente');
        AuditLog::registrar('editar', $request->user(), 'Activó la verificación en dos pasos');
        return back()->with('success', 'Verificación en dos pasos activada. Guardá los códigos de recuperación.')->with('totp_codigos', $codigos);
    }

    public function desactivar2fa(Request $request, TotpService $totp)
    {
        $d = $request->validate(['password' => 'required']);
        if (! Hash::check($d['password'], $request->user()->password)) return back()->withErrors(['password' => 'Contraseña incorrecta.']);
        $totp->desactivar($request->user());
        AuditLog::registrar('editar', $request->user(), 'Desactivó la verificación en dos pasos');
        return back()->with('success', 'Verificación en dos pasos desactivada.');
    }

    // --- Sesiones ---
    public function cerrarSesiones(Request $request)
    {
        $d = $request->validate(['password' => 'required']);
        if (! Hash::check($d['password'], $request->user()->password)) return back()->withErrors(['password' => 'Contraseña incorrecta.']);
        Auth::logoutOtherDevices($d['password']);
        if (config('session.driver') === 'database') DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        AuditLog::registrar('editar', $request->user(), 'Cerró las otras sesiones');
        return back()->with('success', 'Listo: las demás sesiones quedaron cerradas.');
    }

    public function cerrarSesion(Request $request, string $id)
    {
        if (config('session.driver') === 'database') DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $id)->delete();
        return back()->with('success', 'Sesión cerrada.');
    }

    // --- Tokens de API ---
    public function crearToken(Request $request)
    {
        $d = $request->validate(['nombre' => 'required|string|max:60']);
        $t = $request->user()->createToken($d['nombre']);
        AuditLog::registrar('crear', $request->user(), "Creó el token de API {$d['nombre']}");
        return back()->with('success', 'Token creado. Copialo ahora: no se vuelve a mostrar.')->with('token_nuevo', $t->plainTextToken);
    }

    public function borrarToken(Request $request, int $id)
    {
        $request->user()->tokens()->where('id', $id)->delete();
        return back()->with('success', 'Token revocado.');
    }

    // --- Webhooks ---
    public function guardarWebhook(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:80', 'url' => 'required|url|max:500', 'eventos' => 'required|array|min:1', 'activo' => 'boolean']);
        $w = $id ? Webhook::findOrFail($id) : new Webhook(['business_id' => $request->user()->business_id, 'secreto' => 'whsec_' . Str::random(32)]);
        $w->fill($d + ['activo' => $d['activo'] ?? true, 'fallos' => 0])->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $w, "Webhook {$w->nombre}");
        return back()->with('success', 'Webhook guardado.');
    }

    public function borrarWebhook(int $id)
    {
        Webhook::findOrFail($id)->delete();
        return back()->with('success', 'Webhook eliminado.');
    }

    public function probarWebhook(int $id, WebhookService $svc)
    {
        $w = Webhook::findOrFail($id);
        $e = $svc->enviar($w, 'prueba', ['mensaje' => 'Hola desde BigSysWeb', 'webhook' => $w->nombre]);
        return back()->with($e->status && $e->status < 400 ? 'success' : 'error', $e->status ? "Respuesta HTTP {$e->status} en {$e->ms} ms." : 'No se pudo conectar: ' . $e->respuesta);
    }
}
