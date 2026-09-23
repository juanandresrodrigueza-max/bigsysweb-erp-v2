<?php

namespace App\Http\Controllers\Fondos;

use App\Http\Controllers\Controller;
use App\Models\CuentaFondos;
use App\Models\CuponTarjeta;
use App\Models\LiquidacionTarjeta;
use App\Services\Fondos\TarjetasService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TarjetasController extends Controller
{
    public function __construct(private TarjetasService $service) {}

    public function index(Request $request)
    {
        $cupones = CuponTarjeta::with(['contact:id,name', 'cobro:id,numero'])->when($request->estado, fn($q, $e) => $q->where('estado', $e), fn($q) => $q->where('estado', 'cartera'))->orderBy('fecha')->get()
            ->map(fn($c) => ['id' => $c->id, 'fecha' => $c->fecha->format('d/m/Y'), 'tarjeta' => $c->tarjeta, 'numero' => $c->numero, 'lote' => $c->lote, 'cuotas' => $c->cuotas, 'monto' => (float) $c->monto, 'estado' => $c->estado, 'cliente' => $c->contact?->name, 'recibo' => $c->cobro?->numeroFormateado(), 'cobro_id' => $c->cobro_id, 'dias' => $c->fecha->diffInDays(today())]);
        return Inertia::render('Fondos/Tarjetas', [
            'cupones' => $cupones, 'filtros' => $request->only('estado'), 'tarjetas' => CuponTarjeta::TARJETAS,
            'totales' => ['cartera' => (float) CuponTarjeta::enCartera()->sum('monto'), 'cartera_n' => CuponTarjeta::enCartera()->count(), 'liquidado_mes' => (float) LiquidacionTarjeta::where('estado', 'registrada')->whereMonth('fecha', today()->month)->whereYear('fecha', today()->year)->sum('neto'), 'costo_mes' => (float) LiquidacionTarjeta::where('estado', 'registrada')->whereMonth('fecha', today()->month)->whereYear('fecha', today()->year)->selectRaw('COALESCE(SUM(bruto - neto),0) as c')->value('c')],
            'liquidaciones' => LiquidacionTarjeta::with('cuenta:id,nombre')->withCount('cupones')->orderByDesc('fecha')->orderByDesc('id')->limit(20)->get()->map(fn($l) => ['id' => $l->id, 'codigo' => $l->numeroFormateado(), 'numero' => $l->numero, 'fecha' => $l->fecha->format('d/m/Y'), 'tarjeta' => $l->tarjeta, 'banco' => $l->cuenta?->nombre, 'bruto' => (float) $l->bruto, 'neto' => (float) $l->neto, 'descuentos' => round((float) $l->bruto - (float) $l->neto, 2), 'cupones' => $l->cupones_count, 'estado' => $l->estado]),
            'bancos' => CuentaFondos::where('activa', true)->whereIn('tipo', ['banco', 'billetera'])->orderBy('nombre')->get(['id', 'nombre', 'tipo']),
        ]);
    }

    public function liquidar(Request $request)
    {
        $d = $request->validate(['cupones' => 'required|array|min:1', 'cupones.*' => 'integer', 'cuenta_fondos_id' => 'required|exists:cuentas_fondos,id', 'fecha' => 'required|date', 'numero' => 'nullable|string|max:40', 'tarjeta' => 'nullable|string|max:30', 'comision' => 'nullable|numeric|min:0', 'iva_comision' => 'nullable|numeric|min:0', 'ret_iva' => 'nullable|numeric|min:0', 'ret_iibb' => 'nullable|numeric|min:0', 'ret_ganancias' => 'nullable|numeric|min:0', 'otros' => 'nullable|numeric|min:0', 'notas' => 'nullable|string|max:500']);
        $l = $this->service->liquidar($d);
        return back()->with('success', "Liquidación {$l->numeroFormateado()} registrada: neto $ " . number_format((float) $l->neto, 2, ',', '.') . ' acreditado.');
    }

    public function anular(Request $request, int $id)
    {
        $request->validate(['motivo' => 'required|string|max:255']);
        $this->service->anular(LiquidacionTarjeta::findOrFail($id), $request->motivo);
        return back()->with('success', 'Liquidación anulada; los cupones volvieron a cartera.');
    }

    public function rechazar(Request $request, int $id)
    {
        $request->validate(['motivo' => 'required|string|max:255']);
        $this->service->rechazar(CuponTarjeta::findOrFail($id), $request->motivo);
        return back()->with('success', 'Cupón marcado como rechazado.');
    }
}
