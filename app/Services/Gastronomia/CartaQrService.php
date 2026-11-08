<?php

namespace App\Services\Gastronomia;

use App\Models\Business;
use App\Models\ComandaItem;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 56 · La carta QR vende mejor: el agregado que más se pide junto con cada plato ("¿le sumás papas?") y la carta traducida para turistas.
class CartaQrService
{
    public const IDIOMAS = ['en' => 'English', 'pt' => 'Português'];

    // Para cada plato, el acompañante que más veces salió en la misma comanda (últimos 90 días, al menos 3 veces).
    public function acompanantes(Business $b): array
    {
        $its = ComandaItem::withoutGlobalScopes()->join('comandas', 'comandas.id', '=', 'comanda_items.comanda_id')->where('comandas.business_id', $b->id)->where('comandas.estado', 'cerrada')->where('comandas.cerrada_en', '>=', now()->subDays(90))
            ->where('comanda_items.estado', '!=', 'anulado')->whereNotNull('comanda_items.product_id')->get(['comanda_items.comanda_id', 'comanda_items.product_id'])->groupBy('comanda_id')->map(fn($g) => $g->pluck('product_id')->unique()->values());
        $pares = [];
        foreach ($its as $ids) foreach ($ids as $a) foreach ($ids as $c) if ($a !== $c) $pares[$a][$c] = ($pares[$a][$c] ?? 0) + 1;
        $out = [];
        foreach ($pares as $a => $cs) { arsort($cs); $top = array_key_first($cs); if ($cs[$top] >= 3) $out[$a] = ['id' => $top, 'veces' => $cs[$top]]; }
        return $out;
    }

    // Traduce con IA los platos publicados que todavía no tienen traducción en ese idioma. Queda guardado en el plato.
    public function traducir(Business $b, string $idioma): array
    {
        abort_unless(isset(self::IDIOMAS[$idioma]), 422);
        if (! config('services.anthropic.api_key')) return ['ok' => false, 'aviso' => 'Para traducir la carta hay que cargar la clave de IA en Configuración → IA.'];
        $faltan = Product::where('active', true)->where('en_tienda', true)->whereNotIn('tipo', ['insumo'])->get()->filter(fn($p) => empty($p->traducciones[$idioma]['nombre']))->take(120);
        if ($faltan->isEmpty()) return ['ok' => true, 'traducidos' => 0, 'aviso' => 'La carta ya está traducida a ' . self::IDIOMAS[$idioma] . '.'];
        $lista = $faltan->map(fn($p) => "{$p->id}|{$p->name}|" . str_replace("\n", ' ', (string) ($p->descripcion_tienda ?: $p->description)))->implode("\n");
        try {
            $res = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(90)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 4000,
                'system' => 'Traducís la carta de un restaurante argentino al idioma pedido, para turistas. Mantené los nombres propios de platos típicos (milanesa, empanada, chimichurri, dulce de leche) y aclaralos brevemente en la descripción si hace falta. Devolvé SOLO JSON: {"items":[{"id":1,"nombre":"","descripcion":""}]} con los mismos ids.',
                'messages' => [['role' => 'user', 'content' => "Idioma: " . self::IDIOMAS[$idioma] . "\nid|nombre|descripción:\n{$lista}"]],
            ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $d = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            if (! is_array($d) || empty($d['items'])) throw new \RuntimeException('sin items: ' . mb_substr($raw, 0, 150));
        } catch (\Throwable $e) { Log::warning('Traducir carta: ' . $e->getMessage()); return ['ok' => false, 'aviso' => 'No pude traducir ahora. Probá de nuevo en un rato.']; }
        $n = 0;
        foreach ($d['items'] as $it) { $p = $faltan->firstWhere('id', (int) ($it['id'] ?? 0)); if (! $p || empty($it['nombre'])) continue; $t = $p->traducciones ?? []; $t[$idioma] = ['nombre' => mb_substr(trim($it['nombre']), 0, 150), 'descripcion' => mb_substr(trim((string) ($it['descripcion'] ?? '')), 0, 500) ?: null]; $p->forceFill(['traducciones' => $t])->save(); $n++; }
        return ['ok' => true, 'traducidos' => $n, 'aviso' => "{$n} platos traducidos a " . self::IDIOMAS[$idioma] . '. En el QR aparece el botón para cambiar de idioma.'];
    }

    // Textos de la carta QR en cada idioma (lo que no es un plato).
    public static function textos(string $lang): array
    {
        return match ($lang) {
            'en' => ['pedir' => 'Order to the table →', 'llamar' => '🙋 Call the waiter', 'cuenta' => '🧾 Ask for the bill', 'agregar' => 'Add', 'sumar' => 'Add', 'volver' => 'Back', 'enviar' => 'Send to kitchen', 'tu_pedido' => 'Your order', 'nombre' => 'Your name (optional)', 'elegi' => 'Choose', 'sugerencia' => 'Goes well with', 'opinion' => 'How was it?', 'gracias' => 'Thanks! Your opinion helps us improve.', 'comentario' => 'Anything you want to tell us? (optional)', 'enviar_opinion' => 'Send'],
            'pt' => ['pedir' => 'Pedir para a mesa →', 'llamar' => '🙋 Chamar o garçom', 'cuenta' => '🧾 Pedir a conta', 'agregar' => 'Adicionar', 'sumar' => 'Adicionar', 'volver' => 'Voltar', 'enviar' => 'Enviar para a cozinha', 'tu_pedido' => 'Seu pedido', 'nombre' => 'Seu nome (opcional)', 'elegi' => 'Escolha', 'sugerencia' => 'Combina com', 'opinion' => 'Como foi?', 'gracias' => 'Obrigado! Sua opinião nos ajuda a melhorar.', 'comentario' => 'Quer nos contar algo? (opcional)', 'enviar_opinion' => 'Enviar'],
            default => ['pedir' => 'Pedir a la mesa →', 'llamar' => '🙋 Llamar al mozo', 'cuenta' => '🧾 Pedir la cuenta', 'agregar' => 'Agregar', 'sumar' => 'Sumar', 'volver' => 'Volver', 'enviar' => 'Enviar a cocina', 'tu_pedido' => 'Tu pedido', 'nombre' => 'Tu nombre (opcional)', 'elegi' => 'Elegí', 'sugerencia' => 'Va bien con', 'opinion' => '¿Cómo estuvo?', 'gracias' => '¡Gracias! Tu opinión nos ayuda a mejorar.', 'comentario' => '¿Algo que quieras contarnos? (opcional)', 'enviar_opinion' => 'Enviar'],
        };
    }
}
