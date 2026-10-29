<?php

namespace Tests\Feature;

use App\Models\CuponTarjeta;
use App\Models\SistemaConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 32.2 y 32.3: pantalla del cliente con cartelera; Mercado Pago en el local con un botón (OAuth), caja QR, terminal Point en modo PDV,
// y la factura al fondo de débito o crédito que informa la Point.
class CajaIntegradaTest extends ErpTestCase
{
    public function test_conectar_mercado_pago_con_un_boton_elige_la_caja_sola_y_renueva_el_token(): void
    {
        $this->get('/conectar/mercadopago')->assertStatus(422); // sin la app configurada en el panel
        SistemaConfig::set('mp_app_id', 'APP123'); SistemaConfig::set('mp_app_secret', 'secreto');
        $r = $this->get('/conectar/mercadopago')->assertRedirect();
        $url = $r->headers->get('Location');
        $this->assertStringStartsWith('https://auth.mercadopago.com.ar/authorization?', $url); $this->assertStringContainsString('client_id=APP123', $url); $this->assertStringContainsString('platform_id=mp', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        Http::fake([
            'api.mercadopago.com/oauth/token' => Http::response(['access_token' => 'APP_USR-nuevo', 'refresh_token' => 'TG-ref', 'expires_in' => 15552000, 'user_id' => 987654]),
            'api.mercadopago.com/users/me' => Http::response(['nickname' => 'CORRALONDEMO']),
            'api.mercadopago.com/pos*' => Http::response(['results' => [['id' => 1, 'external_id' => 'CAJA01', 'name' => 'Caja mostrador', 'store_id' => 'S1']]]),
        ]);
        $this->get("/conectar/mercadopago/volver?code=ABC&state={$q['state']}")->assertRedirect('/configuracion#mercadopago')->assertSessionHas('success');
        $s = $this->empresa->fresh()->mercadopago_settings;
        $this->assertSame('APP_USR-nuevo', $s['access_token']); $this->assertTrue($s['oauth']); $this->assertSame('987654', $s['user_id']); $this->assertSame('CORRALONDEMO', $s['nickname']); $this->assertSame('CAJA01', $s['pos_external_id']);
        $this->actingAs($this->dueno->fresh())->get('/configuracion')->assertInertia(fn($p) => $p->where('mercadopago.oauth', true)->where('mercadopago.nickname', 'CORRALONDEMO')->where('mercadopago.boton', true));
        // vencido: la próxima llamada renueva el token sola
        $this->empresa->update(['mercadopago_settings' => array_merge($s, ['expires_at' => now()->subDay()->toIso8601String()])]);
        Http::swap(new \Illuminate\Http\Client\Factory(app('events'))); // los stubs de arriba no mandan más
        Http::fake([
            'api.mercadopago.com/oauth/token' => Http::response(['access_token' => 'APP_USR-renovado', 'refresh_token' => 'TG-ref2', 'expires_in' => 15552000]),
            'api.mercadopago.com/point/integration-api/devices' => Http::response(['devices' => [['id' => 'PAX_A910__SMARTPOS1', 'operating_mode' => 'STANDALONE', 'pos_id' => 1, 'store_id' => 'S1']]]),
        ]);
        $this->getJson('/configuracion/empresa/mercadopago/terminales')->assertOk()->assertJsonPath('0.id', 'PAX_A910__SMARTPOS1')->assertJsonPath('0.modo', 'STANDALONE');
        $this->assertSame('APP_USR-renovado', $this->empresa->fresh()->mercadopago_settings['access_token']);
        Http::assertSent(fn($req) => str_contains($req->url(), 'oauth/token') && ($req['grant_type'] ?? '') === 'refresh_token');
        // desconectar deja la empresa sin token
        $this->post('/configuracion/empresa/mercadopago/desconectar')->assertSessionHas('success');
        $this->assertArrayNotHasKey('access_token', $this->empresa->fresh()->mercadopago_settings);
    }

    public function test_la_terminal_point_queda_en_modo_pdv_y_la_factura_sale_al_fondo_de_la_tarjeta_que_informa(): void
    {
        $this->empresa->update(['mercadopago_settings' => ['access_token' => 'APP_USR-x', 'user_id' => '1', 'oauth' => false]]);
        Http::fake(['api.mercadopago.com/point/integration-api/devices/PAX1' => Http::response(['id' => 'PAX1', 'operating_mode' => 'PDV'])]);
        $this->post('/configuracion/empresa/mercadopago/terminal', ['device_id' => 'PAX1'])->assertSessionHas('success');
        $s = $this->empresa->fresh()->mercadopago_settings; $this->assertSame('PAX1', $s['point_device_id']); $this->assertSame('PDV', $s['point_modo']);
        Http::assertSent(fn($req) => $req->method() === 'PATCH' && ($req['operating_mode'] ?? '') === 'PDV');
        // el cobro con la Point: al aprobarse, se sabe que fue crédito en 3 cuotas
        Http::fake([
            'api.mercadopago.com/point/integration-api/payment-intents/INT1' => Http::response(['id' => 'INT1', 'state' => 'FINISHED', 'amount' => 363000, 'payment' => ['id' => 555]]),
            'api.mercadopago.com/v1/payments/555' => Http::response(['id' => 555, 'payment_type_id' => 'credit_card', 'payment_method_id' => 'visa', 'installments' => 3]),
        ]);
        $this->getJson('/retail/mp/estado?tipo=point&id=INT1')->assertOk()->assertJsonPath('estado', 'pagado')->assertJsonPath('tarjeta', 'Crédito')->assertJsonPath('cuotas', 3)->assertJsonPath('es_tarjeta', true)->assertJsonPath('monto', 3630);
        // y la venta entra como tarjeta al fondo de crédito, con el número de pago y las cuotas
        $credito = \App\Models\CuentaFondos::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'tipo' => 'tarjeta', 'nombre' => 'Tarjetas crédito', 'activa' => true, 'saldo' => 0]);
        $p = $this->articulo(['price' => 3630, 'stock_inicial' => 5]);
        $r = $this->postJson('/retail/vender', ['items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 3630, 'descuento' => 0, 'alicuota_iva' => 21]], 'precios_con_iva' => true,
            'medios' => [['medio' => 'tarjeta', 'monto' => 3630, 'referencia' => '555', 'cuenta_fondos_id' => $credito->id, 'datos' => ['tarjeta' => 'Crédito visa', 'cuotas' => 3]]]])->assertOk()->json();
        $cupon = CuponTarjeta::first();
        $this->assertNotNull($cupon); $this->assertSame(3, (int) $cupon->cuotas); $this->assertStringContainsString('Crédito', (string) $cupon->tarjeta);
        $this->assertDatabaseHas('cobro_medios', ['medio' => 'tarjeta', 'referencia' => '555', 'cuenta_fondos_id' => $credito->id]);
    }

    public function test_la_cartelera_se_sube_se_ve_en_la_pantalla_del_cliente_y_se_borra(): void
    {
        $this->post('/configuracion/empresa/cartelera', ['archivos' => [UploadedFile::fake()->image('promo1.jpg', 1080, 1600), UploadedFile::fake()->image('promo2.png', 1080, 1600)]])->assertSessionHas('success');
        $lista = $this->empresa->fresh()->pos['cartelera'];
        $this->assertCount(2, $lista); $this->assertSame('imagen', $lista[0]['tipo']);
        $this->get("/configuracion/empresa/cartelera/{$lista[0]['id']}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->post('/configuracion/empresa/pos', ['balanza_prefijo' => '2', 'balanza_modo' => 'peso', 'balanza_decimales' => 3, 'balanza_digitos_plu' => 5, 'imprimir_auto' => false, 'impresora' => 'navegador', 'ancho' => 42, 'cartelera_segundos' => 12])->assertSessionHasNoErrors();
        $this->get('/retail/pantalla-cliente')->assertOk()->assertInertia(fn($p) => $p->component('Comprobantes/PantallaCliente', false)->has('cartelera', 2)->where('segundos', 12)->where('vertical', 'retail')->where('cartelera.0.url', "/retail/cartelera/{$lista[0]['id']}"));
        $this->get("/retail/cartelera/{$lista[1]['id']}")->assertOk();
        $this->delete("/configuracion/empresa/cartelera/{$lista[0]['id']}")->assertSessionHas('success');
        $this->assertCount(1, $this->empresa->fresh()->pos['cartelera']);
        $this->get("/configuracion/empresa/cartelera/{$lista[0]['id']}")->assertNotFound();
        // un archivo que no es imagen ni video no entra
        $this->post('/configuracion/empresa/cartelera', ['archivos' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])->assertSessionHasErrors();
        // otra empresa no ve la cartelera de ésta
        [$otra, $sucB, $duenoB] = $this->crearEmpresa('Otra', 'otra');
        $this->actingAs($duenoB)->get("/configuracion/empresa/cartelera/{$lista[1]['id']}")->assertNotFound();
    }
}
