<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Cada empresa carga su propia clave de IA y paga su consumo; la clave del sistema no la usan las empresas.
class IaEmpresaTest extends ErpTestCase
{
    public function test_sin_clave_propia_el_asistente_queda_en_modo_basico_aunque_el_sistema_tenga_clave(): void
    {
        config(['services.anthropic.api_key' => 'sk-ant-del-sistema']);
        Http::fake();
        $this->postJson('/agente/chat', ['mensaje' => 'hola'])->assertOk()->assertJsonPath('modo', 'local');
        Http::assertNothingSent();
    }

    public function test_carga_prueba_y_usa_la_clave_de_la_empresa(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push(['error' => ['message' => 'invalid x-api-key']], 401)->push(['content' => [['type' => 'text', 'text' => 'OK']]])->push(['content' => [['type' => 'text', 'text' => 'Hola desde tu IA']]])]);
        $this->post('/conexiones-ia/clave', ['api_key' => 'nada', 'modelo' => 'claude-haiku-5-5'])->assertSessionHasErrors('api_key');
        $this->post('/conexiones-ia/clave', ['api_key' => 'sk-ant-mala', 'modelo' => 'claude-haiku-5-5'])->assertSessionHasErrors(['api_key' => 'La clave no es válida.']);
        $this->post('/conexiones-ia/clave', ['api_key' => 'sk-ant-buena-1234', 'modelo' => 'claude-sonnet-5-5'])->assertSessionHasNoErrors();
        $b = $this->empresa->fresh();
        $this->assertSame('sk-ant-buena-1234', $b->ia_settings['api_key']);
        $this->assertStringNotContainsString('sk-ant-buena', (string) \DB::table('businesses')->where('id', $b->id)->value('ia_settings'), 'Se guarda cifrada');
        $this->get('/conexiones-ia')->assertInertia(fn($i) => $i->where('ia.cargada', true)->where('ia.mascara', 'sk-ant-…1234')->where('ia.modelo', 'claude-sonnet-5-5'));

$this->postJson('/agente/chat', ['mensaje' => 'hola'])->assertOk()->assertJsonPath('modo', 'ia')->assertJsonPath('respuesta', 'Hola desde tu IA');
        Http::assertSent(fn($r) => $r->hasHeader('x-api-key', 'sk-ant-buena-1234') && $r['model'] === 'claude-sonnet-5-5' && isset($r['tools']));

        // Otra empresa no la usa.
        [$otra, , $duenoB] = $this->crearEmpresa('Otra', 'otra-ia');
        $this->actingAs($duenoB)->postJson('/agente/chat', ['mensaje' => 'hola'])->assertJsonPath('modo', 'local');

        // Un vendedor no la puede cambiar; el dueño la puede quitar.
        $this->actingAs($this->usuarioConRol('vendedor'))->post('/conexiones-ia/clave', ['api_key' => 'sk-ant-x', 'modelo' => 'claude-haiku-5-5'])->assertStatus(403);
        $this->actingAs($this->dueno)->delete('/conexiones-ia/clave')->assertRedirect();
        $this->assertNull($this->empresa->fresh()->ia_settings);
    }
}
