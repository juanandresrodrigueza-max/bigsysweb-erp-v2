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

    public function importar(string $contenido, bool $registrar = false): array
    {
        $lineas = preg_split('/\r\n|\n|\r/', trim($contenido));
        $sep = substr_count($lineas[0], ';') >= substr_count($lineas[0], ',') ? ';' : ',';
        $cab = array_map(fn($h) => $this->clave($h), str_getcsv(array_shift($lineas), $sep));

        $res = ['creadas' => 0, 'existentes' => 0, 'errores' => [], 'proveedores_nuevos' => 0];
        $user = Auth::user();

        foreach ($lineas as $n => $linea) {
            if (trim($linea) === '') continue;
            $f = array_combine($cab, array_pad(str_getcsv($linea, $sep), count($cab), null));
            try {
                $tipoCod = (int) ($f['tipo'] ?? $f['tipo_comprobante'] ?? 0);
                $tipo = self::TIPOS[$tipoCod] ?? null;
                if (! $tipo) {
                    $res['errores'][] = 'Fila ' . ($n + 2) . ": tipo de comprobante {$tipoCod} no soportado.";
                    continue;
                }
                $pv = (int) ($f['punto_de_venta'] ?? 0);
                $num = (int) ($f['numero_desde'] ?? $f['numero'] ?? 0);
                $numero = sprintf('%04d-%08d', $pv, $num);
                $cuit = preg_replace('/\D/', '', (string) ($f['nro_doc_emisor'] ?? $f['nro_doc_vendedor'] ?? $f['cuit'] ?? ''));
                $nombre = trim((string) ($f['denominacion_emisor'] ?? $f['denominacion_vendedor'] ?? $f['razon_social'] ?? ''));
                $fecha = $this->fecha($f['fecha'] ?? $f['fecha_de_emision'] ?? '');
                $neto = $this->num($f['imp_neto_gravado'] ?? $f['neto_gravado'] ?? 0);
                $exento = $this->num($f['imp_neto_no_gravado'] ?? 0) + $this->num($f['imp_op_exentas'] ?? 0);
                $iva = $this->num($f['iva'] ?? $f['imp_iva'] ?? 0);
                $total = $this->num($f['imp_total'] ?? $f['total'] ?? 0);
                $otros = max(0, round($total - $neto - $exento - $iva, 2));

                $prov = $cuit ? Contact::suppliers()->get()->first(fn($c) => preg_replace('/\D/', '', (string) $c->cuit) === $cuit) : null;
                if (! $prov) {
                    $prov = Contact::create(['business_id' => $user->business_id, 'type' => 'supplier', 'name' => $nombre ?: "Proveedor {$cuit}", 'cuit' => $cuit ? $this->cuitFmt($cuit) : null, 'condicion_iva' => 'Responsable Inscripto', 'is_active' => true]);
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
                    'cae_proveedor' => $f['cod_autorizacion'] ?? null, 'items' => $items, 'impuestos' => $otros > 0 ? [['tipo' => 'otros', 'monto' => $otros]] : [],
                    'notas' => 'Importado de AFIP Mis Comprobantes. Completá los artículos si querés que impacte en stock.',
                ]);
                if ($registrar) {
                    $this->compras->registrar($c);
                }
                $res['creadas']++;
            } catch (\Throwable $e) {
                $res['errores'][] = 'Fila ' . ($n + 2) . ': ' . $e->getMessage();
            }
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
