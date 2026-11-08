<?php

namespace App\Services\Canales;

use App\Models\AuditLog;
use App\Models\Canal;
use App\Models\PedidoWeb;
use App\Models\Product;
use App\Services\Gastronomia\HorariosService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 54 · Conectores directos de PedidosYa, Rappi y Uber Eats: aceptar o rechazar cada pedido, avisar que está listo,
// mandar la carta (con las opciones de cada plato) y pausar lo que se agotó. Las URL salen de la documentación de cada
// plataforma y se pueden pisar desde las credenciales (base_url / auth_url) si el integrador indica otras.
class DeliveryApiService
{
    public const TIPOS = ['pedidosya', 'rappi', 'ubereats'];

    private const BASES = [
        'rappi' => ['base' => 'https://services.rappi.com.ar/api/v2/restaurants-integrations-public-api', 'auth' => 'https://rests-integrations.auth0.com/oauth/token', 'audience' => 'https://int-public-api-v2/api'],
        'ubereats' => ['base' => 'https://api.uber.com', 'auth' => 'https://auth.uber.com/oauth/v2/token', 'scope' => 'eats.order eats.store eats.store.orders.read eats.store.status.write'],
        'pedidosya' => ['base' => 'https://integration-middleware.us.restaurant-partners.com', 'auth' => 'https://integration-middleware.us.restaurant-partners.com/v2/login'],
    ];

    public function __construct(private ConexionesService $cx) {}

    public static function esDelivery(?string $tipo): bool { return in_array($tipo, self::TIPOS, true); }

    public function base(Canal $c, string $k = 'base'): string { return rtrim((string) ($c->credenciales[$k === 'base' ? 'base_url' : 'auth_url'] ?? self::BASES[$c->tipo][$k]), '/'); }

    // Token de cada plataforma (client_credentials o usuario/clave), guardado en las credenciales hasta que venza.
    public function token(Canal $c): string
    {
        $cr = $c->credenciales ?? [];
        if (! empty($cr['token']) && ! empty($cr['token_expira']) && now()->lt(\Carbon\Carbon::parse($cr['token_expira'])->subMinutes(5))) return $cr['token'];
        $auth = $this->base($c, 'auth');
        $r = match ($c->tipo) {
            'rappi' => Http::acceptJson()->timeout(15)->post($auth, ['client_id' => $cr['client_id'] ?? '', 'client_secret' => $cr['client_secret'] ?? '', 'audience' => $cr['audience'] ?? self::BASES['rappi']['audience'], 'grant_type' => 'client_credentials']),
            'ubereats' => Http::asForm()->acceptJson()->timeout(15)->post($auth, ['client_id' => $cr['client_id'] ?? '', 'client_secret' => $cr['client_secret'] ?? '', 'grant_type' => 'client_credentials', 'scope' => $cr['scope'] ?? self::BASES['ubereats']['scope']]),
            'pedidosya' => Http::asForm()->acceptJson()->timeout(15)->post($auth, ['username' => $cr['username'] ?? '', 'password' => $cr['password'] ?? '', 'grant_type' => 'client_credentials']),
            default => throw new \RuntimeException('Esta plataforma no tiene conector directo.'),
        };
        if (! $r->ok() || ! ($t = $r->json('access_token'))) throw new \RuntimeException(Canal::TIPOS[$c->tipo]['label'] . " no aceptó las credenciales ({$r->status()}). Revisá las claves que te dio la plataforma.");
        if ($c->exists) $c->forceFill(['credenciales' => array_merge($cr, ['token' => $t, 'token_expira' => now()->addSeconds((int) ($r->json('expires_in') ?: 3600))->toDateTimeString()])])->save();
        else $c->credenciales = array_merge($cr, ['token' => $t, 'token_expira' => now()->addSeconds((int) ($r->json('expires_in') ?: 3600))->toDateTimeString()]);
        return $t;
    }

    public function http(Canal $c) { return Http::withToken($this->token($c))->acceptJson()->timeout(20)->baseUrl($this->base($c)); }

    public function probar(Canal $c): array
    {
        try { $this->token($c); return ['ok' => true, 'nombre' => $c->credenciales['store_id'] ?? $c->credenciales['vendor_id'] ?? null, 'mensaje' => null]; }
        catch (\Throwable $e) { return ['ok' => false, 'nombre' => null, 'mensaje' => mb_substr($e->getMessage(), 0, 200)]; }
    }

    private function anotar(PedidoWeb $p, string $que, bool $ok, ?string $detalle = null): void
    {
        $a = $p->avisos ?? []; $a['plataforma'][$que] = ['en' => now()->toDateTimeString(), 'ok' => $ok, 'detalle' => $detalle];
        $p->forceFill(['avisos' => $a])->save();
    }

    private function canalDe(PedidoWeb $p): ?Canal
    {
        return self::esDelivery($p->canal) ? Canal::withoutGlobalScopes()->where('business_id', $p->business_id)->where('tipo', $p->canal)->where('activo', true)->first() : null;
    }

    // Aceptar el pedido en la plataforma (Rappi cancela a los 4 minutos si nadie lo toma; Uber a los 11).
    public function aceptar(PedidoWeb $p, ?int $minutos = null): bool
    {
        $c = $this->canalDe($p); if (! $c || ! $p->external_id || ! empty($p->avisos['plataforma']['aceptado']['ok'])) return (bool) ($p->avisos['plataforma']['aceptado']['ok'] ?? false);
        $min = $minutos ?: (int) $c->cfg('tiempo_preparacion', 20);
        try {
            $r = match ($c->tipo) {
                'rappi' => $this->http($c)->post("orders/{$p->external_id}/take", ['cooking_time' => $min]),
                'ubereats' => $this->http($c)->post("/v1/eats/orders/{$p->external_id}/accept_pos_order", ['reason' => 'accepted', 'pickup_time' => now()->addMinutes($min)->toIso8601String()]),
                'pedidosya' => $this->http($c)->post("/v2/order/status/{$p->external_id}", ['status' => 'order_accepted', 'acceptanceTime' => now()->addMinutes($min)->toIso8601String(), 'remoteOrderId' => $p->numeroFormateado()]),
            };
            $ok = $r->successful(); $this->anotar($p, 'aceptado', $ok, $ok ? null : "HTTP {$r->status()}: " . mb_substr($r->body(), 0, 200));
            if (! $ok) Log::warning("Delivery {$c->tipo} aceptar {$p->external_id}: {$r->status()} " . mb_substr($r->body(), 0, 200));
            return $ok;
        } catch (\Throwable $e) { $this->anotar($p, 'aceptado', false, mb_substr($e->getMessage(), 0, 200)); Log::warning("Delivery {$c->tipo} aceptar: " . $e->getMessage()); return false; }
    }

    public function rechazar(PedidoWeb $p, string $motivo = 'No podemos tomar el pedido en este momento.'): bool
    {
        $c = $this->canalDe($p); if (! $c || ! $p->external_id) return false;
        try {
            $r = match ($c->tipo) {
                'rappi' => $this->http($c)->post("orders/{$p->external_id}/reject", ['reason' => $motivo]),
                'ubereats' => $this->http($c)->post("/v1/eats/orders/{$p->external_id}/deny_pos_order", ['reason' => ['explanation' => $motivo, 'code' => 'OTHER']]),
                'pedidosya' => $this->http($c)->post("/v2/order/status/{$p->external_id}", ['status' => 'order_rejected', 'message' => $motivo, 'reason' => 'OTHER']),
            };
            $this->anotar($p, 'rechazado', $r->successful(), $r->successful() ? $motivo : "HTTP {$r->status()}");
            return $r->successful();
        } catch (\Throwable $e) { $this->anotar($p, 'rechazado', false, mb_substr($e->getMessage(), 0, 200)); return false; }
    }

    // El pedido está listo para que lo retire el repartidor. Uber no tiene este aviso para los envíos que hace Uber.
    public function listo(PedidoWeb $p): bool
    {
        $c = $this->canalDe($p); if (! $c || ! $p->external_id || ! empty($p->avisos['plataforma']['listo']['ok'])) return (bool) ($p->avisos['plataforma']['listo']['ok'] ?? false);
        try {
            $r = match ($c->tipo) {
                'rappi' => $this->http($c)->post("orders/{$p->external_id}/ready-for-pickup"),
                'pedidosya' => $this->http($c)->post("/v2/order/status/{$p->external_id}", ['status' => 'order_prepared']),
                'ubereats' => null,
            };
            $ok = $r === null || $r->successful(); $this->anotar($p, 'listo', $ok, $r === null ? 'Uber Eats no lleva este aviso' : ($ok ? null : "HTTP {$r->status()}"));
            return $ok;
        } catch (\Throwable $e) { $this->anotar($p, 'listo', false, mb_substr($e->getMessage(), 0, 200)); return false; }
    }

    // Desde Pedidos web: el estado que eligió el local se refleja en la plataforma.
    public function estadoCambiado(PedidoWeb $p, string $estado): void
    {
        if (! self::esDelivery($p->canal)) return;
        match ($estado) { 'confirmado', 'preparando' => $this->aceptar($p), 'enviado', 'entregado' => $this->listo($p), 'cancelado' => $this->rechazar($p), default => null };
    }

    // Uber Eats avisa con el id: hay que ir a buscar el pedido completo.
    public function pedidoUber(Canal $c, string $orderId): ?array
    {
        $r = $this->http($c)->get("/v1/eats/orders/{$orderId}");
        return $r->ok() ? $r->json() : null;
    }

    // Rappi no avisa: se le pregunta qué pedidos nuevos hay (cada 10 minutos, y cuando el local abre Pedidos).
    public function pedidosRappi(Canal $c): array
    {
        $r = $this->http($c)->get('orders/status/sent');
        if (! $r->ok()) throw new \RuntimeException("Rappi contestó {$r->status()} al pedir los pedidos nuevos.");
        return collect($r->json() ?? [])->map(fn($raw) => app(CanalesService::class)->normalizar('rappi', $raw))->all();
    }

    // ---- La carta hacia la plataforma ----

    // Carta neutra: rubros → platos con precio final y grupos de opciones. Sólo lo activo, publicado en tienda y que se sirve a cualquier hora o ahora.
    public function carta(Canal $c): array
    {
        $b = $c->business; $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $pub = app(PublicacionesService::class);
        $prods = Product::withoutGlobalScopes()->where('business_id', $b->id)->where('active', true)->where('en_tienda', true)->whereIn('tipo', ['producto', 'elaborado', 'servicio'])->with('rubro:id,nombre,orden')->orderBy('name')->get();
        $rubros = [];
        foreach ($prods as $p) {
            $precio = round((float) $p->precioLista($pub->listaPara($c, $p)) * ($ri ? 1 + (float) $p->iva / 100 : 1), 2);
            $precio = round($precio * (1 + (float) $c->cfg('recargo_pct', 0) / 100), 2);
            $rn = $p->rubro?->nombre ?? 'Carta';
            $rubros[$rn]['nombre'] = $rn; $rubros[$rn]['orden'] = $p->rubro?->orden ?? 999;
            $rubros[$rn]['platos'][] = ['id' => (string) ($p->sku ?: $p->id), 'product_id' => $p->id, 'nombre' => $p->name, 'descripcion' => $p->descripcion_tienda ?: $p->description, 'precio' => $precio, 'disponible' => (! $p->controla_stock || (float) $p->stock > 0) && HorariosService::disponible($p), 'imagen' => $p->imagen,
                'opciones' => collect($p->modificadores ?? [])->map(fn($g) => ['id' => (string) ($p->sku ?: $p->id) . '-' . \Illuminate\Support\Str::slug($g['nombre']), 'nombre' => $g['nombre'], 'min' => ! empty($g['obligatorio']) ? 1 : 0, 'max' => ! empty($g['multiple']) ? count($g['opciones'] ?? []) : 1,
                    'items' => collect($g['opciones'] ?? [])->map(fn($o) => ['id' => (string) ($p->sku ?: $p->id) . '-' . \Illuminate\Support\Str::slug($g['nombre'] . '-' . $o['nombre']), 'nombre' => $o['nombre'], 'precio' => round((float) ($o['precio'] ?? 0), 2)])->values()->all()])->values()->all()];
        }
        return collect($rubros)->sortBy('orden')->values()->all();
    }

    // Formato de cada plataforma. Centavos para Uber; pesos para Rappi y PedidosYa.
    public function payloadCarta(Canal $c, array $carta): array
    {
        $cr = $c->credenciales ?? [];
        return match ($c->tipo) {
            'rappi' => ['storeId' => $cr['store_id'] ?? null, 'items' => collect($carta)->flatMap(fn($r) => collect($r['platos'])->map(fn($p) => ['sku' => $p['id'], 'name' => $p['nombre'], 'description' => (string) $p['descripcion'], 'price' => $p['precio'], 'available' => $p['disponible'], 'category' => ['id' => \Illuminate\Support\Str::slug($r['nombre']), 'name' => $r['nombre']], 'imageUrl' => $p['imagen'],
                'toppingCategories' => collect($p['opciones'])->map(fn($g) => ['id' => $g['id'], 'name' => $g['nombre'], 'minToppingsForCategory' => $g['min'], 'maxToppingsForCategory' => $g['max'], 'toppings' => collect($g['items'])->map(fn($o) => ['id' => $o['id'], 'name' => $o['nombre'], 'price' => $o['precio'], 'available' => true])->all()])->all()]))->values()->all()],
            'ubereats' => (function () use ($carta) {
                $items = []; $grupos = []; $cats = [];
                foreach ($carta as $r) {
                    $cats[] = ['id' => \Illuminate\Support\Str::slug($r['nombre']), 'title' => ['translations' => ['es' => $r['nombre']]], 'entities' => collect($r['platos'])->map(fn($p) => ['id' => $p['id'], 'type' => 'ITEM'])->all()];
                    foreach ($r['platos'] as $p) {
                        $items[] = ['id' => $p['id'], 'external_data' => $p['id'], 'title' => ['translations' => ['es' => $p['nombre']]], 'description' => ['translations' => ['es' => (string) $p['descripcion']]], 'price_info' => ['price' => (int) round($p['precio'] * 100)], 'modifier_group_ids' => ['ids' => array_column($p['opciones'], 'id')], 'suspension_info' => $p['disponible'] ? null : ['suspension' => ['suspend_until' => 0, 'reason' => 'Sin stock']]] + ($p['imagen'] ? ['image_url' => $p['imagen']] : []);
                        foreach ($p['opciones'] as $g) {
                            $grupos[] = ['id' => $g['id'], 'title' => ['translations' => ['es' => $g['nombre']]], 'quantity_info' => ['quantity' => ['min_permitted' => $g['min'], 'max_permitted' => $g['max']]], 'modifier_options' => collect($g['items'])->map(fn($o) => ['id' => $o['id'], 'type' => 'ITEM'])->all()];
                            foreach ($g['items'] as $o) $items[] = ['id' => $o['id'], 'external_data' => $o['id'], 'title' => ['translations' => ['es' => $o['nombre']]], 'price_info' => ['price' => (int) round($o['precio'] * 100)]];
                        }
                    }
                }
                return ['menus' => [['id' => 'carta', 'title' => ['translations' => ['es' => 'Carta']], 'service_availability' => collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])->map(fn($d) => ['day_of_week' => $d, 'time_periods' => [['start_time' => '00:00', 'end_time' => '23:59']]])->all(), 'category_ids' => array_column($cats, 'id')]], 'categories' => $cats, 'items' => $items, 'modifier_groups' => $grupos];
            })(),
            'pedidosya' => ['catalog' => ['items' => collect($carta)->flatMap(fn($r) => collect($r['platos'])->mapWithKeys(fn($p) => [$p['id'] => ['type' => 'Product', 'title' => ['default' => $p['nombre']], 'description' => ['default' => (string) $p['descripcion']], 'price' => (string) $p['precio'], 'active' => $p['disponible'], 'images' => $p['imagen'] ? [['url' => $p['imagen']]] : [],
                'categories' => [\Illuminate\Support\Str::slug($r['nombre'])], 'toppings' => collect($p['opciones'])->map(fn($g) => ['id' => $g['id'], 'title' => ['default' => $g['nombre']], 'quantity' => ['minimum' => $g['min'], 'maximum' => $g['max']], 'products' => collect($g['items'])->map(fn($o) => ['id' => $o['id'], 'title' => ['default' => $o['nombre']], 'price' => (string) $o['precio']])->all()])->all()]]))->all(),
                'categories' => collect($carta)->mapWithKeys(fn($r) => [\Illuminate\Support\Str::slug($r['nombre']) => ['type' => 'Category', 'title' => ['default' => $r['nombre']]]])->all()], 'callbackUrl' => url('/api/canales/' . $c->tipo . '/' . $c->token_entrada)],
        };
    }

    public function publicarCarta(Canal $c): array
    {
        $carta = $this->carta($c); $n = collect($carta)->sum(fn($r) => count($r['platos']));
        if (! $n) return ['ok' => false, 'mensaje' => 'No hay platos publicados en la tienda (Stock → Tienda online) para mandar.'];
        $cr = $c->credenciales ?? []; $payload = $this->payloadCarta($c, $carta);
        try {
            $r = match ($c->tipo) {
                'rappi' => $this->http($c)->put('menu', $payload),
                'ubereats' => $this->http($c)->put('/v2/eats/stores/' . ($cr['store_id'] ?? '') . '/menus', $payload),
                'pedidosya' => $this->http($c)->put('/v2/chains/' . ($cr['chain_code'] ?? '') . '/catalog', $payload),
            };
            $ok = $r->successful();
            $c->forceFill(['ultimo_sync_en' => now(), 'ultimo_error' => $ok ? null : "Carta: HTTP {$r->status()} " . mb_substr($r->body(), 0, 200)])->save();
            AuditLog::registrar('publicar', $c, ($ok ? 'Mandó la carta a ' : 'Falló la carta a ') . $c->nombre . " ({$n} platos)");
            return ['ok' => $ok, 'platos' => $n, 'mensaje' => $ok ? "Carta enviada a {$c->nombre}: {$n} platos. La plataforma la publica en unos minutos." : "{$c->nombre} contestó {$r->status()}: " . mb_substr($r->body(), 0, 200)];
        } catch (\Throwable $e) { $c->forceFill(['ultimo_error' => mb_substr($e->getMessage(), 0, 300)])->save(); return ['ok' => false, 'mensaje' => mb_substr($e->getMessage(), 0, 200)]; }
    }

    // Pausar o volver a activar un plato (se agotó, volvió).
    public function disponibilidad(Canal $c, Product $p, bool $disponible): bool
    {
        $cr = $c->credenciales ?? []; $id = (string) ($p->sku ?: $p->id);
        try {
            $r = match ($c->tipo) {
                'rappi' => $this->http($c)->put('availability/stores/items', ['store_integration_id' => $cr['store_id'] ?? null, 'items' => [['id' => $id, 'available' => $disponible]]]),
                'ubereats' => $this->http($c)->post('/v2/eats/stores/' . ($cr['store_id'] ?? '') . "/menus/items/{$id}", ['suspension_info' => $disponible ? null : ['suspension' => ['suspend_until' => 0, 'reason' => 'Sin stock']]]),
                'pedidosya' => $this->http($c)->put('/v2/chains/' . ($cr['chain_code'] ?? '') . '/remoteVendors/' . ($cr['vendor_id'] ?? '') . '/availability', ['items' => [['remoteCode' => $id, 'availability' => $disponible ? 'AVAILABLE' : 'UNAVAILABLE']]]),
            };
            return $r->successful();
        } catch (\Throwable $e) { Log::warning("Delivery {$c->tipo} disponibilidad: " . $e->getMessage()); return false; }
    }
}
