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
    public function __construct(private TiendaService $tienda, private ConexionesService $cx) {}

    // Trae pedidos nuevos del canal (según API) y los da de alta como PedidoWeb. Devuelve cuántos entraron.
    public function importarPedidos(Canal $c): array
    {
        $b = $c->business; $n = 0; $err = null;
        try {
            $pedidos = match ($c->tipo) {
                'woocommerce' => $this->wooPedidos($c),
                'shopify' => $this->shopifyPedidos($c),
                'mercadolibre' => $this->mlPedidos($c),
                'tiendanube' => $this->tnPedidos($c),
                default => [],
            };
            foreach ($pedidos as $p) {
                if (! empty($p['cancelado'])) { $this->cancelado($c, $p['external_id']); continue; }
                if (! empty($p['sin_pagar'])) continue;
                // Lo anterior a la fecha de corte se ve pero no entra: ya lo resolviste a mano.
                if (($fc = $c->cfg('fecha_corte')) && ! empty($p['fecha']) && \Carbon\Carbon::parse($p['fecha'])->lt(\Carbon\Carbon::parse($fc))) continue;
                if ($p['items'] && $this->alta($b, $c, $p)) $n++;
            }
        } catch (\Throwable $e) { $err = mb_substr($e->getMessage(), 0, 300); }
        $c->forceFill(['ultimo_sync_en' => now(), 'ultima_importacion_en' => now(), 'ultimo_error' => $err, 'pedidos_importados' => $c->pedidos_importados + $n])->save();
        return ['importados' => $n, 'error' => $err];
    }

    // Alta idempotente por external_id. $p ya normalizado: [external_id, cliente{}, items[{sku|product_id, descripcion, cantidad, precio_unit}], entrega, pago, envio, total]
    public function alta(Business $b, Canal $c, array $p): ?PedidoWeb
    {
        if (! empty($p['external_id']) && PedidoWeb::withoutGlobalScopes()->where('business_id', $b->id)->where('canal', $c->tipo)->where('external_id', $p['external_id'])->exists()) return null;
        foreach ($p['items'] as &$it) {
            // Primero la publicación atada (Fase 30.3): entra con el artículo puesto aunque el SKU de la tienda no sea el nuestro.
            if (empty($it['product_id']) && ! empty($it['external_item'])) $it['product_id'] = \App\Models\Publicacion::withoutGlobalScopes()->where('canal_id', $c->id)->where('external_id', (string) $it['external_item'])->when(! empty($it['variante']), fn($q) => $q->where('variante_id', (string) $it['variante']))->whereNotNull('product_id')->value('product_id');
            if (empty($it['product_id']) && ! empty($it['sku'])) $it['product_id'] = Product::withoutGlobalScopes()->where('business_id', $b->id)->where(fn($q) => $q->where('sku', $it['sku'])->orWhere('barcode', $it['sku']))->value('id');
        }
        $pedido = $this->tienda->crearPedido($b, ['items' => $p['items'], 'cliente' => $p['cliente'] ?? [], 'entrega' => $p['entrega'] ?? 'envio', 'pago' => $p['pago'] ?? 'pagado_externo', 'envio' => $p['envio'] ?? 0, 'external_id' => $p['external_id'] ?? null, 'notas' => $p['notas'] ?? null, 'texto_original' => $p['texto_original'] ?? null], $c->tipo);
        if (isset($p['total']) && abs((float) $p['total'] - (float) $pedido->total) > 0.01) $pedido->forceFill(['descuento' => round((float) $pedido->subtotal + (float) $pedido->envio - (float) $p['total'], 2), 'total' => (float) $p['total']])->save();
        if (! empty($p['envio_datos'])) $pedido->forceFill(['envio_datos' => $p['envio_datos']])->save();
        if (! empty($p['pagos']) || ! empty($p['pack_id']) || ! empty($p['comprador_id'])) $pedido->forceFill(['cargos' => array_filter(['pack_id' => $p['pack_id'] ?? null, 'comprador_id' => $p['comprador_id'] ?? null, 'pagos' => $p['pagos'] ?? null])])->save();
        // Fase 30.4: lo que hay que mirar, y si la tienda está en modo automático, entra sola cuando tiene todo en orden.
        if (in_array($c->tipo, ['mercadolibre', 'woocommerce', 'tiendanube', 'shopify'], true)) { try { app(VentasTiendaService::class)->entradaAutomatica($pedido, $c); } catch (\Throwable) {} }
        return $pedido->fresh();
    }

    // Normaliza el JSON que manda cada plataforma (o un integrador) al webhook de entrada.
    public function normalizar(string $tipo, array $raw): array
    {
        return match ($tipo) {
            'woocommerce' => ['external_id' => (string) ($raw['id'] ?? ''), 'numero' => $raw['number'] ?? null, 'fecha' => $raw['date_created'] ?? null, 'cancelado' => in_array($raw['status'] ?? '', ['cancelled', 'refunded', 'failed'], true), 'sin_pagar' => in_array($raw['status'] ?? 'processing', ['pending', 'on-hold'], true) && empty($raw['date_paid']), 'descuento' => (float) ($raw['discount_total'] ?? 0), 'cliente' => ['cuit' => self::meta($raw, ['_billing_cuit', 'cuit', '_billing_dni', 'billing_cuit', 'dni']), 'condicion_iva' => self::meta($raw, ['_billing_condicion_iva', 'condicion_iva', 'billing_condicion_iva', 'iva']), 'nombre' => trim(($raw['billing']['first_name'] ?? '') . ' ' . ($raw['billing']['last_name'] ?? '')), 'email' => $raw['billing']['email'] ?? null, 'telefono' => $raw['billing']['phone'] ?? null, 'direccion' => trim(($raw['shipping']['address_1'] ?? $raw['billing']['address_1'] ?? '') . ' ' . ($raw['shipping']['city'] ?? $raw['billing']['city'] ?? ''))], 'items' => array_map(fn($l) => ['sku' => $l['sku'] ?? null, 'external_item' => (string) ($l['product_id'] ?? ''), 'variante' => ! empty($l['variation_id']) ? (string) $l['variation_id'] : null, 'descripcion' => $l['name'] ?? 'Ítem', 'cantidad' => (float) ($l['quantity'] ?? 1), 'precio_unit' => (float) ($l['total'] ?? 0) / max(1, (float) ($l['quantity'] ?? 1))], $raw['line_items'] ?? []), 'entrega' => 'envio', 'envio' => (float) ($raw['shipping_total'] ?? 0), 'total' => (float) ($raw['total'] ?? 0), 'pago' => ($raw['status'] ?? '') === 'processing' || ($raw['date_paid'] ?? null) ? 'pagado_externo' : 'a_convenir', 'notas' => $raw['customer_note'] ?? null],
            'shopify' => ['external_id' => (string) ($raw['id'] ?? ''), 'numero' => $raw['order_number'] ?? ($raw['name'] ?? null), 'fecha' => $raw['created_at'] ?? null, 'cancelado' => ! empty($raw['cancelled_at']) || in_array($raw['financial_status'] ?? '', ['refunded', 'voided'], true), 'sin_pagar' => ($raw['financial_status'] ?? 'paid') !== 'paid', 'descuento' => (float) ($raw['total_discounts'] ?? 0), 'cliente' => ['cuit' => self::atributo($raw, ['cuit', 'dni', 'documento']), 'condicion_iva' => self::atributo($raw, ['condicion_iva', 'iva', 'condición de iva']), 'nombre' => trim(($raw['customer']['first_name'] ?? '') . ' ' . ($raw['customer']['last_name'] ?? '')) ?: ($raw['email'] ?? 'Cliente Shopify'), 'email' => $raw['email'] ?? null, 'telefono' => $raw['phone'] ?? ($raw['shipping_address']['phone'] ?? null), 'direccion' => trim(($raw['shipping_address']['address1'] ?? '') . ' ' . ($raw['shipping_address']['city'] ?? ''))], 'items' => array_map(fn($l) => ['sku' => $l['sku'] ?? null, 'external_item' => (string) ($l['product_id'] ?? ''), 'variante' => ! empty($l['variant_id']) ? (string) $l['variant_id'] : null, 'descripcion' => $l['title'] ?? $l['name'] ?? 'Ítem', 'cantidad' => (float) ($l['quantity'] ?? 1), 'precio_unit' => (float) ($l['price'] ?? 0)], $raw['line_items'] ?? []), 'entrega' => 'envio', 'envio' => (float) ($raw['total_shipping_price_set']['shop_money']['amount'] ?? 0), 'total' => (float) ($raw['total_price'] ?? 0), 'pago' => ($raw['financial_status'] ?? '') === 'paid' ? 'pagado_externo' : 'a_convenir', 'notas' => $raw['note'] ?? null],
            'mercadolibre' => ['external_id' => (string) ($raw['id'] ?? ''), 'numero' => $raw['id'] ?? null, 'fecha' => $raw['date_created'] ?? null, 'cancelado' => ($raw['status'] ?? '') === 'cancelled', 'sin_pagar' => ! in_array($raw['status'] ?? 'paid', ['paid', 'confirmed'], true) && ($raw['status'] ?? '') !== 'cancelled', 'pack_id' => $raw['pack_id'] ?? null, 'comprador_id' => $raw['buyer']['id'] ?? null, 'pagos' => array_map(fn($pg) => ['id' => $pg['id'] ?? null, 'monto' => (float) ($pg['total_paid_amount'] ?? 0), 'cargo' => (float) ($pg['marketplace_fee'] ?? 0), 'cuotas' => (float) ($pg['installment_amount'] ?? 0) > 0 && (int) ($pg['installments'] ?? 1) > 1 ? max(0, round((float) $pg['installment_amount'] * (int) $pg['installments'] - (float) ($pg['transaction_amount'] ?? $pg['total_paid_amount'] ?? 0), 2)) : 0, 'envio' => (float) ($pg['shipping_cost'] ?? 0), 'retenciones' => (float) ($pg['taxes_amount'] ?? 0), 'estado' => $pg['status'] ?? null, 'liberacion' => $pg['money_release_date'] ?? null], $raw['payments'] ?? []), 'cliente' => ['cuit' => $raw['buyer']['billing_info']['doc_number'] ?? null, 'condicion_iva' => collect($raw['buyer']['billing_info']['additional_info'] ?? [])->firstWhere('type', 'TAXPAYER_TYPE')['value'] ?? null, 'nombre' => trim(($raw['buyer']['first_name'] ?? '') . ' ' . ($raw['buyer']['last_name'] ?? '')) ?: ($raw['buyer']['nickname'] ?? 'Comprador ML'), 'email' => $raw['buyer']['email'] ?? null, 'telefono' => isset($raw['buyer']['phone']) ? ($raw['buyer']['phone']['area_code'] ?? '') . ($raw['buyer']['phone']['number'] ?? '') : null, 'direccion' => $raw['shipping']['receiver_address']['address_line'] ?? null], 'items' => array_map(fn($l) => ['sku' => $l['item']['seller_sku'] ?? $l['item']['seller_custom_field'] ?? null, 'external_item' => (string) ($l['item']['id'] ?? ''), 'variante' => ! empty($l['item']['variation_id']) ? (string) $l['item']['variation_id'] : null, 'descripcion' => $l['item']['title'] ?? 'Ítem', 'cantidad' => (float) ($l['quantity'] ?? 1), 'precio_unit' => (float) ($l['unit_price'] ?? 0)], $raw['order_items'] ?? []), 'entrega' => 'envio', 'envio' => 0, 'total' => (float) ($raw['total_amount'] ?? 0), 'pago' => ($raw['status'] ?? '') === 'paid' ? 'pagado_externo' : 'a_convenir', 'envio_datos' => ! empty($raw['shipping']['id']) ? ['proveedor' => 'mercadoenvios', 'shipment_id' => (string) $raw['shipping']['id'], 'estado' => $raw['shipping']['status'] ?? null, 'logistica' => $raw['shipping']['logistic_type'] ?? null] : null],
            'tiendanube' => ['external_id' => (string) ($raw['id'] ?? ''), 'numero' => $raw['number'] ?? null, 'fecha' => $raw['created_at'] ?? null, 'cancelado' => ($raw['status'] ?? '') === 'cancelled', 'sin_pagar' => ($raw['payment_status'] ?? 'paid') !== 'paid',
                'cliente' => ['nombre' => $raw['customer']['name'] ?? ($raw['contact_name'] ?? 'Cliente Tienda Nube'), 'email' => $raw['customer']['email'] ?? ($raw['contact_email'] ?? null), 'telefono' => $raw['customer']['phone'] ?? ($raw['contact_phone'] ?? null), 'cuit' => $raw['customer']['identification'] ?? ($raw['contact_identification'] ?? null), 'direccion' => trim(($raw['shipping_address']['address'] ?? '') . ' ' . ($raw['shipping_address']['number'] ?? '') . ', ' . ($raw['shipping_address']['city'] ?? ''), ' ,'), 'condicion_iva' => collect($raw['customer']['custom_fields'] ?? [])->first(fn($f) => stripos($f['name'] ?? '', 'iva') !== false)['value'] ?? null],
                'items' => array_map(fn($l) => ['sku' => $l['sku'] ?? null, 'external_item' => (string) ($l['product_id'] ?? ''), 'variante' => (string) ($l['variant_id'] ?? ''), 'descripcion' => $l['name'] ?? 'Ítem', 'cantidad' => (float) ($l['quantity'] ?? 1), 'precio_unit' => (float) ($l['price'] ?? 0)], $raw['products'] ?? []),
                'entrega' => ($raw['shipping_option'] ?? '') === 'Retiro en el local' || ($raw['shipping_pickup_type'] ?? '') === 'pickup' ? 'retiro' : 'envio', 'envio' => (float) ($raw['shipping_cost_customer'] ?? 0), 'descuento' => (float) ($raw['discount'] ?? 0) + (float) ($raw['promotional_discount']['total_discount_amount'] ?? 0), 'total' => (float) ($raw['total'] ?? 0), 'pago' => 'pagado_externo', 'notas' => $raw['note'] ?? null, 'envio_datos' => ['proveedor' => $raw['shipping_option'] ?? null, 'tracking' => $raw['shipping_tracking_number'] ?? null, 'estado' => $raw['shipping_status'] ?? null]],
            'pedidosya', 'rappi' => ['external_id' => (string) ($raw['id'] ?? $raw['order_id'] ?? $raw['code'] ?? ''), 'cliente' => ['nombre' => $raw['customer']['name'] ?? ($raw['user']['name'] ?? 'Cliente ' . ucfirst($tipo)), 'telefono' => $raw['customer']['phone'] ?? ($raw['user']['phone'] ?? null), 'direccion' => $raw['delivery']['address'] ?? ($raw['address'] ?? ($raw['delivery_information']['address'] ?? null)), 'notas' => $raw['notes'] ?? ($raw['comments'] ?? null)], 'items' => array_map(fn($l) => ['sku' => $l['sku'] ?? ($l['external_id'] ?? ($l['id'] ?? null)), 'descripcion' => $l['name'] ?? ($l['product_name'] ?? 'Ítem'), 'cantidad' => (float) ($l['quantity'] ?? ($l['units'] ?? 1)), 'precio_unit' => (float) ($l['unit_price'] ?? ($l['price'] ?? 0))], $raw['items'] ?? ($raw['products'] ?? ($raw['order_detail']['items'] ?? []))), 'entrega' => 'envio', 'envio' => 0, 'total' => (float) ($raw['total'] ?? ($raw['total_amount'] ?? ($raw['payment']['total'] ?? 0))), 'pago' => 'pagado_externo', 'texto_original' => json_encode($raw, JSON_UNESCAPED_UNICODE)],
            default => ['external_id' => (string) ($raw['external_id'] ?? $raw['id'] ?? ''), 'cliente' => $raw['cliente'] ?? [], 'items' => $raw['items'] ?? [], 'entrega' => $raw['entrega'] ?? 'envio', 'envio' => (float) ($raw['envio'] ?? 0), 'total' => $raw['total'] ?? null, 'pago' => $raw['pago'] ?? 'pagado_externo', 'notas' => $raw['notas'] ?? null],
        };
    }

    private static function meta(array $raw, array $claves): ?string
    {
        foreach ($raw['meta_data'] ?? [] as $m) if (in_array(strtolower((string) ($m['key'] ?? '')), $claves, true) && ! empty($m['value'])) return (string) $m['value'];
        return null;
    }
    private static function atributo(array $raw, array $claves): ?string
    {
        foreach ($raw['note_attributes'] ?? [] as $a) if (in_array(strtolower((string) ($a['name'] ?? '')), $claves, true) && ! empty($a['value'])) return (string) $a['value'];
        return null;
    }

    // La tienda canceló la venta (o el correo la devolvió): si no había entrado se rechaza; si ya era comprobante, vuelve el stock.
    public function cancelado(Canal $c, string $externalId): void
    {
        $p = PedidoWeb::withoutGlobalScopes()->where('business_id', $c->business_id)->where('canal', $c->tipo)->where('external_id', $externalId)->first();
        if (! $p || $p->estado === 'cancelado') return;
        if (! $p->comprobante_id) { $p->forceFill(['estado' => 'cancelado', 'notas' => trim(($p->notas ?? '') . ' · Cancelado en la tienda')])->save(); return; }
        if (str_contains((string) $p->notas, 'Entró sola')) { try { app(VentasTiendaService::class)->devolverStock($p); return; } catch (\Throwable) {} }
        $p->forceFill(['devolucion' => 'pendiente', 'notas' => trim(($p->notas ?? '') . ' · Devolución: la tienda canceló la venta, hay que volver el stock')])->save();
        \App\Models\Alerta::emitir(['business_id' => $c->business_id, 'modulo' => 'comprobantes', 'tipo' => 'pedido_cancelado', 'severidad' => 'warning', 'titulo' => "{$p->numeroFormateado()} cancelado en " . (PedidoWeb::CANALES[$c->tipo] ?? $c->tipo) . ': hay que volver el stock', 'url' => "/comprobantes/pedidos?abrir={$p->id}"]);
    }

    private function tnPedidos(Canal $c): array
    {
        $r = $this->cx->tn($c)->get('orders', ['payment_status' => 'paid', 'per_page' => 50, 'created_at_min' => ($c->ultima_importacion_en ?? now()->subDays(7))->toIso8601String()]);
        if (! $r->ok()) throw new \RuntimeException("Tienda Nube HTTP {$r->status()}");
        return array_map(fn($o) => $this->normalizar('tiendanube', $o), $r->json() ?? []);
    }

    // Stock y precio hacia los canales que lo piden. Se llama al mover stock o cambiar precio; nunca corta la operación.
    // Fase 30: va por las publicaciones (lo publicado desde acá y lo adoptado); los canales sin publicación siguen por SKU.
    public function empujarStock(Product $p): void
    {
        $pubs = \App\Models\Publicacion::withoutGlobalScopes()->where('business_id', $p->business_id)->where('product_id', $p->id)->with('canal')->get();
        foreach ($pubs->groupBy('canal_id') as $grupo) { $canal = $grupo->first()->canal; if ($canal && $canal->activo) { try { app(PublicacionesService::class)->sincronizar($canal, $grupo->each->setRelation('product', $p)); } catch (\Throwable) {} } }
        $conPub = $pubs->pluck('canal_id')->all();
        foreach (Canal::withoutGlobalScopes()->where('business_id', $p->business_id)->where('activo', true)->where('sync_stock', true)->whereNotIn('id', $conPub)->whereNotIn('tipo', ['tiendanube'])->get() as $c) {
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
