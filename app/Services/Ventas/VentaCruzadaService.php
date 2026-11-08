<?php

namespace App\Services\Ventas;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Product;
use App\Support\Ia;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 63 · Venta cruzada: con lo que ya está en la venta o el presupuesto, qué conviene ofrecer. Primero lo que dicen las ventas de la
// casa (qué se llevó junto con qué, y qué suele llevar este cliente); si todavía no hay historia, la IA elige complementos del catálogo.
class VentaCruzadaService
{
    public const DIAS = 180;
    public const MIN_VECES = 2;

    // Pares "se llevó junto": por cada artículo, cuántas veces salió en la misma venta con cada otro. Cacheado 6 horas por empresa.
    public function pares(Business $b): array
    {
        return Cache::remember("vcruzada:{$b->id}", now()->addHours(6), function () use ($b) {
            $ids = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->ventas()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'FX', 'REM', 'PRE'])->where('fecha', '>=', now()->subDays(self::DIAS))->orderByDesc('id')->limit(4000)->pluck('id');
            $porVenta = ComprobanteItem::whereIn('comprobante_id', $ids)->whereNotNull('product_id')->get(['comprobante_id', 'product_id'])->groupBy('comprobante_id')->map(fn($g) => $g->pluck('product_id')->unique()->values()->all());
            $pares = [];
            foreach ($porVenta as $lista) { if (count($lista) < 2 || count($lista) > 40) continue; foreach ($lista as $a) foreach ($lista as $c) if ($a !== $c) $pares[$a][$c] = ($pares[$a][$c] ?? 0) + 1; }
            foreach ($pares as &$cs) { arsort($cs); $cs = array_slice($cs, 0, 15, true); }
            return $pares;
        });
    }

    public function olvidar(Business $b): void { Cache::forget("vcruzada:{$b->id}"); }

    // Lo que este cliente lleva seguido (últimos 12 meses) y hoy no está en la venta.
    private function delCliente(Business $b, int $contactId, array $enVenta): array
    {
        $ids = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('contact_id', $contactId)->ventas()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'FX', 'REM'])->where('fecha', '>=', now()->subYear())->pluck('id');
        if ($ids->isEmpty()) return [];
        return ComprobanteItem::whereIn('comprobante_id', $ids)->whereNotNull('product_id')->whereNotIn('product_id', $enVenta)->selectRaw('product_id, count(distinct comprobante_id) as veces')->groupBy('product_id')->havingRaw('count(distinct comprobante_id) >= ?', [self::MIN_VECES])->orderByRaw('count(distinct comprobante_id) desc, product_id')->limit(6)->pluck('veces', 'product_id')->all(); // havingRaw: PostgreSQL no acepta el alias en HAVING
    }

    // Las sugerencias para una venta: [{product_id, nombre, precio, motivo, veces, tipo}] ordenadas por fuerza.
    public function sugerir(Business $b, array $productIds, ?int $contactId = null, int $max = 4): array
    {
        $enVenta = array_values(array_unique(array_map('intval', array_filter($productIds))));
        if (! $enVenta) return [];
        $pares = $this->pares($b); $cand = [];
        foreach ($enVenta as $a) foreach ($pares[$a] ?? [] as $c => $n) if (! in_array($c, $enVenta, true) && $n >= self::MIN_VECES) { $cand[$c] ??= ['veces' => 0, 'con' => $a]; if ($n > $cand[$c]['veces']) { $cand[$c]['veces'] = $n; $cand[$c]['con'] = $a; } $cand[$c]['puntos'] = ($cand[$c]['puntos'] ?? 0) + $n; }
        $cliente = $contactId ? $this->delCliente($b, $contactId, $enVenta) : [];
        $ids = array_unique(array_merge(array_keys($cand), array_keys($cliente), $enVenta));
        $prods = Product::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('id', $ids)->where('active', true)->get()->keyBy('id');
        $out = [];
        foreach ($cliente as $pid => $veces) if (($p = $prods[$pid] ?? null) && $this->hay($p)) $out[$pid] = ['product_id' => $p->id, 'nombre' => $p->name, 'precio' => (float) $p->price, 'motivo' => "Este cliente lo lleva seguido ({$veces} veces en el año)", 'veces' => (int) $veces, 'puntos' => 100 + $veces, 'tipo' => 'cliente'];
        foreach ($cand as $pid => $c) if (! isset($out[$pid]) && ($p = $prods[$pid] ?? null) && $this->hay($p)) $out[$pid] = ['product_id' => $p->id, 'nombre' => $p->name, 'precio' => (float) $p->price, 'motivo' => 'Los que llevan ' . ($prods[$c['con']]->name ?? 'eso') . " también llevan esto ({$c['veces']} veces)", 'veces' => $c['veces'], 'puntos' => $c['puntos'], 'tipo' => 'par'];
        usort($out, fn($x, $y) => [$y['puntos'], $x['nombre']] <=> [$x['puntos'], $y['nombre']]);
        $out = array_slice(array_values($out), 0, $max);
        if (! $out && Ia::tiene($b)) $out = $this->conIa($b, $prods->only($enVenta)->values(), $max);
        return array_map(fn($s) => array_diff_key($s, ['puntos' => 1]), $out);
    }

    private function hay(Product $p): bool { return in_array($p->tipo, ['producto', 'elaborado', 'servicio'], true) && (! $p->controla_stock || (float) $p->stock > 0); }

    // Sin historia todavía: la IA elige del catálogo lo que complementa lo que se está vendiendo (cacheado un día por combinación).
    private function conIa(Business $b, $enVenta, int $max): array
    {
        if ($enVenta->isEmpty()) return [];
        $clave = 'vcruzada-ia:' . $b->id . ':' . md5($enVenta->pluck('id')->sort()->implode(','));
        return Cache::remember($clave, now()->addDay(), function () use ($b, $enVenta, $max) {
            Ia::aplicar($b);
            $rubros = $enVenta->pluck('rubro_id')->filter()->unique();
            $cat = Product::withoutGlobalScopes()->where('business_id', $b->id)->where('active', true)->whereIn('tipo', ['producto', 'elaborado', 'servicio'])->whereNotIn('id', $enVenta->pluck('id'))
                ->where(fn($q) => $q->where('controla_stock', false)->orWhere('stock', '>', 0))->when($rubros->isNotEmpty(), fn($q) => $q->orderByRaw('CASE WHEN rubro_id IN (' . $rubros->implode(',') . ') THEN 0 ELSE 1 END'))->orderBy('name')->limit(400)->get(['id', 'name', 'price']);
            if ($cat->isEmpty()) return [];
            try {
                $r = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(25)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 300,
                    'system' => "Sos el vendedor con más calle de {$b->name}" . ($b->vertical ? " ({$b->vertical})" : '') . ". Te muestro lo que el cliente ya lleva y el catálogo. Elegí hasta {$max} artículos del catálogo que de verdad complementan la compra (lo que hace falta para usar o instalar lo que lleva, el consumible, el accesorio), nunca lo mismo en otra medida. Devolvé SOLO JSON: {\"sugeridos\":[{\"id\":12,\"motivo\":\"máx 10 palabras, en voz de vendedor\"}]}.",
                    'messages' => [['role' => 'user', 'content' => "Lleva:\n" . $enVenta->map(fn($p) => "- {$p->name}")->implode("\n") . "\n\nCatálogo (id|nombre):\n" . $cat->map(fn($p) => "{$p->id}|{$p->name}")->implode("\n")]]]);
                $j = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''))), true);
                $out = [];
                foreach ((array) ($j['sugeridos'] ?? []) as $s) if (($p = $cat->firstWhere('id', (int) ($s['id'] ?? 0))) && count($out) < $max) $out[] = ['product_id' => $p->id, 'nombre' => $p->name, 'precio' => (float) $p->price, 'motivo' => mb_substr(trim((string) ($s['motivo'] ?? 'Va bien con lo que lleva')), 0, 120), 'veces' => 0, 'puntos' => 1, 'tipo' => 'ia'];
                return $out;
            } catch (\Throwable $e) { Log::warning('Venta cruzada IA: ' . $e->getMessage()); return []; }
        });
    }
}
