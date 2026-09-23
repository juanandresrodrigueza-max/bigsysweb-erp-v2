<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Services\Fondos\CashFlowService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Cash flow proyectado a 13 semanas.
class CashFlowController extends Controller
{
    public function index(Request $request, CashFlowService $svc)
    {
        $p = $svc->proyectar($request->user()->business, (int) ($request->semanas ?: 13));
        if ($request->export) {
            $csv = "Concepto;" . implode(';', array_map(fn($c) => $c['desde'] . '-' . $c['hasta'], $p['columnas'])) . ";Total\n";
            foreach ($p['filas'] as $f) $csv .= $f['label'] . ';' . implode(';', array_map(fn($v) => number_format($v * ($f['tipo'] === 'out' ? -1 : 1), 2, ',', ''), $f['semanas'])) . ';' . number_format($f['total'] * ($f['tipo'] === 'out' ? -1 : 1), 2, ',', '') . "\n";
            $csv .= "Saldo proyectado;" . implode(';', array_map(fn($c) => number_format($c['saldo'], 2, ',', ''), $p['columnas'])) . ";\n";
            return response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=cashflow_' . today()->toDateString() . '.csv']);
        }
        return Inertia::render('Contable/CashFlow', ['p' => $p]);
    }
}
