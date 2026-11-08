<?php

namespace Tests\Feature;

use App\Models\PrecioPactado;
use Tests\ErpTestCase;

// Fase 70 (lado ERP): stock y precio del cliente en vivo para presupuestar desde el CRM.
class CrmStockPrecioFase70Test extends ErpTestCase
{
    public function test_el_crm_ve_el_stock_y_el_precio_que_el_erp_le_cobraria_a_ese_cliente(): void
    {
        $cem = $this->articulo(['name' => 'Cemento x 50 kg', 'price' => 10000, 'iva' => 21, 'stock_inicial' => 40, 'stock_min' => 50]);
        $cem->update(['prices' => ['2' => 9500], 'desc_cant_min' => 10, 'desc_cant_pct' => 5]);
        $hierro = $this->articulo(['name' => 'Hierro 8 mm', 'price' => 12000, 'stock_inicial' => 0]);
        $serv = $this->articulo(['name' => 'Flete', 'price' => 5000, 'tipo' => 'servicio', 'controla_stock' => false]);
        $cli = $this->cliente(['name' => 'López SRL', 'lista_precios' => 2, 'descuento' => 3]);
        PrecioPactado::create(['business_id' => $this->empresa->id, 'contact_id' => $cli->id, 'product_id' => $hierro->id, 'precio' => 11000, 'origen' => 'pactado', 'user_id' => $this->dueno->id]);
        $token = $this->dueno->createToken('api')->plainTextToken; auth()->logout();

        // Sin cliente: precio de lista 1, stock total y por depósito.
        $r = $this->withToken($token)->getJson('/api/products/para-presupuestar?q=cemento')->assertOk()->json();
        $this->assertNull($r['cliente']); $c = $r['data'][0];
        $this->assertSame('Cemento x 50 kg', $c['nombre']); $this->assertEquals(10000, $c['precio']['final']); $this->assertEquals(12100, $c['precio']['final_con_iva']); $this->assertSame('lista', $c['precio']['origen']);
        $this->assertEquals(40, $c['stock']['total']); $this->assertTrue($c['stock']['hay']); $this->assertTrue($c['stock']['poco'], 'Está bajo el mínimo'); $this->assertEquals(40, $c['stock']['por_deposito'][0]['cantidad']);

        // Con el cliente: su lista 2 y su 3 %, el descuento por cantidad cuando conviene, el precio pactado para el hierro, y si alcanza el stock.
        $r = $this->withToken($token)->getJson("/api/products/para-presupuestar?ids={$cem->id},{$hierro->id},{$serv->id}&contact_id={$cli->id}&cantidad=10")->assertOk()->json();
        $this->assertSame(2, $r['cliente']['lista']); $por = collect($r['data'])->keyBy('id');
        $this->assertEquals(9500, $por[$cem->id]['precio']['precio_lista']); $this->assertEquals(3, $por[$cem->id]['precio']['descuento']); $this->assertEquals(9215, $por[$cem->id]['precio']['final']); $this->assertSame('lista', $por[$cem->id]['precio']['origen']);
        $this->assertTrue($por[$cem->id]['stock']['alcanza']); $this->assertEquals(['desde' => 10, 'pct' => 5], $por[$cem->id]['descuento_por_cantidad']);
        $this->assertEquals(11000, $por[$hierro->id]['precio']['final']); $this->assertSame('pactado', $por[$hierro->id]['precio']['origen']); $this->assertFalse($por[$hierro->id]['stock']['hay']); $this->assertFalse($por[$hierro->id]['stock']['alcanza']);
        $this->assertFalse($por[$serv->id]['stock']['controla']); $this->assertTrue($por[$serv->id]['stock']['hay']); $this->assertTrue($por[$serv->id]['stock']['alcanza']);

        // Cliente sin descuento general: con 10 unidades entra el descuento por cantidad.
        $otro = $this->cliente(['name' => 'Otro']);
        $u = $this->withToken($token)->getJson("/api/products/{$cem->id}/para-presupuestar?contact_id={$otro->id}&cantidad=10")->assertOk()->json('data');
        $this->assertEquals(5, $u['precio']['descuento']); $this->assertSame('cantidad', $u['precio']['origen']); $this->assertEquals(9500, $u['precio']['final']);

        // Sólo con stock, y lo que no corresponde.
        $r = $this->withToken($token)->getJson('/api/products/para-presupuestar?q=o&solo_con_stock=1')->assertOk()->json('data');
        $this->assertNotContains('Hierro 8 mm', array_column($r, 'nombre'));
        $this->withToken($token)->getJson('/api/products/para-presupuestar')->assertStatus(422);
        [$b2] = $this->crearEmpresa('Otra', 'otra');
        $ajeno = \App\Models\Product::withoutGlobalScopes()->where('business_id', $b2->id)->value('id') ?? 999999;
        $this->withToken($token)->getJson("/api/products/{$ajeno}/para-presupuestar")->assertStatus(404);
    }
}
