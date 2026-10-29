<?php

namespace App\Services\Estadisticas;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Product;
use App\Models\ProveedorArticulo;
use App\Models\StockMovement;
use App\Support\Sql;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Fase 33.4 · Stock y compras: lo que dejás de vender por falta de stock, qué pedirle a cada proveedor, antigüedad, ajustes para abajo
// valorizados, cuánto aumentó cada proveedor y quién vende más barato cada artículo con el ahorro por mes.
class StockComprasService
{
    public const COMO = [
        'dejas' => 'Artículos sin stock que se venían vendiendo: lo que vendían por día en los últimos 60 días (facturas, sin IVA) por el precio de hoy. Es una estimación de lo que dejás de facturar mientras falten.',
        'pedir' => 'Lo que hay que reponer para cubrir 30 días al ritmo de venta de los últimos 90, agrupado por el proveedor de la ficha; «cada cuánto le comprás» es el promedio de días entre sus facturas del último año.',
        'antiguedad' => 'Para cada artículo con stock: la última venta y la última compra, con los días que pasaron, y la plata parada al costo.',
        'ajustes' => 'Los ajustes de stock para abajo (recuentos, roturas, vencimientos, errores) de los últimos 30 días, valorizados al costo del movimiento. Los ajustes para arriba no se muestran.',
        'aumentos' => 'Por proveedor: el costo de los mismos artículos en sus facturas de los últimos 90 días contra los 90 anteriores, ponderado por lo comprado.',
        'barato' => 'Para cada artículo con más de un proveedor con precio en los últimos 180 días (facturas y listas): el de hoy (el de la ficha o el último) contra el más barato, y el ahorro por lo vendido en 30 días. Una diferencia de más del 70 % va aparte: casi siempre es otra presentación o un precio mal cargado.',
    ];

    private function ventasItems(int $bid, string $d, string $h)
    {
        return ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', $bid)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')
            ->whereIn('comprobantes.tipo', TableroService::FACTURAS)->whereBetween('comprobantes.fecha', [$d, $h])->whereNotNull('comprobante_items.product_id');
    }

    public function dejasDeVender(Business $b): array
    {
        $d60 = today()->subDays(60)->toDateString();
        $v = $this->ventasItems($b->id, $d60, today()->toDateString())->selectRaw('comprobante_items.product_id as pid, SUM(comprobante_items.cantidad) as cant, MAX(comprobantes.fecha) as ultima')->groupBy('comprobante_items.product_id')->get()->keyBy('pid');
        $out = [];
        foreach (Product::with('proveedor:id,name')->where('active', true)->where('controla_stock', true)->where('stock', '<=', 0)->whereIn('id', $v->keys())->get() as $p) {
            $diaria = (float) $v[$p->id]->cant / 60; if ($diaria <= 0) continue;
            $sin = StockMovement::where('product_id', $p->id)->where('stock_after', '<=', 0)->orderByDesc('id')->value('created_at');
            $precio = $p->precioLista(1);
            $out[] = ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'proveedor' => $p->proveedor?->name, 'proveedor_id' => $p->proveedor_id, 'por_dia' => round($diaria, 2), 'precio' => $precio, 'pierde_dia' => round($diaria * $precio, 2), 'sin_stock_desde' => $sin ? Carbon::parse($sin)->toDateString() : null, 'dias_sin' => $sin ? (int) Carbon::parse($sin)->diffInDays(now()) : null, 'ultima_venta' => (string) $v[$p->id]->ultima];
        }
        usort($out, fn($x, $y) => $y['pierde_dia'] <=> $x['pierde_dia']);
        return ['items' => $out, 'por_dia' => round(array_sum(array_column($out, 'pierde_dia')), 2), 'por_mes' => round(array_sum(array_column($out, 'pierde_dia')) * 30, 2), 'como' => self::COMO['dejas']];
    }

    public function pedirPorProveedor(Business $b): array
    {
        $f = app(\App\Services\Stock\InformesStockService::class)->faltantes(30, 90);
        // cada cuánto le comprás a cada proveedor: promedio de días entre facturas del último año
        $compras = Comprobante::compras()->emitidos()->whereIn('tipo', TableroService::FACTURAS)->where('fecha', '>=', today()->subYear()->toDateString())->orderBy('fecha')->get(['contact_id', 'fecha'])->groupBy('contact_id');
        $cada = $compras->map(function ($g) { $f = $g->pluck('fecha')->map(fn($x) => $x->toDateString())->unique()->values(); if ($f->count() < 2) return null; $tot = 0; for ($i = 1; $i < $f->count(); $i++) $tot += Carbon::parse($f[$i - 1])->diffInDays(Carbon::parse($f[$i])); return (int) round($tot / ($f->count() - 1)); });
        $ultima = $compras->map(fn($g) => $g->last()->fecha->toDateString());
        $grupos = collect($f['filas'])->groupBy(fn($r) => $r['proveedor_id'] ?? 0)->map(function ($g, $pid) use ($cada, $ultima) {
            $sinCosto = $g->filter(fn($r) => (float) ($r['costo'] ?? 0) <= 0)->count();
            return ['proveedor_id' => $pid ?: null, 'proveedor' => $g->first()['proveedor'] ?? 'Sin proveedor en la ficha', 'renglones' => $g->count(), 'costo' => round($g->sum('costo'), 2), 'sin_costo' => $sinCosto, 'cada_dias' => $cada[$pid] ?? null, 'ultima_compra' => $ultima[$pid] ?? null, 'items' => $g->values()->all()];
        })->sortByDesc('costo')->values();
        return ['proveedores' => $grupos->all(), 'total' => round($grupos->sum('costo'), 2), 'renglones' => $grupos->sum('renglones'), 'sin_costo' => $grupos->sum('sin_costo'), 'como' => self::COMO['pedir']];
    }

    public function antiguedad(Business $b, string $orden = 'sin_vender', ?string $q = null, int $limite = 300): array
    {
        $uv = $this->ventasItems($b->id, '2000-01-01', today()->toDateString())->selectRaw('comprobante_items.product_id as pid, MAX(comprobantes.fecha) as f')->groupBy('comprobante_items.product_id')->pluck('f', 'pid');
        $uc = ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', $b->id)->where('comprobantes.direccion', 'compra')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', ['FA', 'FB', 'FC', 'FE', 'REM'])->whereNotNull('comprobante_items.product_id')
            ->selectRaw('comprobante_items.product_id as pid, MAX(comprobantes.fecha) as f')->groupBy('comprobante_items.product_id')->pluck('f', 'pid');
        $filas = Product::where('active', true)->where('controla_stock', true)->where('stock', '>', 0)->when($q, fn($qq) => $qq->where(fn($w) => $w->where('name', Sql::like(), "%{$q}%")->orWhere('sku', Sql::like(), "%{$q}%")))->get()
            ->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'stock' => (float) $p->stock, 'unit' => $p->unit, 'plata' => round((float) $p->stock * $p->costoPesos(), 2),
                'ultima_venta' => isset($uv[$p->id]) ? (string) $uv[$p->id] : null, 'dias_sin_vender' => isset($uv[$p->id]) ? (int) Carbon::parse($uv[$p->id])->diffInDays(today()) : null,
                'ultima_compra' => isset($uc[$p->id]) ? (string) $uc[$p->id] : null, 'dias_sin_comprar' => isset($uc[$p->id]) ? (int) Carbon::parse($uc[$p->id])->diffInDays(today()) : null]);
        $filas = match ($orden) { 'sin_comprar' => $filas->sortByDesc(fn($f) => $f['dias_sin_comprar'] ?? 99999), 'plata' => $filas->sortByDesc('plata'), 'stock' => $filas->sortByDesc('stock'), default => $filas->sortByDesc(fn($f) => $f['dias_sin_vender'] ?? 99999) };
        return ['items' => $filas->take($limite)->values()->all(), 'total' => $filas->count(), 'plata' => round($filas->sum('plata'), 2), 'orden' => $orden, 'como' => self::COMO['antiguedad']];
    }

    public function ajustesAbajo(Business $b, int $dias = 30): array
    {
        $movs = StockMovement::with('product:id,name,sku,rubro_id,unit', 'product.rubro:id,nombre')->where('business_id', $b->id)->where('type', 'ajuste')->whereColumn('stock_after', '<', 'stock_before')->where('created_at', '>=', today()->subDays($dias)->startOfDay())->orderByDesc('id')->get();
        $filas = $movs->map(fn($m) => ['fecha' => $m->created_at->toDateString(), 'id' => $m->product_id, 'nombre' => $m->product?->name, 'sku' => $m->product?->sku, 'rubro' => $m->product?->rubro?->nombre ?? 'Sin rubro', 'cantidad' => round((float) $m->stock_before - (float) $m->stock_after, 3), 'unit' => $m->product?->unit, 'valor' => round(((float) $m->stock_before - (float) $m->stock_after) * (float) ($m->costo_unit ?: 0), 2), 'motivo' => $m->reason]);
        $porRubro = $filas->groupBy('rubro')->map(fn($g, $r) => ['rubro' => $r, 'ajustes' => $g->count(), 'valor' => round($g->sum('valor'), 2)])->sortByDesc('valor')->values();
        return ['items' => $filas->take(300)->values()->all(), 'por_rubro' => $porRubro->all(), 'total' => round($filas->sum('valor'), 2), 'cantidad' => $filas->count(), 'dias' => $dias, 'como' => self::COMO['ajustes']];
    }

    public function aumentoProveedores(Business $b): array
    {
        $q = fn(string $d, string $h) => ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', $b->id)->where('comprobantes.direccion', 'compra')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', TableroService::FACTURAS)
            ->whereBetween('comprobantes.fecha', [$d, $h])->whereNotNull('comprobante_items.product_id')->selectRaw('comprobantes.contact_id as cid, comprobante_items.product_id as pid, SUM(comprobante_items.cantidad) as cant, SUM(comprobante_items.cantidad * comprobante_items.precio_unit * (1 - comprobante_items.descuento / 100)) as importe')->groupBy('comprobantes.contact_id', 'comprobante_items.product_id')->get();
        $act = $q(today()->subDays(90)->toDateString(), today()->toDateString()); $ant = $q(today()->subDays(180)->toDateString(), today()->subDays(91)->toDateString());
        $antK = $ant->keyBy(fn($r) => $r->cid . '|' . $r->pid);
        $por = [];
        foreach ($act as $r) {
            $p = $antK[$r->cid . '|' . $r->pid] ?? null; if (! $p || (float) $r->cant <= 0 || (float) $p->cant <= 0) continue;
            $cA = (float) $r->importe / (float) $r->cant; $cP = (float) $p->importe / (float) $p->cant; if ($cP <= 0) continue;
            $por[$r->cid] ??= ['peso' => 0, 'suma' => 0, 'articulos' => 0, 'gasto' => 0];
            $por[$r->cid]['peso'] += (float) $r->importe; $por[$r->cid]['suma'] += ($cA / $cP - 1) * (float) $r->importe; $por[$r->cid]['articulos']++; $por[$r->cid]['gasto'] += (float) $r->importe;
        }
        $nombres = \App\Models\Contact::whereIn('id', array_keys($por))->pluck('name', 'id');
        $out = collect($por)->map(fn($x, $cid) => ['contact_id' => $cid, 'proveedor' => $nombres[$cid] ?? "Proveedor {$cid}", 'aumento' => $x['peso'] > 0 ? round($x['suma'] / $x['peso'] * 100, 1) : 0, 'articulos' => $x['articulos'], 'comprado' => round($x['gasto'], 2)])->sortByDesc('aumento')->values();
        return ['items' => $out->all(), 'como' => self::COMO['aumentos']];
    }

    public function masBarato(Business $b): array
    {
        $corte = today()->subDays(180)->toDateString();
        $precios = []; // pid => [cid => [costo, fecha, codigo]]
        foreach (ProveedorArticulo::whereNotNull('product_id')->whereNotNull('ultimo_costo')->where('ultimo_costo', '>', 0)->where('ultima_fecha', '>=', $corte)->get() as $pa) $precios[$pa->product_id][$pa->contact_id] = ['costo' => (float) $pa->ultimo_costo, 'fecha' => $pa->ultima_fecha->toDateString(), 'codigo' => $pa->codigo];
        $compras = ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', $b->id)->where('comprobantes.direccion', 'compra')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', TableroService::FACTURAS)->where('comprobantes.fecha', '>=', $corte)->whereNotNull('comprobante_items.product_id')
            ->orderBy('comprobantes.fecha')->orderBy('comprobantes.id')->get(['comprobantes.contact_id', 'comprobantes.fecha', 'comprobante_items.product_id', 'comprobante_items.precio_unit', 'comprobante_items.descuento', 'comprobantes.moneda', 'comprobantes.cotizacion']);
        $ultimoProv = [];
        foreach ($compras as $c) { $costo = (float) $c->precio_unit * (1 - (float) $c->descuento / 100) * (($c->moneda ?? 'ARS') === 'USD' ? ((float) $c->cotizacion ?: 1) : 1); if ($costo <= 0) continue; $f = (string) $c->fecha; if (! isset($precios[$c->product_id][$c->contact_id]) || $precios[$c->product_id][$c->contact_id]['fecha'] <= $f) $precios[$c->product_id][$c->contact_id] = ['costo' => round($costo, 2), 'fecha' => $f, 'codigo' => $precios[$c->product_id][$c->contact_id]['codigo'] ?? null]; $ultimoProv[$c->product_id] = $c->contact_id; }
        $pids = array_keys(array_filter($precios, fn($x) => count($x) >= 2)); if (! $pids) return ['items' => [], 'revisar' => [], 'ahorro_mes' => 0, 'gana' => [], 'como' => self::COMO['barato']];
        $vend = $this->ventasItems($b->id, today()->subDays(30)->toDateString(), today()->toDateString())->whereIn('comprobante_items.product_id', $pids)->selectRaw('comprobante_items.product_id as pid, SUM(comprobante_items.cantidad) as cant')->groupBy('comprobante_items.product_id')->pluck('cant', 'pid');
        $prods = Product::whereIn('id', $pids)->get()->keyBy('id'); $nombres = \App\Models\Contact::whereIn('id', collect($precios)->flatMap(fn($x) => array_keys($x))->unique())->pluck('name', 'id');
        $items = []; $revisar = []; $gana = [];
        foreach ($pids as $pid) {
            $p = $prods[$pid] ?? null; if (! $p) continue;
            $lista = collect($precios[$pid])->map(fn($x, $cid) => $x + ['contact_id' => $cid, 'proveedor' => $nombres[$cid] ?? "Proveedor {$cid}"])->sortBy('costo')->values();
            $mejor = $lista->first(); $hoyId = $p->proveedor_id && isset($precios[$pid][$p->proveedor_id]) ? $p->proveedor_id : ($ultimoProv[$pid] ?? $lista->last()['contact_id']);
            $hoy = $lista->firstWhere('contact_id', $hoyId) ?? $lista->last();
            if ($hoy['contact_id'] === $mejor['contact_id']) { $gana[$mejor['proveedor']] = ($gana[$mejor['proveedor']] ?? 0) + 1; continue; }
            $dif = $hoy['costo'] - $mejor['costo']; $pct = $mejor['costo'] > 0 ? round($dif / $mejor['costo'] * 100, 1) : 0;
            $fila = ['id' => $pid, 'nombre' => $p->name, 'sku' => $p->sku, 'hoy' => $hoy, 'mejor' => $mejor, 'diferencia' => round($dif, 2), 'pct' => $pct, 'vendido_30' => (float) ($vend[$pid] ?? 0), 'ahorro_mes' => round($dif * (float) ($vend[$pid] ?? 0), 2), 'proveedores' => $lista->count()];
            if ($pct > 70) $revisar[] = $fila; else { $items[] = $fila; $gana[$mejor['proveedor']] = ($gana[$mejor['proveedor']] ?? 0) + 1; }
        }
        usort($items, fn($x, $y) => $y['ahorro_mes'] <=> $x['ahorro_mes']);
        arsort($gana);
        return ['items' => array_slice($items, 0, 200), 'revisar' => $revisar, 'ahorro_mes' => round(array_sum(array_column($items, 'ahorro_mes')), 2), 'gana' => collect($gana)->map(fn($n, $k) => ['proveedor' => $k, 'articulos' => $n])->values()->all(), 'como' => self::COMO['barato']];
    }
}
