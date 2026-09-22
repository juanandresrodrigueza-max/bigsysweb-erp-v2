<?php

namespace App\Http\Controllers\Fondos;

use App\Http\Controllers\Controller;
use App\Models\Cheque;
use App\Models\CuentaFondos;
use App\Services\Fondos\ChequeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChequesController extends Controller
{
    public function __construct(private ChequeService $service) {}

    public function index(Request $request)
    {
        $tipo = $request->input('tipo', 'tercero');
        $q = Cheque::with(['contact:id,name', 'cuenta:id,nombre'])->where('tipo', $tipo)
            ->when($request->estado, fn($q, $e) => $q->where('estado', $e), fn($q) => $q->whereIn('estado', $tipo === 'tercero' ? ['cartera', 'depositado', 'rechazado'] : ['entregado', 'rechazado']))
            ->orderBy('fecha_pago');
        $lista = $q->get()->map(fn($c) => ['id' => $c->id, 'numero' => $c->numero, 'banco' => $c->banco, 'emisor' => $c->emisor, 'contacto' => $c->contact?->name, 'fecha_emision' => $c->fecha_emision->format('d/m/Y'), 'fecha_pago' => $c->fecha_pago->format('d/m/Y'), 'dias' => today()->diffInDays($c->fecha_pago, false), 'monto' => (float) $c->monto, 'estado' => $c->estado, 'echeq' => $c->echeq, 'cuenta' => $c->cuenta?->nombre, 'cobro_id' => $c->cobro_id, 'pago_id' => $c->pago_id]);

        return Inertia::render('Fondos/Cheques', [
            'lista' => $lista, 'tipo' => $tipo, 'filtros' => $request->only('estado'), 'estados' => Cheque::ESTADOS,
            'totales' => ['cartera' => (float) Cheque::enCartera()->sum('monto'), 'depositados' => (float) Cheque::where('tipo', 'tercero')->where('estado', 'depositado')->sum('monto'), 'propios' => (float) Cheque::propiosPendientes()->sum('monto'), 'rechazados' => (float) Cheque::where('estado', 'rechazado')->sum('monto')],
            'bancos' => CuentaFondos::where('activa', true)->where('tipo', 'banco')->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function depositar(Request $request, int $id)
    {
        $request->validate(['cuenta_fondos_id' => 'required|integer']);
        $this->service->depositar(Cheque::findOrFail($id), CuentaFondos::findOrFail($request->cuenta_fondos_id));
        return back()->with('success', 'Cheque depositado.');
    }

    public function rechazar(Request $request, int $id)
    {
        $request->validate(['motivo' => 'required|string|max:255']);
        $this->service->rechazar(Cheque::findOrFail($id), $request->motivo);
        return back()->with('success', 'Cheque marcado como rechazado.');
    }

    public function cobrado(int $id)
    {
        $this->service->marcarCobrado(Cheque::findOrFail($id));
        return back()->with('success', 'Cheque acreditado.');
    }

    public function debitar(int $id)
    {
        $this->service->debitarPropio(Cheque::findOrFail($id));
        return back()->with('success', 'Cheque propio debitado del banco.');
    }
}
