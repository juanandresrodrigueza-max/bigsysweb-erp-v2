<?php

namespace App\Services\Canales;

use App\Models\Business;
use App\Models\Canal;
use App\Models\PedidoWeb;
use App\Models\Product;
use Illuminate\Support\Facades\Http;

// Marketplaces y delivery: importa pedidos, empuja stock y precios, y normaliza lo que llega por webhook.
class CanalesService
{
    public function __construct(private TiendaService $tienda) {}

    // Trae pedidos nuevos del canal (según API) y los da de alta como PedidoWeb. Devuelve cuántos entraron.
    public function importarPedidos(Canal $c): array
    {
        $b = $c->business; $n = 0; $err = null;
        try {
            $pedidos = match ($c->tipo) {
                'woocommerce' => $this->wooPedidos($c),
                'shopify' => $this->shopifyPedidos($c),
                'mercadolibre' => $this->mlPedidos($c),
                default => [],
            };
            foreach ($pedidos as $p) { if ($this->alta($b, $c, $p)) $n++; }
        } catch (\Throwable $e) { $err = mb_substr($e->getMessage(), 0, 300); }
        $c->forceFill(['ultimo_sync_en' => now(), 'ultimo_error' => $err, 'pedidos_importados' => $c->pedidos_importados + $n])->save();
        return ['importados' => $n, 'error' => $err];
    }

    // Alta idempotente por external_id. $p ya normalizado: [external_id, cliente{}, items[{sku|product_id, descripcion, cantidad, precio_unit}], entrega, pago, envio, total]
    public function alta(Business $b, Canal $c, array $p): ?PedidoWeb
    {
        if (! empty($p['external_id']) && PedidoWeb::withoutGlobalScopes()->where('business_id', $b->id)->where('canal', $c->tipo)->where('external_id', $p['external_id'])->exists()) return null;
        foreach ($p['items'] as &$it) {
            if (empty($it['product_id']) && ! empty($it['sku'])) $it['product_id'] = Product::withoutGlobalScopes()->where('business_id', $b->id)->where(fn($q) => $q->where('sku', $it['sku'])->orWhere('barcode', $it['sku']))->value('id');
        }
        $pedido = $this->tienda->crearPedido($b, ['items' => $p['items'], 'cliente' => $p['cliente'] ?? [], 'entrega' => $p['entrega'] ?? 'envio', 'pago' => $p['pago'] ?? 'pagado_externo', 'envio' => $p['envio'] ?? 0, 'external_id' => $p['external_id'] ?? null, 'notas' => $p['notas'] ?? null, 'texto_original' => $p['texto_original'] ?? null], $c->tipo);
        if (isset($p['total']) && abs((float) $p['total'] - (float) $pedido->total) > 0.01) $pedido->forceFill(['descuento' => round((float) $pedido->subtotal + (float) $pedido->envio - (float) $p['total'], 2), 'total' => (float) $p['total']])->save();
        if (! empty($p['envio_datos'])) $pedido->forceFill(['envio_datos' => $p['envio_datos']])->save();
        return $pedido;
    }

    // Normaliza el JSON que manda cada plataforma (o un integrador) al webhook de entrada.
    public function normalizar(string $tipo, array $raw): array
    {
        return match ($tipo) {
            'woocommerce' => ['external_id' => (string) ($raw['id'] ?? ''), 'cliente' => ['nombre' => trim(($raw['billing']['first_name'] ?? '') . ' ' . ($raw['billing']['last_name'] ?? '')), 'email' => $raw['billing']['email'] ?? null, 'telefono' => $raw['billing']['phone'] ?? null, 'direccion' => trim(($raw['shipping']['address_1'] ?? $raw['billing']['address_1'] ?? '') . ' ' . ($raw['shipping']['city'] ?? $raw['billing']['city'] ?? ''))], 'items' => array_map(fn($l) => ['sku' => $l['sku'] ?? null, 'descripcion' => $l['name'] ?? 'Ítem', 'cantidad' => (float) ($l['quantity'] ?? 1), 'precio_unit' => (float) ($l['total'] ?? 0) / max(1, (float) ($l['quantity'] ?? 1))], $raw['line_items'] ?? []), 'entrega' => 'envio', 'envio' => (float) ($raw['shipping_total'] ?? 0), 'total' => (float) ($raw['total'] ?? 0), 'pago' => ($raw['status'] ?? '') === 'processing' || ($raw['date_paid'] ?? null) ? 'pagado_externo' : 'a_convenir', 'notas' => $raw['customer_note'] ?? null],
            'shopify' => ['external_id' => (string) ($raw['id'] ?? ''), 'cliente' => ['nombre' => trim(($raw['customer']['first_name'] ?? '') . ' ' . ($raw['customer']['last_name'] ?? '')) ?: ($raw['email'] ?? 'Cliente Shopify'), 'email' => $raw['email'] ?? null, 'telefono' => $raw['phone'] ?? ($raw['shipping_address']['phone'] ?? null), 'direccion' => trim(($raw['shipping_address']['address1'] ?? '') . ' ' . ($raw['shipping_address']['city'] ?? ''))], 'items' => array_map(fn($l) => ['sku' => $l['sku'] ?? null, 'descripcion' => $l['title'] ?? $l['name'] ?? 'Ítem', 'cantidad' => (float) ($l['quantity'] ?? 1), 'precio_unit' => (float) ($l['price'] ?? 0)], $raw['line_items'] ?? []), 'entrega' => 'envio', 'envio' => (float) ($raw['total_shipping_price_set']['shop_money']['amount'] ?? 0), 'total' => (float) ($raw['total_price'] ?? 0), 'pago' => ($raw['financial_status'] ?? '') === 'paid' ? 'pagado_externo' : 'a_convenir', 'notas' => $raw['note'] ?? null],
            'mercadolibre' => ['external_id' => (string) ($raw['id'] ?? ''), 'cliente' => ['nombre' => trim(($raw['buyer']['first_name'] ?? '') . ' ' . ($raw['buyer']['last_name'] ?? '')) ?: ($raw['buyer']['nickname'] ?? 'Comprador ML'), 'email' => $raw['buyer']['email'] ?? null, 'telefono' => isset($raw['buyer']['phone']) ? ($raw['buyer']['phone']['area_code'] ?? '') . ($raw['buyer']['phone']['number'] ?? '') : null, 'direccion' => $raw['shipping']['receiver_address']['address_line'] ?? null], 'items' => array_map(fn($l) => ['sku' => $l['item']['seller_sku'] ?? $l['item']['seller_custom_field'] ?? null, 'descripcion' => $l['item']['title'] ?? 'Ítem', 'cantidad' => (float) ($l['quantity'] ?? 1), 'precio_unit' => (float) ($l['unit_price'] ?? 0)], $raw['order_items'] ?? []), 'entrega' => 'envio', 'envio' => 0, 'total' => (float) ($raw['total_amount'] ?? 0), 'pago' => ($raw['status'] ?? '') === 'paid' ? 'pagado_externo' : 'a_convenir', 'envio_datos' => ! empty($raw['shipping']['id']) ? ['proveedor' => 'mercadoenvios', 'shipment_id' => (string) $raw['shipping']['id'], 'estado' => $raw['shipping']['status'] ?? null, 'logistica' => $raw['shipping']['logistic_type'] ?? null] : null],
            'pedidosya', 'rappi' => ['external_id' => (string) ($raw['id'] ?? $raw['order_id'] ?? $raw['code'] ?? ''), 'cliente' => ['nombre' => $raw['customer']['name'] ?? ($raw['user']['name'] ?? 'Cliente ' . ucfirst($tipo)), 'telefono' => $raw['customer']['phone'] ?? ($raw['user']['phone'] ?? null), 'direccion' => $raw['delivery']['address'] ?? ($raw['address'] ?? ($raw['delivery_information']['address'] ?? null)), 'notas' => $raw['notes'] ?? ($raw['comments'] ?? null)], 'items' => array_map(fn($l) => ['sku' => $l['sku'] ?? ($l['external_id'] ?? ($l['id'] ?? null)), 'descripcion' => $l['name'] ?? ($l['product_name'] ?? 'Ítem'), 'cantidad' => (float) ($l['quantity'] ?? ($l['units'] ?? 1)), 'precio_unit' => (float) ($l['unit_price'] ?? ($l['price'] ?? 0))], $raw['items'] ?? ($raw['products'] ?? ($raw['order_detail']['items'] ?? []))), 'entrega' => 'envio', 'envio' => 0, 'total' => (float) ($raw['total'] ?? ($raw['total_amount'] ?? ($raw['payment']['total'] ?? 0))), 'pago' => 'pagado_externo', 'texto_original' => json_encode($raw, JSON_UNESCAPED_UNICODE)],
            default => ['external_id' => (string) ($raw['external_id'] ?? $raw['id'] ?? ''), 'cliente' => $raw['cliente'] ?? [], 'items' => $raw['items'] ?? [], 'entrega' => $raw['entrega'] ?? 'envio', 'envio' => (float) ($raw['envio'] ?? 0), 'total' => $raw['total'] ?? null, 'pago' => $raw['pago'] ?? 'pagado_externo', 'notas' => $raw['notas'] ?? null],
        };
    }

    // Stock y precio hacia los canales que lo piden. Se llama al mover stock o cambiar precio; nunca corta la operación.
    public function empujarStock(Product $p): void
    {
        foreach (Canal::withoutGlobalScopes()->where('business_id', $p->business_id)->where('activo', true)->where('sync_stock', true)->get() as $c) {
            try {
                $cr = $c->credenciales ?? [];
                match ($c->tipo) {
                    'woocommerce' => $this->woo($c)->get('products', ['sku' => $p->sku])->json()[0]['id'] ?? null ? $this->woo($c)->put('products/' . ($this->woo($c)->get('products', ['sku' => $p->sku])->json()[0]['id']), ['stock_quantity' => (int) $p->stock, 'manage_stock' => true] + ($c->sync_precios ? ['regular_price' => (string) $p->precioLista(1)] : [])) : null,
                    'shopify' => $this->shopifyStock($c, $p),
                    'mercadolibre' => ! empty($cr['item_' . $p->sku]) ? Http::withToken($cr['access_token'] ?? '')->timeout(8)->put('https://api.mercadolibre.com/items/' . $cr['item_' . $p->sku], ['available_quantity' => (int) $p->stock] + ($c->sync_precios ? ['price' => $p->precioLista(1)] : [])) : null,
                    default => null,
                };
            } catch (\Throwable $e) { $c->forceFill(['ultimo_error' => mb_substr($e->getMessage(), 0, 300)])->save(); }
        }
    }

    private function woo(Canal $c) { $cr = $c->credenciales ?? []; return Http::withBasicAuth($cr['consumer_key'] ?? '', $cr['consumer_secret'] ?? '')->timeout(10)->baseUrl(rtrim($cr['url'] ?? '', '/') . '/wp-json/wc/v3/'); }
    private function wooPedidos(Canal $c): array
    {
        $r = $this->woo($c)->get('orders', ['status' => 'processing,on-hold', 'per_page' => 50, 'after' => ($c->ultimo_sync_en ?? now()->subDays(7))->toIso8601String()]);
        if (! $r->ok()) throw new \RuntimeException("WooCommerce HTTP {$r->status()}");
        return array_map(fn($o) => $this->normalizar('woocommerce', $o), $r->json() ?? []);
    }
    private function shopify(Canal $c) { $cr = $c->credenciales ?? []; return Http::withHeaders(['X-Shopify-Access-Token' => $cr['access_token'] ?? ''])->timeout(10)->baseUrl('https://' . ($cr['tienda'] ?? '') . '/admin/api/2024-01/'); }
    private function shopifyPedidos(Canal $c): array
    {
        $r = $this->shopify($c)->get('orders.json', ['status' => 'open', 'limit' => 50, 'created_at_min' => ($c->ultimo_sync_en ?? now()->subDays(7))->toIso8601String()]);
        if (! $r->ok()) throw new \RuntimeException("Shopify HTTP {$r->status()}");
        return array_map(fn($o) => $this->normalizar('shopify', $o), $r->json('orders') ?? []);
    }
    private function shopifyStock(Canal $c, Product $p): void
    {
        $cr = $c->credenciales ?? []; if (empty($cr['location_id']) || empty($cr['inventory_' . $p->sku])) return;
        $this->shopify($c)->post('inventory_levels/set.json', ['location_id' => $cr['location_id'], 'inventory_item_id' => $cr['inventory_' . $p->sku], 'available' => (int) $p->stock]);
    }
    // Mercado Envíos: etiqueta PDF del envío y estado/tracking. Usa las credenciales del canal Mercado Libre.
    public function mlCanal(Business $b): ?Canal { return Canal::withoutGlobalScopes()->where('business_id', $b->id)->where('tipo', 'mercadolibre')->where('activo', true)->first(); }

    public function mlEtiqueta(Business $b, string $shipmentId): string
    {
        $c = $this->mlCanal($b); abort_unless($c, 422, 'No hay un canal Mercado Libre activo con credenciales.');
        $r = Http::withToken($c->credenciales['access_token'] ?? '')->timeout(15)->get('https://api.mercadolibre.com/shipment_labels', ['shipment_ids' => $shipmentId, 'response_type' => 'pdf']);
        if (! $r->ok()) throw new \RuntimeException("Mercado Envíos HTTP {$r->status()}: " . mb_substr($r->body(), 0, 200));
        return $r->body();
    }

    public function mlEnvioEstado(Business $b, PedidoWeb $p): array
    {
        $c = $this->mlCanal($b); abort_unless($c, 422, 'No hay un canal Mercado Libre activo con credenciales.');
        $id = $p->envio_datos['shipment_id'] ?? null; abort_unless($id, 422, 'El pedido no tiene envío de Mercado Envíos.');
        $r = Http::withToken($c->credenciales['access_token'] ?? '')->timeout(10)->get("https://api.mercadolibre.com/shipments/{$id}");
        if (! $r->ok()) throw new \RuntimeException("Mercado Envíos HTTP {$r->status()}");
        $datos = array_replace($p->envio_datos ?? [], ['estado' => $r->json('status'), 'subestado' => $r->json('substatus'), 'tracking' => $r->json('tracking_number'), 'logistica' => $r->json('logistic_type') ?? ($p->envio_datos['logistica'] ?? null), 'consultado' => now()->format('d/m H:i')]);
        $p->forceFill(['envio_datos' => $datos])->save();
        // Estado del pedido según el envío: shipped → enviado, delivered → entregado.
        $mapa = ['shipped' => 'enviado', 'delivered' => 'entregado'];
        if (isset($mapa[$datos['estado']]) && ! in_array($p->estado, ['entregado', 'cancelado'], true) && $p->estado !== $mapa[$datos['estado']]) $this->tienda->cambiarEstado($p, $mapa[$datos['estado']]);
        return $datos;
    }

    private function mlPedidos(Canal $c): array
    {
        $cr = $c->credenciales ?? [];
        $r = Http::withToken($cr['access_token'] ?? '')->timeout(10)->get('https://api.mercadolibre.com/orders/search', ['seller' => $cr['user_id'] ?? '', 'order.status' => 'paid', 'sort' => 'date_desc', 'limit' => 50]);
        if (! $r->ok()) throw new \RuntimeException("MercadoLibre HTTP {$r->status()}");
        return array_map(fn($o) => $this->normalizar('mercadolibre', $o), $r->json('results') ?? []);
    }
}
