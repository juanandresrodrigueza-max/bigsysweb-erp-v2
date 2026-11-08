<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Empleado;
use App\Models\Liquidacion;
use App\Models\SueldoConcepto;
use App\Services\Sueldos\NovedadesTextoService;
use App\Services\Sueldos\SueldosService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 65: las novedades del mes dictadas o pegadas se vuelven la tabla de liquidación, y el recibo se le explica al empleado.
class NovedadesTextoFase65Test extends ErpTestCase
{
    private function empleado(string $nombre, int $legajo): Empleado
    {
        return Empleado::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'legajo' => $legajo, 'nombre' => $nombre, 'cuil' => '20-' . str_pad((string) (30000000 + $legajo), 8, '0', STR_PAD_LEFT) . '-3', 'categoria' => 'Vendedor B', 'fecha_ingreso' => '2022-03-01', 'sueldo_basico' => 900000, 'modalidad' => 'mensual', 'activo' => true, 'jornada' => 1]);
    }

    public function test_el_texto_se_vuelve_la_tabla_de_novedades_sin_ia(): void
    {
        $juan = $this->empleado('Juan Pérez', 1); $maria = $this->empleado('María Gómez', 2); $carlos = $this->empleado('Carlos Ruiz', 3); $pedro = $this->empleado('Pedro Díaz', 4); $ana = $this->empleado('Ana Sosa', 5);
        $texto = "Juan Pérez hizo 6 horas extra al 50 y 2 hs al 100, faltó 2 días.\nMaría tomó 7 días de vacaciones y tiene premio de \$ 20.000.\nCarlos: anticipo 15000, 1 feriado, viáticos 8.500.\nPedro no se liquida este mes, se fue.";
        $r = app(NovedadesTextoService::class)->interpretar($this->empresa, $texto);
        $this->assertTrue($r['ok']); $this->assertSame('palabras', $r['modo']);
        $n = $r['novedades'];
        $this->assertEquals(6, $n[$juan->id]['horas_extra_50']); $this->assertEquals(2, $n[$juan->id]['horas_extra_100']); $this->assertSame(28, $n[$juan->id]['dias']); $this->assertStringContainsString('2 falta', $n[$juan->id]['nota']);
        $this->assertSame(7, $n[$maria->id]['vacaciones']); $this->assertSame(23, $n[$maria->id]['dias']); $this->assertEquals(20000, $n[$maria->id]['adicionales']);
        $this->assertEquals(15000, $n[$carlos->id]['anticipos']); $this->assertSame(1, $n[$carlos->id]['feriados']); $this->assertSame(29, $n[$carlos->id]['dias']); $this->assertEquals(8500, $n[$carlos->id]['no_rem_extra']);
        $this->assertTrue($n[$pedro->id]['excluir']);
        $this->assertArrayNotHasKey($ana->id, $n, 'Ana no aparece en el texto: queda como está');
        $this->assertSame('Juan Pérez', $r['filas'][0]['empleado']);

        // El endpoint que usa el modal de Liquidar.
        $this->postJson('/sueldos/novedades-texto', ['texto' => 'Ana Sosa trabajó 20 días'])->assertOk()->assertJsonPath("novedades.{$ana->id}.dias", 20);
        $this->postJson('/sueldos/novedades-texto', ['texto' => 'el gato hizo horas extra'])->assertOk()->assertJsonPath('ok', false);
    }

    public function test_con_ia_lo_dudoso_lo_resuelve_la_ia_y_bigcito_liquida_desde_el_texto(): void
    {
        $juan = $this->empleado('Juan Pérez', 1); $maria = $this->empleado('María Gómez', 2);
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save(); $this->dueno->refresh();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['novedades' => [['empleado_id' => $juan->id, 'faltas' => 1, 'horas_extra_50' => 4], ['empleado_id' => $maria->id, 'vacaciones' => 14, 'dias' => null]], 'no_entendido' => ['el flaco nuevo cobra 10 lucas más']])]]])]);
        $r = app(Catalogo::class)->llamar($this->dueno, 'liquidar_sueldos', ['periodo' => '2026-10', 'novedades' => 'Juanchi faltó un día e hizo 4 extras; la Mari se tomó dos semanas; el flaco nuevo cobra 10 lucas más'], 'asistente');
        $this->assertStringContainsString('Juan Pérez', $r['detalle']); $this->assertStringContainsString('29 días', $r['detalle']); $this->assertStringContainsString('14 vac', $r['detalle']); $this->assertStringContainsString('el flaco nuevo', $r['detalle']);
        $this->assertStringContainsString('Juanchi', Http::recorded()[0][0]->data()['messages'][0]['content']);
        app(Catalogo::class)->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $r['propuesta_id']], 'asistente');
        $liq = Liquidacion::where('periodo', '2026-10')->first(); $this->assertNotNull($liq); $this->assertSame('borrador', $liq->estado);
        $itJuan = $liq->items->firstWhere('empleado_id', $juan->id); $this->assertSame(29, (int) $itJuan->dias); $this->assertEquals(4, (float) $itJuan->horas_extra_50);
        $this->assertSame(14, (int) $liq->items->firstWhere('empleado_id', $maria->id)->vacaciones);
    }

    public function test_el_recibo_se_explica_al_empleado_sin_y_con_ia(): void
    {
        $juan = $this->empleado('Juan Pérez', 1);
        $liq = app(SueldosService::class)->liquidar($this->empresa, '2026-10', 'mensual', [$juan->id => ['dias' => 30]]);
        $item = $liq->items->first(); $this->assertGreaterThan(0, (float) $item->neto);
        $e = app(NovedadesTextoService::class)->explicarRecibo($this->empresa, $item);
        $this->assertSame('basico', $e['modo']); $this->assertStringContainsString('Sueldo bruto', $e['texto']); $this->assertStringContainsString('jubilación', $e['texto']); $this->assertStringContainsString('Neto, lo que te depositan', $e['texto']);

        // El empleado lo ve desde el link del recibo, sin entrar al sistema.
        app(\App\Services\Sueldos\RecibosFirmaService::class)->url($item); $item->refresh();
        auth()->logout();
        $this->getJson("/recibo/{$item->token}/explicar")->assertOk()->assertJsonPath('modo', 'basico');
        $this->get("/recibo/{$item->token}")->assertOk()->assertSee('Qué significa cada renglón');

        // Con IA, un texto a medida.
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Juan, este mes ganaste tu sueldo completo. Te descontaron los aportes de siempre, que van a tu jubilación y tu obra social, y te depositan el neto.']]])]);
        $e = app(NovedadesTextoService::class)->explicarRecibo($this->empresa->fresh(), $item);
        $this->assertSame('ia', $e['modo']); $this->assertStringContainsString('Juan, este mes', $e['texto']);
        $this->assertStringContainsString('Neto', Http::recorded()[0][0]->data()['messages'][0]['content']);

        // Bigcito para el dueño: explicar el recibo de alguien para mandárselo.
        $this->actingAs($this->dueno->fresh());
        $m = app(Catalogo::class)->llamar($this->dueno->fresh(), 'explicar_recibo', ['empleado' => 'Juan', 'periodo' => '2026-10'], 'asistente');
        $this->assertStringContainsString('Juan, este mes', $m['explicacion']);
    }
}
