<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\Catalogo;
use Illuminate\Support\Facades\DB;
use Tests\ErpTestCase;

// Fase 19: buscador global, sugeridos, repetir la última factura y POS liviano.
class VelocidadTest extends ErpTestCase
{
    public function test_buscador_global_encuentra_clientes_articulos_y_comprobantes_por_numero_y_respeta_la_empresa(): void
    {
        $p = $this->articulo(['name' => 'Cemento Loma Negra x 50', 'sku' => 'CEM50', 'barcode' => '7790001', 'stock_inicial' => 5]);
        $cli = $this->cliente(['name' => 'Constructora del Sur', 'cuit' => '30-71234567-1']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $r = $this->getJson('/buscar/global?q=cemento')->assertOk()->json();
        $this->assertSame('Cemento Loma Negra x 50', $r['articulos'][0]['titulo']); $this->assertSame("/stock/{$p->id}", $r['articulos'][0]['url']); $this->assertCount(0, $r['clientes']);
        $r = $this->getJson('/buscar/global?q=del sur')->assertOk()->json();
        $this->assertSame("/clientes/{$cli->id}", $r['clientes'][0]['url']); $this->assertCount(1, $r['comprobantes'], 'Sus comprobantes también aparecen');
        $r = $this->getJson('/buscar/global?q=' . $f->numero)->assertOk()->json();
        $this->assertSame("/comprobantes/{$f->id}", $r['comprobantes'][0]['url']);
        $this->getJson('/buscar/global?q=7790001')->assertOk()->assertJsonPath('articulos.0.id', $p->id);
        // Otra empresa no ve nada de esto.
        [$otra, $sucB, $duenoB] = $this->crearEmpresa('Otra', 'otra');
        $this->actingAs($duenoB)->getJson('/buscar/global?q=cemento')->assertOk()->assertJsonCount(0, 'articulos')->assertJsonCount(0, 'clientes');
    }

    public function test_sin_texto_sugiere_lo_que_el_cliente_compro_y_lo_mas_vendido(): void
    {
        $a = $this->articulo(['name' => 'Arena', 'stock_inicial' => 100]); $b = $this->articulo(['name' => 'Hierro', 'stock_inicial' => 100]); $c = $this->articulo(['name' => 'Cal', 'stock_inicial' => 100]);
        $cli = $this->cliente(); $otro = $this->cliente(['name' => 'Otro', 'cuit' => '20-12345678-6']);
        $this->factura($otro, [['product_id' => $b->id, 'cantidad' => 50, 'precio_unit' => 10]]);   // el más vendido
        $this->factura($cli, [['product_id' => $c->id, 'cantidad' => 1, 'precio_unit' => 10]]);     // lo que compró este cliente
        $s = Catalogo::buscar('articulos', 'venta', '', [], $cli->id);
        $this->assertSame([$c->id, $b->id], $s->pluck('id')->take(2)->all(), 'Primero lo del cliente, después lo más vendido');
        $this->assertSame('cliente', $s[0]['sugerido']); $this->assertSame('top', $s[1]['sugerido']);
        $this->getJson("/buscar/articulos/venta?q=&contact_id={$cli->id}")->assertOk()->assertJsonPath('0.id', $c->id);
        // Clientes recientes primero.
        $r = $this->getJson('/buscar/contactos/cliente?q=')->assertOk()->json();
        $this->assertSame($cli->id, $r[0]['id']); $this->assertSame('reciente', $r[0]['sugerido']);
    }

    public function test_repetir_la_ultima_factura_del_cliente(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente();
        $this->getJson("/comprobantes/ultima-de/{$cli->id}")->assertOk()->assertJsonPath('comprobante', null);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 3, 'precio_unit' => 200, 'descuento' => 5]]);
        $r = $this->getJson("/comprobantes/ultima-de/{$cli->id}")->assertOk()->json();
        $this->assertSame($f->numeroFormateado(), $r['comprobante']['numero']); $this->assertSame($p->id, $r['items'][0]['product_id']); $this->assertEquals(3, $r['items'][0]['cantidad']); $this->assertEquals(5, $r['items'][0]['descuento']);
    }

    public function test_el_pos_manda_un_catalogo_chico_cuando_hay_muchos_articulos(): void
    {
        $ahora = now()->toDateTimeString(); $rows = [];
        for ($i = 0; $i < 700; $i++) $rows[] = ['business_id' => $this->empresa->id, 'name' => "Art {$i}", 'sku' => "P{$i}", 'tipo' => 'producto', 'unit' => 'un', 'cost' => 1, 'price' => 2, 'iva' => 21, 'stock' => 1, 'stock_min' => 0, 'active' => true, 'controla_stock' => true, 'moneda' => 'ARS', 'favorito_pos' => $i < 3, 'created_at' => $ahora, 'updated_at' => $ahora];
        foreach (array_chunk($rows, 500) as $ch) DB::table('products')->insert($ch);
        $vendido = Product::where('sku', 'P650')->first(); $vendido->update(['stock' => 100]);
        $this->factura(\App\Models\Contact::where('name', 'Consumidor Final')->first(), [['product_id' => $vendido->id, 'cantidad' => 2, 'precio_unit' => 2]]);
        $this->empresa->update(['verticales_extra' => ['retail']]);
        $this->get('/retail')->assertOk()->assertInertia(fn($p) => $p->component('Pos/Index', false)->where('catalogoParcial', true)->where('productos', fn($lista) => count($lista) <= 400 && collect($lista)->contains('sku', 'P650') && collect($lista)->first()['favorito'] === true));
    }
}
