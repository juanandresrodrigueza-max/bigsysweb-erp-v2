<?php

namespace Tests\Feature;

use App\Models\Canal;
use App\Models\PedidoWeb;
use App\Models\Publicacion;
use App\Models\Rubro;
use App\Models\RubroCanal;
use App\Models\SistemaConfig;
use App\Services\Canales\PublicacionesService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 30.1 y 30.2: conectar tiendas con un botón o con claves probadas, avisos de venta con firma, publicar desde el catálogo
// con la lista que corresponde y el colchón, y mantener precio y stock solos.
class TiendaOnlineTest extends ErpTestCase
{
    public function test_conectar_mercadolibre_con_un_boton_canjea_el_codigo_y_renueva_el_token(): void
    {
        SistemaConfig::set('ml_app_id', '123'); SistemaConfig::set('ml_app_secret', 'shh');
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::sequence()->push(['access_token' => 'tok1', 'refresh_token' => 'ref1', 'expires_in' => 21600, 'user_id' => 998877])->push(['access_token' => 'tok2', 'refresh_token' => 'ref2', 'expires_in' => 21600, 'user_id' => 998877]),
            'api.mercadolibre.com/users/me' => Http::response(['nickname' => 'CORRALON_DEMO']),
        ]);
        $r = $this->get('/conectar/mercadolibre')->assertRedirect();
        $this->assertStringContainsString('auth.mercadolibre.com.ar/authorization', $r->headers->get('Location'));
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('123', $q['client_id']); $this->assertNotEmpty($q['state']);
        // La vuelta con el código.
        $this->get("/conectar/mercadolibre/volver?code=ABC&state={$q['state']}")->assertRedirect('/configuracion/tienda#canales')->assertSessionHas('success');
        $c = Canal::where('tipo', 'mercadolibre')->first();
        $this->assertSame('998877', $c->external_user_id); $this->assertSame('oauth', $c->metodo); $this->assertSame('tok1', $c->credenciales['access_token']); $this->assertSame('MercadoLibre · CORRALON_DEMO', $c->nombre);
        Http::assertSent(fn($req) => str_contains($req->url(), '/oauth/token') && $req['grant_type'] === 'authorization_code' && $req['code'] === 'ABC');
        // Un state vencido no conecta nada.
        $this->get('/conectar/mercadolibre/volver?code=X&state=inventado')->assertRedirect('/configuracion/tienda#canales')->assertSessionHas('error');
        // El token vence en 6 horas: se renueva solo con el refresh token antes de usar la API.
        $c->forceFill(['credenciales' => array_merge($c->credenciales, ['expires_at' => now()->subMinute()->toIso8601String()])])->save();
        $this->post("/configuracion/tienda/canales/{$c->id}/probar")->assertSessionHas('success');
        $this->assertSame('tok2', $c->fresh()->credenciales['access_token']);
        Http::assertSent(fn($req) => str_contains($req->url(), '/oauth/token') && $req['grant_type'] === 'refresh_token');
        // Sin la aplicación configurada, el botón avisa que es un dato del sistema.
        SistemaConfig::set('tn_app_id', null);
        $this->get('/conectar/tiendanube')->assertSessionHasErrors('app');
    }

    public function test_conectar_woocommerce_con_el_flujo_de_autorizacion_y_crear_los_avisos_de_venta_firmados(): void
    {
        Http::fake([
            'mitienda.com/wp-json/wc/v3/webhooks' => Http::sequence()->push(['id' => 11])->push(['id' => 12]),
            'mitienda.com/wp-json/wc/v3/system_status' => Http::response(['environment' => ['site_url' => 'https://mitienda.com']]),
        ]);
        $this->get('/conectar/woocommerce?url=http://mitienda.com')->assertSessionHasErrors('url'); // tiene que ser HTTPS
        $r = $this->get('/conectar/woocommerce?url=https://mitienda.com')->assertRedirect();
        $loc = $r->headers->get('Location'); $this->assertStringStartsWith('https://mitienda.com/wc-auth/v1/authorize', $loc);
        parse_str(parse_url($loc, PHP_URL_QUERY), $q); $state = $q['user_id'];
        $this->assertSame('read_write', $q['scope']); $this->assertStringContainsString('/api/canales/woocommerce/claves', $q['callback_url']);
        // WooCommerce manda las claves al callback y después trae al usuario de vuelta.
        $this->postJson('/api/canales/woocommerce/claves', ['user_id' => $state, 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_1', 'key_permissions' => 'read_write'])->assertOk();
        $this->get("/conectar/woocommerce/volver?success=1&state={$state}")->assertRedirect('/configuracion/tienda#canales')->assertSessionHas('success');
        $c = Canal::where('tipo', 'woocommerce')->first();
        $this->assertSame('ck_1', $c->credenciales['consumer_key']); $this->assertSame('mitienda.com', $c->external_user_id);
        $this->assertCount(2, $c->webhooks); $this->assertNotEmpty($c->credenciales['webhook_secret']);
        Http::assertSent(fn($req) => str_contains($req->url(), '/webhooks') && $req['topic'] === 'order.created' && $req['delivery_url'] === $c->urlEntrada());
        // Un aviso sin la firma correcta se rechaza; con la firma entra el pedido pagado; uno sin pagar espera.
        $p = $this->articulo(['sku' => 'CEM50', 'stock_inicial' => 10]);
        $pedido = ['id' => 501, 'number' => '501', 'status' => 'processing', 'date_paid' => '2026-10-29T10:00:00', 'total' => '2420.00', 'shipping_total' => '0', 'billing' => ['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ana@x.com'], 'line_items' => [['sku' => 'CEM50', 'name' => 'Cemento', 'quantity' => 2, 'total' => '2420.00']], 'meta_data' => [['key' => '_billing_cuit', 'value' => '20-11111111-1']]];
        $json = json_encode($pedido);
        $this->call('POST', "/api/canales/woocommerce/{$c->token_entrada}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WC_WEBHOOK_SIGNATURE' => 'mala', 'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created'], $json)->assertStatus(401);
        $firma = base64_encode(hash_hmac('sha256', $json, $c->credenciales['webhook_secret'], true));
        $this->call('POST', "/api/canales/woocommerce/{$c->token_entrada}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WC_WEBHOOK_SIGNATURE' => $firma, 'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created'], $json)->assertOk()->assertJsonPath('pedidos', 1);
        $pw = PedidoWeb::first(); $this->assertSame('woocommerce', $pw->canal); $this->assertSame($p->id, $pw->items[0]['product_id']); $this->assertSame('20-11111111-1', $pw->cliente['cuit']);
        $sinPagar = json_encode(['id' => 502, 'status' => 'on-hold', 'total' => '100', 'billing' => ['first_name' => 'B'], 'line_items' => [['sku' => 'CEM50', 'name' => 'Cemento', 'quantity' => 1, 'total' => '100']]]);
        $this->call('POST', "/api/canales/woocommerce/{$c->token_entrada}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $sinPagar, $c->credenciales['webhook_secret'], true))], $sinPagar)->assertOk()->assertJsonPath('pedidos', 0);
        // Desconectar borra los avisos y las claves; los ajustes quedan.
        $this->post("/configuracion/tienda/canales/{$c->id}/config", ['contact_id' => $this->cliente()->id, 'colchon' => 3, 'lista' => 2])->assertSessionHas('success');
        $this->post("/configuracion/tienda/canales/{$c->id}/desconectar")->assertSessionHas('success');
        $c->refresh(); $this->assertNull($c->credenciales); $this->assertFalse($c->activo); $this->assertSame(3, $c->cfg('colchon'));
    }

    public function test_shopify_pide_la_direccion_myshopify_y_la_clave_secreta_y_prueba_antes_de_guardar(): void
    {
        $this->post('/configuracion/tienda/conectar-claves', ['tipo' => 'shopify', 'credenciales' => ['tienda' => 'mitienda.com', 'access_token' => 'shpat_x', 'secret' => 's']])->assertSessionHasErrors('tienda');
        $this->post('/configuracion/tienda/conectar-claves', ['tipo' => 'shopify', 'credenciales' => ['tienda' => 'mitienda.myshopify.com', 'access_token' => 'shpat_x']])->assertSessionHasErrors('secret');
        Http::fake(['mitienda.myshopify.com/admin/api/*/shop.json' => Http::sequence()->push([], 401)->push(['shop' => ['name' => 'Mi Tienda']]), 'mitienda.myshopify.com/admin/api/*/webhooks.json' => Http::response(['webhook' => ['id' => 7]]), 'mitienda.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => [['id' => 55]]]), 'mitienda.myshopify.com/admin/api/*/publications.json' => Http::response(['publications' => [['id' => 9, 'name' => 'Online Store']]])]);
        $this->post('/configuracion/tienda/conectar-claves', ['tipo' => 'shopify', 'credenciales' => ['tienda' => 'https://mitienda.myshopify.com', 'access_token' => 'malo', 'secret' => 's']])->assertSessionHasErrors('credenciales');
        $this->assertSame(0, Canal::count());
        $this->post('/configuracion/tienda/conectar-claves', ['tipo' => 'shopify', 'credenciales' => ['tienda' => 'https://mitienda.myshopify.com', 'access_token' => 'shpat_ok', 'secret' => 'sec']])->assertSessionHas('success');
        $c = Canal::where('tipo', 'shopify')->first();
        $this->assertSame('Shopify · Mi Tienda', $c->nombre); $this->assertSame(55, $c->credenciales['location_id']); $this->assertSame(9, $c->credenciales['publication_id']); $this->assertCount(4, $c->webhooks);
        // "app/uninstalled" con la firma de Shopify desconecta.
        $cuerpo = '{}'; $firma = base64_encode(hash_hmac('sha256', $cuerpo, 'sec', true));
        $this->call('POST', "/api/canales/shopify/{$c->token_entrada}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_HMAC_SHA256' => $firma, 'HTTP_X_SHOPIFY_TOPIC' => 'app/uninstalled'], $cuerpo)->assertOk()->assertJsonPath('desconectado', true);
        $this->assertFalse($c->fresh()->activo);
    }

    private function canalMl(): Canal
    {
        return Canal::create(['business_id' => $this->empresa->id, 'tipo' => 'mercadolibre', 'nombre' => 'ML', 'credenciales' => ['access_token' => 'tok', 'user_id' => '1'], 'external_user_id' => '1', 'activo' => true, 'config' => ['lista' => 2, 'colchon' => 2]]);
    }

    public function test_publicar_en_mercadolibre_con_la_lista_que_corresponde_el_colchon_y_los_motivos_en_castellano(): void
    {
        $c = $this->canalMl();
        $rubro = Rubro::firstOrCreate(['business_id' => $this->empresa->id, 'nombre' => 'General', 'parent_id' => null]);
        $conFoto = $this->articulo(['name' => 'Cemento', 'price' => 1000, 'prices' => ['2' => 1200, '3' => 1500], 'imagen' => 'https://x/cem.jpg', 'stock_inicial' => 12]);
        $sinFoto = $this->articulo(['name' => 'Arena', 'price' => 500, 'prices' => ['2' => 600], 'stock_inicial' => 5]);
        $sinPrecio3 = $this->articulo(['name' => 'Cal', 'price' => 300, 'imagen' => 'https://x/cal.jpg', 'stock_inicial' => 1]);
        $svc = app(PublicacionesService::class);
        // La lista: la del artículo publicado, después la del rubro en la tienda, después la de la tienda, y si no, la 1. Siempre con IVA.
        $this->assertSame(2, $svc->listaPara($c, $conFoto));
        $this->assertEqualsWithDelta(1200 * 1.21, $svc->precioPara($c, $conFoto, 2), 0.01);
        $this->assertSame(10, $svc->stockPara($c, $conFoto), '12 menos el colchón de 2');
        $svc->guardarRubro($c, $rubro, ['lista' => 3]);
        $this->assertSame(3, $svc->listaPara($c, $conFoto));
        // Revisar: el rubro no tiene categoría → candado; sin foto; sin precio en la lista 3.
        $rev = collect($this->postJson("/stock/tienda-online/{$c->id}/revisar", ['ids' => [$conFoto->id, $sinFoto->id, $sinPrecio3->id]])->assertOk()->json())->keyBy('id');
        $this->assertContains('el rubro no tiene categoría de MercadoLibre', $rev[$conFoto->id]['motivos']);
        $this->assertContains('sin foto (MercadoLibre la exige)', $rev[$sinFoto->id]['motivos']);
        $this->assertContains('sin precio en la lista 3', $rev[$sinPrecio3->id]['motivos']); $this->assertNull($rev[$sinPrecio3->id]['precio']);
        $this->assertEqualsWithDelta(1500 * 1.21, $rev[$conFoto->id]['precio'], 0.01);
        // Categoría sugerida por ML y lo que pide (marca, medidas y peso).
        Http::fake([
            'api.mercadolibre.com/sites/MLA/domain_discovery/search*' => Http::response([['category_id' => 'MLA1234', 'category_name' => 'Cementos', 'domain_name' => 'MLA-CEMENT']]),
            'api.mercadolibre.com/categories/MLA1234/attributes' => Http::response([['id' => 'BRAND', 'name' => 'Marca', 'tags' => ['required' => true], 'values' => [['name' => 'Loma Negra']]], ['id' => 'COLOR', 'name' => 'Color', 'tags' => []]]),
            'api.mercadolibre.com/categories/MLA1234' => Http::response(['name' => 'Cementos', 'settings' => ['shipping_modes' => ['me2'], 'minimum_price' => 3500]]),
            'api.mercadolibre.com/items' => Http::response(['id' => 'MLA999', 'permalink' => 'https://articulo.mercadolibre.com.ar/MLA999'], 201),
            'api.mercadolibre.com/items/MLA999/description' => Http::response(['ok' => true]),
            'api.mercadolibre.com/items/*' => Http::response(['id' => 'MLA999']),
        ]);
        $this->getJson("/stock/tienda-online/{$c->id}/categorias?rubro={$rubro->id}")->assertOk()->assertJsonPath('0.id', 'MLA1234');
        $at = $this->getJson("/stock/tienda-online/{$c->id}/atributos/MLA1234")->assertOk()->json();
        $this->assertSame(['BRAND'], array_column($at['requeridos'], 'id')); $this->assertEquals(3500, $at['precio_minimo']); $this->assertCount(4, $at['envio']);
        $this->postJson("/stock/tienda-online/{$c->id}/rubro/{$rubro->id}", ['categoria_id' => 'MLA1234', 'categoria_nombre' => 'Cementos', 'lista' => 3, 'atributos' => ['extra' => ['BRAND' => 'Loma Negra'], 'alto' => 10, 'ancho' => 40, 'largo' => 60, 'peso' => 50000]])->assertOk();
        // Publicar: el que tiene todo sale; el sin foto y el sin precio quedan con su motivo.
        $r = $this->postJson("/stock/tienda-online/{$c->id}/publicar", ['ids' => [$conFoto->id, $sinFoto->id, $sinPrecio3->id]])->assertOk()->json();
        $this->assertSame([$conFoto->id], array_column($r['ok'], 'id')); $this->assertCount(2, $r['errores']);
        $pub = Publicacion::where('product_id', $conFoto->id)->first();
        $this->assertSame('MLA999', $pub->external_id); $this->assertSame(3, $pub->lista); $this->assertSame('publicada', $pub->origen); $this->assertEqualsWithDelta(1500 * 1.21, $pub->precio_remoto, 0.01); $this->assertSame(10, $pub->stock_remoto);
        Http::assertSent(fn($req) => $req->method() === 'POST' && str_ends_with($req->url(), '/items') && $req['category_id'] === 'MLA1234' && $req['available_quantity'] === 10 && collect($req['attributes'])->firstWhere('id', 'BRAND')['value_name'] === 'Loma Negra' && collect($req['attributes'])->firstWhere('id', 'PACKAGE_WEIGHT')['value_name'] === '50000 g');
        // Mantener solo: cambia el precio y baja el stock a cero → se corrige y se pausa; entra mercadería → vuelve.
        $conFoto->update(['prices' => ['2' => 1200, '3' => 2000]]);
        app(\App\Services\Stock\StockService::class)->salida($conFoto, 10, 'venta', null, $this->deposito);
        $svc->sincronizar($c); $pub->refresh(); // (la salida de stock ya la empujó sola; la sincronización no encuentra nada más que corregir)
        $this->assertSame('pausada', $pub->estado); $this->assertSame(0, $pub->stock_remoto); $this->assertEqualsWithDelta(2000 * 1.21, $pub->precio_remoto, 0.01);
        Http::assertSent(fn($req) => $req->method() === 'PUT' && str_ends_with($req->url(), '/items/MLA999') && $req['status'] === 'paused' && $req['available_quantity'] === 0);
        app(\App\Services\Stock\StockService::class)->entrada($conFoto, 7, 'compra', null, $this->deposito, 100);
        $this->assertSame('activa', $pub->fresh()->estado, 'Al entrar mercadería la publicación vuelve sola (empujarStock)');
        $this->assertSame(7, $pub->fresh()->stock_remoto, "12 − 10 + 7 = 9, menos el colchón de 2");
        // Los errores de ML, en castellano y con el paso a seguir.
        $this->assertStringContainsString('no admite Mercado Envíos', $svc->enCastellano('User has not mode me1'));
        // La página.
        $this->get("/stock/tienda-online?canal={$c->id}")->assertOk()->assertInertia(fn($p) => $p->component('Stock/TiendaOnline', false)->where('publicaciones.0.external_id', 'MLA999')->where('rubros.0.canal.categoria_id', 'MLA1234'));
    }

    public function test_publicar_en_woocommerce_actualiza_por_sku_crea_la_categoria_del_rubro_y_respeta_los_campos_elegidos(): void
    {
        $c = Canal::create(['business_id' => $this->empresa->id, 'tipo' => 'woocommerce', 'nombre' => 'Woo', 'credenciales' => ['url' => 'https://mitienda.com', 'consumer_key' => 'k', 'consumer_secret' => 's'], 'activo' => true, 'config' => ['colchon' => 0, 'recargo_pct' => 10, 'campos' => ['precio', 'stock']]]);
        $p = $this->articulo(['name' => 'Cemento', 'sku' => 'CEM50', 'price' => 1000, 'stock_inicial' => 4]);
        Http::fake([
            'mitienda.com/wp-json/wc/v3/products?sku=CEM50' => Http::response([['id' => 77]]),
            'mitienda.com/wp-json/wc/v3/products/categories?*' => Http::response([]),
            'mitienda.com/wp-json/wc/v3/products/categories' => Http::response(['id' => 5, 'name' => 'General']),
            'mitienda.com/wp-json/wc/v3/products/77' => Http::response(['id' => 77, 'permalink' => 'https://mitienda.com/p/cemento']),
        ]);
        $r = $this->postJson("/stock/tienda-online/{$c->id}/publicar", ['ids' => [$p->id]])->assertOk()->json();
        $this->assertCount(1, $r['ok']);
        $pub = Publicacion::first(); $this->assertSame('77', $pub->external_id); $this->assertEqualsWithDelta(1000 * 1.21 * 1.10, $pub->precio_remoto, 0.01, 'Con IVA y el recargo de la tienda');
        // Ya existía con ese SKU: se actualizó (PUT), sólo precio y stock, sin tocar nombre ni descripción.
        Http::assertSent(fn($req) => $req->method() === 'PUT' && str_ends_with($req->url(), '/products/77') && ! isset($req['description']) && $req['stock_quantity'] === 4 && $req['regular_price'] === '1331.00');
        Http::assertNotSent(fn($req) => $req->method() === 'POST' && str_ends_with($req->url(), '/products'));
        $this->assertSame('5', RubroCanal::first()->categoria_id, 'La categoría se creó con el nombre del rubro y quedó guardada');
    }

    public function test_los_ajustes_de_la_tienda_y_la_pagina_sin_tiendas(): void
    {
        $this->get('/stock/tienda-online')->assertOk()->assertInertia(fn($p) => $p->where('canales', []));
        $c = $this->canalMl();
        $cli = $this->cliente(['name' => 'MERCADO LIBRE']);
        $this->post("/configuracion/tienda/canales/{$c->id}/config", ['contact_id' => $cli->id, 'colchon' => 5, 'lista' => 4, 'factura_por' => 'tienda', 'fecha_corte' => '2026-10-01', 'automatico' => true, 'recargo_pct' => 5, 'campos' => ['precio', 'stock', 'foto']])->assertSessionHas('success');
        $c->refresh();
        $this->assertSame($cli->id, $c->cfg('contact_id')); $this->assertSame(5, $c->cfg('colchon')); $this->assertSame('tienda', $c->cfg('factura_por')); $this->assertTrue($c->cfg('automatico')); $this->assertSame(['precio', 'stock', 'foto'], $c->cfg('campos'));
        $this->get('/configuracion/tienda')->assertOk()->assertInertia(fn($p) => $p->where('canales.0.cfg.colchon', 5)->where('canales.0.conectado', true)->has('clientes'));
        // Un vendedor no configura tiendas.
        $this->actingAs($this->usuarioConRol('vendedor'))->post("/configuracion/tienda/canales/{$c->id}/config", ['colchon' => 1])->assertStatus(403);
    }
}
