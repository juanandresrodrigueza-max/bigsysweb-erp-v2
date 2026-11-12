<?php

namespace Tests\Feature;

use App\Models\Comanda;
use App\Models\Merma;
use App\Models\Mesa;
use App\Models\Role;
use App\Models\StockMovement;
use Tests\ErpTestCase;

// Fase 89: plano del salón, pedir por asiento, cortesías, arqueo ciego y mermas con motivo.
class SalonFase89Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->forceFill(['vertical' => 'gastronomia'])->save();
    }

    private function mesa(string $nombre = '4', int $cubiertos = 3): array
    {
        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => $nombre, 'sector' => 'Salón', 'capacidad' => 4, 'activa' => true]);
        $this->post('/gastronomia/comandas', ['mesa_id' => $m->id, 'cubiertos' => $cubiertos])->assertSessionHasNoErrors();
        return [$m, Comanda::latest('id')->first()];
    }

    public function test_el_plano_guarda_la_posicion_y_la_forma_de_cada_mesa(): void
    {
        [$m] = $this->mesa('1'); $m2 = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '2', 'sector' => 'Terraza', 'capacidad' => 2, 'activa' => true]);
        $this->post('/gastronomia/mesas/plano', ['mesas' => [['id' => $m->id, 'pos_x' => 12.5, 'pos_y' => 40], ['id' => $m2->id, 'pos_x' => 80, 'pos_y' => 60.4]]])->assertSessionHas('success');
        $this->assertSame(12.5, (float) $m->fresh()->pos_x); $this->assertSame(60.4, (float) $m2->fresh()->pos_y);
        $this->post("/gastronomia/mesas/{$m2->id}", ['nombre' => '2', 'sector' => 'Terraza', 'capacidad' => 2, 'forma' => 'redonda'])->assertSessionHas('success');
        $mesas = collect($this->get('/gastronomia')->assertOk()->viewData('page')['props']['mesas'])->keyBy('id');
        $this->assertSame('redonda', $mesas[$m2->id]['forma']); $this->assertSame(12.5, $mesas[$m->id]['pos_x']);
        $this->post('/gastronomia/mesas/plano', ['mesas' => [['id' => $m->id, 'pos_x' => 120, 'pos_y' => 0]]])->assertSessionHasErrors();
    }

    public function test_cada_item_puede_ir_a_un_asiento_y_el_cobro_lo_separa(): void
    {
        [, $c] = $this->mesa();
        $cafe = $this->articulo(['name' => 'Café', 'price' => 1000, 'iva' => 0]); $torta = $this->articulo(['name' => 'Torta', 'price' => 3000, 'iva' => 0]);
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $cafe->id, 'asiento' => 1])->assertSessionHasNoErrors();
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $torta->id, 'asiento' => 2])->assertSessionHasNoErrors();
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $cafe->id])->assertSessionHasNoErrors();
        $items = collect($this->get("/gastronomia/comandas/{$c->id}")->assertOk()->viewData('page')['props']['comanda']['items']);
        $this->assertSame([1, 2, null], $items->pluck('asiento')->all());
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $cafe->id, 'asiento' => 99])->assertSessionHasErrors('asiento');
        // Cobrar el asiento 2 solo: queda pendiente lo demás.
        $torta2 = $items->firstWhere('asiento', 2);
        $this->post("/gastronomia/comandas/{$c->id}/cerrar", ['medios' => [['medio' => 'efectivo', 'monto' => 3000]], 'partes' => [$torta2['id'] => 1]])->assertSessionHasNoErrors();
        $c->refresh(); $this->assertSame('abierta', $c->estado); $this->assertEqualsWithDelta(2000, (float) $this->get("/gastronomia/comandas/{$c->id}")->viewData('page')['props']['comanda']['pendiente'], 0.01);
    }

    public function test_la_cortesia_deja_el_plato_a_cero_y_registra_la_merma_con_su_costo(): void
    {
        [, $c] = $this->mesa();
        $bife = $this->articulo(['name' => 'Bife', 'price' => 10000, 'cost' => 4000, 'iva' => 0, 'controla_stock' => true, 'stock' => 10]);
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $bife->id, 'asiento' => 1])->assertSessionHasNoErrors();
        $it = $c->items()->first();
        $this->assertEqualsWithDelta(10000, (float) $c->fresh()->total, 0.01);
        $this->post("/gastronomia/comandas/{$c->id}/items/{$it->id}/cortesia", ['motivo' => 'cortesia', 'notas' => 'Cumpleaños'])->assertSessionHas('success');
        $it->refresh(); $this->assertTrue($it->cortesia); $this->assertSame(0.0, (float) $it->precio_unit); $this->assertStringContainsString('Cortesía', $it->notas);
        $this->assertEqualsWithDelta(0, (float) $c->fresh()->total, 0.01);
        $m = Merma::first();
        $this->assertSame('cortesia', $m->motivo); $this->assertSame($it->id, $m->comanda_item_id); $this->assertEqualsWithDelta(4000, $m->importe(), 0.01);
        $this->assertSame(10.0, (float) $bife->fresh()->stock, 'La cortesía no baja el stock dos veces: lo baja la venta a $ 0 al cobrar');
        $this->post("/gastronomia/comandas/{$c->id}/items/{$it->id}/cortesia", ['motivo' => 'cortesia'])->assertStatus(422);
        // Un cajero (sin gastronomia.editar) no puede regalar platos.
        $this->actingAs($this->usuarioConRol('cajero', 'caj@test.com'))->post("/gastronomia/comandas/{$c->id}/items/{$it->id}/cortesia", ['motivo' => 'cortesia'])->assertStatus(403);
    }

    public function test_la_merma_baja_el_stock_con_motivo_y_el_reporte_la_junta(): void
    {
        $tomate = $this->articulo(['name' => 'Tomate', 'cost' => 500, 'controla_stock' => true, 'stock' => 20, 'unit' => 'kg']);
        $this->post('/stock/mermas', ['product_id' => $tomate->id, 'cantidad' => 2, 'motivo' => 'tirado', 'notas' => 'Se pasó'])->assertSessionHas('success');
        $this->post('/stock/mermas', ['product_id' => $tomate->id, 'cantidad' => 1, 'motivo' => 'vencido'])->assertSessionHas('success');
        $this->post('/stock/mermas', ['product_id' => $tomate->id, 'cantidad' => 1, 'motivo' => 'inventado'])->assertSessionHasErrors('motivo');
        $this->assertSame(17.0, (float) $tomate->fresh()->stock);
        $this->assertSame(2, StockMovement::where('product_id', $tomate->id)->where('type', 'out')->where('reason', 'like', 'Merma:%')->count());
        $p = $this->get('/stock/mermas')->assertOk()->viewData('page')['props'];
        $this->assertSame(2, $p['resumen']['veces']); $this->assertEqualsWithDelta(1500, $p['resumen']['total'], 0.01);
        $this->assertSame('Tomate', $p['resumen']['por_articulo'][0]['nombre']);
        $this->assertEqualsCanonicalizing(['tirado', 'vencido'], array_column($p['resumen']['por_motivo'], 'motivo'));
        $this->assertSame(['vencido'], array_column($this->get('/stock/mermas?motivo=vencido')->viewData('page')['props']['lista']['data'], 'motivo_key'));
        $this->assertContains('mermas', array_column($p['subnav']['items'], 'key'), 'Mermas es una sección de Stock');
        // Bigcito la tiene como herramienta.
        $this->assertStringContainsString("'registrar_merma'", file_get_contents(app_path('Mcp/Modulos/Stock.php')));
    }

    public function test_con_arqueo_ciego_el_cajero_no_ve_lo_esperado_y_el_encargado_si(): void
    {
        $this->post('/fondos/config', ['arqueo_ciego' => true])->assertSessionHas('success');
        $this->assertTrue($this->empresa->fresh()->caja_config['arqueo_ciego']);
        $this->assertSame([], $this->get('/fondos/cierres')->assertOk()->viewData('page')['props']['cajaConfig'] ? [] : ['x'], 'La pantalla de cierres recibe la configuración');
        $cajero = $this->usuarioConRol('cajero', 'c@test.com');
        $this->actingAs($cajero)->post("/fondos/cuentas/{$this->caja->id}/abrir-turno", ['saldo_inicial' => 1000])->assertSessionHasNoErrors();
        $turno = fn($u) => collect($this->actingAs($u)->get('/fondos')->assertOk()->viewData('page')['props']['cuentas'])->firstWhere('id', $this->caja->id)['turno'];
        $t = $turno($cajero);
        $this->assertTrue($t['ciego']); $this->assertArrayHasKey('efectivo', $t['esperado']); $this->assertNull($t['esperado']['efectivo'], 'El cajero no ve lo esperado');
        $t = $turno($this->dueno);
        $this->assertFalse($t['ciego']); $this->assertEqualsWithDelta(1000, (float) $t['esperado']['efectivo'], 0.01, 'El encargado lo ve igual');
        // El cajero no puede cambiar la preferencia.
        $this->actingAs($cajero)->post('/fondos/config', ['arqueo_ciego' => false])->assertStatus(403);
        $this->assertStringContainsString('cortesías', file_get_contents(base_path('database/migrations/2027_01_12_000001_fase89_novedad_salon.php')));
    }
}
