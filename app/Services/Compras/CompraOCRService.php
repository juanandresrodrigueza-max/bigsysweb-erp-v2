<?php

namespace App\Services\Compras;

use App\Models\Contact;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Lee una factura de compra (foto o PDF) con IA y devuelve los datos listos para el formulario.
class CompraOCRService
{
    public function leer(string $base64, string $mime): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            return ['ok' => false, 'aviso' => 'Para leer facturas con IA hay que configurar ANTHROPIC_API_KEY. Por ahora cargala a mano.'];
        }

        $catalogo = Product::where('active', true)->orderBy('name')->get(['id', 'name', 'sku', 'unit'])->map(fn($p) => "{$p->id}|{$p->sku}|{$p->name}|{$p->unit}")->implode("\n");
        $system = <<<TXT
Sos un lector de facturas de compra argentinas para un ERP. Extraé los datos del comprobante y devolvé SOLO JSON con esta forma:
{"proveedor":{"nombre":"","cuit":"30-12345678-9","condicion_iva":""},"tipo":"FA|FB|FC|NCA|NCB|NCC|NDA|NDB|NDC","numero":"0003-00012345","fecha":"YYYY-MM-DD","fecha_vto":"YYYY-MM-DD o null","cae":"",
"items":[{"product_id":123 o null,"descripcion":"","cantidad":1,"precio_unit":0,"alicuota_iva":21,"descuento":0}],
"neto":0,"iva":0,"percepciones":[{"tipo":"iibb_cba|iva|ganancias|otro","monto":0}],"total":0,"confianza":0.0-1.0,"observaciones":""}
Reglas: la letra del tipo es la letra grande del comprobante (A, B, C). En facturas B y C el precio unitario ya incluye IVA: pasalo a neto dividiendo por 1.21 y poné alicuota 21. Matcheá cada ítem con el catálogo (id|sku|nombre|unidad) solo si es claramente el mismo artículo; si no, product_id null. Nunca inventes datos: si algo no se lee, null.
Catálogo:
{$catalogo}
TXT;

        $bloque = str_starts_with($mime, 'application/pdf')
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $base64]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]];

        try {
            $res = Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])->timeout(90)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.model'), 'max_tokens' => 2500, 'system' => $system,
                    'messages' => [['role' => 'user', 'content' => [$bloque, ['type' => 'text', 'text' => 'Extraé los datos de esta factura.']]]],
                ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $json = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            if (! is_array($json) || ! isset($json['items'])) {
                throw new \RuntimeException('Respuesta no interpretable: ' . mb_substr($raw, 0, 200));
            }
        } catch (\Throwable $e) {
            Log::warning('CompraOCR', ['e' => $e->getMessage()]);
            return ['ok' => false, 'aviso' => 'No pude leer la factura. Probá con una foto más nítida o cargala a mano.'];
        }

        $cuit = isset($json['proveedor']['cuit']) ? preg_replace('/\D/', '', $json['proveedor']['cuit']) : null;
        $prov = $cuit ? Contact::suppliers()->get()->first(fn($c) => preg_replace('/\D/', '', (string) $c->cuit) === $cuit) : null;

        return ['ok' => true, 'datos' => $json, 'proveedor_id' => $prov?->id, 'proveedor_nuevo' => ! $prov && ! empty($json['proveedor']['nombre'])];
    }
}
