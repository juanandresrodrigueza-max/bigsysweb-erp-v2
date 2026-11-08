<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Product;
use App\Services\Ventas\CondicionesClienteService;
use App\Support\Sql;
use Illuminate\Http\Request;

// Fase 70 · Stock y precio en vivo para presupuestar desde el CRM: lo que hay hoy (total y por depósito) y el precio que le toca a ese
// cliente (su lista, sus pactos, el descuento del rubro o general, y el descuento por cantidad), igual que lo calcula el ERP al vender.
class CrmArticulosController extends Controller
{
    public function buscar(Request $request, CondicionesClienteService $cond)
    {
        $d = $request->validate(['q' => 'nullable|string|max:120', 'ids' => 'nullable|string|max:2000', 'contact_id' => 'nullable|integer', 'cantidad' => 'nullable|numeric|min:0', 'limit' => 'nullable|integer|min:1|max:100', 'solo_con_stock' => 'nullable|boolean']);
        $ids = array_filter(array_map('intval', explode(',', (string) ($d['ids'] ?? '')))); $q = trim((string) ($d['q'] ?? ''));
        abort_if($q === '' && ! $ids, 422, 'Pasá q (texto a buscar) o ids.');
        $like = Sql::like(); $t = "%{$q}%";
        $prods = Product::where('active', true)->whereIn('tipo', ['producto', 'elaborado', 'servicio'])->with('rubro:id,nombre', 'stocks.deposito:id,nombre')
            ->when($ids, fn($b) => $b->whereIn('id', $ids))->when($q !== '', fn($b) => $b->where(fn($w) => $w->where('name', $like, $t)->orWhere('sku', $like, $t)->orWhere('barcode', $like, $t)))
            ->when($request->boolean('solo_con_stock'), fn($b) => $b->where(fn($w) => $w->where('controla_stock', false)->orWhere('stock', '>', 0)))
            ->orderBy('name')->limit((int) ($d['limit'] ?? 30))->get();
        $cli = ! empty($d['contact_id']) ? Contact::find($d['contact_id']) : null; $c = $cli ? $cond->paraCliente($cli) : null;
        return response()->json(['data' => $prods->map(fn($p) => $this->fila($p, $cli, $c, $cond, (float) ($d['cantidad'] ?? 0)))->values(), 'cliente' => $cli ? ['id' => $cli->id, 'nombre' => $cli->name, 'lista' => $c['lista'], 'descuento' => $c['descuento']] : null, 'actualizado' => now()->toIso8601String()]);
    }

    public function uno(Request $request, Product $product, CondicionesClienteService $cond)
    {
        $d = $request->validate(['contact_id' => 'nullable|integer', 'cantidad' => 'nullable|numeric|min:0']);
        $cli = ! empty($d['contact_id']) ? Contact::find($d['contact_id']) : null;
        return response()->json(['data' => $this->fila($product->load('rubro:id,nombre', 'stocks.deposito:id,nombre'), $cli, $cli ? $cond->paraCliente($cli) : null, $cond, (float) ($d['cantidad'] ?? 0)), 'actualizado' => now()->toIso8601String()]);
    }

    private function fila(Product $p, ?Contact $cli, ?array $c, CondicionesClienteService $cond, float $cantidad): array
    {
        $stock = (float) $p->stock; $hay = ! $p->controla_stock || $stock > 0;
        $precio = $cli ? $cond->para($cli, $p, $c) : ['precio' => (float) $p->price, 'descuento' => 0.0, 'origen' => 'lista'];
        $descCant = $cantidad > 0 && (float) $precio['descuento'] == 0.0 ? $p->descuentoPorCantidad($cantidad) : 0.0;
        $desc = $descCant > 0 ? $descCant : (float) $precio['descuento'];
        $final = round((float) $precio['precio'] * (1 - $desc / 100), 2);
        return [
            'id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'codigo_barras' => $p->barcode, 'unidad' => $p->unit, 'iva' => (float) $p->iva, 'rubro' => $p->rubro?->nombre, 'marca' => $p->marca, 'imagen' => $p->imagen, 'en_tienda' => (bool) $p->en_tienda,
            'stock' => ['controla' => (bool) $p->controla_stock, 'total' => $stock, 'hay' => $hay, 'poco' => $p->controla_stock && $hay && $stock <= (float) $p->stock_min, 'minimo' => (float) $p->stock_min,
                'por_deposito' => $p->stocks->map(fn($s) => ['deposito' => $s->deposito?->nombre, 'cantidad' => (float) $s->cantidad])->values()->all(), 'alcanza' => ! $p->controla_stock || $cantidad <= 0 || $stock >= $cantidad],
            'precio' => ['lista_1' => (float) $p->price, 'lista' => $c['lista'] ?? 1, 'precio_lista' => (float) $precio['precio'], 'descuento' => $desc, 'origen' => $descCant > 0 ? 'cantidad' : $precio['origen'], 'final' => $final, 'final_con_iva' => round($final * (1 + (float) $p->iva / 100), 2), 'moneda' => $p->moneda ?? 'ARS'],
            'descuento_por_cantidad' => (float) $p->desc_cant_pct > 0 ? ['desde' => (float) $p->desc_cant_min, 'pct' => (float) $p->desc_cant_pct] : null,
        ];
    }
}
