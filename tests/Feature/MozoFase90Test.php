<?php

namespace Tests\Feature;

use App\Models\Comanda;
use App\Models\Mesa;
use App\Services\Gastronomia\CalorSalonService;
use Tests\ErpTestCase;

// Fase 90: el mozo de bolsillo (pantalla de celular) y el mapa de calor de mesas.
class MozoFase90Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->forceFill(['vertical' => 'gastronomia'])->save();
    }

    private function mesa(string $nombre, string $sector = 'Salón'): Mesa
    {
        return Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => $nombre, 'sector' => $sector, 'capacidad' => 4, 'activa' => true]);
    }

    public function test_el_mozo_ve_sus_mesas_lo_listo_para_llevar_y_las_libres(): void
    {
        $m1 = $this->mesa('1'); $m2 = $this->mesa('2'); $m3 = $this->mesa('3', 'Terraza');
        $cafe = $this->articulo(['name' => 'Café', 'price' => 1000, 'iva' => 0]);
        $this->post('/gastronomia/comandas', ['mesa_id' => $m1->id, 'cubiertos' => 2])->assertSessionHasNoErrors();
        $mia = Comanda::latest('id')->first();
        $this->post("/gastronomia/comandas/{$mia->id}/items", ['product_id' => $cafe->id, 'asiento' => 1]);
        $this->post("/gastronomia/comandas/{$mia->id}/items", ['product_id' => $cafe->id]);
        $this->post("/gastronomia/comandas/{$mia->id}/enviar");
        $it = $mia->items()->first(); $this->post("/gastronomia/items/{$it->id}/estado", ['estado' => 'listo']);
        // Otro mozo abre la 2.
        $otro = $this->usuarioConRol('mozo', 'mozo2@test.com');
        $this->actingAs($otro)->post('/gastronomia/comandas', ['mesa_id' => $m2->id, 'cubiertos' => 3])->assertSessionHasNoErrors();
        $p = $this->actingAs($this->dueno)->get('/gastronomia/mozo')->assertOk()->viewData('page')['props'];
        $this->assertSame('Gastronomia/Mozo', $this->get('/gastronomia/mozo')->viewData('page')['component']);
        $this->assertCount(1, $p['mias']); $this->assertSame('1', $p['mias'][0]['mesa']); $this->assertSame(1, $p['mias'][0]['listos']); $this->assertSame(1, $p['mias'][0]['en_cocina']);
        $this->assertSame(1, $p['mias'][0]['para_llevar'][0]['asiento']);
        $this->assertCount(1, $p['otras']); $this->assertSame('2', $p['otras'][0]['mesa']);
        // El mozo ve lo suyo primero; el de cocina no abre mesas.
        $pm = $this->actingAs($otro)->get('/gastronomia/mozo')->assertOk()->viewData('page')['props']; $this->assertSame('2', $pm['mias'][0]['mesa']); $this->assertCount(1, $pm['otras']);
        $this->actingAs($this->usuarioConRol('cocina', 'cocina@test.com'))->post('/gastronomia/comandas', ['mesa_id' => $m3->id, 'cubiertos' => 1])->assertStatus(403);
        $this->actingAs($this->dueno);
        $this->assertSame(['3'], array_column($p['libres'], 'nombre'));
        $this->assertContains('mozo', array_column($p['subnav']['items'], 'key'), 'Mozo de bolsillo es una sección de Big Restó');
        // Entregar desde el bolsillo.
        $this->post("/gastronomia/items/{$it->id}/estado", ['estado' => 'entregado'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->get('/gastronomia/mozo')->viewData('page')['props']['mias'][0]['listos']);
    }

    public function test_el_mapa_de_calor_junta_por_mesa_y_por_franja(): void
    {
        $m1 = $this->mesa('1'); $m2 = $this->mesa('2'); $this->mesa('9', 'Terraza');
        $cerrada = fn(Mesa $m, float $total, string $hora, int $cub = 2) => Comanda::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'mesa_id' => $m->id, 'user_id' => $this->dueno->id, 'numero' => rand(1, 99999), 'tipo' => 'mesa', 'estado' => 'cerrada', 'cubiertos' => $cub, 'total' => $total,
            'abierta_en' => today()->subDays(2)->setTimeFromTimeString($hora), 'cerrada_en' => today()->subDays(2)->setTimeFromTimeString($hora)->addMinutes(50)]);
        $cerrada($m1, 10000, '13:00'); $cerrada($m1, 6000, '21:30', 4); $cerrada($m2, 4000, '09:15');
        $cerrada($m2, 99999, '13:00'); Comanda::latest('id')->first()->update(['cerrada_en' => now()->subDays(40)]); // fuera del período
        $d = app(CalorSalonService::class)->datos($this->sucursal->id, 30);
        $mesas = collect($d['mesas'])->keyBy('nombre');
        $this->assertEqualsWithDelta(16000, $mesas['1']['total'], 0.01); $this->assertSame(2, $mesas['1']['comandas']); $this->assertSame(1.0, (float) $mesas['1']['calor']); $this->assertSame(50, $mesas['1']['minutos']); $this->assertSame(6, $mesas['1']['cubiertos']);
        $this->assertEqualsWithDelta(4000, $mesas['2']['total'], 0.01); $this->assertSame(0.25, (float) $mesas['2']['calor']);
        $this->assertSame(0, $mesas['9']['comandas']); $this->assertSame(1, $d['sin_uso']);
        $this->assertSame('1', $d['top'][0]['nombre']); $this->assertSame('9', $d['frias'][0]['nombre']);
        $fr = collect($d['franjas'])->keyBy('franja');
        $this->assertSame(1, $fr['desayuno']['comandas']); $this->assertSame(1, $fr['almuerzo']['comandas']); $this->assertEqualsWithDelta(6000, $fr['cena']['total'], 0.01); $this->assertSame(0, $fr['merienda']['comandas']);
        // La pantalla la trae sólo cuando se pide (prop perezosa).
        $this->assertArrayNotHasKey('calor', $this->get('/gastronomia')->assertOk()->viewData('page')['props']);
        $p = $this->get('/gastronomia', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Gastronomia/Mesas', 'X-Inertia-Partial-Data' => 'calor', 'X-Inertia-Version' => $this->get('/gastronomia')->viewData('page')['version']])->assertOk()->json('props');
        $this->assertSame(30, $p['calor']['dias']); $this->assertCount(3, $p['calor']['mesas']);
        $this->assertStringContainsString("'mapa_de_calor_mesas'", file_get_contents(app_path('Mcp/Modulos/Gestion.php')));
        $this->assertStringContainsString('Mozo de bolsillo', file_get_contents(base_path('database/migrations/2027_01_13_000001_fase90_novedad_mozo.php')));
    }
}
