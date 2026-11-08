<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\IaLlamada;
use App\Models\McpConexion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 72 (lado ERP): Bigcito compartido. El del ERP contesta con datos del CRM; el del CRM pregunta al ERP diciendo quién pregunta.
class CrmBigcitoCompartidoFase72Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => 'wh', 'lista_precios' => 1]]);
        $this->dueno->unsetRelation('business'); Cache::flush();
    }

    private function fakeCrm(): void
    {
        $hoy = today()->toDateString(); $ayer = today()->subDay()->toDateString(); $manana = today()->addDay()->toDateString();
        Http::fake([
            'crm.bigsysweb.test/api/v1/contacts/777' => Http::response(['data' => ['id' => 777, 'name' => 'López SRL', 'lifecycle_stage' => 'customer', 'tags' => [['id' => 1, 'name' => 'Corralón'], 'VIP'], 'owner' => ['id' => 3, 'name' => 'Juan Vendedor', 'email' => 'juan@test.com'], 'last_contact_at' => now()->subDays(2)->toIso8601String()]]),
            'crm.bigsysweb.test/api/v1/deals?*' => Http::response(['data' => [
                ['id' => 10, 'title' => 'Hierro para obra Barrio Norte', 'status' => 'open', 'value' => '350000', 'priority' => 'high', 'contact_id' => 777, 'stage_id' => 2, 'stage_name' => 'Propuesta', 'assigned_to_name' => 'Juan Vendedor', 'due_date' => $manana],
                ['id' => 11, 'title' => 'Cemento mensual', 'status' => 'open', 'value' => '120000', 'contact_id' => 777, 'stage_id' => 1, 'stage_name' => 'Contacto', 'assigned_to_name' => 'Ana Vendedora'],
                ['id' => 12, 'title' => 'Pérez - techo', 'status' => 'open', 'value' => '80000', 'contact_id' => 888, 'stage_id' => 2, 'stage_name' => 'Propuesta', 'assigned_to_name' => 'Juan Vendedor'],
            ]]),
            'crm.bigsysweb.test/api/v1/tasks?*' => Http::response(['data' => [
                ['id' => 501, 'title' => 'Llamar por el presupuesto del hierro', 'status' => 'pending', 'priority' => 'high', 'contact_id' => 777, 'due_date' => $ayer, 'assigned_to_name' => 'Juan Vendedor'],
                ['id' => 502, 'title' => 'Mandar catálogo', 'status' => 'pending', 'priority' => 'low', 'contact_id' => 888, 'due_date' => $hoy, 'assigned_to_name' => 'Ana Vendedora'],
            ]]),
            'crm.bigsysweb.test/api/v1/appointments?*' => Http::response(['data' => [['id' => 70, 'contact_id' => 777, 'status' => 'confirmed', 'start_at' => today()->addDays(3)->setTime(10, 30)->toIso8601String(), 'service_name_snapshot' => 'Visita a obra', 'assigned_to_name' => 'Juan Vendedor']]]),
            'crm.bigsysweb.test/*' => Http::response(['data' => []]),
        ]);
    }

    public function test_bigcito_del_erp_cuenta_quien_atiende_al_cliente_y_en_que_anda_en_el_crm(): void
    {
        $this->fakeCrm();
        $cli = $this->cliente(['name' => 'López SRL']); $cli->update(['crm_external_id' => '777']);

        $f = app(Catalogo::class)->llamar($this->dueno->fresh(), 'cliente_en_crm', ['cliente' => 'López'], 'asistente');
        $this->assertTrue($f['en_crm']); $this->assertSame('Juan Vendedor', $f['vendedor']); $this->assertSame('customer', $f['etapa']); $this->assertSame(['Corralón', 'VIP'], $f['etiquetas']);
        $this->assertCount(2, $f['oportunidades'], 'Sólo las del cliente, aunque el CRM devuelva otras'); $this->assertSame('Propuesta', $f['oportunidades'][0]['etapa']); $this->assertEquals(350000, $f['oportunidades'][0]['monto']); $this->assertSame('López SRL', $f['oportunidades'][0]['cliente']);
        $this->assertCount(1, $f['tareas']); $this->assertTrue($f['tareas'][0]['vencida']); $this->assertSame('Visita a obra', $f['proxima_cita']['que']);
        $this->assertStringContainsString('/integraciones/crm/ir?a=' . urlencode('/contacts/777'), $f['url']); $this->assertStringContainsString(urlencode('/deals?id=10'), $f['oportunidades'][0]['url']);
        Http::assertSent(fn($q) => str_contains($q->url(), '/api/v1/deals?') && str_contains($q->url(), 'contact_id=777') && str_contains($q->url(), 'status=open'));

        // Sin id del CRM: lo dice sin romper.
        $otro = $this->cliente(['name' => 'Nuevo Cliente']);
        $n = app(Catalogo::class)->llamar($this->dueno->fresh(), 'cliente_en_crm', ['cliente' => 'Nuevo Cliente'], 'asistente');
        $this->assertFalse($n['en_crm']); $this->assertStringContainsString('todavía no está en el CRM', $n['nota']);
    }

    public function test_bigcito_del_erp_lee_el_embudo_y_las_tareas_del_crm(): void
    {
        $this->fakeCrm();
        $this->cliente(['name' => 'López SRL', 'crm_external_id' => '777']);

        $e = app(Catalogo::class)->llamar($this->dueno->fresh(), 'oportunidades_crm', [], 'asistente');
        $this->assertSame(3, $e['cantidad']); $this->assertEquals(550000, $e['total']); $this->assertSame(2, $e['por_etapa']['Propuesta']['cantidad']); $this->assertEquals(430000, $e['por_etapa']['Propuesta']['total']);
        $this->assertSame('López SRL', $e['oportunidades'][0]['cliente'], 'Cruza el contacto del CRM con el cliente del ERP'); $this->assertNull($e['oportunidades'][2]['cliente'], 'El 888 no está en el ERP');

        $j = app(Catalogo::class)->llamar($this->dueno->fresh(), 'oportunidades_crm', ['vendedor' => 'juan'], 'asistente');
        $this->assertSame(2, $j['cantidad']); $this->assertEquals(430000, $j['total']);

        $t = app(Catalogo::class)->llamar($this->dueno->fresh(), 'pendientes_crm', [], 'asistente');
        $this->assertSame(2, $t['cantidad']); $this->assertSame(1, $t['vencidas']); $this->assertSame('Llamar por el presupuesto del hierro', $t['tareas'][0]['titulo'], 'La vencida primero'); $this->assertSame('López SRL', $t['tareas'][0]['cliente']);
        $v = app(Catalogo::class)->llamar($this->dueno->fresh(), 'pendientes_crm', ['solo_vencidas' => true], 'asistente');
        $this->assertSame(1, $v['cantidad']);
        Http::assertSent(fn($q) => str_contains($q->url(), '/api/v1/tasks?') && str_contains($q->url(), 'status=pending') && str_contains($q->url(), 'to=' . today()->toDateString()));

        // El contexto de Bigcito ya sabe que el CRM está conectado y qué herramientas usar.
        $this->assertStringContainsString('oportunidades_crm', \App\Mcp\Recursos::instrucciones($this->dueno->fresh()));
    }

    public function test_si_el_crm_no_responde_bigcito_lo_dice_y_sin_crm_no_aparecen_las_herramientas(): void
    {
        Http::fake(['crm.bigsysweb.test/api/v1/contacts/sync' => Http::response(['data' => ['summary' => ['total' => 1], 'results' => []]]), 'crm.bigsysweb.test/*' => Http::response('caído', 503)]);
        $this->cliente(['name' => 'López SRL', 'crm_external_id' => '777']);
        $r = app(Catalogo::class)->llamar($this->dueno->fresh(), 'cliente_en_crm', ['cliente' => 'López'], 'asistente');
        $this->assertStringContainsString('El CRM no respondió', $r['error']);
        $r = app(Catalogo::class)->llamar($this->dueno->fresh(), 'oportunidades_crm', [], 'asistente');
        $this->assertStringContainsString('El CRM no respondió', $r['error']);

        $this->empresa->update(['crm_settings' => ['activo' => false]]); Cache::flush();
        $r = app(Catalogo::class)->llamar($this->dueno->fresh(), 'pendientes_crm', [], 'asistente');
        $this->assertStringContainsString('no tiene el CRM conectado', $r['error']);
        $this->assertStringNotContainsString('oportunidades_crm', \App\Mcp\Recursos::instrucciones($this->dueno->fresh()));
    }

    public function test_el_crm_pregunta_al_mcp_del_erp_diciendo_quien_pregunta_y_el_erp_responde_con_sus_permisos(): void
    {
        $vendedor = $this->usuarioConRol('vendedor', 'juan@test.com');
        $t = $this->dueno->createToken('MCP · CRM', ['mcp']);
        McpConexion::withoutGlobalScopes()->create(['business_id' => $this->empresa->id, 'user_id' => $this->dueno->id, 'nombre' => 'CRM', 'token_id' => $t->accessToken->id, 'modo' => 'lectura']);
        $tk = $t->plainTextToken;
        $rpc = fn(array $headers, string $tool) => tap($this->withHeaders(['Authorization' => "Bearer {$tk}"] + $headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]]), fn() => Auth::forgetGuards());

        // Sin decir quién: responde con los permisos del dueño del token.
        Auth::forgetGuards();
        $r = $rpc([], 'saldos_cuentas')->assertOk()->json();
        $this->assertArrayNotHasKey('error', $r['result']['structuredContent'] ?? ['error' => 'x']);

        // Diciendo que pregunta el vendedor: a él no le muestra las cajas, y la llamada queda a su nombre.
        $r = $rpc(['X-BigSys-Usuario' => 'JUAN@test.com'], 'saldos_cuentas')->assertOk()->json();
        $this->assertStringContainsString('no tiene permiso', $r['result']['structuredContent']['error']);
        // Pero sí lo que un vendedor puede ver, y la llamada queda registrada a su nombre.
        $r = $rpc(['X-BigSys-Usuario' => 'juan@test.com'], 'deudores')->assertOk()->json();
        $this->assertArrayNotHasKey('error', $r['result']['structuredContent'] ?? ['error' => 'x']);
        $this->assertTrue(IaLlamada::withoutGlobalScopes()->where('user_id', $vendedor->id)->where('herramienta', 'deudores')->exists());
        $this->assertFalse(IaLlamada::withoutGlobalScopes()->where('user_id', $this->dueno->id)->where('herramienta', 'deudores')->exists());

        // Un mail que no es de la empresa: sigue siendo el dueño del token (no se puede "ser" alguien de otra empresa).
        [$otraB, , $otroDueno] = $this->crearEmpresa('Otra', 'otra');
        $r = $rpc(['X-BigSys-Usuario' => $otroDueno->email], 'saldos_cuentas')->assertOk()->json();
        $this->assertArrayNotHasKey('error', $r['result']['structuredContent'] ?? ['error' => 'x']);
        $this->assertFalse(IaLlamada::withoutGlobalScopes()->where('user_id', $otroDueno->id)->exists());

        // En modo acciones la cabecera se ignora: las acciones son siempre del dueño del token.
        $t2 = $this->dueno->createToken('MCP · acciones', ['mcp']);
        McpConexion::withoutGlobalScopes()->create(['business_id' => $this->empresa->id, 'user_id' => $this->dueno->id, 'nombre' => 'acc', 'token_id' => $t2->accessToken->id, 'modo' => 'acciones']);
        $r = $this->withHeaders(['Authorization' => "Bearer {$t2->plainTextToken}", 'X-BigSys-Usuario' => 'juan@test.com'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'saldos_cuentas', 'arguments' => []]])->assertOk()->json();
        $this->assertArrayNotHasKey('error', $r['result']['structuredContent'] ?? ['error' => 'x']);
    }
}
