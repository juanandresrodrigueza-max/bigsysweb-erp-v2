<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Vendedor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Hallazgos de la prueba de punta a punta en producción: el cobro desde el CRM sin cobrador (3.4) y los ítems del presupuesto por external_id.
class CrmHallazgosE2ETest extends ErpTestCase
{
    private const WH = 'secreto-webhook-crm-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => self::WH, 'lista_precios' => 1]]);
        $this->dueno->unsetRelation('business'); Cache::flush();
        Http::fake(['crm.bigsysweb.test/*' => Http::response(['ok' => true, 'data' => ['summary' => [], 'results' => []]])]);
    }

    public function test_cobrar_desde_el_crm_con_el_mail_de_un_usuario_que_no_era_vendedor_le_crea_la_ficha_y_no_deja_el_recibo_sin_cobrador(): void
    {
        $juan = $this->usuarioConRol('vendedor', 'juan@test.com');
        $art = $this->articulo(['price' => 1000]); $cli = $this->cliente(['name' => 'López SRL', 'crm_external_id' => '777']);
        $f = $this->factura($cli, [['product_id' => $art->id, 'cantidad' => 10, 'precio_unit' => 1000]]);
        $token = $this->dueno->createToken('api')->plainTextToken; Auth::logout();
        $this->assertNull(Vendedor::withoutGlobalScopes()->where('user_id', $juan->id)->first());

        $c = $this->withToken($token)->postJson("/api/contacts/{$cli->id}/cobros", ['medios' => [['medio' => 'transferencia', 'monto' => 6050, 'cuenta_fondos_id' => $this->banco->id]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 6050]], 'vendedor_email' => 'Juan@Test.com'])->assertStatus(201)->json('data');
        $v = Vendedor::withoutGlobalScopes()->where('user_id', $juan->id)->first();
        $this->assertNotNull($v, 'Le creó la ficha de vendedor'); $this->assertSame('juan@test.com', $v->email); $this->assertEquals(0, $v->comision_cobro);
        $this->assertSame($v->id, $c['cobrador']['id']); $this->assertNull($c['aviso']);
        $this->assertSame($v->id, \App\Models\Cobro::withoutGlobalScopes()->find($c['id'])->cobrador_id);

        // Segundo cobro del mismo: reutiliza la ficha. Un mail de nadie: queda sin cobrador y lo avisa, pero el recibo sale igual.
        $c2 = $this->withToken($token)->postJson("/api/contacts/{$cli->id}/cobros", ['medios' => [['medio' => 'efectivo', 'monto' => 1000, 'cuenta_fondos_id' => $this->caja->id]], 'vendedor_email' => 'juan@test.com'])->assertStatus(201)->json('data');
        $this->assertSame($v->id, $c2['cobrador']['id']); $this->assertSame(1, Vendedor::withoutGlobalScopes()->where('user_id', $juan->id)->count());
        $c3 = $this->withToken($token)->postJson("/api/contacts/{$cli->id}/cobros", ['medios' => [['medio' => 'efectivo', 'monto' => 500, 'cuenta_fondos_id' => $this->caja->id]], 'vendedor_email' => 'nadie@otra.com'])->assertStatus(201)->json('data');
        $this->assertNull($c3['cobrador']); $this->assertStringContainsString('no es usuario ni vendedor', $c3['aviso']);
    }

    public function test_el_presupuesto_aceptado_empareja_los_items_por_external_id_aunque_no_tengan_codigo(): void
    {
        $p = $this->articulo(['name' => 'Hierro del 8', 'sku' => 'H8', 'iva' => 21, 'price' => 100]);
        $cli = $this->cliente(['name' => 'López SRL']); $cli->update(['crm_external_id' => '55']);
        Auth::logout();
        $json = json_encode(['event' => 'quote.accepted', 'data' => ['quote' => ['id' => 902, 'quote_number' => 'PRE-0043', 'contact_id' => 55, 'status' => 'accepted', 'tax_rate' => 21,
            'items' => [['description' => 'Hierro 8 mm', 'quantity' => 4, 'unit_price' => 100, 'external_id' => (string) $p->id], ['description' => 'Flete', 'quantity' => 1, 'unit_price' => 5000, 'external_id' => '999999']]]]]);
        $r = $this->call('POST', "/api/crm/webhook/{$this->empresa->id}", [], [], [], ['HTTP_X_BIGSYSWEB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $json, self::WH), 'CONTENT_TYPE' => 'application/json'], $json)->assertOk()->json();
        $c = Comprobante::withoutGlobalScopes()->find($r['id']);
        $this->assertSame($p->id, $c->items->first()->product_id, 'Enlaza por external_id sin code'); $this->assertNull($c->items->last()->product_id, 'Un external_id que no existe no rompe');
    }
}
