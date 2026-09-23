<?php

namespace App\Http\Controllers\Estadisticas;

use App\Http\Controllers\Controller;
use App\Services\IA\AnalistaService;
use App\Services\Stock\InformesStockService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Analista IA: hallazgos, informe en palabras y compra sugerida inteligente.
class AnalistaController extends Controller
{
    public function index(Request $request, AnalistaService $svc, InformesStockService $stock)
    {
        $b = $request->user()->business;
        $h = $svc->hallazgos($b);
        $compra = $stock->faltantes(30, 90, true);
        return Inertia::render('Estadisticas/Analista', [
            'hallazgos' => $h, 'informe' => $svc->informe($b, $h), 'ia' => (bool) config('services.anthropic.api_key'),
            'resumen' => ['criticas' => count(array_filter($h, fn($x) => $x['sev'] === 'critica')), 'avisos' => count(array_filter($h, fn($x) => $x['sev'] === 'aviso')), 'info' => count(array_filter($h, fn($x) => in_array($x['sev'], ['info', 'ok'], true))), 'impacto' => array_sum(array_column(array_filter($h, fn($x) => $x['sev'] !== 'ok'), 'impacto'))],
            'compra' => ['filas' => array_slice($compra['filas'], 0, 15), 'total' => $compra['total'], 'n' => count($compra['filas'])],
        ]);
    }
}
