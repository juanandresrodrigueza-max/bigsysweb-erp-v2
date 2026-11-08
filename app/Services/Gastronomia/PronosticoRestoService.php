<?php

namespace App\Services\Gastronomia;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\ComandaItem;
use App\Models\Product;
use App\Models\Recipe;
use Carbon\Carbon;

// Fase 55 · Big Restó se adelanta: cuánto se vende cada día de la semana (platos), qué insumos hacen falta para la semana que viene
// y a qué proveedor pedírselos, y cuántos platos va a tener cada estación la próxima hora para avisar antes de que se trabe.
class PronosticoRestoService
{
    public const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    // Promedio de unidades vendidas de cada plato por día de la semana (últimas N semanas), sobre los días en que el local vendió.
    public function porDiaSemana(Business $b, int $semanas = 8): array
    {
        $desde = now()->subWeeks($semanas)->startOfDay();
        $filas = ComandaItem::withoutGlobalScopes()->join('comandas', 'comandas.id', '=', 'comanda_items.comanda_id')->where('comandas.business_id', $b->id)->where('comandas.estado', 'cerrada')->where('comandas.cerrada_en', '>=', $desde)
            ->where('comanda_items.estado', '!=', 'anulado')->whereNotNull('comanda_items.product_id')->get(['comanda_items.product_id', 'comanda_items.cantidad', 'comandas.cerrada_en']);
        $diasConVenta = []; $suma = [];
        foreach ($filas as $f) { $d = Carbon::parse($f->cerrada_en); $dow = $d->isoWeekday(); $diasConVenta[$dow][$d->toDateString()] = true; $suma[$f->product_id][$dow] = ($suma[$f->product_id][$dow] ?? 0) + (float) $f->cantidad; }
        $out = [];
        foreach ($suma as $pid => $porDia) foreach ($porDia as $dow => $u) $out[$pid][$dow] = round($u / max(1, count($diasConVenta[$dow] ?? [])), 2);
        return ['platos' => $out, 'dias_con_venta' => array_map('count', $diasConVenta), 'semanas' => $semanas];
    }

    // Los próximos 7 días: cuántos platos se esperan cada día y cuáles son los que más salen ese día.
    public function semana(Business $b): array
    {
        $p = $this->porDiaSemana($b); $nombres = Product::withoutGlobalScopes()->whereIn('id', array_keys($p['platos']))->pluck('name', 'id');
        $dias = [];
        for ($i = 1; $i <= 7; $i++) {
            $f = today()->addDays($i); $dow = $f->isoWeekday();
            $platos = collect($p['platos'])->map(fn($d, $pid) => ['id' => $pid, 'plato' => $nombres[$pid] ?? '?', 'unidades' => $d[$dow] ?? 0])->filter(fn($x) => $x['unidades'] > 0)->sortByDesc('unidades')->values();
            $dias[] = ['fecha' => $f->toDateString(), 'dia' => ucfirst(self::DIAS[$dow]), 'platos' => round($platos->sum('unidades'), 1), 'top' => $platos->take(3)->all(), 'base' => $p['dias_con_venta'][$dow] ?? 0];
        }
        return ['dias' => $dias, 'total' => round(collect($dias)->sum('platos'), 1), 'como' => "Promedio de lo vendido cada día de la semana en las últimas {$p['semanas']} semanas, sobre los días en que el local abrió."];
    }

    // Insumos que hacen falta para los próximos $dias según el pronóstico y las recetas, contra el stock que hay, agrupados por proveedor.
    public function queComprar(Business $b, int $dias = 7): array
    {
        $p = $this->porDiaSemana($b); $necesidad = [];
        $recetas = Recipe::withoutGlobalScopes()->where('business_id', $b->id)->where('is_active', true)->whereIn('product_id', array_keys($p['platos']))->with('items')->get()->keyBy('product_id');
        $platos = Product::withoutGlobalScopes()->whereIn('id', array_keys($p['platos']))->get()->keyBy('id');
        for ($i = 1; $i <= $dias; $i++) {
            $dow = today()->addDays($i)->isoWeekday();
            foreach ($p['platos'] as $pid => $porDia) {
                $u = $porDia[$dow] ?? 0; if ($u <= 0) continue;
                if ($r = $recetas[$pid] ?? null) { $factor = (float) $r->yield_quantity > 0 ? $u / (float) $r->yield_quantity : $u; foreach ($r->items as $it) $necesidad[$it->product_id] = ($necesidad[$it->product_id] ?? 0) + (float) $it->quantity * $factor; }
                elseif (($pl = $platos[$pid] ?? null) && $pl->controla_stock) $necesidad[$pid] = ($necesidad[$pid] ?? 0) + $u;
            }
        }
        $insumos = Product::withoutGlobalScopes()->whereIn('id', array_keys($necesidad))->with('proveedor:id,name')->get();
        $filas = $insumos->map(function ($x) use ($necesidad) { $n = round($necesidad[$x->id], 3); $falta = round(max(0, $n - (float) $x->stock), 3); return ['id' => $x->id, 'insumo' => $x->name, 'unit' => $x->unit, 'necesita' => $n, 'stock' => (float) $x->stock, 'falta' => $falta, 'costo' => round($falta * (float) $x->cost, 2), 'proveedor_id' => $x->proveedor_id, 'proveedor' => $x->proveedor?->name ?? 'Sin proveedor en la ficha']; })->filter(fn($f) => $f['falta'] > 0)->sortByDesc('costo')->values();
        $prov = $filas->groupBy('proveedor')->map(fn($g, $n) => ['proveedor' => $n, 'proveedor_id' => $g->first()['proveedor_id'], 'renglones' => $g->count(), 'costo' => round($g->sum('costo'), 2), 'items' => $g->values()->all()])->sortByDesc('costo')->values();
        return ['proveedores' => $prov->all(), 'total' => round($filas->sum('costo'), 2), 'dias' => $dias, 'como' => "Lo que se espera vender en los próximos {$dias} días (promedio por día de la semana) pasado por las recetas, menos el stock que hay. Lo que no tiene receta ni controla stock no entra."];
    }

    // Platos por hora y estación según el día de la semana (últimas N semanas).
    public function colaCocina(Business $b, int $semanas = 4): array
    {
        $desde = now()->subWeeks($semanas)->startOfDay(); $primera = $b->estacionesCocina()[0]['nombre'] ?? 'Cocina';
        $its = ComandaItem::withoutGlobalScopes()->join('comandas', 'comandas.id', '=', 'comanda_items.comanda_id')->where('comandas.business_id', $b->id)->where('comanda_items.va_cocina', true)->whereNotNull('comanda_items.enviado_en')->where('comanda_items.enviado_en', '>=', $desde)->where('comanda_items.estado', '!=', 'anulado')
            ->get(['comanda_items.estacion', 'comanda_items.cantidad', 'comanda_items.enviado_en']);
        $cuenta = []; $fechas = [];
        foreach ($its as $i) { $d = Carbon::parse($i->enviado_en); $k = $d->isoWeekday() . '-' . $d->format('G'); $e = $i->estacion ?: $primera; $cuenta[$e][$k] = ($cuenta[$e][$k] ?? 0) + (float) $i->cantidad; $fechas[$k][$d->toDateString()] = true; }
        $prom = [];
        foreach ($cuenta as $e => $porHora) foreach ($porHora as $k => $n) $prom[$e][$k] = round($n / max(1, count($fechas[$k] ?? [])), 1);
        return $prom;
    }

    // La próxima hora, estación por estación: lo esperado contra lo normal de esa estación. Pico = 1,5 veces lo normal y al menos 8 platos.
    public function proximaHora(Business $b): array
    {
        $prom = $this->colaCocina($b); if (! $prom) return [];
        $h = now()->addHour(); $k = $h->isoWeekday() . '-' . $h->format('G'); $out = [];
        foreach ($prom as $e => $porHora) {
            $esperado = $porHora[$k] ?? 0; $normal = round(collect($porHora)->avg(), 1); $max = max($porHora);
            $out[] = ['estacion' => $e, 'hora' => $h->format('H') . ':00', 'esperado' => $esperado, 'normal' => $normal, 'maximo' => $max, 'pico' => $esperado >= 8 && $esperado >= $normal * 1.5];
        }
        return $out;
    }

    // Cada 15 minutos: si la próxima hora viene pesada en alguna estación, una alerta (una por estación y hora).
    public function alertarPicos(Business $b): int
    {
        $n = 0;
        foreach ($this->proximaHora($b) as $e) {
            if (! $e['pico']) continue;
            Alerta::emitir(['business_id' => $b->id, 'modulo' => 'gastronomia', 'tipo' => 'cocina_pico', 'modelo' => 'Estacion', 'modelo_id' => crc32($e['estacion'] . today()->toDateString() . $e['hora']), 'severidad' => 'aviso', 'titulo' => "A las {$e['hora']} se te viene {$e['estacion']}: ~{$e['esperado']} platos", 'detalle' => "Lo normal de {$e['estacion']} son {$e['normal']} por hora (el máximo fue {$e['maximo']}). Adelantá mise en place o sumá una mano en esa estación.", 'url' => '/gastronomia/cocina?estacion=' . urlencode($e['estacion'])]);
            $n++;
        }
        return $n;
    }
}
