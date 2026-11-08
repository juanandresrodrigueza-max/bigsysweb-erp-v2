<?php

namespace Tests\Feature;

use App\Models\Cobro;
use App\Models\ExtractoItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Transferencias de clientes → recibo: desde el extracto (lo que está en el banco y no en el sistema) o desde la foto del comprobante.
// El cliente se reconoce sólo por CUIT, DNI o el nombre completo; nunca por parecido.
class TransferenciasClientesTest extends ErpTestCase
{
    private function facturaA($cli, float $precio)
    {
        return $this->factura($cli, [['product_id' => $this->articulo()->id, 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => $precio, 'alicuota_iva' => 21]]);
    }

    public function test_extracto_reconoce_al_cliente_hace_el_recibo_y_lo_concilia(): void
    {
        $cli = $this->cliente(['name' => 'Corralón del Sur SRL', 'cuit' => '30-71234567-1']);
        $otro = $this->cliente(['name' => 'Corralón del Norte SRL', 'cuit' => '30-70000000-7']);
        $f1 = $this->facturaA($cli, 1000); $f2 = $this->facturaA($cli, 2000);
        $csv = "Fecha;Concepto;Importe;Saldo\n05/11/2026;TRANSF RECIBIDA CUIT 30712345671 CORRALON DEL SUR;3630,00;3630,00\n05/11/2026;TRANSF RECIBIDA CORRALON;500,00;4130,00\n";
        $this->post('/contable/conciliacion/importar', ['cuenta_fondos_id' => $this->banco->id, 'archivo' => UploadedFile::fake()->createWithContent('banco.csv', $csv)])->assertSessionHasNoErrors();
        $props = $this->get('/contable/conciliacion?cuenta=' . $this->banco->id)->assertOk()->viewData('page')['props'];
        $it = ExtractoItem::where("monto", 3630)->first(); $raro = ExtractoItem::where('monto', 500)->first();
        $this->assertSame($cli->id, $props['deClientes'][$it->id]['contact_id']);
        $this->assertSame('CUIT', $props['deClientes'][$it->id]['por']);
        $this->assertArrayNotHasKey($raro->id, $props['deClientes'], 'Con sólo «Corralón» no adivina');

        $this->post("/contable/conciliacion/{$it->id}/recibo", ['contact_id' => $cli->id])->assertSessionHas('success');
        $this->assertSame('conciliado', $it->fresh()->estado);
        $this->assertEqualsWithDelta(0, (float) $f1->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(0, (float) $f2->fresh()->saldo, 0.01, 'Cancela de la más vieja a la más nueva');
        $this->assertEqualsWithDelta(3630, (float) $this->banco->fresh()->saldo, 0.01);
        $this->post("/contable/conciliacion/{$it->id}/recibo", ['contact_id' => $cli->id])->assertStatus(422);
        $this->assertSame(1, Cobro::count());
        $this->assertAsientosBalancean();
    }

    public function test_la_foto_del_comprobante_propone_el_recibo_y_despues_el_extracto_cruza_solo(): void
    {
        $cli = $this->cliente(['name' => 'Juan Pérez', 'cuit' => '20-30111222-9']);
        $f = $this->facturaA($cli, 10000);
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['monto' => 12100, 'fecha' => '2026-11-06', 'ordenante_nombre' => 'PEREZ JUAN', 'ordenante_cuit' => '20-30111222-9', 'numero_operacion' => 'OP99881', 'banco' => 'Galicia'])]]])]);

        $r = $this->post('/clientes/cobranzas/leer-transferencia', ['archivo' => UploadedFile::fake()->image('transf.jpg')])->assertOk()->json();
        $this->assertTrue($r['ok']); $this->assertSame($cli->id, $r['contact_id']); $this->assertSame('CUIT', $r['por']);
        $this->assertSame($f->id, $r['imputaciones'][0]['comprobante_id']);

        $this->post('/clientes/cobranzas/desde-transferencia', ['contact_id' => $cli->id, 'monto' => 12100, 'fecha' => '2026-11-06', 'cuenta_fondos_id' => $this->banco->id, 'referencia' => 'OP99881'])->assertSessionHas('success');
        $this->assertEqualsWithDelta(0, (float) $f->fresh()->saldo, 0.01);
        $this->assertTrue($this->post('/clientes/cobranzas/leer-transferencia', ['archivo' => UploadedFile::fake()->image('transf.jpg')])->json('repetido'), 'Avisa si ya se cargó');

        // Después llega el extracto: el renglón se concilia solo con ese recibo
        $csv = "Fecha;Concepto;Importe;Saldo\n07/11/2026;TRANSF PEREZ JUAN;12100,00;12100,00\n";
        $this->post('/contable/conciliacion/importar', ['cuenta_fondos_id' => $this->banco->id, 'archivo' => UploadedFile::fake()->createWithContent('b.csv', $csv)]);
        $this->post('/contable/conciliacion/automatica', ['cuenta_fondos_id' => $this->banco->id, 'cuenta' => $this->banco->id]);
        $this->assertSame('conciliado', ExtractoItem::first()->fresh()->estado);
    }

    public function test_sin_clave_de_ia_avisa_y_no_inventa(): void
    {
        $r = $this->post('/clientes/cobranzas/leer-transferencia', ['archivo' => UploadedFile::fake()->image('t.jpg')])->assertOk()->json();
        $this->assertFalse($r['ok']); $this->assertStringContainsString('clave de IA', $r['aviso']);
    }
}
