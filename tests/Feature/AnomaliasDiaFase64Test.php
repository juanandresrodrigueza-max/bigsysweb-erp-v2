<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\TurnoCaja;
use App\Services\Avisos\CelularService;
use App\Services\IA\AnomaliasDiaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 64: lo raro del día. El analista diario compara ayer con los mismos días de semana de las 8 semanas anteriores.
class AnomaliasDiaFase64Test extends ErpTestCase
{
    private function ventaEl(string $fecha, $cli, $prod, float $cant, float $precio): void
    {
        $this->factura($cli, [['product_id' => $prod->id, 'cantidad' => $cant, 'precio_unit' => $precio, 'alicuota_iva' => 21]], ['fecha' => $fecha]);
    }

    public function test_detecta_lo_que_se_salio_de_lo_normal_y_lo_cuenta_en_el_parte(): void
    {
        $ayer = today()->subDay(); $cli = $this->cliente(); $p = $this->articulo(['name' => 'Cemento x 50 kg', 'price' => 10000, 'cost' => 7000, 'stock_inicial' => 400, 'stock_min' => 50]);
        // 8 mismos días de semana anteriores: 3 facturas de 10.000 cada día (30.000, margen 30%).
        for ($i = 1; $i <= 8; $i++) for ($k = 0; $k < 3; $k++) $this->ventaEl($ayer->copy()->subWeeks($i)->toDateString(), $cli, $p, 1, 10000);
        // Ayer: 12 facturas, una de ellas regalada (precio 40% abajo de lista) y 150 unidades vendidas; caja con faltante.
        for ($k = 0; $k < 11; $k++) $this->ventaEl($ayer->toDateString(), $cli, $p, 13, 10000);
        $this->ventaEl($ayer->toDateString(), $cli, $p, 7, 6000);
        TurnoCaja::create(['business_id' => $this->empresa->id, 'cuenta_fondos_id' => $this->caja->id, 'user_id' => $this->dueno->id, 'apertura' => $ayer->copy()->setTime(8, 0), 'cierre' => $ayer->copy()->setTime(20, 0), 'saldo_inicial' => 0, 'saldo_esperado' => 50000, 'saldo_contado' => 42000, 'diferencia' => -8000]);
        Cache::flush();

        $r = app(AnomaliasDiaService::class)->anomalias($this->empresa, $ayer);
        $this->assertSame(8, $r['base_dias']);
        $cats = collect($r['anomalias']);
        $ventas = $cats->firstWhere('cat', 'ventas'); $this->assertNotNull($ventas); $this->assertStringContainsString('% más que un', $ventas['titulo']); $this->assertSame(36300, (int) $ventas['esperado']); // 3 × 10.000 + IVA
        $caja = $cats->firstWhere('cat', 'caja'); $this->assertStringContainsString('faltante de $ 8.000', $caja['titulo']); $this->assertSame('critica', $caja['sev']);
        $this->assertSame('critica', $r['anomalias'][0]['sev'], 'Lo crítico va primero');
        $volo = $cats->first(fn($x) => str_contains($x['titulo'], 'voló')); $this->assertNotNull($volo); $this->assertStringContainsString('Cemento x 50 kg voló: 150 unidades (normal: 3)', $volo['titulo']);
        $barato = $cats->first(fn($x) => str_contains($x['titulo'], 'abajo de la lista')); $this->assertNotNull($barato); $this->assertStringContainsString('a $ 6.000 (lista $ 10.000)', $barato['detalle']);
        $this->assertNull($cats->firstWhere('cat', 'margen'), 'El margen del día (28%) no se alejó más de 8 puntos del normal (30%)');

        // En palabras, sin IA.
        $t = app(AnomaliasDiaService::class)->texto($this->empresa, $r);
        $this->assertSame('basico', $t['modo']); $this->assertStringContainsString('Lo raro del', $t['texto']); $this->assertStringContainsString('faltante', $t['texto']);

        // El parte de la mañana lo trae (hasta 3 renglones) y Estadísticas → Analista también.
        $parte = app(CelularService::class)->parte($this->empresa);
        $this->assertStringContainsString('Lo raro de ayer', $parte['texto']); $this->assertStringContainsString('🔴 Caja de', $parte['texto']);
        $bloque = trim(explode("\n\n", explode('Lo raro de ayer*', $parte['texto'])[1])[0]); $this->assertLessThanOrEqual(3, substr_count($bloque, "\n") + 1, 'Hasta 3 renglones en el parte');
        $props = $this->get('/estadisticas/analista')->assertOk()->viewData('page')['props'];
        $this->assertSame($ayer->toDateString(), $props['ayer']['fecha']); $this->assertNotEmpty($props['ayer']['anomalias']); $this->assertStringContainsString('Lo raro del', $props['ayer']['texto']['texto']);

        // Bigcito
        $m = app(Catalogo::class)->llamar($this->dueno, 'anomalias_del_dia', ['dia' => 'ayer'], 'asistente');
        $this->assertSame($ayer->toDateString(), $m['fecha']); $this->assertGreaterThanOrEqual(4, count($m['anomalias']));

        // Con IA: un párrafo redactado.
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Ayer vendiste el cuádruple de lo normal, casi todo cemento; ojo que quedás justo de stock y la caja de la tarde cerró con 8.000 menos.']]])]);
        $t = app(AnomaliasDiaService::class)->texto($this->empresa->fresh(), $r);
        $this->assertSame('ia', $t['modo']); $this->assertStringContainsString('cuádruple', $t['texto']);
        $this->assertStringContainsString('faltante', Http::recorded()[0][0]->data()['messages'][0]['content']);
    }

    public function test_un_dia_normal_no_dice_nada_y_sin_historia_avisa_que_espera(): void
    {
        $ayer = today()->subDay(); $cli = $this->cliente(); $p = $this->articulo(['price' => 10000, 'stock_inicial' => 100]);
        $r = app(AnomaliasDiaService::class)->anomalias($this->empresa, $ayer);
        $this->assertSame([], $r['anomalias']); $this->assertStringContainsString('Todavía no hay suficientes', app(AnomaliasDiaService::class)->texto($this->empresa, $r)['texto']);
        $this->assertNull(app(AnomaliasDiaService::class)->paraElParte($this->empresa, $ayer));
        for ($i = 0; $i <= 8; $i++) for ($k = 0; $k < 3; $k++) $this->ventaEl($ayer->copy()->subWeeks($i)->toDateString(), $cli, $p, 1, 10000);
        Cache::flush();
        $r = app(AnomaliasDiaService::class)->anomalias($this->empresa, $ayer);
        $this->assertSame([], $r['anomalias']); $this->assertStringContainsString('Nada raro', app(AnomaliasDiaService::class)->texto($this->empresa, $r)['texto']);
        $this->assertStringNotContainsString('Lo raro de ayer', app(CelularService::class)->parte($this->empresa)['texto']);
    }
}
