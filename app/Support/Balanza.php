<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Product;

// Etiquetas de balanza (EAN-13 de peso o importe variable): prefijo + código de artículo (PLU) + peso o importe + verificador.
// El prefijo, el modo (peso / importe) y los decimales se configuran en Configuración → Empresa → Punto de venta.
class Balanza
{
    public static function config(?Business $b): array
    {
        return array_replace(['balanza_prefijo' => '2', 'balanza_modo' => 'peso', 'balanza_decimales' => 3, 'balanza_digitos_plu' => 5], (array) ($b?->pos ?? []));
    }

    // ['product' => Product, 'cantidad' => kg o unidades, 'importe' => ?float] o null si no es una etiqueta de balanza.
    public static function leer(string $codigo, ?Business $b, int $lista = 1): ?array
    {
        $cfg = self::config($b); $pre = (string) ($cfg['balanza_prefijo'] ?: '2'); $t = trim($codigo);
        if (! preg_match('/^\d{13}$/', $t) || ! str_starts_with($t, $pre)) return null;
        $dig = max(4, min(6, (int) $cfg['balanza_digitos_plu']));
        $plu = substr($t, strlen($pre), $dig);
        $valor = (int) substr($t, strlen($pre) + $dig, 5); // 5 dígitos de peso o importe; el resto es relleno y verificador
        $p = self::producto($plu);
        if (! $p) return null;
        if ($cfg['balanza_modo'] === 'importe') {
            // Importe en pesos: con 0 decimales llega hasta $ 99.999.
            $importe = $valor / (10 ** (int) $cfg['balanza_decimales']); $precio = $p->precioLista($lista) * (1 + (float) $p->iva / 100);
            return ['product' => $p, 'cantidad' => $precio > 0 ? round($importe / $precio, 3) : 1, 'importe' => $importe];
        }
        return ['product' => $p, 'cantidad' => round($valor / (10 ** (int) $cfg['balanza_decimales']), 3), 'importe' => null];
    }

    // El PLU del artículo manda; si no tiene, se busca por SKU o código de barras numérico.
    public static function producto(string $plu): ?Product
    {
        $n = (int) $plu;
        return Product::where('active', true)->whereNotNull('plu')->get(['id', 'plu'])->first(fn($p) => (int) $p->plu === $n)?->fresh()
            ?? Product::where('active', true)->where(fn($q) => $q->where('sku', $plu)->orWhere('sku', (string) $n)->orWhere('barcode', $plu))->first();
    }

    // Archivo para cargar la balanza: PLU, nombre, precio por kg (o por unidad) con IVA, tipo y días de vencimiento.
    public static function exportar(Business $b, int $lista = 1): string
    {
        $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $out = "PLU;Nombre;Precio;Tipo;Vence_dias;Codigo\n";
        foreach (Product::where('active', true)->where('pesable', true)->whereNotNull('plu')->orderByRaw('CAST(plu AS INTEGER)')->get() as $p) {
            $precio = $p->precioLista($lista) * ($ri ? 1 + (float) $p->iva / 100 : 1);
            $out .= implode(';', [$p->plu, mb_substr(str_replace(';', ',', $p->name), 0, 26), number_format($precio, 2, '.', ''), $p->unit === 'kg' ? 'P' : 'U', (int) ($p->dias_vencimiento ?? 0), $p->sku]) . "\n";
        }
        return $out;
    }
}
