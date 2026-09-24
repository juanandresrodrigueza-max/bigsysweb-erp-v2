<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Models\ExtractoBancario;
use App\Models\ExtractoItem;
use App\Models\MovimientoFondos;
use App\Services\Contabilidad\ConciliacionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConciliacionController extends Controller
{
    public function index(Request $request)
    {
        $bancos = CuentaFondos::whereIn('tipo', ['banco', 'billetera'])->where('activa', true)->orderBy('nombre')->get(['id', 'nombre', 'tipo', 'saldo', 'banco']);
        $cuentaId = (int) ($request->cuenta ?: $bancos->first()?->id);
        $estado = $request->estado ?? 'pendiente';
        $items = ExtractoItem::with('movimiento:id,fecha,concepto,ingreso,egreso,referencia')->where('cuenta_fondos_id', $cuentaId)
            ->when($estado !== 'todos', fn($q) => $q->where('estado', $estado))->orderByDesc('fecha')->orderByDesc('id')->limit(300)->get()
            ->map(fn($i) => ['id' => $i->id, 'fecha' => $i->fecha->format('d/m/Y'), 'descripcion' => $i->descripcion, 'referencia' => $i->referencia, 'monto' => (float) $i->monto, 'saldo' => $i->saldo !== null ? (float) $i->saldo : null, 'estado' => $i->estado, 'match' => $i->match, 'movimiento' => $i->movimiento ? ['id' => $i->movimiento->id, 'fecha' => $i->movimiento->fecha->format('d/m/Y'), 'concepto' => $i->movimiento->concepto] : null]);
        $sinConciliar = MovimientoFondos::where('cuenta_fondos_id', $cuentaId)->where('conciliado', false)->orderByDesc('fecha')->orderByDesc('id')->limit(200)->get()
            ->map(fn($m) => ['id' => $m->id, 'fecha' => $m->fecha->format('d/m/Y'), 'concepto' => $m->concepto, 'referencia' => $m->referencia, 'monto' => (float) $m->ingreso - (float) $m->egreso, 'origen' => $m->origen]);
        $cuenta = $bancos->firstWhere('id', $cuentaId);
        $ultimoExt = ExtractoBancario::where('cuenta_fondos_id', $cuentaId)->latest('id')->first();

        return Inertia::render('Contable/Conciliacion', [
            'bancos' => $bancos, 'cuentaId' => $cuentaId, 'estado' => $estado, 'items' => $items, 'sinConciliar' => $sinConciliar,
            'resumen' => [
                'saldo_sistema' => (float) ($cuenta?->saldo ?? 0), 'saldo_extracto' => $ultimoExt?->saldo_final !== null ? (float) $ultimoExt->saldo_final : null,
                'pendientes_extracto' => ExtractoItem::where('cuenta_fondos_id', $cuentaId)->where('estado', 'pendiente')->count(), 'pendientes_sistema' => $sinConciliar->count(),
                'monto_pend_extracto' => (float) ExtractoItem::where('cuenta_fondos_id', $cuentaId)->where('estado', 'pendiente')->sum('monto'), 'monto_pend_sistema' => round($sinConciliar->sum('monto'), 2),
                'ultimo_extracto' => $ultimoExt ? ['fecha' => $ultimoExt->created_at->format('d/m/Y'), 'desde' => $ultimoExt->desde?->format('d/m'), 'hasta' => $ultimoExt->hasta?->format('d/m'), 'items' => $ultimoExt->items, 'archivo' => $ultimoExt->archivo] : null,
            ],
            'categorias' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function importar(Request $request, ConciliacionService $svc)
    {
        $d = $request->validate(['cuenta_fondos_id' => 'required|exists:cuentas_fondos,id', 'archivo' => 'required|file|max:15360|mimes:pdf,xlsx,xls,csv,txt']);
        $ext = $svc->importarArchivo(CuentaFondos::findOrFail($d['cuenta_fondos_id']), $request->file('archivo')->getRealPath(), $request->file('archivo')->getClientOriginalName());
        $conc = ExtractoItem::where('extracto_id', $ext->id)->where('estado', 'conciliado')->count();
        return back()->with('success', "Importados {$ext->items} renglones; {$conc} conciliados automáticamente.");
    }

    public function automatica(Request $request, ConciliacionService $svc)
    {
        $n = $svc->conciliarAutomatico(CuentaFondos::findOrFail($request->integer('cuenta')));
        return back()->with('success', "{$n} renglones conciliados.");
    }

    public function vincular(Request $request, int $id, ConciliacionService $svc)
    {
        $d = $request->validate(['movimiento_id' => 'required|exists:movimientos_fondos,id']);
        $svc->vincular(ExtractoItem::findOrFail($id), MovimientoFondos::findOrFail($d['movimiento_id']));
        return back()->with('success', 'Conciliado.');
    }

    public function desvincular(int $id, ConciliacionService $svc)
    {
        $svc->desvincular(ExtractoItem::findOrFail($id));
        return back()->with('success', 'Se deshizo la conciliación.');
    }

    public function ignorar(int $id)
    {
        $it = ExtractoItem::findOrFail($id);
        $it->update(['estado' => $it->estado === 'ignorado' ? 'pendiente' : 'ignorado']);
        return back();
    }

    public function registrar(Request $request, int $id, ConciliacionService $svc)
    {
        $d = $request->validate(['expense_category_id' => 'nullable|exists:expense_categories,id', 'concepto' => 'nullable|string|max:150']);
        $m = $svc->registrarDesdeExtracto(ExtractoItem::findOrFail($id), $d['expense_category_id'] ?? null, $d['concepto'] ?? null);
        return back()->with('success', "Registrado en Fondos: {$m->concepto}.");
    }
}
