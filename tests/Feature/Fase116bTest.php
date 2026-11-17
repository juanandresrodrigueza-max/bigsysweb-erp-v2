<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 116 (parte 2, puntos D y E): carga inicial CRM → ERP y los eventos que antes se ignoraban.
class Fase116bTest extends ErpTestCase
{
    private const WH = 'secreto-webhook-crm-123456';
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => self::WH, 'lista_precios' => 1, 'presupuesto_como' => 'presupuesto']]);
        $this->dueno->unsetRelation('business');
        Http::fake(['crm.bigsysweb.test/*' => Http::response(['data' => ['summary' => [], 'results' => []], 'ok' => true], 200)]);
        $this->token = $this->dueno->createToken('api')->plainTextToken;
    }

    private function webhook(array $body)
    {
        $json = json_encode($body);
        return $this->call('POST', "/api/crm/webhook/{$this->empresa->id}", [], [], [], ['HTTP_X_BIGSYSWEB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $json, self::WH), 'CONTENT_TYPE' => 'application/json'], $json);
    }

    public function test_carga_inicial_de_contactos_y_presupuestos_sin_duplicar(): void
    {
        $existente = $this->cliente(['name' => 'López SRL', 'cuit' => '30-70012345-6']);
        $p = $this->articulo(['name' => 'Cemento', 'sku' => 'CEM50', 'stock_inicial' => 100, 'iva' => 21]);
        auth()->logout();
        $items = [['id' => 1, 'name' => 'Lopez S.R.L.', 'cuit' => '30700123456', 'email' => 'lopez@mail.com'], ['id' => 2, 'name' => 'Nuevo Cliente', 'email' => 'nuevo@mail.com', 'phone' => '351 444'], ['id' => 3, 'name' => 'Aceros Norte', 'tipo' => 'proveedor'], ['name' => 'Sin id']];
        $r = $this->withToken($this->token)->postJson('/api/crm/importar/contactos', ['items' => $items])->assertOk()->json('data');
        $this->assertSame(['total' => 4, 'creados' => 2, 'actualizados' => 1, 'existentes' => 0, 'errores' => 1], $r['summary']);
        $this->assertSame('1', $existente->fresh()->crm_external_id, 'Mismo CUIT: se emparejó, no se duplicó');
        $this->assertSame('supplier', Contact::withoutGlobalScopes()->where('crm_external_id', '3')->value('type'));
        // Repetir no duplica.
        $r2 = $this->withToken($this->token)->postJson('/api/crm/importar/contactos', ['items' => $items])->assertOk()->json('data');
        $this->assertSame(0, $r2['summary']['creados']); $this->assertSame(3, Contact::withoutGlobalScopes()->whereNotNull('crm_external_id')->count());
        // Presupuestos aceptados que nunca llegaron (mismo payload que quote.accepted), idempotentes por crm_quote_id.
        $q = ['id' => 901, 'quote_number' => 'PRE-0042', 'contact_id' => 1, 'status' => 'accepted', 'tax_rate' => 21, 'items' => [['description' => 'Cemento', 'quantity' => 10, 'unit_price' => 1000, 'code' => 'CEM50']]];
        $r = $this->withToken($this->token)->postJson('/api/crm/importar/presupuestos', ['items' => [['quote' => $q], ['quote' => ['id' => 902, 'contact_id' => 999]]]])->assertOk()->json('data');
        $this->assertSame(1, $r['summary']['creados']); $this->assertSame(1, $r['summary']['errores']);
        $this->assertSame('PRE', Comprobante::withoutGlobalScopes()->where('crm_quote_id', 901)->value('tipo'));
        $this->assertSame('existente', $this->withToken($this->token)->postJson('/api/crm/importar/presupuestos', ['items' => [$q]])->json('data.results.0.accion'));
        $this->withToken($this->token)->postJson('/api/crm/importar/contactos', ['items' => []])->assertStatus(422);
    }

    public function test_comprobantes_historicos_quedan_como_historial_con_el_saldo_que_incide_en_la_cuenta(): void
    {
        $p = $this->articulo(['name' => 'Cemento', 'sku' => 'CEM50', 'stock_inicial' => 100]);
        auth()->logout();
        $items = [
            ['crm_document_id' => 'd1', 'tipo' => 'FA', 'punto_venta' => 6, 'numero' => 120, 'fecha' => '2026-09-10', 'vencimiento' => '2026-10-10', 'cae' => '76123456789012', 'cae_vto' => '2026-09-20', 'cliente' => ['crm_contact_id' => 1, 'nombre' => 'López SRL', 'cuit' => '30-70012345-6'],
             'items' => [['sku' => 'CEM50', 'descripcion' => 'Cemento', 'cantidad' => 10, 'precio_unitario' => 1000, 'alicuota_iva' => 21]], 'total' => 12100, 'saldo' => 4100],
            ['crm_document_id' => 'd2', 'tipo' => 'NCA', 'punto_venta' => 6, 'numero' => 3, 'fecha' => '2026-09-15', 'origen_crm_document_id' => 'd1', 'cliente' => ['crm_contact_id' => 1, 'nombre' => 'López SRL'], 'items' => [['descripcion' => 'Devolución', 'cantidad' => 1, 'precio_unitario' => 1000]], 'total' => 1210],
            ['crm_document_id' => 'd3', 'tipo' => 'FB', 'punto_venta' => 6, 'numero' => 500, 'fecha' => '2026-08-01', 'cliente' => ['nombre' => 'Consumidor'], 'total' => 5000],
            ['crm_document_id' => 'd4', 'tipo' => 'REM', 'punto_venta' => 6, 'numero' => 1, 'fecha' => '2026-08-01', 'cliente' => ['nombre' => 'X']],
        ];
        $r = $this->withToken($this->token)->postJson('/api/crm/importar/comprobantes-historicos', ['items' => $items])->assertOk()->json('data');
        $this->assertSame(3, $r['summary']['creados']); $this->assertSame(1, $r['summary']['errores']);
        $f = Comprobante::withoutGlobalScopes()->where('crm_external_id', 'crm-doc-d1')->first();
        $this->assertSame('emitido', $f->estado); $this->assertSame('crm', $f->afip_estado); $this->assertSame('76123456789012', $f->cae); $this->assertSame('0006-00000120', $f->numeroFormateado());
        $this->assertEquals(12100, (float) $f->total); $this->assertEquals(4100, (float) $f->saldo); $this->assertFalse((bool) $f->stock_impactado); $this->assertSame('crm', $f->origen_carga);
        $this->assertEquals(100, (float) $p->fresh()->stock, 'El histórico no mueve stock'); $this->assertSame(1, $f->items()->count()); $this->assertSame($p->id, $f->items()->first()->product_id);
        $cli = Contact::withoutGlobalScopes()->where('crm_external_id', '1')->first();
        $this->assertEquals(4100, (float) $cli->fresh()->balance, 'La cuenta corriente arranca con lo pendiente, no con el total');
        $this->assertSame(2, CuentaCorriente::withoutGlobalScopes()->where('comprobante_id', $f->id)->count(), 'Debe por el total y haber por lo ya cobrado en el CRM');
        $nc = Comprobante::withoutGlobalScopes()->where('crm_external_id', 'crm-doc-d2')->first();
        $this->assertSame($f->id, $nc->origen_id); $this->assertEquals(0, (float) $nc->saldo); $this->assertSame(0, CuentaCorriente::withoutGlobalScopes()->where('comprobante_id', $nc->id)->count(), 'NC sin crédito pendiente: sólo historial');
        $this->assertSame(0, \App\Models\Asiento::withoutGlobalScopes()->where('business_id', $this->empresa->id)->count(), 'Sin asientos: el contador carga la apertura');
        $this->assertEquals(0, (float) Contact::withoutGlobalScopes()->where('name', 'Consumidor')->value('balance'));
        // Repetir: existentes, nada cambia.
        $r2 = $this->withToken($this->token)->postJson('/api/crm/importar/comprobantes-historicos', ['items' => $items])->assertOk()->json('data');
        $this->assertSame(3, $r2['summary']['existentes']); $this->assertEquals(4100, (float) $cli->fresh()->balance);
        // Se ve como cualquier comprobante en la API del CRM (cuenta del cliente).
        $this->assertCount(1, $this->withToken($this->token)->getJson("/api/contacts/{$cli->id}/cuenta")->json('data.pendientes'));
    }

    public function test_saldos_iniciales_arrancan_la_cuenta_corriente_como_comprobante_interno(): void
    {
        $cli = $this->cliente(['name' => 'Deudor Viejo', 'cuit' => '30-70012345-6']); $cli->update(['crm_external_id' => '10']);
        auth()->logout();
        $items = [['crm_contact_id' => 10, 'saldo' => 15000, 'fecha' => '2026-11-01', 'detalle' => 'deuda al 31/10'], ['cliente' => ['nombre' => 'A Favor SA', 'cuit' => '27-12345678-0'], 'saldo' => -2000], ['crm_contact_id' => 10, 'saldo' => 0]];
        $r = $this->withToken($this->token)->postJson('/api/crm/importar/saldos-iniciales', ['items' => $items])->assertOk()->json('data');
        $this->assertSame(2, $r['summary']['creados']); $this->assertSame(1, $r['summary']['errores']);
        $this->assertEquals(15000, (float) $cli->fresh()->balance); $this->assertEquals(15000, $r['results'][0]['saldo_cliente']);
        $nd = Comprobante::withoutGlobalScopes()->find($r['results'][0]['id']);
        $this->assertStringStartsWith('ND', $nd->tipo); $this->assertTrue((bool) $nd->sin_arca); $this->assertSame('emitido', $nd->estado); $this->assertEquals(15000, (float) $nd->total); $this->assertEquals(0, (float) $nd->iva);
        $this->assertSame('2026-11-01', $nd->fecha->toDateString()); $this->assertStringContainsString('Saldo inicial desde el CRM', $nd->items()->first()->descripcion);
        $favor = Contact::withoutGlobalScopes()->where('name', 'A Favor SA')->first();
        $this->assertEquals(-2000, (float) $favor->balance); $this->assertStringStartsWith('NC', Comprobante::withoutGlobalScopes()->find($r['results'][1]['id'])->tipo);
        // Idempotente por cliente.
        $r2 = $this->withToken($this->token)->postJson('/api/crm/importar/saldos-iniciales', ['items' => [$items[0]]])->assertOk()->json('data');
        $this->assertSame('existente', $r2['results'][0]['accion']); $this->assertEquals(15000, (float) $cli->fresh()->balance);
        $this->assertSame(0, \App\Models\Asiento::withoutGlobalScopes()->where('business_id', $this->empresa->id)->count());
    }

    public function test_eventos_nuevos_borrado_fusion_presupuesto_rechazado_o_actualizado_y_venta_perdida(): void
    {
        $p = $this->articulo(['name' => 'Cemento', 'sku' => 'CEM50', 'stock_inicial' => 100]);
        $a = $this->cliente(['name' => 'Cliente A', 'cuit' => '30-70012345-6']); $a->update(['crm_external_id' => '1']);
        $b = $this->cliente(['name' => 'Cliente A duplicado', 'cuit' => '27-12345678-0']); $b->update(['crm_external_id' => '2']);
        $this->factura($b, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]); // el duplicado tiene saldo
        $c = $this->cliente(['name' => 'Para borrar', 'cuit' => '30-50000000-3']); $c->update(['crm_external_id' => '3']);
        Auth::logout();
        // contact.deleted: desactiva, no borra.
        $this->webhook(['event' => 'contact.deleted', 'data' => ['contact' => ['id' => 3]]])->assertOk()->assertJsonPath('accion', 'desactivado');
        $this->assertFalse((bool) $c->fresh()->is_active); $this->assertNotNull(Contact::withoutGlobalScopes()->find($c->id));
        $this->webhook(['event' => 'contact.deleted', 'data' => ['contact' => ['id' => 404]]])->assertOk()->assertJsonPath('accion', 'no_estaba');
        // contact.merged: sobrevive el 1, el 2 queda inactivo y con alerta porque tenía saldo.
        $r = $this->webhook(['event' => 'contact.merged', 'data' => ['contact' => ['id' => 1], 'merged_ids' => [2]]])->assertOk()->json();
        $this->assertSame($a->id, $r['sobrevive']); $this->assertSame([$b->id], $r['desactivados']); $this->assertSame([$b->id], $r['con_saldo']);
        $this->assertFalse((bool) $b->fresh()->is_active); $this->assertNull($b->fresh()->crm_external_id); $this->assertSame('1', $a->fresh()->crm_external_id);
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('tipo', 'crm_fusion_saldo')->where('modelo_id', $b->id)->exists());
        // quote.accepted → PRE; quote.updated lo reemplaza; quote.rejected lo anula.
        $q = ['id' => 901, 'quote_number' => 'PRE-0042', 'contact_id' => 1, 'status' => 'accepted', 'items' => [['description' => 'Cemento', 'quantity' => 10, 'unit_price' => 1000, 'code' => 'CEM50']]];
        $pre = $this->webhook(['event' => 'quote.accepted', 'data' => ['quote' => $q]])->assertOk()->json();
        $q2 = $q; $q2['items'][0]['quantity'] = 20;
        $r = $this->webhook(['event' => 'quote.updated', 'data' => ['quote' => $q2]])->assertOk()->json();
        $this->assertSame('creado', $r['accion']); $this->assertNotSame($pre['id'], $r['id']); $this->assertSame($pre['numero'], $r['reemplazo']);
        $this->assertSame('anulado', Comprobante::withoutGlobalScopes()->find($pre['id'])->estado);
        $this->assertEquals(20, (float) Comprobante::withoutGlobalScopes()->find($r['id'])->items()->first()->cantidad);
        $this->webhook(['event' => 'quote.rejected', 'data' => ['quote' => ['id' => 901]]])->assertOk()->assertJsonPath('accion', 'anulado');
        $this->assertSame('anulado', Comprobante::withoutGlobalScopes()->find($r['id'])->estado);
        $this->webhook(['event' => 'quote.rejected', 'data' => ['quote' => ['id' => 901], 'reintento' => 2]])->assertOk()->assertJsonPath('accion', 'no_estaba'); // cuerpo distinto: el idéntico se descarta como duplicado
        // deal.lost: aviso informativo y cierra el de «ganada».
        $this->webhook(['event' => 'deal.won', 'data' => ['deal' => ['id' => 70, 'contact_id' => 1, 'title' => 'Obra', 'value' => 5000]]])->assertOk();
        $this->webhook(['event' => 'deal.lost', 'data' => ['deal' => ['id' => 70, 'contact_id' => 1, 'title' => 'Obra', 'lost_reason' => 'Precio']]])->assertOk()->assertJsonPath('accion', 'avisado');
        $this->assertNotNull(Alerta::withoutGlobalScopes()->where('tipo', 'crm_venta')->where('modelo_id', 70)->value('resuelta_en'));
        $this->assertStringContainsString('Precio', Alerta::withoutGlobalScopes()->where('tipo', 'crm_venta_perdida')->where('modelo_id', 70)->value('detalle'));
    }
}
