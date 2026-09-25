<?php

namespace Tests\Feature;

use App\Models\StockDeposito;
use App\Models\Ubicacion;
use App\Models\UbicacionStock;
use App\Services\Comprobantes\ComprobanteService;
use Tests\ErpTestCase;

// Fase 27.4: gestión de almacén: ubicaciones, guardar, mover, consultar, contar, salida por venta y preparación.
class AlmacenTest extends ErpTestCase
{
    private function en(string $codigo, int $pid): float
    {
        return (float) UbicacionStock::where('ubicacion_id', Ubicacion::where('codigo', $codigo)->value('id'))->where('product_id', $pid)->sum('cantidad');
    }

    public function test_circuito_completo_de_almacen(): void
    {
        $dep = $this->deposito;
        $this->post('/stock/almacen/generar', ['deposito_id' => $dep->id, 'pasillos' => 'A-B', 'estantes' => 2, 'niveles' => 2, 'tipo' => 'estanteria'])->assertSessionHasNoErrors();
        $this->assertSame(8, Ubicacion::count());
        $this->assertSame(5, Ubicacion::where('codigo', 'B-02-1')->value('orden'), 'Recorrido en serpentina: el pasillo B vuelve desde el estante 02');
        $p = $this->articulo(['stock_inicial' => 20, 'sku' => 'YERBA1', 'barcode' => '7790001112223']);

        // Guardar con la pistola (por código de barras del artículo) y no más de lo que hay sin ubicar.
        $this->postJson('/stock/almacen/guardar', ['ubicacion' => 'a-01-1', 'articulo' => '7790001112223', 'cantidad' => 12])->assertOk();
        $this->postJson('/stock/almacen/guardar', ['ubicacion' => 'A-01-2', 'product_id' => $p->id, 'cantidad' => 10])->assertStatus(422)->assertJsonValidationErrors('cantidad');
        $this->postJson('/stock/almacen/mover', ['desde' => 'A-01-1', 'hacia' => 'B-02-1', 'product_id' => $p->id, 'cantidad' => 5])->assertOk();
        $this->assertEquals(7, $this->en('A-01-1', $p->id)); $this->assertEquals(5, $this->en('B-02-1', $p->id));

        // Consultar: ubicación o artículo.
        $this->getJson('/stock/almacen/escanear?codigo=A-01-1')->assertOk()->assertJsonPath('tipo', 'ubicacion')->assertJsonPath('ubicacion.contenido.0.cantidad', 7);
        $r = $this->getJson('/stock/almacen/escanear?codigo=YERBA1')->assertOk()->assertJsonPath('tipo', 'articulo')->json();
        $this->assertCount(2, $r['articulo']['ubicaciones']); $this->assertEquals(8, $r['articulo']['sin_ubicar'][0]['cantidad']);
        $this->getJson('/stock/almacen/escanear?codigo=NOEXISTE')->assertNotFound();

        // Venta de 10: 8 salen de lo sin ubicar y 2 de la primera ubicación del recorrido.
        $this->factura($this->cliente(), [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 100]]);
        $this->assertEquals(5, $this->en('A-01-1', $p->id)); $this->assertEquals(5, $this->en('B-02-1', $p->id));

        // Hoja de preparación de un presupuesto de 9: A-01-1 (5) y después B-02-1 (4).
        $svc = app(ComprobanteService::class);
        $pre = $svc->guardarBorrador(['contact_id' => $this->cliente()->id, 'tipo' => 'PRE', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'items' => [['product_id' => $p->id, 'cantidad' => 9, 'precio_unit' => 100]]]);
        $prep = $this->getJson("/stock/almacen/preparar/{$pre->id}")->assertOk()->json();
        $this->assertSame(['A-01-1', 'B-02-1'], array_column($prep['pasos'], 'codigo'));
        $this->assertEquals([5, 4], array_column($prep['pasos'], 'cantidad'));
        $this->get("/stock/almacen/preparar/{$pre->id}")->assertOk()->assertSee('Hoja de preparación')->assertSee('B-02-1');

        // Conteo: B-02-1 tenía 5, se contaron 3. Si las ubicaciones pasan el stock del depósito, el depósito se ajusta.
        $this->postJson('/stock/almacen/contar', ['ubicacion' => 'B-02-1', 'conteos' => [$p->id => 3]])->assertOk();
        $this->assertEquals(3, $this->en('B-02-1', $p->id));
        $this->postJson('/stock/almacen/contar', ['ubicacion' => 'A-01-1', 'conteos' => [$p->id => 12]])->assertOk();
        $this->assertEqualsWithDelta(15, (float) StockDeposito::where('product_id', $p->id)->where('deposito_id', $dep->id)->value('cantidad'), 0.001);

        // No se borra una ubicación con mercadería; pantallas.
        $this->delete('/stock/almacen/ubicaciones/' . Ubicacion::where('codigo', 'A-01-1')->value('id'))->assertStatus(422);
        $this->get('/stock/almacen')->assertOk()->assertInertia(fn($pg) => $pg->component('Stock/Almacen', false)->has('ubicaciones', 8));
        $this->get('/stock/almacen/etiquetas')->assertOk()->assertInertia(fn($pg) => $pg->component('Stock/AlmacenEtiquetas', false)->has('ubicaciones', 8));
        $this->get("/stock/{$p->id}")->assertOk()->assertInertia(fn($pg) => $pg->has('p.ubicaciones', 2));
    }

    public function test_ubicacion_duplicada_y_otra_empresa(): void
    {
        $this->post('/stock/almacen/ubicaciones', ['deposito_id' => $this->deposito->id, 'codigo' => 'recepcion', 'tipo' => 'recepcion'])->assertSessionHasNoErrors();
        $this->assertSame('RECEPCION', Ubicacion::first()->codigo);
        $this->post('/stock/almacen/ubicaciones', ['deposito_id' => $this->deposito->id, 'codigo' => 'RECEPCION', 'tipo' => 'recepcion'])->assertStatus(422);
        [, , $otro] = $this->crearEmpresa('Otra', 'otra');
        $this->actingAs($otro);
        $this->getJson('/stock/almacen/escanear?codigo=RECEPCION')->assertNotFound();
    }
}
