<?php

namespace App\Services\Estadisticas;

use App\Models\Business;
use App\Models\ComprobanteItem;
use App\Models\PrecioHistorial;
use App\Models\Product;
use App\Models\Rubro;
use Illuminate\Support\Carbon;

// Fase 33.3 · Rentabilidad: márgenes a vigilar (mínimo por artículo, rubro o general, en semáforo, con historial), precios a revisar
// (debajo del costo, bajo el mínimo, sin actualizar hace 90 días o más) y el margen que se está achicando.
class VigilanciaService
{
    public const COMO = [
        'vigilar' => 'El margen de cada artículo sobre su costo (precio de la lista elegida menos costo, dividido costo) contra el mínimo que le pongas: manda el del artículo, después el del rubro y por último el general. Rojo: por debajo. Amarillo: a menos de 3 puntos. Un artículo sin ningún mínimo no se vigila.',
        'revisar' => 'Debajo del costo: la lista 1 no cubre el costo. Bajo el mínimo: no cumple el margen mínimo. Precio viejo: se vendió en los últimos 30 días y el precio no cambia hace más de 90. Los artículos en dólares no cuentan: se actualizan solos con la cotización.',
        'achica' => 'Por artículo, el margen sobre la venta de este mes contra el del mes anterior (con el costo del día de cada venta). Entran los que bajaron 2 puntos o más; dice si subió el costo o bajó el precio, y cuánto dejaste de ganar este mes con esa caída.',
    ];

    public function minimoGeneral(Business $b): ?float { $v = $b->rentabilidad['margen_minimo'] ?? null; return $v !== null && $v !== '' ? (float) $v : null; }

    public function margenes(Business $b, int $lista = 1, ?string $color = null, ?int $rubroId = null, ?string $q = null): array
    {
        $general = $this->minimoGeneral($b);
        $hace30 = today()->subDays(30)->toDateString(); $hace90 = today()->subDays(90)->toDateString();
        $prods = Product::with('rubro:id,nombre,margen_minimo')->where('active', true)->where('cost', '>', 0)->when($rubroId, fn($qq) => $qq->where('rubro_id', $rubroId))
            ->when($q, fn($qq) => $qq->where(fn($w) => $w->where('name', \App\Support\Sql::like(), "%{$q}%")->orWhere('sku', \App\Support\Sql::like(), "%{$q}%")))->get();
        $hist = PrecioHistorial::whereIn('product_id', $prods->pluck('id'))->orderBy('fecha')->orderBy('id')->get()->groupBy('product_id');
        $items = []; $cnt = ['rojo' => 0, 'amarillo' => 0, 'verde' => 0];
        foreach ($prods as $p) {
            $min = $p->margenMinimo($general); if ($min === null) continue;
            $m = $p->margenSobreCosto($lista); if ($m === null) continue;
            $estado = $m < $min ? 'rojo' : ($m < $min + 3 ? 'amarillo' : 'verde'); $cnt[$estado]++;
            if ($color && $color !== $estado) continue;
            $h = $hist[$p->id] ?? collect();
            $en = fn(string $fecha) => ($r = $h->filter(fn($x) => $x->fecha->toDateString() <= $fecha)->last()) ? PrecioHistorial::margen((float) $r->precio, (float) $r->costo) : null;
            $ultimo = $h->last();
            $items[] = ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'rubro' => $p->rubro?->nombre, 'precio' => $p->precioLista($lista), 'costo' => $p->costoPesos(), 'margen' => $m, 'minimo' => $min, 'origen_minimo' => $p->margen_minimo !== null ? 'artículo' : ($p->rubro?->margen_minimo !== null ? 'rubro' : 'general'),
                'estado' => $estado, 'brecha' => round($m - $min, 2), 'hace30' => $lista === 1 ? $en($hace30) : null, 'hace90' => $lista === 1 ? $en($hace90) : null,
                'ultimo_cambio' => $ultimo ? ['fecha' => $ultimo->fecha->toDateString(), 'precio' => (float) $ultimo->precio, 'precio_antes' => (float) $ultimo->precio_antes, 'costo' => (float) $ultimo->costo, 'costo_antes' => (float) $ultimo->costo_antes] : null, 'margen_minimo_articulo' => $p->margen_minimo !== null ? (float) $p->margen_minimo : null];
        }
        usort($items, fn($a, $bb) => [$a['estado'] === 'rojo' ? 0 : ($a['estado'] === 'amarillo' ? 1 : 2), $a['brecha']] <=> [$bb['estado'] === 'rojo' ? 0 : ($bb['estado'] === 'amarillo' ? 1 : 2), $bb['brecha']]);
        return ['items' => array_slice($items, 0, 400), 'cantidad' => $cnt, 'general' => $general, 'lista' => $lista, 'como' => self::COMO['vigilar'],
            'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre', 'parent_id', 'margen_minimo'])->map(fn($r) => ['id' => $r->id, 'nombre' => $r->nombre, 'parent_id' => $r->parent_id, 'margen_minimo' => $r->margen_minimo !== null ? (float) $r->margen_minimo : null])];
    }

    public function historial(Product $p): array
    {
        $h = PrecioHistorial::where('product_id', $p->id)->orderBy('fecha')->orderBy('id')->get();
        $serie = $h->map(fn($x) => ['fecha' => $x->fecha->toDateString(), 'precio' => (float) $x->precio, 'costo' => (float) $x->costo, 'margen' => PrecioHistorial::margen((float) $x->precio, (float) $x->costo), 'precio_antes' => (float) $x->precio_antes, 'costo_antes' => (float) $x->costo_antes, 'usuario' => $x->user_id ? \App\Models\User::withoutGlobalScopes()->whereKey($x->user_id)->value('name') : null])->values()->all();
        $serie[] = ['fecha' => today()->toDateString(), 'precio' => (float) $p->price, 'costo' => $p->costoPesos(), 'margen' => $p->margenSobreCosto(1), 'hoy' => true];
        return ['nombre' => $p->name, 'minimo' => $p->margenMinimo($this->minimoGeneral($p->business)), 'serie' => $serie];
    }

    public function guardarMinimos(Business $b, array $d): void
    {
        if (array_key_exists('general', $d)) $b->update(['rentabilidad' => array_merge($b->rentabilidad ?? [], ['margen_minimo' => $d['general'] !== null && $d['general'] !== '' ? (float) $d['general'] : null])]);
        foreach ($d['rubros'] ?? [] as $r) Rubro::whereKey($r['id'])->update(['margen_minimo' => $r['margen_minimo'] !== null && $r['margen_minimo'] !== '' ? (float) $r['margen_minimo'] : null]);
        foreach ($d['articulos'] ?? [] as $a) Product::whereKey($a['id'])->update(['margen_minimo' => $a['margen_minimo'] !== null && $a['margen_minimo'] !== '' ? (float) $a['margen_minimo'] : null]);
    }

    public function preciosARevisar(Business $b): array
    {
        $general = $this->minimoGeneral($b);
        $vendidos = ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', $b->id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')
            ->whereIn('comprobantes.tipo', TableroService::FACTURAS)->where('comprobantes.fecha', '>=', today()->subDays(30)->toDateString())->whereNotNull('comprobante_items.product_id')->distinct()->pluck('comprobante_items.product_id')->flip();
        $debajo = []; $bajo = []; $viejo = ['90_180' => [], '180_365' => [], 'mas_365' => [], 'nunca' => []];
        foreach (Product::with('rubro:id,nombre,margen_minimo')->where('active', true)->whereIn('tipo', ['producto', 'elaborado'])->get() as $p) {
            $costo = $p->costoPesos(); $precio = $p->precioLista(1);
            $fila = ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'rubro' => $p->rubro?->nombre, 'precio' => $precio, 'costo' => $costo];
            if ($costo > 0 && $precio < $costo) $debajo[] = $fila + ['pierde' => round($costo - $precio, 2)];
            elseif ($costo > 0 && ($min = $p->margenMinimo($general)) !== null && ($m = $p->margenSobreCosto(1)) !== null && $m < $min) $bajo[] = $fila + ['margen' => $m, 'minimo' => $min];
            if ($p->moneda !== 'USD' && isset($vendidos[$p->id])) {
                $dias = ($p->precio_actualizado_en ?? $p->created_at)?->diffInDays(now()); // sin fecha de actualización: desde que se cargó
                if ($dias === null) $viejo['nunca'][] = $fila + ['dias' => null];
                elseif ($dias > 365) $viejo['mas_365'][] = $fila + ['dias' => $dias];
                elseif ($dias > 180) $viejo['180_365'][] = $fila + ['dias' => $dias];
                elseif ($dias > 90) $viejo['90_180'][] = $fila + ['dias' => $dias];
            }
        }
        usort($debajo, fn($a, $bb) => $bb['pierde'] <=> $a['pierde']); usort($bajo, fn($a, $bb) => ($a['margen'] - $a['minimo']) <=> ($bb['margen'] - $bb['minimo']));
        return ['debajo_costo' => $debajo, 'bajo_minimo' => $bajo, 'viejo' => $viejo, 'total_viejo' => array_sum(array_map('count', $viejo)), 'como' => self::COMO['revisar']];
    }

    // El margen que se está achicando: este mes contra el anterior, artículo por artículo, con el costo del día de cada venta.
    public function margenQueSeAchica(Business $b, ?Carbon $hoy = null, float $puntos = 2.0): array
    {
        $hoy ??= today();
        $mes = fn(Carbon $d, Carbon $h) => ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->join('products', 'products.id', '=', 'comprobante_items.product_id')
            ->where('comprobantes.business_id', $b->id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', TableroService::FACTURAS)->whereBetween('comprobantes.fecha', [$d->toDateString(), $h->toDateString()])
            ->selectRaw('comprobante_items.product_id as pid, products.name as nombre, products.sku, SUM(comprobante_items.neto - comprobante_items.descuento) as neto, SUM(comprobante_items.cantidad) as cantidad, SUM(comprobante_items.cantidad * COALESCE(comprobante_items.costo_unit, products.cost)) as costo')
            ->groupBy('comprobante_items.product_id', 'products.name', 'products.sku')->havingRaw('SUM(comprobante_items.neto - comprobante_items.descuento) > 0')->get()->keyBy('pid');
        $act = $mes($hoy->copy()->startOfMonth(), $hoy); $ant = $mes($hoy->copy()->subMonthNoOverflow()->startOfMonth(), $hoy->copy()->subMonthNoOverflow()->endOfMonth());
        $out = [];
        foreach ($act as $pid => $a) {
            $p = $ant[$pid] ?? null; if (! $p) continue;
            $pctA = ((float) $a->neto - (float) $a->costo) / (float) $a->neto * 100; $pctP = ((float) $p->neto - (float) $p->costo) / (float) $p->neto * 100;
            if ($pctP - $pctA < $puntos) continue;
            $costoA = (float) $a->cantidad > 0 ? (float) $a->costo / (float) $a->cantidad : 0; $costoP = (float) $p->cantidad > 0 ? (float) $p->costo / (float) $p->cantidad : 0;
            $precioA = (float) $a->cantidad > 0 ? (float) $a->neto / (float) $a->cantidad : 0; $precioP = (float) $p->cantidad > 0 ? (float) $p->neto / (float) $p->cantidad : 0;
            $subioCosto = $costoA > $costoP * 1.005; $bajoPrecio = $precioA < $precioP * 0.995;
            $out[] = ['id' => $pid, 'nombre' => $a->nombre, 'sku' => $a->sku, 'margen_actual' => round($pctA, 1), 'margen_anterior' => round($pctP, 1), 'puntos' => round($pctP - $pctA, 1), 'costo_actual' => round($costoA, 2), 'costo_anterior' => round($costoP, 2), 'precio_actual' => round($precioA, 2), 'precio_anterior' => round($precioP, 2),
                'motivo' => $subioCosto && $bajoPrecio ? 'subió el costo y bajó el precio' : ($subioCosto ? 'subió el costo' : ($bajoPrecio ? 'bajó el precio' : 'cambió la mezcla')), 'dejaste_de_ganar' => round(($pctP - $pctA) / 100 * (float) $a->neto, 2), 'neto' => round((float) $a->neto, 2)];
        }
        usort($out, fn($x, $y) => $y['dejaste_de_ganar'] <=> $x['dejaste_de_ganar']);
        return ['items' => array_slice($out, 0, 100), 'total' => round(array_sum(array_column($out, 'dejaste_de_ganar')), 2), 'mes' => $hoy->locale('es')->isoFormat('MMMM'), 'mes_anterior' => $hoy->copy()->subMonthNoOverflow()->locale('es')->isoFormat('MMMM'), 'como' => self::COMO['achica']];
    }
}
