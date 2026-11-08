<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Comanda;
use App\Models\Mesa;
use App\Services\Gastronomia\HorariosService;
use Carbon\Carbon;
use Tests\ErpTestCase;

// Fase 52: happy hour por franja y rubro, platos con horario y Estadísticas → Gastronomía.
class GastronomiaHorariosReporteFase52Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->forceFill(['vertical' => 'gastronomia'])->save();
    }

    public function test_franjas_cruzan_la_medianoche_y_respetan_los_dias(): void
    {
        $f = ['dias' => [5], 'desde' => '22:00', 'hasta' => '02:00'];
        $this->assertTrue(HorariosService::enFranja($f, Carbon::parse('2026-11-13 23:30')));   // viernes
        $this->assertTrue(HorariosService::enFranja($f, Carbon::parse('2026-11-14 01:00')));   // sábado temprano: sigue la noche del viernes
        $this->assertFalse(HorariosService::enFranja($f, Carbon::parse('2026-11-14 23:30')));  // sábado
        $this->assertFalse(HorariosService::enFranja($f, Carbon::parse('2026-11-13 20:00')));
    }

    public function test_happy_hour_y_menu_del_dia_en_la_comanda(): void
    {
        $tragos = \App\Models\Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Tragos']);
        $fernet = $this->articulo(['name' => 'Fernet', 'price' => 4000, 'iva' => 0]); $fernet->forceFill(['rubro_id' => $tragos->id])->save();
        $pizza = $this->articulo(['name' => 'Pizza', 'price' => 9000, 'iva' => 0]);
        $menu = $this->articulo(['name' => 'Menú ejecutivo', 'price' => 8000, 'iva' => 0]);
        $ahora = now(); $desde = $ahora->copy()->subHour()->format('H:i'); $hasta = $ahora->copy()->addHour()->format('H:i');
        if ($desde > $hasta) $this->markTestSkipped('Cerca de la medianoche');
        $this->post('/gastronomia/happy-hours', ['happy_hours' => [['nombre' => 'Happy hour', 'activo' => true, 'pct' => 50, 'desde' => $desde, 'hasta' => $hasta, 'dias' => [], 'rubros' => [$tragos->id]]]])->assertSessionHas('success');
        $otroDia = $ahora->copy()->addDay()->isoWeekday();
        $this->post("/gastronomia/carta/{$menu->id}", ['va_cocina' => true, 'horario' => ['desde' => $desde, 'hasta' => $hasta, 'dias' => [$otroDia]]])->assertSessionHas('success');

        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '3', 'sector' => 'Barra', 'capacidad' => 2, 'activa' => true]);
        $this->post('/gastronomia/comandas', ['mesa_id' => $m->id]); $c = Comanda::latest('id')->first();
        $prods = collect($this->get("/gastronomia/comandas/{$c->id}")->viewData('page')['props']['productos'])->keyBy('name');
        $this->assertFalse($prods->has('Menú ejecutivo'), 'Hoy no se sirve');
        $this->assertEqualsWithDelta(2000, $prods['Fernet']['price'], 0.01);
        $this->assertEqualsWithDelta(9000, $prods['Pizza']['price'], 0.01, 'El happy hour es sólo de tragos');

        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $fernet->id, 'cantidad' => 2]);
        $it = $c->items()->first();
        $this->assertEqualsWithDelta(2000, (float) $it->precio_unit, 0.01);
        $this->assertStringContainsString('[Happy hour]', $it->descripcion);
    }

    public function test_estadisticas_de_gastronomia_y_bigcito(): void
    {
        $p = $this->articulo(['name' => 'Milanesa', 'price' => 7000, 'iva' => 0]);
        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '5', 'sector' => 'Salón', 'capacidad' => 4, 'activa' => true]);
        $this->post('/gastronomia/comandas', ['mesa_id' => $m->id, 'cubiertos' => 3]); $c = Comanda::latest('id')->first();
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $p->id, 'cantidad' => 3]);
        $this->post("/gastronomia/comandas/{$c->id}/enviar");
        $c->items()->update(['enviado_en' => now()->subMinutes(12), 'listo_en' => now(), 'estado' => 'listo']);
        $this->post("/gastronomia/comandas/{$c->id}/cerrar", ['propina' => 500, 'medios' => [['medio' => 'efectivo', 'monto' => (float) $c->fresh()->total + 500]]]);

        $props = $this->get('/estadisticas/gastronomia')->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $props['kpis']['comandas']);
        $this->assertEqualsWithDelta((float) $c->fresh()->total, $props['kpis']['ticket'], 0.01);
        $this->assertSame('Milanesa', $props['platos'][0]['plato']);
        $this->assertEqualsWithDelta(3, $props['platos'][0]['unidades'], 0.01);
        $this->assertSame(12, $props['cocina'][0]['promedio']);
        $this->assertEqualsWithDelta(500, $props['por_mozo'][0]['propinas'], 0.01);

        $r = app(Catalogo::class)->llamar($this->dueno, 'resumen_gastronomia', [], 'asistente');
        $this->assertSame(1, $r['kpis']['comandas'] ?? null);
    }
}
