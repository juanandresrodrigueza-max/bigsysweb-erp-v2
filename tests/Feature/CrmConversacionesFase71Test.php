<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Services\Integraciones\CrmSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 71 (lado ERP): las conversaciones del CRM en la ficha del cliente, y los reclamos de WhatsApp como tarea del CRM.
class CrmConversacionesFase71Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => 'wh', 'lista_precios' => 1]]);
        $this->dueno->unsetRelation('business'); Cache::flush();
    }

    public function test_la_ficha_del_cliente_muestra_lo_que_el_crm_hablo_con_el_y_el_boton_para_seguir_alla(): void
    {
        Http::fake([
            'crm.bigsysweb.test/api/v1/contacts/sync' => Http::response(['data' => ['summary' => ['total' => 1], 'results' => [['external_id' => '__ID__', 'id' => 777, 'action' => 'created']]]]),
            'crm.bigsysweb.test/api/v1/conversations?*' => Http::response(['data' => [['id' => 55, 'contact_id' => 777, 'channel_type' => 'whatsapp', 'status' => 'open', 'unread_count' => 2, 'last_message_at' => now()->subMinutes(30)->toIso8601String()], ['id' => 40, 'contact_id' => 777, 'channel_type' => 'email', 'status' => 'closed', 'unread_count' => 0, 'last_message_at' => now()->subDays(3)->toIso8601String()]]]),
            'crm.bigsysweb.test/api/v1/conversations/55/messages*' => Http::response(['data' => [['id' => 2, 'direction' => 'outbound', 'content' => 'Te paso el presupuesto del hierro.', 'message_type' => 'text', 'created_at' => now()->subMinutes(30)->toIso8601String()], ['id' => 1, 'direction' => 'inbound', 'content' => '¿Tenés hierro del 8?', 'message_type' => 'text', 'created_at' => now()->subMinutes(40)->toIso8601String()]]]),
            'crm.bigsysweb.test/*' => Http::response(['data' => []]),
        ]);
        $cli = $this->cliente(['name' => 'López SRL']);
        // El sync le puso el id del CRM (el fake no conoce el id real del ERP: se lo fija a mano, como lo haría la respuesta).
        $cli->update(['crm_external_id' => '777']);

        $props = $this->get("/clientes/{$cli->id}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['crm']['activo']); $this->assertSame('777', $props['crm']['external_id']);
        $this->assertStringContainsString('/integraciones/crm/ir?a=' . urlencode('/conversations?contact=777'), $props['crm']['url_escribir']); $this->assertStringContainsString(urlencode('/contacts/777'), $props['crm']['url_ficha']);

        $r = $this->getJson("/clientes/{$cli->id}/crm")->assertOk()->json();
        $this->assertCount(2, $r); $this->assertSame('WhatsApp', $r[0]['canal']); $this->assertSame('open', $r[0]['estado']); $this->assertSame(2, $r[0]['no_leidos']);
        $this->assertCount(2, $r[0]['mensajes']); $this->assertSame('inbound', $r[0]['mensajes'][0]['direccion'], 'En orden cronológico'); $this->assertSame('Te paso el presupuesto del hierro.', $r[0]['mensajes'][1]['texto']);
        $this->assertStringContainsString(urlencode('/conversations?id=55'), $r[0]['url']); $this->assertSame([], $r[1]['mensajes'], 'Sólo la última conversación trae mensajes');
        Http::assertSent(fn($q) => str_contains($q->url(), '/api/v1/conversations?') && str_contains($q->url(), 'contact_id=777'));

        // Bigcito del ERP: "¿qué habló el vendedor con López?"
        $m = app(Catalogo::class)->llamar($this->dueno->fresh(), 'conversaciones_crm', ['cliente' => 'López'], 'asistente');
        $this->assertCount(2, $m['conversaciones']); $this->assertStringContainsString('hierro del 8', json_encode($m, JSON_UNESCAPED_UNICODE));
    }

    public function test_un_reclamo_por_whatsapp_tambien_queda_como_tarea_del_crm(): void
    {
        Http::fake(['crm.bigsysweb.test/api/v1/tasks' => Http::response(['data' => ['id' => 9001]], 201), 'crm.bigsysweb.test/*' => Http::response(['data' => []]), 'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);
        $cli = $this->cliente(['name' => 'Juan Gómez', 'phone' => '3515559999', 'crm_external_id' => '777']);
        $this->empresa->forceFill(['whatsapp_settings' => ['token' => 'tk', 'phone_id' => '111', 'verify_token' => 'v']])->save();
        auth()->logout();
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515559999', 'texto' => 'Lo que me mandaron ayer llegó todo roto'])->assertOk()->assertJsonPath('atendidos.0', 'reclamo');
        Http::assertSent(fn($q) => str_ends_with($q->url(), '/api/v1/tasks') && str_contains($q['title'], 'Reclamo por WhatsApp') && $q['contact_id'] === 777 && $q['priority'] === 'high');
        $this->assertTrue(\App\Models\Alerta::withoutGlobalScopes()->where('tipo', 'reclamo_whatsapp')->where('detalle', 'like', '%tarea del CRM #9001%')->exists(), 'La alerta dice que ya está en el CRM');
    }
}
