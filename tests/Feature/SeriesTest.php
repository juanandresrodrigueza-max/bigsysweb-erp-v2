<?php

namespace Tests\Feature;

use App\Models\Lote;
use App\Models\OrdenTrabajo;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Validation\ValidationException;
use Tests\ErpTestCase;

// Fase 27.2: números de serie: una por unidad en la venta, garantía, historia del equipo y service.
class SeriesTest extends ErpTestCase
{
    private function falla(callable $f, string $texto): void
    {
        try { $f(); $this->fail("Tenía que frenar: {$texto}"); } catch (ValidationException $e) { $this->assertStringContainsString($texto, implode(' ', array_merge(...array_values($e->errors())))); }
    }

    public function test_venta_con_serie_garantia_devolucion_y_service(): void
    {
        $p = $this->articulo(['seriado' => true, 'garantia_meses' => 12, 'name' => 'Notebook X1']);
        $prov = $this->proveedor(['name' => 'Distribuidora Tech']);
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'fecha' => today()->toDateString(), 'numero_proveedor' => '0002-00000555', 'registrar' => true, 'condicion' => 'cta_cte', 'items' => [
            ['product_id' => $p->id, 'descripcion' => 'x', 'cantidad' => 3, 'precio_unit' => 500000, 'alicuota_iva' => 21, 'serie' => "SN1, SN2\nSN3"]]])->assertSessionHasNoErrors();
        $this->assertSame(3, Lote::where('product_id', $p->id)->whereNotNull('serie')->where('cantidad', 1)->count());
        $cli = $this->cliente(['name' => 'Estudio Pérez']);

        $this->falla(fn() => $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 800000]]), 'lleva número de serie');
        $this->falla(fn() => $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 800000, 'serie' => 'SN9']]), 'SN9 no está en stock');
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 800000, 'serie' => 'SN2']]);
        $sn2 = Lote::where('serie', 'SN2')->first();
        $this->assertEquals(0, (float) $sn2->cantidad); $this->assertSame($cli->id, $sn2->cliente_id); $this->assertSame($f->id, $sn2->comprobante_venta_id);
        $this->assertSame(today()->addMonthsNoOverflow(12)->toDateString(), $sn2->garantia_hasta->toDateString());
        $this->assertEquals(1, (float) Lote::where('serie', 'SN1')->value('cantidad'), 'Sale justo la serie elegida');
        $this->falla(fn() => $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 800000, 'serie' => 'SN2']]), 'se vendió a Estudio Pérez');

        // Ficha del equipo y service en garantía.
        $this->get('/stock/series?buscar=SN2')->assertOk()->assertInertia(fn($pg) => $pg->component('Stock/Series', false)->has('series', 1)->where('series.0.estado', 'vendido')->where('series.0.en_garantia', true));
        $ficha = $this->getJson("/stock/series/{$sn2->id}")->assertOk()->json();
        $this->assertSame('Distribuidora Tech', $ficha['proveedor']); $this->assertCount(2, $ficha['movimientos']);
        $this->post("/stock/series/{$sn2->id}/servicio", ['falla' => 'No enciende'])->assertRedirect();
        $ot = OrdenTrabajo::latest('id')->first();
        $this->assertSame('SN2', $ot->serie); $this->assertSame($cli->id, $ot->contact_id); $this->assertStringContainsString('EN GARANTÍA', $ot->notas);
        $this->assertCount(1, $this->getJson("/stock/series/{$sn2->id}")->json('servicios'));

        // Nota de crédito: la serie vuelve al stock y se libera.
        $svc = app(ComprobanteService::class);
        $svc->emitir($svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'NCX', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'origen_id' => $f->id, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 800000]]]));
        $sn2 = $sn2->fresh();
        $this->assertEquals(1, (float) $sn2->cantidad); $this->assertNull($sn2->cliente_id); $this->assertNull($sn2->garantia_hasta);

        // Anular una venta también la libera.
        $f3 = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 800000, 'serie' => 'SN3']]);
        $svc->anular($f3->fresh(), 'error');
        $this->assertEquals(1, (float) Lote::where('serie', 'SN3')->value('cantidad')); $this->assertNull(Lote::where('serie', 'SN3')->value('cliente_id'));
    }

    public function test_sin_exigir_serie_sale_cualquiera(): void
    {
        $p = $this->articulo(['seriado' => true]);
        app(\App\Services\Stock\StockService::class)->entrada($p, 2, 'ingreso', null, $this->deposito, 100, null, ['serie' => 'A1,A2']);
        $this->empresa->update(['lotes_config' => ['exigir_serie' => false, 'bloquear_vencidos' => true, 'dias_aviso' => 30]]);
        $this->actingAs($this->dueno->fresh());
        $this->factura($this->cliente(), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame(1, Lote::where('product_id', $p->id)->where('cantidad', 1)->count());
    }

    public function test_punto_de_venta_con_la_etiqueta_de_serie(): void
    {
        $p = $this->articulo(['seriado' => true, 'garantia_meses' => 6, 'price' => 1000]);
        app(\App\Services\Stock\StockService::class)->entrada($p, 2, 'ingreso', null, $this->deposito, 100, null, ['serie' => 'CEL-001,CEL-002']);
        $this->getJson('/retail/serie?codigo=CEL-002')->assertOk()->assertJsonPath('serie', 'CEL-002')->assertJsonPath('producto.id', $p->id)->assertJsonPath('producto.seriado', true);
        $this->getJson('/retail/serie?codigo=NADA')->assertNotFound();
        app(\App\Services\Pos\PosService::class)->vender(['items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1210, 'serie' => 'CEL-002']], 'precios_con_iva' => true, 'medios' => [['medio' => 'efectivo', 'monto' => 1210]]]);
        $this->assertEquals(0, (float) Lote::where('serie', 'CEL-002')->value('cantidad'));
        $this->assertNotNull(Lote::where('serie', 'CEL-002')->value('garantia_hasta'));
        $this->getJson('/retail/serie?codigo=CEL-002')->assertNotFound();
    }
}
