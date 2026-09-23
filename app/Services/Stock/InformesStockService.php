<?php

namespace App\Services\Stock;

use App\Models\ComprobanteItem;
use App\Models\Product;
use App\Models\Rubro;
use Illuminate\Support\Facades\Auth;

// Informes de stock: valorizado, ABC, faltantes con compra sugerida, artículos muertos y sugerencia de mínimos.
class InformesStockService
{
    private function ventas(int $dias): \Illuminate\Support\Collection
    {
        return ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', Auth::user()->business_id)
            ->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', ['FA', 'FB', 'FC', 'FE', 'REM'])->where('comprobantes.fecha', '>=', today()->subDays($dias))
            ->whereNotNull('comprobante_items.product_id')->selectRaw('comprobante_items.product_id, SUM(comprobante_items.cantidad) as cant, SUM(comprobante_items.neto) as neto, MAX(comprobantes.fecha) as ultima')->groupBy('comprobante_items.product_id')->get()->keyBy('product_id');
    }

    private function ventasEntre(\Carbon\Carbon $d, \Carbon\Carbon $h): \Illuminate\Support\Collection
    {
        return ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', Auth::user()->business_id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', ['FA', 'FB', 'FC', 'FE', 'REM'])->whereBetween('comprobantes.fecha', [$d->toDateString(), $h->toDateString()])->whereNotNull('comprobante_items.product_id')->selectRaw('comprobante_items.product_id, SUM(comprobante_items.cantidad) as cant')->groupBy('comprobante_items.product_id')->get()->keyBy('product_id');
    }

    public function valorizado(string $base = 'cost', ?int $rubroId = null): array
    {
        $ps = Product::with('rubro:id,nombre')->where('active', true)->where('controla_stock', true)->when($rubroId, fn($q, $r) => $q->where('rubro_id', $r))->get();
        $filas = $ps->map(function ($p) use ($base) {
            $unit = $base === 'cost' ? $p->costoPesos() : ($base === 'precio_compra' ? (float) $p->precio_compra : $p->precioLista((int) substr($base, -1)));
            return ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'rubro' => $p->rubro?->nombre ?? 'Sin rubro', 'unit' => $p->unit, 'stock' => (float) $p->stock, 'unitario' => $unit, 'valor' => round((float) $p->stock * $unit, 2)];
        })->sortByDesc('valor')->values();
        $porRubro = $filas->groupBy('rubro')->map(fn($g, $r) => ['rubro' => $r, 'articulos' => $g->count(), 'valor' => round($g->sum('valor'), 2)])->sortByDesc('valor')->values();
        return ['filas' => $filas->all(), 'por_rubro' => $porRubro->all(), 'total' => round($filas->sum('valor'), 2)];
    }

    // ABC por ventas (neto) de los últimos $dias: A hasta 80% acumulado, B hasta 95%, C el resto.
    public function abc(int $dias = 90): array
    {
        $v = $this->ventas($dias);
        $ps = Product::where('active', true)->whereIn('tipo', ['producto', 'elaborado'])->get();
        $filas = $ps->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'stock' => (float) $p->stock, 'valor_stock' => round((float) $p->stock * $p->costoPesos(), 2), 'vendido' => (float) ($v[$p->id]->cant ?? 0), 'neto' => round((float) ($v[$p->id]->neto ?? 0), 2)])->sortByDesc('neto')->values();
        $total = $filas->sum('neto'); $acum = 0;
        $filas = $filas->map(function ($f) use (&$acum, $total) { $acum += $f['neto']; $pct = $total > 0 ? $acum / $total * 100 : 100; $f['acumulado'] = round($pct, 1); $f['clase'] = $f['neto'] <= 0 ? 'C' : ($pct <= 80 ? 'A' : ($pct <= 95 ? 'B' : 'C')); return $f; });
        $resumen = collect(['A', 'B', 'C'])->map(fn($k) => ['clase' => $k, 'articulos' => $filas->where('clase', $k)->count(), 'neto' => round($filas->where('clase', $k)->sum('neto'), 2), 'valor_stock' => round($filas->where('clase', $k)->sum('valor_stock'), 2)]);
        return ['filas' => $filas->all(), 'resumen' => $resumen->all(), 'dias' => $dias];
    }

    // Faltantes: cuánto comprar para cubrir X días de venta según el ritmo de los últimos 90.
    public function faltantes(int $cobertura = 30, int $base = 90, bool $estacional = false): array
    {
        $v = $this->ventas($base);
        // Compra inteligente: si el mismo período del año pasado vendió más, se toma esa demanda (temporada).
        $vAnt = $estacional ? $this->ventasEntre(today()->subYear()->subDays(15), today()->subYear()->addDays($cobertura + 15)) : collect();
        $out = [];
        foreach (Product::with('proveedor:id,name')->where('active', true)->where('controla_stock', true)->whereIn('tipo', ['producto', 'insumo'])->get() as $p) {
            $diaria = (float) ($v[$p->id]->cant ?? 0) / $base;
            if ($estacional && isset($vAnt[$p->id])) $diaria = max($diaria, (float) $vAnt[$p->id]->cant / ($cobertura + 30));
            $necesario = $diaria * $cobertura;
            $dias = $diaria > 0 ? (float) $p->stock / $diaria : null;
            $pedir = round(max(0, $necesario - (float) $p->stock), 3);
            if ($pedir <= 0 && ! $p->bajoMinimo()) continue;
            $pedir = max($pedir, $p->bajoMinimo() ? round((float) $p->stock_min * 2 - (float) $p->stock, 3) : 0);
            $out[] = ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'proveedor' => $p->proveedor?->name, 'proveedor_id' => $p->proveedor_id, 'stock' => (float) $p->stock, 'minimo' => (float) $p->stock_min, 'venta_diaria' => round($diaria, 3), 'dias_stock' => $dias === null ? null : round($dias), 'pedir' => $pedir, 'costo' => round($pedir * (float) ($p->precio_compra ?: $p->cost), 2)];
        }
        usort($out, fn($a, $b) => ($a['dias_stock'] ?? 9999) <=> ($b['dias_stock'] ?? 9999));
        return ['filas' => $out, 'total' => round(array_sum(array_column($out, 'costo')), 2), 'cobertura' => $cobertura, 'estacional' => $estacional];
    }

    // Artículos con stock y sin ventas en $dias días: plata parada.
    public function muertos(int $dias = 90): array
    {
        $v = $this->ventas($dias);
        $filas = Product::where('active', true)->where('controla_stock', true)->where('stock', '>', 0)->get()->filter(fn($p) => ! isset($v[$p->id]))
            ->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'stock' => (float) $p->stock, 'unit' => $p->unit, 'valor' => round((float) $p->stock * $p->costoPesos(), 2), 'ultima_venta' => ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobante_items.product_id', $p->id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->max('comprobantes.fecha')])
            ->sortByDesc('valor')->values();
        return ['filas' => $filas->all(), 'total' => round($filas->sum('valor'), 2), 'dias' => $dias];
    }

    // Sugerencia de stock mínimo = venta diaria × días de reposición (lead time) × 1.2 de margen.
    public function sugerirMinimos(int $lead = 15, int $base = 90): array
    {
        $v = $this->ventas($base);
        $out = [];
        foreach (Product::where('active', true)->where('controla_stock', true)->get() as $p) {
            $diaria = (float) ($v[$p->id]->cant ?? 0) / $base; if ($diaria <= 0) continue;
            $sug = ceil($diaria * $lead * 1.2);
            if (abs($sug - (float) $p->stock_min) < 0.5) continue;
            $out[] = ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'actual' => (float) $p->stock_min, 'sugerido' => $sug, 'venta_diaria' => round($diaria, 2)];
        }
        return ['filas' => $out, 'lead' => $lead];
    }

    public function aplicarMinimos(array $items): int
    {
        $n = 0;
        foreach ($items as $id => $min) { if (Product::where('id', $id)->update(['stock_min' => (float) $min])) $n++; }
        return $n;
    }
}
