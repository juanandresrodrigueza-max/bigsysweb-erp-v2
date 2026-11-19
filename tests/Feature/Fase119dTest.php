<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Alerta;
use App\Models\BigcitoRutina;
use App\Models\Cobro;
use App\Models\IaPropuesta;
use App\Services\IA\RutinasService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 119.4: varias acciones en un pedido y rutinas de Bigcito (informes, vigilancia y tareas que hace solo cada tanto).
class Fase119dTest extends ErpTestCase
{
    private function conIa(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-ant-test', 'modelo' => 'claude-haiku-5-5']])->save(); $this->actingAs($this->dueno->fresh());
        config(['services.anthropic.api_key' => 'sk-ant-test']);
    }

    private function rutina(array $d = []): BigcitoRutina
    {
        return app(RutinasService::class)->crear($this->dueno, $d + ['nombre' => 'Resumen', 'instruccion' => 'Pasame cuánto vendí hoy', 'frecuencia' => 'diaria', 'hora' => '08:00', 'canal' => 'aviso']);
    }

    public function test_un_pedido_con_varias_acciones_se_propone_junto_y_se_confirma_cada_una(): void
    {
        $this->conIa();
        $a = $this->cliente(['name' => 'López SRL', 'balance' => 9000]); $b = $this->cliente(['name' => 'García Hnos', 'balance' => 9000]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [
            ['type' => 'text', 'text' => 'Dale, te armo los dos cobros.'],
            ['type' => 'tool_use', 'id' => 't1', 'name' => 'registrar_cobro', 'input' => ['cliente' => 'López SRL', 'monto' => 5000, 'medio' => 'efectivo']],
            ['type' => 'tool_use', 'id' => 't2', 'name' => 'registrar_cobro', 'input' => ['cliente' => 'García Hnos', 'monto' => 3000, 'medio' => 'efectivo']],
            ['type' => 'tool_use', 'id' => 't3', 'name' => 'registrar_cobro', 'input' => ['cliente' => 'Nadie Existe', 'monto' => 10, 'medio' => 'efectivo']]]])]);
        $r = $this->postJson('/agente/chat', ['mensaje' => 'cobrale 5000 a López y 3000 a García, en efectivo'])->assertOk()->json();
        $this->assertCount(2, $r['propuestas']); $this->assertSame($r['propuestas'][0]['id'], $r['propuesta']['id']);
        $this->assertStringContainsString('López', $r['propuestas'][0]['titulo']); $this->assertStringContainsString('García', $r['propuestas'][1]['titulo']);
        $this->assertStringContainsString('No pude armar', $r['respuesta']);
        $this->assertSame(0, Cobro::count());
        foreach ($r['propuestas'] as $p) $this->postJson('/agente/ejecutar', ['propuesta_id' => $p['id']])->assertOk();
        $this->assertSame(2, Cobro::count());
        $this->assertEqualsWithDelta(5000, (float) Cobro::where('contact_id', $a->id)->sum('total'), 0.01); $this->assertEqualsWithDelta(3000, (float) Cobro::where('contact_id', $b->id)->sum('total'), 0.01);
    }

    public function test_horas_dias_y_proxima_vez(): void
    {
        $this->assertSame('08:00', RutinasService::hora('8')); $this->assertSame('18:30', RutinasService::hora('18:30')); $this->assertSame('18:00', RutinasService::hora('6 de la tarde')); $this->assertSame('07:05', RutinasService::hora('7.05')); $this->assertNull(RutinasService::hora('a la tardecita')); $this->assertNull(RutinasService::hora('25:00'));
        $this->assertSame([1, 4], RutinasService::dias(['jueves', 'Lunes'])); $this->assertSame([3, 6], RutinasService::dias('miércoles y sábado')); $this->assertSame([7], RutinasService::dias([7]));

        $mie = Carbon::parse('2026-11-18 10:00:00'); // miércoles
        $r = fn(array $d) => new BigcitoRutina($d + ['hora' => '08:00']);
        $this->assertSame('2026-11-19 08:00', RutinasService::proxima($r(['frecuencia' => 'diaria']), $mie)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-18 19:00', RutinasService::proxima($r(['frecuencia' => 'diaria', 'hora' => '19:00']), $mie)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-23 08:00', RutinasService::proxima($r(['frecuencia' => 'semanal', 'dias' => [1]]), $mie)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-20 08:00', RutinasService::proxima($r(['frecuencia' => 'semanal', 'dias' => [1, 5]]), $mie)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-23 08:00', RutinasService::proxima($r(['frecuencia' => 'habiles']), Carbon::parse('2026-11-20 09:00'))->format('Y-m-d H:i'), 'viernes después de hora → lunes');
        $this->assertSame('2026-12-01 08:00', RutinasService::proxima($r(['frecuencia' => 'mensual', 'dia_mes' => 1]), $mie)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-30 08:00', RutinasService::proxima($r(['frecuencia' => 'mensual', 'dia_mes' => 31]), $mie)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-18 10:15', RutinasService::proxima($r(['frecuencia' => 'cada_hora', 'hora' => '00:15']), $mie)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-18 11:15', RutinasService::proxima($r(['frecuencia' => 'cada_hora', 'hora' => '00:15']), Carbon::parse('2026-11-18 10:15:00'))->format('Y-m-d H:i'));
    }

    public function test_se_encarga_se_lista_se_pausa_y_se_borra_hablando(): void
    {
        $cat = app(Catalogo::class);
        $p = $cat->llamar($this->dueno, 'crear_rutina', ['instruccion' => 'Pasame cómo cerró la semana y los 5 que más deben', 'nombre' => 'Resumen del lunes', 'frecuencia' => 'semanal', 'dias' => ['lunes'], 'hora' => '8'], 'asistente');
        $this->assertSame('Rutina «Resumen del lunes»', $p['titulo']); $this->assertStringContainsString('Todos los lunes a las 08:00', $p['detalle']); $this->assertStringContainsString('campana y celular', $p['detalle']);
        $this->assertSame(0, BigcitoRutina::count());
        $r = $cat->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $this->assertStringContainsString('quedó la rutina «Resumen del lunes»', $r['texto']);
        $rut = BigcitoRutina::first(); $this->assertSame([1], $rut->dias); $this->assertSame('aviso', $rut->canal); $this->assertTrue($rut->activa); $this->assertSame(1, $rut->proxima_en->isoWeekday()); $this->assertSame($this->dueno->id, $rut->user_id);

        // Faltan datos → error claro, sin propuesta.
        $this->assertStringContainsString('qué día', $cat->llamar($this->dueno, 'crear_rutina', ['instruccion' => 'Pasame el resumen', 'frecuencia' => 'semanal'], 'asistente')['error']);
        $this->assertStringContainsString('WhatsApp', $cat->llamar($this->dueno, 'crear_rutina', ['instruccion' => 'Pasame el resumen', 'frecuencia' => 'diaria', 'canal' => 'whatsapp'], 'asistente')['error']);

        $l = $cat->llamar($this->dueno, 'mis_rutinas', [], 'asistente')['rutinas'];
        $this->assertCount(1, $l); $this->assertSame('Resumen del lunes', $l[0]['nombre']); $this->assertStringContainsString('lunes', $l[0]['cuando']);

        $p = $cat->llamar($this->dueno, 'cambiar_rutina', ['rutina' => 'resumen', 'activa' => false], 'asistente');
        $this->assertSame('Pausar la rutina «Resumen del lunes»', $p['titulo']); $cat->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $this->assertFalse($rut->fresh()->activa); $this->assertNull($rut->fresh()->proxima_en);
        $p = $cat->llamar($this->dueno, 'cambiar_rutina', ['rutina' => 'lunes', 'activa' => true, 'hora' => '9:30'], 'asistente'); $cat->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $this->assertTrue($rut->fresh()->activa); $this->assertSame('09:30', $rut->fresh()->hora);
        $p = $cat->llamar($this->dueno, 'cambiar_rutina', ['rutina' => (string) $rut->id, 'borrar' => true], 'asistente'); $cat->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $this->assertSame(0, BigcitoRutina::count());

        // Las rutinas de otro usuario no se tocan.
        $otra = $this->rutina(); $v = $this->usuarioConRol('vendedor'); $this->actingAs($v); Auth::login($v);
        $this->assertArrayHasKey('error', $cat->llamar($v, 'cambiar_rutina', ['rutina' => (string) $otra->id, 'borrar' => true], 'asistente'));
        $this->assertSame([], $cat->llamar($v, 'mis_rutinas', [], 'asistente')['rutinas']);
    }

    public function test_el_programador_corre_las_que_tocan_y_avisa_por_la_campana(): void
    {
        $this->conIa();
        Carbon::setTestNow('2026-11-18 07:00:00');
        $r = $this->rutina(); $despues = $this->rutina(['nombre' => 'A la noche', 'hora' => '22:00']);
        $this->assertSame('2026-11-18 08:00', $r->proxima_en->format('Y-m-d H:i'));
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "Hoy vendiste \$ 150.000 en 12 comprobantes.\nSUGERENCIAS: a | b"]]])]);

        $this->assertSame(0, app(RutinasService::class)->correrPendientes()['corridas'], 'todavía no es la hora');
        Carbon::setTestNow('2026-11-18 08:02:00'); Auth::logout();
        $this->artisan('bigcito:rutinas')->assertSuccessful();
        $r->refresh();
        $this->assertSame('Hoy vendiste $ 150.000 en 12 comprobantes.', $r->ultimo_resultado); $this->assertSame(1, $r->ejecuciones); $this->assertSame('2026-11-19 08:00', $r->proxima_en->format('Y-m-d H:i'));
        $al = Alerta::withoutGlobalScopes()->where('tipo', 'rutina')->where('modelo_id', $r->id)->first();
        $this->assertSame('Bigcito · Resumen', $al->titulo); $this->assertStringContainsString('150.000', $al->detalle); $this->assertSame('/rutinas', $al->url);
        $this->assertSame(0, $despues->fresh()->ejecuciones);
        Http::assertSent(fn($req) => str_contains($req['system'], 'rutina programada') && $req['messages'][0]['content'] === 'Pasame cuánto vendí hoy');
        $this->assertNull(Auth::user(), 'no deja a nadie logueado');
        // No vuelve a correr hasta mañana.
        $this->assertSame(0, app(RutinasService::class)->correrPendientes()['corridas']);
        Carbon::setTestNow();
    }

    public function test_una_rutina_de_vigilancia_no_molesta_si_no_hay_nada(): void
    {
        $this->conIa(); Carbon::setTestNow('2026-11-18 07:00:00');
        $r = $this->rutina(['nombre' => 'Vigilar límites', 'instruccion' => 'Avisame si alguien pasó su límite', 'solo_si_hay' => true]);
        Carbon::setTestNow('2026-11-18 08:01:00');
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push(['content' => [['type' => 'text', 'text' => 'NADA']]])->push(['content' => [['type' => 'text', 'text' => 'López SRL pasó su límite por $ 20.000.']]])]);
        $res = app(RutinasService::class)->correrPendientes();
        $this->assertSame(1, $res['sin_novedad']); $this->assertSame(0, Alerta::withoutGlobalScopes()->where('tipo', 'rutina')->count()); $this->assertSame('Sin novedades.', $r->fresh()->ultimo_resultado);
        Carbon::setTestNow('2026-11-19 08:01:00');
        $this->assertSame(1, app(RutinasService::class)->correrPendientes()['entregadas']);
        $this->assertSame(1, Alerta::withoutGlobalScopes()->where('tipo', 'rutina')->count());
        Http::assertSent(fn($req) => str_contains($req['system'], 'respondé exactamente NADA'));
        Carbon::setTestNow();
    }

    public function test_por_whatsapp_llega_el_informe_con_lo_propuesto_y_un_si_lo_confirma(): void
    {
        $this->conIa(); Carbon::setTestNow('2026-11-18 07:00:00');
        $this->empresa->update(['whatsapp_settings' => ['token' => 'tok', 'phone_id' => '123', 'verify_token' => 'v']]); $this->dueno->forceFill(['mobile' => '351 555-1234'])->save();
        $cli = $this->cliente(['name' => 'López SRL', 'balance' => 9000]);
        $r = $this->rutina(['nombre' => 'Cobranza', 'instruccion' => 'Cobrale lo de siempre a López', 'canal' => 'whatsapp']);
        $this->assertSame('whatsapp', $r->canal);
        Carbon::setTestNow('2026-11-18 08:01:00');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]]),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'López debe 9.000. Te propongo cobrarle 5.000.'], ['type' => 'tool_use', 'id' => 't1', 'name' => 'registrar_cobro', 'input' => ['cliente' => 'López SRL', 'monto' => 5000, 'medio' => 'efectivo']]]])]);
        Auth::logout();
        $this->assertSame(1, app(RutinasService::class)->correrPendientes()['entregadas']);
        $r->refresh(); $this->assertCount(1, $r->propuestas);
        $prop = IaPropuesta::withoutGlobalScopes()->find($r->propuestas[0]['id']);
        $this->assertSame('rutina', $prop->origen); $this->assertTrue($prop->expira_en->gt(now()->addHours(20)), 'tiene un día para confirmarla');
        Http::assertSent(fn($req) => str_contains($req->url(), '/123/messages') && str_contains($req['text']['body'], 'Cobranza') && str_contains($req['text']['body'], '➡️ Cobrar') && str_contains($req['text']['body'], 'Respondé SÍ'));
        $this->assertSame(0, Alerta::withoutGlobalScopes()->where('tipo', 'rutina')->count(), 'salió por WhatsApp, no por la campana');

        // El dueño contesta SÍ al WhatsApp y se hace.
        config(['services.anthropic.api_key' => null]);
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515551234', 'texto' => 'sí'])->assertOk()->assertJsonPath('atendidos.0', 'bigcito');
        $this->assertSame(1, Cobro::withoutGlobalScopes()->count()); $this->assertEqualsWithDelta(5000, (float) Cobro::withoutGlobalScopes()->where('contact_id', $cli->id)->sum('total'), 0.01);
        Carbon::setTestNow();
    }

    public function test_la_pantalla_de_rutinas_crea_prueba_pausa_y_borra_solo_las_propias(): void
    {
        $this->conIa();
        $this->get('/rutinas')->assertOk()->assertInertia(fn($p) => $p->component('Rutinas/Index', false)->where('rutinas', [])->has('ejemplos', 5)->where('canalDefecto', 'aviso'));
        $this->post('/rutinas', ['instruccion' => 'Pasame cuánto vendí', 'frecuencia' => 'habiles', 'hora' => '19:00', 'canal' => 'aviso'])->assertRedirect()->assertSessionHas('success');
        $r = BigcitoRutina::first(); $this->assertSame('habiles', $r->frecuencia); $this->assertSame('Pasame cuánto vendí', $r->nombre);
        $this->post('/rutinas', ['instruccion' => 'x', 'frecuencia' => 'diaria', 'hora' => '08:00'])->assertSessionHasErrors('instruccion');
        $this->post('/rutinas', ['instruccion' => 'Pasame el resumen', 'frecuencia' => 'semanal', 'hora' => '08:00', 'dias' => []])->assertSessionHasErrors('dias');

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Vendiste $ 10.']]])]);
        $this->post("/rutinas/{$r->id}/probar")->assertRedirect()->assertSessionHas('prueba', fn($x) => $x['texto'] === 'Vendiste $ 10.' && $x['id'] === $r->id);
        $this->assertSame(0, $r->fresh()->ejecuciones, 'probar no cuenta ni mueve el horario'); $this->assertSame(0, Alerta::where('tipo', 'rutina')->count());

        $this->post("/rutinas/{$r->id}", ['instruccion' => 'Pasame cuánto vendí y cobré', 'frecuencia' => 'diaria', 'hora' => '20:15', 'canal' => 'mail', 'solo_si_hay' => true])->assertRedirect();
        $r->refresh(); $this->assertSame('20:15', $r->hora); $this->assertSame('mail', $r->canal); $this->assertTrue($r->solo_si_hay);
        $this->post("/rutinas/{$r->id}/alternar")->assertRedirect(); $this->assertFalse($r->fresh()->activa);

        $v = $this->usuarioConRol('vendedor'); $this->actingAs($v); Auth::login($v);
        $this->get('/rutinas')->assertOk()->assertInertia(fn($p) => $p->where('rutinas', []));
        $this->post("/rutinas/{$r->id}/alternar")->assertNotFound(); $this->delete("/rutinas/{$r->id}")->assertNotFound();
        $this->actingAs($this->dueno); Auth::login($this->dueno);
        $this->delete("/rutinas/{$r->id}")->assertRedirect(); $this->assertSame(0, BigcitoRutina::count());
    }
}
