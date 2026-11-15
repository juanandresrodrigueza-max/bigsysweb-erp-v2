<?php

namespace App\Services\Compras;

use App\Models\Business;
use App\Models\Product;
use App\Models\VentaHistorica;

// Fase 107: lo que hay que reponer, agrupado por proveedor habitual (lo que mostraba Comprar → Qué reponer desde la fase 83, ahora
// compartido con el agente de reposición). Entra lo que está en el mínimo o por debajo, y lo sin stock aunque no tenga mínimo.
class ReposicionService
{
    public function sugerencias(Business $b): array
    {
        $prods = Product::withoutGlobalScopes()->where('business_id', $b->id)->with('proveedor:id,name')->where('active', true)->where('controla_stock', true)
            ->where(fn($q) => $q->where(fn($w) => $w->where('stock_min', '>', 0)->whereColumn('stock', '<=', 'stock_min'))->orWhere('stock', '<=', 0))->orderBy('name')->get();
        // Fase 101: sin mínimo, la cantidad sugerida sale de lo que se vendía por mes en el sistema anterior.
        $prom = $prods->isEmpty() ? [] : VentaHistorica::promedioMensual($b->id, 6, $prods->pluck('id')->all());
        $items = $prods->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'stock' => (float) $p->stock, 'stock_min' => (float) $p->stock_min,
            'sugerido' => (float) $p->stock_min > 0 ? max(1, (float) ceil(2 * (float) $p->stock_min - (float) $p->stock)) : ((($prom[$p->id] ?? 0) > 0 && ceil($prom[$p->id] - (float) $p->stock) > 0) ? (float) ceil($prom[$p->id] - (float) $p->stock) : null),
            'por_historia' => (float) $p->stock_min <= 0 && ($prom[$p->id] ?? 0) > 0, 'unidad' => $p->unit, 'costo' => (float) $p->precio_compra > 0 ? (float) $p->precio_compra : (float) $p->cost,
            'proveedor_id' => $p->proveedor_id, 'proveedor' => $p->proveedor?->name]);
        $grupos = $items->groupBy(fn($i) => $i['proveedor_id'] ?? 0)->map(fn($lista, $pid) => [
            'proveedor_id' => $pid ?: null, 'proveedor' => $pid ? $lista->first()['proveedor'] : null,
            'items' => $lista->values()->all(), 'estimado' => round($lista->sum(fn($i) => ($i['sugerido'] ?? 0) * $i['costo']), 2),
        ])->sortBy(fn($g) => $g['proveedor'] ?? 'zzz')->values();
        return ['grupos' => $grupos, 'total' => $items->count(), 'sinProveedor' => $items->whereNull('proveedor_id')->count()];
    }
}
