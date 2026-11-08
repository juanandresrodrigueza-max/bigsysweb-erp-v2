<?php

namespace App\Services\Gastronomia;

use App\Models\Comanda;
use App\Models\ComandaItem;
use Carbon\Carbon;

// Fase 52 · Estadísticas → Gastronomía: ventas por mozo y por hora, ticket promedio, cubiertos, platos más vendidos, propinas y tiempos de cocina.
class ReporteService
{
    public function datos(Carbon $desde, Carbon $hasta): array
    {
        $cs = Comanda::with('mozo:id,name')->where('estado', 'cerrada')->whereBetween('cerrada_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])->get();
        $total = round($cs->sum(fn($c) => (float) $c->total), 2); $n = $cs->count(); $cub = (int) $cs->sum('cubiertos');
        $porMozo = $cs->groupBy(fn($c) => $c->mozo?->name ?? 'Sin mozo')->map(fn($g, $m) => ['mozo' => $m, 'comandas' => $g->count(), 'total' => round($g->sum(fn($c) => (float) $c->total), 2), 'propinas' => round($g->sum(fn($c) => (float) $c->propina), 2), 'ticket' => round($g->avg(fn($c) => (float) $c->total), 2), 'cubiertos' => (int) $g->sum('cubiertos')])->sortByDesc('total')->values();
        $porHora = collect(range(0, 23))->map(fn($h) => ['hora' => sprintf('%02d', $h), 'total' => round($cs->filter(fn($c) => (int) $c->abierta_en?->format('G') === $h)->sum(fn($c) => (float) $c->total), 2), 'comandas' => $cs->filter(fn($c) => (int) $c->abierta_en?->format('G') === $h)->count()])->filter(fn($r) => $r['comandas'] > 0)->values();
        $porTipo = $cs->groupBy('tipo')->map(fn($g, $t) => ['tipo' => ['mesa' => 'Salón', 'mostrador' => 'Mostrador / para llevar', 'delivery' => 'Delivery'][$t] ?? $t, 'comandas' => $g->count(), 'total' => round($g->sum(fn($c) => (float) $c->total), 2)])->sortByDesc('total')->values();
        $items = ComandaItem::whereIn('comanda_id', $cs->pluck('id'))->where('estado', '!=', 'anulado')->get();
        $platos = $items->groupBy(fn($i) => $i->product_id ?: $i->descripcion)->map(fn($g) => ['plato' => preg_replace('/ \(.*\)$| \[.*\]$/', '', $g->first()->descripcion), 'unidades' => round($g->sum(fn($i) => (float) $i->cantidad), 2), 'total' => round($g->sum(fn($i) => (float) $i->cantidad * (float) $i->precio_unit), 2)])->sortByDesc('unidades')->values()->take(30);
        $cocina = $items->filter(fn($i) => $i->va_cocina && $i->enviado_en && $i->listo_en)->groupBy(fn($i) => $i->estacion ?: 'Cocina')->map(function ($g, $e) {
            $min = $g->map(fn($i) => $i->enviado_en->diffInMinutes($i->listo_en));
            return ['estacion' => $e, 'platos' => $g->count(), 'promedio' => (int) round($min->avg()), 'maximo' => (int) $min->max()];
        })->values();
        $mins = $cs->filter(fn($c) => $c->abierta_en && $c->cerrada_en)->map(fn($c) => $c->abierta_en->diffInMinutes($c->cerrada_en));
        return [
            'kpis' => ['total' => $total, 'comandas' => $n, 'ticket' => $n ? round($total / $n, 2) : 0, 'cubiertos' => $cub, 'por_cubierto' => $cub ? round($total / $cub, 2) : 0, 'propinas' => round($cs->sum(fn($c) => (float) $c->propina), 2), 'permanencia' => (int) round($mins->avg() ?? 0)],
            'por_mozo' => $porMozo, 'por_hora' => $porHora, 'por_tipo' => $porTipo, 'platos' => $platos, 'cocina' => $cocina,
            'como' => 'Comandas cerradas en el período (fecha de cierre). Ticket promedio = total / comandas; por cubierto = total / cubiertos. Tiempo de cocina = de que se envió a que se marcó listo. Permanencia = de que se abrió la mesa a que se cerró.',
        ];
    }

    // Fase 56: el bloque de Big Restó para el parte del dueño (lo de ayer) y, con IA, una línea con qué conviene cambiar.
    public function parteDelDia(\App\Models\Business $b, Carbon $dia): ?string
    {
        $d = $this->datos($dia, $dia); if (! $d['kpis']['comandas']) return null;
        $f = fn($v) => '$ ' . number_format($v, 0, ',', '.'); $k = $d['kpis'];
        $t = "🍽️ *Big Restó*: {$f($k['total'])} en {$k['comandas']} mesas/pedidos · ticket {$f($k['ticket'])} · {$k['cubiertos']} cubiertos · propinas {$f($k['propinas'])}\n";
        if ($d['platos']) $t .= '⭐ Plato estrella: ' . $d['platos'][0]['plato'] . ' (' . rtrim(rtrim(number_format($d['platos'][0]['unidades'], 1, ',', '.'), '0'), ',') . ")\n";
        if ($d['cocina']) { $lenta = collect($d['cocina'])->sortByDesc('promedio')->first(); $t .= "⏱️ Cocina: {$lenta['estacion']} tardó {$lenta['promedio']}' en promedio (máx {$lenta['maximo']}')\n"; }
        $ops = \App\Models\RestoOpinion::withoutGlobalScopes()->where('business_id', $b->id)->whereDate('created_at', $dia)->get();
        if ($ops->count()) $t .= '💬 Opiniones: ' . round($ops->avg('puntaje'), 1) . "★ en {$ops->count()}" . ($ops->where('puntaje', '<=', 2)->count() ? ' · ' . $ops->where('puntaje', '<=', 2)->count() . ' mala/s' : '') . "\n";
        if (config('services.anthropic.api_key')) {
            try {
                $res = \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(20)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 120,
                    'system' => 'Sos el encargado de un restaurante argentino. Con los números del día, decile al dueño en UNA sola frase (máximo 25 palabras, español rioplatense, sin emojis) qué conviene cambiar o cuidar mañana. Sin inventar datos.', 'messages' => [['role' => 'user', 'content' => $t . "\nPor hora: " . collect($d['por_hora'])->map(fn($h) => "{$h['hora']}h {$h['comandas']}")->implode(', ') . "\nPor mozo: " . collect($d['por_mozo'])->map(fn($m) => "{$m['mozo']} {$f($m['total'])}")->implode(', ')]]]);
                $linea = trim(collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
                if ($linea) $t .= '💡 ' . mb_substr($linea, 0, 200) . "\n";
            } catch (\Throwable) {}
        }
        return trim($t);
    }
}
