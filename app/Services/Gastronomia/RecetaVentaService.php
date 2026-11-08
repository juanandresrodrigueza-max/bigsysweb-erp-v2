<?php

namespace App\Services\Gastronomia;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Deposito;
use App\Models\Product;
use App\Models\Recipe;
use App\Services\Stock\StockService;

// Fase 53 · Big Restó: la venta de un plato descuenta los insumos de su receta (café, leche, vaso) y el costo del plato sale de la receta.
// Cuando una compra sube el costo de un insumo, se recalculan los platos que lo llevan y se avisa el margen que les queda.
class RecetaVentaService
{
    public function __construct(private StockService $stock) {}

    public static function activo(Business $b): bool
    {
        return (bool) ($b->gastronomia_config['receta_al_vender'] ?? in_array('gastronomia', $b->verticalesPermitidos(), true));
    }

    public static function receta(Product $p): ?Recipe
    {
        return Recipe::withoutGlobalScopes()->where('business_id', $p->business_id)->where('product_id', $p->id)->where('is_active', true)->with('items.product')->first();
    }

    // Al vender $cantidad del plato (sentido -1) o devolverlo (+1): mueve cada insumo en proporción al rendimiento de la receta.
    public function mover(Comprobante $c, Product $p, float $cantidad, int $sentido, ?Deposito $dep): void
    {
        if (! self::activo($c->business) || ! ($r = self::receta($p))) return;
        $factor = (float) $r->yield_quantity > 0 ? $cantidad / (float) $r->yield_quantity : $cantidad;
        foreach ($r->items as $it) {
            if (! $it->product) continue;
            $this->stock->mover($it->product, $sentido * round((float) $it->quantity * $factor, 4), $dep, $sentido > 0 ? 'in' : 'out', ($sentido < 0 ? 'Receta ' : 'Devolución receta ') . "{$p->name} · {$c->nombreTipo()} {$c->numeroFormateado()}", $c, null, $c->business_location_id);
        }
    }

    // Costo del plato = costo de la receta por unidad (si tiene); si no, el costo cargado.
    public static function costo(Product $p): float
    {
        $r = self::receta($p);
        if (! $r) return (float) $p->cost;
        $total = $r->items->sum(fn($i) => (float) $i->quantity * (float) ($i->product?->cost ?? 0));
        return (float) $r->yield_quantity > 0 ? round($total / (float) $r->yield_quantity, 2) : round($total, 2);
    }

    // Margen sobre el precio neto: (precio − costo) / precio.
    public static function margen(Product $p, ?float $costo = null): ?float
    {
        $costo ??= self::costo($p); $precio = (float) $p->price;
        return $precio > 0 ? round(($precio - $costo) / $precio * 100, 1) : null;
    }

    // Precio neto que deja el margen pedido con este costo.
    public static function precioPara(float $costo, float $margenPct): float
    {
        return $margenPct >= 100 ? $costo : round($costo / (1 - $margenPct / 100), 2);
    }

    // Después de una compra que cambió el costo de un insumo: recalcula las recetas que lo llevan y avisa si el margen del plato cayó.
    public function insumoCambio(Product $insumo, float $costoAnterior, float $costoNuevo): array
    {
        if ($costoAnterior <= 0 || abs($costoNuevo - $costoAnterior) / $costoAnterior < 0.03) return [];
        $avisos = [];
        $recetas = Recipe::withoutGlobalScopes()->where('business_id', $insumo->business_id)->where('is_active', true)->whereHas('items', fn($q) => $q->where('product_id', $insumo->id))->with('product', 'items.product')->get();
        foreach ($recetas as $r) {
            $plato = $r->product; if (! $plato) continue;
            $r->update(['costo_calculado' => round($r->items->sum(fn($i) => (float) $i->quantity * (float) ($i->product?->cost ?? 0)), 2)]);
            $costo = self::costo($plato); $margen = self::margen($plato, $costo);
            $objetivo = $plato->margen_objetivo !== null ? (float) $plato->margen_objetivo : null;
            $pct = round(($costoNuevo - $costoAnterior) / $costoAnterior * 100);
            if ($margen === null) continue;
            $baja = $objetivo !== null ? $margen < $objetivo - 0.5 : $margen < 30;
            if (! $baja && $costoNuevo <= $costoAnterior) continue;
            $sugerido = self::precioPara($costo, $objetivo ?? max(30, $margen));
            $txt = "{$insumo->name} " . ($pct >= 0 ? "subió {$pct} %" : 'bajó ' . abs($pct) . ' %') . ": {$plato->name} queda con {$margen} % de margen" . ($objetivo !== null ? " (objetivo {$objetivo} %)" : '') . '. Costo $ ' . number_format($costo, 2, ',', '.') . ', precio neto $ ' . number_format((float) $plato->price, 2, ',', '.') . ($baja ? ' · precio sugerido $ ' . number_format($sugerido, 2, ',', '.') : '') . '.';
            if ($baja) Alerta::emitir(['business_id' => $plato->business_id, 'modulo' => 'gastronomia', 'tipo' => 'margen_plato', 'modelo' => 'Product', 'modelo_id' => $plato->id, 'severidad' => $margen < 15 ? 'critica' : 'aviso', 'titulo' => "Revisar el precio de {$plato->name}", 'detalle' => $txt, 'url' => '/gastronomia/carta?q=' . urlencode($plato->name), 'resuelta_en' => null]);
            $avisos[] = ['plato' => $plato->name, 'margen' => $margen, 'sugerido' => $sugerido, 'baja' => $baja];
        }
        return $avisos;
    }
}
