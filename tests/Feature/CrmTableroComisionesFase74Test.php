<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Vendedor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 74 (lado ERP): el embudo del CRM en Estadísticas → Vendedores; la base de comisiones con lo facturado y cobrado, para el CRM y para Bigcito.
class CrmTableroComisionesFase74Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => 'wh', 'lista_precios' => 1]]);
        $this->dueno->unsetRelation('business'); Cache::flush();
    }

    private function fakeCrm(): void
    {
        Http::fake([
            'crm.bigsysweb.test/api/v1/deals?*' => function ($req) {
                $st = $req->data()['status'] ?? 'open';
                return Http::response(['data' => match ($st) {
                    'won' => [['id' => 20, 'title' => 'Obra Sur', 'status' => 'won', 'value' => '500000', 'contact_id' => 777, 'stage_name' => 'Cerrado', 'assigned_to_name' => 'Juan Vendedor', 'won_at' => today()->subDays(3)->toIso8601String()],
                        ['id' => 21, 'title' => 'Vieja', 'status' => 'won', 'value' => '99999', 'contact_id' => 777, 'assigned_to_name' => 'Juan Vendedor', 'won_at' => today()->subYear()->toIso8601String()]],
                    'lost' => [['id' => 30, 'title' => 'Perdida', 'status' => 'lost', 'value' => '10000', 'contact_id' => 777, 'assigned_to_name' => 'Ana', 'closed_at' => today()->subDay()->toIso8601String()]],
                    default => [['id' => 10, 'title' => 'Hierro Barrio Norte', 'status' => 'open', 'value' => '350000', 'contact_id' => 777, 'stage_name' => 'Propuesta', 'assigned_to_name' => 'Juan Vendedor'],
                        ['id' => 11, 'title' => 'Cemento mensual', 'status' => 'open', 'value' => '120000', 'contact_id' => 777, 'stage_name' => 'Contacto', 'assigned_to_name' => 'Ana'],
                        ['id' => 12, 'title' => 'Techo', 'status' => 'open', 'value' => '80000', 'contact_id' => 888, 'stage_name' => 'Propuesta', 'assigned_to_name' => 'Juan Vendedor']],
                }]);
            },
            'crm.bigsysweb.test/api/v1/tasks?*' => Http::response(['data' => [['id' => 501, 'title' => 'Llamar', 'status' => 'pending', 'priority' => 'high', 'contact_id' => 777, 'due_date' => today()->subDays(2)->toDateString(), 'assigned_to_name' => 'Juan Vendedor']]]),
            'crm.bigsysweb.test/*' => Http::response(['data' => []]),
        ]);
    }

    public function test_el_embudo_del_crm_aparece_en_estadisticas_vendedores_cruzado_con_lo_vendido_en_el_erp(): void
    {
        $this->fakeCrm();
        $juan = Vendedor::create(['business_id' => $this->empresa->id, 'nombre' => 'Juan Vendedor', 'email' => 'juan@test.com', 'comision_venta' => 5, 'comision_cobro' => 2, 'activo' => true]);
        $art = $this->articulo(['price' => 1000]); $cli = $this->cliente(['name' => 'López SRL', 'crm_external_id' => '777']);
        $this->factura($cli, [['product_id' => $art->id, 'cantidad' => 10, 'precio_unit' => 1000]], ['vendedor_id' => $juan->id]);

        $crm = $this->get('/estadisticas/vendedores')->assertOk()->viewData('page')['props']['crm'];
        $this->assertSame(3, $crm['abiertas']['cantidad']); $this->assertEquals(550000, $crm['abiertas']['total']);
        $this->assertSame(1, $crm['ganadas']['cantidad'], 'La ganada hace un año no cuenta en este mes'); $this->assertEquals(500000, $crm['ganadas']['total']); $this->assertSame(1, $crm['perdidas']['cantidad']); $this->assertSame(50, $crm['tasa_cierre']);
        $this->assertSame('Propuesta', $crm['etapas'][0]['etapa']); $this->assertEquals(430000, $crm['etapas'][0]['total']); $this->assertSame(1, $crm['tareas_vencidas']);
        $j = collect($crm['por_vendedor'])->firstWhere('vendedor', 'Juan Vendedor');
        $this->assertSame(2, $j['abiertas']); $this->assertSame(1, $j['ganadas']); $this->assertEquals(12100, $j['vendido_erp'], 'Cruza con lo facturado en el ERP (con IVA)'); $this->assertSame(1, $j['tareas_vencidas']);
        $this->assertNull(collect($crm['por_vendedor'])->firstWhere('vendedor', 'Ana')['vendido_erp'], 'Ana no es vendedora del ERP');

        // Sin CRM no hay bloque.
        $this->empresa->update(['crm_settings' => ['activo' => false]]); $this->dueno->unsetRelation('business'); Cache::flush();
        $this->assertNull($this->get('/estadisticas/vendedores')->assertOk()->viewData('page')['props']['crm']);
    }

    public function test_la_base_de_comisiones_sale_del_erp_con_lo_facturado_y_cobrado_de_verdad(): void
    {
        Http::fake(['crm.bigsysweb.test/*' => Http::response(['data' => []])]);
        $juan = Vendedor::create(['business_id' => $this->empresa->id, 'nombre' => 'Juan Vendedor', 'email' => 'juan@test.com', 'comision_venta' => 5, 'comision_cobro' => 2, 'activo' => true]);
        $ana = Vendedor::create(['business_id' => $this->empresa->id, 'nombre' => 'Ana', 'email' => 'ana@test.com', 'comision_venta' => 10, 'comision_cobro' => 0, 'activo' => true]);
        $art = $this->articulo(['price' => 1000]); $cli = $this->cliente(['name' => 'López SRL', 'crm_external_id' => '777']);
        $f1 = $this->factura($cli, [['product_id' => $art->id, 'cantidad' => 10, 'precio_unit' => 1000]], ['vendedor_id' => $juan->id]);   // 12.100
        $this->factura($cli, [['product_id' => $art->id, 'cantidad' => 2, 'precio_unit' => 1000]], ['vendedor_id' => $ana->id]);           // 2.420
        $this->factura($cli, [['product_id' => $art->id, 'cantidad' => 1, 'precio_unit' => 1000]]);                                        // sin vendedor (el cliente no tiene asignado)
        $token = $this->dueno->createToken('api')->plainTextToken;
        // El vendedor cobra 6.050 de su factura (desde el CRM, Fase 69).
        auth()->logout();
        $this->withToken($token)->postJson("/api/contacts/{$cli->id}/cobros", ['medios' => [['medio' => 'transferencia', 'monto' => 6050, 'cuenta_fondos_id' => $this->banco->id]], 'imputaciones' => [['comprobante_id' => $f1->id, 'monto' => 6050]], 'vendedor_id' => $juan->id])->assertStatus(201);

        $r = $this->withToken($token)->getJson('/api/comisiones/base?desde=' . today()->startOfMonth()->toDateString() . '&hasta=' . today()->toDateString())->assertOk()->json('data');
        $j = collect($r['vendedores'])->firstWhere('email', 'juan@test.com');
        $this->assertEquals(12100, $j['facturado']); $this->assertEquals(12100, $j['vendido']); $this->assertEquals(6050, $j['cobrado']);
        $this->assertEquals(605, $j['comision_por_ventas']); $this->assertEquals(121, $j['comision_por_cobros']); $this->assertEquals(726, $j['comision_total']);
        $this->assertSame(777, $j['detalle_comprobantes'][0]['cliente']['crm_contact_id']); $this->assertCount(1, $j['detalle_cobros']);
        $a = collect($r['vendedores'])->firstWhere('email', 'ana@test.com');
        $this->assertEquals(242, $a['comision_total']); $this->assertEquals(968, $r['total_comisiones']); $this->assertSame(1, $r['facturas_sin_vendedor']);
        $this->withToken($token)->getJson('/api/comisiones/base?desde=2020-01-01&hasta=2026-01-01')->assertStatus(422);

        // Los avisos al CRM dicen de qué vendedor son.
        Http::assertSent(fn($q) => str_ends_with($q->url(), '/api/erp/webhook') && ($q['evento'] ?? '') === 'comprobante.emitido' && ($q['datos']['vendedor']['email'] ?? null) === 'juan@test.com');
        Http::assertSent(fn($q) => str_ends_with($q->url(), '/api/erp/webhook') && ($q['evento'] ?? '') === 'cobro.registrado' && ($q['datos']['vendedor']['nombre'] ?? null) === 'Juan Vendedor');

        // Bigcito: "¿cuánto le toca a Juan este mes?"
        $this->actingAs($this->dueno);
        $m = app(Catalogo::class)->llamar($this->dueno->fresh(), 'comisiones_vendedores', ['periodo' => 'mes', 'vendedor' => 'juan'], 'asistente');
        $this->assertCount(1, $m['vendedores']); $this->assertEquals(726, $m['total_comisiones']); $this->assertStringContainsString('1 factura(s) del período no tienen vendedor', $m['nota']);
        $this->assertStringContainsString('No encuentro un vendedor', app(Catalogo::class)->llamar($this->dueno->fresh(), 'comisiones_vendedores', ['vendedor' => 'zzz'], 'asistente')['error']);
    }
}
