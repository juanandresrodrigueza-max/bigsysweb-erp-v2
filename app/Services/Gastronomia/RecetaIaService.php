<?php

namespace App\Services\Gastronomia;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 53: la IA propone la receta de un plato con los insumos que la empresa ya tiene cargados. El dueño la ajusta y la guarda.
class RecetaIaService
{
    public function sugerir(Business $b, Product $plato): array
    {
        $key = config('services.anthropic.api_key');
        if (! $key) return ['ok' => false, 'aviso' => 'Para sugerir recetas con IA hay que cargar la clave de IA en Configuración → IA.'];
        $insumos = Product::where('active', true)->whereIn('tipo', ['insumo', 'producto'])->where('id', '!=', $plato->id)->orderBy('name')->limit(400)->get(['id', 'name', 'unit', 'cost']);
        if ($insumos->isEmpty()) return ['ok' => false, 'aviso' => 'Primero cargá los insumos (café, leche, harina…) en Stock. Después la IA arma la receta con ellos.'];
        $lista = $insumos->map(fn($p) => "{$p->id}|{$p->name}|{$p->unit}")->implode("\n");
        try {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 700,
                'system' => "Sos el jefe de cocina de {$b->name}, un local gastronómico argentino. Te dan un plato y la lista de insumos cargados (id|nombre|unidad). Proponé la receta para UNA porción usando SOLO ids de la lista, con cantidades típicas en la unidad de cada insumo (kg → 0.25, l → 0.2, un → 1). Devolvé SOLO JSON: {\"items\":[{\"id\":1,\"cantidad\":0.2}],\"nota\":\"una línea con lo que falta cargar como insumo si hace falta\"}. Si un ingrediente importante no está en la lista, no lo inventes: nombralo en nota.\nInsumos:\n{$lista}",
                'messages' => [['role' => 'user', 'content' => "Plato: {$plato->name}" . ($plato->description ? " ({$plato->description})" : '')]],
            ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $d = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            if (! is_array($d) || empty($d['items'])) throw new \RuntimeException('sin items: ' . mb_substr($raw, 0, 150));
        } catch (\Throwable $e) { Log::warning('Receta IA: ' . $e->getMessage()); return ['ok' => false, 'aviso' => 'No pude armar la receta. Probá de nuevo o cargala a mano.']; }
        $items = collect($d['items'])->map(function ($i) use ($insumos) { $p = $insumos->firstWhere('id', (int) ($i['id'] ?? 0)); return $p && (float) ($i['cantidad'] ?? 0) > 0 ? ['product_id' => $p->id, 'nombre' => $p->name, 'quantity' => round((float) $i['cantidad'], 4), 'unit' => $p->unit, 'costo' => round((float) $i['cantidad'] * (float) $p->cost, 2)] : null; })->filter()->values();
        return ['ok' => true, 'items' => $items->all(), 'costo' => round($items->sum('costo'), 2), 'nota' => $d['nota'] ?? null];
    }
}
