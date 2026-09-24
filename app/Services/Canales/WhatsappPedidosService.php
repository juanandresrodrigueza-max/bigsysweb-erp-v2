<?php

namespace App\Services\Canales;

use App\Models\Business;
use App\Models\Contact;
use App\Models\PedidoWeb;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Pedidos por WhatsApp: el mensaje ("hola, me mandás 10 bolsas de cemento y 3 hierros del 8 a Colón 1234") se convierte en pedido.
// Con clave de Anthropic lo interpreta la IA; sin clave, un parser por cantidad + nombre de artículo.
class WhatsappPedidosService
{
    public function __construct(private TiendaService $tienda) {}

    public function interpretar(Business $b, string $texto): array
    {
        $catalogo = Product::withoutGlobalScopes()->where('business_id', $b->id)->where('active', true)->where('en_tienda', true)->whereIn('tipo', ['producto', 'elaborado', 'servicio'])->get(['id', 'name', 'sku', 'unit', 'price']);
        $res = config('services.anthropic.api_key') ? $this->conIa($b, $texto, $catalogo) : null;
        $res ??= $this->heuristico($texto, $catalogo);
        $res['texto'] = $texto;
        return $res;
    }

    private function conIa(Business $b, string $texto, $catalogo): ?array
    {
        try {
            $lista = $catalogo->map(fn($p) => "{$p->id}|{$p->name}|{$p->unit}")->implode("\n");
            $r = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 800,
                'system' => "Sos el tomador de pedidos de {$b->name}. Recibís un mensaje de WhatsApp de un cliente y devolvés SOLO un JSON con: items (lista de {id, cantidad}) usando ÚNICAMENTE ids del catálogo, direccion (string o null), entrega ('envio' si menciona dirección o que se lo lleven, si no 'retiro'), nombre (si se presenta), notas (lo que no pudiste mapear), respuesta (un mensaje corto y amable en español rioplatense confirmando lo entendido y pidiendo lo que falte). Catálogo (id|nombre|unidad):\n{$lista}",
                'messages' => [['role' => 'user', 'content' => $texto]],
            ]);
            if (! $r->successful()) return null;
            $t = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $j = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $t)), true);
            if (! is_array($j) || ! isset($j['items'])) return null;
            $items = collect($j['items'])->map(function ($i) use ($catalogo) { $p = $catalogo->firstWhere('id', (int) ($i['id'] ?? 0)); return $p ? ['product_id' => $p->id, 'descripcion' => $p->name, 'cantidad' => max(0.001, (float) ($i['cantidad'] ?? 1)), 'unit' => $p->unit] : null; })->filter()->values()->all();
            return ['items' => $items, 'direccion' => $j['direccion'] ?? null, 'entrega' => $j['entrega'] ?? ($j['direccion'] ?? null ? 'envio' : 'retiro'), 'nombre' => $j['nombre'] ?? null, 'notas' => $j['notas'] ?? null, 'respuesta' => $j['respuesta'] ?? null, 'modo' => 'ia'];
        } catch (\Throwable $e) { Log::warning('WhatsApp IA: ' . $e->getMessage()); return null; }
    }

    // Parser simple: "10 cemento, 3 hierro 8, 2 m3 arena" → busca cantidad seguida de palabras que coincidan con un artículo.
    private function heuristico(string $texto, $catalogo): array
    {
        $norm = fn($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s)));
        $items = []; $noMapeado = [];
        $partes = preg_split('/[,\n;]+| y (?=\d)/u', $texto);
        foreach ($partes as $parte) {
            $parte = trim($parte); if ($parte === '') continue;
            if (! preg_match('/(\d+(?:[.,]\d+)?)\s*(?:x|un|unidades|bolsas?|kg|m3|m³|metros|litros|cajas?|u\.?)?\s*(?:de\s+)?([^\d]+?)\s*$/iu', $parte, $m)) { $noMapeado[] = $parte; continue; }
            $cant = (float) str_replace(',', '.', $m[1]); $desc = $norm($m[2]);
            $mejor = null; $score = 0;
            foreach ($catalogo as $p) {
                $n = $norm($p->name); $pal = array_filter(explode(' ', $desc), fn($w) => mb_strlen($w) > 2);
                $s = 0; foreach ($pal as $w) if (str_contains($n, $w)) $s += mb_strlen($w);
                if (preg_match('/\b(\d+)\b/', $desc, $num) && str_contains($n, $num[1])) $s += 3;
                if ($s > $score) { $score = $s; $mejor = $p; }
            }
            if ($mejor && $score >= 3) $items[] = ['product_id' => $mejor->id, 'descripcion' => $mejor->name, 'cantidad' => $cant, 'unit' => $mejor->unit];
            else $noMapeado[] = $parte;
        }
        $dir = null; if (preg_match('/(?:a|en|direcci[oó]n:?)\s+((?:av\.?|calle|ruta|bv\.?|boulevard)?\s*[a-záéíóúñ\. ]+\s+\d{1,5}[^,\n]*)/iu', $texto, $d)) $dir = trim($d[1]);
        $nombre = null; if (preg_match('/(?:soy|me llamo|de parte de)\s+([a-záéíóúñ ]{3,40})/iu', $texto, $nm)) $nombre = trim($nm[1]);
        $resp = $items ? 'Anotado: ' . collect($items)->map(fn($i) => rtrim(rtrim(number_format($i['cantidad'], 2, ',', '.'), '0'), ',') . ' ' . $i['descripcion'])->implode(', ') . '. ' . ($dir ? "Lo llevamos a {$dir}. " : '¿Lo retirás o te lo enviamos? ') . 'Te confirmamos el total en un momento.' : 'No pude identificar los artículos. ¿Me decís cantidad y producto? Ej: "10 cemento, 3 hierro 8".';
        return ['items' => $items, 'direccion' => $dir, 'entrega' => $dir ? 'envio' : 'retiro', 'nombre' => $nombre, 'notas' => $noMapeado ? 'Sin identificar: ' . implode(' | ', $noMapeado) : null, 'respuesta' => $resp, 'modo' => 'basico'];
    }

    // Crea el pedido a partir de la interpretación (desde el webhook o desde la pantalla de pedidos).
    public function crearDesdeTexto(Business $b, string $texto, ?string $telefono = null, ?array $interp = null): ?PedidoWeb
    {
        $i = $interp ?? $this->interpretar($b, $texto);
        if (! $i['items']) return null;
        $contact = $telefono ? Contact::withoutGlobalScopes()->where('business_id', $b->id)->where('type', 'customer')->whereRaw("replace(replace(replace(coalesce(phone,''),'-',''),' ',''),'+','') like ?", ['%' . substr(preg_replace('/\D/', '', $telefono), -8)])->first() : null;
        return $this->tienda->crearPedido($b, ['items' => $i['items'], 'cliente' => ['nombre' => $i['nombre'] ?? $contact?->name ?? ('WhatsApp ' . ($telefono ?: '')), 'telefono' => $telefono, 'direccion' => $i['direccion'] ?? $contact?->address, 'notas' => $i['notas'] ?? null], 'entrega' => $i['entrega'] ?? 'retiro', 'pago' => 'a_convenir', 'texto_original' => $texto], 'whatsapp', $contact);
    }

    // Responde por la API de WhatsApp Cloud si está configurada; si no, deja el texto listo para copiar.
    public function responder(Business $b, string $telefono, string $texto): array
    {
        $ws = $b->whatsapp_settings ?? [];
        if (! empty($ws['token']) && ! empty($ws['phone_id'])) {
            try {
                $r = Http::withToken($ws['token'])->timeout(10)->post("https://graph.facebook.com/v19.0/{$ws['phone_id']}/messages", ['messaging_product' => 'whatsapp', 'to' => preg_replace('/\D/', '', $telefono), 'type' => 'text', 'text' => ['body' => $texto]]);
                return ['enviado' => $r->successful(), 'link' => null];
            } catch (\Throwable $e) {}
        }
        return ['enviado' => false, 'link' => 'https://wa.me/' . preg_replace('/\D/', '', $telefono) . '?text=' . rawurlencode($texto)];
    }
}
