<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\SistemaConfig;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 67: Bigcito atiende primero los tickets de soporte con el manual y deriva lo que no sabe.
class SoporteBigcitoFase67Test extends ErpTestCase
{
    public function test_responde_con_las_guias_sin_ia_y_deriva_lo_que_no_cubre(): void
    {
        $this->post('/soporte', ['asunto' => 'No sé cómo anular una factura', 'categoria' => 'consulta', 'prioridad' => 'normal', 'mensaje' => '¿Cómo anulo una factura que emití mal?'])->assertSessionHas('success');
        $t = Ticket::latest('id')->first(); $m = collect($t->mensajes);
        $this->assertCount(2, $m); $this->assertSame('bigcito', $m[1]['de']); $this->assertSame('guias', $m[1]['modo']); $this->assertFalse($m[1]['escalado']);
        $this->assertStringContainsString('esto de las guías puede servirte', $m[1]['texto']); $this->assertNotEmpty($m[1]['fuentes']); $this->assertStringContainsString('/ayuda/', $m[1]['fuentes'][0]['url']);
        $this->assertSame('abierto', $t->estado);
        $props = $this->get('/soporte')->assertOk()->viewData('page')['props'];
        $this->assertSame('bigcito', $props['tickets'][0]['ultimo_de']);

        // Lo que el manual no cubre, o es de facturación, va directo al equipo.
        $this->post('/soporte', ['asunto' => 'Quiero cambiar de plan', 'categoria' => 'facturacion', 'prioridad' => 'baja', 'mensaje' => 'Quiero pasar al plan Pro y que me facturen a otra razón social']);
        $t2 = Ticket::latest('id')->first(); $u = collect($t2->mensajes)->last();
        $this->assertSame('bigcito', $u['de']); $this->assertTrue($u['escalado']); $this->assertStringContainsString('persona del equipo', $u['texto']); $this->assertSame('normal', $t2->fresh()->prioridad, 'Se sube de baja a normal para que alguien lo mire');

        // "No me sirvió" → se deriva; una respuesta normal del usuario no dispara a Bigcito otra vez.
        $this->post("/soporte/{$t->id}/responder", ['mensaje' => 'No me sirvió, sigue igual. Quiero hablar con una persona.']);
        $t->refresh(); $u = collect($t->mensajes)->last(); $this->assertSame('bigcito', $u['de']); $this->assertTrue($u['escalado']); $this->assertStringContainsString('Entendido', $u['texto']);
        $n = count($t->mensajes);
        $this->post("/soporte/{$t->id}/responder", ['mensaje' => 'Gracias, espero.']);
        $this->assertCount($n + 1, $t->fresh()->mensajes, 'Bigcito no contesta dos veces');
    }

    public function test_con_la_clave_del_sistema_responde_con_pasos_y_escala_cuando_la_ia_lo_dice(): void
    {
        SistemaConfig::set('ia_api_key', 'sk-sistema');
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => 'Entrá a Comprobantes, abrí la factura y tocá «Anular»: se emite la nota de crédito y queda todo revertido.']]])
            ->push(['content' => [['type' => 'text', 'text' => 'ESCALAR']]])]);
        $this->post('/soporte', ['asunto' => 'Anular factura', 'categoria' => 'consulta', 'prioridad' => 'normal', 'mensaje' => '¿Cómo anulo una factura?']);
        $u = collect(Ticket::latest('id')->first()->mensajes)->last();
        $this->assertSame('ia', $u['modo']); $this->assertStringContainsString('tocá «Anular»', $u['texto']); $this->assertStringContainsString('Si te sirvió, cerrá el ticket', $u['texto']);
        $this->assertSame('sk-sistema', Http::recorded()[0][0]->header('x-api-key')[0]);
        $this->assertStringContainsString('ESCALAR', Http::recorded()[0][0]->data()['system']);

        $this->post('/soporte', ['asunto' => 'Error raro', 'categoria' => 'error', 'prioridad' => 'alta', 'mensaje' => 'Al anular la factura el sistema tira error 500']);
        $u = collect(Ticket::latest('id')->first()->mensajes)->last();
        $this->assertTrue($u['escalado']); $this->assertStringContainsString('no lo resuelven las guías', $u['texto']);

        // Bigcito (el asistente) también abre tickets por voz, y el ticket nace atendido.
        $r = app(Catalogo::class)->llamar($this->dueno, 'abrir_ticket_soporte', ['asunto' => 'Cómo cargo un remito', 'mensaje' => 'No encuentro dónde se carga un remito'], 'asistente');
        app(Catalogo::class)->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $r['propuesta_id']], 'asistente');
        $t = Ticket::latest('id')->first(); $this->assertSame('Cómo cargo un remito', $t->asunto); $this->assertSame('bigcito', collect($t->mensajes)->last()['de']);
    }
}
