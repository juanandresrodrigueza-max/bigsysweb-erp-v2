<?php

namespace Tests\Feature;

use App\Models\StockDeposito;
use App\Models\TurnoCaja;
use App\Services\Comprobantes\CobroService;
use App\Services\Fondos\FondosService;
use Tests\ErpTestCase;

// Fase 26.1: cierre de turno con stock final contado contra lo facturado y lo recaudado.
class StockTurnoTest extends ErpTestCase
{
    public function test_el_cierre_con_stock_final_detecta_mercaderia_que_salio_sin_facturar(): void
    {
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 20, 'control_turno' => true]);
        $otro = $this->articulo(['price' => 50, 'stock_inicial' => 5]); // no se cuenta en el turno
        $this->travel(1)->minutes();
        $t = app(FondosService::class)->abrirTurno($this->caja, (float) $this->caja->fresh()->saldo);
        $cli = $this->cliente();
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 3, 'precio_unit' => 100]]); // en cuenta corriente: el cajero la rinde como tal

        $this->get('/fondos?cuenta=' . $this->caja->id)->assertOk()->assertInertia(function ($pg) {
            $c = collect($pg->toArray()['props']['cuentas'])->firstWhere('id', $this->caja->id);
            $this->assertCount(1, $c['turno']['stock'], 'Solo el artículo marcado');
            $this->assertEquals(20, $c['turno']['stock'][0]['inicial']); $this->assertEquals(3, $c['turno']['stock'][0]['facturado']); $this->assertEquals(17, $c['turno']['stock'][0]['esperado']);
            return $pg;
        });

        // Se contaron 16: salió uno sin facturar.
        $this->post("/fondos/turnos/{$t->id}/cerrar", ['saldo_contado' => (float) $this->caja->fresh()->saldo, 'stock' => [$p->id => 16], 'ajustar_stock' => true])->assertSessionHas('error');
        $t = $t->fresh();
        $fila = $t->stock[0];
        $this->assertEquals(4, $fila['salio']); $this->assertEquals(1, $fila['diferencia']); $this->assertEquals(100, $fila['diferencia_importe']);
        $this->assertEqualsWithDelta(400, (float) $t->stock_importe, 0.001);
        $this->assertEqualsWithDelta((float) $f->total - 400, (float) $t->stock_diferencia, 0.01, 'Recaudado − lo que salió por conteo');
        $this->assertEqualsWithDelta(16, (float) StockDeposito::where('product_id', $p->id)->value('cantidad'), 0.001, 'El stock quedó igual a lo contado');
        $this->get("/fondos/turnos/{$t->id}/rendicion")->assertOk()->assertSee('Stock final del turno')->assertSee('Recaudado − stock');

        // El turno siguiente arranca del final contado.
        $this->travel(1)->minutes();
        $t2 = app(FondosService::class)->abrirTurno($this->caja->fresh(), (float) $this->caja->fresh()->saldo);
        $pl = app(\App\Services\Fondos\StockTurnoService::class)->planilla($t2);
        $this->assertEquals(16, $pl[0]['inicial']); $this->assertSame('turno anterior', $pl[0]['inicial_origen']);
    }

    public function test_sin_conteo_el_cierre_funciona_como_antes(): void
    {
        $this->articulo(['price' => 100, 'stock_inicial' => 20, 'control_turno' => true]);
        $t = app(FondosService::class)->abrirTurno($this->caja, (float) $this->caja->fresh()->saldo);
        $this->post("/fondos/turnos/{$t->id}/cerrar", ['saldo_contado' => (float) $this->caja->fresh()->saldo])->assertSessionHas('success');
        $this->assertNull($t->fresh()->stock);
    }
}
