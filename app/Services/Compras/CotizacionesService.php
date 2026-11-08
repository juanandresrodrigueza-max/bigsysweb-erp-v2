<?php

namespace App\Services\Compras;

use App\Models\Business;
use App\Models\Contact;
use App\Models\OrdenCompra;
use App\Models\Product;
use App\Support\Ia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Fase 62 · Comparar cotizaciones de proveedores: fotos, PDF o el mensaje pegado de cada proveedor se leen con IA, se arma la tabla
// comparada artículo por artículo (quién gana cada renglón, cuánto sale todo en uno solo y cuánto el mixto) y se arman las órdenes
// de compra divididas al que cotizó más barato. Bigcito hace lo mismo con los textos.
class CotizacionesService
{
    // Cada archivo o texto → una cotización: proveedor, condiciones y renglones con precio neto por unidad.
    public function leer(Business $b, array $entradas): array
    {
        Ia::aplicar($b);
        $key = config('services.anthropic.api_key');
        if (! $key) return ['ok' => false, 'aviso' => 'Para leer cotizaciones con IA hay que cargar la clave en Configuración → IA.'];
        $catalogo = Product::withoutGlobalScopes()->where('business_id', $b->id)->where('active', true)->whereIn('tipo', ['producto', 'insumo'])->orderBy('name')->limit(1500)->get(['id', 'name', 'sku', 'unit']);
        $lista = $catalogo->map(fn($p) => "{$p->id}|{$p->sku}|{$p->name}|{$p->unit}")->implode("\n");
        $system = "Leés cotizaciones o listas de precios que proveedores le mandan a una PyME argentina ({$b->name}): foto, PDF o el mensaje tal cual. Devolvé SOLO JSON: {\"proveedor\":{\"nombre\":\"\",\"cuit\":\"30-12345678-9 o null\"},\"fecha\":\"YYYY-MM-DD o null\",\"validez\":\"texto corto o null\",\"condiciones\":\"pago, entrega, envío, mínimo de compra; corto o null\",\"items\":[{\"product_id\":123 o null,\"descripcion\":\"\",\"cantidad\":1,\"unidad\":\"un\",\"precio_unit\":0,\"iva_incluido\":false,\"descuento\":0}],\"observaciones\":\"\"}. precio_unit es el precio por unidad SIN IVA: si la cotización dice IVA incluido o es consumidor final, dividí por 1.21 y poné iva_incluido=true. Si el precio es por bulto o por pack y la unidad del catálogo es la unidad suelta, convertí a la unidad del catálogo y aclaralo en descripcion. Matcheá cada renglón con el catálogo (id|sku|nombre|unidad) sólo si es claramente el mismo artículo; si no, product_id null. Si el documento no es una cotización ni una lista de precios, devolvé items vacío y decilo en observaciones.\nCatálogo:\n{$lista}";
        $cotizaciones = []; $avisos = [];
        foreach ($entradas as $i => $e) {
            $contenido = [];
            if (! empty($e['base64'])) $contenido[] = str_starts_with((string) $e['mime'], 'application/pdf') ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $e['base64']]] : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $e['mime'] === 'image/jpg' ? 'image/jpeg' : $e['mime'], 'data' => $e['base64']]];
            $contenido[] = ['type' => 'text', 'text' => ! empty($e['texto']) ? "Cotización recibida:\n" . mb_substr($e['texto'], 0, 6000) : 'Leé esta cotización.'];
            try {
                $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(90)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 3000, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $contenido]]]);
                $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
                $j = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
                if (! is_array($j) || ! isset($j['items'])) throw new \RuntimeException('sin items: ' . mb_substr($raw, 0, 120));
            } catch (\Throwable $ex) { Log::warning('Cotización IA: ' . $ex->getMessage()); $avisos[] = 'No pude leer ' . ($e['nombre'] ?? 'la cotización ' . ($i + 1)) . '.'; continue; }
            $prov = $this->proveedor($b, $j['proveedor'] ?? []);
            $items = collect($j['items'])->map(function ($it) use ($catalogo) {
                $p = ! empty($it['product_id']) ? $catalogo->firstWhere('id', (int) $it['product_id']) : null;
                $precio = round((float) ($it['precio_unit'] ?? 0) * (1 - (float) ($it['descuento'] ?? 0) / 100), 2);
                if ($precio <= 0 || trim((string) ($it['descripcion'] ?? '')) === '' && ! $p) return null;
                return ['product_id' => $p?->id, 'descripcion' => $p?->name ?? mb_substr(trim($it['descripcion']), 0, 150), 'detalle' => $p ? mb_substr(trim((string) ($it['descripcion'] ?? '')), 0, 150) : null, 'cantidad' => max(0.0, (float) ($it['cantidad'] ?? 1)) ?: 1.0, 'unidad' => $p?->unit ?? ($it['unidad'] ?? 'un'), 'precio_unit' => $precio, 'iva_incluido' => (bool) ($it['iva_incluido'] ?? false), 'clave' => $p ? 'p' . $p->id : 'd' . Str::slug(Str::ascii(mb_strtolower(trim($it['descripcion']))))];
            })->filter()->values()->all();
            if (! $items) { $avisos[] = (($e['nombre'] ?? null) ?: 'Una entrada') . ': no parece una cotización' . (! empty($j['observaciones']) ? " ({$j['observaciones']})" : '') . '.'; continue; }
            $cotizaciones[] = ['proveedor_id' => $prov?->id, 'proveedor' => $prov?->name ?? ($j['proveedor']['nombre'] ?? null) ?: 'Proveedor ' . ($i + 1), 'cuit' => $j['proveedor']['cuit'] ?? null, 'proveedor_nuevo' => ! $prov, 'fecha' => $j['fecha'] ?? null, 'validez' => $j['validez'] ?? null, 'condiciones' => $j['condiciones'] ?? null, 'archivo' => $e['nombre'] ?? null, 'items' => $items, 'observaciones' => $j['observaciones'] ?? null];
        }
        return ['ok' => (bool) $cotizaciones, 'cotizaciones' => $cotizaciones, 'avisos' => $avisos, 'comparacion' => $cotizaciones ? $this->comparar($cotizaciones) : null];
    }

    private function proveedor(Business $b, array $p): ?Contact
    {
        $q = fn() => Contact::withoutGlobalScopes()->where('business_id', $b->id)->where('type', 'supplier')->where('is_active', true);
        $dig = preg_replace('/\D/', '', (string) ($p['cuit'] ?? ''));
        if (strlen($dig) === 11 && ($c = $q()->get(['id', 'cuit'])->first(fn($x) => preg_replace('/\D/', '', (string) $x->cuit) === $dig))) return Contact::withoutGlobalScopes()->find($c->id);
        $n = trim((string) ($p['nombre'] ?? '')); if ($n === '') return null;
        $like = \App\Support\Sql::like();
        return $q()->where('name', $like, $n)->first() ?? $q()->where('name', $like, "%{$n}%")->orderByRaw('LENGTH(name)')->first()
            ?? $q()->where(function ($w) use ($n, $like) { foreach (array_filter(explode(' ', Str::ascii($n)), fn($x) => mb_strlen($x) > 3 && ! in_array(mb_strtolower($x), ['srl', 'sa', 's.a.', 'sas', 'sociedad', 'anonima', 'hnos', 'hermanos'], true)) as $x) $w->where('name', $like, "%{$x}%"); })->first();
    }

    // La tabla comparada: una fila por artículo (o descripción), una columna por proveedor, el más barato de cada fila, y los totales.
    public function comparar(array $cotizaciones): array
    {
        $provs = collect($cotizaciones)->map(fn($c, $i) => ['i' => $i, 'proveedor_id' => $c['proveedor_id'], 'proveedor' => $c['proveedor'], 'condiciones' => $c['condiciones'] ?? null])->values()->all();
        $filas = [];
        foreach ($cotizaciones as $i => $c) foreach ($c['items'] as $it) {
            $k = $it['clave'];
            $filas[$k] ??= ['clave' => $k, 'product_id' => $it['product_id'], 'descripcion' => $it['descripcion'], 'unidad' => $it['unidad'], 'cantidad' => $it['cantidad'], 'precios' => array_fill(0, count($cotizaciones), null), 'detalles' => array_fill(0, count($cotizaciones), null)];
            $filas[$k]['precios'][$i] = $filas[$k]['precios'][$i] === null ? $it['precio_unit'] : min($filas[$k]['precios'][$i], $it['precio_unit']);
            $filas[$k]['detalles'][$i] = $it['detalle'] ?? null;
            $filas[$k]['cantidad'] = max($filas[$k]['cantidad'], $it['cantidad']);
        }
        $n = count($cotizaciones); $totalTodo = array_fill(0, $n, 0.0); $completo = array_fill(0, $n, true); $mixto = 0.0;
        foreach ($filas as &$f) {
            $validos = array_filter($f['precios'], fn($v) => $v !== null); $min = $validos ? min($validos) : null;
            $f['gana'] = $min === null ? null : array_search($min, $f['precios'], true);
            $f['mejor_precio'] = $min; $f['cotizan'] = count($validos);
            $max = $validos ? max($validos) : null; $f['diferencia_pct'] = $min && $max && $min > 0 ? round(($max - $min) / $min * 100, 1) : 0;
            foreach ($f['precios'] as $i => $v) { if ($v === null) $completo[$i] = false; else $totalTodo[$i] += $v * $f['cantidad']; }
            if ($min !== null) $mixto += $min * $f['cantidad'];
        }
        unset($f);
        $filas = array_values($filas);
        $resumen = [];
        foreach ($provs as $p) $resumen[] = ['proveedor_id' => $p['proveedor_id'], 'proveedor' => $p['proveedor'], 'total_todo' => round($totalTodo[$p['i']], 2), 'cotiza_todo' => $completo[$p['i']], 'renglones_que_gana' => count(array_filter($filas, fn($f) => $f['gana'] === $p['i'])), 'condiciones' => $p['condiciones']];
        $completos = array_filter($resumen, fn($r) => $r['cotiza_todo']);
        $mejorUnico = $completos ? collect($completos)->sortBy('total_todo')->first() : null;
        $reparto = [];
        foreach ($filas as $f) if ($f['gana'] !== null) { $i = $f['gana']; $reparto[$i] ??= ['proveedor_id' => $provs[$i]['proveedor_id'], 'proveedor' => $provs[$i]['proveedor'], 'items' => [], 'total' => 0.0]; $reparto[$i]['items'][] = ['product_id' => $f['product_id'], 'descripcion' => $f['descripcion'], 'cantidad' => $f['cantidad'], 'precio_unit' => $f['mejor_precio']]; $reparto[$i]['total'] = round($reparto[$i]['total'] + $f['mejor_precio'] * $f['cantidad'], 2); }
        return ['proveedores' => $provs, 'filas' => $filas, 'resumen' => $resumen, 'mixto' => round($mixto, 2), 'mejor_unico' => $mejorUnico, 'ahorro_mixto' => $mejorUnico ? round($mejorUnico['total_todo'] - $mixto, 2) : null, 'reparto' => array_values($reparto),
            'texto' => $this->texto($filas, $resumen, $mixto, $mejorUnico)];
    }

    private function texto(array $filas, array $resumen, float $mixto, ?array $mejorUnico): string
    {
        $pesos = fn($n) => '$ ' . number_format($n, 0, ',', '.');
        $t = count($filas) . ' artículos cotizados por ' . count($resumen) . ' proveedores. ';
        foreach ($resumen as $r) $t .= "{$r['proveedor']}: gana {$r['renglones_que_gana']} renglones" . ($r['cotiza_todo'] ? ', todo ahí ' . $pesos($r['total_todo']) : ', no cotiza todo') . '. ';
        $t .= 'Comprando cada cosa al más barato: ' . $pesos($mixto) . ($mejorUnico ? ' (' . $pesos($mejorUnico['total_todo'] - $mixto) . " menos que todo en {$mejorUnico['proveedor']})" : '') . '.';
        return $t;
    }

    // Una orden de compra en borrador por proveedor, con lo que ganó cada uno. Los proveedores sin ficha no se pueden pedir: hay que darlos de alta.
    public function pedidos(array $reparto, ?string $notas = null): array
    {
        $svc = app(OrdenCompraService::class); $ocs = [];
        foreach ($reparto as $r) {
            if (empty($r['proveedor_id']) || empty($r['items'])) continue;
            $ocs[] = $svc->guardar(['contact_id' => (int) $r['proveedor_id'], 'fecha' => today()->toDateString(), 'notas' => trim('Cotización comparada' . ($notas ? " · {$notas}" : '')), 'origen' => 'cotizacion',
                'items' => array_map(fn($i) => ['product_id' => $i['product_id'] ?? null, 'descripcion' => $i['descripcion'] ?? null, 'cantidad' => (float) $i['cantidad'], 'precio_unit' => (float) $i['precio_unit']], $r['items'])]);
        }
        return $ocs;
    }
}
