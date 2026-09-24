<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ErrorSistema;
use App\Models\SistemaConfig;
use App\Services\Monitoreo\ErrorReporter;
use App\Services\Monitoreo\SaludService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Panel Salud del superadmin: controles del servidor, errores agrupados y monitoreo externo (Sentry o compatible).
class SaludController extends Controller
{
    public function index(SaludService $svc)
    {
        $errores = ErrorSistema::orderByDesc('ultima_vez')->limit(100)->get()->map(fn($e) => ['id' => $e->id, 'clase' => class_basename($e->clase), 'mensaje' => \Illuminate\Support\Str::limit($e->mensaje, 200), 'archivo' => $e->archivo, 'linea' => $e->linea, 'ruta' => $e->ruta,
            'empresa' => $e->business_id, 'veces' => $e->veces, 'primera' => $e->primera_vez?->format('d/m H:i'), 'ultima' => $e->ultima_vez?->format('d/m H:i'), 'resuelto' => (bool) $e->resuelto_en, 'sentry' => $e->enviado_sentry, 'traza' => $e->traza]);
        return Inertia::render('Superadmin/Salud', [
            'salud' => $svc->verificar(), 'errores' => $errores,
            'monitoreo' => ['dsn_set' => (bool) ErrorReporter::dsn(), 'dsn_panel' => (bool) SistemaConfig::get('sentry_dsn'), 'email' => SistemaConfig::get('monitoreo_email') ?: SistemaConfig::get('soporte_email'), 'salud_token' => SistemaConfig::get('salud_token'), 'url_publica' => url('/salud')],
        ]);
    }

    public function guardar(Request $request)
    {
        $d = $request->validate(['sentry_dsn' => 'nullable|string|max:300', 'monitoreo_email' => 'nullable|email', 'salud_token' => 'nullable|string|max:80']);
        if (! empty($d['sentry_dsn']) && ! ErrorReporter::dsn() && ! (parse_url($d['sentry_dsn'])['user'] ?? null)) return back()->withErrors(['sentry_dsn' => 'El DSN tiene que ser como https://clave@sentry.io/123456.']);
        foreach ($d as $k => $v) { if ($k === 'sentry_dsn' && ! $v) continue; SistemaConfig::set($k, $v ?: null); }
        AuditLog::registrar('editar', null, 'Configuró el monitoreo de errores y salud');
        return back()->with('success', 'Monitoreo guardado.');
    }

    public function borrarDsn()
    {
        SistemaConfig::set('sentry_dsn', null);
        return back()->with('success', 'DSN borrado: los errores quedan solo en este panel.');
    }

    // Manda un evento de prueba al servicio de monitoreo.
    public function probar(ErrorReporter $rep)
    {
        if (! ErrorReporter::dsn()) return back()->with('error', 'Cargá primero el DSN.');
        $ok = $rep->enviarSentry(new \RuntimeException('Evento de prueba de BigSysWeb'), 'Evento de prueba desde el panel de BigSys · ' . now()->format('d/m/Y H:i'));
        return back()->with($ok ? 'success' : 'error', $ok ? 'Evento de prueba enviado: tiene que aparecer en tu proyecto de Sentry en segundos.' : 'El servicio no aceptó el evento. Revisá el DSN y la red del servidor.');
    }

    public function resolver(Request $request, int $id)
    {
        ErrorSistema::findOrFail($id)->update(['resuelto_en' => now()]);
        return back()->with('success', 'Error marcado como resuelto.');
    }

    // Endpoint para servicios de uptime (UptimeRobot, Better Stack, cron externo): sin token solo el estado; con token, el detalle.
    public function publico(Request $request, SaludService $svc)
    {
        $r = $svc->verificar();
        $token = SistemaConfig::get('salud_token');
        $detalle = $token && hash_equals((string) $token, (string) $request->query('token', $request->bearerToken() ?? ''));
        $body = $detalle ? $r : ['estado' => $r['estado'], 'hora' => $r['hora']];
        return response()->json($body, $r['estado'] === 'critico' ? 503 : 200);
    }
}
