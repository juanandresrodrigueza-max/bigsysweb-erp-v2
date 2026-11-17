<?php

namespace Tests\Feature;

use App\Jobs\SincronizarCrmJob;
use App\Models\Alerta;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\PedidoWeb;
use App\Services\Comprobantes\CobroService;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Integraciones\CrmSyncService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 116 (parte 1, puntos A, B y C del pedido del CRM): pedidos de tiendas que entran por el CRM, deudores para gestionar en el CRM,
// cobros idempotentes y anulaciones que viajan, y stock siempre al día (cambios masivos, proveedores y usuarios).
class Fase116Test extends ErpTestCase
{
    private const SEC = 'secreto-compartido-de-prueba-1234567890';
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => self::SEC, 'api_key' => 'bsk_prueba', 'webhook_secreto' => 'wh-123456', 'lista_precios' => 1]]);
        $this->dueno->unsetRelation('business');
        Http::fake(['crm.bigsysweb.test/*' => Http::response(['data' => ['summary' => [], 'results' => []], 'ok' => true], 200)]);
        $this->token = $this->dueno->createToken('api')->plainTextToken;
    }

    private function avisos(string $evento): array
    {
        $out = [];
        Http::assertSent(function ($r) use (&$out, $evento) { if (str_ends_with($r->url(), '/api/erp/webhook') && ($r['evento'] ?? null) === $evento) $out[] = $r['datos']; return true; });
        return $out;
    }

    public function test_un_pedido_de_tienda_entra_como_venta_interna_descuenta_stock_y_es_idempotente(): void
    {
        $p = $this->articulo(['name' => 'Cemento x 50', 'sku' => 'CEM50', 'stock_inicial' => 100, 'price' => 9000, 'iva' => 21]);
        auth()->logout();
        $pedido = ['crm_order_id' => 'woo-1001', 'origen' => 'woo', 'numero' => '1001', 'fecha' => today()->toDateString(), 'pagado' => true, 'medio_pago' => 'Mercado Pago', 'total' => 23500, 'envio' => 1500,
            'cliente' => ['crm_contact_id' => 55, 'nombre' => 'Marta Pérez', 'email' => 'marta@mail.com', 'telefono' => '351 555 0000', 'direccion' => 'Av. Colón 100'],
            'items' => [['sku' => 'CEM50', 'descripcion' => 'Cemento x 50 kg', 'cantidad' => 2, 'precio_unitario' => 10000], ['sku' => 'ZZZ-NO', 'descripcion' => 'Pala ancha', 'cantidad' => 1, 'precio_unitario' => 2000]]];
        $r = $this->withToken($this->token)->postJson('/api/crm/pedidos', $pedido)->assertStatus(201)->json('data');
        $this->assertFalse($r['duplicado']); $this->assertSame('woocommerce', $r['canal']); $this->assertSame('confirmado', $r['estado']);
        $this->assertTrue($r['comprobante']['interno']); $this->assertSame('emitido', $r['comprobante']['estado']); $this->assertStringStartsWith('F', $r['comprobante']['tipo']);
        $this->assertSame(['Pala ancha (ZZZ-NO)'], $r['items_sin_articulo']); $this->assertNull($r['aviso']);
        $this->assertEquals(98, (float) $p->fresh()->stock, 'La venta descontó el stock del artículo emparejado');
        $cli = Contact::withoutGlobalScopes()->where('crm_external_id', '55')->first();
        $this->assertNotNull($cli); $this->assertSame('Marta Pérez', $cli->name); $this->assertSame($cli->id, $r['cliente']['id']);
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('tipo', 'crm_pedido_sin_articulo')->exists());
        $comp = Comprobante::withoutGlobalScopes()->find($r['comprobante']['id']);
        $this->assertSame('woo-1001', $comp->crm_order_id); $this->assertTrue((bool) $comp->sin_arca); $this->assertEqualsWithDelta(23500, (float) $comp->total, 0.05, 'Respeta el total que cobró la tienda (centavos de redondeo de IVA aparte)');
        // El aviso al CRM del interno lleva crm_order_id.
        $avisos = $this->avisos('comprobante.emitido');
        $this->assertNotEmpty($avisos); $this->assertSame('woo-1001', end($avisos)['crm_order_id']); $this->assertTrue(end($avisos)['interno']);
        // La tienda avisa dos veces: no entra dos veces.
        $r2 = $this->withToken($this->token)->postJson('/api/crm/pedidos', $pedido)->assertOk()->json('data');
        $this->assertTrue($r2['duplicado']); $this->assertSame($r['id'], $r2['id']);
        $this->assertSame(1, PedidoWeb::withoutGlobalScopes()->where('crm_order_id', 'woo-1001')->count());
        $this->assertEquals(98, (float) $p->fresh()->stock);
        // Facturar: el fiscal que nace del interno también avisa, con el mismo crm_order_id.
        Auth::shouldUse('web'); Auth::guard('web')->login($this->dueno); $this->actingAs($this->dueno, 'web');
        $fiscal = app(ComprobanteService::class)->facturarInterno($comp->fresh());
        $this->assertFalse((bool) $fiscal->sin_arca); $this->assertSame('woo-1001', $fiscal->crm_order_id);
        $ultimo = end($avisos); $avisos = $this->avisos('comprobante.emitido');
        $this->assertSame('woo-1001', end($avisos)['crm_order_id']); $this->assertFalse(end($avisos)['interno']); $this->assertArrayHasKey('fiscal_desde_interno', end($avisos));
    }

    public function test_un_pedido_de_un_cliente_sin_id_del_crm_se_empareja_por_cuit_y_un_origen_raro_cae_en_tienda(): void
    {
        $cli = $this->cliente(['name' => 'Ferretería López', 'cuit' => '30-70012345-6']); $p = $this->articulo(['sku' => 'A1', 'stock_inicial' => 5, 'price' => 100]);
        auth()->logout();
        $r = $this->withToken($this->token)->postJson('/api/crm/pedidos', ['crm_order_id' => 'tn-7', 'origen' => 'tiendanube', 'cliente' => ['nombre' => 'Lopez Ferreteria', 'cuit' => '30700123456'], 'items' => [['external_id' => $p->id, 'descripcion' => 'A1', 'cantidad' => 1, 'precio_unitario' => 100]], 'pagado' => false])->assertStatus(201)->json('data');
        $this->assertSame($cli->id, $r['cliente']['id'], 'Mismo CUIT: no crea otro cliente');
        $this->assertSame('tiendanube', $r['canal']);
        $this->withToken($this->token)->postJson('/api/crm/pedidos', ['crm_order_id' => 'x', 'origen' => 'rappi', 'cliente' => ['nombre' => 'X'], 'items' => []])->assertStatus(422);
    }

    public function test_deudores_para_el_crm_con_vencido_dias_de_atraso_y_comprobantes(): void
    {
        $p = $this->articulo(['price' => 1000, 'stock_inicial' => 50]);
        $a = $this->cliente(['name' => 'Atrasado SA', 'cuit' => '30-70012345-6']); $b = $this->cliente(['name' => 'Al Día SRL', 'cuit' => '27-12345678-0']); $c = $this->cliente(['name' => 'Sin Deuda', 'cuit' => '30-50000000-3']);
        $f1 = $this->factura($a, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 1000]], ['fecha' => today()->subDays(40)->toDateString()]); $f1->forceFill(['fecha_vto' => today()->subDays(10)->toDateString()])->save();
        $f2 = $this->factura($a, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $this->factura($b, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 1000]]);
        $a->update(['crm_external_id' => '77']);
        auth()->logout();
        $r = $this->withToken($this->token)->getJson('/api/crm/deudores')->assertOk()->json();
        $this->assertSame(2, $r['meta']['total']); $this->assertEquals(12100 + 1210 + 2420, $r['meta']['saldo_total']);
        $x = collect($r['data'])->firstWhere('nombre', 'Atrasado SA');
        $this->assertSame('77', $x['crm_external_id']); $this->assertEquals(13310, $x['saldo']); $this->assertEquals(12100, $x['vencido']); $this->assertSame(10, $x['dias_mayor_atraso']);
        $this->assertCount(1, $x['comprobantes_vencidos']); $this->assertSame($f1->numeroFormateado(), $x['comprobantes_vencidos'][0]['numero']); $this->assertSame(10, $x['comprobantes_vencidos'][0]['dias']); $this->assertNull($x['ultimo_cobro']);
        $y = collect($r['data'])->firstWhere('nombre', 'Al Día SRL'); $this->assertEquals(0, $y['vencido']); $this->assertSame(0, $y['dias_mayor_atraso']);
        $this->assertSame(1, $this->withToken($this->token)->getJson('/api/crm/deudores?solo_vencidos=1')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->withToken($this->token)->getJson('/api/crm/deudores?por_pagina=1')->assertOk()->json('meta.por_pagina'));
    }

    public function test_el_cobro_desde_el_crm_no_se_duplica_y_la_anulacion_viaja_al_crm(): void
    {
        $p = $this->articulo(['price' => 1000, 'stock_inicial' => 50]); $cli = $this->cliente(['name' => 'López']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 1000]]);
        auth()->logout();
        $pago = ['crm_payment_id' => 'crm-pay-9', 'medios' => [['medio' => 'efectivo', 'monto' => 5000], ['medio' => 'transferencia', 'monto' => 2000, 'cuenta_fondos_id' => $this->banco->id, 'referencia' => 'TRF 1']], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 7000]]];
        $r1 = $this->withToken($this->token)->postJson("/api/contacts/{$cli->id}/cobros", $pago)->assertStatus(201)->json('data');
        $this->assertFalse($r1['duplicado']); $this->assertEquals(7000, $r1['total']); $this->assertSame('crm-pay-9', $r1['crm_payment_id']);
        $r2 = $this->withToken($this->token)->postJson("/api/contacts/{$cli->id}/cobros", $pago)->assertOk()->json('data');
        $this->assertTrue($r2['duplicado']); $this->assertSame($r1['id'], $r2['id']);
        $this->assertSame(1, Cobro::withoutGlobalScopes()->where('contact_id', $cli->id)->count()); $this->assertEquals(5100, (float) $cli->fresh()->balance);
        $this->assertSame('crm-pay-9', $this->avisos('cobro.registrado')[0]['crm_payment_id'] ?? null);
        // Anular: viaja cobro.anulado con el motivo y el crm_payment_id.
        Auth::shouldUse('web'); Auth::guard('web')->login($this->dueno); $this->actingAs($this->dueno, 'web');
        app(CobroService::class)->anular(Cobro::withoutGlobalScopes()->find($r1['id']), 'Pago rebotado');
        $an = $this->avisos('cobro.anulado');
        $this->assertCount(1, $an); $this->assertSame('anulado', $an[0]['estado']); $this->assertSame('Pago rebotado', $an[0]['motivo']); $this->assertSame('crm-pay-9', $an[0]['crm_payment_id']);
        $this->assertEquals(12100, (float) $cli->fresh()->balance);
        // Anulado, el mismo crm_payment_id puede volver a entrar (el CRM lo corrigió).
        $this->withToken($this->token)->postJson("/api/contacts/{$cli->id}/cobros", $pago)->assertStatus(201);
    }

    public function test_los_cambios_masivos_los_proveedores_y_los_usuarios_viajan_al_crm(): void
    {
        $p1 = $this->articulo(['sku' => 'M1', 'stock_inicial' => 1]); $p2 = $this->articulo(['sku' => 'M2', 'stock_inicial' => 1]);
        \Illuminate\Support\Facades\Cache::flush(); // el alta recién los mandó; el cambio masivo de después tiene que volver a mandarlos
        Bus::fake([SincronizarCrmJob::class]);
        $this->post('/configuracion/tienda/articulos', ['ids' => [$p1->id, $p2->id], 'en_tienda' => true])->assertSessionHasNoErrors();
        Bus::assertDispatched(SincronizarCrmJob::class, fn($j) => $j->tipo === 'productos' && count(array_intersect($j->ids, [$p1->id, $p2->id])) === 2);
        // Proveedor: ahora también viaja (con la etiqueta Proveedor).
        $prov = $this->proveedor(['name' => 'Aceros Sur']);
        Bus::assertDispatched(SincronizarCrmJob::class, fn($j) => $j->tipo === 'contactos' && in_array($prov->id, $j->ids, true));
        $this->assertContains('Proveedor', CrmSyncService::contactoItem($prov)['tags']);
        // La carga completa suma usuarios.
        $this->usuarioConRol('vendedor');
        $r = CrmSyncService::sincronizarTodo($this->empresa->fresh());
        $this->assertSame(2, $r['usuarios']); $this->assertGreaterThanOrEqual(2, $r['contactos']);
        Bus::assertDispatched(SincronizarCrmJob::class, fn($j) => $j->tipo === 'usuarios');
    }
}
