<?php

namespace App\Services\Gastronomia;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Product;
use App\Models\Rubro;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Fase 53.2: la carta del local desde una foto, la pizarra o un PDF. La IA la lee; el dueño la revisa; se cargan rubros, platos, precios y opciones.
class CartaIaService
{
    public function leer(string $contenido, string $mime): array
    {
        $key = config('services.anthropic.api_key');
        if (! $key) return ['ok' => false, 'aviso' => 'Para leer la carta con IA hay que cargar la clave de IA en Configuración → IA.'];
        $bloque = $mime === 'application/pdf' ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($contenido)]] : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($contenido)]];
        try {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(90)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 4000,
                'system' => 'Leés la carta de un bar, restaurante o cafetería argentino (foto, pizarra o PDF). Devolvé SOLO JSON: {"rubros":[{"nombre":"Cafetería","platos":[{"nombre":"Café con leche","precio":3500,"descripcion":"","opciones":[{"grupo":"Tamaño","obligatorio":true,"multiple":false,"opciones":[{"nombre":"Chico","precio":0},{"nombre":"Grande","precio":500}]}]}]}]}. Los precios son los que figuran (finales, con IVA), en pesos, número sin puntos. Si un plato tiene variantes con distinto precio ("Pizza chica / grande"), ponelo como un plato con un grupo de opciones obligatorio y el precio base el más barato con el extra en cada opción. Si no se lee el precio, null. Nunca inventes platos.',
                'messages' => [['role' => 'user', 'content' => [$bloque, ['type' => 'text', 'text' => 'Leé esta carta.']]]],
            ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $d = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            if (! is_array($d) || empty($d['rubros'])) throw new \RuntimeException('sin rubros: ' . mb_substr($raw, 0, 150));
        } catch (\Throwable $e) { Log::warning('Carta IA: ' . $e->getMessage()); return ['ok' => false, 'aviso' => 'No pude leer la carta. Probá con una foto más nítida, de a una página.']; }
        $existentes = Product::where('active', true)->get(['id', 'name', 'price', 'iva'])->keyBy(fn($p) => PedidoRestoService::norm($p->name));
        $rubros = collect($d['rubros'])->map(fn($r) => ['nombre' => trim((string) ($r['nombre'] ?? 'Carta')), 'platos' => collect($r['platos'] ?? [])->filter(fn($p) => trim((string) ($p['nombre'] ?? '')) !== '')->map(function ($p) use ($existentes) {
            $ex = $existentes[PedidoRestoService::norm($p['nombre'])] ?? null;
            return ['nombre' => trim($p['nombre']), 'precio' => isset($p['precio']) ? round((float) $p['precio'], 2) : null, 'descripcion' => trim((string) ($p['descripcion'] ?? '')) ?: null, 'opciones' => array_values(array_filter((array) ($p['opciones'] ?? []), fn($g) => ! empty($g['grupo']) && ! empty($g['opciones']))), 'existe_id' => $ex?->id, 'cargar' => true];
        })->values()->all()])->filter(fn($r) => $r['platos'])->values()->all();
        return ['ok' => true, 'rubros' => $rubros, 'platos' => collect($rubros)->sum(fn($r) => count($r['platos']))];
    }

    // Carga lo revisado: rubro por nombre (se crea si no existe), plato nuevo o precio actualizado si ya existía, opciones como modificadores.
    public function importar(Business $b, array $rubros, int $locationId): array
    {
        $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto'; $nuevos = 0; $actualizados = 0;
        foreach ($rubros as $r) {
            $rubro = Rubro::firstOrCreate(['business_id' => $b->id, 'nombre' => trim($r['nombre']), 'parent_id' => null]);
            foreach ($r['platos'] as $pl) {
                if (empty($pl['cargar']) || trim((string) ($pl['nombre'] ?? '')) === '') continue;
                $iva = 21.0; $precioFinal = (float) ($pl['precio'] ?? 0); $neto = $ri ? round($precioFinal / (1 + $iva / 100), 2) : $precioFinal;
                $mods = collect($pl['opciones'] ?? [])->map(fn($g) => ['nombre' => trim($g['grupo']), 'obligatorio' => (bool) ($g['obligatorio'] ?? false), 'multiple' => (bool) ($g['multiple'] ?? false), 'opciones' => collect($g['opciones'])->map(fn($o) => ['nombre' => trim((string) ($o['nombre'] ?? '')), 'precio' => round((float) ($o['precio'] ?? 0), 2)])->filter(fn($o) => $o['nombre'] !== '')->values()->all()])->filter(fn($g) => $g['nombre'] !== '' && $g['opciones'])->values()->all();
                $p = ! empty($pl['existe_id']) ? Product::find($pl['existe_id']) : null;
                if ($p) { $p->forceFill(array_filter(['price' => $precioFinal > 0 ? $neto : null, 'modificadores' => $mods ?: null, 'rubro_id' => $p->rubro_id ?: $rubro->id], fn($v) => $v !== null))->save(); $actualizados++; continue; }
                $base = Str::upper(Str::slug(Str::limit($pl['nombre'], 12, ''), '')); $sku = $base; $n = 2;
                while (Product::withoutGlobalScopes()->where('business_id', $b->id)->where('sku', $sku)->exists()) $sku = $base . $n++;
                Product::create(['business_id' => $b->id, 'business_location_id' => $locationId, 'rubro_id' => $rubro->id, 'name' => trim($pl['nombre']), 'sku' => $sku, 'tipo' => 'producto', 'unit' => 'un', 'cost' => 0, 'price' => $neto, 'iva' => $iva, 'stock' => 0, 'stock_min' => 0, 'active' => true, 'controla_stock' => false, 'moneda' => 'ARS', 'en_tienda' => true, 'va_cocina' => true, 'description' => $pl['descripcion'] ?? null, 'modificadores' => $mods ?: null]);
                $nuevos++;
            }
        }
        AuditLog::registrar('crear', null, "Cargó la carta desde una foto: {$nuevos} platos nuevos, {$actualizados} actualizados");
        return ['nuevos' => $nuevos, 'actualizados' => $actualizados];
    }
}
