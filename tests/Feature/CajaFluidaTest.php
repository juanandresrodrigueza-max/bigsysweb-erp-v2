<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\Comprobante;
use App\Models\CuentaCorriente;
use App\Models\MovimientoFondos;
use Tests\ErpTestCase;

// Fase 32.1 · Caja fluida: anular la última venta, devolución con la plata de vuelta, DNI del consumidor final, cheques, frecuentes y últimas ventas.
class CajaFluidaTest extends ErpTestCase
{
    private function venta(array $items, array $medios, array $extra = []): array
    {
        return $this->postJson('/retail/vender', ['items' => $items, 'medios' => $medios, 'precios_con_iva' => true] + $extra)->assertOk()->json();
    }

    public function test_anular_la_ultima_venta_devuelve_el_cobro_y_con_cae_sale_la_nota_de_credito(): void
    {
        $p = $this->articulo(['price' => 1210, 'stock_inicial' => 10]);
        $cajaAntes = (float) $this->caja->fresh()->saldo;
        $v = $this->venta([['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 1210, 'descuento' => 0, 'alicuota_iva' => 21]], [['medio' => 'efectivo', 'monto' => 3000]]);
        $this->assertEquals(8, $p->fresh()->stock); $this->assertEquals($cajaAntes + 2420, (float) $this->caja->fresh()->saldo);
        $this->getJson('/retail/ultimas')->assertOk()->assertJsonPath('0.id', $v['comprobante_id'])->assertJsonPath('0.total', 2420)->assertJsonPath('0.medios.0', 'efectivo');
        // sin CAE: se anula de verdad y la plata sale de la caja
        $r = $this->postJson("/retail/venta/{$v['comprobante_id']}/anular", ['motivo' => 'se equivocó de artículo'])->assertOk()->json();
        $this->assertSame('anulada', $r['modo']);
        $this->assertSame('anulado', Comprobante::find($v['comprobante_id'])->estado);
        $this->assertEquals(10, $p->fresh()->stock); $this->assertEquals($cajaAntes, (float) $this->caja->fresh()->saldo);
        // con CAE: nota de crédito automática por el total y la plata vuelve por donde entró
        $v2 = $this->venta([['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1210, 'descuento' => 0, 'alicuota_iva' => 21]], [['medio' => 'efectivo', 'monto' => 1210]]);
        Comprobante::whereKey($v2['comprobante_id'])->update(['afip_estado' => 'aprobado', 'cae' => '71234567890123', 'tipo' => 'FB']);
        $r = $this->postJson("/retail/venta/{$v2['comprobante_id']}/anular", ['motivo' => 'cliente se arrepintió'])->assertOk()->json();
        $this->assertSame('nc', $r['modo']);
        $nc = Comprobante::find($r['comprobante_id']);
        $this->assertSame('NCB', $nc->tipo); $this->assertEquals(1210, $nc->total); $this->assertSame($v2['comprobante_id'], $nc->origen_id);
        $this->assertSame('emitido', Comprobante::find($v2['comprobante_id'])->estado);
        $this->assertEquals(10, $p->fresh()->stock); $this->assertEquals($cajaAntes, (float) $this->caja->fresh()->saldo);
        $this->assertEquals(0, (float) CuentaCorriente::where('contact_id', $nc->contact_id)->sum(\Illuminate\Support\Facades\DB::raw('debe - haber')));
        $this->assertAsientosBalancean();
        // una venta de ayer no se anula desde la caja
        Comprobante::whereKey($v2['comprobante_id'])->update(['fecha' => today()->subDay()->toDateString()]);
        $this->postJson("/retail/venta/{$v2['comprobante_id']}/anular", ['motivo' => 'x'])->assertStatus(422);
    }

    public function test_devolucion_parcial_con_la_plata_por_el_medio_elegido_y_no_mas_de_lo_vendido(): void
    {
        $a = $this->articulo(['name' => 'Gaseosa', 'price' => 1000, 'stock_inicial' => 10]);
        $b = $this->articulo(['name' => 'Papas', 'price' => 500, 'stock_inicial' => 10]);
        $v = $this->venta([['product_id' => $a->id, 'cantidad' => 3, 'precio_unit' => 1000, 'descuento' => 0, 'alicuota_iva' => 21], ['product_id' => $b->id, 'cantidad' => 1, 'precio_unit' => 500, 'descuento' => 0, 'alicuota_iva' => 21]], [['medio' => 'efectivo', 'monto' => 3500]]);
        $c = Comprobante::with('items')->find($v['comprobante_id']); $ia = $c->items->firstWhere('product_id', $a->id);
        $caja = (float) $this->caja->fresh()->saldo;
        $r = $this->postJson("/retail/venta/{$c->id}/devolver", ['items' => [['item_id' => $ia->id, 'cantidad' => 2]], 'medio' => 'efectivo', 'motivo' => 'vinieron vencidas'])->assertOk()->json();
        $this->assertEquals(2000, $r['total']);
        $nc = Comprobante::find($r['comprobante_id']);
        $this->assertTrue(str_starts_with($nc->tipo, 'NC')); $this->assertEquals(9, $a->fresh()->stock); $this->assertEquals(9, $b->fresh()->stock);
        $this->assertEquals($caja - 2000, (float) $this->caja->fresh()->saldo);
        $this->assertDatabaseHas('movimientos_fondos', ['origen' => 'devolucion', 'origen_id' => $nc->id, 'egreso' => 2000]);
        $this->assertEquals(0, (float) CuentaCorriente::where('contact_id', $c->contact_id)->sum(\Illuminate\Support\Facades\DB::raw('debe - haber')));
        $this->assertStringContainsString('vinieron vencidas', $nc->notas); $this->assertStringContainsString('Efectivo', $nc->notas);
        // ya volvieron 2 de 3: no se pueden devolver 2 más
        $this->postJson("/retail/venta/{$c->id}/devolver", ['items' => [['item_id' => $ia->id, 'cantidad' => 2]], 'medio' => 'efectivo'])->assertStatus(422);
        $this->postJson("/retail/venta/{$c->id}/devolver", ['items' => [['item_id' => $ia->id, 'cantidad' => 1]], 'medio' => 'tarjeta'])->assertOk();
        $this->assertEquals($caja - 2000, (float) $this->caja->fresh()->saldo); // con tarjeta no sale plata de la caja
        $this->getJson('/retail/ultimas')->assertJsonPath('0.devuelto', 3000);
        $this->assertAsientosBalancean();
    }

    public function test_dni_del_consumidor_final_cheques_y_frecuentes(): void
    {
        $p = $this->articulo(['name' => 'Pan', 'price' => 100, 'stock_inicial' => 50]);
        $q = $this->articulo(['name' => 'Leche', 'price' => 200, 'stock_inicial' => 50]);
        $v = $this->venta([['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100, 'descuento' => 0, 'alicuota_iva' => 21]], [['medio' => 'efectivo', 'monto' => 100]], ['receptor' => ['nombre' => 'Juan Pérez', 'documento' => '30123456']]);
        $c = Comprobante::find($v['comprobante_id']);
        $this->assertSame('30123456', $c->receptor['documento']); $this->assertSame('Juan Pérez', $c->receptor['nombre']);
        $this->postJson('/retail/vender', ['items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100, 'alicuota_iva' => 21]], 'medios' => [['medio' => 'efectivo', 'monto' => 100]], 'receptor' => ['documento' => '123']])->assertStatus(422);
        // dos cheques y efectivo en la misma venta: quedan en cartera
        $this->venta([['product_id' => $q->id, 'cantidad' => 10, 'precio_unit' => 200, 'descuento' => 0, 'alicuota_iva' => 21]],
            [['medio' => 'cheque', 'monto' => 800, 'datos' => ['numero' => '1001', 'banco' => 'Galicia', 'fecha_pago' => today()->addDays(10)->toDateString(), 'emisor' => 'Pepe']], ['medio' => 'cheque', 'monto' => 1000, 'datos' => ['numero' => '1002', 'banco' => 'Nación', 'fecha_pago' => today()->addDays(30)->toDateString()]], ['medio' => 'efectivo', 'monto' => 200]]);
        $this->assertSame(2, Cheque::where('estado', 'cartera')->count()); $this->assertSame('Galicia', Cheque::where('numero', '1001')->first()->banco);
        $this->getJson('/retail/ultimas')->assertJsonPath('0.medios', ['cheque', 'efectivo']);
        // frecuentes: lo más vendido en la caja en los últimos 30 días (por cantidad de tickets): el pan salió en dos tickets
        $this->venta([['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100, 'descuento' => 0, 'alicuota_iva' => 21]], [['medio' => 'efectivo', 'monto' => 100]]);
        $this->get('/retail')->assertInertia(fn($pg) => $pg->where('frecuentes.0', $p->id)->where('frecuentes.1', $q->id));
    }
}
