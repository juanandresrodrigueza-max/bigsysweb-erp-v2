<?php

namespace App\Services\Comprobantes;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Interpreta un pedido (texto pegado de WhatsApp o foto) y lo convierte en ítems del catálogo.
class PresupuestoIAService
{
    public function interpretar(?string $texto, ?string $imagenBase64 = null, ?string $mime = null, int $lista = 1): array
    {
        $catalogo = Product::where('active', true)->orderBy('name')->get(['id', 'name', 'sku', 'price', 'prices', 'unit', 'iva']);
        $apiKey = config('services.anthropic.api_key');

        if (! $apiKey) {
            return $this->interpretarLocal((string) $texto, $catalogo, $lista);
        }

        $catTxt = $catalogo->map(fn($p) => "{$p->id}|{$p->sku}|{$p->name}|{$p->unit}")->implode("\n");
        $system = <<<TXT
Sos el asistente de carga de presupuestos de un ERP argentino. Te dan un pedido de un cliente (texto de WhatsApp o foto de una nota) y el catálogo de artículos de la empresa con formato id|sku|nombre|unidad.
Devolvé SOLO un JSON con esta forma, sin texto adicional:
{"items":[{"product_id":123 o null,"descripcion":"texto tal como lo pidió","cantidad":número,"confianza":0.0-1.0}],"observaciones":"lo que no entendiste o pedidos sin artículo"}
Reglas: matcheá por nombre, sinónimos y abreviaturas comunes de corralón/ferretería/comercio argentino (ej. "bolsa de portland" = cemento, "hierro del 8" = hierro 8 mm). Si dudás, product_id null y confianza baja. Nunca inventes artículos. Cantidad por defecto 1.
Catálogo:
{$catTxt}
TXT;

        $content = [];
        if ($imagenBase64) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime ?: 'image/jpeg', 'data' => $imagenBase64]];
        }
        $content[] = ['type' => 'text', 'text' => $texto ?: 'Interpretá el pedido de la imagen.'];

        try {
            $res = Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])->timeout(60)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.model'), 'max_tokens' => 1500, 'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $content]],
                ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $json = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            if (! is_array($json) || ! isset($json['items'])) {
                throw new \RuntimeException('Respuesta no interpretable');
            }
        } catch (\Throwable $e) {
            Log::warning('PresupuestoIA', ['e' => $e->getMessage()]);
            return $this->interpretarLocal((string) $texto, $catalogo, $lista) + ['aviso' => 'La IA no respondió; se usó el reconocimiento básico.'];
        }

        return ['items' => $this->completar($json['items'], $catalogo, $lista), 'observaciones' => $json['observaciones'] ?? null, 'modo' => 'ia'];
    }

    // Sin IA: busca "cantidad + palabras" línea por línea contra el catálogo.
    private function interpretarLocal(string $texto, $catalogo, int $lista): array
    {
        $items = [];
        foreach (preg_split('/\r?\n|,|;/', $texto) as $linea) {
            $linea = trim($linea);
            if ($linea === '') {
                continue;
            }
            $cant = 1.0;
            if (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(?:x|un|u|bolsas?|unid\w*)?\s*(?:de\s+)?(.+)$/iu', $linea, $m)) {
                $cant = (float) str_replace(',', '.', $m[1]);
                $linea = trim($m[2]);
            } elseif (preg_match('/^(.+?)\s+x?\s*(\d+(?:[.,]\d+)?)\s*(?:un|u|bolsas?|unid\w*)?$/iu', $linea, $m)) {
                $cant = (float) str_replace(',', '.', $m[2]);
                $linea = trim($m[1]);
            }
            $palabras = collect(preg_split('/\s+/u', mb_strtolower($linea)))->filter(fn($w) => mb_strlen($w) >= 3 || ctype_digit($w))->values();
            $mejor = null; $score = 0;
            foreach ($catalogo as $p) {
                $nombre = ' ' . mb_strtolower($p->name . ' ' . $p->sku) . ' ';
                // Las palabras suman 1; un número que aparece como token exacto (ej. "8" en "8 mm") suma 2.
                $hits = $palabras->sum(fn($w) => ctype_digit($w) ? (preg_match('/\b' . preg_quote($w, '/') . '\b/', $nombre) ? 2 : 0) : (str_contains($nombre, $w) ? 1 : 0));
                if ($hits > $score) { $score = $hits; $mejor = $p; }
            }
            $items[] = ['product_id' => $score > 0 ? $mejor->id : null, 'descripcion' => $linea, 'cantidad' => $cant, 'confianza' => $score > 0 ? min(1, 0.4 + 0.3 * $score) : 0];
        }
        return ['items' => $this->completar($items, $catalogo, $lista), 'observaciones' => null, 'modo' => 'local'];
    }

    private function completar(array $items, $catalogo, int $lista): array
    {
        $porId = $catalogo->keyBy('id');
        return collect($items)->map(function ($i) use ($porId, $lista) {
            $p = ! empty($i['product_id']) ? $porId->get((int) $i['product_id']) : null;
            return [
                'product_id' => $p?->id, 'descripcion' => $p?->name ?? ($i['descripcion'] ?? ''), 'pedido' => $i['descripcion'] ?? '',
                'cantidad' => (float) ($i['cantidad'] ?? 1), 'unidad' => $p?->unit, 'precio_unit' => $p ? $p->precioLista($lista) : 0,
                'alicuota_iva' => $p ? (float) $p->iva : 21, 'confianza' => round((float) ($i['confianza'] ?? 0), 2),
            ];
        })->values()->all();
    }
}
