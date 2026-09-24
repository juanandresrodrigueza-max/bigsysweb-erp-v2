<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Services\Comprobantes\CobroService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Integración CRM · etapa 3: presupuesto aceptado en el CRM → presupuesto en el ERP; facturas y cobros del ERP avisados al CRM.
class CrmVentasTest extends ErpTestCase
{
    private const WH = 'secreto-webhook-crm-123456';
    private const SEC = 'secreto-compartido-de-prueba-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => self::SEC, 'api_key' => 'bsk_prueba', 'webhook_secreto' => self::WH, 'lista_precios' => 1, 'presupuesto_como' => 'presupuesto']]);
        $this->dueno->unsetRelation('business');
        Http::fake(['crm.bigsysweb.test/*' => Http::response(['data' => ['summary' => [], 'results' => []], 'ok' => true], 200)]);
    }

    private function webhook(array $body)
    {
        $json = json_encode($body);
        return $this->call('POST', "/api/crm/webhook/{$this->empresa->id}", [], [], [], ['HTTP_X_BIGSYSWEB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $json, self::WH), 'CONTENT_TYPE' => 'application/json'], $json);
    }

    public function test_presupuesto_aceptado_en_el_crm_nace_como_presupuesto_numerado_en_el_erp(): void
    {
        $p = $this->articulo(['name' => 'Cemento', 'sku' => 'CEM50', 'stock_inicial' => 100, 'iva' => 21]);
        $cli = $this->cliente(['name' => 'López SRL']); $cli->update(['crm_external_id' => '55']);
        Auth::logout();
        $quote = ['id' => 901, 'quote_number' => 'PRE-0042', 'title' => 'Obra Ruta 9', 'contact_id' => 55, 'status' => 'accepted', 'tax_rate' => 21, 'notes' => 'Entrega en obra',
            'items' => [['description' => 'Cemento x50', 'quantity' => 10, 'unit_price' => 1000, 'code' => 'CEM50'], ['description' => 'Flete', 'quantity' => 1, 'unit_price' => 5000]]];
        $r = $this->webhook(['event' => 'quote.accepted', 'data' => ['quote' => $quote]])->assertOk()->json();
        $this->assertSame('creado', $r['accion']); $this->assertSame('PRE', $r['tipo']);
        $c = Comprobante::withoutGlobalScopes()->find($r['id']);
        $this->assertSame('emitido', $c->estado, 'El presupuesto se numera');
        $this->assertSame(901, (int) $c->crm_quote_id); $this->assertSame($cli->id, $c->contact_id);
        $this->assertEqualsWithDelta(15000 * 1.21, (float) $c->total, 0.01);
        $this->assertSame($p->id, $c->items->first()->product_id, 'Enlaza el artículo por SKU');
        $this->assertStringContainsString('PRE-0042', $c->notas);
        $this->assertStringContainsString($r['numero'], 'P ' . $r['numero']);
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('tipo', 'crm_presupuesto')->where('modelo_id', $c->id)->exists(), 'Avisa en la campana');
        // Reintento del CRM con otro timestamp: no duplica.
        $r2 = $this->webhook(['event' => 'quote.accepted', 'data' => ['quote' => $quote], 'timestamp' => 'otro'])->assertOk()->json();
        $this->assertSame('existente', $r2['accion']); $this->assertSame($c->id, $r2['id']);
        $this->assertSame(1, Comprobante::withoutGlobalScopes()->where('crm_quote_id', 901)->count());
        $this->assertEqualsWithDelta(100, (float) $p->fresh()->stock, 0.001, 'Un presupuesto no mueve stock');

        // Cliente desconocido pero el CRM manda sus datos: lo crea y sigue.
        $r3 = $this->webhook(['event' => 'quote.accepted', 'data' => ['quote' => ['id' => 902, 'quote_number' => 'PRE-0043', 'contact_id' => 77, 'items' => [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 100]]], 'contact' => ['name' => 'Nuevo del CRM', 'email' => 'nuevo@crm.com']]])->assertOk()->json();
        $this->assertSame('creado', $r3['accion']);
        $this->assertSame('77', Contact::withoutGlobalScopes()->where('name', 'Nuevo del CRM')->value('crm_external_id'));
        // Sin cliente y sin datos → 422 con motivo.
        $this->webhook(['event' => 'quote.accepted', 'data' => ['quote' => ['id' => 903, 'contact_id' => 999, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1]]]]])->assertStatus(422)->assertJsonPath('ok', false);
        // Venta ganada sin presupuesto: alerta.
        $this->webhook(['event' => 'deal.won', 'data' => ['deal' => ['id' => 31, 'title' => 'Galpón', 'value' => 250000, 'contact_id' => 55]]])->assertOk()->assertJsonPath('accion', 'avisado');
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('tipo', 'crm_venta')->where('modelo_id', 31)->exists());
    }

    public function test_con_factura_en_borrador_configurado_queda_para_revisar(): void
    {
        $this->empresa->update(['crm_settings' => array_merge($this->empresa->crm_settings, ['presupuesto_como' => 'factura'])]);
        $cli = $this->cliente(); $cli->update(['crm_external_id' => '55']);
        Auth::logout();
        $r = $this->webhook(['event' => 'quote.accepted', 'data' => ['quote' => ['id' => 910, 'quote_number' => 'PRE-0050', 'contact_id' => 55, 'items' => [['description' => 'Servicio', 'quantity' => 2, 'unit_price' => 500]]]]])->assertOk()->json();
        $c = Comprobante::withoutGlobalScopes()->find($r['id']);
        $this->assertSame('borrador', $c->estado); $this->assertSame('FA', $c->tipo);
        $this->assertStringStartsWith('borrador #', $r['numero']);
    }

    public function test_facturas_y_cobros_del_erp_se_avisan_al_crm_con_el_cuit_y_el_presupuesto_de_origen(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente();
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]], ['tipo' => 'FA']);
        $f->forceFill(['crm_quote_id' => 901])->save();
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/api/erp/webhook') && $r->hasHeader('X-BigSys-Firma') && ($r->data()['evento'] ?? null) === 'comprobante.emitido' && $r['datos']['id'] === $f->id && $r['datos']['emisor_cuit'] === '30712345678' && $r['datos']['cliente']['id'] === $cli->id && $r['datos']['total'] == 1210);
        // La firma es sobre el cuerpo tal cual viaja.
        Http::assertSent(fn($r) => ($r->data()['evento'] ?? null) === 'comprobante.emitido' && hash_hmac('sha256', $r->body(), self::SEC) === $r->header('X-BigSys-Firma')[0]);
        app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 1210]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 1210]]]);
        Http::assertSent(fn($r) => ($r->data()['evento'] ?? null) === 'cobro.registrado' && $r['datos']['total'] == 1210 && $r['datos']['cliente']['id'] === $cli->id);
        $f2 = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 500]], ['tipo' => 'FA']);
        app(\App\Services\Comprobantes\ComprobanteService::class)->anular($f2->fresh(), 'prueba');
        Http::assertSent(fn($r) => ($r->data()['evento'] ?? null) === 'comprobante.anulado' && $r['datos']['estado'] === 'anulado');
    }
}
