<?php

namespace Tests\Feature;

use App\Models\Canal;
use App\Models\Comanda;
use App\Models\PedidoWeb;
use App\Services\Canales\CanalesService;
use App\Services\Canales\DeliveryApiService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 54 · Conectores directos de PedidosYa, Rappi y Uber Eats: el pedido entra, va a cocina como comanda, se acepta en la plataforma,
// se avisa cuando está listo, se rechaza desde el ERP, se manda la carta con las opciones y se pausa lo agotado. Todo contra respuestas simuladas.
class DeliveryApisFase54Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->forceFill(['vertical' => 'gastronomia'])->save();
    }

    private function canal(string $tipo, array $cred): Canal
    {
        return Canal::create(['business_id' => $this->empresa->id, 'tipo' => $tipo, 'nombre' => Canal::TIPOS[$tipo]['label'], 'credenciales' => $cred, 'activo' => true, 'importar_pedidos' => true, 'sync_stock' => true]);
    }

    public function test_rappi_pregunta_los_pedidos_los_toma_los_manda_a_cocina_y_avisa_listo(): void
    {
        $pizza = $this->articulo(['name' => 'Pizza muzza', 'sku' => 'PZ1', 'price' => 9000, 'iva' => 0]); $pizza->forceFill(['va_cocina' => true, 'en_tienda' => true])->save();
        $c = $this->canal('rappi', ['client_id' => 'id', 'client_secret' => 'sec', 'store_id' => '777']);
        Http::fake([
            'rests-integrations.auth0.com/*' => Http::response(['access_token' => 'tok-rappi', 'expires_in' => 86400]),
            'services.rappi.com.ar/*/orders/status/sent' => Http::response([['id' => 'R-55', 'customer' => ['name' => 'Pedro', 'phone' => '351555'], 'delivery' => ['address' => 'Belgrano 100'], 'items' => [['sku' => 'PZ1', 'name' => 'Pizza muzza', 'quantity' => 2, 'unit_price' => 9500]], 'total' => 19000]]),
            'services.rappi.com.ar/*' => Http::response(['ok' => true]),
        ]);
        $r = app(CanalesService::class)->importarPedidos($c);
        $this->assertSame(1, $r['importados']); $this->assertNull($r['error']);
        $pw = PedidoWeb::where('canal', 'rappi')->first();
        $this->assertSame('R-55', $pw->external_id); $this->assertSame('preparando', $pw->estado); $this->assertNotNull($pw->comanda_id);
        $this->assertEqualsWithDelta(19000, (float) $pw->total, 0.01, 'El precio real cobrado por la app');
        $cm = Comanda::find($pw->comanda_id); $this->assertSame('delivery', $cm->tipo); $this->assertStringContainsString('Pedro', $cm->cliente); $this->assertSame('cocina', $cm->items()->first()->estado);
        Http::assertSent(fn($q) => str_contains($q->url(), '/orders/R-55/take') && $q['cooking_time'] === 20);
        $this->assertTrue($pw->fresh()->avisos['plataforma']['aceptado']['ok']);
        $this->assertSame('tok-rappi', $c->fresh()->credenciales['token'], 'El token queda guardado hasta que venza');
        // Segunda vuelta: el mismo pedido no entra dos veces
        $this->assertSame(0, app(CanalesService::class)->importarPedidos($c)['importados']);

        // Cocina marca listo → Rappi se entera y el pedido pasa a "en camino"
        $this->post("/gastronomia/items/{$cm->items()->first()->id}/estado", ['estado' => 'listo']);
        Http::assertSent(fn($q) => str_contains($q->url(), '/orders/R-55/ready-for-pickup'));
        $this->assertSame('enviado', $pw->fresh()->estado);
    }

    public function test_uber_eats_avisa_con_el_id_y_el_sistema_busca_el_pedido_y_lo_acepta(): void
    {
        $this->articulo(['name' => 'Hamburguesa', 'sku' => 'HAM1', 'price' => 8000, 'iva' => 0])->forceFill(['en_tienda' => true])->save();
        $c = $this->canal('ubereats', ['client_id' => 'id', 'client_secret' => 'sec', 'store_id' => 'store-1']);
        auth()->logout();
        Http::fake([
            'auth.uber.com/*' => Http::response(['access_token' => 'tok-uber', 'expires_in' => 2592000]),
            'api.uber.com/v1/eats/orders/ord-1' => Http::response(['id' => 'ord-1', 'display_id' => 'A1B2', 'type' => 'DELIVERY_BY_UBER', 'eater' => ['first_name' => 'Lu', 'last_name' => 'G'], 'cart' => ['items' => [['title' => 'Hamburguesa', 'quantity' => 1, 'external_data' => 'HAM1', 'price' => ['unit_price' => ['amount' => 850000]]]]], 'payment' => ['charges' => ['total' => ['amount' => 850000]]]]),
            'api.uber.com/*' => Http::response(['ok' => true]),
        ]);
        $this->postJson("/api/canales/ubereats/{$c->token_entrada}", ['event_type' => 'orders.notification', 'meta' => ['resource_id' => 'ord-1', 'status' => 'pos']])->assertOk();
        $pw = PedidoWeb::withoutGlobalScopes()->where('canal', 'ubereats')->first();
        $this->assertNotNull($pw); $this->assertSame('A1B2', $pw->external_id); $this->assertNotNull($pw->comanda_id);
        Http::assertSent(fn($q) => str_contains($q->url(), '/orders/A1B2/accept_pos_order'));
        // Un aviso que no es de pedido se ignora; sin claves, pide el pedido completo
        $this->postJson("/api/canales/ubereats/{$c->token_entrada}", ['event_type' => 'store.provisioned', 'meta' => ['resource_id' => 'x']])->assertOk()->assertJsonPath('ignorado', 'store.provisioned');
        $c->forceFill(['credenciales' => ['store_id' => 'store-1']])->save();
        $this->postJson("/api/canales/ubereats/{$c->token_entrada}", ['event_type' => 'orders.notification', 'meta' => ['resource_id' => 'ord-2']])->assertStatus(202);
    }

    public function test_pedidosya_entra_por_webhook_se_acepta_y_al_cancelar_desde_el_erp_se_rechaza(): void
    {
        $this->articulo(['name' => 'Empanada', 'sku' => 'EMP', 'price' => 1500, 'iva' => 0])->forceFill(['en_tienda' => true])->save();
        $c = $this->canal('pedidosya', ['username' => 'u', 'password' => 'p', 'chain_code' => 'CH', 'vendor_id' => 'V1']);
        Http::fake(['integration-middleware.us.restaurant-partners.com/v2/login' => Http::response(['access_token' => 'tok-py', 'expires_in' => 3600]), 'integration-middleware.us.restaurant-partners.com/*' => Http::response(['ok' => true])]);
        auth()->logout();
        $this->postJson("/api/canales/pedidosya/{$c->token_entrada}", ['id' => 'PY-9', 'customer' => ['name' => 'Ana', 'phone' => '351'], 'delivery' => ['address' => 'Mitre 20'], 'items' => [['sku' => 'EMP', 'name' => 'Empanada', 'quantity' => 12, 'unit_price' => 1500]], 'total' => 18000])->assertOk();
        $pw = PedidoWeb::withoutGlobalScopes()->where('canal', 'pedidosya')->first();
        Http::assertSent(fn($q) => str_contains($q->url(), '/v2/order/status/PY-9') && $q['status'] === 'order_accepted');
        $this->actingAs($this->dueno)->post("/comprobantes/pedidos/{$pw->id}/estado", ['estado' => 'cancelado'])->assertSessionHas('success');
        Http::assertSent(fn($q) => str_contains($q->url(), '/v2/order/status/PY-9') && $q['status'] === 'order_rejected');
        $this->assertFalse($pw->fresh()->avisos['plataforma']['rechazado']['ok'] === false);
    }

    public function test_la_carta_viaja_con_las_opciones_y_lo_agotado_se_pausa(): void
    {
        $bife = $this->articulo(['name' => 'Bife', 'sku' => 'BIFE', 'price' => 10000, 'iva' => 21, 'stock_inicial' => 5]);
        $bife->forceFill(['en_tienda' => true, 'modificadores' => [['nombre' => 'Punto', 'obligatorio' => true, 'multiple' => false, 'opciones' => [['nombre' => 'Jugoso', 'precio' => 0], ['nombre' => 'A punto', 'precio' => 0]]], ['nombre' => 'Extras', 'multiple' => true, 'opciones' => [['nombre' => '+ Huevo', 'precio' => 800]]]]])->save();
        $sinStock = $this->articulo(['name' => 'Flan', 'sku' => 'FLAN', 'price' => 3000, 'iva' => 21]); $sinStock->forceFill(['en_tienda' => true])->save();
        $c = $this->canal('pedidosya', ['username' => 'u', 'password' => 'p', 'chain_code' => 'CH', 'vendor_id' => 'V1']);
        Http::fake(['integration-middleware.us.restaurant-partners.com/v2/login' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]), 'integration-middleware.us.restaurant-partners.com/*' => Http::response(['ok' => true])]);

        $carta = app(DeliveryApiService::class)->carta($c);
        $plato = collect($carta)->flatMap(fn($r) => $r['platos'])->firstWhere('id', 'BIFE');
        $this->assertEqualsWithDelta(12100, $plato['precio'], 0.01, 'Precio final con IVA');
        $this->assertSame(1, $plato['opciones'][0]['min']); $this->assertSame(1, $plato['opciones'][0]['max']); $this->assertEqualsWithDelta(800, $plato['opciones'][1]['items'][0]['precio'], 0.01);
        $this->assertFalse(collect($carta)->flatMap(fn($r) => $r['platos'])->firstWhere('id', 'FLAN')['disponible'], 'Sin stock va como no disponible');
        $uber = app(DeliveryApiService::class)->payloadCarta(new Canal(['tipo' => 'ubereats', 'credenciales' => ['store_id' => 's']]), $carta);
        $this->assertSame(1210000, collect($uber['items'])->firstWhere('id', 'BIFE')['price_info']['price'], 'Uber va en centavos');
        $this->assertCount(2, $uber['modifier_groups']);

        $this->post("/configuracion/tienda/canales/{$c->id}/carta")->assertSessionHas('success');
        Http::assertSent(fn($q) => $q->method() === 'PUT' && str_contains($q->url(), '/v2/chains/CH/catalog') && isset($q['catalog']['items']['BIFE']['toppings'][0]['products'][0]['title']));

        app(DeliveryApiService::class)->disponibilidad($c, $sinStock, false);
        Http::assertSent(fn($q) => str_contains($q->url(), '/remoteVendors/V1/availability') && $q['items'][0]['availability'] === 'UNAVAILABLE');
    }

    public function test_claves_malas_no_se_guardan(): void
    {
        Http::fake(['rests-integrations.auth0.com/*' => Http::response(['error' => 'access_denied'], 401)]);
        $this->post('/configuracion/tienda/conectar-claves', ['tipo' => 'rappi', 'credenciales' => ['client_id' => 'x', 'client_secret' => 'y', 'store_id' => '1']])->assertSessionHasErrors('credenciales');
        $this->assertSame(0, Canal::count());
    }
}
