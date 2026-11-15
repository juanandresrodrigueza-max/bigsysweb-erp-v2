<?php

namespace App\Http\Controllers;

use App\Models\Propuesta;
use App\Services\Agentes\AgentesService;
use App\Services\Agentes\ResumenHabladoService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 107: lo que Bigcito dejó de noche. Se aprueba o se rechaza de a un toque; aprobar ejecuta la acción con los servicios de siempre.
class PropuestasController extends Controller
{
    public function __construct(private AgentesService $svc) {}

    public function index(Request $r)
    {
        $b = $r->user()->business;
        return Inertia::render('Propuestas/Index', [
            'pendientes' => $this->svc->pendientes($b)->map(fn($p) => $this->fila($p)),
            'historial' => Propuesta::where('estado', '!=', 'pendiente')->with('resueltaPor:id,name')->latest('resuelta_en')->limit(30)->get()->map(fn($p) => $this->fila($p) + ['resuelta' => $p->resuelta_en?->format('d/m H:i'), 'por' => $p->resueltaPor?->name, 'resultado' => $p->resultado, 'motivo' => $p->motivo]),
            'agentes' => collect(AgentesService::AGENTES)->map(fn($a, $k) => ['key' => $k, 'label' => $a['label'], 'que' => $a['que']])->values(),
            'config' => $this->svc->config($b), 'puedeResolver' => $r->user()->esDueno() || $r->user()->puede('configuracion', 'editar'),
        ]);
    }

    private function fila(Propuesta $p): array
    {
        return ['id' => $p->id, 'agente' => $p->agente, 'agente_label' => AgentesService::AGENTES[$p->agente]['label'] ?? $p->agente, 'titulo' => $p->titulo, 'detalle' => $p->detalle, 'datos' => $p->datos, 'solo_aviso' => $p->soloAviso(),
            'importe' => $p->importe !== null ? (float) $p->importe : null, 'prioridad' => $p->prioridad, 'estado' => $p->estado, 'hace' => $p->created_at->diffForHumans()];
    }

    public function resolver(Request $r, int $id)
    {
        $d = $r->validate(['aprobar' => 'required|boolean', 'motivo' => 'nullable|string|max:200']);
        $p = $this->svc->resolver(Propuesta::findOrFail($id), $r->user(), (bool) $d['aprobar'], $d['motivo'] ?? null);
        return back()->with($d['aprobar'] ? 'success' : 'info', $d['aprobar'] ? ($p->resultado ?: 'Hecho.') : 'Propuesta rechazada. Bigcito no insiste con lo mismo por una semana.');
    }

    public function correr(Request $r)
    {
        $d = $r->validate(['agente' => 'nullable|string|in:' . implode(',', array_keys(AgentesService::AGENTES))]);
        $b = $r->user()->business;
        $res = ! empty($d['agente']) && $d['agente'] === 'cierre' ? ['cierre' => $this->correrCierre($b)] : $this->svc->correr($b, ! empty($d['agente']) ? [$d['agente']] : null, true);
        $n = array_sum($res);
        return back()->with('success', $n ? "Listo: {$n} propuesta(s) nueva(s)." : 'Revisé todo y no hay nada nuevo para proponer.');
    }

    // A pedido, el cierre corre aunque no sean los primeros días del mes.
    private function correrCierre($b): int
    {
        $dueno = $b->owner; $prev = auth()->user(); if ($dueno) auth()->setUser($dueno);
        try { $props = app(\App\Services\Agentes\CierreAgente::class)->proponer($b, true); } finally { if ($prev) auth()->setUser($prev); }
        $m = new \ReflectionMethod($this->svc, 'guardar'); $m->setAccessible(true);
        return (int) $m->invoke($this->svc, $b, 'cierre', $props);
    }

    public function configurar(Request $r)
    {
        abort_unless($r->user()->esDueno(), 403, 'Los agentes los configura el dueño.');
        $d = $r->validate(['activos' => 'present|array', 'activos.*' => 'string', 'push' => 'boolean']);
        $this->svc->guardarConfig($r->user()->business, $d);
        return back()->with('success', 'Listo. Bigcito trabaja con eso desde esta noche.');
    }

    public function resumenHablado(Request $r, ResumenHabladoService $svc)
    {
        $t = $svc->texto($r->user()->business); $t['texto'] = str_replace('*', '', $t['texto']);
        return Inertia::render('Propuestas/ResumenHablado', $t + ['empresa' => $r->user()->business->name]);
    }
}
