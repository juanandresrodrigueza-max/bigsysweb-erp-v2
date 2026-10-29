<?php

namespace App\Services\Fondos;

use App\Models\Business;
use App\Services\Stock\ImportacionPreciosService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

// Fase 35.2: lee el archivo que manda la procesadora o la billetera (Excel o CSV, tal como viene) y lo deja en operaciones iguales
// para todas: fecha de venta, importe, cupón, cuotas, fecha de pago, neto, comisión, IVA, retenciones, terminal y acreditación.
// Reconoce de quién es (Fiserv, Naranja X, GOcuotas, Mercado Pago, NAVE) por el contenido; Payway, Getnet y MODO esperan un archivo real.
class LiquidacionLectorService
{
    public function __construct(private ImportacionPreciosService $planillas) {}

    // Alias de encabezado por campo (normalizados: minúsculas, sin acentos ni signos). El orden importa: el primero que aparece gana.
    private const ALIAS = [
        'fecha_venta' => ['fecha de venta', 'fecha venta', 'fecha de la operacion', 'fecha operacion', 'fecha de operacion', 'fecha de compra', 'fecha de aprobacion', 'date approved', 'date created', 'fecha de creacion', 'fecha transaccion', 'fecha de transaccion', 'fecha'],
        'importe' => ['importe bruto', 'monto bruto', 'importe de la venta', 'importe venta', 'monto de la transaccion', 'transaction amount', 'valor de la transaccion', 'importe total', 'monto total', 'importe', 'monto', 'total', 'bruto'],
        'cupon' => ['nro de cupon', 'nro cupon', 'n cupon', 'numero de cupon', 'cupon', 'nro de ticket', 'nro ticket', 'ticket', 'numero de orden', 'nro de orden', 'orden', 'codigo de operacion', 'numero de operacion', 'nro de operacion', 'nro operacion', 'id de operacion', 'operacion', 'source id', 'external reference', 'referencia externa', 'id de pago', 'payment id', 'autorizacion', 'nro autorizacion', 'codigo de autorizacion'],
        'cuotas' => ['cuotas', 'cantidad de cuotas', 'plan', 'installments', 'cant cuotas'],
        'fecha_pago' => ['fecha de pago', 'fecha pago', 'fecha de acreditacion', 'fecha acreditacion', 'fecha de liquidacion', 'fecha liquidacion', 'fecha de liberacion', 'money release date', 'fecha de deposito', 'fecha presentacion'],
        'neto' => ['importe neto', 'neto a pagar', 'monto neto recibido', 'net received amount', 'monto neto', 'neto', 'a cobrar', 'total a cobrar', 'importe a acreditar', 'acreditado'],
        'comision' => ['arancel', 'comision de mercado pago', 'mp fee amount', 'comision', 'costo financiero', 'fee amount', 'tarifa', 'costo por procesamiento', 'descuento arancel'],
        'iva' => ['iva arancel', 'iva comision', 'iva sobre arancel', 'iva s arancel', 'taxes amount', 'impuestos', 'iva'],
        'ret_iva' => ['retencion iva', 'ret iva', 'retencion de iva', 'ret de iva', 'retenciones iva'],
        'ret_iibb' => ['retencion iibb', 'ret iibb', 'retencion ingresos brutos', 'ret ingresos brutos', 'percepcion iibb', 'sircreb', 'retenciones iibb', 'retencion ib'],
        'ret_ganancias' => ['retencion ganancias', 'ret ganancias', 'retencion de ganancias', 'retenciones ganancias'],
        'otros' => ['otros descuentos', 'otros debitos', 'otros', 'ajustes', 'cargos', 'cargo financiero'],
        'terminal' => ['nro de terminal', 'terminal', 'nro de comercio', 'numero de comercio', 'comercio', 'establecimiento', 'sucursal', 'pos', 'punto de venta', 'caja', 'store id', 'pos id'],
        'acreditacion' => ['nro de liquidacion', 'numero de liquidacion', 'liquidacion', 'grupo de acreditacion', 'grupo', 'settlement', 'lote de pago', 'nro de lote', 'lote', 'id de liquidacion'],
        'tarjeta' => ['tarjeta', 'marca', 'medio de pago', 'payment method', 'tipo de tarjeta', 'producto'],
        'estado' => ['estado', 'status', 'estado de la operacion', 'situacion'],
    ];

    private const HUELLAS = ['fiserv' => ['fiserv', 'posnet', 'first data', 'firstdata'], 'naranjax' => ['naranja x', 'naranjax', 'naranja'], 'gocuotas' => ['gocuotas', 'go cuotas'], 'mercadopago' => ['mercado pago', 'mercadopago', 'source id', 'money release', 'settlement report', 'mp fee'], 'nave' => ['nave', 'codigo de operacion', 'grupo de acreditacion']];

    public function leer(string $path, string $nombre, ?string $origenForzado = null): array
    {
        $tabla = $this->planillas->leer($path, $nombre, 50000);
        if (count($tabla) < 2) throw ValidationException::withMessages(['archivo' => 'El archivo está vacío o no tiene renglones.']);
        $texto = mb_strtolower(implode(' ', array_map(fn($f) => implode(' ', array_map('strval', $f)), array_slice($tabla, 0, 25))) . ' ' . $nombre);
        $origen = $origenForzado ?: $this->detectarOrigen($texto);
        // El encabezado puede no ser la primera fila: NAVE y Fiserv ponen títulos y totales arriba.
        $h = null; $mapa = [];
        for ($i = 0; $i < min(30, count($tabla)); $i++) {
            $m = $this->mapear($tabla[$i]);
            if (isset($m['fecha_venta']) && isset($m['importe']) && count($m) >= 3) { $h = $i; $mapa = $m; break; }
        }
        if ($h === null) throw ValidationException::withMessages(['archivo' => 'No encontré las columnas de fecha e importe. Mandame el archivo tal como lo exporta ' . (\App\Models\Conciliacion::ORIGENES[$origen] ?? 'la procesadora') . ' (Excel o CSV, no el PDF).']);
        $encabezado = array_map(fn($c) => trim((string) $c), $tabla[$h]);
        // Control propio del archivo: "Total bruto" en las filas de arriba (NAVE) o una fila "Totales" al final (GOcuotas).
        $totalArchivo = null;
        foreach (array_slice($tabla, 0, $h) as $f) { $l = $this->norm(implode(' ', array_map('strval', $f))); if (preg_match('/total( bruto| ventas)?\b/', $l)) foreach ($f as $c) if (($v = $this->num((string) $c)) !== null && $v > 0) { $totalArchivo = $v; break 2; } }
        $ops = []; $filasDatos = 0; $filasTotales = 0;
        foreach (array_slice($tabla, $h + 1) as $f) {
            $celda = fn($k) => isset($mapa[$k]) ? trim((string) ($f[$mapa[$k]] ?? '')) : '';
            $primera = $this->norm(implode(' ', array_map('strval', array_slice($f, 0, 3))));
            if (preg_match('/^(total|totales|subtotal)/', $primera)) { $filasTotales++; if ($totalArchivo === null && ($v = $this->num($celda('importe'))) !== null && $v > 0) $totalArchivo = $v; continue; }
            $fecha = $this->fecha($celda('fecha_venta')); $importe = $this->num($celda('importe'));
            if (! $fecha || $importe === null) { if (count(array_filter($f, fn($c) => trim((string) $c) !== ''))) $filasDatos++; continue; }
            $filasDatos++;
            $estado = mb_strtolower($celda('estado'));
            if ($estado !== '' && preg_match('/rechaz|cancel|refund|devol|contracargo|chargeback|anulad/', $estado)) continue; // no es una venta a cruzar
            $ops[] = ['fila' => count($ops) + 1, 'fecha_venta' => $fecha, 'importe' => round(abs($importe), 2), 'cupon' => $this->limpiarCupon($celda('cupon')), 'cuotas' => (int) preg_replace('/\D/', '', $celda('cuotas')) ?: 1, 'fecha_pago' => $this->fecha($celda('fecha_pago')), 'neto' => $this->numAbs($celda('neto')), 'comision' => $this->numAbs($celda('comision')) ?? 0, 'iva' => $this->numAbs($celda('iva')) ?? 0, 'ret_iva' => $this->numAbs($celda('ret_iva')) ?? 0, 'ret_iibb' => $this->numAbs($celda('ret_iibb')) ?? 0, 'ret_ganancias' => $this->numAbs($celda('ret_ganancias')) ?? 0, 'otros' => $this->numAbs($celda('otros')) ?? 0, 'terminal' => $celda('terminal') ?: null, 'acreditacion' => $celda('acreditacion') ?: null, 'tarjeta' => $celda('tarjeta') ?: null];
        }
        if (! $ops) throw ValidationException::withMessages(['archivo' => 'Leí el encabezado pero ninguna fila tenía fecha e importe válidos.']);
        // Si no trae el neto por fila pero sí comisión e impuestos, lo deducimos; si no trae nada, se dice.
        foreach ($ops as &$o) if ($o['neto'] === null && ($o['comision'] || $o['iva'] || $o['ret_iva'] || $o['ret_iibb'] || $o['ret_ganancias'])) $o['neto'] = round($o['importe'] - $o['comision'] - $o['iva'] - $o['ret_iva'] - $o['ret_iibb'] - $o['ret_ganancias'] - $o['otros'], 2);
        unset($o);
        $fechas = array_column($ops, 'fecha_venta');
        $formato = $this->formato($origen, array_keys($mapa));
        return ['origen' => $origen, 'operaciones' => $ops, 'encabezado' => $encabezado, 'campos' => array_keys($mapa),
            'faltan' => array_values(array_diff(['cupon', 'fecha_pago', 'neto', 'comision'], array_keys($mapa))),
            'control' => ['filas_archivo' => count($tabla) - $h - 1 - $filasTotales, 'filas_datos' => $filasDatos, 'filas_leidas' => count($ops), 'total_archivo' => $totalArchivo, 'total_leido' => round(array_sum(array_column($ops, 'importe')), 2), 'cierra' => $totalArchivo === null ? null : abs($totalArchivo - array_sum(array_column($ops, 'importe'))) < 0.05],
            'periodo' => ['desde' => min($fechas), 'hasta' => max($fechas)], 'terminales' => array_values(array_unique(array_filter(array_column($ops, 'terminal')))), 'formato' => $formato];
    }

    private function detectarOrigen(string $texto): string
    {
        $t = $this->norm($texto);
        foreach (self::HUELLAS as $o => $hs) foreach ($hs as $h) if (str_contains($t, $h)) return $o;
        return 'otro';
    }

    private function mapear(array $fila): array
    {
        $m = []; $usadas = [];
        $cols = array_map(fn($c) => $this->norm((string) $c), $fila);
        foreach (self::ALIAS as $campo => $aliases) {
            foreach ($aliases as $a) { foreach ($cols as $i => $c) { if ($c === $a && ! isset($usadas[$i])) { $m[$campo] = $i; $usadas[$i] = true; continue 3; } } }
        }
        // Segunda pasada laxa: el alias contenido en la columna ("Importe de la venta (ARS)").
        foreach (self::ALIAS as $campo => $aliases) { if (isset($m[$campo])) continue;
            foreach ($aliases as $a) { if (strlen($a) < 5) continue; foreach ($cols as $i => $c) { if (! isset($usadas[$i]) && $c !== '' && str_contains($c, $a)) { $m[$campo] = $i; $usadas[$i] = true; continue 3; } } }
        }
        return $m;
    }

    // Formato aprendido por procesadora: si cambian las columnas, se avisa qué falta y qué es nuevo (sin dejar de leer lo que se puede).
    private function formato(string $origen, array $campos): array
    {
        $b = Auth::user()?->business; if (! $b) return ['nuevo' => true, 'faltan' => [], 'nuevas' => []];
        $cfg = $b->conciliacion ?? []; $antes = $cfg['formatos'][$origen] ?? null;
        $out = ['nuevo' => $antes === null, 'faltan' => $antes ? array_values(array_diff($antes, $campos)) : [], 'nuevas' => $antes ? array_values(array_diff($campos, $antes)) : []];
        if ($antes === null) { $cfg['formatos'][$origen] = $campos; $b->conciliacion = $cfg; $b->save(); }
        return $out;
    }

    private function limpiarCupon(string $s): ?string { $s = preg_replace('/\s+/', '', $s); return $s === '' ? null : mb_substr($s, 0, 40); }
    private function numAbs(string $s): ?float { $v = $this->num($s); return $v === null ? null : round(abs($v), 2); }

    public function num(string $s): ?float
    {
        $s = trim($s); if ($s === '' || ! preg_match('/\d/', $s)) return null;
        $neg = str_starts_with($s, '-') || str_ends_with($s, '-') || (str_starts_with($s, '(') && str_ends_with($s, ')'));
        $s = preg_replace('/[^\d,.]/', '', $s);
        if (preg_match('/,\d{1,2}$/', $s)) $s = str_replace('.', '', $s);
        elseif (preg_match('/\.\d{1,2}$/', $s) && substr_count($s, ',') > 0) $s = str_replace(',', '', $s);
        elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
        elseif (preg_match('/^\d{1,3}(,\d{3})+$/', $s)) $s = str_replace(',', '', $s);
        $v = (float) str_replace(',', '.', $s);
        return $neg ? -$v : $v;
    }

    public function fecha(string $v): ?string
    {
        $v = trim($v); if ($v === '') return null;
        $v = preg_replace('/[T ]\d{1,2}:\d{2}.*$/', '', $v); // "2026-10-03T14:22:00.000-03:00" → fecha
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $v, $m)) { $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]; return checkdate((int) $m[2], (int) $m[1], (int) $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null; }
        if (is_numeric($v) && (float) $v > 30000 && (float) $v < 80000) return \Carbon\Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString(); // serial de Excel
        return null;
    }

    private function norm(string $s): string { return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s)))); }
}
