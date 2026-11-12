<?php

namespace App\Http\Controllers\Proveedores;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Inertia\Inertia;

// Fase 83: "Qué reponer", la primera sección de Comprar. Lo que está en el mínimo o por debajo, agrupado por proveedor habitual,
// con la cantidad sugerida (hasta el doble del mínimo) y un botón que arma la orden con ese proveedor.
class ReposicionController extends Controller
{
    public function index()
    {
        // Entra lo que está en el mínimo o por debajo y también lo que no tiene stock aunque no tenga mínimo (sin mínimo no hay cantidad sugerida).
        $items = Product::with('proveedor:id,name')->where('active', true)->where('controla_stock', true)
            ->where(fn($q) => $q->where(fn($w) => $w->where('stock_min', '>', 0)->whereColumn('stock', '<=', 'stock_min'))->orWhere('stock', '<=', 0))->orderBy('name')->get()
            ->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'stock' => (float) $p->stock, 'stock_min' => (float) $p->stock_min,
                'sugerido' => (float) $p->stock_min > 0 ? max(1, (float) ceil(2 * (float) $p->stock_min - (float) $p->stock)) : null, 'unidad' => $p->unit, 'costo' => (float) $p->cost,
                'proveedor_id' => $p->proveedor_id, 'proveedor' => $p->proveedor?->name]);
        $grupos = $items->groupBy(fn($i) => $i['proveedor_id'] ?? 0)->map(fn($lista, $pid) => [
            'proveedor_id' => $pid ?: null, 'proveedor' => $pid ? $lista->first()['proveedor'] : null,
            'items' => $lista->values()->all(), 'estimado' => round($lista->sum(fn($i) => ($i['sugerido'] ?? 0) * $i['costo']), 2),
        ])->sortBy(fn($g) => $g['proveedor'] ?? 'zzz')->values();

        return Inertia::render('Proveedores/Reponer', ['grupos' => $grupos, 'total' => $items->count(), 'sinProveedor' => $items->whereNull('proveedor_id')->count()]);
    }
}
