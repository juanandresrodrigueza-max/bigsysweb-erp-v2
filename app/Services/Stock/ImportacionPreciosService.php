<?php

namespace App\Services\Stock;

use App\Models\AuditLog;
use App\Models\ImportacionPrecios;
use App\Models\Product;
use App\Models\Rubro;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Lista de precios del proveedor (CSV o Excel): se leen las filas, se mapean las columnas y se crean o actualizan artículos.
class ImportacionPreciosService
{
    public const CAMPOS = ['codigo' => 'Código / SKU', 'barcode' => 'Código de barras', 'descripcion' => 'Descripción', 'precio_compra' => 'Precio de lista (neto)', 'descuento' => '% descuento', 'costo' => 'Costo (neto)', 'precio_venta' => 'Precio de venta', 'iva' => 'IVA %', 'rubro' => 'Rubro', 'marca' => 'Marca', 'unidad' => 'Unidad'];

    // Devuelve las filas del archivo como arrays de celdas (máximo $max), detectando CSV o XLSX.
    public function leer(string $path, string $nombre, int $max = 5000): array
    {
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $filas = $ext === 'xlsx' ? $this->leerXlsx($path) : $this->leerCsv($path);
        $filas = array_values(array_filter($filas, fn($f) => count(array_filter($f, fn($c) => trim((string) $c) !== '')) > 0));
        return array_slice($filas, 0, $max);
    }

    private function leerCsv(string $path): array
    {
        $raw = file_get_contents($path);
        if (! mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $lineas = preg_split('/\r\n|\r|\n/', $raw);
        $sep = substr_count($lineas[0] ?? '', ';') >= substr_count($lineas[0] ?? '', ',') ? ';' : ',';
        if (substr_count($lineas[0] ?? '', "\t") > substr_count($lineas[0] ?? '', $sep)) $sep = "\t";
        return array_map(fn($l) => str_getcsv($l, $sep), array_filter($lineas, fn($l) => trim($l) !== ''));
    }

    // Lector mínimo de XLSX (sin dependencias): primera hoja, strings compartidos y celdas inline.
    private function leerXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw ValidationException::withMessages(['archivo' => 'No se pudo abrir el Excel.']);
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = simplexml_load_string($xml);
            foreach ($sx->si as $si) { $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn($r) => (string) $r->t, iterator_to_array($si->r, false))); }
        }
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($hoja === false) throw ValidationException::withMessages(['archivo' => 'El Excel no tiene hojas.']);
        $sx = simplexml_load_string($hoja);
        $filas = [];
        foreach ($sx->sheetData->row as $row) {
            $f = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r']; $col = 0;
                foreach (str_split(preg_replace('/\d+/', '', $ref)) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $t = (string) $c['t'];
                $v = $t === 's' ? ($shared[(int) $c->v] ?? '') : ($t === 'inlineStr' ? (string) $c->is->t : (string) $c->v);
                $f[$col - 1] = $v;
            }
            if ($f) { $n = max(array_keys($f)); $filas[] = array_map(fn($i) => $f[$i] ?? '', range(0, $n)); }
        }
        return $filas;
    }

    // Adivina qué columna es cada campo mirando el encabezado.
    public function sugerirMapeo(array $encabezado): array
    {
        $m = [];
        foreach ($encabezado as $i => $h) {
            $h = mb_strtolower(trim((string) $h));
            $campo = match (true) {
                str_contains($h, 'barra') || str_contains($h, 'ean') => 'barcode',
                str_contains($h, 'cod') || str_contains($h, 'sku') || str_contains($h, 'art') && str_contains($h, 'nro') => 'codigo',
                str_contains($h, 'dto') || str_contains($h, 'bonif') || (str_contains($h, 'desc') && str_contains($h, '%')) => 'descuento',
                str_contains($h, 'desc') || str_contains($h, 'nombre') || str_contains($h, 'detalle') || str_contains($h, 'producto') || str_contains($h, 'articulo') || str_contains($h, 'artículo') || str_contains($h, 'item') || str_contains($h, 'material') => 'descripcion',
                str_contains($h, 'iva') => 'iva',
                str_contains($h, 'rubro') || str_contains($h, 'familia') || str_contains($h, 'categor') => 'rubro',
                str_contains($h, 'marca') => 'marca',
                str_contains($h, 'unid') => 'unidad',
                str_contains($h, 'costo') => 'costo',
                str_contains($h, 'venta') || str_contains($h, 'público') || str_contains($h, 'publico') || str_contains($h, 'pvp') => 'precio_venta',
                str_contains($h, 'precio') || str_contains($h, 'lista') || str_contains($h, 'importe') => 'precio_compra',
                default => null,
            };
            if ($campo && ! in_array($campo, $m, true)) $m[$i] = $campo;
        }
        return $m;
    }

    // Cuando el encabezado no alcanza, le pedimos a la IA que diga qué es cada columna mirando el encabezado y tres filas.
    public function mapeoConIA(array $muestra): array
    {
        $key = config('services.anthropic.api_key'); if (! $key || ! $muestra) return [];
        try {
            $r = \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(30)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model', 'claude-sonnet-5'), 'max_tokens' => 400, 'messages' => [['role' => 'user', 'content' => "Esta es una lista de precios de un proveedor argentino (primeras filas, cada fila es un array de celdas):\n" . json_encode(array_slice($muestra, 0, 4), JSON_UNESCAPED_UNICODE) . "\nCampos posibles: " . implode(', ', array_keys(self::CAMPOS)) . ". Devolvé SOLO un JSON {\"indice_columna\": \"campo\"} con las columnas que reconozcas (índices desde 0). Sin comentarios."]]]);
            if (! $r->successful()) return [];
            $t = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''); $j = json_decode(substr($t, strpos($t, '{'), strrpos($t, '}') - strpos($t, '{') + 1), true) ?: [];
            $m = []; foreach ($j as $i => $c) if (isset(self::CAMPOS[$c]) && ! in_array($c, $m, true)) $m[(int) $i] = $c;
            return $m;
        } catch (\Throwable) { return []; }
    }

    // Normaliza nombres para comparar: sin acentos, "8mm" → "8 mm", "mts" → "m", abreviaturas comunes de corralón y ferretería.
    private function normalizar(string $s): string
    {
        $s = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s);
        $s = preg_replace(['/(\d)([a-z])/', '/([a-z])(\d)/'], '$1 $2', $s);
        $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
        $s = preg_replace(['/\bmts?\b|\bmetros?\b/', '/\bkgs?\b|\bkilos?\b/', '/\bunid(ad(es)?)?\b|\bun\b/', '/\balet(ado)?\b/', '/\bp\b/', '/\bx\b|\bpor\b/', '/\bbolsas?\b/', '/\bceram(ico|icos)?\b/'], [' m ', ' kg ', ' ', ' ', ' para ', ' x ', ' ', ' ceramico '], $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    // Analiza la lista antes de importar: qué artículo existente corresponde a cada fila (por código, barras, nombre exacto o parecido), cuánto cambia el costo y qué se crearía.
    public function analizar(array $filas, array $mapeo, array $opt): array
    {
        $col = array_flip($mapeo);
        $g = fn($f, $k) => isset($col[$k]) ? trim((string) ($f[$col[$k]] ?? '')) : '';
        $numS = function ($v) { $s = trim((string) $v); if ($s === '') return null; if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace('.', '', $s); return (float) str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $s)); };
        $todos = Product::where('active', true)->get(['id', 'name', 'sku', 'barcode', 'cost', 'price', 'proveedor_id', 'rubro_id']);
        $porSku = $todos->filter(fn($p) => $p->sku)->keyBy(fn($p) => mb_strtolower($p->sku)); $porBar = $todos->filter(fn($p) => $p->barcode)->keyBy('barcode'); $porNombre = $todos->keyBy(fn($p) => $this->normalizar($p->name));
        $candidatos = ($opt['solo_proveedor'] ?? false) && ($opt['contact_id'] ?? null) ? $todos->where('proveedor_id', $opt['contact_id']) : $todos;
        $out = []; $dudosas = [];
        foreach ($filas as $n => $f) {
            if ($n === 0 && ($opt['encabezado'] ?? true)) continue;
            $desc = $g($f, 'descripcion'); $sku = $g($f, 'codigo'); $bar = $g($f, 'barcode');
            if ($desc === '' && $sku === '') continue;
            $pc = $numS($g($f, 'precio_compra')); $dto = $numS($g($f, 'descuento')) ?? 0; $costo = $numS($g($f, 'costo')) ?? ($pc !== null ? round($pc * (1 - $dto / 100), 2) : null);
            $p = ($sku !== '' ? $porSku[mb_strtolower($sku)] ?? null : null) ?? ($bar !== '' ? $porBar[$bar] ?? null : null) ?? ($desc !== '' ? $porNombre[$this->normalizar($desc)] ?? null : null);
            $estado = $p ? 'existente' : 'nuevo'; $conf = $p ? 100 : 0;
            if (! $p && $desc !== '') {
                // Parecido por nombre: tokens en común y similitud de texto.
                $nd = $this->normalizar($desc); $tk = array_filter(explode(' ', $nd), fn($t) => strlen($t) > 2); $mejor = null; $mejorScore = 0;
                foreach ($candidatos as $c) {
                    $nc = $this->normalizar($c->name); $tc = array_filter(explode(' ', $nc), fn($t) => strlen($t) > 2);
                    $comunes = count(array_intersect($tk, $tc)); if (! $comunes) continue;
                    similar_text($nd, $nc, $pct); $score = $pct * 0.6 + ($comunes / max(1, count($tk))) * 40;
                    if ($score > $mejorScore) { $mejorScore = $score; $mejor = $c; }
                }
                if ($mejor && $mejorScore >= 55) { $p = $mejor; $estado = 'sugerido'; $conf = (int) round($mejorScore); }
                elseif ($mejor && $mejorScore >= 35) $dudosas[$n] = ['desc' => $desc, 'candidato' => $mejor];
            }
            $out[$n] = ['fila' => $n, 'desc' => $desc ?: $sku, 'codigo' => $sku, 'barcode' => $bar, 'costo_nuevo' => $costo, 'precio_venta' => $numS($g($f, 'precio_venta')), 'estado' => $estado, 'confianza' => $conf, 'product_id' => $p?->id, 'product_nombre' => $p?->name, 'product_sku' => $p?->sku, 'costo_actual' => $p ? (float) $p->cost : null, 'variacion' => $p && (float) $p->cost > 0 && $costo !== null ? round(($costo / (float) $p->cost - 1) * 100, 1) : null, 'sin_precio' => $costo === null && $numS($g($f, 'precio_venta')) === null];
        }
        // Las dudosas y las nuevas se las mostramos a la IA con los candidatos parecidos para que decida (si hay clave).
        $nuevas = array_filter($out, fn($r) => $r['estado'] === 'nuevo');
        if ($nuevas && ($key = config('services.anthropic.api_key'))) {
            try {
                $lote = array_slice(array_values($nuevas), 0, 120);
                $nombres = $candidatos->map(fn($c) => ['id' => $c->id, 'n' => $c->name, 'sku' => $c->sku])->values()->take(400)->all();
                $rubros = Rubro::orderBy('nombre')->get(['id', 'nombre'])->map(fn($r) => ['id' => $r->id, 'n' => $r->nombre])->all();
                $r = \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(60)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model', 'claude-sonnet-5'), 'max_tokens' => 4000, 'messages' => [['role' => 'user', 'content' => "Sos el asistente de un corralón/ferretería argentina. Filas de una lista de precios del proveedor que no encontré en mi catálogo:\n" . json_encode(array_map(fn($x) => ['fila' => $x['fila'], 'desc' => $x['desc'], 'codigo' => $x['codigo']], $lote), JSON_UNESCAPED_UNICODE) . "\nMi catálogo:\n" . json_encode($nombres, JSON_UNESCAPED_UNICODE) . "\nMis rubros:\n" . json_encode($rubros, JSON_UNESCAPED_UNICODE) . "\nPara cada fila decí si es el mismo artículo que alguno de mi catálogo (aunque esté escrito distinto: abreviaturas, medidas, marca) o si es nuevo. Devolvé SOLO JSON: {\"filas\":[{\"fila\":N,\"product_id\":ID o null,\"confianza\":0-100,\"rubro_id\":ID o null,\"nombre_limpio\":\"nombre prolijo para crearlo\"}]}."]]]);
                if ($r->successful()) {
                    $t = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''); $j = json_decode(substr($t, strpos($t, '{'), strrpos($t, '}') - strpos($t, '{') + 1), true);
                    foreach ($j['filas'] ?? [] as $x) { $n = (int) ($x['fila'] ?? -1); if (! isset($out[$n]) || $out[$n]['estado'] !== 'nuevo') continue;
                        if (! empty($x['product_id']) && ($c = $todos->firstWhere('id', (int) $x['product_id'])) && (int) ($x['confianza'] ?? 0) >= 60) { $out[$n] = array_merge($out[$n], ['estado' => 'sugerido', 'confianza' => (int) $x['confianza'], 'product_id' => $c->id, 'product_nombre' => $c->name, 'product_sku' => $c->sku, 'costo_actual' => (float) $c->cost, 'variacion' => (float) $c->cost > 0 && $out[$n]['costo_nuevo'] !== null ? round(($out[$n]['costo_nuevo'] / (float) $c->cost - 1) * 100, 1) : null, 'ia' => true]); }
                        else { $out[$n]['rubro_sugerido'] = ! empty($x['rubro_id']) ? (int) $x['rubro_id'] : null; $out[$n]['nombre_limpio'] = $x['nombre_limpio'] ?? null; $out[$n]['ia'] = true; }
                    }
                }
            } catch (\Throwable) {}
        }
        $out = array_values($out);
        return ['filas' => $out, 'resumen' => ['total' => count($out), 'existentes' => count(array_filter($out, fn($r) => $r['estado'] === 'existente')), 'sugeridos' => count(array_filter($out, fn($r) => $r['estado'] === 'sugerido')), 'nuevos' => count(array_filter($out, fn($r) => $r['estado'] === 'nuevo')), 'sin_precio' => count(array_filter($out, fn($r) => $r['sin_precio'])), 'suben_mucho' => count(array_filter($out, fn($r) => ($r['variacion'] ?? 0) > 30), ), 'bajan' => count(array_filter($out, fn($r) => ($r['variacion'] ?? 0) < -0.5)), 'ia' => (bool) config('services.anthropic.api_key')]];
    }

    // Aplica la importación. $mapeo: [indiceColumna => campo]. Busca por SKU, después por barras, después por descripción + proveedor.
    // $opt['decisiones'] (opcional, del paso de revisión): [fila => product_id | 'nuevo' | 'omitir'] y $opt['nombres'] [fila => nombre limpio], $opt['rubros_fila'] [fila => rubro_id].
    public function aplicar(array $filas, array $mapeo, array $opt): ImportacionPrecios
    {
        $user = Auth::user();
        $col = array_flip($mapeo);
        if (! isset($col['descripcion']) && ! isset($col['codigo'])) throw ValidationException::withMessages(['mapeo' => 'Indicá al menos la columna de código o de descripción.']);
        $num = fn($v) => (float) str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', str_replace('.', '', (string) $v)));
        $numS = function ($v) { $s = trim((string) $v); if ($s === '') return null; if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace('.', '', $s); return (float) str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $s)); };
        $margen = isset($opt['margen']) && $opt['margen'] !== '' ? (float) $opt['margen'] : null;
        $rubroDefault = $opt['rubro_id'] ?? null; $ivaDefault = (float) ($opt['iva'] ?? 21); $usd = ($opt['moneda'] ?? 'ARS') === 'USD';
        $crear = (bool) ($opt['crear'] ?? true); $soloProveedor = (bool) ($opt['solo_proveedor'] ?? false);
        $imp = ImportacionPrecios::create(['business_id' => $user->business_id, 'contact_id' => $opt['contact_id'] ?? null, 'user_id' => $user->id, 'archivo' => $opt['archivo'] ?? 'lista', 'mapeo' => $mapeo]);
        $leidos = $creados = $act = $err = 0; $detalle = []; $rubros = [];

        DB::transaction(function () use ($filas, $col, $opt, $numS, $margen, $rubroDefault, $ivaDefault, $usd, $crear, $soloProveedor, $user, &$leidos, &$creados, &$act, &$err, &$detalle, &$rubros) {
            foreach ($filas as $n => $f) {
                if ($n === 0 && ($opt['encabezado'] ?? true)) continue;
                $g = fn($k) => isset($col[$k]) ? trim((string) ($f[$col[$k]] ?? '')) : '';
                $desc = $g('descripcion'); $sku = $g('codigo'); $bar = $g('barcode');
                if ($desc === '' && $sku === '') continue;
                $leidos++;
                $pc = $numS($g('precio_compra')); $dto = $numS($g('descuento')) ?? 0; $costo = $numS($g('costo')); $pv = $numS($g('precio_venta'));
                if ($pc === null && $costo === null && $pv === null) { $err++; $detalle[] = ['fila' => $n + 1, 'desc' => $desc ?: $sku, 'error' => 'Sin precio']; continue; }
                $iva = $numS($g('iva')); $iva = $iva === null ? $ivaDefault : ($iva > 1 ? $iva : $iva * 100);

                $p = null; $dec = $opt['decisiones'][$n] ?? $opt['decisiones'][(string) $n] ?? null;
                if ($dec === 'omitir') { $leidos--; continue; }
                if ($dec && $dec !== 'nuevo') $p = Product::find((int) $dec);
                if (! $p && $dec !== 'nuevo' && $sku !== '') $p = Product::where('sku', $sku)->first();
                if (! $p && $dec !== 'nuevo' && $bar !== '') $p = Product::where('barcode', $bar)->first();
                if (! $p && $dec !== 'nuevo' && $desc !== '') $p = Product::where('name', $desc)->when($soloProveedor && ($opt['contact_id'] ?? null), fn($q) => $q->where('proveedor_id', $opt['contact_id']))->first();
                if (! $p) {
                    if (! $crear) { $err++; $detalle[] = ['fila' => $n + 1, 'desc' => $desc ?: $sku, 'error' => 'No existe y no se crean nuevos']; continue; }
                    $rubroId = $opt['rubros_fila'][$n] ?? $opt['rubros_fila'][(string) $n] ?? $rubroDefault;
                    if (! empty($opt['nombres'][$n] ?? $opt['nombres'][(string) $n] ?? null)) $desc = trim($opt['nombres'][$n] ?? $opt['nombres'][(string) $n]);
                    if (($rn = $g('rubro')) !== '' && ! ($opt['rubros_fila'][$n] ?? null)) {
                        $rubroId = $rubros[$rn] ??= (Rubro::where('nombre', $rn)->value('id') ?? Rubro::create(['business_id' => $user->business_id, 'nombre' => $rn])->id);
                    }
                    $p = new Product(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'name' => $desc ?: $sku, 'sku' => $sku ?: (strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($desc)), 0, 6)) . '-' . str_pad((string) (Product::withTrashed()->count() + $creados + 1), 4, '0', STR_PAD_LEFT)), 'barcode' => $bar ?: null, 'tipo' => 'producto', 'unit' => $g('unidad') ?: 'un', 'rubro_id' => $rubroId, 'marca' => $g('marca') ?: null, 'iva' => $iva, 'stock' => 0, 'stock_min' => 0, 'active' => true, 'controla_stock' => true, 'proveedor_id' => $opt['contact_id'] ?? null, 'price' => 0, 'cost' => 0]);
                    $creados++;
                } else {
                    $act++;
                    if ($bar !== '' && ! $p->barcode) $p->barcode = $bar;
                    if (($opt['contact_id'] ?? null) && ! $p->proveedor_id) $p->proveedor_id = $opt['contact_id'];
                }
                $p->moneda = $usd ? 'USD' : ($p->moneda ?: 'ARS');
                if ($pc !== null) { $p->precio_compra = $pc; $p->descuento_proveedor = $dto; $p->cost = round($pc * (1 - $dto / 100), 2); }
                if ($costo !== null) { $p->cost = $costo; if ($pc === null) $p->precio_compra = $costo; }
                if ($margen !== null) { $p->margenes = array_replace($p->margenes ?? [], ['1' => $margen]); }
                $p->recalcularDesdeCosto();
                if ($pv !== null) $p->price = $pv;
                if ((float) $p->price <= 0 && (float) $p->cost > 0) $p->price = round((float) $p->cost * 1.3, 2);
                $p->precio_actualizado_en = now();
                $p->save();
            }
        });
        $imp->update(['leidos' => $leidos, 'creados' => $creados, 'actualizados' => $act, 'errores' => $err, 'detalle' => array_slice($detalle, 0, 200)]);
        AuditLog::registrar('editar', $imp, "Importó lista de precios: {$leidos} filas, {$creados} nuevos, {$act} actualizados, {$err} con error");
        return $imp;
    }
}
