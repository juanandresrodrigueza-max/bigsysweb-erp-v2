<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\BigcitoMemoria;
use App\Services\IA\MemoriaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 119.5: la memoria de Bigcito (apodos, valores por defecto y preferencias por usuario y por empresa).
class Fase119eTest extends ErpTestCase
{
    public function test_anota_reemplaza_lista_y_olvida_respetando_quien_puede_lo_de_la_empresa(): void
    {
        $cat = app(Catalogo::class); $svc = app(MemoriaService::class);
        $r = $cat->llamar($this->dueno, 'recordar', ['texto' => 'acordate que Pepe es Pérez SRL'], 'asistente');
        $this->assertSame('Pepe es Pérez SRL', $r['anotado']); $this->assertFalse($r['reemplazo_una_anterior']); $this->assertSame('este usuario', $r['vale_para']);
        // Mismo apodo: se pisa, no se duplica.
        $r = $cat->llamar($this->dueno, 'recordar', ['texto' => 'Pepe es Pérez Hermanos'], 'asistente');
        $this->assertTrue($r['reemplazo_una_anterior']); $this->assertSame(1, BigcitoMemoria::count()); $this->assertSame('Pepe es Pérez Hermanos', BigcitoMemoria::first()->texto);
        $cat->llamar($this->dueno, 'recordar', ['texto' => 'Los pedidos de obra se entregan los martes', 'para_toda_la_empresa' => true], 'asistente');
        $cat->llamar($this->dueno, 'recordar', ['texto' => 'Contestame corto'], 'asistente');
        $this->assertSame(3, BigcitoMemoria::count()); $this->assertNull(BigcitoMemoria::where('texto', 'like', 'Los pedidos%')->value('user_id'));
        $l = $cat->llamar($this->dueno, 'que_recordas', [], 'asistente')['recuerdos'];
        $this->assertCount(3, $l); $this->assertTrue($l[0]['de_la_empresa'], 'lo de la empresa va primero');
        $this->assertArrayHasKey('error', $cat->llamar($this->dueno, 'recordar', ['texto' => 'x'], 'asistente'));

        // Un vendedor: ve lo de la empresa, no lo del dueño; no puede dejar ni borrar cosas de toda la empresa.
        $v = $this->usuarioConRol('vendedor'); $this->actingAs($v); Auth::login($v);
        $this->assertSame(['Los pedidos de obra se entregan los martes'], array_column($svc->para($v), 'texto'));
        $this->assertStringContainsString('dueño', $cat->llamar($v, 'recordar', ['texto' => 'Regla nueva para todos', 'para_toda_la_empresa' => true], 'asistente')['error']);
        $this->assertStringContainsString('dueño', $cat->llamar($v, 'olvidar', ['que' => 'pedidos de obra'], 'asistente')['error']);
        $cat->llamar($v, 'recordar', ['texto' => 'Mi caja es la Caja 2'], 'asistente');
        $this->assertSame('Mi caja es la Caja 2', $cat->llamar($v, 'olvidar', ['que' => 'lo de la caja'], 'asistente')['olvidado']);

        $this->actingAs($this->dueno); Auth::login($this->dueno);
        $this->assertSame('Pepe es Pérez Hermanos', $cat->llamar($this->dueno, 'olvidar', ['que' => 'pepe'], 'asistente')['olvidado']);
        $this->assertArrayHasKey('error', $cat->llamar($this->dueno, 'olvidar', ['que' => 'algo que nunca anoté zzz'], 'asistente'));
        $this->assertSame(2, BigcitoMemoria::count());
    }

    public function test_bigcito_tiene_presente_lo_anotado_en_cada_charla(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-ant-test', 'modelo' => 'claude-haiku-5-5']])->save(); $this->actingAs($this->dueno->fresh());
        $svc = app(MemoriaService::class);
        $svc->recordar($this->dueno, 'Pepe es Pérez SRL'); $svc->recordar($this->dueno, 'La caja del local es la Caja Central', true);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Dale.']]])]);
        $this->postJson('/agente/chat', ['mensaje' => 'cobrale 5000 a Pepe'])->assertOk();
        Http::assertSent(fn($r) => str_contains($r['system'], 'Lo que te pidieron recordar') && str_contains($r['system'], '- Pepe es Pérez SRL') && str_contains($r['system'], '- La caja del local es la Caja Central (vale para toda la empresa)') && str_contains($r['system'], 'nunca te autorizan a saltear la confirmación'));

        // Otro usuario: sólo lo de la empresa.
        $v = $this->usuarioConRol('vendedor'); $this->actingAs($v); Auth::login($v);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Dale.']]])]);
        $this->postJson('/agente/chat', ['mensaje' => 'hola'])->assertOk();
        Http::assertSent(fn($r) => str_contains($r['system'], 'Caja Central') && ! str_contains($r['system'], 'Pepe es Pérez SRL'));
    }

    public function test_sin_clave_de_ia_tambien_anota_lista_y_olvida(): void
    {
        config(['services.anthropic.api_key' => null]);
        $this->assertSame('Anotado: Pepe es Pérez SRL', $this->postJson('/agente/chat', ['mensaje' => 'Acordate que Pepe es Pérez SRL'])->json('respuesta'));
        $this->assertStringContainsString('- Pepe es Pérez SRL', $this->postJson('/agente/chat', ['mensaje' => '¿Qué recordás?'])->json('respuesta'));
        $this->assertStringContainsString('me olvidé de: Pepe es Pérez SRL', $this->postJson('/agente/chat', ['mensaje' => 'Olvidate de lo de Pepe'])->json('respuesta'));
        $this->assertSame(0, BigcitoMemoria::count());
        $this->assertStringContainsString('Todavía no me pediste', $this->postJson('/agente/chat', ['mensaje' => 'qué recordás'])->json('respuesta'));
    }

    public function test_la_pantalla_muestra_anota_y_borra(): void
    {
        $this->post('/rutinas/memoria', ['texto' => 'A López siempre con la lista 2', 'empresa' => true])->assertRedirect()->assertSessionHas('success');
        $this->post('/rutinas/memoria', ['texto' => 'abc'])->assertSessionHasErrors('texto');
        $m = BigcitoMemoria::first();
        $this->get('/rutinas')->assertInertia(fn($p) => $p->where('memoria.0.texto', 'A López siempre con la lista 2')->where('memoria.0.de_la_empresa', true)->where('puedeMemoriaEmpresa', true));
        $v = $this->usuarioConRol('vendedor'); $this->actingAs($v); Auth::login($v);
        $this->get('/rutinas')->assertInertia(fn($p) => $p->has('memoria', 1)->where('puedeMemoriaEmpresa', false));
        $this->delete("/rutinas/memoria/{$m->id}")->assertSessionHasErrors('memoria'); $this->assertSame(1, BigcitoMemoria::count());
        $this->actingAs($this->dueno); Auth::login($this->dueno);
        $this->delete("/rutinas/memoria/{$m->id}")->assertRedirect(); $this->assertSame(0, BigcitoMemoria::count());
    }
}
