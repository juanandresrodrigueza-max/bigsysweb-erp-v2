<?php

namespace App\Services\Compras;

use App\Models\CompraBorrador;
use App\Models\Contact;
use App\Models\OrdenCompra;
use App\Models\Product;
use App\Models\ProveedorArticulo;
use App\Models\ProveedorRegla;

// Fase 31.1–31.4: después de la lectura con IA, entender la factura como la entiende el que la carga a mano: el QR de ARCA da la
// cabecera exacta; el proveedor se reconoce por CUIT; sus códigos y descripciones se recuerdan; los bultos se pasan a unidades;
// la bonificación en mercadería, los renglones de descuento y el pie se tratan como corresponde; y antes de mandar se revisa
// el costo contra el sistema, el margen que queda y que todo cierre.
class LecturaCompraService
{
    private const TIPOS_AFIP = [1 => 'FA', 6 => 'FB', 11 => 'FC', 19 => 'FE', 2 => 'NDA', 7 => 'NDB', 12 => 'NDC', 3 => 'NCA', 8 => 'NCB', 13 => 'NCC', 51 => 'FA', 201 => 'FA', 206 => 'FB', 211 => 'FC'];

    // ---- El QR de ARCA: CUIT, tipo, punto de venta, número, fecha, total y CAE, escritos por el proveedor y firmados por ARCA ----
    public function qrAfip(?string $texto): ?array
    {
        if (! $texto) return null;
        $p = null;
        if (preg_match('/[?&]p=([A-Za-z0-9+\/=_-]+)/', $texto, $m)) $p = $m[1]; elseif (preg_match('/^[A-Za-z0-9+\/=_-]{40,}$/', trim($texto))) $p = trim($texto);
        if (! $p) return null;
        $json = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        if (! is_array($json) || empty($json['cuit']) || ! isset($json['nroCmp'])) return null;
        $cuit = preg_replace('/\D/', '', (string) $json['cuit']);
        return [
            'cuit' => strlen($cuit) === 11 ? substr($cuit, 0, 2) . '-' . substr($cuit, 2, 8) . '-' . substr($cuit, 10) : $cuit,
            'tipo' => self::TIPOS_AFIP[(int) ($json['tipoCmp'] ?? 0)] ?? null, 'tipo_codigo' => (int) ($json['tipoCmp'] ?? 0),
            'numero' => sprintf('%04d-%08d', (int) ($json['ptoVta'] ?? 0), (int) $json['nroCmp']),
            'fecha' => ! empty($json['fecha']) ? substr((string) $json['fecha'], 0, 10) : null,
            'total' => isset($json['importe']) ? (float) $json['importe'] : null,
            'cae' => isset($json['codAut']) ? (string) $json['codAut'] : null, 'moneda' => $json['moneda'] ?? 'PES', 'cotizacion' => (float) ($json['ctz'] ?? 1),
        ];
    }

    // ---- Entender la lectura: lo que el proveedor escribe, pasado a lo nuestro ----
    public function entender(array $d, ?Contact $prov, ?array $qr = null): array
    {
        $avisos = []; $corregido = [];
        if ($qr) {
            foreach (['tipo', 'numero', 'fecha', 'cae', 'total'] as $k) if (! empty($qr[$k]) && (($d[$k] ?? null) != $qr[$k])) { if (! empty($d[$k])) $corregido[] = $k; $d[$k] = $qr[$k]; }
            $d['proveedor']['cuit'] = $qr['cuit'];
            $d['cabecera_qr'] = true;
            $avisos[] = 'Los datos de la factura salieron del código QR' . ($corregido ? ' (se corrigió: ' . implode(', ', $corregido) . ')' : '') . '.';
            if (! $prov) { $prov = Contact::suppliers()->get()->first(fn($c) => preg_replace('/\D/', '', (string) $c->cuit) === preg_replace('/\D/', '', $qr['cuit'])); }
        }
        $items = array_values(array_filter($d['items'] ?? [], fn($i) => trim((string) ($i['descripcion'] ?? '')) !== '' || ! empty($i['product_id'])));
        $cfg = $prov?->compras_config ?? [];
        $aprendidos = $prov ? ProveedorArticulo::where('contact_id', $prov->id)->get() : collect();
        $productos = Product::where('active', true)->get(['id', 'name', 'sku', 'barcode', 'cost', 'price', 'unidades_bulto', 'unit', 'iva'])->keyBy('id');

        // 1) Lo aprendido de este proveedor manda sobre lo que propuso la IA (código y descripción).
        foreach ($items as &$it) {
            $it['por'] = $it['por'] ?? (! empty($it['product_id']) ? 'ia' : null);
            $cod = trim((string) ($it['codigo'] ?? '')); $desc = ProveedorArticulo::normalizar((string) ($it['descripcion'] ?? ''));
            $ap = ($cod !== '' ? $aprendidos->first(fn($a) => $a->codigo && mb_strtolower($a->codigo) === mb_strtolower($cod)) : null) ?? ($desc !== '' ? $aprendidos->first(fn($a) => $a->descripcion === $desc) : null);
            if ($ap && isset($productos[$ap->product_id])) { $it['product_id'] = $ap->product_id; $it['por'] = 'aprendido'; if ($ap->unidades_bulto && empty($it['unidades_bulto'])) $it['unidades_bulto'] = $ap->unidades_bulto; }
            // Sin IA ni memoria: por código de barras o por código exacto.
            if (empty($it['product_id']) && $cod !== '') { $pr = $productos->first(fn($p) => ($p->barcode && $p->barcode === $cod) || ($p->sku && mb_strtolower($p->sku) === mb_strtolower($cod))); if ($pr) { $it['product_id'] = $pr->id; $it['por'] = 'codigo'; } }
        }
        unset($it);

        // 2) Renglones negativos: son descuentos. Se reparten entre los renglones que nombran, o entre todos.
        $descuentos = array_values(array_filter($items, fn($i) => (float) ($i['precio_unit'] ?? 0) < 0 || (float) ($i['cantidad'] ?? 0) < 0));
        if ($descuentos) {
            $items = array_values(array_filter($items, fn($i) => (float) ($i['precio_unit'] ?? 0) >= 0 && (float) ($i['cantidad'] ?? 0) >= 0));
            foreach ($descuentos as $dsc) {
                $monto = abs((float) $dsc['cantidad'] * (float) $dsc['precio_unit']);
                $tk = array_filter(explode(' ', ProveedorArticulo::normalizar((string) $dsc['descripcion'])), fn($t) => strlen($t) > 3 && ! in_array($t, ['descuento', 'bonificacion', 'bonif', 'promo', 'promocion'], true));
                $alcanza = $tk ? array_keys(array_filter($items, fn($i) => count(array_intersect($tk, explode(' ', ProveedorArticulo::normalizar((string) $i['descripcion'])))) > 0)) : [];
                if (! $alcanza) $alcanza = array_keys($items);
                $base = array_sum(array_map(fn($k) => (float) $items[$k]['cantidad'] * (float) $items[$k]['precio_unit'] * (1 - (float) ($items[$k]['descuento'] ?? 0) / 100), $alcanza));
                if ($base > 0) foreach ($alcanza as $k) { $pct = min(100, (float) ($items[$k]['descuento'] ?? 0) + $monto / $base * 100 * (1 - (float) ($items[$k]['descuento'] ?? 0) / 100)); $items[$k]['descuento'] = round($pct, 2); $items[$k]['por_descuento'] = trim((string) $dsc['descripcion']); }
                $avisos[] = 'El renglón «' . trim((string) $dsc['descripcion']) . '» (−$ ' . number_format($monto, 2, ',', '.') . ') es un descuento: se repartió entre ' . count($alcanza) . ' renglón/es.';
            }
        }

        // 3) Bonificación en mercadería: el mismo artículo en $0 va sin cargo, y no baja el costo del renglón pagado.
        foreach ($items as $k => &$it) {
            if ((float) ($it['precio_unit'] ?? 0) == 0.0 && (float) ($it['cantidad'] ?? 0) > 0) {
                $par = collect($items)->first(fn($o, $j) => $j !== $k && (float) ($o['precio_unit'] ?? 0) > 0 && ((! empty($o['product_id']) && ($o['product_id'] ?? null) === ($it['product_id'] ?? -1)) || ProveedorArticulo::normalizar((string) $o['descripcion']) === ProveedorArticulo::normalizar((string) $it['descripcion'])));
                if ($par) { $it['product_id'] = $it['product_id'] ?: $par['product_id']; $it['sin_cargo'] = true; $it['actualiza_costo'] = false; $avisos[] = 'Bonificación en mercadería: ' . $it['cantidad'] . ' × «' . trim((string) $it['descripcion']) . '» sin cargo (no baja el costo).'; }
            }
        }
        unset($it);

        // 4) El bulto: la ficha dice cuántas trae; o el nombre lo dice entre paréntesis; o el precio es como N veces el costo.
        foreach ($items as &$it) {
            $p = ! empty($it['product_id']) ? ($productos[$it['product_id']] ?? null) : null; if (! $p) continue;
            $n = (int) ($it['unidades_bulto'] ?? $p->unidades_bulto ?: 0);
            if (! $n && ! empty($cfg['factor_parentesis']) && preg_match('/\((\d{1,4})\)\s*$/', $p->name, $m)) $n = (int) $m[1];
            $precio = (float) ($it['precio_unit'] ?? 0); $costo = (float) $p->cost;
            $porBulto = preg_match('/\b(bulto|bultos|caja|cajas|pack|cj|bto)\b/i', (string) ($it['descripcion'] ?? '')) === 1 || (! empty($it['unidad']) && preg_match('/^(bu|cj|ca|pa)/i', (string) $it['unidad']));
            if (! $n && $costo > 0 && $precio > $costo * 2.5) { $cand = (int) round($precio / $costo); if ($cand >= 3 && abs($precio / $cand - $costo) / $costo <= 0.15) { $n = $cand; $it['bulto_deducido'] = true; } }
            if ($n > 1 && ($porBulto || ! empty($it['bulto_deducido']) || ($costo > 0 && $precio > $costo * 2.5))) {
                $it['cantidad'] = round((float) $it['cantidad'] * $n, 3); $it['precio_unit'] = round($precio / $n, 4); $it['bulto'] = $n;
                $avisos[] = 'Bulto de ' . $n . ': «' . trim((string) $it['descripcion']) . '» pasó a unidades (' . ($it['cantidad']) . ' × $ ' . number_format($it['precio_unit'], 2, ',', '.') . ')' . (! empty($it['bulto_deducido']) ? ' porque el precio era como ' . $n . ' veces el costo' : '') . '.';
            }
        }
        unset($it);

        // 5) El pie: bonificación general y conceptos raros. Lo conocido se aplica; lo desconocido se pregunta una vez por proveedor.
        $pct = (float) ($d['bonificacion_pct'] ?? 0);
        if ($pct > 0) { foreach ($items as &$it) { $it['descuento'] = round(min(100, (float) ($it['descuento'] ?? 0) + $pct * (1 - (float) ($it['descuento'] ?? 0) / 100)), 2); } unset($it); $avisos[] = "Bonificación del pie del {$pct}% aplicada a todos los renglones."; }
        $preguntar = []; $percepciones = []; $cuit = preg_replace('/\D/', '', (string) ($d['proveedor']['cuit'] ?? $prov?->cuit ?? ''));
        foreach ($d['percepciones'] ?? [] as $pe) {
            $tipo = $pe['tipo'] ?? 'otro'; $monto = (float) ($pe['monto'] ?? 0); if ($monto <= 0) continue;
            if (in_array($tipo, ['iva', 'ganancias'], true) || str_starts_with((string) $tipo, 'iibb')) { $percepciones[] = $pe; continue; }
            $concepto = ProveedorRegla::normalizar((string) ($pe['concepto'] ?? $pe['descripcion'] ?? 'otro'));
            $regla = $cuit ? ProveedorRegla::where('cuit', $cuit)->where('concepto', $concepto)->first() : null;
            $tr = $regla?->tratamiento ?? ($pe['tratamiento'] ?? null);
            switch ($tr) {
                case 'percepcion_iibb': $percepciones[] = ['tipo' => 'iibb', 'monto' => $monto]; break;
                case 'percepcion_iva': $percepciones[] = ['tipo' => 'iva', 'monto' => $monto]; break;
                case 'percepcion_ganancias': $percepciones[] = ['tipo' => 'ganancias', 'monto' => $monto]; break;
                case 'flete': $items[] = ['descripcion' => $pe['concepto'] ?? 'Flete', 'cantidad' => 1, 'precio_unit' => $monto, 'descuento' => 0, 'alicuota_iva' => 21, 'actualiza_costo' => false]; break;
                case 'descuento': $this->repartir($items, $monto); break;
                case 'ignorar': break;
                default: $preguntar[] = ['concepto' => $pe['concepto'] ?? $pe['descripcion'] ?? 'Concepto del pie', 'monto' => $monto, 'clave' => $concepto];
            }
            if ($regla) $avisos[] = '«' . ($pe['concepto'] ?? $concepto) . '» se trató como ' . (ProveedorRegla::TRATAMIENTOS[$regla->tratamiento] ?? $regla->tratamiento) . ' (ya lo dijiste para este proveedor).';
        }
        $d['items'] = array_values($items); $d['percepciones'] = $percepciones; $d['avisos'] = $avisos; $d['preguntar'] = $preguntar; $d['proveedor_id'] = $prov?->id;
        return $d;
    }

    private function repartir(array &$items, float $monto): void
    {
        $base = array_sum(array_map(fn($i) => (float) $i['cantidad'] * (float) $i['precio_unit'] * (1 - (float) ($i['descuento'] ?? 0) / 100), $items));
        if ($base <= 0) return;
        foreach ($items as &$it) { $it['descuento'] = round(min(100, (float) ($it['descuento'] ?? 0) + $monto / $base * 100 * (1 - (float) ($it['descuento'] ?? 0) / 100)), 2); }
    }

    // ---- Revisar antes de mandar: costo de la factura contra el del sistema, margen que queda, y que todo cierre ----
    public function revisar(array $items, ?float $totalFactura, array $percepciones = []): array
    {
        $ids = array_filter(array_column($items, 'product_id'));
        $productos = Product::whereIn('id', $ids)->get(['id', 'name', 'cost', 'price', 'iva', 'unidades_bulto'])->keyBy('id');
        $vistos = []; $filas = []; $alertas = [];
        foreach ($items as $k => $it) {
            $p = ! empty($it['product_id']) ? ($productos[$it['product_id']] ?? null) : null;
            $costoNuevo = (float) ($it['precio_unit'] ?? 0) * (1 - (float) ($it['descuento'] ?? 0) / 100);
            $costoActual = $p ? (float) $p->cost : null;
            $var = $costoActual > 0 && empty($it['sin_cargo']) ? round(($costoNuevo - $costoActual) / $costoActual * 100, 1) : null;
            $precio = $p ? (float) $p->price : null;
            $margen = $precio && $costoNuevo > 0 ? round(($precio - $costoNuevo) / $precio * 100, 1) : null;
            $f = ['k' => $k, 'product_id' => $p?->id, 'nombre' => $p?->name, 'costo_actual' => $costoActual, 'costo_nuevo' => round($costoNuevo, 4), 'variacion' => $var, 'precio_venta' => $precio, 'margen' => $margen, 'cambia_mucho' => $var !== null && abs($var) >= 15, 'bajo_costo' => $margen !== null && $margen < 0, 'repetido' => false, 'actualiza_costo' => ! empty($it['sin_cargo']) ? false : (bool) ($it['actualiza_costo'] ?? true)];
            if ($p) { if (isset($vistos[$p->id]) && empty($it['sin_cargo'])) { $f['repetido'] = true; $filas[$vistos[$p->id]]['repetido'] = true; } else $vistos[$p->id] = count($filas); }
            $filas[] = $f;
        }
        $neto = array_sum(array_map(fn($i) => (float) $i['cantidad'] * (float) $i['precio_unit'] * (1 - (float) ($i['descuento'] ?? 0) / 100), $items));
        $iva = array_sum(array_map(fn($i) => (float) $i['cantidad'] * (float) $i['precio_unit'] * (1 - (float) ($i['descuento'] ?? 0) / 100) * (float) ($i['alicuota_iva'] ?? 21) / 100, $items));
        $otros = array_sum(array_map(fn($pe) => (float) ($pe['monto'] ?? 0), $percepciones));
        $suma = round($neto + $iva + $otros, 2);
        if ($totalFactura && abs($suma - $totalFactura) > max(1, $totalFactura * 0.01)) $alertas[] = ['tipo' => 'no_cierra', 'texto' => 'Los renglones suman $ ' . number_format($suma, 2, ',', '.') . ' y la factura dice $ ' . number_format($totalFactura, 2, ',', '.') . ': falta un renglón, una percepción o hay una columna cruzada. Así no se manda.'];
        if (count(array_filter($filas, fn($f) => $f['repetido']))) $alertas[] = ['tipo' => 'repetidos', 'texto' => 'Hay artículos repetidos en la factura: fijate si son sabores de un mismo artículo (está bien) o una lectura doble.'];
        foreach ($filas as $f) if ($f['bajo_costo']) { $alertas[] = ['tipo' => 'bajo_costo', 'texto' => 'Con este costo, «' . $f['nombre'] . '» queda por encima del precio de venta: revisá el precio o el bulto.']; break; }
        foreach ($filas as $f) if ($f['cambia_mucho']) { $alertas[] = ['tipo' => 'cambia_mucho', 'texto' => 'Hay costos que cambian más del 15 %: en rojo en la lista. Destildá el que no quieras escribir.']; break; }
        return ['filas' => $filas, 'suma' => $suma, 'total_factura' => $totalFactura, 'cierra' => ! collect($alertas)->contains('tipo', 'no_cierra'), 'alertas' => $alertas];
    }

    // ---- Aprender: lo que el usuario confirmó queda para la próxima factura de ese proveedor ----
    public function aprender(Contact $prov, array $items, string $fecha): void
    {
        foreach ($items as $it) {
            if (empty($it['product_id']) || trim((string) ($it['descripcion'] ?? '')) === '') continue;
            $desc = ProveedorArticulo::normalizar((string) $it['descripcion']); $cod = trim((string) ($it['codigo'] ?? '')) ?: null;
            $pa = ProveedorArticulo::firstOrNew(['contact_id' => $prov->id, 'descripcion' => $desc], ['business_id' => $prov->business_id]);
            $pa->fill(['product_id' => $it['product_id'], 'codigo' => $cod ?? $pa->codigo, 'unidades_bulto' => $it['bulto'] ?? $pa->unidades_bulto, 'ultimo_costo' => (float) ($it['precio_unit'] ?? 0) * (1 - (float) ($it['descuento'] ?? 0) / 100), 'ultima_fecha' => $fecha])->save();
            if (! empty($it['bulto']) && ($p = Product::find($it['product_id'])) && ! $p->unidades_bulto) $p->forceFill(['unidades_bulto' => (int) $it['bulto']])->saveQuietly();
        }
    }

    public function reglaPie(?string $cuit, string $concepto, string $tratamiento): void
    {
        $cuit = preg_replace('/\D/', '', (string) $cuit); if (! $cuit) return;
        $r = ProveedorRegla::firstOrNew(['cuit' => $cuit, 'concepto' => ProveedorRegla::normalizar($concepto)]);
        $r->fill(['tratamiento' => $tratamiento, 'votos' => ($r->exists ? $r->votos : 0) + 1])->save();
    }

    // ---- Comparar contra la orden de compra, con tolerancia ----
    public function compararOc(array $items, OrdenCompra $oc, float $toleranciaPct = 2.0): array
    {
        $dif = []; $vistos = [];
        foreach ($oc->items as $oi) {
            $fact = collect($items)->filter(fn($i) => ($i['product_id'] ?? null) === $oi->product_id);
            $cant = (float) $fact->sum('cantidad'); $precio = $fact->count() ? (float) $fact->avg('precio_unit') : null; $vistos[] = $oi->product_id;
            $pend = (float) $oi->pendiente();
            if ($cant <= 0) { $dif[] = ['product_id' => $oi->product_id, 'descripcion' => $oi->descripcion, 'tipo' => 'no_vino', 'pedido' => $pend, 'facturado' => 0, 'texto' => 'Pedido y no facturado']; continue; }
            if (abs($cant - $pend) > 0.001) $dif[] = ['product_id' => $oi->product_id, 'descripcion' => $oi->descripcion, 'tipo' => $cant > $pend ? 'de_mas' : 'de_menos', 'pedido' => $pend, 'facturado' => $cant, 'texto' => ($cant > $pend ? 'Facturaron más' : 'Facturaron menos') . " ({$cant} contra {$pend})"];
            if ($precio !== null && (float) $oi->precio_unit > 0 && abs($precio - (float) $oi->precio_unit) / (float) $oi->precio_unit * 100 > $toleranciaPct) $dif[] = ['product_id' => $oi->product_id, 'descripcion' => $oi->descripcion, 'tipo' => 'precio', 'pedido' => (float) $oi->precio_unit, 'facturado' => $precio, 'texto' => 'Precio distinto al pactado: $ ' . number_format($precio, 2, ',', '.') . ' contra $ ' . number_format((float) $oi->precio_unit, 2, ',', '.')];
        }
        foreach ($items as $i) if (! empty($i['product_id']) && ! in_array($i['product_id'], $vistos, true)) $dif[] = ['product_id' => $i['product_id'], 'descripcion' => $i['descripcion'] ?? '', 'tipo' => 'no_pedido', 'pedido' => 0, 'facturado' => (float) $i['cantidad'], 'texto' => 'Facturado y no pedido'];
        $reclamo = $dif ? "Reclamo sobre la factura contra la orden {$oc->numeroFormateado()}:\n" . implode("\n", array_map(fn($x) => "- {$x['descripcion']}: {$x['texto']}", $dif)) : null;
        return ['orden' => $oc->numeroFormateado(), 'diferencias' => $dif, 'reclamo' => $reclamo, 'tolerancia' => $toleranciaPct];
    }

    // ---- Facturas a medio cargar ----
    public function guardarBorrador(int $userId, int $businessId, array $datos, ?int $id = null): CompraBorrador
    {
        $b = $id ? CompraBorrador::findOrFail($id) : new CompraBorrador(['business_id' => $businessId, 'user_id' => $userId]);
        $items = $datos['items'] ?? []; $total = array_sum(array_map(fn($i) => (float) ($i['cantidad'] ?? 0) * (float) ($i['precio_unit'] ?? 0) * (1 - (float) ($i['descuento'] ?? 0) / 100) * (1 + (float) ($i['alicuota_iva'] ?? 21) / 100), $items));
        $b->fill(['contact_id' => $datos['contact_id'] ?? null, 'proveedor' => $datos['proveedor_nombre'] ?? Contact::find($datos['contact_id'] ?? 0)?->name, 'numero' => $datos['numero_proveedor'] ?? null, 'total' => round($total, 2), 'datos' => $datos, 'adjuntos' => $datos['adjuntos'] ?? $b->adjuntos])->save();
        return $b;
    }
}
