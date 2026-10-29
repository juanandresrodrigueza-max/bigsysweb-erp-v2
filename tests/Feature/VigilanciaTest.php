<?php

namespace Tests\Feature;

use App\Models\PrecioHistorial;
use App\Models\ProveedorArticulo;
use App\Models\Rubro;
use App\Services\Estadisticas\StockComprasService;
use App\Services\Estadisticas\VigilanciaService;
use App\Services\Stock\StockService;
use Illuminate\Support\Carbon;
use Tests\ErpTestCase;

// Fase 33.3–33.4: márgenes a vigilar con historial, precios a revisar, el margen que se achica; stock y compras.
class VigilanciaTest extends ErpTestCase
{
    public function test_margenes_a_vigilar_con_minimo_general_por_rubro_o_por_articulo_y_el_historial(): void
    {
        $rubro = Rubro::firstOrCreate(['business_id' => $this->empresa->id, 'nombre' => 'General', 'parent_id' => null]);
        $a = $this->articulo(['name' => 'Flojo', 'cost' => 100, 'price' => 110]);     // 10 % sobre costo
        $b = $this->articulo(['name' => 'Justo', 'cost' => 100, 'price' => 132]);     // 32 %
        $c = $this->articulo(['name' => 'Bien', 'cost' => 100, 'price' => 180]);      // 80 %
        $d = $this->articulo(['name' => 'Sin costo', 'cost' => 0, 'price' => 50]);
        $vig = app(VigilanciaService::class);
        $this->assertSame([], $vig->margenes($this->empresa)['items']); // sin mínimos no se vigila nada
        $this->post('/estadisticas/rentabilidad/minimos', ['general' => 30])->assertSessionHas('success');
        $r = $vig->margenes($this->empresa->fresh());
        $this->assertSame(['rojo' => 1, 'amarillo' => 1, 'verde' => 1], $r['cantidad']);
        $this->assertSame('Flojo', $r['items'][0]['nombre']); $this->assertSame('rojo', $r['items'][0]['estado']); $this->assertEquals(10, $r['items'][0]['margen']); $this->assertSame('general', $r['items'][0]['origen_minimo']);
        $this->assertSame('amarillo', $r['items'][1]['estado']);
        // el del rubro manda sobre el general; el del artículo sobre el del rubro
        $this->post('/estadisticas/rentabilidad/minimos', ['rubros' => [['id' => $rubro->id, 'margen_minimo' => 50]]]);
        $r = $vig->margenes($this->empresa->fresh()); $this->assertSame(['rojo' => 2, 'amarillo' => 0, 'verde' => 1], $r['cantidad']); $this->assertSame('rubro', $r['items'][0]['origen_minimo']);
        $this->post('/estadisticas/rentabilidad/minimos', ['articulos' => [['id' => $c->id, 'margen_minimo' => 90]]]);
        $r = $vig->margenes($this->empresa->fresh()); $this->assertSame(['rojo' => 3, 'amarillo' => 0, 'verde' => 0], $r['cantidad']);
        $this->assertSame('artículo', collect($r['items'])->firstWhere('id', $c->id)['origen_minimo']);
        // el historial: cada cambio de precio o costo queda, con cómo venía hace 30 y 90 días
        PrecioHistorial::create(['business_id' => $this->empresa->id, 'product_id' => $a->id, 'fecha' => today()->subDays(100)->toDateString(), 'precio' => 160, 'costo' => 100]);
        PrecioHistorial::create(['business_id' => $this->empresa->id, 'product_id' => $a->id, 'fecha' => today()->subDays(40)->toDateString(), 'precio' => 130, 'costo' => 100]);
        $a->update(['cost' => 105]);
        $this->assertDatabaseHas('precio_historial', ['product_id' => $a->id, 'costo' => 105, 'costo_antes' => 100, 'precio' => 110]);
        $r = $vig->margenes($this->empresa->fresh()); $fila = collect($r['items'])->firstWhere('id', $a->id);
        $this->assertEquals(30, $fila['hace30']); $this->assertEquals(60, $fila['hace90']); $this->assertEquals(4.76, $fila['margen']);
        $h = $this->getJson("/estadisticas/rentabilidad/historial/{$a->id}")->assertOk()->json();
        $this->assertCount(4, $h['serie']); $this->assertTrue($h['serie'][3]['hoy']); $this->assertEquals(50, $h['minimo']);
        $this->get('/estadisticas/rentabilidad?color=rojo')->assertInertia(fn($p) => $p->has('vigilar.items', 3)->has('revisar')->has('achica'));
    }

    public function test_precios_a_revisar_y_el_margen_que_se_achica(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00'));
        $cli = $this->cliente();
        $debajo = $this->articulo(['name' => 'Regalado', 'cost' => 100, 'price' => 90, 'stock_inicial' => 10]);
        $viejo = $this->articulo(['name' => 'Viejo', 'cost' => 50, 'price' => 100, 'stock_inicial' => 10]); $viejo->forceFill(['precio_actualizado_en' => now()->subDays(200)])->saveQuietly();
        $dolar = $this->articulo(['name' => 'En dólares', 'cost' => 50, 'price' => 100, 'moneda' => 'USD', 'stock_inicial' => 10]); $dolar->forceFill(['precio_actualizado_en' => now()->subDays(400)])->saveQuietly();
        $nuevo = $this->articulo(['name' => 'Nuevo', 'cost' => 50, 'price' => 100, 'stock_inicial' => 10]);
        foreach ([$viejo, $dolar, $nuevo] as $p) $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $r = app(VigilanciaService::class)->preciosARevisar($this->empresa);
        $this->assertCount(1, $r['debajo_costo']); $this->assertSame('Regalado', $r['debajo_costo'][0]['nombre']); $this->assertEquals(10, $r['debajo_costo'][0]['pierde']);
        $this->assertCount(1, $r['viejo']['180_365']); $this->assertSame('Viejo', $r['viejo']['180_365'][0]['nombre']); $this->assertSame(1, $r['total_viejo']); // el de dólares no cuenta, el nuevo está al día
        // el margen que se achica: mismo artículo vendido el mes pasado con costo 50 y este mes con costo 80
        $m = $this->articulo(['name' => 'Achica', 'cost' => 50, 'price' => 100, 'stock_inicial' => 50]);
        $f1 = $this->factura($cli, [['product_id' => $m->id, 'cantidad' => 10, 'precio_unit' => 100]]); $f1->forceFill(['fecha' => '2026-09-15'])->saveQuietly();
        $m->update(['cost' => 80]);
        $this->factura($cli, [['product_id' => $m->id, 'cantidad' => 10, 'precio_unit' => 100]]);
        $r = app(VigilanciaService::class)->margenQueSeAchica($this->empresa, today());
        $this->assertCount(1, $r['items']); $x = $r['items'][0];
        $this->assertSame('Achica', $x['nombre']); $this->assertEquals(50, $x['margen_anterior']); $this->assertEquals(20, $x['margen_actual']); $this->assertSame('subió el costo', $x['motivo']); $this->assertEquals(300, $x['dejaste_de_ganar']);
        Carbon::setTestNow();
    }

    public function test_stock_y_compras_dejas_de_vender_pedir_antiguedad_ajustes_aumentos_y_mas_barato(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00'));
        $cli = $this->cliente(); $sc = app(StockComprasService::class);
        $prov1 = $this->proveedor(['name' => 'Alfa', 'cuit' => '30-50000000-1']); $prov2 = $this->proveedor(['name' => 'Beta', 'cuit' => '30-60000000-2']);
        $sin = $this->articulo(['name' => 'Agotado', 'cost' => 100, 'price' => 200, 'iva' => 21, 'stock_inicial' => 6, 'proveedor_id' => $prov1->id]);
        for ($i = 0; $i < 6; $i++) $this->factura($cli, [['product_id' => $sin->id, 'cantidad' => 1, 'precio_unit' => 200]]); // 6 en 60 días → 0,1 por día; queda en 0
        $this->assertEquals(0, $sin->fresh()->stock);
        $d = $sc->dejasDeVender($this->empresa);
        $this->assertCount(1, $d['items']); $this->assertEquals(0.1, $d['items'][0]['por_dia']); $this->assertEquals(20, $d['items'][0]['pierde_dia']); $this->assertEquals(600, $d['por_mes']); $this->assertSame('Alfa', $d['items'][0]['proveedor']);
        // qué pedirle a cada proveedor: el agotado se pide a Alfa
        $this->post('/proveedores/compras', ['contact_id' => $prov1->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000001', 'fecha' => '2026-06-15', 'registrar' => true, 'condicion' => 'cta_cte', 'items' => [['product_id' => $sin->id, 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 100, 'alicuota_iva' => 21]]])->assertSessionHasNoErrors();
        $this->post('/proveedores/compras', ['contact_id' => $prov1->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000002', 'fecha' => '2026-09-01', 'registrar' => true, 'condicion' => 'cta_cte', 'items' => [['product_id' => $sin->id, 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 120, 'alicuota_iva' => 21]]])->assertSessionHasNoErrors();
        $this->post('/proveedores/compras', ['contact_id' => $prov2->id, 'tipo' => 'FA', 'numero_proveedor' => '0002-00000001', 'fecha' => '2026-10-20', 'registrar' => true, 'condicion' => 'cta_cte', 'items' => [['product_id' => $sin->id, 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 90, 'alicuota_iva' => 21]]])->assertSessionHasNoErrors();
        app(StockService::class)->ajustar($sin->fresh(), $this->deposito, 0, 'se rompió', 'ajuste'); // 3 → 0: ajuste para abajo valorizado
        $p = $sc->pedirPorProveedor($this->empresa);
        $alfa = collect($p['proveedores'])->firstWhere('proveedor', 'Alfa'); $this->assertNotNull($alfa); $this->assertSame(78, $alfa['cada_dias']); $this->assertSame('2026-09-01', $alfa['ultima_compra']);
        $an = $sc->antiguedad($this->empresa);
        $this->assertSame([], $an['items']); // sin stock no hay plata parada
        $otro = $this->articulo(['name' => 'Parado', 'cost' => 10, 'price' => 20, 'stock_inicial' => 5]);
        $an = $sc->antiguedad($this->empresa); $this->assertSame('Parado', $an['items'][0]['nombre']); $this->assertEquals(50, $an['plata']); $this->assertNull($an['items'][0]['ultima_venta']);
        $aj = $sc->ajustesAbajo($this->empresa);
        $this->assertSame(1, $aj['cantidad']); $this->assertEquals(3, $aj['items'][0]['cantidad']); $this->assertGreaterThan(0, $aj['total']); $this->assertSame('se rompió', $aj['items'][0]['motivo']);
        // aumentos: Alfa cobró 100 en junio (los 90 días anteriores) y 120 en septiembre (los últimos 90) → +20 %
        $au = $sc->aumentoProveedores($this->empresa);
        $this->assertSame('Alfa', $au['items'][0]['proveedor']); $this->assertEquals(20, $au['items'][0]['aumento']);
        // más barato: hoy se le compra a Alfa (120); Beta lo tiene a 90 → ahorro por lo vendido en 30 días (6 unidades × 30)
        $mb = $sc->masBarato($this->empresa);
        $this->assertCount(1, $mb['items']); $this->assertSame('Beta', $mb['items'][0]['mejor']['proveedor']); $this->assertSame('Alfa', $mb['items'][0]['hoy']['proveedor']); $this->assertEquals(30, $mb['items'][0]['diferencia']); $this->assertEquals(180, $mb['items'][0]['ahorro_mes']); $this->assertEquals(180, $mb['ahorro_mes']);
        // una diferencia de más del 70 % va a revisar
        ProveedorArticulo::create(['business_id' => $this->empresa->id, 'contact_id' => $prov2->id, 'codigo' => 'B-1', 'descripcion' => 'parado', 'product_id' => $otro->id, 'ultimo_costo' => 2, 'ultima_fecha' => today()->toDateString()]);
        ProveedorArticulo::create(['business_id' => $this->empresa->id, 'contact_id' => $prov1->id, 'codigo' => 'A-1', 'descripcion' => 'parado', 'product_id' => $otro->id, 'ultimo_costo' => 10, 'ultima_fecha' => today()->toDateString()]);
        $mb = $sc->masBarato($this->empresa); $this->assertCount(1, $mb['revisar']); $this->assertSame('Parado', $mb['revisar'][0]['nombre']);
        $this->get('/estadisticas/stock')->assertOk()->assertInertia(fn($pg) => $pg->component('Estadisticas/StockCompras', false)->has('dejas.items', 1)->has('pedir.proveedores')->has('barato.items', 1)->where('ajustes.cantidad', 1));
        Carbon::setTestNow();
    }
}
