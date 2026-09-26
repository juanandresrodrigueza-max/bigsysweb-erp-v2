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
            'faltantes' => $svc->faltantes((int) ($request->cobertura ?: 30), 90, (bool) $request->estacional),
            'muertos' => $svc->muertos((int) ($request->dias ?: 90)),
            'minimos' => $svc->sugerirMinimos((int) ($request->lead ?: 15), 90, $request->metodo === 'estadistico' ? 'estadistico' : 'simple'),
            'vencimientos' => ['filas' => $lotes->porVencer((int) ($request->dias ?: 30))->map(fn($l) => ['id' => $l->id, 'product_id' => $l->product_id, 'nombre' => $l->product?->name, 'sku' => $l->product?->sku, 'etiqueta' => $l->etiqueta(), 'lote' => $l->lote, 'serie' => $l->serie, 'vencimiento' => $l->vencimiento->format('d/m/Y'), 'vencido' => $l->vencimiento->isPast(), 'dias' => (int) today()->diffInDays($l->vencimiento, false), 'cantidad' => (float) $l->cantidad, 'unit' => $l->product?->unit, 'deposito' => $l->deposito?->nombre, 'valor' => round((float) $l->cantidad * (float) $l->costo_unit, 2)])->all(), 'dias' => (int) ($request->dias ?: 30)],
        };
        if ($request->export) return $this->csv($tipo, $datos['filas']);
        return Inertia::render('Stock/Informes', ['tipo' => $tipo, 'datos' => $datos, 'filtros' => $request->only('base', 'rubro', 'dias', 'cobertura', 'lead', 'estacional', 'metodo'), 'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre'])]);
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

    // Centro de etiquetas (Fase 27.5): artículos, una compra recibida, cambios de precio, lotes o números de serie.
    public function etiquetas(Request $request)
    {
        $lista = max(1, min(6, (int) ($request->lista ?: 1)));
        $b = $request->user()->business;
        $fila = function (Product $p, array $extra = []) use ($lista) {
            $precio = $p->precioLista($lista);
            return array_replace(['k' => $p->id . '-' . ($extra['lote_id'] ?? '') . '-' . ($extra['serie'] ?? ''), 'id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode ?: $p->sku, 'precio' => $precio, 'medida' => $p->precioPorMedida($precio),
                'contenido' => $p->contenido_neto ? rtrim(rtrim(number_format((float) $p->contenido_neto, 3, ',', '.'), '0'), ',') . ' ' . $p->contenido_unidad : null, 'rubro' => $p->rubro?->nombre, 'unit' => $p->unit, 'cantidad' => 1, 'lote' => null, 'vence' => null, 'serie' => null], $extra);
        };
        $origen = null;
        if ($request->compra) {
            $c = \App\Models\Comprobante::compras()->with('items.product.rubro', 'contact:id,name')->findOrFail($request->compra);
            $origen = "{$c->nombreTipo()} {$c->numeroFormateado()} · {$c->contact?->name}";
            $filas = collect();
            foreach ($c->items as $it) {
                if (! $it->product) continue;
                $series = \App\Services\Stock\LotesService::series($it->serie);
                if ($it->product->seriado && $series) foreach ($series as $sn) $filas->push($fila($it->product, ['serie' => $sn, 'lote' => $it->lote, 'vence' => $it->vencimiento ? \Carbon\Carbon::parse($it->vencimiento)->format('d/m/Y') : null]));
                else $filas->push($fila($it->product, ['cantidad' => $it->product->unit === 'kg' ? 1 : max(1, (int) round((float) $it->cantidad)), 'lote' => $it->lote, 'vence' => $it->vencimiento ? \Carbon\Carbon::parse($it->vencimiento)->format('d/m/Y') : null]));
            }
        } elseif ($request->lotes) {
            $ls = Lote::with('product.rubro')->whereIn('id', array_map('intval', explode(',', (string) $request->lotes)))->get();
            $origen = 'Lotes y series elegidos';
            $filas = $ls->filter(fn($l) => $l->product)->map(fn($l) => $fila($l->product, ['lote_id' => $l->id, 'lote' => $l->lote, 'serie' => $l->serie, 'vence' => $l->vencimiento?->format('d/m/Y'), 'cantidad' => $l->serie ? 1 : max(1, (int) round((float) $l->cantidad))]));
        } elseif ($request->cambios) {
            $dias = max(1, (int) $request->cambios);
            $origen = "Precios cambiados en los últimos {$dias} días";
            $filas = Product::with('rubro:id,nombre')->where('active', true)->where('precio_actualizado_en', '>=', now()->subDays($dias))->orderBy('name')->limit(500)->get()->map(fn($p) => $fila($p));
        } else {
            $ids = array_filter(array_map('intval', explode(',', (string) $request->ids)));
            $filas = Product::with('rubro:id,nombre')->where('active', true)->when($ids, fn($q) => $q->whereIn('id', $ids))->when($request->rubro, fn($q, $r) => $q->where('rubro_id', $r))
                ->when($request->q, fn($q, $s) => $q->where(fn($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")->orWhere('barcode', 'like', "%{$s}%")))->orderBy('name')->limit(300)->get()->map(fn($p) => $fila($p));
        }
        return Inertia::render('Stock/Etiquetas', [
            'articulos' => $filas->values(), 'origen' => $origen, 'preseleccion' => (bool) ($origen || $request->ids),
            'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre']), 'filtros' => $request->only('rubro', 'q', 'ids', 'lista', 'compra', 'lotes', 'cambios'),
            'empresa' => $b->only('name') + ['color' => $b->marcaImpresion()['color_primario'] ?? '#e4003f', 'texto' => $b->marcaImpresion()['texto_primario'] ?? '#ffffff'],
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
        return response()->json(['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'precio' => $p->precioLista(1), 'precios' => collect([1, 2, 3, 4, 5, 6])->mapWithKeys(fn($l) => [$l => $p->precioLista($l)])->filter(), 'stock' => (float) $p->stock, 'unit' => $p->unit, 'desc_cant_min' => (float) $p->desc_cant_min, 'desc_cant_pct' => (float) $p->desc_cant_pct, 'desc_cant2_min' => (float) $p->desc_cant2_min, 'desc_cant2_pct' => (float) $p->desc_cant2_pct, 'imagen' => $p->imagen]);
    }
}
