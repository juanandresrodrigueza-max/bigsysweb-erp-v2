<?php

namespace App\Services\Gastronomia;

use App\Models\Business;
use App\Models\ComandaItem;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 56 · Ingeniería de menú: cada plato por popularidad y margen (el método clásico de Kasavana y Smith).
// Estrellas (populares y rinden), caballitos (populares, poco margen), rompecabezas (rinden, salen poco) y perros (ni una cosa ni la otra), con qué hacer con cada uno.
class IngenieriaMenuService
{
    public function matriz(Business $b, int $dias = 30): array
    {
        $its = ComandaItem::withoutGlobalScopes()->join('comandas', 'comandas.id', '=', 'comanda_items.comanda_id')->where('comandas.business_id', $b->id)->where('comandas.estado', 'cerrada')->where('comandas.cerrada_en', '>=', now()->subDays($dias))
            ->where('comanda_items.estado', '!=', 'anulado')->whereNotNull('comanda_items.product_id')->get(['comanda_items.product_id', 'comanda_items.cantidad', 'comanda_items.precio_unit', 'comanda_items.alicuota_iva']);
        if ($its->isEmpty()) return ['platos' => [], 'dias' => $dias, 'como' => self::COMO];
        $prods = Product::withoutGlobalScopes()->whereIn('id', $its->pluck('product_id')->unique())->get()->keyBy('id');
        $platos = $its->groupBy('product_id')->map(function ($g, $pid) use ($prods) {
            $p = $prods[$pid] ?? null; if (! $p) return null;
            $u = (float) $g->sum('cantidad'); $precioNeto = $g->avg(fn($i) => (float) $i->precio_unit / (1 + (float) $i->alicuota_iva / 100));
            $costo = RecetaVentaService::costo($p); $margen = round($precioNeto - $costo, 2);
            return ['id' => $pid, 'plato' => $p->name, 'unidades' => round($u, 1), 'precio' => round($precioNeto, 2), 'costo' => $costo, 'margen' => $margen, 'margen_pct' => $precioNeto > 0 ? round($margen / $precioNeto * 100, 1) : null, 'contribucion' => round($margen * $u, 2), 'sin_costo' => $costo <= 0];
        })->filter()->values();
        $n = $platos->count(); $totalU = $platos->sum('unidades');
        $umbralPop = $n ? ($totalU / $n) * 0.7 : 0; // populares: al menos el 70 % de la venta promedio por plato
        $margenMedio = $totalU > 0 ? $platos->sum('contribucion') / $totalU : 0;
        $platos = $platos->map(function ($p) use ($umbralPop, $margenMedio) {
            $pop = $p['unidades'] >= $umbralPop; $rinde = $p['margen'] >= $margenMedio;
            [$clase, $accion] = match (true) {
                $p['sin_costo'] => ['sin_costo', 'Cargale la receta o el costo para saber si rinde.'],
                $pop && $rinde => ['estrella', 'Destacalo en la carta y en el QR; cuidá la calidad, es tu plato bandera.'],
                $pop && ! $rinde => ['caballito', 'Sale mucho pero deja poco: subí el precio de a poco, achicá la porción o cambiá un insumo caro de la receta.'],
                ! $pop && $rinde => ['rompecabezas', 'Deja buen margen pero pocos lo piden: ponelo arriba en la carta, con foto, como sugerencia del mozo o en un combo.'],
                default => ['perro', 'Ni sale ni rinde: sacalo de la carta o rehacelo.'],
            };
            return $p + ['clase' => $clase, 'accion' => $accion];
        })->sortByDesc('contribucion')->values();
        return ['platos' => $platos->all(), 'umbral_popular' => round($umbralPop, 1), 'margen_medio' => round($margenMedio, 2), 'dias' => $dias,
            'resumen' => collect(['estrella', 'caballito', 'rompecabezas', 'perro', 'sin_costo'])->mapWithKeys(fn($c) => [$c => $platos->where('clase', $c)->count()])->all(), 'como' => self::COMO];
    }

    public const COMO = 'Popular = vende al menos el 70 % del promedio por plato. Rinde = su margen por unidad (precio neto − costo de la receta) está en o sobre el margen medio ponderado. Contribución = margen × unidades.';

    // Con IA: tres acciones concretas para esta semana, con números. Sin clave, las acciones por clase alcanzan.
    public function acciones(Business $b, ?array $m = null): array
    {
        $m ??= $this->matriz($b); if (! $m['platos'] || ! config('services.anthropic.api_key')) return [];
        try {
            $lista = collect($m['platos'])->take(40)->map(fn($p) => "{$p['plato']}: {$p['unidades']} u, precio neto {$p['precio']}, costo {$p['costo']}, margen {$p['margen']} ({$p['margen_pct']} %), clase {$p['clase']}")->implode("\n");
            $res = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 500,
                'system' => "Sos consultor gastronómico de {$b->name} (Argentina). Con la ingeniería de menú de los últimos {$m['dias']} días proponé exactamente 3 acciones concretas para esta semana, cada una con el plato, qué hacer y el número (precio nuevo, cuánto más de margen, qué sacar). Devolvé SOLO JSON {\"acciones\":[\"...\",\"...\",\"...\"]}. Español rioplatense, sin inventar datos.",
                'messages' => [['role' => 'user', 'content' => $lista]],
            ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $d = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            return is_array($d) ? array_values(array_filter((array) ($d['acciones'] ?? []), 'is_string')) : [];
        } catch (\Throwable $e) { Log::warning('Ingeniería menú IA: ' . $e->getMessage()); return []; }
    }
}
