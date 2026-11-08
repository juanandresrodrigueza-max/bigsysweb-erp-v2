<?php

namespace Tests\Feature;

use App\Models\Cobro;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 69 (lado ERP): la cuenta del cliente en vivo para el CRM y el cobro registrado desde el CRM.
class CrmCuentaCobroFase69Test extends ErpTestCase
{
    public function test_el_crm_lee_saldo_vencido_limite_y_riesgo_y_cobra_desde_su_pantalla(): void
    {
        Http::fake(); // sin CRM activo no sale ningún aviso; igual no queremos red
        $cli = $this->cliente(['name' => 'López SRL', 'credit_limit' => 100000, 'dias_pago' => 30]); $p = $this->articulo(['price' => 10000, 'stock_inicial' => 50]);
        $f1 = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 5, 'precio_unit' => 10000, 'alicuota_iva' => 21]], ['fecha' => today()->subDays(45)->toDateString()]);
        $f1->forceFill(['fecha_vto' => today()->subDays(15)->toDateString()])->save();
        $f2 = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 10000, 'alicuota_iva' => 21]]);
        $token = $this->dueno->createToken('api')->plainTextToken; auth()->logout();

        $r = $this->withToken($token)->getJson("/api/contacts/{$cli->id}/cuenta")->assertOk()->json('data');
        $this->assertEquals(84700, $r['saldo']); $this->assertEquals(60500, $r['vencido']); $this->assertEquals(24200, $r['a_vencer']);
        $this->assertEquals(100000, $r['limite_credito']); $this->assertEquals(15300, $r['disponible']); $this->assertFalse($r['excede_limite']);
        $this->assertCount(2, $r['pendientes']); $this->assertTrue($r['pendientes'][0]['vencida']); $this->assertSame(15, $r['pendientes'][0]['dias_vencida']); $this->assertSame($f1->numeroFormateado(), $r['pendientes'][0]['numero']);
        $this->assertNotNull($r['riesgo']['puntaje']); $this->assertArrayHasKey('efectivo', $r['medios_de_cobro']); $this->assertNull($r['ultimo_cobro']);

        // Cobra desde el CRM: transferencia al banco, imputada a la vencida.
        $c = $this->withToken($token)->postJson("/api/contacts/{$cli->id}/cobros", ['medios' => [['medio' => 'transferencia', 'monto' => 60500, 'cuenta_fondos_id' => $this->banco->id, 'referencia' => 'TRF 998877']], 'imputaciones' => [['comprobante_id' => $f1->id, 'monto' => 60500]], 'crm_ref' => 'deal 77'])
            ->assertStatus(201)->json('data');
        $this->assertEquals(60500, $c['total']); $this->assertEquals(24200, $c['saldo_cliente']); $this->assertStringContainsString("/clientes/cobros/{$c['id']}/imprimir", $c['url_imprimir']);
        $cobro = Cobro::withoutGlobalScopes()->find($c['id']); $this->assertStringContainsString('Cobrado desde el CRM (deal 77)', $cobro->notas); $this->assertSame('crm', $cobro->modo);
        $this->assertEquals(0, (float) $f1->fresh()->saldo, 'La vencida quedó cancelada'); $this->assertEquals(24200, (float) $cli->fresh()->balance);
        $r = $this->withToken($token)->getJson("/api/contacts/{$cli->id}/cuenta")->assertOk()->json('data');
        $this->assertEquals(0, $r['vencido']); $this->assertSame($cobro->numeroFormateado(), $r['ultimo_cobro']['numero']);
        $this->assertAsientosBalancean();

        // Lo que no corresponde: medio inválido, factura de otro cliente, cliente de otra empresa.
        $this->withToken($token)->postJson("/api/contacts/{$cli->id}/cobros", ['medios' => [['medio' => 'bitcoin', 'monto' => 10]]])->assertStatus(422);
        $otro = $this->cliente(['name' => 'Otro']); $f3 = $this->factura($otro, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 10000, 'alicuota_iva' => 21]]);
        $this->withToken($token)->postJson("/api/contacts/{$cli->id}/cobros", ['medios' => [['medio' => 'efectivo', 'monto' => 100]], 'imputaciones' => [['comprobante_id' => $f3->id, 'monto' => 100]]])->assertStatus(422);
        [$b2, $s2, $d2] = $this->crearEmpresa('Otra', 'otra');
        $this->withToken($token)->getJson('/api/contacts/' . \App\Models\Contact::withoutGlobalScopes()->where('business_id', $b2->id)->value('id') . '/cuenta')->assertStatus(404);
    }
}
