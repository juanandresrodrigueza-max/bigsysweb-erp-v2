<?php

namespace App\Services\Stock;

use App\Models\Business;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\IA\VozFotoService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Fase 61 · Alta de artículos con IA: del nombre (o de una foto del producto o su etiqueta) salen el nombre prolijo, marca, rubro,
// unidad, IVA, código de barras, contenido, la descripción interna, la descripción para la tienda y los atributos para MercadoLibre.
class AltaArticuloIaService
{
    public function completar(Business $b, ?string $nombre, ?string $fotoDataUrl = null): array
    {
        \App\Support\Ia::aplicar($b); // también desde Bigcito (MCP), donde no pasa por el middleware
        $key = config('services.anthropic.api_key');
        if (! $key) return ['ok' => false, 'aviso' => 'Para completar el artículo con IA hay que cargar la clave de IA en Configuración → IA.'];
        $nombre = trim((string) $nombre);
        if ($nombre === '' && ! $fotoDataUrl) return ['ok' => false, 'aviso' => 'Escribí el nombre o sacale una foto.'];
        $rubros = Rubro::withoutGlobalScopes()->where('business_id', $b->id)->with('parent')->orderBy('nombre')->get();
        $listaRubros = $rubros->map(fn($r) => $r->nombreCompleto())->implode(' | ') ?: '(todavía no hay rubros)';
        $unidades = implode('|', array_keys(Product::UNIDADES));
        $contenido = [];
        if ($fotoDataUrl && ($img = VozFotoService::bloqueImagen($fotoDataUrl))) $contenido[] = $img;
        $contenido[] = ['type' => 'text', 'text' => $nombre !== '' ? "Nombre cargado por el usuario: {$nombre}" . ($fotoDataUrl ? "\nMirá también la foto." : '') : 'Identificá el producto de la foto.'];
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 1200,
                'system' => "Das de alta artículos en el stock de una PyME argentina ({$b->name}" . ($b->vertical ? ", rubro {$b->vertical}" : '') . "). A partir del nombre y/o la foto devolvé SOLO JSON con: name (nombre comercial limpio y completo, con marca y medida o contenido si se conocen, máx 100), marca (o null), modelo (o null), tipo (producto|insumo|servicio), unit (uno de {$unidades}), iva (21, o 10.5 sólo para alimentos básicos: pan, leche, carne, frutas, verduras, harina), rubro (el nombre EXACTO de uno de los rubros existentes que mejor encaje, con el mismo texto, o null), rubro_nuevo (si ninguno encaja, un nombre corto y genérico de rubro, o null), barcode (los dígitos del código de barras EAN si se ve en la foto, o null), contenido_neto (número o null), contenido_unidad (g|kg|ml|l|un o null), description (una línea interna: qué es y para qué sirve, máx 160), descripcion_tienda (2 o 3 oraciones vendedoras para la tienda online, en español rioplatense, sin precio, máx 400), titulo_publicacion (título para marketplace: marca + producto + medida, máx 60), ml_atributos (objeto id→valor con los atributos de MercadoLibre que sepas con seguridad: BRAND, MODEL y los que apliquen como COLOR, MATERIAL, LENGTH, DIAMETER, WEIGHT, CAPACITY, POWER, VOLTAGE, UNITS_PER_PACK, GTIN; valores con unidad, ej. \"50 kg\"). No inventes marca, medidas ni códigos que no estén en el nombre o la foto.\nRubros existentes: {$listaRubros}",
                'messages' => [['role' => 'user', 'content' => $contenido]],
            ]);
            $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $j = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            if (! is_array($j) || empty($j['name'])) throw new \RuntimeException('sin nombre: ' . mb_substr($raw, 0, 120));
        } catch (\Throwable $e) { Log::warning('Alta artículo IA: ' . $e->getMessage()); return ['ok' => false, 'aviso' => 'No pude completar el artículo. Probá con un nombre más claro o una foto más nítida.']; }

        $rubro = null; $rubroN = Str::lower(Str::ascii(trim((string) ($j['rubro'] ?? ''))));
        if ($rubroN !== '') $rubro = $rubros->first(fn($r) => in_array($rubroN, [Str::lower(Str::ascii($r->nombreCompleto())), Str::lower(Str::ascii($r->nombre))], true));
        $ml = collect((array) ($j['ml_atributos'] ?? []))->filter(fn($v, $k) => is_string($k) && (is_string($v) || is_numeric($v)) && trim((string) $v) !== '')->map(fn($v) => mb_substr(trim((string) $v), 0, 80))->mapWithKeys(fn($v, $k) => [strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $k)) => $v])->all();
        $campos = [
            'name' => mb_substr(trim($j['name']), 0, 150), 'marca' => $this->str($j['marca'] ?? null, 60), 'tipo' => in_array($j['tipo'] ?? '', array_keys(Product::TIPOS), true) ? $j['tipo'] : 'producto',
            'unit' => in_array($j['unit'] ?? '', array_keys(Product::UNIDADES), true) ? $j['unit'] : 'un', 'iva' => in_array((float) ($j['iva'] ?? 21), [0.0, 10.5, 21.0, 27.0], true) ? (float) $j['iva'] : 21.0,
            'rubro_id' => $rubro?->id, 'barcode' => preg_match('/^\d{8,14}$/', (string) ($j['barcode'] ?? '')) ? $j['barcode'] : null,
            'contenido_neto' => is_numeric($j['contenido_neto'] ?? null) && (float) $j['contenido_neto'] > 0 ? (float) $j['contenido_neto'] : null, 'contenido_unidad' => in_array($j['contenido_unidad'] ?? '', ['g', 'kg', 'ml', 'l', 'un'], true) ? $j['contenido_unidad'] : null,
            'description' => $this->str($j['description'] ?? null, 500), 'descripcion_tienda' => $this->str($j['descripcion_tienda'] ?? null, 500),
            'atributos' => array_filter(['titulo_publicacion' => $this->str($j['titulo_publicacion'] ?? null, 60), 'modelo' => $this->str($j['modelo'] ?? null, 60), 'ml' => $ml ?: null]),
        ];
        return ['ok' => true, 'campos' => $campos, 'rubro_sugerido' => ! $rubro ? $this->str($j['rubro_nuevo'] ?? ($j['rubro'] ?? null), 60) : null];
    }

    private function str(mixed $v, int $max): ?string { $v = is_scalar($v) ? trim((string) $v) : ''; return $v === '' ? null : mb_substr($v, 0, $max); }
}
