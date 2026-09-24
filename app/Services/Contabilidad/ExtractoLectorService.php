<?php

namespace App\Services\Contabilidad;

use App\Services\Stock\ImportacionPreciosService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

// Lee el extracto bancario tal como lo baja el home banking: PDF, Excel, CSV o texto, de cualquier banco.
// Devuelve renglones normalizados: [fecha Y-m-d, descripcion, monto (+ crédito / − débito), saldo|null, referencia|null].
class ExtractoLectorService
{
    public function __construct(private ImportacionPreciosService $planillas) {}

    public function leer(string $path, string $nombre): array
    {
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $filas = match (true) {
            $ext === 'pdf' => $this->desdePdf($path),
            in_array($ext, ['xlsx', 'xls'], true) => $this->desdeTabla($this->planillas->leer($path, $nombre, 20000)),
            default => $this->desdeTexto(file_get_contents($path)),
        };
        if (! $filas) throw ValidationException::withMessages(['archivo' => 'No pude reconocer movimientos en el archivo. Probá con el CSV o Excel que exporta el home banking, o mandame el PDF de "Movimientos" (no el resumen de tarjeta).']);
        return $filas;
    }

    // ---- CSV / texto -------------------------------------------------------------------
    private function desdeTexto(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        if (! mb_check_encoding($contenido, 'UTF-8')) $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
        $lineas = array_values(array_filter(preg_split('/\r\n|\r|\n/', $contenido), fn($l) => trim($l) !== ''));
        if (count($lineas) < 2) return [];
        // Separador: el que más aparece en las primeras líneas con fecha.
        $muestra = implode("\n", array_slice($lineas, 0, 30));
        $sep = collect([';', "\t", '|', ','])->sortByDesc(fn($s) => substr_count($muestra, $s))->first();
        $tabla = array_map(fn($l) => array_map('trim', str_getcsv($l, $sep)), $lineas);
        $r = $this->desdeTabla($tabla);
        // Texto sin columnas (extracto copiado del navegador): renglón por renglón.
        return $r ?: $this->desdeLineas($lineas);
    }

    // ---- Tabla (CSV/Excel) con o sin encabezado -------------------------------------------
    private function desdeTabla(array $tabla): array
    {
        $tabla = array_values(array_filter($tabla, fn($f) => count(array_filter($f, fn($c) => trim((string) $c) !== '')) >= 2));
        if (count($tabla) < 2) return [];
        // 1) Encabezado conocido en alguna de las primeras 15 filas (los bancos ponen títulos y datos de la cuenta arriba).
        for ($h = 0; $h < min(15, count($tabla)); $h++) {
            $header = array_map(fn($x) => $this->norm((string) $x), $tabla[$h]);
            $col = fn(array $alias) => collect($alias)->map(fn($a) => array_search($a, $header, true))->first(fn($i) => $i !== false);
            $iFecha = $col(['fecha', 'fecha operacion', 'fecha mov', 'fecha movimiento', 'date', 'fecha valor', 'f operacion', 'fecha de operacion', 'fec']);
            $iDeb = $col(['debito', 'debitos', 'debe', 'egreso', 'egresos', 'salida', 'debit', 'retiro', 'retiros']);
            $iCred = $col(['credito', 'creditos', 'haber', 'ingreso', 'ingresos', 'entrada', 'credit', 'deposito', 'depositos']);
            $iImp = $col(['importe', 'monto', 'amount', 'valor', 'importe pesos', 'importe ars', 'importe $']);
            if ($iFecha === null || ($iImp === null && $iDeb === null && $iCred === null)) continue;
            $iDesc = $col(['descripcion', 'concepto', 'detalle', 'movimiento', 'leyenda', 'description', 'operacion', 'referencia del movimiento', 'tipo de movimiento']);
            $iSaldo = $col(['saldo', 'balance', 'saldo pesos', 'saldo ars']);
            $iRef = $col(['referencia', 'comprobante', 'nro comprobante', 'numero', 'id operacion', 'reference', 'codigo', 'nro operacion', 'n operacion', 'nro', 'origen']);
            $iDC = $col(['d c', 'dc', 'tipo', 'signo', 'd/c']);
            $out = [];
            foreach (array_slice($tabla, $h + 1) as $r) {
                $fecha = $this->fecha((string) ($r[$iFecha] ?? '')); if (! $fecha) continue;
                if ($iImp !== null) { $monto = $this->num((string) ($r[$iImp] ?? '')); $dc = $iDC !== null ? strtoupper(trim((string) ($r[$iDC] ?? ''))) : ''; if ($dc === 'D' && $monto > 0) $monto = -$monto; }
                else $monto = abs($this->num((string) ($r[$iCred] ?? ''))) - abs($this->num((string) ($r[$iDeb] ?? '')));
                if (abs($monto) < 0.005) continue;
                $out[] = ['fecha' => $fecha, 'descripcion' => trim((string) ($iDesc !== null ? ($r[$iDesc] ?? '') : '')) ?: 'Movimiento', 'monto' => round($monto, 2), 'saldo' => $iSaldo !== null && trim((string) ($r[$iSaldo] ?? '')) !== '' ? $this->num((string) $r[$iSaldo]) : null, 'referencia' => $iRef !== null ? (trim((string) ($r[$iRef] ?? '')) ?: null) : null];
            }
            if ($out) return $this->corregirSignos($out);
        }
        // 2) Sin encabezado: la columna con fechas, las numéricas (importe y saldo) y el texto más largo como descripción.
        $filasFecha = array_values(array_filter($tabla, fn($f) => collect($f)->contains(fn($c) => $this->fecha((string) $c))));
        if (count($filasFecha) < 1) return [];
        $out = [];
        foreach ($filasFecha as $r) {
            $fecha = null; $nums = []; $textos = [];
            foreach ($r as $i => $c) { $c = trim((string) $c); if ($c === '') continue; if (! $fecha && ($f = $this->fecha($c))) { $fecha = $f; continue; } if ($this->esNumero($c)) $nums[$i] = $this->num($c); else $textos[] = $c; }
            if (! $fecha || ! $nums) continue;
            // Último número = saldo si hay 2 o más; el anterior es el importe.
            $vals = array_values($nums);
            [$monto, $saldo] = count($vals) >= 2 ? [$vals[count($vals) - 2], $vals[count($vals) - 1]] : [$vals[0], null];
            if (count($vals) >= 3 && abs($vals[count($vals) - 2]) < 0.005) $monto = $vals[count($vals) - 3]; // columnas débito | crédito | saldo con una vacía en cero
            if (abs($monto) < 0.005) continue;
            usort($textos, fn($a, $b) => strlen($b) <=> strlen($a));
            $out[] = ['fecha' => $fecha, 'descripcion' => $textos[0] ?? 'Movimiento', 'monto' => round($monto, 2), 'saldo' => $saldo, 'referencia' => isset($textos[1]) && preg_match('/\d{4,}/', $textos[1]) ? $textos[1] : null];
        }
        return $this->corregirSignos($out);
    }

    // ---- PDF -------------------------------------------------------------------------------
    private function desdePdf(string $path): array
    {
        $texto = '';
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            foreach ($pdf->getPages() as $pg) $texto .= $pg->getText() . "\n";
        } catch (\Throwable $e) { $texto = ''; }
        $texto = preg_replace('/[ \t]+/', ' ', $texto);
        $lineas = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $texto))));
        $filas = $this->desdeLineas($lineas);
        if (count($filas) >= 1) return $filas;
        // PDF escaneado o con un layout raro: se lo pedimos a la IA si hay clave; si no, avisamos.
        return $this->desdePdfConIA($path, $texto);
    }

    // Renglones de texto: cada movimiento empieza con una fecha y termina con importe y saldo. Las líneas sin fecha continúan la descripción anterior.
    private function desdeLineas(array $lineas): array
    {
        $out = []; $actual = null;
        // Importes con dos decimales; sin límite por palabra porque en los PDF suelen venir pegados ("458.900,001.708.900,00").
        $reNum = '-?\$?\s?\(?(?:\d+(?:\.\d{3})*,\d{2}|\d+(?:,\d{3})*\.\d{2})\)?-?';
        foreach ($lineas as $l) {
            if (preg_match('/^(\d{1,2}[\/\-.]\d{1,2}(?:[\/\-.]\d{2,4})?)\s*(.*)$/u', $l, $m) && ($fecha = $this->fecha($m[1]))) {
                if ($actual) $out[] = $actual;
                $resto = $m[2];
                preg_match_all("/($reNum)/u", $resto, $nn);
                $nums = array_map(fn($x) => $this->num($x), $nn[1]);
                // Quitar la segunda fecha (fecha valor) si viene pegada.
                $resto = preg_replace('/^\d{1,2}[\/\-.]\d{1,2}(?:[\/\-.]\d{2,4})?\s+/', '', $resto);
                $desc = trim(preg_replace("/($reNum)/u", ' ', $resto));
                $desc = trim(preg_replace('/\s+[DC]\s*$/', '', $desc));
                $signo = preg_match('/\s[D-]\s*$|\bDEB\b|-\s*$/i', $resto) ? -1 : (preg_match('/\sC\s*$|\bCRED\b/i', $resto) ? 1 : 0);
                $actual = ['fecha' => $fecha, 'descripcion' => $desc ?: 'Movimiento', 'nums' => $nums, 'signo' => $signo, 'referencia' => preg_match('/\b(\d{6,})\b/', $desc, $rf) ? $rf[1] : null];
            } elseif ($actual && ! preg_match('/^(saldo|total|pagina|página|hoja)/i', $l) && strlen($l) < 120) {
                if (! $actual['nums']) { preg_match_all("/($reNum)/u", $l, $nn); $actual['nums'] = array_map(fn($x) => $this->num($x), $nn[1]); $l = trim(preg_replace("/($reNum)/u", ' ', $l)); }
                if ($l !== '') $actual['descripcion'] = trim($actual['descripcion'] . ' ' . $l);
            }
        }
        if ($actual) $out[] = $actual;
        $filas = [];
        foreach ($out as $r) {
            $n = $r['nums']; if (! $n) continue;
            if (preg_match('/saldo\s+(anterior|inicial|final|al)|^saldo$/iu', $r['descripcion'])) continue;
            $monto = count($n) >= 2 ? $n[count($n) - 2] : $n[0]; $saldo = count($n) >= 2 ? $n[count($n) - 1] : null;
            if (count($n) >= 3 && abs($n[count($n) - 2]) < 0.005) $monto = $n[count($n) - 3];
            if (abs($monto) < 0.005) continue;
            if ($r['signo'] === -1 && $monto > 0) $monto = -$monto;
            $filas[] = ['fecha' => $r['fecha'], 'descripcion' => mb_substr($r['descripcion'], 0, 250), 'monto' => round($monto, 2), 'saldo' => $saldo, 'referencia' => $r['referencia'], 'signo_leido' => $r['signo'] !== 0];
        }
        return $this->corregirSignos($filas);
    }

    // Con la columna de saldo se sabe el sentido de cada movimiento sin ambigüedad: saldo nuevo − saldo anterior. Corrige débitos que el banco lista sin signo.
    private function corregirSignos(array $filas): array
    {
        $conSaldo = array_filter($filas, fn($f) => $f['saldo'] !== null);
        if (count($conSaldo) >= 2) {
            $prev = null;
            foreach ($filas as $i => $f) {
                if ($f['saldo'] === null) continue;
                if ($prev !== null) { $delta = round($f['saldo'] - $prev, 2); if (abs(abs($delta) - abs($f['monto'])) < 0.02) $filas[$i]['monto'] = $delta < 0 ? -abs($f['monto']) : abs($f['monto']); }
                $prev = $f['saldo'];
            }
            // El primer renglón se deduce del segundo hacia atrás.
            $idx = array_keys($conSaldo); if (count($idx) >= 2) { $a = $filas[$idx[0]]; $b = $filas[$idx[1]]; $deltaB = round($b['saldo'] - $a['saldo'], 2); if (abs(abs($deltaB) - abs($b['monto'])) < 0.02) { /* ya corregido arriba */ } }
        }
        foreach ($filas as &$f) unset($f['signo_leido']);
        return array_values($filas);
    }

    private function desdePdfConIA(string $path, string $textoPlano): array
    {
        $key = config('services.anthropic.api_key');
        if (! $key) return [];
        try {
            $content = [['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(file_get_contents($path))]], ['type' => 'text', 'text' => 'Este es un extracto bancario argentino. Devolvé SOLO un JSON: {"movimientos":[{"fecha":"YYYY-MM-DD","descripcion":"...","monto":-1234.56,"saldo":12345.67,"referencia":"..."}]} con monto negativo para débitos y positivo para créditos. Sin comentarios.']];
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])->timeout(120)->post('https://api.anthropic.com/v1/messages', ['model' => 'claude-sonnet-5', 'max_tokens' => 8000, 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (! $r->successful()) return [];
            $txt = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
            $json = json_decode(substr($txt, strpos($txt, '{'), strrpos($txt, '}') - strpos($txt, '{') + 1), true);
            return collect($json['movimientos'] ?? [])->map(fn($m) => ['fecha' => $this->fecha((string) ($m['fecha'] ?? '')), 'descripcion' => mb_substr((string) ($m['descripcion'] ?? 'Movimiento'), 0, 250), 'monto' => round((float) ($m['monto'] ?? 0), 2), 'saldo' => isset($m['saldo']) ? (float) $m['saldo'] : null, 'referencia' => $m['referencia'] ?? null])->filter(fn($m) => $m['fecha'] && abs($m['monto']) > 0.005)->values()->all();
        } catch (\Throwable $e) { return []; }
    }

    // ---- helpers ---------------------------------------------------------------------------
    private function norm(string $s): string { return trim(preg_replace('/[^a-z0-9 \/$]/', ' ', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s))); }

    private function esNumero(string $s): bool { return (bool) preg_match('/^-?\$?\s?\(?-?\d+(?:[.,]\d{3})*(?:[.,]\d{1,2})?\)?-?$/', trim($s)) && preg_match('/\d/', $s) && ! $this->fecha($s); }

    public function num(string $s): float
    {
        $s = trim($s); if ($s === '') return 0;
        $neg = str_starts_with($s, '-') || str_ends_with($s, '-') || (str_starts_with($s, '(') && str_ends_with($s, ')'));
        $s = preg_replace('/[^\d,.]/', '', $s);
        if (preg_match('/,\d{1,2}$/', $s)) $s = str_replace('.', '', $s); // 1.234,56
        elseif (preg_match('/\.\d{1,2}$/', $s) && substr_count($s, ',') > 0) $s = str_replace(',', '', $s); // 1,234.56
        elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s); // 1.234 (miles sin decimales)
        $v = (float) str_replace(',', '.', $s);
        return $neg ? -$v : $v;
    }

    public function fecha(string $v): ?string
    {
        $v = trim($v); if ($v === '' || strlen($v) > 12) return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})(?:[\/\-.](\d{2,4}))?$/', $v, $m)) {
            $y = isset($m[3]) && $m[3] !== '' ? (strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]) : (string) today()->year;
            return checkdate((int) $m[2], (int) $m[1], (int) $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        if (is_numeric($v) && (float) $v > 30000 && (float) $v < 80000) return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString(); // serial de Excel
        return null;
    }
}
