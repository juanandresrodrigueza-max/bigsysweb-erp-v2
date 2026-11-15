<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\PosicionIva;
use App\Services\Contabilidad\PosicionIvaService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 99: Contable → Posición IVA: la cuenta del mes con arrastre, para cerrarla y mandársela al contador.
class PosicionIvaController extends Controller
{
    public function __construct(private PosicionIvaService $svc) {}

    public function index(Request $request)
    {
        $b = $request->user()->business;
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->mes) ? $request->mes : now()->subMonth()->format('Y-m');
        $c = $this->svc->calcular($b, $mes);
        return Inertia::render('Contable/PosicionIva', ['posicion' => $c, 'texto' => $this->svc->resumenTexto($b, $c), 'historial' => $this->svc->historial($b, 12)]);
    }

    public function guardar(Request $request)
    {
        $d = $request->validate(['mes' => 'required|date_format:Y-m', 'ajuste' => 'nullable|numeric', 'ajuste_nota' => 'nullable|string|max:200', 'cerrar' => 'boolean']);
        $p = $this->svc->guardar($request->user()->business, $d['mes'], $request->user(), ['ajuste' => $d['ajuste'] ?? 0, 'ajuste_nota' => $d['ajuste_nota'] ?? null], $request->boolean('cerrar'));
        return back()->with('success', $request->boolean('cerrar') ? "Posición de IVA {$p->mes} cerrada: " . ((float) $p->a_pagar > 0 ? 'a pagar $ ' . number_format((float) $p->a_pagar, 2, ',', '.') : 'sin impuesto a pagar') . '.' : 'Posición guardada.');
    }

    public function reabrir(Request $request, int $id)
    {
        $this->svc->reabrir(PosicionIva::findOrFail($id));
        return back()->with('success', 'Posición reabierta.');
    }

    public function texto(Request $request)
    {
        $b = $request->user()->business; $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->mes) ? $request->mes : now()->subMonth()->format('Y-m');
        return response($this->svc->resumenTexto($b, $this->svc->calcular($b, $mes)), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => "attachment; filename=posicion_iva_{$mes}.txt"]);
    }
}
