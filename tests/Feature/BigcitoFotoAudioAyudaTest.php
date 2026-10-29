<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\AyudaPregunta;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 40.3 y 40.4: fotos y audios para Bigcito, comprobantes sin cliente, y la ayuda que recuerda, se vota y se baja.
class BigcitoFotoAudioAyudaTest extends ErpTestCase
{
    private function mcp(string $h, array $a = []): array { return app(Catalogo::class)->llamar($this->dueno, $h, $a, 'asistente'); }

    public function test_audio_con_openai_y_con_gemini(): void
    {
        $this->actingAs($this->dueno)->post('/agente/audio', ['audio' => UploadedFile::fake()->createWithContent('a.webm', 'audio-falso')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.audio.0', fn($m) => str_contains($m, 'OpenAI'));
        $this->actingAs($this->dueno)->post('/conexiones-ia/audio', ['proveedor' => 'openai', 'clave' => 'sk-openai-test'])->assertRedirect();
        Http::fake(['api.openai.com/*' => Http::response(['text' => 'cuánto vendí hoy']), 'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'stock de cemento']]]]]])]);
        $this->actingAs($this->dueno->fresh())->post('/agente/audio', ['audio' => UploadedFile::fake()->createWithContent('a.webm', 'audio-falso')], ['Accept' => 'application/json'])->assertOk()->assertJson(['texto' => 'cuánto vendí hoy']);
        $this->actingAs($this->dueno->fresh())->post('/conexiones-ia/audio', ['proveedor' => 'gemini', 'clave' => 'AIza-test']);
        $this->actingAs($this->dueno->fresh())->post('/agente/audio', ['audio' => UploadedFile::fake()->createWithContent('a.webm', 'audio-falso')], ['Accept' => 'application/json'])->assertOk()->assertJson(['texto' => 'stock de cemento']);
        Http::assertSent(fn($r) => str_contains($r->url(), 'gemini') && str_contains(json_encode($r->data()), 'inline_data'));
    }

    public function test_la_foto_viaja_como_bloque_de_imagen(): void
    {
        $img = 'data:image/jpeg;base64,' . base64_encode('fakejpeg');
        $this->actingAs($this->dueno)->postJson('/agente/chat', ['mensaje' => '¿qué es esto?', 'imagen' => $img])->assertOk()->assertJsonPath('modo', 'local');
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-ant-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Es una bolsa de cemento.']], 'stop_reason' => 'end_turn'])]);
        $this->actingAs($this->dueno->fresh())->postJson('/agente/chat', ['mensaje' => '¿qué es esto?', 'imagen' => $img])->assertOk();
        Http::assertSent(function ($r) { $c = collect($r->data()['messages'] ?? [])->last()['content'] ?? null; return is_array($c) && ($c[0]['type'] ?? null) === 'image' && $c[0]['source']['media_type'] === 'image/jpeg'; });
    }

    public function test_comprobantes_sin_cliente_se_vinculan(): void
    {
        $cf = $this->cliente(['name' => 'Consumidor Final', 'cuit' => null]);
        $juan = $this->cliente(['name' => 'Juan Pérez', 'cuit' => '20-30111222-3']);
        $f = $this->factura($cf, [['descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 100, 'alicuota_iva' => 21]]);
        $f->forceFill(['receptor' => ['nombre' => 'JUAN PEREZ', 'documento' => '30111222']])->save();
        $r = $this->mcp('comprobantes_sin_cliente');
        $this->assertCount(1, $r['encontrados']); $this->assertSame('documento', $r['encontrados'][0]['como']);
        $p = $this->mcp('vincular_comprobantes_cliente');
        $this->mcp('confirmar_accion', ['propuesta_id' => $p['propuesta_id']]);
        $this->assertSame($juan->id, $f->fresh()->contact_id);
    }

    public function test_ayuda_guarda_vota_sin_respuesta_y_manual(): void
    {
        $r = $this->actingAs($this->dueno)->postJson('/ayuda/preguntar', ['q' => 'zzqxw inexistente', 'historial' => [['rol' => 'user', 'texto' => 'hola']]])->assertOk()->json();
        $p = AyudaPregunta::findOrFail($r['id']); $this->assertTrue((bool) $p->sin_respuesta);
        $this->actingAs($this->dueno)->postJson("/ayuda/preguntas/{$p->id}/voto", ['util' => false])->assertOk();
        $this->assertFalse((bool) $p->fresh()->util);
        $this->actingAs($this->dueno)->get('/ayuda')->assertInertia(fn($pg) => $pg->where('sin_respuesta.0.pregunta', 'zzqxw inexistente'));
        $this->actingAs($this->dueno)->get('/ayuda/manual?descargar=1')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=manual-bigsysweb.html');
        $otro = $this->usuarioConRol('vendedor', 'v@x.com');
        $this->actingAs($otro)->postJson("/ayuda/preguntas/{$p->id}/voto", ['util' => true])->assertStatus(404);
    }
}
