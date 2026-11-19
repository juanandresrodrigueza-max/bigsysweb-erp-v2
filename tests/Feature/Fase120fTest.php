<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Alerta;
use App\Models\Encargo;
use App\Models\User;
use App\Services\Equipo\EncargosService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 120.6: encargos entre personas del equipo (pantalla, Bigcito y WhatsApp).
class Fase120fTest extends ErpTestCase
{
    private array $salientes = [];
    private User $pedro;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->empresa->forceFill(['whatsapp_settings' => ['token' => 'tok', 'phone_id' => '111', 'bigcito_equipo' => true]])->save();
        $this->dueno->forceFill(['name' => 'Juan Rodríguez', 'mobile' => '3515550100'])->save();
        $this->pedro = $this->usuarioConRol('vendedor', 'pedro@test.com'); $this->pedro->forceFill(['name' => 'Pedro Gómez', 'mobile' => '3515550200'])->save();
        Http::fake(function ($req) { if (str_contains($req->url(), '/messages')) $this->salientes[] = ['a' => $req['to'], 'tipo' => $req['type'], 'texto' => $req['text']['body'] ?? null, 'plantilla' => $req['template']['name'] ?? null, 'params' => array_column($req['template']['components'][0]['parameters'] ?? [], 'text')]; return Http::response(['messages' => [['id' => 'wamid.' . count($this->salientes)]]]); });
    }

    private function como(User $u): void { Auth::login($u); $this->actingAs($u); }

    private function wa(string $texto, string $tel): string
    {
        $antes = count($this->salientes);
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => $tel, 'texto' => $texto])->assertOk();
        return implode("\n---\n", array_map(fn($s) => (string) $s['texto'], array_filter(array_slice($this->salientes, $antes), fn($s) => str_ends_with($s['a'], substr($tel, -10)))));
    }

    public function test_encargar_desde_la_pantalla_llega_por_whatsapp_y_solo_el_destinatario_lo_ve_en_la_campana(): void
    {
        $this->post('/encargos', ['para_user_id' => $this->pedro->id, 'texto' => 'Preparar el pedido 12', 'detalle' => 'Va con flete', 'vence_en' => now()->addHours(3)->format('Y-m-d\TH:i')])->assertSessionHas('success');
        $e = Encargo::firstOrFail();
        $this->assertSame('pendiente', $e->estado); $this->assertSame($this->dueno->id, $e->de_user_id); $this->assertSame($this->pedro->id, $e->para_user_id); $this->assertSame('whatsapp', $e->aviso);
        $this->assertCount(1, $this->salientes); $this->assertStringEndsWith('3515550200', $this->salientes[0]['a']);
        $this->assertStringContainsString('Juan te dejó un encargo', $this->salientes[0]['texto']); $this->assertStringContainsString('*Preparar el pedido 12*', $this->salientes[0]['texto']); $this->assertStringContainsString('Va con flete', $this->salientes[0]['texto']); $this->assertStringContainsString('Respondé HECHO', $this->salientes[0]['texto']);
        $al = Alerta::where('tipo', 'encargo')->firstOrFail(); $this->assertSame($this->pedro->id, $al->user_id);
        // Juan (que lo encargó) no lo tiene en su campana ni en su contador; Pedro sí.
        $p = $this->get('/encargos')->viewData('page')['props'];
        $this->assertSame(0, $p['encargos']); $this->assertCount(1, $p['encargue']); $this->assertCount(0, $p['paraMi']); $this->assertSame([], collect($p['alertas']['ultimas'])->where('titulo', 'Juan te dejó un encargo')->all());
        $this->como($this->pedro);
        $p = $this->get('/encargos')->viewData('page')['props'];
        $this->assertSame(1, $p['encargos']); $this->assertSame('Preparar el pedido 12', $p['paraMi'][0]['texto']); $this->assertSame('Juan Rodríguez', $p['paraMi'][0]['de']); $this->assertStringStartsWith('hoy a las', $p['paraMi'][0]['vence']);
        $this->assertCount(1, collect($p['alertas']['ultimas'])->where('titulo', 'Juan te dejó un encargo'));
        // Pedro no puede cancelarlo (no es suyo); sí puede marcarlo hecho, y Juan se entera por WhatsApp y campana.
        $this->post("/encargos/{$e->id}/cancelar")->assertSessionHasErrors('encargo');
        $this->post("/encargos/{$e->id}/hecho", ['nota' => 'Salió con el flete de las 4'])->assertSessionHas('success');
        $e->refresh(); $this->assertSame('hecho', $e->estado); $this->assertSame('Salió con el flete de las 4', $e->nota);
        $ult = end($this->salientes); $this->assertStringEndsWith('3515550100', $ult['a']); $this->assertStringContainsString('Pedro terminó lo que le encargaste', $ult['texto']); $this->assertStringContainsString('«Salió con el flete de las 4»', $ult['texto']);
        $this->assertSame($this->dueno->id, Alerta::where('tipo', 'encargo_hecho')->value('user_id')); $this->assertNotNull($al->fresh()->resuelta_en);
        // Juan lo reabre: vuelve a estar pendiente y se le avisa de nuevo a Pedro.
        $this->como($this->dueno); $n = count($this->salientes);
        $this->post("/encargos/{$e->id}/reabrir")->assertSessionHas('success');
        $this->assertSame('pendiente', $e->fresh()->estado); $this->assertCount($n + 1, $this->salientes);
        // Validaciones: fecha pasada, persona de otra empresa.
        $this->post('/encargos', ['para_user_id' => $this->pedro->id, 'texto' => 'Algo', 'vence_en' => now()->subDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('vence_en');
        [, , $ajeno] = $this->crearEmpresa('Otra', 'otra');
        $this->post('/encargos', ['para_user_id' => $ajeno->id, 'texto' => 'Algo'])->assertNotFound();
    }

    public function test_por_whatsapp_hecho_ver_y_con_varios_pide_el_numero(): void
    {
        $svc = app(EncargosService::class);
        $a = $svc->crear($this->dueno, $this->pedro, 'Llamar a López por el cheque');
        $this->assertStringContainsString('✅ Listo, marqué como hecho: Llamar a López por el cheque. Le avisé a Juan.', $this->wa('Hecho', '5493515550200'));
        $this->assertSame('hecho', $a->fresh()->estado);
        $this->assertStringContainsString('No tenés encargos pendientes', $this->wa('mis encargos', '5493515550200'));

        $b = $svc->crear($this->dueno, $this->pedro, 'Contar el stock de cemento', now()->addDay()->setTime(10, 0));
        $c = $svc->crear($this->dueno, $this->pedro, 'Ordenar el depósito');
        $r = $this->wa('HECHO', '5493515550200');
        $this->assertStringContainsString('Tenés 2 encargos pendientes. ¿Cuál terminaste?', $r); $this->assertStringContainsString('1) Contar el stock de cemento · para mañana a las 10:00 · de Juan', $r); $this->assertStringContainsString('2) Ordenar el depósito', $r);
        $this->assertSame('pendiente', $b->fresh()->estado);
        $r = $this->wa('hecho 2 Quedó todo en la Estantería B', '5493515550200');
        $this->assertStringContainsString('marqué como hecho: Ordenar el depósito', $r); $this->assertStringContainsString('Te quedan 1', $r);
        $this->assertSame('hecho', $c->fresh()->estado); $this->assertSame('Quedó todo en la Estantería B', $c->fresh()->nota); $this->assertSame('pendiente', $b->fresh()->estado);
        $this->assertStringContainsString('No encuentro el encargo 9', $this->wa('hecho 9', '5493515550200'));
        $this->assertStringContainsString('1) Contar el stock de cemento', $this->wa('Mis encargos', '5493515550200'));
        // Un cliente cualquiera que escribe «hecho» no entra por acá.
        $this->wa('hecho', '5493519999999');
        $this->assertSame('pendiente', $b->fresh()->estado);
    }

    public function test_fuera_de_las_24_horas_sale_la_plantilla_de_encargo(): void
    {
        $this->empresa->forceFill(['whatsapp_settings' => ['token' => 'tok', 'phone_id' => '111', 'waba_id' => 'W1', 'plantillas' => ['bigsys_encargo' => ['estado' => 'aprobada'], 'bigsys_aviso' => ['estado' => 'aprobada']]]])->save();
        app(EncargosService::class)->crear($this->dueno->fresh(), $this->pedro, 'Preparar el pedido 12', now()->addHours(2));
        $s = $this->salientes[0];
        $this->assertSame('template', $s['tipo']); $this->assertSame('bigsys_encargo', $s['plantilla']);
        $this->assertSame('Pedro', $s['params'][0]); $this->assertSame('Juan', $s['params'][1]); $this->assertSame('Empresa Test', $s['params'][2]); $this->assertStringStartsWith('Preparar el pedido 12 (Para hoy a las', $s['params'][3]);
        $this->assertSame('whatsapp', Encargo::first()->aviso);
    }

    public function test_bigcito_encarga_lista_y_marca_hecho(): void
    {
        $cat = app(Catalogo::class);
        $this->assertStringContainsString('No encuentro a «Ramiro»', $cat->llamar($this->dueno, 'encargar', ['para' => 'Ramiro', 'texto' => 'algo'], 'asistente')['error']);
        $p = $cat->llamar($this->dueno, 'encargar', ['para' => 'pedro', 'texto' => 'Preparar el pedido 12', 'para_cuando' => now()->addDay()->format('Y-m-d') . 'T17:00'], 'asistente');
        $this->assertSame('Encargarle a Pedro Gómez: Preparar el pedido 12', $p['titulo']); $this->assertStringContainsString('Para mañana a las 17:00', $p['detalle']); $this->assertStringContainsString('Le llega por WhatsApp', $p['detalle']);
        $this->assertSame(0, Encargo::count(), 'Sin el sí no se encarga nada');
        $r = $cat->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $this->assertStringContainsString('se lo dejé a Pedro Gómez y le avisé por WhatsApp', $r['texto']);
        $e = Encargo::firstOrFail(); $this->assertSame('bigcito', $e->origen); $this->assertSame('17:00', $e->vence_en->format('H:i'));
        // Para uno mismo, con sólo la fecha: vale hasta el final de ese día y no manda WhatsApp.
        $n = count($this->salientes);
        $p = $cat->llamar($this->dueno, 'encargar', ['para' => 'yo', 'texto' => 'Pasar por el banco', 'para_cuando' => now()->addDay()->format('Y-m-d')], 'asistente');
        $this->assertStringStartsWith('Anotarte: Pasar por el banco', $p['titulo']); $cat->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $this->assertCount($n, $this->salientes); $this->assertSame('23:59', Encargo::latest('id')->first()->vence_en->format('H:i'));
        $this->assertStringContainsString('ya pasó', $cat->llamar($this->dueno, 'encargar', ['para' => 'pedro', 'texto' => 'x', 'para_cuando' => '2020-01-01'], 'asistente')['error']);

        $m = $cat->llamar($this->dueno, 'mis_encargos', [], 'asistente');
        $this->assertCount(1, $m['tengo_que_hacer']); $this->assertSame('Pasar por el banco', $m['tengo_que_hacer'][0]['texto']); $this->assertCount(1, $m['le_encargue_a_otros']); $this->assertSame('Pedro Gómez', $m['le_encargue_a_otros'][0]['a']);
        // «Ya hice lo del banco»: inmediato, sin propuesta.
        $h = $cat->llamar($this->dueno, 'encargo_hecho', ['encargo' => 'banco'], 'asistente');
        $this->assertTrue($h['hecho']); $this->assertSame('hecho', Encargo::where('texto', 'Pasar por el banco')->value('estado'));
        // Pedro, desde su Bigcito, marca el suyo y Juan se entera.
        $hp = $cat->llamar($this->pedro, 'encargo_hecho', ['encargo' => (string) $e->id, 'nota' => 'listo'], 'asistente');
        $this->assertStringContainsString('Le avisé a Juan Rodríguez', $hp['resumen']); $this->assertSame('hecho', $e->fresh()->estado);
        $this->assertStringContainsString('No tenés encargos pendientes', $cat->llamar($this->pedro, 'encargo_hecho', ['encargo' => 'nada'], 'asistente')['error']);
    }

    public function test_lo_vencido_se_recuerda_una_vez_por_dia_y_se_le_cuenta_a_quien_lo_pidio(): void
    {
        $svc = app(EncargosService::class);
        $e = $svc->crear($this->dueno, $this->pedro, 'Mandar el presupuesto a Obras SA', now()->addHour());
        $sinPlazo = $svc->crear($this->dueno, $this->pedro, 'Ordenar la vidriera');
        $n = count($this->salientes);
        $this->artisan('encargos:recordar')->expectsOutputToContain('Encargos recordados: 0')->assertSuccessful();
        $this->travel(2)->hours();
        $this->artisan('encargos:recordar')->expectsOutputToContain('Encargos recordados: 1')->assertSuccessful();
        $this->assertCount($n + 1, $this->salientes); $this->assertStringContainsString('Sigue pendiente el encargo de Juan', end($this->salientes)['texto']);
        $at = Alerta::where('tipo', 'encargo_atrasado')->firstOrFail(); $this->assertSame($this->dueno->id, $at->user_id); $this->assertStringContainsString('Pedro todavía no hizo', $at->titulo);
        $this->assertTrue($e->fresh()->vencido()); $this->assertFalse($sinPlazo->fresh()->vencido());
        // A la hora siguiente no insiste; al otro día sí. Hecho, no se recuerda más.
        $this->travel(1)->hours(); $this->artisan('encargos:recordar')->expectsOutputToContain('Encargos recordados: 0');
        $this->travel(23)->hours(); $this->artisan('encargos:recordar')->expectsOutputToContain('Encargos recordados: 1');
        $svc->hecho($e->fresh(), $this->pedro);
        $this->travel(25)->hours(); $this->artisan('encargos:recordar')->expectsOutputToContain('Encargos recordados: 0');
    }

    public function test_sin_whatsapp_llega_a_la_campana_y_cada_empresa_ve_lo_suyo(): void
    {
        $this->pedro->forceFill(['mobile' => null])->save();
        $e = app(EncargosService::class)->crear($this->dueno, $this->pedro->fresh(), 'Revisar la caja chica');
        $this->assertSame('campana', $e->aviso); $this->assertCount(0, $this->salientes);
        $this->assertNull(app(EncargosService::class)->persona($this->dueno, 'usuario inexistente'));
        $this->assertSame($this->pedro->id, app(EncargosService::class)->persona($this->dueno, 'Pedro Gómez')->id);
        [, , $ajeno] = $this->crearEmpresa('Otra', 'otra');
        $this->como($ajeno);
        $p = $this->get('/encargos')->viewData('page')['props'];
        $this->assertCount(0, $p['paraMi']); $this->assertCount(0, $p['encargue']); $this->assertSame([$ajeno->id], array_column($p['equipo'], 'id'));
        $this->post("/encargos/{$e->id}/hecho")->assertNotFound();
    }
}
