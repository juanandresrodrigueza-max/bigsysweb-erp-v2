<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Lote;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Stock\StockService;
use Illuminate\Validation\ValidationException;
use Tests\ErpTestCase;

// Fase 27.1: lotes y vencimientos: FEFO, bloqueo de vencidos, lote elegido, devolución al mismo lote, trazabilidad y retiro.
class LotesTrazabilidadTest extends ErpTestCase
{
    private function preparar(): array
    {
        $p = $this->articulo(['perecedero' => true, 'name' => 'Leche entera']);
        $prov = $this->proveedor(['name' => 'Lácteos del Sur']);
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'fecha' => today()->toDateString(), 'numero_proveedor' => '0001-00000777', 'registrar' => true, 'condicion' => 'cta_cte', 'items' => [
            ['product_id' => $p->id, 'descripcion' => 'x', 'cantidad' => 10, 'precio_unit' => 100, 'alicuota_iva' => 21, 'lote' => 'L1', 'vencimiento' => today()->addDays(10)->toDateString()],
            ['product_id' => $p->id, 'descripcion' => 'x', 'cantidad' => 10, 'precio_unit' => 100, 'alicuota_iva' => 21, 'lote' => 'L2', 'vencimiento' => today()->addDays(60)->toDateString()],
        ]])->assertSessionHasNoErrors();
        app(StockService::class)->entrada($p, 5, 'viejo', null, $this->deposito, 100, null, ['lote' => 'L0', 'vencimiento' => today()->subDay()->toDateString()]);
        $lote = fn($n) => Lote::where('product_id', $p->id)->where('lote', $n)->first();
        return [$p, $prov, $lote];
    }

    public function test_la_venta_sale_por_vencimiento_no_toma_vencidos_y_la_nota_de_credito_devuelve_al_mismo_lote(): void
    {
        [$p, $prov, $lote] = $this->preparar();
        $this->assertSame($prov->id, $lote('L1')->proveedor_id, 'El lote sabe de qué proveedor vino');
        $cli = $this->cliente(['name' => 'Almacén Doña Rosa']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 12, 'precio_unit' => 150]]);
        $this->assertEqualsWithDelta(0, (float) $lote('L1')->cantidad, 0.001);
        $this->assertEqualsWithDelta(8, (float) $lote('L2')->cantidad, 0.001);
        $this->assertEqualsWithDelta(5, (float) $lote('L0')->cantidad, 0.001, 'El vencido no se vende');

        // Quedan 8 vendibles (y 5 vencidos): una venta de 10 se frena.
        try { $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 150]]); $this->fail('Tenía que frenar'); }
        catch (ValidationException $e) { $this->assertStringContainsString('L0', $e->errors()['items'][0]); }

        // Nota de crédito por 3: vuelve a los lotes de la factura.
        $svc = app(ComprobanteService::class);
        $nc = $svc->emitir($svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'NCX', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'origen_id' => $f->id, 'items' => [['product_id' => $p->id, 'cantidad' => 3, 'precio_unit' => 150]]]));
        $this->assertEqualsWithDelta(11, (float) $lote('L1')->cantidad + (float) $lote('L2')->cantidad, 0.001);
        $this->assertNull(Lote::where('product_id', $p->id)->whereNull('lote')->first(), 'No crea una partida sin lote');

        // Trazabilidad: el cliente recibió 12 − 3 = 9 unidades, repartidas entre L1 y L2.
        $t1 = $this->getJson('/stock/lotes/' . $lote('L1')->id)->assertOk()->json();
        $this->assertSame('Almacén Doña Rosa', $t1['clientes'][0]['nombre']);
        // La NC devolvió primero las 2 de L2 (la última salida) y 1 a L1: entraron 10 de la compra + 1 de la devolución.
        $this->assertEqualsWithDelta(11, $t1['entrado'], 0.001);
        $this->assertEqualsWithDelta(9, $t1['clientes'][0]['cantidad'], 0.001, 'Al cliente le quedaron 9 de L1');
        $this->assertTrue(collect($t1['movimientos'])->contains(fn($m) => str_contains((string) $m['comprobante'], '0001-00000777') || $m['contacto'] === 'Lácteos del Sur'));
    }

    public function test_lote_elegido_bloqueo_retiro_y_baja(): void
    {
        [$p, , $lote] = $this->preparar();
        $cli = $this->cliente();
        // Lote elegido en la línea: sale de L2 aunque L1 vence antes.
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 150, 'lote_id' => $lote('L2')->id]]);
        $this->assertEqualsWithDelta(10, (float) $lote('L1')->cantidad, 0.001); $this->assertEqualsWithDelta(8, (float) $lote('L2')->cantidad, 0.001);

        // Retiro del mercado de L1: no se vende más; la venta toma L2.
        $this->post('/stock/lotes/' . $lote('L1')->id . '/estado', ['estado' => 'retirado', 'motivo' => 'Alerta del proveedor'])->assertSessionHasNoErrors();
        $this->assertSame('retirado', $lote('L1')->estado);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 3, 'precio_unit' => 150]]);
        $this->assertEqualsWithDelta(10, (float) $lote('L1')->cantidad, 0.001); $this->assertEqualsWithDelta(5, (float) $lote('L2')->cantidad, 0.001);

        // Anular la primera factura devuelve a L2.
        app(ComprobanteService::class)->anular($f->fresh(), 'error');
        $this->assertEqualsWithDelta(7, (float) $lote('L2')->cantidad, 0.001);

        // Baja del vencido: sale del stock con su lote.
        $antes = (float) $p->fresh()->stock;
        $this->post('/stock/lotes/' . $lote('L0')->id . '/baja', [])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(0, (float) $lote('L0')->cantidad, 0.001);
        $this->assertEqualsWithDelta($antes - 5, (float) $p->fresh()->stock, 0.001);

        // Tablero.
        $this->get('/stock/lotes?filtro=bloqueados')->assertOk()->assertInertia(fn($pg) => $pg->component('Stock/Lotes', false)->has('lotes', 1)->where('kpis.bloqueados', 1));
        $this->getJson("/stock/lotes/de/{$p->id}")->assertOk()->assertJsonCount(1);
        $this->get('/stock/lotes/' . $lote('L1')->id . '/retiro.csv')->assertOk();
        // Alertas diarias.
        $this->artisan('alertas:generar')->assertExitCode(0);
    }
}
