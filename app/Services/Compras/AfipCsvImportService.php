<?php

namespace App\Services\Compras;

use App\Models\Comprobante;
use App\Models\Contact;
use Illuminate\Support\Facades\Auth;

// Importa el CSV de "Mis Comprobantes Recibidos" de AFIP y crea las facturas de compra que falten.
class AfipCsvImportService
{
    private const TIPOS = [1 => 'FA', 6 => 'FB', 11 => 'FC', 19 => 'FE', 2 => 'NDA', 7 => 'NDB', 12 => 'NDC', 3 => 'NCA', 8 => 'NCB', 13 => 'NCC', 51 => 'FA', 201 => 'FA', 206 => 'FB', 211 => 'FC'];

    public function __construct(private CompraService $compras) {}

    // Las filas del CSV normalizadas: una por comprobante, con su clave (cuit|tipo|numero) para cruzar.
    public function filas(string $contenido): array
    {
        $lineas = preg_split('/\r\n|\n|\r/', trim($contenido));
        if (! $lineas || trim($lineas[0]) === '') return [];
        $sep = substr_count($lineas[0], ';') >= substr_count($lineas[0], ',') ? ';' : ',';
        $cab = array_map(fn($h) => $this->clave($h), str_getcsv(array_shift($lineas), $sep));
        $out = [];
        foreach ($lineas as $n => $linea) {
            if (trim($linea) === '') continue;
            $f = array_combine($cab, array_pad(str_getcsv($linea, $sep), count($cab), null));
            $tipoCod = (int) ($f['tipo'] ?? $f['tipo_comprobante'] ?? $f['tipo_de_comprobante'] ?? 0);
            $pv = (int) ($f['punto_de_venta'] ?? 0);
            $num = (int) ($f['numero_desde'] ?? $f['numero'] ?? 0);
            $cuit = preg_replace('/\D/', '', (string) ($f['nro_doc_emisor'] ?? $f['nro_doc_vendedor'] ?? $f['cuit'] ?? ''));
            $neto = $this->num($f['imp_neto_gravado'] ?? $f['neto_gravado'] ?? 0);
            $exento = $this->num($f['imp_neto_no_gravado'] ?? 0) + $this->num($f['imp_op_exentas'] ?? 0);
            $iva = $this->num($f['iva'] ?? $f['imp_iva'] ?? 0);
            $total = $this->num($f['imp_total'] ?? $f['total'] ?? 0);
            $tipo = self::TIPOS[$tipoCod] ?? null;
            $numero = sprintf('%04d-%08d', $pv, $num);
            $out[] = ['fila' => $n + 2, 'tipo_cod' => $tipoCod, 'tipo' => $tipo, 'numero' => $numero, 'cuit' => $cuit, 'nombre' => trim((string) ($f['denominacion_emisor'] ?? $f['denominacion_vendedor'] ?? $f['razon_social'] ?? '')),
                'fecha' => $this->fecha($f['fecha'] ?? $f['fecha_de_emision'] ?? ''), 'neto' => $neto, 'exento' => $exento, 'iva' => $iva, 'total' => $total, 'otros' => max(0, round($total - $neto - $exento - $iva, 2)),
                'cae' => $f['cod_autorizacion'] ?? null, 'clave' => "{$cuit}|{$tipo}|{$numero}"];
        }
        return $out;
    }

    public function importar(string $contenido, bool $registrar = false, ?array $solo = null): array
    {
        $res = ['creadas' => 0, 'existentes' => 0, 'errores' => [], 'proveedores_nuevos' => 0];
        $user = Auth::user();
        $provs = Contact::suppliers()->get();

        foreach ($this->filas($contenido) as $f) {
            if ($solo !== null && ! in_array($f['clave'], $solo)) continue;
            try {
                $tipo = $f['tipo'];
                if (! $tipo) {
                    $res['errores'][] = "Fila {$f['fila']}: tipo de comprobante {$f['tipo_cod']} no soportado.";
                    continue;
                }
                $cuit = $f['cuit']; $numero = $f['numero']; $nombre = $f['nombre']; $fecha = $f['fecha'];
                $neto = $f['neto']; $exento = $f['exento']; $iva = $f['iva']; $total = $f['total']; $otros = $f['otros'];

                $prov = $cuit ? $provs->first(fn($c) => preg_replace('/\D/', '', (string) $c->cuit) === $cuit) : null;
                if (! $prov) {
                    $prov = Contact::create(['business_id' => $user->business_id, 'type' => 'supplier', 'name' => $nombre ?: "Proveedor {$cuit}", 'cuit' => $cuit ? $this->cuitFmt($cuit) : null, 'condicion_iva' => 'Responsable Inscripto']);
                    $provs->push($prov);
                    $res['proveedores_nuevos']++;
                }
                if (Comprobante::compras()->where('contact_id', $prov->id)->where('tipo', $tipo)->where('numero_proveedor', $numero)->exists()) {
                    $res['existentes']++;
                    continue;
                }
                $alic = $neto > 0 ? round($iva / $neto * 100, 1) : 0;
                $alic = collect([0, 2.5, 5, 10.5, 21, 27])->sortBy(fn($a) => abs($a - $alic))->first();
                $items = [];
                if ($neto > 0) $items[] = ['descripcion' => 'Según comprobante AFIP (gravado)', 'cantidad' => 1, 'precio_unit' => $neto, 'alicuota_iva' => $alic];
                if ($exento > 0) $items[] = ['descripcion' => 'Según comprobante AFIP (exento / no gravado)', 'cantidad' => 1, 'precio_unit' => $exento, 'alicuota_iva' => 0];
                if (! $items) $items[] = ['descripcion' => 'Según comprobante AFIP', 'cantidad' => 1, 'precio_unit' => $total, 'alicuota_iva' => 0];

                $c = $this->compras->guardarBorrador([
                    'contact_id' => $prov->id, 'tipo' => $tipo, 'numero_proveedor' => $numero, 'fecha' => $fecha, 'condicion' => 'cta_cte', 'origen_carga' => 'afip_csv',
                    'cae_proveedor' => $f['cae'], 'items' => $items, 'impuestos' => $otros > 0 ? [['tipo' => 'otros', 'monto' => $otros]] : [],
                    'notas' => 'Importado de AFIP Mis Comprobantes. Completá los artículos si querés que impacte en stock.',
                ]);
                if ($registrar) {
                    $this->compras->registrar($c);
                }
                $res['creadas']++;
            } catch (\Throwable $e) {
                $res['errores'][] = "Fila {$f['fila']}: " . $e->getMessage();
            }
        }
        return $res;
    }

    // Fase 31.6 · Qué me falta cargar: ¿hay comprobantes de proveedores que ARCA tiene y el sistema no? Cada uno es IVA que no te estás tomando.
    // Devuelve las que faltan (ordenadas por IVA), las cargadas sin CUIT, las cargadas por otro importe, las cargadas dos veces y las que no cierran.
    public function cruzar(string $contenido): array
    {
        $filas = $this->filas($contenido);
        $filas = array_values(array_filter($filas, fn($f) => $f['tipo']));
        $fechas = array_column($filas, 'fecha'); sort($fechas);
        $desde = $fechas[0] ?? today()->startOfMonth()->toDateString(); $hasta = end($fechas) ?: today()->toDateString();
        $provs = Contact::suppliers()->get(['id', 'name', 'cuit']);
        $porCuit = []; foreach ($provs as $p) { $k = preg_replace('/\D/', '', (string) $p->cuit); if ($k !== '') $porCuit[$k] = $p; }
        $cargadas = Comprobante::compras()->with('contact:id,name,cuit')->whereBetween('fecha', [\Carbon\Carbon::parse($desde)->subDays(45)->toDateString(), \Carbon\Carbon::parse($hasta)->addDays(45)->toDateString()])->where('estado', '!=', 'anulado')->get();
        $porNumero = $cargadas->groupBy(fn($c) => "{$c->tipo}|{$c->numero_proveedor}");

        $res = ['periodo' => ['desde' => $desde, 'hasta' => $hasta], 'filas' => count($filas), 'ok' => 0, 'faltan' => [], 'sin_cuit' => [], 'otro_importe' => [], 'duplicadas' => [], 'no_cierran' => [], 'iva_faltante' => 0.0, 'proveedores_nuevos' => 0];
        foreach ($filas as $f) {
            $prov = $porCuit[$f['cuit']] ?? null;
            $match = $prov ? $cargadas->first(fn($c) => $c->contact_id === $prov->id && $c->tipo === $f['tipo'] && $c->numero_proveedor === $f['numero']) : null;
            $fila = ['clave' => $f['clave'], 'tipo' => $f['tipo'], 'numero' => $f['numero'], 'fecha' => $f['fecha'], 'cuit' => $this->cuitFmt($f['cuit']), 'proveedor' => $prov?->name ?: $f['nombre'], 'proveedor_nuevo' => ! $prov, 'neto' => $f['neto'], 'iva' => $f['iva'], 'total' => $f['total']];
            if ($match) {
                if (abs((float) $match->total - $f['total']) > 0.05) $res['otro_importe'][] = $fila + ['compra_id' => $match->id, 'total_cargado' => (float) $match->total, 'diferencia' => round((float) $match->total - $f['total'], 2)];
                else $res['ok']++;
                continue;
            }
            // mismo tipo y número cargado a un proveedor sin CUIT (o con otro CUIT): está, pero el libro de IVA no cruza
            $sinCuit = ($porNumero["{$f['tipo']}|{$f['numero']}"] ?? collect())->first(fn($c) => preg_replace('/\D/', '', (string) $c->contact?->cuit) === '' && abs((float) $c->total - $f['total']) <= 0.05);
            if ($sinCuit) { $res['sin_cuit'][] = $fila + ['compra_id' => $sinCuit->id, 'contact_id' => $sinCuit->contact_id, 'cargada_a' => $sinCuit->contact?->name]; continue; }
            $res['faltan'][] = $fila; $res['iva_faltante'] += $f['iva']; if (! $prov) $res['proveedores_nuevos']++;
        }
        usort($res['faltan'], fn($a, $b) => $b['iva'] <=> $a['iva']);
        $res['iva_faltante'] = round($res['iva_faltante'], 2);
        // Lo que se controla sobre lo cargado en el período, sin el CSV: dos veces y los números que no cierran.
        $enPeriodo = $cargadas->filter(fn($c) => $c->fecha->toDateString() >= $desde && $c->fecha->toDateString() <= $hasta && $c->numero_proveedor);
        foreach ($enPeriodo->groupBy(fn($c) => "{$c->contact_id}|{$c->tipo}|{$c->numero_proveedor}") as $g) {
            if ($g->count() > 1) $res['duplicadas'][] = ['proveedor' => $g->first()->contact?->name, 'tipo' => $g->first()->tipo, 'numero' => $g->first()->numero_proveedor, 'veces' => $g->count(), 'ids' => $g->pluck('id')->all(), 'total' => (float) $g->first()->total, 'iva' => (float) $g->first()->iva];
        }
        foreach ($enPeriodo as $c) {
            $suma = round((float) $c->neto + (float) $c->exento + (float) $c->iva + (float) $c->percepciones - (float) $c->descuento, 2);
            if (abs($suma - (float) $c->total) > 0.05) $res['no_cierran'][] = ['compra_id' => $c->id, 'proveedor' => $c->contact?->name, 'tipo' => $c->tipo, 'numero' => $c->numero_proveedor, 'total' => (float) $c->total, 'suma' => $suma, 'diferencia' => round((float) $c->total - $suma, 2)];
        }
        return $res;
    }

    private function clave(string $h): string
    {
        $h = mb_strtolower(trim($h, " \t\"\xEF\xBB\xBF"));
        $h = strtr($h, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', '.' => '', '(' => '', ')' => '']);
        return trim(preg_replace('/[^a-z0-9]+/', '_', $h), '_');
    }

    private function num($v): float
    {
        $v = trim((string) $v);
        if ($v === '') return 0.0;
        if (str_contains($v, ',') && (! str_contains($v, '.') || strrpos($v, ',') > strrpos($v, '.'))) {
            $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v);
        } else {
            $v = str_replace(',', '', $v);
        }
        return (float) $v;
    }

    private function fecha(string $v): string
    {
        $v = trim($v);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Ymd'] as $fmt) {
            try { return \Carbon\Carbon::createFromFormat($fmt, $v)->toDateString(); } catch (\Throwable) {}
        }
        return today()->toDateString();
    }

    private function cuitFmt(string $c): string
    {
        return strlen($c) === 11 ? substr($c, 0, 2) . '-' . substr($c, 2, 8) . '-' . substr($c, 10) : $c;
    }
}
