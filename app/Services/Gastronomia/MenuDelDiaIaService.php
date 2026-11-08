<?php

namespace App\Services\Gastronomia;

use App\Models\Business;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Services\Stock\LotesService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 55: el menú del día sugerido con lo que está por vencer o sobra. Con IA propone los menús; sin IA, lista con qué armarlo.
class MenuDelDiaIaService
{
    // Insumos a usar primero: lotes que vencen en 5 días y lo que está quieto hace 14 días con stock.
    public function aUsar(Business $b): array
    {
        $out = [];
        foreach (app(LotesService::class)->porVencer(5) as $l) if ($l->product) $out[$l->product_id] = ['id' => $l->product_id, 'insumo' => $l->product->name, 'cantidad' => (float) $l->cantidad, 'unit' => $l->product->unit, 'motivo' => 'vence ' . $l->vencimiento->format('d/m')];
        $quietos = Product::where('active', true)->whereIn('tipo', ['insumo', 'producto'])->where('controla_stock', true)->where('stock', '>', 0)->whereNotIn('id', array_keys($out))->get()
            ->filter(fn($p) => ! StockMovement::where('product_id', $p->id)->where('created_at', '>=', now()->subDays(14))->where('type', 'out')->exists())->take(15);
        foreach ($quietos as $p) $out[$p->id] = ['id' => $p->id, 'insumo' => $p->name, 'cantidad' => (float) $p->stock, 'unit' => $p->unit, 'motivo' => 'sin salida hace 14 días'];
        $usan = Recipe::where('is_active', true)->whereHas('items', fn($q) => $q->whereIn('product_id', array_keys($out)))->with('product:id,name,price', 'items')->get();
        foreach ($out as $id => &$x) $x['platos'] = $usan->filter(fn($r) => $r->items->contains('product_id', $id))->map(fn($r) => $r->product?->name)->filter()->values()->all();
        return array_values($out);
    }

    public function sugerir(Business $b): array
    {
        $usar = $this->aUsar($b);
        if (! $usar) return ['ok' => true, 'usar' => [], 'menus' => [], 'aviso' => 'No hay nada por vencer ni quieto: armá el menú del día con lo que más margen te deja.'];
        $key = config('services.anthropic.api_key');
        if (! $key) return ['ok' => true, 'usar' => $usar, 'menus' => [], 'aviso' => 'Con la clave de IA cargada, Bigcito te propone los menús. Mientras tanto, esto es lo que conviene usar primero.'];
        $carta = Product::where('active', true)->where('en_tienda', true)->whereNotIn('tipo', ['insumo'])->orderByDesc('favorito_pos')->limit(60)->get(['name', 'price'])->map(fn($p) => $p->name . ' ($ ' . number_format((float) $p->price, 0, ',', '.') . ')')->implode(', ');
        $lista = collect($usar)->map(fn($x) => "{$x['insumo']} ({$x['cantidad']} {$x['unit']}, {$x['motivo']})" . ($x['platos'] ? ' · ya lo usan: ' . implode(', ', $x['platos']) : ''))->implode("\n");
        try {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 900,
                'system' => "Sos el jefe de cocina de {$b->name}, un local gastronómico argentino. Hoy es " . now()->locale('es')->translatedFormat('l d/m') . ". Proponé 3 menús del día que usen primero estos insumos (por vencer o que sobran), aprovechando platos que ya están en la carta cuando se pueda. Devolvé SOLO JSON: {\"menus\":[{\"nombre\":\"\",\"platos\":[\"entrada o principal\",\"...\"],\"usa\":[\"insumo\"],\"precio_sugerido\":0,\"pizarra\":\"texto corto y vendedor para la pizarra\"}]}. Precios en pesos, coherentes con la carta. Nunca inventes insumos que no estén en la lista.\nCarta actual: {$carta}\nInsumos a usar primero:\n{$lista}",
                'messages' => [['role' => 'user', 'content' => 'Proponé los menús del día.']],
            ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $d = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            if (! is_array($d) || empty($d['menus'])) throw new \RuntimeException('sin menús: ' . mb_substr($raw, 0, 150));
        } catch (\Throwable $e) { Log::warning('Menú del día IA: ' . $e->getMessage()); return ['ok' => true, 'usar' => $usar, 'menus' => [], 'aviso' => 'No pude pensar los menús ahora. Esto es lo que conviene usar primero.']; }
        $menus = collect($d['menus'])->take(3)->map(fn($m) => ['nombre' => (string) ($m['nombre'] ?? 'Menú del día'), 'platos' => array_values(array_filter((array) ($m['platos'] ?? []), 'is_string')), 'usa' => array_values(array_filter((array) ($m['usa'] ?? []), 'is_string')), 'precio_sugerido' => round((float) ($m['precio_sugerido'] ?? 0), 2), 'pizarra' => (string) ($m['pizarra'] ?? '')])->values()->all();
        return ['ok' => true, 'usar' => $usar, 'menus' => $menus, 'aviso' => null];
    }
}
