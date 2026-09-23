<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Lote;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\Stock\InformesStockService;
use App\Services\Stock\LotesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Informes de stock (valorizado, ABC, faltantes, muertos, mínimos, vencimientos), etiquetas con código de barras y verificador de precios.
class InformesController extends Controller
{
    public function index(Request $request, InformesStockService $svc, LotesService $lotes)
    {
        $tipo = in_array($request->tipo, ['valorizado', 'abc', 'faltantes', 'muertos', 'minimos', 'vencimientos'], true) ? $request->tipo : 'valorizado';
        $datos = match ($tipo) {
            'valorizado' => $svc->valorizado($request->base ?: 'cost', $request->rubro ? (int) $request->rubro : null),
            'abc' => $svc->abc((int) ($request->dias ?: 90)),
            'faltantes' => $svc->faltantes((int) ($request->cobertura ?: 30)),
            'muertos' => $svc->muertos((int) ($request->dias ?: 90)),
            'minimos' => $svc->sugerirMinimos((int) ($request->lead ?: 15)),
            'vencimientos' => ['filas' => $lotes->porVencer((int) ($request->dias ?: 30))->map(fn($l) => ['id' => $l->id, 'product_id' => $l->product_id, 'nombre' => $l->product?->name, 'sku' => $l->product?->sku, 'etiqueta' => $l->etiqueta(), 'lote' => $l->lote, 'serie' => $l->serie, 'vencimiento' => $l->vencimiento->format('d/m/Y'), 'vencido' => $l->vencimiento->isPast(), 'dias' => (int) today()->diffInDays($l->vencimiento, false), 'cantidad' => (float) $l->cantidad, 'unit' => $l->product?->unit, 'deposito' => $l->deposito?->nombre, 'valor' => round((float) $l->cantidad * (float) $l->costo_unit, 2)])->all(), 'dias' => (int) ($request->dias ?: 30)],
        };
        if ($request->export) return $this->csv($tipo, $datos['filas']);
        return Inertia::render('Stock/Informes', ['tipo' => $tipo, 'datos' => $datos, 'filtros' => $request->only('base', 'rubro', 'dias', 'cobertura', 'lead'), 'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre'])]);
    }

    private function csv(string $tipo, array $filas)
    {
        if (! $filas) return back()->with('error', 'No hay datos para exportar.');
        $cols = array_keys($filas[0]);
        $csv = implode(';', $cols) . "\n";
        foreach ($filas as $f) $csv .= implode(';', array_map(fn($v) => is_float($v) ? number_format($v, 2, ',', '') : str_replace(';', ',', (string) ($v ?? '')), array_values($f))) . "\n";
        AuditLog::registrar('exportar', null, "Exportó informe de stock {$tipo}");
        return response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=stock_{$tipo}_" . today()->toDateString() . '.csv']);
    }

    public function aplicarMinimos(Request $request, InformesStockService $svc)
    {
        $d = $request->validate(['minimos' => 'required|array']);
        $n = $svc->aplicarMinimos($d['minimos']);
        AuditLog::registrar('editar', null, "Actualizó el stock mínimo de {$n} artículos con la sugerencia del sistema");
        return back()->with('success', "Stock mínimo actualizado en {$n} artículos.");
    }

    public function etiquetas(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->ids)));
        $q = Product::with('rubro:id,nombre')->where('active', true)->when($ids, fn($q) => $q->whereIn('id', $ids))->when($request->rubro, fn($q, $r) => $q->where('rubro_id', $r))->when($request->q, fn($q, $s) => $q->where(fn($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")->orWhere('barcode', 'like', "%{$s}%")))->orderBy('name')->limit(300);
        return Inertia::render('Stock/Etiquetas', [
            'articulos' => $q->get()->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode ?: $p->sku, 'precio' => $p->precioLista(1), 'rubro' => $p->rubro?->nombre, 'unit' => $p->unit]),
            'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre']), 'filtros' => $request->only('rubro', 'q', 'ids'),
            'empresa' => $request->user()->business->only('name'),
        ]);
    }

    public function verificador()
    {
        return Inertia::render('Stock/Verificador');
    }

    public function verificar(Request $request)
    {
        $c = trim((string) $request->codigo);
        if ($c === '') return response()->json(null);
        $p = Product::where('active', true)->where(fn($q) => $q->where('barcode', $c)->orWhere('sku', $c))->first() ?? Product::where('active', true)->where('name', 'like', "%{$c}%")->first();
        if (! $p) return response()->json(null);
        return response()->json(['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'precio' => $p->precioLista(1), 'precios' => collect([1, 2, 3, 4, 5])->mapWithKeys(fn($l) => [$l => $p->precioLista($l)])->filter(), 'stock' => (float) $p->stock, 'unit' => $p->unit, 'desc_cant_min' => (float) $p->desc_cant_min, 'desc_cant_pct' => (float) $p->desc_cant_pct, 'imagen' => $p->imagen]);
    }
}
