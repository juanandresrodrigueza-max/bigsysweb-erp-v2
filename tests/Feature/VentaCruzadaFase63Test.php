<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Services\Ventas\VentaCruzadaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 63: venta cruzada en Vender y presupuestos: lo que se llevó junto, lo que este cliente suele llevar, y la IA cuando no hay historia.
class VentaCruzadaFase63Test extends ErpTestCase
{
    private function venta($cli, array $prods): void
    {
        $this->factura($cli, array_map(fn($p) => ['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => (float) $p->price, 'alicuota_iva' => 21], $prods));
    }

    public function test_sugiere_lo_que_se_lleva_junto_y_lo_que_el_cliente_suele_llevar(): void
    {
        $cem = $this->articulo(['name' => 'Cemento x 50 kg', 'price' => 9000, 'stock_inicial' => 500]); $hie = $this->articulo(['name' => 'Hierro 8 mm', 'price' => 12000, 'stock_inicial' => 500]);
        $cal = $this->articulo(['name' => 'Cal x 25 kg', 'price' => 4000, 'stock_inicial' => 500]); $arena = $this->articulo(['name' => 'Arena m3', 'price' => 30000, 'stock_inicial' => 500]);
        $agot = $this->articulo(['name' => 'Malla sima', 'price' => 20000, 'stock_inicial' => 0]);
        $a = $this->cliente(['name' => 'Obra A']); $bcli = $this->cliente(['name' => 'Obra B']);
        for ($i = 0; $i < 5; $i++) $this->venta($a, [$cem, $hie, $agot]);
        for ($i = 0; $i < 3; $i++) $this->venta($bcli, [$cem, $cal]);
        for ($i = 0; $i < 3; $i++) $this->venta($bcli, [$arena]);
        Cache::flush();

        $s = app(VentaCruzadaService::class)->sugerir($this->empresa, [$cem->id]);
        $this->assertSame(['Hierro 8 mm', 'Cal x 25 kg'], array_column($s, 'nombre'), 'Por fuerza del par; la malla agotada no se ofrece');
        $this->assertSame(5, $s[0]['veces']); $this->assertSame('par', $s[0]['tipo']); $this->assertStringContainsString('Los que llevan Cemento x 50 kg también llevan esto (5 veces)', $s[0]['motivo']);

        // Con el cliente B: lo que él suele llevar (arena, 3 veces) va primero, y lo que ya está en la venta no se repite.
        $s = app(VentaCruzadaService::class)->sugerir($this->empresa, [$cem->id, $cal->id], $bcli->id);
        $this->assertSame('Arena m3', $s[0]['nombre']); $this->assertSame('cliente', $s[0]['tipo']); $this->assertStringContainsString('3 veces', $s[0]['motivo']);
        $this->assertNotContains('Cal x 25 kg', array_column($s, 'nombre'));

        // El endpoint de la factura/presupuesto trae el artículo en la forma del buscador; el del mostrador en la del POS.
        $r = $this->getJson("/buscar/sugeridos?ids={$cem->id},{$cal->id}&contact_id={$bcli->id}")->assertOk()->json();
        $this->assertSame('Arena m3', $r[0]['nombre']); $this->assertSame('Arena m3', $r[0]['producto']['name']); $this->assertArrayHasKey('precios', $r[0]['producto']);
        $r = $this->getJson("/vender/sugeridos?ids={$cem->id}")->assertOk()->json();
        $this->assertSame('Hierro 8 mm', $r[0]['nombre']); $this->assertSame('Hierro 8 mm', $r[0]['producto']['name']); $this->assertArrayHasKey('prices', $r[0]['producto']);

        // Bigcito
        $m = app(Catalogo::class)->llamar($this->dueno, 'que_ofrecer_con', ['articulos' => ['cemento', 'cal'], 'cliente' => 'Obra B'], 'asistente');
        $this->assertSame('Arena m3', $m['sugeridos'][0]['articulo']); $this->assertStringContainsString('seguido', $m['sugeridos'][0]['motivo']);
    }

    public function test_sin_historia_la_ia_elige_complementos_del_catalogo_y_sin_clave_no_sugiere_nada(): void
    {
        $taladro = $this->articulo(['name' => 'Taladro percutor 650 W', 'price' => 80000, 'stock_inicial' => 3]); $mechas = $this->articulo(['name' => 'Set de mechas widia', 'price' => 9000, 'stock_inicial' => 10]);
        $this->articulo(['name' => 'Taladro percutor 850 W', 'price' => 110000, 'stock_inicial' => 2]); $this->articulo(['name' => 'Tarugos 8 mm x 100', 'price' => 2500, 'stock_inicial' => 0]);
        $this->assertSame([], app(VentaCruzadaService::class)->sugerir($this->empresa, [$taladro->id]), 'Sin historia ni IA, nada');

        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['sugeridos' => [['id' => $mechas->id, 'motivo' => 'Sin mechas el taladro no sirve'], ['id' => 9999, 'motivo' => 'x']]])]]])]);
        $s = app(VentaCruzadaService::class)->sugerir($this->empresa->fresh(), [$taladro->id]);
        $this->assertCount(1, $s); $this->assertSame('Set de mechas widia', $s[0]['nombre']); $this->assertSame('ia', $s[0]['tipo']); $this->assertSame('Sin mechas el taladro no sirve', $s[0]['motivo']);
        $cat = Http::recorded()[0][0]->data()['messages'][0]['content'];
        $this->assertStringContainsString('Set de mechas', $cat); $this->assertStringNotContainsString('Tarugos', $cat, 'Lo agotado no se ofrece'); $this->assertStringNotContainsString('|Taladro percutor 650', $cat, 'Lo que ya lleva no está en el catálogo');
        app(VentaCruzadaService::class)->sugerir($this->empresa->fresh(), [$taladro->id]);
        Http::assertSentCount(1, 'La combinación queda cacheada');
    }
}
