<?php

namespace Tests\Feature;

use App\Models\DispositivoMovil;
use App\Models\SistemaConfig;
use App\Services\Avisos\CelularService;
use App\Services\Avisos\PushNativoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 93: el ERP listo para las apps nativas: el celular se registra, los avisos salen por FCM/APNs y el superadmin carga Firebase.
class MovilFase93Test extends ErpTestCase
{
    private function cuentaFirebase(): array
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); openssl_pkey_export($k, $pem);
        return ['type' => 'service_account', 'project_id' => 'bigsys-test', 'client_email' => 'fcm@bigsys-test.iam.gserviceaccount.com', 'private_key' => $pem];
    }

    public function test_la_app_registra_el_celular_y_se_da_de_baja(): void
    {
        $this->actingAs($this->dueno)->postJson('/movil/dispositivo', ['token' => str_repeat('a', 40), 'plataforma' => 'ios', 'modelo' => 'Apple iPhone 15 · ios 18.1', 'version_app' => '1.0.0 (3)'])->assertOk()->assertJsonPath('push', false);
        $this->actingAs($this->dueno)->postJson('/movil/dispositivo', ['token' => str_repeat('a', 40), 'plataforma' => 'ios'])->assertOk(); // el mismo token no se duplica
        $this->assertSame(1, DispositivoMovil::count()); $d = DispositivoMovil::first(); $this->assertSame('ios', $d->plataforma); $this->assertSame($this->empresa->id, $d->business_id); $this->assertStringContainsString('iPhone', $d->modelo);
        $this->actingAs($this->dueno)->postJson('/movil/dispositivo', ['token' => 'corto', 'plataforma' => 'ios'])->assertStatus(422);
        $this->actingAs($this->dueno)->postJson('/movil/dispositivo', ['token' => str_repeat('b', 40), 'plataforma' => 'windows'])->assertStatus(422);
        $this->actingAs($this->dueno)->postJson('/movil/dispositivo/baja', ['token' => str_repeat('a', 40)])->assertOk()->assertJsonPath('borrados', 1);
        $this->assertSame(0, DispositivoMovil::count());
        $this->postJson('/logout'); auth()->logout(); $this->flushSession();
        $this->postJson('/movil/dispositivo', ['token' => str_repeat('a', 40), 'plataforma' => 'android'])->assertStatus(401);
    }

    public function test_los_avisos_salen_por_fcm_a_los_celulares_y_los_tokens_muertos_se_borran(): void
    {
        SistemaConfig::set('fcm_service_account', json_encode($this->cuentaFirebase()));
        $svc = app(PushNativoService::class); $this->assertTrue($svc->configurado());
        $this->actingAs($this->dueno);
        $svc->registrar($this->dueno, ['token' => 'tok-vivo-' . str_repeat('x', 30), 'plataforma' => 'android']);
        $svc->registrar($this->dueno, ['token' => 'tok-muerto-' . str_repeat('y', 30), 'plataforma' => 'ios']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/v1/projects/bigsys-test/messages:send' => function ($req) { return str_contains($req->body(), 'tok-muerto') ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404) : Http::response(['name' => 'projects/bigsys-test/messages/1']); }]);
        Cache::forget('fcm_token_' . md5('fcm@bigsys-test.iam.gserviceaccount.com'));
        $n = app(CelularService::class)->enviar($this->dueno, 'Te llaman de la mesa 4', 'Pide la cuenta', '/gastronomia/mozo');
        $this->assertSame(1, $n, 'Llegó al celular vivo; el muerto no cuenta');
        $this->assertSame(1, DispositivoMovil::count()); $this->assertStringStartsWith('tok-vivo', DispositivoMovil::first()->token); $this->assertNotNull(DispositivoMovil::first()->ultimo_envio_en);
        Http::assertSent(fn($r) => str_contains($r->url(), 'oauth2.googleapis.com') && str_contains($r->body(), 'jwt-bearer'));
        Http::assertSent(function ($r) { if (! str_contains($r->url(), 'messages:send')) return false; $j = $r->data(); return $j['message']['notification']['title'] === 'Te llaman de la mesa 4' && $j['message']['data']['url'] === '/gastronomia/mozo' && $j['message']['android']['notification']['channel_id'] === 'bigsys' && $j['message']['apns']['payload']['aps']['sound'] === 'default' && $r->hasHeader('Authorization', 'Bearer ya29.test'); });
        // Sin cuenta de Firebase no manda nada y no rompe.
        SistemaConfig::set('fcm_service_account', null); Cache::flush();
        $this->assertSame(0, app(PushNativoService::class)->enviar($this->dueno, 'x', 'y'));
    }

    public function test_el_superadmin_carga_firebase_y_los_links_de_las_tiendas_y_prueba_el_aviso(): void
    {
        $super = \App\Models\User::create(['name' => 'Soporte BigSys', 'email' => 'super93@bigsys.com.ar', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
        $this->actingAs($super)->post('/admin/sistema', ['dias_prueba' => 15, 'dias_gracia' => 7, 'fcm_service_account' => 'no es json'])->assertSessionHasErrors('fcm_service_account');
        $this->actingAs($super)->post('/admin/sistema', ['dias_prueba' => 15, 'dias_gracia' => 7, 'fcm_service_account' => json_encode($this->cuentaFirebase()), 'app_android_url' => 'https://play.google.com/store/apps/details?id=ar.com.bigsys.erp', 'app_ios_url' => 'https://apps.apple.com/ar/app/bigsys/id123'])->assertSessionHas('success');
        $raw = \Illuminate\Support\Facades\DB::table('sistema_config')->where('clave', 'fcm_service_account')->value('valor');
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $raw, 'La cuenta de servicio va cifrada');
        $p = $this->actingAs($super)->get('/admin/sistema')->assertOk()->viewData('page')['props'];
        $this->assertTrue($p['movil']['fcm_set']); $this->assertSame('bigsys-test', $p['movil']['fcm_proyecto']); $this->assertStringContainsString('play.google.com', $p['movil']['app_android_url']);
        // Probar el aviso sin celular registrado: avisa que no llegó a nadie.
        $this->actingAs($super)->post('/admin/sistema/probar-push')->assertSessionHas('error');
        // La pantalla de avisos al celular muestra los links a las tiendas.
        $t = $this->actingAs($this->dueno)->get('/avisos-celular')->assertOk()->viewData('page')['props']['tiendas'];
        $this->assertStringContainsString('apps.apple.com', $t['ios']);
        $this->assertFileExists(base_path('capacitor.config.json')); $this->assertSame('ar.com.bigsys.erp', json_decode(file_get_contents(base_path('capacitor.config.json')), true)['appId']);
        $this->assertFileExists(base_path('android/app/src/main/AndroidManifest.xml')); $this->assertFileExists(base_path('ios/App/App/Info.plist'));
        $this->assertStringContainsString('NSMicrophoneUsageDescription', file_get_contents(base_path('ios/App/App/Info.plist')));
    }
}
