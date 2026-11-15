<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Services\Contabilidad\IndicadoresContadorService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 99: Contable → Indicadores: los números que el contador mira antes de hablar con el dueño.
class IndicadoresController extends Controller
{
    public function index(Request $request, IndicadoresContadorService $svc)
    {
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->mes) ? $request->mes : now()->format('Y-m');
        return Inertia::render('Contable/Indicadores', ['tablero' => $svc->tablero($request->user()->business, $mes)]);
    }
}
