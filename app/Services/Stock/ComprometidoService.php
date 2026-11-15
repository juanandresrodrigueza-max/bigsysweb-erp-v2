<?php

namespace App\Services\Stock;

use App\Models\AcopioItem;
use App\Models\Business;
use App\Models\ComandaItem;
use App\Models\PedidoWeb;
use App\Models\Product;

// Fase 97: stock comprometido y disponible real. Lo que ya está vendido o prometido pero todavía no salió del depósito:
// pedidos web/WhatsApp sin entregar, acopios pagados y no retirados, comandas abiertas del salón. Disponible = stock − comprometido.
class ComprometidoService
{
    // [product_id => ['pedidos' => n, 'acopios' => n, 'comandas' => n, 'total' => n]]
    public function porProducto(Business $b, ?array $ids = null): array
    {
        $r = [];
        $suma = function (int $pid, string $k, float $n) use (&$r) { if ($n <= 0) return; $r[$pid] ??= ['pedidos' => 0.0, 'acopios' => 0.0, 'comandas' => 0.0, 'total' => 0.0]; $r[$pid][$k] += $n; $r[$pid]['total'] += $n; };
        foreach (PedidoWeb::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('estado', ['nuevo', 'confirmado', 'preparando'])->whereNull('comprobante_id')->get(['items']) as $p) {
            foreach ((array) $p->items as $it) { $pid = (int) ($it['product_id'] ?? 0); if ($pid && ($ids === null || in_array($pid, $ids, true))) $suma($pid, 'pedidos', (float) ($it['cantidad'] ?? 0)); }
        }
        $acopios = \App\Models\Acopio::withoutGlobalScopes()->where('business_id', $b->id)->where('estado', '!=', 'cerrado')->pluck('id');
        $ac = $acopios->isEmpty() ? collect() : AcopioItem::whereIn('acopio_id', $acopios)->whereNotNull('product_id')->when($ids !== null, fn($q) => $q->whereIn('product_id', $ids))->get(['product_id', 'cantidad_facturada', 'cantidad_retirada']);
        foreach ($ac as $it) $suma((int) $it->product_id, 'acopios', (float) $it->cantidad_facturada - (float) $it->cantidad_retirada);
        $comandas = \App\Models\Comanda::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('estado', ['abierta', 'cuenta'])->pluck('id');
        $co = $comandas->isEmpty() ? collect() : ComandaItem::whereIn('comanda_id', $comandas)->whereNotNull('product_id')->where('estado', '!=', 'anulado')->when($ids !== null, fn($q) => $q->whereIn('product_id', $ids))->get(['product_id', 'cantidad']);
        foreach ($co as $it) $suma((int) $it->product_id, 'comandas', (float) $it->cantidad);
        return $r;
    }

    public function de(Product $p): array
    {
        $c = $this->porProducto($p->business ?? \App\Models\Business::find($p->business_id), [$p->id])[$p->id] ?? ['pedidos' => 0.0, 'acopios' => 0.0, 'comandas' => 0.0, 'total' => 0.0];
        return $c + ['stock' => (float) $p->stock, 'disponible' => round((float) $p->stock - $c['total'], 3)];
    }

    // Los artículos con más comprometido que stock: lo que se prometió y no se puede entregar.
    public function faltantes(Business $b, int $limite = 50): array
    {
        $c = $this->porProducto($b); if (! $c) return [];
        $prods = Product::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('id', array_keys($c))->where('controla_stock', true)->get(['id', 'name', 'sku', 'unit', 'stock'])->keyBy('id');
        $out = [];
        foreach ($c as $pid => $x) { $p = $prods[$pid] ?? null; if (! $p) continue; $disp = round((float) $p->stock - $x['total'], 3); if ($disp < 0) $out[] = ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit, 'stock' => (float) $p->stock, 'comprometido' => round($x['total'], 3), 'disponible' => $disp, 'detalle' => $x]; }
        usort($out, fn($a, $b2) => $a['disponible'] <=> $b2['disponible']);
        return array_slice($out, 0, $limite);
    }
}
