<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Services\Empresas\InductorService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 48: Bigcito entrevista a la empresa nueva según su rubro, deja el sistema a medida y después sigue lo que usa y lo que no.
class InductorBigcitoTest extends ErpTestCase
{
    public function test_las_preguntas_dependen_del_rubro(): void
    {
        $i = app(InductorService::class);
        $this->assertArrayHasKey('reparto', $i->paraRubro('corralon'));
        $this->assertArrayNotHasKey('agenda', $i->paraRubro('corralon'));
        $this->assertArrayHasKey('agenda', $i->paraRubro('servicios'));
        $this->assertArrayNotHasKey('reparto', $i->paraRubro('servicios'));
    }

    public function test_entrevista_deja_el_sistema_a_medida_y_suma_pasos(): void
    {
        $this->get('/primeros-pasos')->assertOk()->assertInertia(fn($p) => $p->where('induccion.hecha', false)->has('induccion.preguntas.rubro')->etc());
        $this->post('/primeros-pasos/induccion', ['respuestas' => ['rubro' => 'corralon', 'como_vende' => 'cuenta', 'arca' => 'si', 'stock' => 'si', 'sucursales' => 'una', 'reparto' => 'si', 'tienda' => 'no', 'produccion' => 'no', 'obras' => 'no', 'sueldos' => 'contador', 'contable' => 'contador', 'inventada' => 'x']])->assertSessionHas('success');
        $b = $this->empresa->fresh();
        $this->assertSame('corralon', $b->vertical);
        $this->assertEqualsCanonicalizing(['produccion', 'obras', 'agenda', 'sueldos'], $b->modulos_ocultos);
        $this->assertNotContains('produccion', $b->modulosActivos());
        $this->assertContains('stock', $b->modulosActivos(), 'Los módulos base nunca se ocultan');
        $this->assertContains('reparto', $b->induccion['usos']);
        $this->assertArrayNotHasKey('inventada', $b->induccion['respuestas']);

        $pasos = collect($this->get('/primeros-pasos')->viewData('page')['props']['pasos']);
        $this->assertTrue($pasos->contains('key', 'ind_reparto'));
        $this->assertTrue($pasos->contains('key', 'ind_contador'));
        $this->assertFalse($pasos->contains('key', 'ind_tienda'));
        $this->assertFalse($pasos->firstWhere('key', 'ind_reparto')['hecho']);

        // Seguimiento: dijo reparto y no armó ningún viaje → Bigcito lo sugiere. Volver a mostrar un módulo oculto.
        $seg = collect(app(InductorService::class)->seguimiento($b));
        $this->assertTrue($seg->contains('uso', 'reparto'));
        $this->post('/primeros-pasos/mostrar-modulo', ['modulo' => 'produccion'])->assertSessionHas('success');
        $this->assertContains('produccion', $this->empresa->fresh()->modulosActivos());
    }

    public function test_contar_en_palabras_y_bigcito_lo_sabe(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"rubro":"corralon","reparto":"si","tienda":"si","sueldos":"cualquiera"}']]])]);
        $r = $this->postJson('/primeros-pasos/interpretar', ['texto' => 'Tengo un corralón con dos camiones y vendo en Mercado Libre'])->assertOk()->json('respuestas');
        $this->assertSame(['rubro' => 'corralon', 'reparto' => 'si', 'tienda' => 'si'], $r, 'Sólo valores válidos');

        app(InductorService::class)->aplicar($this->empresa, $r);
        $res = app(Catalogo::class)->llamar($this->dueno->fresh(), 'como_trabaja_la_empresa', [], 'asistente');
        $this->assertTrue($res['hizo_la_entrevista']);
        $this->assertStringContainsString('reparto propio', $res['como_trabaja']);
        $this->assertNotEmpty($res['preguntas_pendientes']);
    }

    public function test_un_vendedor_no_cambia_la_configuracion(): void
    {
        $v = $this->usuarioConRol('vendedor');
        $this->actingAs($v)->post('/primeros-pasos/induccion', ['respuestas' => ['rubro' => 'gastronomia']])->assertForbidden();
    }
}
