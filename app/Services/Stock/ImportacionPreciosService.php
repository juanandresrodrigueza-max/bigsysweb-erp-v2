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
                str_contains($h, 'desc') || str_contains($h, 'nombre') || str_contains($h, 'detalle') || str_contains($h, 'producto') => 'descripcion',
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

    // Aplica la importación. $mapeo: [indiceColumna => campo]. Busca por SKU, después por barras, después por descripción + proveedor.
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

                $p = null;
                if ($sku !== '') $p = Product::where('sku', $sku)->first();
                if (! $p && $bar !== '') $p = Product::where('barcode', $bar)->first();
                if (! $p && $desc !== '') $p = Product::where('name', $desc)->when($soloProveedor && ($opt['contact_id'] ?? null), fn($q) => $q->where('proveedor_id', $opt['contact_id']))->first();
                if (! $p) {
                    if (! $crear) { $err++; $detalle[] = ['fila' => $n + 1, 'desc' => $desc ?: $sku, 'error' => 'No existe y no se crean nuevos']; continue; }
                    $rubroId = $rubroDefault;
                    if (($rn = $g('rubro')) !== '') {
                        $rubroId = $rubros[$rn] ??= (Rubro::where('nombre', $rn)->value('id') ?? Rubro::create(['business_id' => $user->business_id, 'nombre' => $rn])->id);
                    }
                    $p = new Product(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'name' => $desc ?: $sku, 'sku' => $sku ?: null, 'barcode' => $bar ?: null, 'tipo' => 'producto', 'unit' => $g('unidad') ?: 'un', 'rubro_id' => $rubroId, 'marca' => $g('marca') ?: null, 'iva' => $iva, 'stock' => 0, 'stock_min' => 0, 'active' => true, 'controla_stock' => true, 'proveedor_id' => $opt['contact_id'] ?? null, 'price' => 0, 'cost' => 0]);
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
