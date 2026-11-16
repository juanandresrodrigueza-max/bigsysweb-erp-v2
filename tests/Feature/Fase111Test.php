<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Cobro;
use App\Models\IaPropuesta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 111: Bigcito por WhatsApp para el equipo (texto o audio, con «sí» para ejecutar) y el celular de cada usuario.
class Fase111Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => null]); // modo local: frases entendidas sin IA
        $this->empresa->update(['whatsapp_settings' => ['token' => 'tok', 'phone_id' => '123', 'verify_token' => 'v']]);
        $this->dueno->forceFill(['mobile' => '351 555-1234'])->save();
        Http::fake(['graph.facebook.com/v19.0/123/messages' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        Auth::logout(); // el webhook llega sin sesión
    }

    private function enviados(): array
    {
        $out = [];
        Http::assertSent(function ($r) use (&$out) { if (str_contains($r->url(), '/123/messages')) $out[] = $r['text']['body']; return true; });
        return $out;
    }

    public function test_el_dueno_le_pide_un_cobro_por_whatsapp_y_lo_confirma_con_si(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente(['name' => 'Ferretería López']);
        Auth::login($this->dueno); $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 10000]]); Auth::logout();

        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515551234', 'texto' => 'Cobrale 5000 a López en efectivo'])->assertOk()->json();
        $this->assertSame(['bigcito'], $r['atendidos']);
        $this->assertSame(0, Cobro::withoutGlobalScopes()->count(), 'Todavía no hizo nada: espera el sí');
        $msgs = $this->enviados();
        $this->assertStringContainsString('Cobrar $ 5.000,00 a Ferretería López', $msgs[0]);
        $this->assertStringContainsString('Respondé SÍ para hacerlo', $msgs[0]);
        $this->assertSame('whatsapp', IaPropuesta::withoutGlobalScopes()->latest('id')->value('origen'));

        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515551234', 'texto' => 'Sí, dale'])->assertOk()->assertJsonPath('atendidos.0', 'bigcito');
        $this->assertSame(1, Cobro::withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(7100, (float) $cli->fresh()->balance, 0.01);
        $msgs = $this->enviados(); $this->assertStringNotContainsString('No se pudo', end($msgs) ?: '');
        $this->assertNull(Auth::user(), 'El webhook no deja una sesión abierta');
    }

    public function test_no_cancela_la_propuesta_y_una_consulta_responde_con_datos(): void
    {
        $p = $this->articulo(['name' => 'Cemento x 50 kg', 'sku' => 'CEM', 'stock_inicial' => 30, 'price' => 9000]);
        $cli = $this->cliente(['name' => 'Constructora del Valle SA']);
        Auth::login($this->dueno); $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 9000]]); Auth::logout();
        $tel = '5493515551234';
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => $tel, 'texto' => 'cobrale 1000 a Constructora en efectivo'])->assertOk();
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => $tel, 'texto' => 'no, dejá'])->assertOk();
        $this->assertSame(0, Cobro::withoutGlobalScopes()->count());
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => $tel, 'texto' => 'cuánto vendí hoy'])->assertOk();
        $msgs = $this->enviados();
        $this->assertSame('Listo, no lo hago.', $msgs[1]);
        $this->assertStringContainsString('Ventas hoy: $ 21.780', $msgs[2]);
    }

    public function test_un_numero_que_no_es_del_equipo_sigue_como_cliente(): void
    {
        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493519990000', 'texto' => 'hola, quiero 2 bolsas de cemento'])->assertOk()->json();
        $this->assertNotContains('bigcito', $r['atendidos']);
        $this->assertSame(0, IaPropuesta::withoutGlobalScopes()->count());
    }

    public function test_se_apaga_con_el_tilde_bigcito_equipo(): void
    {
        $this->empresa->update(['whatsapp_settings' => ['token' => 'tok', 'phone_id' => '123', 'bigcito_equipo' => false]]);
        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515551234', 'texto' => 'cuánto vendí hoy'])->assertOk()->json();
        $this->assertNotContains('bigcito', $r['atendidos']);
    }

    private function payloadAudio(string $de, string $id = 'MEDIA1'): array
    {
        return ['entry' => [['changes' => [['value' => ['messages' => [['from' => $de, 'type' => 'audio', 'audio' => ['id' => $id, 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]]]]]]]]];
    }

    public function test_un_audio_sin_clave_de_transcripcion_pide_el_texto(): void
    {
        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", $this->payloadAudio('5493515551234'))->assertOk()->json();
        $this->assertSame(['bigcito'], $r['atendidos']);
        $this->assertStringContainsString('clave de OpenAI o de Gemini', $this->enviados()[0]);
        $this->assertStringContainsString('escribime el mensaje', $this->enviados()[0]);
    }

    public function test_un_audio_del_equipo_se_transcribe_y_se_atiende(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['audio' => ['proveedor' => 'openai', 'api_key' => 'sk-test']]])->save();
        Http::fake([
            'graph.facebook.com/v19.0/MEDIA1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => 'audio/ogg; codecs=opus']),
            'lookaside.fbsbx.com/*' => Http::response('OggS-bytes'),
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => '¿Quiénes me deben?']),
            'graph.facebook.com/v19.0/123/messages' => Http::response(['messages' => [['id' => 'wamid.2']]]),
        ]);
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente(['name' => 'Ferretería López']);
        Auth::login($this->dueno); $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 10000]]); Auth::logout();
        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", $this->payloadAudio('5493515551234'))->assertOk()->json();
        $this->assertSame(['bigcito'], $r['atendidos']);
        Http::assertSent(fn($req) => str_contains($req->url(), 'audio/transcriptions'));
        $this->assertStringContainsString('Ferretería López $ 12.100', $this->enviados()[0]);
    }

    public function test_un_audio_de_un_cliente_no_se_procesa(): void
    {
        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", $this->payloadAudio('5493519990000'))->assertOk()->json();
        $this->assertSame([], $r['atendidos']); $this->assertSame([], $r['pedidos']);
        $this->assertSame(0, Alerta::withoutGlobalScopes()->where('tipo', 'whatsapp_sin_pedido')->count());
    }

    public function test_usuarios_guarda_el_celular_y_bigcito_lo_reconoce_con_o_sin_prefijo(): void
    {
        Auth::login($this->dueno); $this->actingAs($this->dueno);
        $u = $this->usuarioConRol('vendedor');
        $this->post("/configuracion/usuarios/{$u->id}", ['name' => $u->name, 'email' => $u->email, 'mobile' => '11 4444-5555', 'status' => 'active', 'role_id' => $u->role_id, 'sucursales' => [['id' => $this->sucursal->id, 'role_id' => $u->role_id]]])->assertSessionHasNoErrors();
        $this->assertSame('11 4444-5555', $u->fresh()->mobile);
        $svc = app(\App\Services\IA\BigcitoWhatsappService::class);
        $this->assertSame($u->id, $svc->usuario($this->empresa, '5491144445555')?->id);
        $this->assertSame($u->id, $svc->usuario($this->empresa, '1144445555')?->id);
        $this->assertSame($this->dueno->id, $svc->usuario($this->empresa, '54 9 351 555 1234')?->id);
        $this->assertNull($svc->usuario($this->empresa, '5493510000000'));
        $u->forceFill(['status' => 'inactive'])->save();
        $this->assertNull($svc->usuario($this->empresa, '5491144445555'), 'Un usuario inactivo no le habla a Bigcito');
    }
}
