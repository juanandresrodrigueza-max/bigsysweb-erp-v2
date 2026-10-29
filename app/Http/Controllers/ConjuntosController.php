<?php

namespace App\Http\Controllers;

use App\Models\ConjuntoEmpresas;
use App\Services\Empresas\GrupoService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 39.2: conjuntos de empresas con nombre, mirados juntos.
class ConjuntosController extends Controller
{
    public function __construct(private GrupoService $svc) {}

    public function ver(Request $request, int $id)
    {
        $c = ConjuntoEmpresas::where('user_id', $request->user()->id)->findOrFail($id);
        $mes = $request->mes ?: now()->format('Y-m');
        return Inertia::render('Empresas/Conjunto', $this->svc->tablero($request->user(), $c, $mes) + ['empresasAcc' => $request->user()->empresasAccesibles()->map(fn($b) => ['id' => $b->id, 'nombre' => $b->name])->values(), 'elegidas' => $c->empresas]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:80', 'empresas' => 'required|array', 'empresas.*' => 'integer']);
        $c = $this->svc->guardarConjunto($request->user(), $id, $d['nombre'], $d['empresas']);
        return redirect("/conjuntos/{$c->id}")->with('success', "Conjunto «{$c->nombre}» guardado.");
    }

    public function borrar(Request $request, int $id)
    {
        ConjuntoEmpresas::where('user_id', $request->user()->id)->findOrFail($id)->delete();
        return redirect('/contador/empresas')->with('success', 'Conjunto borrado.');
    }
}
