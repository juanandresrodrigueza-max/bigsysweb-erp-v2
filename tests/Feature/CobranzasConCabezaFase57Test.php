<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Comprobante;
use App\Models\Envio;
use App\Services\Comprobantes\CobroService;
use App\Services\Ventas\PlanSugeridoService;
use App\Services\Ventas\RiesgoMoraService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 57: riesgo de mora explicable, para llamar antes de que venza, recordatorio escrito a medida y plan de pago propuesto.
class CobranzasConCabezaFase57Test extends ErpTestCase
{
    // Una factura emitida en $fecha con vencimiento a 30 días, cobrada (o no) $atraso días después del vencimiento.
    private function facturaCobrada($cli, float $importe, Carbon $fecha, ?int $atraso): Comprobante
    {
        Carbon::setTestNow($fecha);
        $f = $this->factura($cli, [['product_id' => $this->articulo()->id, 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => $importe, 'alicuota_iva' => 0]], ['fecha_vto' => $fecha->copy()->addDays(30)->toDateString()]);
        if ($atraso !== null) { Carbon::setTestNow($fecha->copy()->addDays(30 + $atraso)); app(CobroService::class)->registrar($cli, ['fecha' => now()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => (float) $f->total]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => (float) $f->total]]]); }
        Carbon::setTestNow(); return $f;
    }

    public function test_riesgo_explicable_y_para_llamar_antes(): void
    {
        $bueno = $this->cliente(['name' => 'Puntual SA', 'phone' => '351111']); $malo = $this->cliente(['name' => 'Tardón SRL', 'phone' => '351222', 'interes_mora' => 3]);
        foreach ([5, 4, 3] as $m) { $this->facturaCobrada($bueno, 10000, today()->subMonths($m), 0); $this->facturaCobrada($malo, 10000, today()->subMonths($m), 20 + (5 - $m) * 10); }
        // Una vencida hace 40 días para el tardón y una que vence en 5 días para los dos
        $this->facturaCobrada($malo, 20000, today()->subDays(70), null);
        foreach ([$bueno, $malo] as $c) $this->facturaCobrada($c, 5000, today()->subDays(25), null);
        $r = app(RiesgoMoraService::class);
        $rb = $r->de($bueno); $rm = $r->de($malo);
        $this->assertSame('bajo', $rb['nivel']); $this->assertContains('Suele pagar a tiempo', $rb['motivos']);
        $this->assertSame('alto', $rm['nivel']); $this->assertTrue(collect($rm['motivos'])->contains(fn($x) => str_contains($x, 'después del vencimiento'))); $this->assertTrue(collect($rm['motivos'])->contains(fn($x) => str_contains($x, 'empeorando')));
        $antes = $r->paraLlamarAntes($this->empresa);
        $this->assertCount(1, $antes); $this->assertSame('Tardón SRL', $antes[0]['nombre']); $this->assertEqualsWithDelta(5000, $antes[0]['importe'], 0.01);
        $props = $this->get('/clientes/cobranzas')->assertOk()->viewData('page')['props'];
        $this->assertSame('alto', collect($props['deudores'])->firstWhere('nombre', 'Tardón SRL')['riesgo']['nivel']); $this->assertCount(1, $props['llamarAntes']);
        $mcp = app(Catalogo::class)->llamar($this->dueno, 'riesgo_de_mora', ['cliente' => 'Tardón'], 'asistente');
        $this->assertSame('alto', $mcp['nivel']);
    }

    public function test_recordatorio_a_medida_y_plan_propuesto(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5'], 'recordatorios' => ['activo' => false, 'ia' => true, 'dias' => [0], 'canales' => ['whatsapp'], 'texto' => 'Plantilla {cliente} {link}']])->save();
        $cli = $this->cliente(['name' => 'Tardón SRL', 'mobile' => '3515550000', 'interes_mora' => 2]);
        $f1 = $this->facturaCobrada($cli, 30000, today()->subDays(80), null); $f2 = $this->facturaCobrada($cli, 30000, today()->subDays(50), null);
        foreach ([1, 2, 3] as $m) $this->facturaCobrada($cli, 40000, today()->subMonths($m + 2), 0); // le compra ~40.000 por mes y antes pagaba bien
        $link = $f1->urlPublica();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => "Hola Tardón SRL, tenés dos facturas vencidas por $ 60.000. Necesitamos una fecha de pago esta semana. Si te complica, armamos un plan en cuotas. Vela acá: {$link}"]]])
            ->push(['content' => [['type' => 'text', 'text' => 'Hola Tardón SRL, para ponerte al día te proponemos $ 60.000 en 4 cuotas de $ 15.600, la primera el ' . today()->addDays(20)->format('d/m') . '. ¿Te sirve?']]])]);
        // Recordatorio: la IA lo escribe para este cliente, con el link de pago
        $this->post("/clientes/cobranzas/{$cli->id}/recordar", ['canal' => 'whatsapp'])->assertSessionHas('success');
        $e = Envio::where('tipo', 'recordatorio')->latest('id')->first();
        $this->assertStringContainsString('dos facturas vencidas', $e->cuerpo ?? $e->mensaje ?? json_encode($e->toArray()));
        // Plan sugerido: 60.000 de deuda; facturó 180.000 en 6 meses → 30.000/mes → cuota ~15.000 → 4 cuotas; interés 2 % × 4/2 = 4 %
        $s = app(PlanSugeridoService::class)->sugerir($this->empresa, $cli);
        $this->assertTrue($s['ok']); $this->assertSame(4, $s['cuotas']); $this->assertEqualsWithDelta(4.0, $s['interes_pct'], 0.01); $this->assertEqualsWithDelta(60000, $s['deuda'], 0.01);
        $this->assertEqualsCanonicalizing([$f1->id, $f2->id], $s['comprobantes']); $this->assertStringContainsString('4 cuotas', $s['texto']);
        $this->getJson("/clientes/cobranzas/{$cli->id}/plan-sugerido")->assertOk()->assertJsonPath('cuotas', 4);
        $this->post("/clientes/cobranzas/{$cli->id}/proponer-plan", ['canal' => 'whatsapp', 'texto' => $s['texto']])->assertSessionHas('success');
        $this->assertSame(2, Envio::where('contact_id', $cli->id)->count());
        // Sin IA, vuelve la plantilla
        $this->empresa->forceFill(['recordatorios' => ['activo' => false, 'ia' => false, 'dias' => [0], 'canales' => ['whatsapp'], 'texto' => 'Plantilla {cliente} {link}']])->save();
        $this->post("/clientes/cobranzas/{$cli->id}/recordar", ['canal' => 'whatsapp']);
        $u = Envio::where('tipo', 'recordatorio')->latest('id')->first(); $this->assertStringContainsString('Plantilla Tardón SRL', json_encode($u->toArray(), JSON_UNESCAPED_UNICODE));
    }
}
