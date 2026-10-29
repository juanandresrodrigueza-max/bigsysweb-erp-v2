<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Vendedor;
use App\Services\Estadisticas\TableroService;
use Illuminate\Support\Carbon;
use Tests\ErpTestCase;

// Fase 33.1–33.2: comparar siempre (período anterior y año pasado, cómo se calcula), hoy hasta esta hora, la proyección del mes,
// la matriz ABC, el mapa de calor, puntos de venta contra el año anterior, lo que se frenó de golpe y los vendedores.
class TableroTest extends ErpTestCase
{
    private function venta(\App\Models\Contact $c, array $items, string $fecha, ?string $hora = null, array $extra = []): Comprobante
    {
        $f = $this->factura($c, $items, ['fecha' => $fecha] + $extra);
        $f->forceFill(['fecha' => $fecha, 'emitido_en' => Carbon::parse($fecha . ' ' . ($hora ?? '10:00'))])->saveQuietly();
        return $f;
    }

    public function test_hoy_contra_el_mismo_dia_de_la_semana_pasada_a_la_misma_hora_y_la_proyeccion_del_mes(): void
    {
        $ahora = Carbon::parse('2026-10-28 15:30'); Carbon::setTestNow($ahora); // miércoles
        $c = $this->cliente(); $p = $this->articulo(['price' => 1000, 'stock_inicial' => 1000]);
        $this->venta($c, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]], '2026-10-28', '09:00');
        $this->venta($c, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 1000]], '2026-10-28', '14:00');
        $this->venta($c, [['product_id' => $p->id, 'cantidad' => 5, 'precio_unit' => 1000]], '2026-10-28', '19:00'); // después de "ahora": no cuenta
        $this->venta($c, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 1000]], '2026-10-21', '11:00'); // miércoles pasado, antes de las 15:30
        $this->venta($c, [['product_id' => $p->id, 'cantidad' => 4, 'precio_unit' => 1000]], '2026-10-21', '20:00'); // miércoles pasado, de noche: no cuenta
        $hoy = app(TableroService::class)->hoy(null, $ahora);
        $this->assertEquals(3630, $hoy['ventas']); $this->assertEquals(2420, $hoy['semana_pasada']); $this->assertEquals(50, $hoy['var_semana']); $this->assertSame('14:00', $hoy['ultimo']); $this->assertSame('miércoles', $hoy['dia']);
        // proyección: 8 semanas con ventas todos los lunes y miércoles → con menos de 20 facturas no se puede saber
        $pr = app(TableroService::class)->proyeccionMes(null, today());
        $this->assertFalse($pr['suficiente']); $this->assertNull($pr['proyeccion']);
        for ($d = today()->subDays(56); $d->lt(today()); $d->addDay()) { if (in_array($d->dayOfWeek, [1, 3, 5], true)) $this->venta($c, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]], $d->toDateString()); } // lunes, miércoles y viernes: 24 facturas
        $pr = app(TableroService::class)->proyeccionMes(null, today());
        $this->assertTrue($pr['suficiente']); $this->assertGreaterThan($pr['hasta_ayer'], $pr['proyeccion']); $this->assertSame(4, $pr['faltan_dias']);
        $this->assertStringContainsString('cuenta, no una promesa', $pr['como']);
        $this->get('/estadisticas')->assertInertia(fn($pg) => $pg->component('Estadisticas/Index', false)->where('hoy.ventas', 3630)->has('comparaciones.ventas.var_anio')->has('como.ventas')->has('proyeccion.proyeccion'));
        Carbon::setTestNow();
    }

    public function test_abc_mapa_de_calor_puntos_de_venta_y_vendedores(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00'));
        $c = $this->cliente(); $d2 = $this->cliente(['name' => 'Otro']);
        $a = $this->articulo(['name' => 'Estrella', 'price' => 1000, 'cost' => 400, 'stock_inicial' => 100]); $b = $this->articulo(['name' => 'Medio', 'price' => 1000, 'cost' => 800, 'stock_inicial' => 100]); $z = $this->articulo(['name' => 'Cola', 'price' => 1000, 'cost' => 900, 'stock_inicial' => 100]);
        $v1 = Vendedor::create(['business_id' => $this->empresa->id, 'nombre' => 'Ana', 'activo' => true]); $v2 = Vendedor::create(['business_id' => $this->empresa->id, 'nombre' => 'Beto', 'activo' => true]);
        $this->venta($c, [['product_id' => $a->id, 'cantidad' => 80, 'precio_unit' => 1000]], '2026-10-05', '10:00', ['vendedor_id' => $v1->id]);   // lunes 10 hs
        $this->venta($d2, [['product_id' => $b->id, 'cantidad' => 15, 'precio_unit' => 1000]], '2026-10-10', '18:00', ['vendedor_id' => $v2->id]);  // sábado 18 hs
        $this->venta($c, [['product_id' => $z->id, 'cantidad' => 5, 'precio_unit' => 1000]], '2026-10-12', '10:00', ['vendedor_id' => $v1->id]);
        $this->venta($c, [['product_id' => $a->id, 'cantidad' => 10, 'precio_unit' => 1000]], '2025-10-15', '10:00'); // el año pasado
        $this->venta($c, [['product_id' => $a->id, 'cantidad' => 30, 'precio_unit' => 1000]], '2026-09-15', '10:00', ['vendedor_id' => $v1->id]); // período anterior
        $t = app(TableroService::class); $d = Carbon::parse('2026-10-01'); $h = Carbon::parse('2026-10-31');
        $abc = $t->abc($d, $h);
        $this->assertSame('A', $abc['items'][0]['clase']); $this->assertSame('Estrella', $abc['items'][0]['nombre']); $this->assertSame('B', $abc['items'][1]['clase']); $this->assertSame('C', $abc['items'][2]['clase']);
        $this->assertEquals(80000, $abc['items'][0]['neto']); $this->assertEquals(100000, $abc['total']); $this->assertSame(['A' => 1, 'B' => 1, 'C' => 1], $abc['cantidad']);
        $cu = $t->cuandoVende($d, $h);
        $this->assertSame('2026-10-05', $cu['mejor_dia']['fecha']); $this->assertSame('2026-10-12', $cu['peor_dia']['fecha']); $this->assertEquals(10, $cu['mejor_hora']['hora']); $this->assertSame('lunes', $cu['mejor_dia_semana']);
        $this->assertNotNull(collect($cu['celdas'])->first(fn($x) => $x['d'] === 6 && $x['h'] === 18));
        $pv = $t->porPuntoVenta($d, $h);
        $this->assertEquals(121000, $pv['items'][0]['monto']); $this->assertEquals(12100, $pv['items'][0]['anterior']); $this->assertEquals(900, $pv['items'][0]['var']);
        $ve = $t->vendedores($d, $h);
        $ana = collect($ve['items'])->firstWhere('nombre', 'Ana'); $beto = collect($ve['items'])->firstWhere('nombre', 'Beto');
        $this->assertEquals(102850, $ana['ventas']); $this->assertEquals(2, $ana['facturas']); $this->assertEquals(1, $ana['clientes']); $this->assertEquals(10, $ana['hora_pico']); $this->assertEquals(85, $ana['parte']);
        $this->assertEquals(36300, $ana['anterior']); $this->assertEquals(183.3, $ana['var']);
        $this->assertEquals(48500, $ana['margen']); // 80·600 + 5·100
        $this->assertSame('Ana', $ve['destacados']['mas_vendio']['nombre']); $this->assertSame('Ana', $ve['destacados']['mejor_margen']['nombre']); $this->assertSame('Ana', $ve['destacados']['mejor_ticket']['nombre']); $this->assertEquals(18150, $beto['ticket']);
        $this->get('/estadisticas/ventas?desde=2026-10-01&hasta=2026-10-31')->assertOk()->assertInertia(fn($pg) => $pg->component('Estadisticas/Ventas', false)->where('abc.cantidad.A', 1)->has('cuando.celdas')->has('vendedores.items', 2)->has('puntos.items', 1));
        Carbon::setTestNow();
    }

    public function test_lo_que_se_freno_de_golpe(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00'));
        $c = $this->cliente();
        $seguido = $this->articulo(['name' => 'Yerba', 'price' => 100, 'stock_inicial' => 50]);      // se vendía todas las semanas y hace 14 días que no
        $sigue = $this->articulo(['name' => 'Azúcar', 'price' => 100, 'stock_inicial' => 50]);       // se sigue vendiendo
        $sinStock = $this->articulo(['name' => 'Harina', 'price' => 100, 'stock_inicial' => 12]);    // se frenó porque no hay (stock 0): no es "frenado"
        $esporadico = $this->articulo(['name' => 'Vino', 'price' => 100, 'stock_inicial' => 50]);    // se vendió una sola vez
        for ($i = 3; $i <= 8; $i++) {
            $f = today()->subWeeks($i)->toDateString();
            $this->venta($c, [['product_id' => $seguido->id, 'cantidad' => 2, 'precio_unit' => 100], ['product_id' => $sigue->id, 'cantidad' => 1, 'precio_unit' => 100], ['product_id' => $sinStock->id, 'cantidad' => 2, 'precio_unit' => 100]], $f);
        }
        $this->venta($c, [['product_id' => $esporadico->id, 'cantidad' => 1, 'precio_unit' => 100]], today()->subWeeks(4)->toDateString());
        $this->venta($c, [['product_id' => $sigue->id, 'cantidad' => 1, 'precio_unit' => 100]], today()->subDays(3)->toDateString());
        $r = app(TableroService::class)->frenados();
        $this->assertCount(1, $r['items']); $this->assertSame('Yerba', $r['items'][0]['nombre']); $this->assertEquals(2, $r['items'][0]['por_semana']); $this->assertEquals(6, $r['items'][0]['semanas']);
        Carbon::setTestNow();
    }
}
