<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AlertasController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $q = Alerta::visiblesPara($user)
            ->when($request->input('estado', 'activas') === 'activas', fn($q) => $q->activas())
            ->when($request->input('estado') === 'resueltas', fn($q) => $q->whereNotNull('resuelta_en'))
            ->when($request->modulo, fn($q, $m) => $q->where('modulo', $m))
            ->when($request->severidad, fn($q, $s) => $q->where('severidad', $s))
            ->orderByRaw("CASE severidad WHEN 'critica' THEN 0 WHEN 'aviso' THEN 1 ELSE 2 END")
            ->latest();

        $listado = $q->paginate(30)->withQueryString()->through(fn($a) => [
            'id' => $a->id, 'modulo' => $a->modulo, 'tipo' => $a->tipo, 'severidad' => $a->severidad,
            'titulo' => $a->titulo, 'detalle' => $a->detalle, 'url' => $a->url,
            'leida' => in_array($user->id, $a->leida_por ?? [], true),
            'resuelta' => (bool) $a->resuelta_en, 'hace' => $a->created_at->diffForHumans(),
        ]);

        $modulos = collect(config('erp.modulos'))->only($user->modulosVisibles())->map(fn($m, $k) => ['key' => $k, 'label' => $m['label']])->values();

        return Inertia::render('Alertas/Index', [
            'listado' => $listado,
            'modulos' => $modulos,
            'filtros' => $request->only('estado', 'modulo', 'severidad'),
        ]);
    }

    public function leer(Request $request, int $id)
    {
        Alerta::visiblesPara($request->user())->findOrFail($id)->marcarLeida($request->user()->id);
        return back();
    }

    public function leerTodas(Request $request)
    {
        Alerta::visiblesPara($request->user())->noLeidasPor($request->user()->id)->each(fn($a) => $a->marcarLeida($request->user()->id));
        return back()->with('success', 'Todas las alertas quedaron marcadas como leídas.');
    }

    public function resolver(Request $request, int $id)
    {
        $alerta = Alerta::visiblesPara($request->user())->findOrFail($id);
        $alerta->update(['resuelta_en' => now()]);
        return back()->with('success', 'Alerta resuelta.');
    }
}
