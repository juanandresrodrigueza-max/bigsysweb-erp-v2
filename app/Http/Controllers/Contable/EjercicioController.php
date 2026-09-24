<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\Asiento;
use App\Models\Ejercicio;
use App\Models\IndiceIpc;
use App\Services\Contabilidad\EjercicioService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Cierre de ejercicio, ajuste por inflación y libro diario para imprimir.
class EjercicioController extends Controller
{
    public function index(Request $request, EjercicioService $svc)
    {
        $b = $request->user()->business;
        $act = $svc->actual($b);
        // El ajuste se propone al último mes con IPC cargado (normalmente el mes pasado); el cierre, al fin del ejercicio.
        $ultimoIpc = IndiceIpc::orderByDesc('periodo')->value('periodo');
        $cierre = $ultimoIpc && $ultimoIpc <= $act['hasta']->format('Y-m') ? $ultimoIpc : $act['hasta']->format('Y-m');
        return Inertia::render('Contable/Ejercicio', [
            'actual' => ['desde' => $act['desde']->toDateString(), 'hasta' => $act['hasta']->toDateString(), 'asientos' => Asiento::where('estado', 'confirmado')->whereBetween('fecha', [$act['desde']->toDateString(), $act['hasta']->toDateString()])->count()],
            'cierreMes' => (int) ($b->cierre_ejercicio_mes ?: 12),
            'ejercicios' => Ejercicio::orderByDesc('hasta')->get()->map(fn($e) => ['id' => $e->id, 'desde' => $e->desde->format('d/m/Y'), 'hasta' => $e->hasta->format('d/m/Y'), 'estado' => $e->estado, 'cerrado_en' => $e->cerrado_en?->format('d/m/Y H:i'), 'resultado' => (float) ($e->asientos['resultado'] ?? 0), 'asientos' => $e->asientos]),
            'ipcCierre' => IndiceIpc::valor($cierre), 'periodoCierre' => $cierre, 'ajusteDefault' => \Carbon\Carbon::parse($cierre . '-01')->endOfMonth()->toDateString(),
            'ajustes' => Asiento::where('origen', 'ajuste_inflacion')->orderByDesc('fecha')->limit(5)->get()->map(fn($a) => ['id' => $a->id, 'fecha' => $a->fecha->format('d/m/Y'), 'concepto' => $a->concepto, 'total' => (float) $a->total, 'estado' => $a->estado]),
        ]);
    }

    public function cerrar(Request $request, EjercicioService $svc)
    {
        $d = $request->validate(['hasta' => 'required|date']);
        $e = $svc->cerrar($request->user()->business, $d['hasta']);
        return back()->with('success', 'Ejercicio cerrado al ' . $e->hasta->format('d/m/Y') . '. Resultado: $ ' . number_format((float) $e->asientos['resultado'], 2, ',', '.'));
    }

    public function reabrir(int $id, EjercicioService $svc)
    {
        $svc->reabrir(Ejercicio::findOrFail($id));
        return back()->with('success', 'Ejercicio reabierto: sus asientos de cierre quedaron anulados.');
    }

    public function ajuste(Request $request, EjercicioService $svc)
    {
        $d = $request->validate(['hasta' => 'required|date']);
        $act = $svc->actual($request->user()->business);
        $a = $svc->ajustePorInflacion($request->user()->business, $act['desde']->toDateString(), $d['hasta']);
        return back()->with('success', "Asiento de ajuste por inflación N° {$a->numero} generado por $ " . number_format((float) $a->total, 2, ',', '.') . '.');
    }

    public function diario(Request $request)
    {
        $desde = $request->desde ?: now()->startOfMonth()->toDateString();
        $hasta = $request->hasta ?: now()->endOfMonth()->toDateString();
        $asientos = Asiento::with('lineas.cuenta:id,codigo,nombre')->where('estado', 'confirmado')->whereBetween('fecha', [$desde, $hasta])->orderBy('fecha')->orderBy('numero')->get();
        return view('fiscal.diario', ['asientos' => $asientos, 'desde' => $desde, 'hasta' => $hasta, 'empresa' => $request->user()->business]);
    }
}
