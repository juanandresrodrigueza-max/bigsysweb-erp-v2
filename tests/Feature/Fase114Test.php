<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\PrecioHistorial;
use App\Services\Gastronomia\HistoriaPlatoService;
use Tests\ErpTestCase;

// Fase 114: historia del plato: costo de la receta, precio y margen mes a mes, con el insumo que explicó cada cambio.
class Fase114Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['verticales' => ['gastronomia']]);
    }

    private function armar(): array
    {
        $pavo = $this->articulo(['name' => 'Pavo', 'sku' => 'PAVO', 'unit' => 'kg', 'cost' => 9200, 'price' => 0, 'stock_inicial' => 10]);
        $pan = $this->articulo(['name' => 'Pan', 'sku' => 'PAN', 'unit' => 'un', 'cost' => 300, 'price' => 0, 'stock_inicial' => 50]);
        $sandwich = $this->articulo(['name' => 'Sándwich de pavo', 'sku' => 'SAND', 'price' => 8000, 'cost' => 0, 'stock_inicial' => 0, 'controla_stock' => false]);
        $sandwich->forceFill(['margen_objetivo' => 65])->save();
        $this->post("/gastronomia/carta/{$sandwich->id}/receta", ['items' => [['product_id' => $pavo->id, 'quantity' => 0.2], ['product_id' => $pan->id, 'quantity' => 1]]])->assertSessionHasNoErrors();
        // Historial: el pavo costaba 7.000 hasta hace 3 meses, subió a 8.000 hace 2 meses y a 9.200 el mes pasado; el precio subió de 7.000 a 8.000 hace 2 meses.
        PrecioHistorial::withoutGlobalScopes()->where('business_id', $this->empresa->id)->delete();
        PrecioHistorial::create(['business_id' => $this->empresa->id, 'product_id' => $pavo->id, 'fecha' => today()->subMonthsNoOverflow(2)->startOfMonth()->addDays(5)->toDateString(), 'costo' => 8000, 'costo_antes' => 7000, 'precio' => 0, 'precio_antes' => 0]);
        PrecioHistorial::create(['business_id' => $this->empresa->id, 'product_id' => $pavo->id, 'fecha' => today()->subMonthsNoOverflow(1)->startOfMonth()->addDays(3)->toDateString(), 'costo' => 9200, 'costo_antes' => 8000, 'precio' => 0, 'precio_antes' => 0]);
        PrecioHistorial::create(['business_id' => $this->empresa->id, 'product_id' => $sandwich->id, 'fecha' => today()->subMonthsNoOverflow(2)->startOfMonth()->addDays(10)->toDateString(), 'precio' => 8000, 'precio_antes' => 7000, 'costo' => 0, 'costo_antes' => 0]);
        return [$sandwich, $pavo, $pan];
    }

    public function test_reconstruye_costo_precio_y_margen_por_mes_y_explica_cada_salto(): void
    {
        [$sandwich] = $this->armar();
        $h = app(HistoriaPlatoService::class)->serie($sandwich->fresh(), 6);
        $this->assertTrue($h['con_receta']); $this->assertCount(6, $h['meses']);
        $m = collect($h['meses'])->keyBy('mes');
        $hace3 = today()->subMonthsNoOverflow(3)->format('Y-m'); $hace2 = today()->subMonthsNoOverflow(2)->format('Y-m'); $hace1 = today()->subMonthsNoOverflow(1)->format('Y-m'); $hoy = today()->format('Y-m');
        $this->assertEqualsWithDelta(0.2 * 7000 + 300, $m[$hace3]['costo'], 0.01, 'Antes del primer cambio vale lo de antes');
        $this->assertEqualsWithDelta(7000, $m[$hace3]['precio'], 0.01);
        $this->assertEqualsWithDelta(0.2 * 8000 + 300, $m[$hace2]['costo'], 0.01);
        $this->assertEqualsWithDelta(8000, $m[$hace2]['precio'], 0.01);
        $this->assertEqualsWithDelta(0.2 * 9200 + 300, $m[$hace1]['costo'], 0.01);
        $this->assertEqualsWithDelta($m[$hace1]['costo'], $m[$hoy]['costo'], 0.01);
        $this->assertSame('Pavo', $m[$hace2]['cambios'][0]['que']); $this->assertEqualsWithDelta(200, $m[$hace2]['cambios'][0]['impacto'], 0.01, '0,2 kg × 1.000 de suba');
        $this->assertSame(['de' => 7000.0, 'a' => 8000.0], $m[$hace2]['cambio_precio']);
        $this->assertEqualsWithDelta(240, $m[$hace1]['cambios'][0]['impacto'], 0.01);
        $this->assertSame([], $m[$hoy]['cambios']);
        $this->assertEqualsWithDelta(round((8000 - 2140) / 8000 * 100, 1), $h['hoy']['margen'], 0.01);
        $this->assertSame(65.0, $h['margen_objetivo']);
        $this->assertSame('Pavo', $h['que_mas_peso'][0]['insumo']); $this->assertEqualsWithDelta(440, $h['que_mas_peso'][0]['impacto'], 0.01);
        $this->assertStringContainsString('Pavo +$ 440', $h['resumen']);
    }

    public function test_endpoint_y_herramienta_de_bigcito(): void
    {
        [$sandwich] = $this->armar();
        $this->getJson("/gastronomia/carta/{$sandwich->id}/historia?meses=4")->assertOk()->assertJsonPath('plato', 'Sándwich de pavo')->assertJsonCount(4, 'meses')->assertJsonStructure(['resumen', 'hoy' => ['costo', 'precio', 'margen'], 'que_mas_peso']);
        $r = app(Catalogo::class)->llamar($this->dueno, 'historia_plato', ['plato' => 'pavo', 'meses' => 3], 'asistente');
        $this->assertSame('Sándwich de pavo', $r['plato'], 'Con «pavo» elige el plato con receta, no el insumo');
        $this->assertCount(3, $r['meses']);
        $lista = app(Catalogo::class)->llamar($this->dueno, 'historia_plato', [], 'asistente');
        $this->assertSame('Sándwich de pavo', $lista['platos_con_receta'][0]['plato']);
        $this->assertArrayHasKey('error', app(Catalogo::class)->llamar($this->dueno, 'historia_plato', ['plato' => 'Zzz'], 'asistente'));
    }

    public function test_sin_receta_cuenta_la_historia_del_costo_cargado(): void
    {
        $p = $this->articulo(['name' => 'Gaseosa', 'price' => 1500, 'cost' => 600, 'stock_inicial' => 10]);
        $h = app(HistoriaPlatoService::class)->serie($p, 3);
        $this->assertFalse($h['con_receta']); $this->assertEqualsWithDelta(600, $h['hoy']['costo'], 0.01); $this->assertEqualsWithDelta(60, $h['hoy']['margen'], 0.01);
        $this->assertStringContainsString('no tiene receta', $h['resumen']);
    }
}
