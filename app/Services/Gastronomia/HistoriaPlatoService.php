<?php

namespace App\Services\Gastronomia;

use App\Models\PrecioHistorial;
use App\Models\Product;
use Carbon\Carbon;

// Fase 114 · Historia del plato: cómo fue cambiando el costo de la receta, el precio y el margen mes a mes, y qué insumo explicó
// cada salto. Se reconstruye del historial de precios y costos (cada cambio queda con fecha desde la fase 33.3): para cada mes se
// toma el costo que tenía cada insumo al cierre de ese mes. Antes del primer cambio registrado se usa el valor que había antes.
class HistoriaPlatoService
{
    public function serie(Product $plato, int $meses = 12): array
    {
        $meses = max(2, min(36, $meses));
        $receta = RecetaVentaService::receta($plato);
        $items = $receta ? $receta->items->filter(fn($i) => $i->product)->values() : collect();
        $rinde = $receta && (float) $receta->yield_quantity > 0 ? (float) $receta->yield_quantity : 1.0;
        $ids = $items->pluck('product_id')->push($plato->id)->unique()->all();
        $hist = PrecioHistorial::withoutGlobalScopes()->where('business_id', $plato->business_id)->whereIn('product_id', $ids)->orderBy('fecha')->orderBy('id')->get()->groupBy('product_id');
        $hoy = today();
        $filas = []; $prev = null;
        for ($k = $meses - 1; $k >= 0; $k--) {
            $mes = $hoy->copy()->subMonthsNoOverflow($k)->startOfMonth();
            $corte = $mes->copy()->endOfMonth()->min($hoy);
            $detalle = $items->map(fn($i) => ['insumo' => $i->product->name, 'cantidad' => (float) $i->quantity, 'unidad' => $i->product->unit, 'costo_unit' => $this->valorEn($hist->get($i->product_id), $corte, 'costo', (float) $i->product->cost), 'id' => $i->product_id])
                ->map(fn($d) => $d + ['subtotal' => round($d['cantidad'] * $d['costo_unit'], 2)]);
            $costo = $receta ? round($detalle->sum('subtotal') / $rinde, 2) : round($this->valorEn($hist->get($plato->id), $corte, 'costo', (float) $plato->cost), 2);
            $precio = round($this->valorEn($hist->get($plato->id), $corte, 'precio', (float) $plato->price), 2);
            $margen = $precio > 0 ? round(($precio - $costo) / $precio * 100, 1) : null;
            $fila = ['mes' => $mes->format('Y-m'), 'etiqueta' => ucfirst($mes->translatedFormat('M y')), 'costo' => $costo, 'precio' => $precio, 'margen' => $margen, 'cambios' => [], 'detalle' => $detalle->all()];
            if ($prev) {
                foreach ($detalle as $d) {
                    $antes = collect($prev['detalle'])->firstWhere('id', $d['id']);
                    if ($antes && abs($d['subtotal'] - $antes['subtotal']) >= 0.5) $fila['cambios'][] = ['que' => $d['insumo'], 'de' => $antes['costo_unit'], 'a' => $d['costo_unit'], 'impacto' => round(($d['subtotal'] - $antes['subtotal']) / $rinde, 2)];
                }
                if (! $receta && abs($costo - $prev['costo']) >= 0.5) $fila['cambios'][] = ['que' => 'Costo cargado', 'de' => $prev['costo'], 'a' => $costo, 'impacto' => round($costo - $prev['costo'], 2)];
                usort($fila['cambios'], fn($a, $b) => abs($b['impacto']) <=> abs($a['impacto']));
                if (abs($precio - $prev['precio']) >= 0.5) $fila['cambio_precio'] = ['de' => $prev['precio'], 'a' => $precio];
            }
            $filas[] = $fila; $prev = $fila;
        }
        $primero = $filas[0]; $ultimo = $filas[count($filas) - 1];
        $varCosto = $primero['costo'] > 0 ? round(($ultimo['costo'] - $primero['costo']) / $primero['costo'] * 100, 1) : null;
        $varPrecio = $primero['precio'] > 0 ? round(($ultimo['precio'] - $primero['precio']) / $primero['precio'] * 100, 1) : null;
        $culpables = collect($filas)->flatMap(fn($f) => $f['cambios'])->groupBy('que')->map(fn($g) => round($g->sum('impacto'), 2))->sortByDesc(fn($v) => abs($v))->take(3);
        $resumen = $receta
            ? "{$plato->name}: el costo pasó de $ " . number_format($primero['costo'], 0, ',', '.') . ' a $ ' . number_format($ultimo['costo'], 0, ',', '.') . ($varCosto !== null ? " ({$this->signo($varCosto)} %)" : '')
                . ' y el precio de $ ' . number_format($primero['precio'], 0, ',', '.') . ' a $ ' . number_format($ultimo['precio'], 0, ',', '.') . ($varPrecio !== null ? " ({$this->signo($varPrecio)} %)" : '')
                . '. Margen: ' . ($primero['margen'] ?? '—') . ' % → ' . ($ultimo['margen'] ?? '—') . ' %.'
                . ($culpables->isNotEmpty() ? ' Lo que más pesó: ' . $culpables->map(fn($v, $k) => "{$k} " . ($v >= 0 ? '+' : '−') . '$ ' . number_format(abs($v), 0, ',', '.'))->implode(', ') . '.' : '')
            : "{$plato->name} no tiene receta: la historia es del costo cargado a mano y del precio.";
        return ['plato' => $plato->name, 'con_receta' => (bool) $receta, 'margen_objetivo' => $plato->margen_objetivo !== null ? (float) $plato->margen_objetivo : null, 'meses' => array_map(fn($f) => collect($f)->except('detalle')->all(), $filas),
            'hoy' => ['costo' => $ultimo['costo'], 'precio' => $ultimo['precio'], 'margen' => $ultimo['margen']], 'variacion' => ['costo_pct' => $varCosto, 'precio_pct' => $varPrecio],
            'que_mas_peso' => $culpables->map(fn($v, $k) => ['insumo' => $k, 'impacto' => $v])->values()->all(), 'resumen' => $resumen];
    }

    // Valor (costo o precio) que tenía el artículo al cierre de la fecha: el último cambio hasta ese día; si el primer cambio es posterior, lo de antes; si no hay historial, lo de hoy.
    private function valorEn($cambios, Carbon $corte, string $campo, float $actual): float
    {
        if (! $cambios || $cambios->isEmpty()) return $actual;
        $previos = $cambios->filter(fn($h) => $h->fecha->lte($corte) && $h->{$campo} !== null);
        if ($previos->isNotEmpty()) return (float) $previos->last()->{$campo};
        $sig = $cambios->first(fn($h) => $h->{$campo . '_antes'} !== null);
        return $sig ? (float) $sig->{$campo . '_antes'} : $actual;
    }

    private function signo(float $v): string { return ($v > 0 ? '+' : '') . number_format($v, 1, ',', '.'); }
}
