<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Alerta;
use App\Models\Comanda;
use App\Models\Lote;
use App\Services\Gastronomia\MenuDelDiaIaService;
use App\Services\Gastronomia\PronosticoRestoService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 55: pronóstico por día de la semana → qué comprar por proveedor; menú del día con lo que vence; aviso de pico en cocina.
class BigRestoAdelantaFase55Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->forceFill(['vertical' => 'gastronomia'])->save();
    }

    // Una comanda cerrada en una fecha dada, con N unidades de un plato, enviada a cocina a cierta hora.
    private function venta($plato, float $n, \Carbon\Carbon $cuando, string $estacion = 'Cocina'): void
    {
        $c = Comanda::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'user_id' => $this->dueno->id, 'numero' => (Comanda::max('numero') ?? 0) + 1, 'tipo' => 'mostrador', 'estado' => 'cerrada', 'total' => 0, 'propina' => 0, 'descuento' => 0, 'cubiertos' => 1, 'abierta_en' => $cuando, 'cerrada_en' => $cuando]);
        $c->items()->create(['product_id' => $plato->id, 'descripcion' => $plato->name, 'cantidad' => $n, 'precio_unit' => 1000, 'alicuota_iva' => 0, 'va_cocina' => true, 'estacion' => $estacion, 'ronda' => 1, 'estado' => 'entregado', 'enviado_en' => $cuando, 'listo_en' => $cuando]);
    }

    public function test_pronostico_por_dia_de_semana_y_que_comprar_por_proveedor(): void
    {
        $prov = $this->proveedor(['name' => 'Molinos']);
        $harina = $this->articulo(['name' => 'Harina', 'tipo' => 'insumo', 'unit' => 'kg', 'cost' => 1000, 'stock_inicial' => 1]); $harina->forceFill(['proveedor_id' => $prov->id])->save();
        $pizza = $this->articulo(['name' => 'Pizza', 'price' => 9000, 'controla_stock' => false]);
        $this->post("/gastronomia/carta/{$pizza->id}/receta", ['items' => [['product_id' => $harina->id, 'quantity' => 0.3]]]);
        // Dos viernes con 10 pizzas y dos sábados con 20; los demás días cerrado
        foreach ([1, 2] as $w) { $this->venta($pizza, 10, now()->subWeeks($w)->next('friday')->subWeek()->setTime(21, 0)); $this->venta($pizza, 20, now()->subWeeks($w)->next('saturday')->subWeek()->setTime(21, 30)); }
        $p = app(PronosticoRestoService::class);
        $pd = $p->porDiaSemana($this->empresa)['platos'][$pizza->id];
        $this->assertEqualsWithDelta(10, $pd[5], 0.01); $this->assertEqualsWithDelta(20, $pd[6], 0.01); $this->assertArrayNotHasKey(1, $pd);
        $sem = $p->semana($this->empresa);
        $this->assertEqualsWithDelta(30, $sem['total'], 0.01, 'Un viernes y un sábado en los próximos 7 días');
        $comprar = $p->queComprar($this->empresa);
        $this->assertSame('Molinos', $comprar['proveedores'][0]['proveedor']);
        $it = $comprar['proveedores'][0]['items'][0];
        $this->assertEqualsWithDelta(9, $it['necesita'], 0.01, '30 pizzas × 0,3 kg'); $this->assertEqualsWithDelta(8, $it['falta'], 0.01); $this->assertEqualsWithDelta(8000, $comprar['total'], 0.01);
        $props = $this->get('/estadisticas/gastronomia')->assertOk()->viewData('page')['props'];
        $this->assertEqualsWithDelta(8000, $props['comprar']['total'], 0.01);
        $this->assertEqualsWithDelta(30, app(Catalogo::class)->llamar($this->dueno, 'que_comprar_resto', [], 'asistente')['semana']['total'], 0.01);
    }

    public function test_pico_de_cocina_la_proxima_hora(): void
    {
        $bife = $this->articulo(['name' => 'Bife', 'price' => 10000]);
        $h = now()->addHour();
        // Mismo día de la semana y hora, 3 semanas atrás: 12 bifes en Parrilla; en otras horas, 2
        foreach ([1, 2, 3] as $w) { $this->venta($bife, 12, $h->copy()->subWeeks($w), 'Parrilla'); $this->venta($bife, 2, $h->copy()->subWeeks($w)->subHours(3), 'Parrilla'); }
        $px = collect(app(PronosticoRestoService::class)->proximaHora($this->empresa))->firstWhere('estacion', 'Parrilla');
        $this->assertEqualsWithDelta(12, $px['esperado'], 0.1); $this->assertTrue($px['pico']);
        $this->assertSame(1, app(PronosticoRestoService::class)->alertarPicos($this->empresa));
        $this->assertSame(1, app(PronosticoRestoService::class)->alertarPicos($this->empresa), 'La misma hora no se avisa dos veces');
        $this->assertSame(1, Alerta::where('tipo', 'cocina_pico')->count());
        $this->assertTrue(collect($this->get('/gastronomia/cocina')->viewData('page')['props']['proximaHora'])->firstWhere('estacion', 'Parrilla')['pico']);
    }

    public function test_menu_del_dia_con_lo_que_vence(): void
    {
        $crema = $this->articulo(['name' => 'Crema', 'tipo' => 'insumo', 'unit' => 'l', 'cost' => 2000, 'stock_inicial' => 3]);
        Lote::create(['business_id' => $this->empresa->id, 'product_id' => $crema->id, 'deposito_id' => $this->deposito->id, 'lote' => 'L1', 'vencimiento' => today()->addDays(2), 'cantidad' => 3, 'costo_unit' => 2000, 'estado' => 'disponible', 'ingreso' => today()]);
        $noquis = $this->articulo(['name' => 'Ñoquis con crema', 'price' => 8000]); $noquis->forceFill(['en_tienda' => true])->save();
        $this->post("/gastronomia/carta/{$noquis->id}/receta", ['items' => [['product_id' => $crema->id, 'quantity' => 0.2]]]);
        $usar = app(MenuDelDiaIaService::class)->aUsar($this->empresa);
        $this->assertSame('Crema', $usar[0]['insumo']); $this->assertStringContainsString('vence', $usar[0]['motivo']); $this->assertSame(['Ñoquis con crema'], $usar[0]['platos']);
        // Sin clave: lista lo que conviene usar. Con clave: propone los menús.
        $this->assertSame([], $this->postJson('/gastronomia/carta/menu-del-dia')->assertOk()->json('menus'));
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['menus' => [['nombre' => 'Ejecutivo', 'platos' => ['Ñoquis con crema', 'Flan'], 'usa' => ['Crema'], 'precio_sugerido' => 9500, 'pizarra' => 'Ñoquis caseros con crema, $9.500']]])]]])]);
        $r = $this->postJson('/gastronomia/carta/menu-del-dia')->assertOk()->json();
        $this->assertSame('Ejecutivo', $r['menus'][0]['nombre']); $this->assertEqualsWithDelta(9500, $r['menus'][0]['precio_sugerido'], 0.01);
    }
}
