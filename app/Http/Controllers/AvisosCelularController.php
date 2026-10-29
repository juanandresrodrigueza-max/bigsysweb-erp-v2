<?php

namespace App\Http\Controllers;

use App\Models\Novedad;
use App\Models\PushSuscripcion;
use App\Services\Avisos\CelularService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 40.5: avisos al celular, novedades de BigSys, el parte de la mañana y la página de mejoras.
class AvisosCelularController extends Controller
{
    public function __construct(private CelularService $svc) {}

    public function index(Request $r)
    {
        $u = $r->user(); $b = $u->business()->first();
        return Inertia::render('Avisos/Celular', [
            'clave' => $this->svc->vapid()['publicKey'],
            'dispositivos' => PushSuscripcion::where('user_id', $u->id)->latest()->get()->map(fn($s) => ['id' => $s->id, 'dispositivo' => $s->dispositivo ?: 'Navegador', 'desde' => $s->created_at->format('d/m/Y'), 'ultimo' => $s->ultimo_envio?->diffForHumans()]),
            'manana' => $u->esDueno() ? $this->svc->configManana($b) : null, 'parte' => $u->esDueno() ? $this->svc->parte($b)['texto'] : null,
            'whatsapp' => app(\App\Services\Ventas\AvisosDuenoService::class)->config($b)['whatsapp'] ?? null,
        ]);
    }

    public function suscribir(Request $r)
    {
        $d = $r->validate(['endpoint' => 'required|url|max:1000', 'keys.p256dh' => 'required|string|max:200', 'keys.auth' => 'required|string|max:100', 'dispositivo' => 'nullable|string|max:120']);
        $this->svc->suscribir($r->user(), $d, $d['dispositivo'] ?? null);
        return response()->json(['ok' => true]);
    }

    public function desuscribir(Request $r)
    {
        $r->validate(['endpoint' => 'nullable|string|max:1000', 'id' => 'nullable|integer']);
        if ($r->filled('id')) PushSuscripcion::where('user_id', $r->user()->id)->whereKey($r->integer('id'))->delete(); else $this->svc->desuscribir($r->user(), $r->input('endpoint'));
        return $r->wantsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Listo, ese dispositivo ya no recibe avisos.');
    }

    public function probar(Request $r)
    {
        $n = $this->svc->enviar($r->user(), 'Hola desde BigSysWeb', 'Así te van a llegar los avisos.', '/avisos-celular');
        return back()->with($n ? 'success' : 'error', $n ? "Aviso de prueba mandado a {$n} dispositivo/s." : 'No hay ningún dispositivo con los avisos activados.');
    }

    public function manana(Request $r)
    {
        abort_unless($r->user()->esDueno(), 403, 'El parte de la mañana lo configura el dueño.');
        $d = $r->validate(['activo' => 'boolean', 'hora' => ['required', 'regex:/^\d{2}:\d{2}$/'], 'push' => 'boolean', 'whatsapp' => 'boolean']);
        $this->svc->guardarManana($r->user()->business()->first(), $d);
        return back()->with('success', $d['activo'] ?? false ? "Listo: el parte te llega todos los días a las {$d['hora']}." : 'Parte de la mañana apagado.');
    }

    public function novedades(Request $r) { return response()->json(['novedades' => $this->svc->novedades($r->user())]); }

    public function marcar(Request $r, int $id)
    {
        $d = $r->validate(['posponer' => 'nullable|in:7,30']);
        $this->svc->marcar($r->user(), $id, isset($d['posponer']) ? (int) $d['posponer'] : null);
        return response()->json(['ok' => true, 'sin_leer' => $this->svc->sinLeer($r->user())]);
    }

    public function mejoras(Request $r)
    {
        return Inertia::render('Avisos/Mejoras', ['mejoras' => $this->svc->mejoras($r->user()->business()->first()), 'puede' => $r->user()->esDueno() || $r->user()->puede('configuracion', 'editar')]);
    }

    public function meInteresa(Request $r)
    {
        abort_unless($r->user()->esDueno() || $r->user()->puede('configuracion', 'editar'), 403, 'Esto lo pide el dueño o un administrador.');
        $d = $r->validate(['modulo' => 'required|string|max:40', 'comentario' => 'nullable|string|max:500']);
        $this->svc->meInteresa($r->user(), $d['modulo'], $d['comentario'] ?? null);
        return back()->with('success', '¡Gracias! Te escribimos para contarte cómo sumarlo.');
    }

    // Superadmin: publicar una novedad para todos (o para quien tiene un módulo), con aviso al celular opcional.
    public function publicar(Request $r)
    {
        $d = $r->validate(['titulo' => 'required|string|max:150', 'texto' => 'required|string|max:3000', 'url' => 'nullable|string|max:300', 'modulo' => 'nullable|string|max:40', 'push' => 'boolean']);
        $n = Novedad::create(['titulo' => $d['titulo'], 'texto' => $d['texto'], 'url' => $d['url'] ?? null, 'modulo' => $d['modulo'] ?? null, 'publicada_en' => now()]);
        $env = 0;
        if ($d['push'] ?? false) foreach (\App\Models\User::whereIn('id', PushSuscripcion::distinct()->pluck('user_id'))->get() as $u) if (! $n->modulo || $u->business?->tieneModulo($n->modulo)) $env += $this->svc->enviar($u, '✨ ' . $n->titulo, $n->texto, $n->url ?: '/dashboard');
        return back()->with('success', "Novedad publicada" . ($env ? " y mandada a {$env} dispositivos" : '') . '.');
    }
}
