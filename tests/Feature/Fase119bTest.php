<?php

namespace Tests\Feature;

use App\Models\SistemaConfig;
use App\Services\IA\VozFotoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 119.2: voz por servidor. Clave de voz de BigSys para todas las empresas, transcripción y respuesta con voz natural.
class Fase119bTest extends ErpTestCase
{
    private function claveSistema(string $proveedor = 'openai'): void
    {
        SistemaConfig::set('voz_proveedor', $proveedor); SistemaConfig::set('voz_api_key', 'clave-de-bigsys');
    }

    public function test_sin_clave_no_hay_voz_por_servidor_y_el_panel_lo_sabe(): void
    {
        Http::fake();
        $this->postJson('/agente/voz', ['texto' => 'Hola'])->assertNoContent();
        $this->assertFalse($this->get('/dashboard')->viewData('page')['props']['vozServidor']);
        Http::assertNothingSent();
    }

    public function test_con_la_clave_de_bigsys_bigcito_habla_y_repite_desde_la_cache(): void
    {
        $this->claveSistema(); Cache::flush();
        Http::fake(['api.openai.com/v1/audio/speech' => Http::response(str_repeat('MP3', 200), 200, ['Content-Type' => 'audio/mpeg'])]);
        $this->assertTrue($this->get('/dashboard')->viewData('page')['props']['vozServidor']);
        $r = $this->post('/agente/voz', ['texto' => 'Listo. Te cobré $ 1.500,50 a López. https://erp.bigsysweb.com/recibo/abc'])->assertOk();
        $this->assertSame('audio/mpeg', $r->headers->get('Content-Type')); $this->assertSame(str_repeat('MP3', 200), $r->getContent());
        Http::assertSent(fn($req) => $req->hasHeader('Authorization', 'Bearer clave-de-bigsys') && $req['input'] === 'Listo. Te cobré 1500 pesos con 50 a López.' && $req['response_format'] === 'mp3' && str_contains($req['instructions'], 'rioplatense'));
        // La misma frase otra vez no vuelve a pagar.
        $this->post('/agente/voz', ['texto' => 'Listo. Te cobré $ 1.500,50 a López. https://erp.bigsysweb.com/recibo/abc'])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_la_clave_de_la_empresa_gana_y_gemini_devuelve_wav(): void
    {
        $this->claveSistema(); Cache::flush();
        $this->empresa->forceFill(['ia_settings' => ['audio' => ['proveedor' => 'gemini', 'api_key' => 'AIza-de-la-empresa']]])->save(); $this->actingAs($this->dueno->fresh());
        $pcm = str_repeat("\x01\x02", 500);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($pcm)]]]]]]])]);
        $r = $this->post('/agente/voz', ['texto' => 'Hola, ¿en qué te ayudo?'])->assertOk();
        $this->assertSame('audio/wav', $r->headers->get('Content-Type'));
        $wav = $r->getContent();
        $this->assertSame('RIFF', substr($wav, 0, 4)); $this->assertSame('WAVE', substr($wav, 8, 4)); $this->assertSame(44 + strlen($pcm), strlen($wav)); $this->assertSame(24000, unpack('V', substr($wav, 24, 4))[1]);
        Http::assertSent(fn($req) => str_contains($req->url(), 'key=AIza-de-la-empresa') && str_contains($req->url(), 'tts'));
        Http::assertNotSent(fn($req) => str_contains($req->url(), 'openai.com'));
    }

    public function test_si_el_servicio_de_voz_falla_devuelve_204_y_no_rompe(): void
    {
        $this->claveSistema(); Cache::flush();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        $this->postJson('/agente/voz', ['texto' => 'Hola'])->assertNoContent();
    }

    public function test_los_audios_se_transcriben_con_la_clave_de_bigsys_y_respetan_el_tipo_que_dice_el_navegador(): void
    {
        $this->claveSistema();
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'cuánto vendí hoy'])]);
        $r = $this->post('/agente/audio', ['audio' => UploadedFile::fake()->createWithContent('audio.m4a', 'xxxx'), 'mime' => 'audio/mp4;codecs=mp4a'])->assertOk();
        $this->assertSame('cuánto vendí hoy', $r->json('texto'));
        Http::assertSent(fn($req) => $req->hasHeader('Authorization', 'Bearer clave-de-bigsys') && str_contains($req->body(), 'audio.m4a'));
        // Conexiones IA le avisa a la empresa que ya lo tiene incluido.
        $a = $this->get('/conexiones-ia')->viewData('page')['props']['ia']['audio'];
        $this->assertTrue($a['incluida']); $this->assertFalse($a['cargada']);
    }

    public function test_lo_que_se_dice_en_voz_alta_va_limpio(): void
    {
        $this->assertSame('Vendiste 12500 pesos hoy.', VozFotoService::limpiarParaVoz("**Vendiste** \$ 12.500,00 hoy.\nSUGERENCIAS: a | b | c"));
        $this->assertSame('Total 1000 pesos con 50', VozFotoService::limpiarParaVoz('Total $1.000,50 https://x.com/y'));
        $this->assertSame(VozFotoService::MAX_HABLADO, mb_strlen(VozFotoService::limpiarParaVoz(str_repeat('palabra ', 400))));
    }

    public function test_el_superadmin_carga_la_voz_en_sistema_y_la_clave_no_se_muestra(): void
    {
        $su = \App\Models\User::withoutGlobalScopes()->where('is_superadmin', true)->first() ?? \App\Models\User::factory()->create(['is_superadmin' => true, 'business_id' => $this->empresa->id]);
        $this->actingAs($su); \Illuminate\Support\Facades\Auth::login($su);
        $this->post('/admin/sistema', ['dias_prueba' => 15, 'dias_gracia' => 7, 'aviso_dias' => '7,3,1', 'voz_proveedor' => 'gemini', 'voz_api_key' => 'AIza-x', 'voz_nombre' => 'Kore'])->assertRedirect();
        $c = $this->get('/admin/sistema')->viewData('page')['props']['config'];
        $this->assertSame('gemini', $c['voz_proveedor']); $this->assertTrue($c['voz_api_key_set']); $this->assertSame('Kore', $c['voz_nombre']); $this->assertArrayNotHasKey('voz_api_key', $c);
        $this->assertTrue(app(VozFotoService::class)->disponible($this->empresa->fresh()));
    }
}
