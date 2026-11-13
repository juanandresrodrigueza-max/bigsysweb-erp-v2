<?php

namespace App\Services\Gastronomia;

use App\Models\Comanda;
use App\Models\Mesa;

// Fase 90: mapa de calor del salón: qué mesas rinden más y cuáles se sientan poco, y por franja horaria.
class CalorSalonService
{
    public const FRANJAS = ['desayuno' => ['Desayuno', 6, 11], 'almuerzo' => ['Almuerzo', 11, 16], 'merienda' => ['Merienda', 16, 20], 'cena' => ['Cena', 20, 30]];

    public function datos(int $locationId, int $dias = 30): array
    {
        $desde = now()->subDays($dias)->startOfDay();
        $cs = Comanda::where('business_location_id', $locationId)->where('estado', 'cerrada')->where('tipo', 'mesa')->whereNotNull('mesa_id')->where('cerrada_en', '>=', $desde)->get(['id', 'mesa_id', 'total', 'cubiertos', 'abierta_en', 'cerrada_en']);
        $mesas = Mesa::where('business_location_id', $locationId)->where('activa', true)->orderBy('sector')->orderBy('nombre')->get(['id', 'nombre', 'sector', 'capacidad']);
        $porMesa = $cs->groupBy('mesa_id');
        $filas = $mesas->map(function ($m) use ($porMesa, $dias) {
            $g = $porMesa[$m->id] ?? collect();
            $min = $g->filter(fn($c) => $c->abierta_en && $c->cerrada_en)->map(fn($c) => $c->abierta_en->diffInMinutes($c->cerrada_en));
            return ['id' => $m->id, 'nombre' => $m->nombre, 'sector' => $m->sector ?: 'Salón', 'capacidad' => $m->capacidad, 'comandas' => $g->count(), 'total' => round((float) $g->sum('total'), 2),
                'cubiertos' => (int) $g->sum('cubiertos'), 'ticket' => $g->count() ? round((float) $g->sum('total') / $g->count(), 2) : 0, 'rotacion' => round($g->count() / max(1, $dias), 2), 'minutos' => $min->count() ? (int) round($min->avg()) : null];
        });
        $max = (float) $filas->max('total');
        $filas = $filas->map(fn($f) => $f + ['calor' => $max > 0 ? round($f['total'] / $max, 2) : 0])->values();
        $franjas = collect(self::FRANJAS)->map(function ($fr, $k) use ($cs) {
            [$label, $d, $h] = $fr;
            $g = $cs->filter(function ($c) use ($d, $h) { if (! $c->abierta_en) return false; $hora = (int) $c->abierta_en->format('G'); if ($hora < 6) $hora += 24; return $hora >= $d && $hora < $h; });
            return ['franja' => $k, 'label' => $label, 'comandas' => $g->count(), 'total' => round((float) $g->sum('total'), 2), 'cubiertos' => (int) $g->sum('cubiertos')];
        })->values()->all();
        $conVenta = $filas->where('comandas', '>', 0);
        return ['dias' => $dias, 'mesas' => $filas->all(), 'top' => $filas->sortByDesc('total')->take(5)->values()->all(),
            'frias' => $filas->sortBy([['total', 'asc'], ['comandas', 'asc']])->take(5)->values()->all(), 'franjas' => $franjas,
            'total' => round((float) $cs->sum('total'), 2), 'comandas' => $cs->count(), 'sin_uso' => $filas->where('comandas', 0)->count(),
            'como' => "Comandas de mesa cerradas en los últimos {$dias} días en esta sucursal. El calor es la venta de cada mesa respecto de la que más vendió."];
    }
}
