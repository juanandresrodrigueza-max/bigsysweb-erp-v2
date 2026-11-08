<?php

namespace Tests\Feature;

use App\Mail\DocumentoMail;
use App\Mcp\Catalogo;
use App\Models\MovimientoFondos;
use App\Services\Contabilidad\InformeContadorService;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\ErpTestCase;

// Fase 66: el informe del mes para el contador en castellano: qué pasó, qué cambió y qué revisar. En "Enviar a la empresa" y en Bigcito.
class InformeContadorFase66Test extends ErpTestCase
{
    public function test_el_informe_cuenta_el_mes_lo_que_cambio_y_que_revisar(): void
    {
        $mes = today()->subMonth()->format('Y-m'); $ini = today()->subMonth()->startOfMonth(); $ant = $ini->copy()->subMonth();
        $cli = $this->cliente(); $p = $this->articulo(['price' => 10000, 'stock_inicial' => 100]);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 10000, 'alicuota_iva' => 21]], ['fecha' => $ini->copy()->addDays(3)->toDateString()]);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 5, 'precio_unit' => 10000, 'alicuota_iva' => 21]], ['fecha' => $ant->copy()->addDays(3)->toDateString()]);
        $this->post('/fondos/movimiento', ['cuenta_fondos_id' => $this->caja->id, 'fecha' => $ini->copy()->addDays(5)->toDateString(), 'tipo' => 'egreso', 'monto' => 30000, 'concepto' => 'Luz Edenor'])->assertSessionHasNoErrors(); // cae solo en Luz (Fase 58)
        app(FondosService::class)->registrar($this->caja, ['fecha' => $ini->copy()->addDays(6)->toDateString(), 'origen' => 'gasto', 'concepto' => 'Cosa rara', 'egreso' => 5000]);

        $d = app(InformeContadorService::class)->datos($this->empresa, $mes);
        $this->assertEquals(121000, $d['actual']['ventas']); $this->assertEquals(60500, $d['anterior']['ventas']); $this->assertEquals(100, $d['variaciones']['ventas']);
        $this->assertEquals(21000, $d['iva']['debito']); $this->assertEquals(21000, $d['iva']['saldo']); $this->assertEquals(35000, $d['actual']['gastos']);
        $this->assertStringContainsString('No hay compras cargadas', implode(' ', $d['revisar']));
        $this->assertTrue(collect($d['revisar'])->contains(fn($x) => str_contains($x, 'Gastos sin categoría')), 'Cosa rara quedó sin categoría');
        $this->assertSame('Luz', $d['gastos_por_categoria'][0]['categoria'], 'Edenor cayó solo en Luz (Fase 58)');

        // En palabras, con reglas.
        $r = app(InformeContadorService::class)->redactar($this->empresa, $d);
        $this->assertSame('basico', $r['modo']); $this->assertStringContainsString('Ventas: $ 121.000 en 1 comprobantes (+100%', $r['texto']); $this->assertStringContainsString('a pagar $ 21.000', $r['texto']); $this->assertStringContainsString('Para revisar antes de liquidar', $r['texto']);

        // Vista previa y envío desde Mis empresas: el informe va en el mail y en el PDF.
        $this->getJson("/contador/empresas/{$this->empresa->id}/informe?mes={$mes}")->assertOk()->assertJsonPath('modo', 'basico')->assertJsonPath('datos.actual.ventas', 121000);
        Mail::fake();
        $this->post("/contador/empresas/{$this->empresa->id}/enviar", ['email' => 'dueno@empresa.com', 'formato' => 'resumen', 'mes' => $mes, 'informe' => true])->assertSessionHas('success');
        Mail::assertSent(DocumentoMail::class, fn($m) => str_contains($m->cuerpo ?? json_encode(get_object_vars($m)), 'Para revisar antes de liquidar'));

        // Con IA, redactado.
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save(); $this->dueno->refresh();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "Buen mes: duplicaste las ventas contra el anterior. El IVA te da a pagar unos $ 21.000.\n\nQué conviene revisar: no hay compras cargadas."]]])]);
        $r = app(InformeContadorService::class)->redactar($this->empresa->fresh(), $d);
        $this->assertSame('ia', $r['modo']); $this->assertStringContainsString('duplicaste', $r['texto']);
        $this->assertStringContainsString('No hay compras cargadas', Http::recorded()[0][0]->data()['messages'][0]['content']);

        // Bigcito
        $m = app(Catalogo::class)->llamar($this->dueno->fresh(), 'informe_mensual_contador', ['mes' => $mes], 'asistente');
        $this->assertStringContainsString('duplicaste', $m['informe']); $this->assertEquals(121000, $m['ventas']);
    }
}
