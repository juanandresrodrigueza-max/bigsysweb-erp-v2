<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Comanda;
use App\Models\Comprobante;
use App\Services\Gastronomia\RecetaVentaService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 53.1 · Big Restó para una cafetería: la venta descuenta la receta, el costo y margen por plato con aviso cuando sube un insumo,
// la receta sugerida por IA y el llamador de pedidos de mostrador.
class BigRestoCafeteriaFase53Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->forceFill(['vertical' => 'gastronomia'])->save();
    }

    private function cafeteria(): array
    {
        $cafe = $this->articulo(['name' => 'Café en grano', 'tipo' => 'insumo', 'unit' => 'kg', 'cost' => 20000, 'price' => 0, 'stock_inicial' => 2]);
        $leche = $this->articulo(['name' => 'Leche', 'tipo' => 'insumo', 'unit' => 'l', 'cost' => 1500, 'price' => 0, 'stock_inicial' => 10]);
        $plato = $this->articulo(['name' => 'Café con leche', 'price' => 3000, 'iva' => 21, 'controla_stock' => false]);
        $this->post("/gastronomia/carta/{$plato->id}/receta", ['items' => [['product_id' => $cafe->id, 'quantity' => 0.018], ['product_id' => $leche->id, 'quantity' => 0.2]]])->assertSessionHas('success');
        return [$cafe, $leche, $plato->fresh()];
    }

    public function test_la_venta_descuenta_la_receta_y_la_nota_de_credito_la_devuelve(): void
    {
        [$cafe, $leche, $plato] = $this->cafeteria();
        $this->assertEqualsWithDelta(0.018 * 20000 + 0.2 * 1500, RecetaVentaService::costo($plato), 0.01);
        $this->assertEqualsWithDelta(round((3000 - 660) / 3000 * 100, 1), RecetaVentaService::margen($plato), 0.1);

        $f = $this->factura($this->cliente(), [['product_id' => $plato->id, 'descripcion' => 'Café con leche', 'cantidad' => 5, 'precio_unit' => 3000, 'alicuota_iva' => 21]]);
        $this->assertEqualsWithDelta(2 - 0.09, (float) $cafe->fresh()->stock, 0.0001, '5 cafés × 18 g');
        $this->assertEqualsWithDelta(10 - 1, (float) $leche->fresh()->stock, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $plato->fresh()->stock, 0.001, 'El plato no controla stock propio');

        $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
        $nc = $svc->emitir($svc->guardarBorrador(['contact_id' => $f->contact_id, 'tipo' => 'NCX', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'origen_id' => $f->id, 'items' => [['product_id' => $plato->id, 'descripcion' => 'Devolución', 'cantidad' => 2, 'precio_unit' => 3000, 'alicuota_iva' => 21]]]));
        $this->assertEqualsWithDelta(2 - 0.054, (float) $cafe->fresh()->stock, 0.0001, 'La NC devuelve 2 cafés');

        // Apagado el descuento por receta, la venta no toca los insumos
        $this->post('/gastronomia/estaciones', ['receta_al_vender' => false, 'estaciones' => [['nombre' => 'Barra', 'alerta' => 5]]]);
        $this->factura($this->cliente(), [['product_id' => $plato->id, 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 3000, 'alicuota_iva' => 21]]);
        $this->assertEqualsWithDelta(2 - 0.054, (float) $cafe->fresh()->stock, 0.0001);
        $this->assertAsientosBalancean();
    }

    public function test_si_sube_el_insumo_avisa_el_margen_del_plato_con_precio_sugerido(): void
    {
        [$cafe, , $plato] = $this->cafeteria();
        $this->post("/gastronomia/carta/{$plato->id}", ['margen_objetivo' => 80, 'modificadores' => []])->assertSessionHas('success');
        $prov = $this->proveedor();
        // El café pasa de $20.000 a $40.000 el kilo: el margen del café con leche cae de 78 % a 66 %
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000009', 'fecha' => today()->toDateString(), 'registrar' => true,
            'items' => [['product_id' => $cafe->id, 'descripcion' => 'Café', 'cantidad' => 1, 'precio_unit' => 40000, 'alicuota_iva' => 21, 'actualiza_costo' => true]]])->assertRedirect();
        $this->assertEqualsWithDelta(40000, (float) $cafe->fresh()->cost, 0.01);
        $a = Alerta::where('tipo', 'margen_plato')->whereNull('resuelta_en')->first();
        $this->assertNotNull($a, 'Avisa que hay que revisar el precio');
        $this->assertStringContainsString('Café con leche', $a->titulo);
        $this->assertStringContainsString('subió 100 %', $a->detalle);
        $this->assertStringContainsString('precio sugerido', $a->detalle);
        $costo = RecetaVentaService::costo($plato->fresh());
        $this->assertEqualsWithDelta(0.018 * 40000 + 0.2 * 1500, $costo, 0.01);
        $this->assertStringContainsString(number_format(RecetaVentaService::precioPara($costo, 80), 2, ',', '.'), $a->detalle);
        $carta = collect($this->get('/gastronomia/carta')->viewData('page')['props']['productos'])->firstWhere('id', $plato->id);
        $this->assertEqualsWithDelta($costo, $carta['costo'], 0.01); $this->assertSame(80.0, $carta['margen_objetivo']);
    }

    public function test_la_ia_sugiere_la_receta_solo_con_insumos_cargados(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        $harina = $this->articulo(['name' => 'Harina 000', 'tipo' => 'insumo', 'unit' => 'kg', 'cost' => 900]);
        $plato = $this->articulo(['name' => 'Medialunas x3', 'price' => 2500]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['items' => [['id' => $harina->id, 'cantidad' => 0.12], ['id' => 999999, 'cantidad' => 1]], 'nota' => 'Falta cargar manteca como insumo.'])]]])]);
        $r = $this->postJson("/gastronomia/carta/{$plato->id}/sugerir-receta")->assertOk()->json();
        $this->assertTrue($r['ok']); $this->assertCount(1, $r['items'], 'Un id que no existe se descarta');
        $this->assertSame($harina->id, $r['items'][0]['product_id']); $this->assertEqualsWithDelta(108, $r['costo'], 0.01);
        $this->assertStringContainsString('manteca', $r['nota']);
    }

    public function test_llamador_avisa_por_whatsapp_cuando_el_pedido_de_mostrador_queda_listo(): void
    {
        $this->empresa->forceFill(['whatsapp_settings' => ['token' => 'tok', 'phone_id' => '123']])->save();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $tostado = $this->articulo(['name' => 'Tostado', 'price' => 4000]); $tostado->forceFill(['va_cocina' => true])->save();
        $this->post('/gastronomia/comandas', ['tipo' => 'mostrador', 'cliente' => 'Lucía', 'telefono' => '3515551234'])->assertSessionHasNoErrors();
        $c = Comanda::latest('id')->first();
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $tostado->id, 'cantidad' => 2]);
        $this->post("/gastronomia/comandas/{$c->id}/enviar");
        $this->assertFalse($this->get('/gastronomia/llamador')->assertOk()->viewData('page')['props']['pedidos'][0]['listo']);

        $it = $c->items()->first();
        $this->post("/gastronomia/items/{$it->id}/estado", ['estado' => 'listo']);
        $c->refresh(); $this->assertNotNull($c->aviso_listo_en);
        Http::assertSent(fn($r) => str_contains($r->url(), 'graph.facebook.com') && str_contains($r->body(), 'listo para retirar'));
        $p = $this->get('/gastronomia/llamador')->viewData('page')['props']['pedidos'][0];
        $this->assertTrue($p['listo']); $this->assertTrue($p['avisado']); $this->assertSame('Lucía', $p['cliente']);
        $this->post("/gastronomia/comandas/{$c->id}/retirado");
        $this->assertSame('entregado', $it->fresh()->estado);
    }
}
