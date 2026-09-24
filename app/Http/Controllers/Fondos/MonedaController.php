<?php

namespace App\Http\Controllers\Fondos;

use App\Http\Controllers\Controller;
use App\Services\Fondos\CotizacionService;
use App\Services\Fondos\MonedaService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Posición en moneda extranjera: cotizaciones, cuentas en dólares, por cobrar y pagar en dólares, revaluación.
class MonedaController extends Controller
{
    public function index(Request $request, MonedaService $svc)
    {
        $b = $request->user()->business;
        return Inertia::render('Fondos/Moneda', ['cotizaciones' => $svc->cotizaciones($b), 'posicion' => $svc->posicion($b)]);
    }

    public function fijar(Request $request, CotizacionService $svc)
    {
        $d = $request->validate(['venta' => 'required|numeric|gt:0', 'compra' => 'nullable|numeric|gt:0', 'tipo' => 'nullable|in:oficial,blue,mep,tarjeta']);
        $svc->fijarManual($request->user()->business_id, (float) $d['venta'], $d['compra'] ?? null, $d['tipo'] ?? 'oficial');
        return back()->with('success', 'Cotización fijada en $ ' . number_format((float) $d['venta'], 2, ',', '.') . '.');
    }

    public function actualizar(CotizacionService $svc)
    {
        $r = $svc->actualizar();
        return isset($r['error']) ? back()->with('error', 'No se pudo bajar la cotización: ' . $r['error']) : back()->with('success', 'Cotizaciones actualizadas: oficial $ ' . number_format($r['oficial'] ?? 0, 2, ',', '.') . '.');
    }

    public function revaluar(Request $request, MonedaService $svc)
    {
        $d = $request->validate(['cotizacion' => 'required|numeric|gt:0', 'fecha' => 'nullable|date']);
        $r = $svc->revaluar($request->user()->business, (float) $d['cotizacion'], $d['fecha'] ?? null);
        return back()->with('success', $r['n'] ? "Tenencia revaluada: diferencia de cambio $ " . number_format($r['total'], 2, ',', '.') . " en {$r['n']} cuentas." : 'Valuación registrada. La próxima revaluación generará la diferencia de cambio.');
    }
}
