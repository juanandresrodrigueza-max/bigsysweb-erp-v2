<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Deposito;
use App\Models\TransferenciaStock;
use Tests\ErpTestCase;

// Fase 42.1, 42.2 y 42.8: la sucursal pide al depósito y del pedido se arma el pase; cambios o devoluciones a proveedor;
// aviso de los pases en viaje que nadie confirmó; filtros de la lista.
class PasesPedidosProveedorTest extends ErpTestCase
{
    private function norte(): Deposito { return Deposito::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => 'Sucursal Norte', 'activo' => true]); }

    public function test_pedido_al_deposito_y_pase_armado_desde_el_pedido(): void
    {
        $this->actingAs($this->dueno);
        $norte = $this->norte(); $p = $this->articulo(['stock_inicial' => 20]);
        $this->post('/stock/pases/pedir', ['origen_id' => $this->deposito->id, 'destino_id' => $norte->id, 'items' => [['product_id' => $p->id, 'cantidad' => 8]]])->assertSessionHasNoErrors();
        $ped = TransferenciaStock::where('tipo', 'pedido')->firstOrFail();
        $this->assertSame('pedido', $ped->estado);
        $this->assertStringStartsWith('OP ', $ped->numeroFormateado());
        $this->assertEquals(20, $p->fresh()->stockEn($this->deposito->id)); // pedir no mueve stock
        $this->get("/stock/pases?pedido={$ped->id}")->assertInertia(fn($pg) => $pg->where('pedido.id', $ped->id)->where('pedido.items.0.enviado', 8));
        // El depósito manda 6 de los 8 pedidos.
        $this->post('/stock/pases', ['origen_id' => $this->deposito->id, 'destino_id' => $norte->id, 'pedido_id' => $ped->id, 'items' => [['product_id' => $p->id, 'cantidad' => 6]]])->assertSessionHasNoErrors();
        $this->assertSame('armado', $ped->fresh()->estado);
        $pase = TransferenciaStock::where('tipo', 'pase')->firstOrFail();
        $this->assertSame($ped->id, $pase->pedido_id);
        $this->assertEquals(8, (float) $pase->items()->first()->cantidad_pedida);
        $this->assertEquals(14, $p->fresh()->stockEn($this->deposito->id));
        // Un pedido ya armado no se vuelve a armar.
        $this->post('/stock/pases', ['origen_id' => $this->deposito->id, 'destino_id' => $norte->id, 'pedido_id' => $ped->id, 'items' => [['product_id' => $p->id, 'cantidad' => 1]]])->assertStatus(422);
        // Filtros de la lista.
        $this->get('/stock/pases?tipo=pedido')->assertInertia(fn($pg) => $pg->count('pases', 1)->where('pases.0.tipo', 'pedido'));
        $this->get('/stock/pases?q=' . urlencode($p->name))->assertInertia(fn($pg) => $pg->count('pases', 2));
    }

    public function test_cambio_a_proveedor_sale_y_vuelve(): void
    {
        $this->actingAs($this->dueno);
        $prov = $this->proveedor(); $p = $this->articulo(['stock_inicial' => 10]);
        $this->post('/stock/pases/proveedor', ['origen_id' => $this->deposito->id, 'contact_id' => $prov->id, 'notas' => 'Vino fallado', 'items' => [['product_id' => $p->id, 'cantidad' => 3]]])->assertSessionHasNoErrors();
        $t = TransferenciaStock::where('tipo', 'proveedor')->firstOrFail();
        $this->assertSame('en_proveedor', $t->estado);
        $this->assertEquals(7, $p->fresh()->stockEn($this->deposito->id));
        $this->post("/stock/pases/{$t->id}/volvio", ['conteos' => [$t->items()->first()->id => 3]])->assertSessionHasNoErrors();
        $this->assertSame('vuelto', $t->fresh()->estado);
        $this->assertEquals(10, $p->fresh()->stockEn($this->deposito->id));
        // Otro que no vuelve: se cierra sin tocar el stock.
        $this->post('/stock/pases/proveedor', ['origen_id' => $this->deposito->id, 'contact_id' => $prov->id, 'items' => [['product_id' => $p->id, 'cantidad' => 2]]]);
        $t2 = TransferenciaStock::where('tipo', 'proveedor')->latest('id')->first();
        $this->post("/stock/pases/{$t2->id}/volvio", ['cerrar' => true])->assertSessionHasNoErrors();
        $this->assertSame('cerrado', $t2->fresh()->estado);
        $this->assertEquals(8, $p->fresh()->stockEn($this->deposito->id));
    }

    public function test_aviso_de_pases_en_viaje_sin_confirmar(): void
    {
        $this->actingAs($this->dueno);
        $norte = $this->norte(); $p = $this->articulo(['stock_inicial' => 5]);
        $this->post('/stock/pases', ['origen_id' => $this->deposito->id, 'destino_id' => $norte->id, 'items' => [['product_id' => $p->id, 'cantidad' => 2]]]);
        $this->artisan('alertas:generar');
        $this->assertSame(0, Alerta::where('tipo', 'pase_en_viaje')->whereNull('resuelta_en')->count());
        $this->travel(3)->days();
        $this->artisan('alertas:generar');
        $this->assertSame(1, Alerta::where('tipo', 'pase_en_viaje')->whereNull('resuelta_en')->count());
        $t = TransferenciaStock::first();
        $this->post("/stock/pases/{$t->id}/recibir", ['conteos' => [$t->items()->first()->id => 2]]);
        $this->artisan('alertas:generar');
        $this->assertSame(0, Alerta::where('tipo', 'pase_en_viaje')->whereNull('resuelta_en')->count());
    }

    public function test_bigcito_pide_al_deposito_y_manda_a_proveedor(): void
    {
        $norte = $this->norte(); $p = $this->articulo(['name' => 'Cemento Avellaneda', 'stock_inicial' => 30]); $prov = $this->proveedor(['name' => 'Loma Negra']);
        $cat = app(\App\Mcp\Catalogo::class);
        $r = $cat->llamar($this->dueno, 'pedir_al_deposito', ['articulos' => [['articulo' => 'cemento avellaneda', 'cantidad' => 10]], 'para' => 'norte'], 'asistente');
        $cat->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $r['propuesta_id']]);
        $this->assertSame($norte->id, TransferenciaStock::where('tipo', 'pedido')->value('destino_id'));
        $r = $cat->llamar($this->dueno, 'mandar_a_proveedor', ['proveedor' => 'loma negra', 'articulos' => [['articulo' => 'cemento avellaneda', 'cantidad' => 2]], 'motivo' => 'bolsas rotas'], 'asistente');
        $cat->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $r['propuesta_id']]);
        $this->assertEquals(28, $p->fresh()->stockEn($this->deposito->id));
    }
}
