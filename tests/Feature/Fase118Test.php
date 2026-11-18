<?php

namespace Tests\Feature;

use App\Models\SistemaConfig;
use App\Services\Canales\WhatsappConexionService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 118: «Conectar WhatsApp» con el QR de Meta (Embedded Signup con coexistencia) usando la app de Meta de BigSys.
class Fase118Test extends ErpTestCase
{
    private function appMeta(): void
    {
        SistemaConfig::set('meta_app_id', '1234567890'); SistemaConfig::set('meta_app_secret', 'secreto-de-prueba'); SistemaConfig::set('meta_config_id', '987654321');
    }

    private function metaFalsa(array $extra = []): void
    {
        Http::fake(array_merge([
            WhatsappConexionService::GRAPH . '/oauth/access_token*' => Http::response(['access_token' => 'EAAB-token-empresa', 'token_type' => 'bearer']),
            WhatsappConexionService::GRAPH . '/debug_token*' => Http::response(['data' => ['granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA1']]]]]),
            WhatsappConexionService::GRAPH . '/WABA1/phone_numbers*' => Http::response(['data' => [['id' => 'PH1', 'display_phone_number' => '+54 9 351 555-1234', 'verified_name' => 'Corralón Demo', 'platform_type' => 'CLOUD_API']]]),
            WhatsappConexionService::GRAPH . '/WABA1/subscribed_apps' => Http::response(['success' => true]),
            WhatsappConexionService::GRAPH . '/PH1/smb_app_data' => Http::response(['success' => true]),
            'https://crm.bigsysweb.test/api/erp/numero-en-uso*' => Http::response(['en_uso' => false]),
            'https://graph.facebook.com/*/PH1/messages' => Http::response(['messages' => [['id' => 'wamid.1']]]),
        ], $extra));
    }

    public function test_sin_la_app_de_meta_la_pantalla_avisa_y_no_deja_conectar(): void
    {
        $p = $this->get('/configuracion/tienda')->viewData('page')['props']['whatsapp']['conexion'];
        $this->assertFalse($p['app_lista']); $this->assertFalse($p['conectado']);
        $this->post('/configuracion/tienda/whatsapp/conectar', ['code' => 'abc'])->assertSessionHasErrors('whatsapp');
    }

    public function test_con_el_code_del_popup_queda_conectado_suscribe_su_url_y_puede_mandar_una_prueba(): void
    {
        $this->appMeta(); $this->metaFalsa();
        $this->assertTrue($this->get('/configuracion/tienda')->viewData('page')['props']['whatsapp']['conexion']['app_lista']);
        $this->postJson('/configuracion/tienda/whatsapp', ['auto_pedidos' => true, 'auto_responder' => true, 'atencion_clientes' => true, 'bigcito_equipo' => false])->assertRedirect(); // una preferencia que tiene que sobrevivir

        $this->post('/configuracion/tienda/whatsapp/conectar', ['code' => 'code-del-popup', 'phone_number_id' => 'PH1', 'waba_id' => 'WABA1'])->assertRedirect()->assertSessionHas('success');
        $ws = $this->empresa->fresh()->whatsapp_settings;
        $this->assertSame('EAAB-token-empresa', $ws['token']); $this->assertSame('PH1', $ws['phone_id']); $this->assertSame('WABA1', $ws['waba_id']); $this->assertSame('qr', $ws['modo']);
        $this->assertSame('+54 9 351 555-1234', $ws['numero']); $this->assertNotEmpty($ws['verify_token']); $this->assertTrue($ws['sincronizado']); $this->assertArrayNotHasKey('pin', $ws);
        Http::assertSent(fn($r) => str_contains($r->url(), '/oauth/access_token') && $r['code'] === 'code-del-popup' && $r['client_secret'] === 'secreto-de-prueba');
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/WABA1/subscribed_apps') && $r['override_callback_uri'] === url('/api/whatsapp/entrante/' . $this->empresa->id) && $r['verify_token'] === $ws['verify_token']);
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/register')); // con coexistencia no se registra de nuevo
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/subscriptions')); // nunca el webhook de toda la app (es el del CRM)
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/PH1/smb_app_data') && $r['sync_type'] === 'smb_app_state_sync');

        $e = $this->get('/configuracion/tienda')->viewData('page')['props']['whatsapp']['conexion'];
        $this->assertTrue($e['conectado']); $this->assertSame('qr', $e['modo']); $this->assertSame('+54 9 351 555-1234', $e['numero']);

        // El webhook de Meta verifica con el verify_token que suscribimos.
        $this->get('/api/whatsapp/entrante/' . $this->empresa->id . '?hub_mode=subscribe&hub_verify_token=' . $ws['verify_token'] . '&hub_challenge=reto-123')->assertOk()->assertSee('reto-123');

        $this->post('/configuracion/tienda/whatsapp/probar', ['telefono' => '5493515550000'])->assertRedirect()->assertSessionHas('success');
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/PH1/messages') && $r['to'] === '5493515550000');

        // Desconectar: saca token y número, deja las preferencias.
        $this->post('/configuracion/tienda/whatsapp/desconectar')->assertRedirect();
        $ws2 = $this->empresa->fresh()->whatsapp_settings;
        $this->assertArrayNotHasKey('token', $ws2); $this->assertArrayNotHasKey('phone_id', $ws2); $this->assertFalse($ws2['bigcito_equipo']);
        $this->assertFalse($this->get('/configuracion/tienda')->viewData('page')['props']['whatsapp']['conexion']['conectado']);
    }

    public function test_si_el_popup_no_dice_el_numero_lo_busca_por_la_waba(): void
    {
        $this->appMeta(); $this->metaFalsa();
        $this->post('/configuracion/tienda/whatsapp/conectar', ['code' => 'solo-code'])->assertSessionHas('success');
        $this->assertSame('PH1', $this->empresa->fresh()->whatsapp_settings['phone_id']);
        Http::assertSent(fn($r) => str_contains($r->url(), '/debug_token'));
    }

    public function test_si_meta_rechaza_el_code_avisa_y_no_guarda_nada(): void
    {
        $this->appMeta();
        Http::fake([WhatsappConexionService::GRAPH . '/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid verification code format.']], 400)]);
        $this->post('/configuracion/tienda/whatsapp/conectar', ['code' => 'malo'])->assertSessionHasErrors('whatsapp');
        $this->assertStringContainsString('Invalid verification code', session('errors')->first('whatsapp'));
        $this->assertArrayNotHasKey('token', $this->empresa->fresh()->whatsapp_settings ?? []);
    }

    public function test_el_superadmin_carga_la_app_de_meta_en_sistema_y_el_secret_no_se_muestra(): void
    {
        $su = \App\Models\User::withoutGlobalScopes()->where('is_superadmin', true)->first() ?? \App\Models\User::factory()->create(['is_superadmin' => true, 'business_id' => $this->empresa->id]);
        $this->actingAs($su); \Illuminate\Support\Facades\Auth::login($su);
        $base = $this->get('/admin/sistema')->viewData('page')['props']['config'];
        $this->post('/admin/sistema', array_merge(['dias_prueba' => $base['dias_prueba'] ?? 15, 'dias_gracia' => $base['dias_gracia'] ?? 7, 'aviso_dias' => '7,3,1'], ['meta_app_id' => '111', 'meta_app_secret' => 'sss', 'meta_config_id' => '222']))->assertRedirect();
        $c = $this->get('/admin/sistema')->viewData('page')['props']['config'];
        $this->assertSame('111', $c['meta_app_id']); $this->assertSame('222', $c['meta_config_id']); $this->assertTrue($c['meta_app_secret_set']); $this->assertArrayNotHasKey('meta_app_secret', $c);
        $this->assertTrue(WhatsappConexionService::appConfigurada());
    }

    public function test_un_vendedor_no_puede_conectar_ni_desconectar(): void
    {
        $this->appMeta();
        $v = $this->usuarioConRol('vendedor'); $this->actingAs($v); \Illuminate\Support\Facades\Auth::login($v);
        $this->post('/configuracion/tienda/whatsapp/conectar', ['code' => 'x'])->assertForbidden();
        $this->post('/configuracion/tienda/whatsapp/desconectar')->assertForbidden();
    }
    public function test_si_meta_no_deja_el_override_del_webhook_no_conecta(): void
    {
        $this->appMeta(); $this->metaFalsa([WhatsappConexionService::GRAPH . '/WABA1/subscribed_apps' => Http::response(['error' => ['message' => 'Unsupported post request']], 400)]);
        $this->post('/configuracion/tienda/whatsapp/conectar', ['code' => 'c', 'phone_number_id' => 'PH1', 'waba_id' => 'WABA1'])->assertSessionHasErrors('whatsapp');
        $this->assertArrayNotHasKey('token', $this->empresa->fresh()->whatsapp_settings ?? []);
    }

    public function test_si_el_numero_ya_esta_en_el_crm_no_lo_conecta(): void
    {
        $this->appMeta();
        $this->empresa->update(['crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => 'wh', 'lista_precios' => 1]]);
        $this->metaFalsa(['https://crm.bigsysweb.test/api/erp/numero-en-uso*' => Http::response(['en_uso' => true, 'lineas' => [['misma_empresa' => true, 'linea' => 'Central', 'id' => 55, 'tipo' => 'qr', 'conectada' => true, 'en_uso' => true]]])]);
        $this->post('/configuracion/tienda/whatsapp/conectar', ['code' => 'c', 'phone_number_id' => 'PH1', 'waba_id' => 'WABA1'])->assertSessionHasErrors('whatsapp');
        $this->assertStringContainsString('ya está conectado en el CRM', session('errors')->first('whatsapp'));
        Http::assertSent(fn($r) => str_contains($r->url(), '/api/erp/numero-en-uso') && $r->method() === 'POST' && $r['phone_number_id'] === 'PH1' && $r['emisor_cuit'] === \App\Support\Cuit::limpiar($this->empresa->fresh()->cuit) && $r->header('X-BigSys-Firma')[0] === hash_hmac('sha256', $r->body(), 'secreto-compartido-de-prueba-1234567890'));
        $this->assertStringContainsString('Central', session('errors')->first('whatsapp'));
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/subscribed_apps'));
        $this->assertArrayNotHasKey('token', $this->empresa->fresh()->whatsapp_settings ?? []);
    }

    public function test_los_avisos_de_meta_tienen_que_venir_firmados_con_el_app_secret(): void
    {
        $this->appMeta(); $this->metaFalsa();
        $this->post('/configuracion/tienda/whatsapp/conectar', ['code' => 'c', 'phone_number_id' => 'PH1', 'waba_id' => 'WABA1'])->assertSessionHas('success');
        $cuerpo = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['messages' => [['from' => '5493515550000', 'type' => 'text', 'text' => ['body' => 'hola']]]]]]]]]);
        $url = '/api/whatsapp/entrante/' . $this->empresa->id;
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $cuerpo)->assertStatus(403);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $cuerpo, 'otro-secreto')], $cuerpo)->assertStatus(403);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $cuerpo, 'secreto-de-prueba')], $cuerpo)->assertOk();
    }
}
