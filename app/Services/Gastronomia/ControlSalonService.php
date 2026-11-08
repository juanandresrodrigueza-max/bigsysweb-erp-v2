<?php

namespace App\Services\Gastronomia;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\Comanda;
use App\Models\ComandaItem;
use Carbon\Carbon;

// Fase 56 · Control del salón: por mozo, anulaciones después de que el plato salió, descuentos y propinas fuera de lo normal del equipo.
// Es deterministico a propósito: se compara contra la mediana del equipo y se dice por qué, sin acusar a nadie.
class ControlSalonService
{
    public function datos(Business $b, Carbon $desde, Carbon $hasta): array
    {
        $cs = Comanda::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('estado', ['cerrada', 'anulada'])->whereBetween('cerrada_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])->with('mozo:id,name')->get();
        $anulados = ComandaItem::withoutGlobalScopes()->whereIn('comanda_id', $cs->pluck('id'))->where('estado', 'anulado')->whereNotNull('enviado_en')->get()->groupBy('comanda_id');
        $filas = $cs->groupBy('user_id')->map(function ($g) use ($anulados) {
            $cerr = $g->where('estado', 'cerrada'); $venta = (float) $cerr->sum('total'); $bruto = $venta + (float) $cerr->sum('descuento');
            $itemsAnul = $g->sum(fn($c) => ($anulados[$c->id] ?? collect())->count());
            return ['mozo' => $g->first()->mozo?->name ?? 'Sin mozo', 'user_id' => $g->first()->user_id, 'comandas' => $cerr->count(), 'venta' => round($venta, 2), 'anuladas' => $g->where('estado', 'anulada')->count(), 'items_anulados' => $itemsAnul,
                'descuento' => round((float) $cerr->sum('descuento'), 2), 'descuento_pct' => $bruto > 0 ? round((float) $cerr->sum('descuento') / $bruto * 100, 1) : 0, 'con_descuento' => $cerr->where('descuento', '>', 0)->count(),
                'propinas' => round((float) $cerr->sum('propina'), 2), 'propina_pct' => $venta > 0 ? round((float) $cerr->sum('propina') / $venta * 100, 1) : 0, 'raros' => []];
        })->values();
        // Mediana del equipo (con cantidad par, el promedio de los dos del medio).
        $med = function ($k) use ($filas) { $v = $filas->pluck($k)->sort()->values(); $n = $v->count(); if (! $n) return 0; return $n % 2 ? (float) $v[intdiv($n, 2)] : round(((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2, 2); };
        $mDesc = $med('descuento_pct'); $mProp = $med('propina_pct'); $mAnul = $med('items_anulados');
        $filas = $filas->map(function ($f) use ($mDesc, $mProp, $mAnul) {
            $r = [];
            if ($f['items_anulados'] >= 3 && $f['items_anulados'] >= max(2, $mAnul * 2)) $r[] = "{$f['items_anulados']} platos anulados después de salir de cocina (el equipo: " . round($mAnul) . ')';
            if ($f['anuladas'] >= 3) $r[] = "{$f['anuladas']} comandas anuladas enteras";
            if ($f['comandas'] >= 5 && $f['descuento_pct'] >= 5 && $f['descuento_pct'] >= $mDesc * 2) $r[] = "descuentos del {$f['descuento_pct']} % sobre lo vendido (el equipo: {$mDesc} %) en {$f['con_descuento']} comandas";
            if ($f['comandas'] >= 10 && $mProp >= 3 && $f['propina_pct'] < $mProp / 2) $r[] = "propinas del {$f['propina_pct']} % (el equipo: {$mProp} %): quizá no las registra";
            $f['raros'] = $r; return $f;
        })->sortByDesc(fn($f) => count($f['raros']) * 1000 + $f['venta'])->values();
        return ['mozos' => $filas->all(), 'mediana' => ['descuento_pct' => $mDesc, 'propina_pct' => $mProp, 'items_anulados' => $mAnul], 'como' => 'Comandas cerradas o anuladas en el período, por mozo. Se marca lo que se aparta del equipo: anulaciones de platos que ya habían salido, descuentos y propinas comparados con la mediana. Es para mirar, no una acusación.'];
    }

    // Una vez por semana (lunes): lo raro de la semana pasada queda como alerta, una por mozo y semana.
    public function alertarSemanal(Business $b): int
    {
        if (! now()->isMonday()) return 0;
        $d = $this->datos($b, now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()); $n = 0;
        foreach ($d['mozos'] as $m) {
            if (! $m['raros']) continue;
            Alerta::emitir(['business_id' => $b->id, 'modulo' => 'gastronomia', 'tipo' => 'salon_control', 'modelo' => 'User', 'modelo_id' => (int) ($m['user_id'] ?? 0) * 100 + (int) now()->subWeek()->format('W'), 'severidad' => 'aviso', 'titulo' => "Para mirar: {$m['mozo']} la semana pasada", 'detalle' => ucfirst(implode(' · ', $m['raros'])), 'url' => '/estadisticas/gastronomia?desde=' . now()->subWeek()->startOfWeek()->toDateString() . '&hasta=' . now()->subWeek()->endOfWeek()->toDateString()]);
            $n++;
        }
        return $n;
    }
}
