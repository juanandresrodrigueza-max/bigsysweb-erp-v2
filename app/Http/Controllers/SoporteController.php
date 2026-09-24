<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\SistemaConfig;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Soporte: la empresa abre tickets y el equipo de BigSys (superadmin) los responde desde /admin/soporte.
class SoporteController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Soporte', [
            'tickets' => Ticket::with('user:id,name')->latest('ultimo_mensaje_en')->get()->map(fn($t) => $this->fila($t)),
            'categorias' => Ticket::CATEGORIAS, 'prioridades' => Ticket::PRIORIDADES,
            'contacto' => ['whatsapp' => SistemaConfig::get('soporte_whatsapp'), 'email' => SistemaConfig::get('soporte_email')],
            'abrirId' => (int) $request->abrir ?: null,
        ]);
    }

    private function fila(Ticket $t): array
    {
        return ['id' => $t->id, 'numero' => $t->numero(), 'asunto' => $t->asunto, 'categoria' => Ticket::CATEGORIAS[$t->categoria] ?? $t->categoria, 'prioridad' => $t->prioridad, 'estado' => $t->estado, 'usuario' => $t->user?->name, 'empresa' => $t->relationLoaded('business') ? $t->business?->name : null, 'business_id' => $t->business_id, 'creado' => $t->created_at->format('d/m/Y H:i'), 'ultimo' => $t->ultimo_mensaje_en?->diffForHumans(), 'mensajes' => collect($t->mensajes)->map(fn($m) => $m + ['fecha_f' => \Carbon\Carbon::parse($m['fecha'])->format('d/m/Y H:i')])->all()];
    }

    public function crear(Request $request)
    {
        $d = $request->validate(['asunto' => 'required|string|max:150', 'categoria' => 'required|in:' . implode(',', array_keys(Ticket::CATEGORIAS)), 'prioridad' => 'required|in:' . implode(',', array_keys(Ticket::PRIORIDADES)), 'mensaje' => 'required|string|max:4000']);
        $t = Ticket::create(['business_id' => $request->user()->business_id, 'user_id' => $request->user()->id, 'asunto' => $d['asunto'], 'categoria' => $d['categoria'], 'prioridad' => $d['prioridad'], 'estado' => 'abierto', 'mensajes' => []]);
        $t->agregar('empresa', $request->user(), $d['mensaje']);
        AuditLog::registrar('crear', $t, "Abrió el ticket {$t->numero()}: {$t->asunto}");
        return back()->with('success', "Ticket {$t->numero()} enviado. Te respondemos acá y por mail.");
    }

    public function responder(Request $request, int $id)
    {
        $d = $request->validate(['mensaje' => 'required|string|max:4000']);
        $t = Ticket::findOrFail($id);
        $t->agregar('empresa', $request->user(), $d['mensaje']);
        return back()->with('success', 'Respuesta enviada.');
    }

    public function cerrar(Request $request, int $id)
    {
        $t = Ticket::findOrFail($id);
        $t->update(['estado' => 'cerrado', 'cerrado_en' => now()]);
        return back()->with('success', "Ticket {$t->numero()} cerrado.");
    }

    // --- Superadmin ---
    public function admin(Request $request)
    {
        $q = Ticket::withoutGlobalScopes()->with(['user:id,name', 'business:id,name'])->when($request->estado, fn($q, $e) => $q->where('estado', $e), fn($q) => $q->where('estado', '!=', 'cerrado'))->orderByRaw("CASE prioridad WHEN 'alta' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")->latest('ultimo_mensaje_en');
        return Inertia::render('Superadmin/Soporte', [
            'tickets' => $q->get()->map(fn($t) => $this->fila($t)), 'filtros' => $request->only('estado'),
            'kpis' => ['abiertos' => Ticket::withoutGlobalScopes()->where('estado', 'abierto')->count(), 'respondidos' => Ticket::withoutGlobalScopes()->where('estado', 'respondido')->count(), 'cerrados_mes' => Ticket::withoutGlobalScopes()->where('estado', 'cerrado')->where('cerrado_en', '>=', now()->startOfMonth())->count()],
            'abrirId' => (int) $request->abrir ?: null,
        ]);
    }

    public function adminResponder(Request $request, int $id)
    {
        $d = $request->validate(['mensaje' => 'required|string|max:4000', 'cerrar' => 'boolean']);
        $t = Ticket::withoutGlobalScopes()->findOrFail($id);
        $t->agregar('soporte', $request->user(), $d['mensaje']);
        if ($d['cerrar'] ?? false) $t->update(['estado' => 'cerrado', 'cerrado_en' => now()]);
        Alerta::emitir(['business_id' => $t->business_id, 'modulo' => 'configuracion', 'tipo' => 'ticket_respondido', 'severidad' => 'info', 'titulo' => "Soporte respondió el ticket {$t->numero()}", 'detalle' => mb_substr($d['mensaje'], 0, 140), 'url' => "/soporte?abrir={$t->id}"]);
        return back()->with('success', 'Respuesta enviada a la empresa.');
    }
}
