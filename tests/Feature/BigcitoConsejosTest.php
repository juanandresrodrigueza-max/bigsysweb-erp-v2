<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 40.1 y 40.2: proyecciones con método, deudas por antigüedad, a quién cobrar, quién dejó de comprar, resumen del día;
// y el panel recibe la tabla para Excel, el gráfico y las repreguntas.
class BigcitoConsejosTest extends ErpTestCase
{
    private function mcp(string $h, array $a = []): array { return app(Catalogo::class)->llamar($this->dueno, $h, $a, 'asistente'); }

    public function test_deudas_por_antiguedad_y_a_quien_cobrar_primero(): void
    {
        $viejo = $this->cliente(['name' => 'Moroso Viejo', 'phone' => '351111']); $nuevo = $this->cliente(['name' => 'Recién Vencido', 'cuit' => null]);
        $f1 = $this->factura($viejo, [['descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 1000, 'alicuota_iva' => 21]]); $f1->forceFill(['fecha_vto' => today()->subDays(100)])->save();
        $f2 = $this->factura($nuevo, [['descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 2000, 'alicuota_iva' => 21]]); $f2->forceFill(['fecha_vto' => today()->subDays(10)])->save();
        $r = $this->mcp('deudas_por_antiguedad');
        $t = collect($r['tramos'])->keyBy('tramo');
        $this->assertEquals(1210, $t['Más de 90 días']['monto']); $this->assertEquals(2420, $t['Vencido hasta 30 días']['monto']);
        $this->assertSame('barras', $r['_grafico']['tipo']); $this->assertCount(2, $r['_tabla']['filas']);
        $c = $this->mcp('a_quien_cobrar_primero');
        $this->assertSame(['Moroso Viejo', 'Recién Vencido'], array_column($c['clientes'], 'cliente')); // 1210 × 4,33 pesa más que 2420 × 1,33
        $this->assertStringContainsString('vencido', $c['criterio']);
    }

    public function test_proyeccion_dice_el_metodo_y_trae_grafico(): void
    {
        $cli = $this->cliente();
        foreach (range(1, 4) as $i) { $f = $this->factura($cli, [['descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 1000 * $i, 'alicuota_iva' => 21]]); $f->forceFill(['fecha' => today()->subMonthsNoOverflow($i)->startOfMonth()->addDays(3)])->save(); }
        $r = $this->mcp('proyeccion_ventas', ['meses' => 3]);
        $this->assertCount(3, $r['proximos']); $this->assertStringContainsString('Todavía no hay 12 meses', $r['metodo']);
        $this->assertCount(10, $r['_grafico']['valores']); // 6 meses + mes en curso + 3 proyectados
        $this->assertEqualsWithDelta((1210 * 3 + 1210 * 2 + 1210) / 3, $r['proximos'][0]['proyeccion'], 1);
        $this->assertArrayHasKey('dia', $this->mcp('resumen_del_dia'));
        $this->assertArrayHasKey('clientes', $this->mcp('quien_dejo_de_comprar'));
    }

    public function test_el_panel_recibe_tabla_grafico_y_repreguntas(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-ant-test', 'modelo' => 'claude-haiku-5-5']])->save(); $this->actingAs($this->dueno->fresh());
        $c = $this->cliente(['name' => 'Deudor']); $f = $this->factura($c, [['descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 500, 'alicuota_iva' => 21]]); $f->forceFill(['fecha_vto' => today()->subDays(40)])->save();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'deudas_por_antiguedad', 'input' => []]]])
            ->push(['content' => [['type' => 'text', 'text' => "Al día de hoy te deben \$605, todo de 31 a 60 días.\nSUGERENCIAS: ¿A quién le cobro primero? | Mandale un recordatorio a Deudor | ¿Cómo cierra el mes?"]]])]);
        $r = $this->postJson('/agente/chat', ['mensaje' => '¿cuánto me deben por antigüedad?'])->assertOk()->json();
        $this->assertSame('Al día de hoy te deben $605, todo de 31 a 60 días.', $r['respuesta']);
        $this->assertCount(3, $r['sugerencias']); $this->assertSame('barras', $r['grafico']['tipo']); $this->assertSame('deudas_por_antiguedad', $r['tabla']['nombre']);
        // al modelo no le llegan la tabla ni el gráfico
        Http::assertSent(fn($req) => str_contains($req->body(), 'tool_result') ? ! str_contains($req->body(), '_tabla') : true);
    }
}
