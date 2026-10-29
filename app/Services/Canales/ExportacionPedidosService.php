<?php

namespace App\Services\Canales;

use App\Models\Business;
use App\Models\Canal;
use App\Models\PedidoWeb;
use App\Models\Product;
use App\Models\Rubro;
use Illuminate\Support\Carbon;

// Fase 30.6: exportar pedidos por período a CSV o Excel (cabecera y renglones), la planilla de Empretienda y el ZIP de fotos.
class ExportacionPedidosService
{
    public const CABECERA = ['fecha', 'hora', 'plataforma', 'numero_orden', 'id', 'comprador', 'cliente', 'email', 'telefono', 'cuit', 'condicion_iva', 'entrega', 'direccion', 'seguimiento', 'total', 'estado', 'facturado', 'comprobante', 'cae', 'vendedor', 'observaciones'];

    public function pedidos(string $desde, string $hasta, ?string $plataforma = null, ?string $estado = null)
    {
        // El día es el argentino: un pedido de las 22:00 es de ese día.
        return PedidoWeb::with('comprobante', 'contact')->whereBetween('created_at', [Carbon::parse($desde)->startOfDay(), Carbon::parse($hasta)->endOfDay()])
            ->whereNotIn('canal', ['pos', 'calle'])
            ->when($plataforma === 'otros', fn($q) => $q->whereNotIn('canal', ['tienda', 'portal', 'whatsapp', 'mercadolibre', 'woocommerce', 'tiendanube', 'shopify', 'pedidosya', 'rappi', 'menu_qr']))
            ->when($plataforma && $plataforma !== 'otros', fn($q) => $q->where('canal', $plataforma))
            ->when($estado, fn($q) => $q->where('estado', $estado))->orderBy('created_at')->get();
    }

    public function filas($pedidos, bool $renglones = false): array
    {
        $cab = []; $ren = [];
        foreach ($pedidos as $p) {
            $c = $p->comprobante;
            $cab[] = [$p->created_at->format('Y-m-d'), $p->created_at->format('H:i'), PedidoWeb::CANALES[$p->canal] ?? $p->canal, $p->external_id, $p->numeroFormateado(), $p->cliente['nombre'] ?? '', $p->contact?->name, $p->cliente['email'] ?? '', $p->cliente['telefono'] ?? '', $p->cliente['cuit'] ?? '', $p->cliente['condicion_iva'] ?? '', $p->entrega, $p->cliente['direccion'] ?? '', $p->tracking ?? ($p->envio_datos['tracking'] ?? ''), number_format((float) $p->total, 2, '.', ''), $p->estado, $c && $c->esFactura() && $c->estado === 'emitido' ? 'si' : 'no', $c ? $c->nombreTipo() . ' ' . $c->numeroFormateado() : '', $c?->cae, $c?->vendedor?->nombre, $p->notas];
            if ($renglones) foreach ($p->items as $it) $ren[] = [$p->numeroFormateado(), ! empty($it['product_id']) ? (Product::withoutGlobalScopes()->find($it['product_id'])?->sku ?? '') : '', $it['descripcion'], $it['cantidad'], number_format((float) $it['precio_unit'], 2, '.', ''), number_format((float) ($it['total'] ?? $it['cantidad'] * $it['precio_unit']), 2, '.', '')];
        }
        return ['cabecera' => $cab, 'renglones' => $ren];
    }

    public function csv(array $cabeceras, array $filas, bool $excelEs = false): string
    {
        $sep = $excelEs ? ';' : ',';
        $linea = fn($f) => implode($sep, array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $f));
        return "\xEF\xBB\xBF" . $linea($cabeceras) . "\n" . implode("\n", array_map($linea, $filas)) . "\n";
    }

    // Excel mínimo sin dependencias: una hoja con strings inline (lo lee Excel, LibreOffice y Google Sheets).
    public function xlsx(array $hojas): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx'); $z = new \ZipArchive(); $z->open($tmp, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys(array_values($hojas)))) . '</Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . implode('', array_map(fn($i, $n) => '<sheet name="' . htmlspecialchars(mb_substr($n, 0, 31)) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>', array_keys(array_values($hojas)), array_keys($hojas))) . '</sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', array_map(fn($i) => '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>', array_keys(array_values($hojas)))) . '</Relationships>');
        $i = 0;
        foreach ($hojas as $filas) {
            $i++; $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($filas as $r => $f) {
                $xml .= '<row r="' . ($r + 1) . '">';
                foreach (array_values($f) as $k => $v) {
                    $col = ''; $n = $k; do { $col = chr(65 + $n % 26) . $col; $n = intdiv($n, 26) - 1; } while ($n >= 0);
                    $xml .= is_numeric($v) && ! is_string($v) ? '<c r="' . $col . ($r + 1) . '"><v>' . $v . '</v></c>' : '<c r="' . $col . ($r + 1) . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
                }
                $xml .= '</row>';
            }
            $z->addFromString("xl/worksheets/sheet{$i}.xml", $xml . '</sheetData></worksheet>');
        }
        $z->close(); $bin = file_get_contents($tmp); @unlink($tmp); return $bin;
    }

    // Planilla de Empretienda: los encabezados exactos que pide su importación masiva. SKU = código, precio de vidriera (lista + IVA), categoría = rubro, stock menos colchón.
    public function empretienda(Business $b, ?int $rubroId, int $colchon, int $lista = 1): array
    {
        $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $prods = Product::where('active', true)->where('en_tienda', true)->whereIn('tipo', ['producto', 'elaborado'])->when($rubroId, fn($q) => $q->whereIn('rubro_id', Rubro::conDescendientes($rubroId)))->with('rubro')->orderBy('name')->get();
        $filas = [['Nombre', 'Stock', 'SKU', 'Precio', 'Precio oferta', 'Categorías', 'Peso', 'Alto', 'Ancho', 'Profundidad', 'Mostrar en tienda', 'Descripción']];
        $n = 0;
        foreach ($prods as $p) {
            $precio = $p->precioLista($lista); if ($precio <= 0 || trim((string) $p->name) === '') continue;
            $filas[] = [$p->descripcion_tienda ?: $p->name, max(0, (int) floor((float) $p->stock) - $colchon), $p->sku, round($precio * ($ri ? 1 + (float) $p->iva / 100 : 1), 2), '', $p->rubro?->nombreCompleto() ?? '', '', '', '', '', 'Sí', $p->description ?? '']; $n++;
        }
        return ['xlsx' => $this->xlsx(['Productos' => $filas]), 'cantidad' => $n, 'sin_precio' => $prods->count() - $n];
    }

    // ZIP con las fotos del catálogo, cada archivo con el nombre del producto, para subirlas a mano.
    public function zipFotos(Business $b, ?int $rubroId): array
    {
        $prods = Product::where('active', true)->where('en_tienda', true)->whereNotNull('imagen')->when($rubroId, fn($q) => $q->whereIn('rubro_id', Rubro::conDescendientes($rubroId)))->orderBy('name')->get();
        $tmp = tempnam(sys_get_temp_dir(), 'fotos'); $z = new \ZipArchive(); $z->open($tmp, \ZipArchive::OVERWRITE); $n = 0;
        foreach ($prods as $p) {
            try {
                $bin = str_starts_with($p->imagen, 'http') ? \Illuminate\Support\Facades\Http::timeout(8)->get($p->imagen)->body() : (\Illuminate\Support\Facades\Storage::disk('public')->exists(ltrim(str_replace('/storage/', '', $p->imagen), '/')) ? \Illuminate\Support\Facades\Storage::disk('public')->get(ltrim(str_replace('/storage/', '', $p->imagen), '/')) : null);
                if (! $bin) continue;
                $ext = pathinfo(parse_url($p->imagen, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
                $z->addFromString(preg_replace('/[^\w\- ]+/u', '', $p->descripcion_tienda ?: $p->name) . ".{$ext}", $bin); $n++;
            } catch (\Throwable) {}
        }
        $z->close(); $bin = file_get_contents($tmp); @unlink($tmp);
        return ['zip' => $bin, 'cantidad' => $n];
    }
}
