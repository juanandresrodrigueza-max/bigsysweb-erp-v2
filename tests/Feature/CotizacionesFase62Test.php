<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\OrdenCompra;
use App\Services\Compras\CotizacionesService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 62: cotizaciones de proveedores (fotos, PDF o el mensaje pegado) → tabla comparada y órdenes divididas al más barato.
class CotizacionesFase62Test extends ErpTestCase
{
    private function ia(array ...$respuestas): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save(); $this->dueno->refresh();
        $seq = Http::sequence(); foreach ($respuestas as $r) $seq->push(['content' => [['type' => 'text', 'text' => json_encode($r)]]]);
        Http::fake(['api.anthropic.com/*' => $seq]);
    }

    public function test_dos_cotizaciones_se_comparan_y_se_arman_las_ordenes_divididas(): void
    {
        $acindar = $this->proveedor(['name' => 'Acindar SA', 'cuit' => '30-50000000-1']); $sider = $this->proveedor(['name' => 'Siderar Hnos']);
        $hierro = $this->articulo(['name' => 'Hierro 8 mm x 12 m']); $cemento = $this->articulo(['name' => 'Cemento x 50 kg']); $this->articulo(['name' => 'Cal x 25 kg']);
        $this->postJson('/proveedores/ordenes/cotizaciones/leer', ['archivos' => [UploadedFile::fake()->image('a.jpg')]])->assertStatus(422); // sin clave
        $this->ia(
            ['proveedor' => ['nombre' => 'ACINDAR', 'cuit' => '30500000001'], 'fecha' => '2026-11-05', 'validez' => '7 días', 'condiciones' => 'Pago a 30 días, envío sin cargo desde $500.000', 'items' => [
                ['product_id' => $hierro->id, 'descripcion' => 'Hierro aletado 8 mm barra 12 m', 'cantidad' => 100, 'unidad' => 'un', 'precio_unit' => 12100, 'iva_incluido' => true, 'descuento' => 0],
                ['product_id' => $cemento->id, 'descripcion' => 'Cemento 50 kg', 'cantidad' => 50, 'unidad' => 'un', 'precio_unit' => 9500, 'iva_incluido' => false, 'descuento' => 0],
                ['product_id' => null, 'descripcion' => 'Alambre recocido x kg', 'cantidad' => 20, 'unidad' => 'kg', 'precio_unit' => 2100, 'iva_incluido' => false, 'descuento' => 0]]],
            ['proveedor' => ['nombre' => 'Siderar', 'cuit' => null], 'fecha' => null, 'validez' => null, 'condiciones' => 'Contado', 'items' => [
                ['product_id' => $hierro->id, 'descripcion' => 'Hierro 8', 'cantidad' => 100, 'unidad' => 'un', 'precio_unit' => 10000, 'iva_incluido' => false, 'descuento' => 5],
                ['product_id' => $cemento->id, 'descripcion' => 'Cemento', 'cantidad' => 50, 'unidad' => 'un', 'precio_unit' => 9900, 'iva_incluido' => false, 'descuento' => 0]]],
        );
        $r = $this->postJson('/proveedores/ordenes/cotizaciones/leer', ['archivos' => [UploadedFile::fake()->image('acindar.jpg'), UploadedFile::fake()->createWithContent('siderar.pdf', '%PDF-1.4 cotizacion siderar')]])->assertOk()->json();
        $this->assertTrue($r['ok']); $this->assertCount(2, $r['cotizaciones']);
        $this->assertSame($acindar->id, $r['cotizaciones'][0]['proveedor_id'], 'Por CUIT'); $this->assertSame($sider->id, $r['cotizaciones'][1]['proveedor_id'], 'Por nombre aproximado');
        $this->assertSame('document', Http::recorded()[1][0]->data()['messages'][0]['content'][0]['type']);
        $this->assertStringContainsString('Hierro 8 mm x 12 m', Http::recorded()[0][0]->data()['system'], 'La IA ve el catálogo');

        $c = $r['comparacion']; $filas = collect($c['filas'])->keyBy('descripcion');
        $this->assertEquals(12100, $filas['Hierro 8 mm x 12 m']['precios'][0]); $this->assertEquals(9500, $filas['Hierro 8 mm x 12 m']['precios'][1], 'Con el 5% de descuento'); $this->assertSame(1, $filas['Hierro 8 mm x 12 m']['gana']);
        $this->assertSame(0, $filas['Cemento x 50 kg']['gana']); $this->assertNull($filas['Alambre recocido x kg']['precios'][1]); $this->assertSame(0, $filas['Alambre recocido x kg']['gana']);
        $res = collect($c['resumen'])->keyBy('proveedor');
        $this->assertFalse($res['Siderar Hnos']['cotiza_todo']); $this->assertTrue($res['Acindar SA']['cotiza_todo']);
        $this->assertEquals(12100 * 100 + 9500 * 50 + 2100 * 20, $res['Acindar SA']['total_todo']);
        $this->assertEquals(9500 * 100 + 9500 * 50 + 2100 * 20, $c['mixto']); $this->assertEquals(260000, $c['ahorro_mixto']); $this->assertSame('Acindar SA', $c['mejor_unico']['proveedor']);
        $this->assertStringContainsString('menos que todo en Acindar', $c['texto']);

        // Las órdenes: una por proveedor, con lo que ganó cada uno.
        $this->post('/proveedores/ordenes/cotizaciones/pedidos', ['reparto' => $c['reparto'], 'notas' => 'Obra Belgrano'])->assertSessionHas('success', fn($m) => str_contains($m, '2 órdenes'));
        $ocs = OrdenCompra::with('items')->orderBy('id')->get(); $this->assertCount(2, $ocs);
        $deSider = $ocs->firstWhere('contact_id', $sider->id); $this->assertCount(1, $deSider->items); $this->assertEquals(9500, $deSider->items[0]->precio_unit); $this->assertEquals(100, $deSider->items[0]->cantidad);
        $deAcindar = $ocs->firstWhere('contact_id', $acindar->id); $this->assertCount(2, $deAcindar->items); $this->assertTrue($deAcindar->items->contains('descripcion', 'Alambre recocido x kg'));
        $this->assertStringContainsString('Obra Belgrano', $deAcindar->notas); $this->assertSame('cotizacion', $deAcindar->origen);
    }

    public function test_bigcito_compara_los_mensajes_pegados_y_lo_que_no_es_cotizacion_se_avisa(): void
    {
        $this->proveedor(['name' => 'Pinturerías Rex']); $lat = $this->articulo(['name' => 'Látex interior 20 L']);
        $this->ia(
            ['proveedor' => ['nombre' => 'Rex'], 'items' => [['product_id' => $lat->id, 'descripcion' => 'Latex 20L', 'cantidad' => 10, 'unidad' => 'un', 'precio_unit' => 48000, 'iva_incluido' => false, 'descuento' => 0]]],
            ['proveedor' => ['nombre' => null], 'items' => [], 'observaciones' => 'Es un saludo, no una cotización'],
        );
        $r = app(Catalogo::class)->llamar($this->dueno, 'comparar_cotizaciones', ['textos' => ["Rex: látex 20L a 48.000 + IVA, 10 unidades", 'Hola, cómo andás?']], 'asistente');
        $this->assertSame(1, $r['proveedores']); $this->assertStringContainsString('no parece una cotización', $r['avisos'][0]);
        $this->assertSame('Látex interior 20 L', $r['filas'][0]['articulo']); $this->assertSame('Pinturerías Rex', $r['filas'][0]['gana']);
        $this->assertStringContainsString('Rex', Http::recorded()[0][0]->data()['messages'][0]['content'][0]['text']);
    }

    public function test_comparar_sin_ia_es_puro_calculo(): void
    {
        $c = app(CotizacionesService::class)->comparar([
            ['proveedor_id' => 1, 'proveedor' => 'A', 'condiciones' => null, 'items' => [['product_id' => 1, 'descripcion' => 'X', 'detalle' => null, 'cantidad' => 2, 'unidad' => 'un', 'precio_unit' => 100, 'iva_incluido' => false, 'clave' => 'p1'], ['product_id' => 2, 'descripcion' => 'Y', 'detalle' => null, 'cantidad' => 1, 'unidad' => 'un', 'precio_unit' => 50, 'iva_incluido' => false, 'clave' => 'p2']]],
            ['proveedor_id' => 2, 'proveedor' => 'B', 'condiciones' => null, 'items' => [['product_id' => 1, 'descripcion' => 'X', 'detalle' => null, 'cantidad' => 2, 'unidad' => 'un', 'precio_unit' => 90, 'iva_incluido' => false, 'clave' => 'p1'], ['product_id' => 2, 'descripcion' => 'Y', 'detalle' => null, 'cantidad' => 1, 'unidad' => 'un', 'precio_unit' => 60, 'iva_incluido' => false, 'clave' => 'p2']]],
        ]);
        $this->assertEquals(250, $c['resumen'][0]['total_todo']); $this->assertEquals(240, $c['resumen'][1]['total_todo']); $this->assertEquals(230, $c['mixto']); $this->assertEquals(10, $c['ahorro_mixto']);
        $this->assertSame('B', $c['mejor_unico']['proveedor']); $this->assertCount(2, $c['reparto']); $this->assertEquals(11.1, $c['filas'][0]['diferencia_pct']);
    }
}
