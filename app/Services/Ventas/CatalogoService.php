<?php

namespace App\Services\Ventas;

use App\Models\Catalogo;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Rubro;

// Arma el contenido de un catálogo: artículos agrupados por rubro con el precio que corresponde.
class CatalogoService
{
    public function __construct(private CondicionesClienteService $cond) {}

    public function datos(Catalogo $cat, ?Contact $cliente = null): array
    {
        $b = $cat->business;
        $ri = $b->condicion_iva === 'Responsable Inscripto';
        $q = Product::withoutGlobalScopes()->where('business_id', $cat->business_id)->where('active', true)->whereNotIn('tipo', ['insumo'])->with('rubro.parent');
        if ($cat->rubros) {
            $ids = collect($cat->rubros)->flatMap(fn($id) => Rubro::withoutGlobalScopes()->where('business_id', $cat->business_id)->whereKey($id)->exists() ? self::descendientes((int) $id, $cat->business_id) : [])->unique()->all();
            $q->whereIn('rubro_id', $ids ?: [0]);
        }
        if ($cat->solo_con_stock) $q->where(fn($w) => $w->where('controla_stock', false)->orWhere('stock', '>', 0));
        $cond = $cliente ? $this->cond->paraCliente($cliente) : null;
        if ($cond && $cond['lista'] < 1) $cond['lista'] = $cat->lista;

        $items = $q->orderBy('name')->get()->map(function (Product $p) use ($cat, $cliente, $cond, $ri) {
            if ($cliente) {
                $k = $this->cond->para($cliente, $p, $cond);
                $neto = round($k['precio'] * (1 - $k['descuento'] / 100), 2); $origen = $k['origen']; $desc = $k['descuento'];
            } else {
                $neto = $p->precioLista($cat->lista); $origen = 'lista'; $desc = 0;
            }
            if ($neto <= 0) return null;
            $precio = $cat->iva_incluido && $ri ? round($neto * (1 + (float) $p->iva / 100), 2) : $neto;
            return [
                'id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'descripcion' => $p->descripcion_tienda ?: $p->description,
                'unidad' => $p->unit, 'precio' => $precio, 'descuento' => $desc, 'especial' => in_array($origen, ['pactado', 'ultimo', 'rubro'], true) || $desc > 0,
                'imagen' => $cat->mostrar_fotos ? $p->imagen : null, 'stock' => $cat->mostrar_stock ? (float) $p->stock : null, 'sin_stock' => $p->controla_stock && (float) $p->stock <= 0,
                'rubro' => $p->rubro?->nombreCompleto() ?? 'Otros', 'rubro_orden' => $p->rubro?->orden ?? 999,
                'desc_cant' => (float) $p->desc_cant_min > 0 ? ['min' => (float) $p->desc_cant_min, 'pct' => (float) $p->desc_cant_pct] : null,
            ];
        })->filter()->values();

        return [
            'catalogo' => $cat, 'empresa' => $b, 'marca' => $b->marcaImpresion(), 'cliente' => $cliente,
            'rubros' => $items->groupBy('rubro')->sortBy(fn($g, $k) => [$g->first()['rubro_orden'], $k])->map(fn($g, $k) => ['nombre' => $k, 'items' => $g->values()])->values(),
            'total' => $items->count(), 'especiales' => $items->where('especial', true)->count(),
            'aclaracion' => $cat->iva_incluido && $ri ? 'Precios finales con IVA incluido.' : ($ri ? 'Precios netos, más IVA.' : 'Precios finales.'),
        ];
    }

    private static function descendientes(int $id, int $businessId): array
    {
        $ids = [$id]; $frente = [$id];
        while ($frente) { $frente = Rubro::withoutGlobalScopes()->where('business_id', $businessId)->whereIn('parent_id', $frente)->pluck('id')->all(); $ids = array_merge($ids, $frente); }
        return $ids;
    }
}
