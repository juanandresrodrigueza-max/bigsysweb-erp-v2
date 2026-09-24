<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\Cobro;
use App\Models\MovimientoFondos;
use App\Models\OrdenEntrega;
use App\Models\OrdenEntregaCobro;
use Tests\ErpTestCase;

// Fase 25.5: rendición del reparto por chofer y viaje.
class RendicionRepartoTest extends ErpTestCase
{
    private function hoja(): array
    {
        $p = $this->articulo(['stock_inicial' => 50, 'price' => 1000]);
        $a = $this->cliente(['name' => 'Almacén Ana', 'dias_pago' => 0]);
        $b = $this->cliente(['name' => 'Bar Beto', 'cuit' => '20-12345678-6']);
        $fa = $this->factura($a, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 1000]], ['tipo' => 'FA', 'condicion' => 'contado']); // 12.100
        $fb = $this->factura($b, [['product_id' => $p->id, 'cantidad' => 5, 'precio_unit' => 1000]], ['tipo' => 'FA']); // 6.050
        $this->post('/comprobantes/entregas', ['comprobantes' => [$fa->id, $fb->id], 'repartidor' => 'Juan Chofer', 'fecha' => today()->toDateString()])->assertSessionHasNoErrors();
        $oe = OrdenEntrega::latest('id')->firstOrFail();
        return [$oe, $oe->items()->get()->keyBy('comprobante_id'), $fa, $fb, $a, $b];
    }

    public function test_anotar_cobros_rendir_con_viaticos_y_faltante(): void
    {
        [$oe, $items, $fa, $fb, $a, $b] = $this->hoja();
        $ia = $items[$fa->id]; $ib = $items[$fb->id];
        $this->post("/comprobantes/entregas/items/{$ia->id}/cobros", ['medio' => 'efectivo', 'monto' => 10000])->assertSessionHasNoErrors();
        $this->post("/comprobantes/entregas/items/{$ia->id}/cobros", ['medio' => 'transferencia', 'monto' => 2100, 'referencia' => 'OP 555'])->assertSessionHasNoErrors();
        $this->post("/comprobantes/entregas/items/{$ib->id}/cobros", ['medio' => 'cheque', 'monto' => 6050, 'banco' => 'Galicia', 'numero' => '123456', 'fecha_pago' => today()->addDays(30)->toDateString()])->assertSessionHasNoErrors();
        $this->post("/comprobantes/entregas/items/{$ib->id}/cobros", ['medio' => 'cheque', 'monto' => 10])->assertSessionHasErrors(['banco', 'numero']);

        $this->get("/comprobantes/entregas/{$oe->id}")->assertInertia(fn($p) => $p->component('Comprobantes/EntregaVer', false)
            ->where('esperado.efectivo', 10000)->where('esperado.cheque', 6050)->where('esperado.transferencia', 2100)->has('orden.items.0.cobros', 2));

        // No se rinde con entregas sin marcar.
        $this->post("/comprobantes/entregas/{$oe->id}/rendir", ['cuenta_fondos_id' => $this->caja->id, 'efectivo_contado' => 9000])->assertSessionHasErrors('rendir');
        foreach ([$ia, $ib] as $it) $this->post("/comprobantes/entregas/items/{$it->id}", ['estado' => 'entregado'])->assertSessionHasNoErrors();

        $cajaAntes = (float) $this->caja->fresh()->saldo;
        // Cobró 10.000 en efectivo, gastó 800 de nafta y 200 de peaje, entrega 8.950: faltan 50.
        $this->post("/comprobantes/entregas/{$oe->id}/rendir", ['cuenta_fondos_id' => $this->caja->id, 'cuenta_banco_id' => $this->banco->id, 'efectivo_contado' => 8950,
            'viaticos' => [['concepto' => 'Nafta', 'monto' => 800], ['concepto' => 'Peaje', 'monto' => 200], ['concepto' => '', 'monto' => 0]]])
            ->assertSessionHas('success', fn($m) => str_contains($m, '2 recibos') && str_contains($m, 'faltante de $ 50,00'));

        $oe->refresh();
        $this->assertNotNull($oe->rendida_en);
        $this->assertEquals(-50, $oe->rendicion['diferencia']);
        $this->assertEquals(9000, $oe->rendicion['debe_efectivo']);
        // Recibos: las facturas quedan canceladas y los clientes sin deuda.
        $this->assertEquals(0, (float) $fa->fresh()->saldo);
        $this->assertEquals(0, (float) $fb->fresh()->saldo);
        $this->assertEquals(0, (float) $a->fresh()->balance);
        $this->assertEquals(0, (float) $b->fresh()->balance);
        $this->assertSame(2, Cobro::where('notas', 'like', 'Rendición HR-%')->count());
        $this->assertSame(0, OrdenEntregaCobro::whereNull('cobro_id')->count());
        // Caja: +10.000 efectivo −1.000 viáticos −50 faltante = +8.950 (lo que entregó). Banco +2.100. Cheque en cartera.
        $this->assertEqualsWithDelta($cajaAntes + 8950, (float) $this->caja->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(2100, (float) MovimientoFondos::where('cuenta_fondos_id', $this->banco->id)->where('origen', 'cobro')->sum('ingreso'), 0.01);
        $this->assertSame(1, Cheque::where('numero', '123456')->where('estado', 'cartera')->count());
        $this->assertSame(2, MovimientoFondos::where('origen', 'gasto')->where('concepto', 'like', 'Viático HR-%')->count());
        $this->assertSame(1, MovimientoFondos::where('origen', 'ajuste')->where('concepto', 'like', 'Faltante de rendición%')->where('egreso', 50)->count());
        $this->assertAsientosBalancean();

        // Rendida: no se rinde dos veces ni se anotan más cobros; la hoja impresa muestra la rendición.
        $this->post("/comprobantes/entregas/{$oe->id}/rendir", ['cuenta_fondos_id' => $this->caja->id, 'efectivo_contado' => 1])->assertSessionHasErrors('rendir');
        $this->post("/comprobantes/entregas/items/{$ia->id}/cobros", ['medio' => 'efectivo', 'monto' => 1])->assertStatus(422);
        $this->get("/comprobantes/entregas/{$oe->id}/imprimir")->assertSee('Rendición del')->assertSee('Faltante');
    }

    public function test_sobrante_cobro_de_deuda_vieja_y_permisos(): void
    {
        [$oe, $items, $fa, $fb, $a, $b] = $this->hoja();
        // El chofer le cobra a Beto más de lo que salía: el resto queda a cuenta.
        $this->post("/comprobantes/entregas/items/{$items[$fb->id]->id}/cobros", ['medio' => 'efectivo', 'monto' => 7000])->assertSessionHasNoErrors();
        $c = OrdenEntregaCobro::first();
        foreach ($items as $it) $this->post("/comprobantes/entregas/items/{$it->id}", ['estado' => $it->comprobante_id === $fa->id ? 'no_entregado' : 'entregado', 'observacion' => 'cerrado'])->assertSessionHasNoErrors();
        // Rinde quien tiene permiso para cargar movimientos de fondos: el vendedor no.
        $this->actingAs($this->usuarioConRol('vendedor'));
        $this->post("/comprobantes/entregas/{$oe->id}/rendir", ['cuenta_fondos_id' => $this->caja->id, 'efectivo_contado' => 7000])->assertForbidden();
        $this->actingAs($this->dueno);
        $this->post("/comprobantes/entregas/{$oe->id}/rendir", ['cuenta_fondos_id' => $this->caja->id, 'efectivo_contado' => 7020])->assertSessionHas('success', fn($m) => str_contains($m, 'sobrante de $ 20,00'));
        $this->assertEquals(0, (float) $fb->fresh()->saldo);
        $this->assertEquals(950, (float) Cobro::find($c->fresh()->cobro_id)->a_cuenta, 'Lo que sobra de la factura queda a cuenta');
        $this->assertEquals(-950, (float) $b->fresh()->balance);
        $this->assertSame(1, MovimientoFondos::where('origen', 'ajuste')->where('ingreso', 20)->count());
        $this->assertAsientosBalancean();
    }

    public function test_quitar_un_cobro_mal_anotado(): void
    {
        [$oe, $items, $fa] = $this->hoja();
        $this->post("/comprobantes/entregas/items/{$items[$fa->id]->id}/cobros", ['medio' => 'efectivo', 'monto' => 99])->assertSessionHasNoErrors();
        $c = OrdenEntregaCobro::first();
        $this->delete("/comprobantes/entregas/cobros/{$c->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, OrdenEntregaCobro::count());
    }
}
