<?php

namespace Tests\Feature;

use App\Http\Controllers\OnboardingController;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Fase 122: Primeros pasos por etapas, con el siguiente paso siempre a la vista y el camino desde BigSys de escritorio.
class Fase122Test extends TestCase
{
    use RefreshDatabase;

    private function dueno(): User
    {
        $this->seed();
        return User::where('email', 'demo@bigsys.com.ar')->firstOrFail();
    }

    public function test_cada_paso_tiene_etapa_tiempo_y_para_que_y_hay_un_siguiente(): void
    {
        $u = $this->dueno(); $b = $u->business;
        $b->forceFill(['onboarding' => [], 'onboarding_completado_en' => null])->save();
        $p = $this->actingAs($u)->get('/primeros-pasos')->assertOk()->viewData('page')['props'];
        foreach ($p['pasos'] as $paso) {
            $this->assertArrayHasKey($paso['etapa'], OnboardingController::ETAPAS, $paso['key']);
            $this->assertGreaterThan(0, $paso['minutos'], $paso['key']);
        }
        $this->assertSame('empresa', $p['pasos'][0]['key']);
        // El siguiente es el primero obligatorio que falta, nunca uno opcional si queda alguno obligatorio.
        $sig = collect($p['pasos'])->firstWhere('key', $p['siguiente']);
        $this->assertNotNull($sig); $this->assertFalse($sig['hecho']);
        $this->assertSame(collect($p['pasos'])->contains(fn($x) => ! $x['hecho'] && empty($x['opcional'])), empty($sig['opcional']));
        $this->assertTrue(collect($p['pasos'])->contains('key', 'migrar'));
        $this->assertTrue(collect($p['pasos'])->firstWhere('key', 'migrar')['opcional']);
        $this->assertTrue(collect($p['pasos'])->contains('key', 'whatsapp'));
        // La barra de arriba dice cuál sigue.
        $this->get('/dashboard')->assertInertia(fn($pg) => $pg->where('onboarding.siguiente.titulo', $sig['titulo'])->etc());
    }

    public function test_quien_viene_de_bigsys_tiene_que_traer_sus_datos_y_quien_no_no_lo_ve(): void
    {
        $u = $this->dueno(); $b = $u->business;
        $this->actingAs($u)->post('/primeros-pasos/induccion', ['respuestas' => ['rubro' => 'corralon', 'origen' => 'bigsys']])->assertSessionHas('success');
        $m = collect(OnboardingController::pasos($b->fresh()))->firstWhere('key', 'migrar');
        $this->assertFalse($m['opcional']); $this->assertFalse($m['hecho']);
        $b->forceFill(['bigconnect' => ['url' => 'https://hub.example', 'clave' => 'bk_x', 'ultima' => now()->toDateTimeString()]])->save();
        $this->assertTrue(collect(OnboardingController::pasos($b->fresh()))->firstWhere('key', 'migrar')['hecho']);
        $this->post('/primeros-pasos/induccion', ['respuestas' => ['rubro' => 'corralon', 'origen' => 'cero']]);
        $this->assertFalse(collect(OnboardingController::pasos($b->fresh()))->contains('key', 'migrar'));
    }

    public function test_si_esta_todo_lo_obligatorio_sigue_el_primer_opcional_y_si_esta_todo_no_hay_siguiente(): void
    {
        $u = $this->dueno(); $b = $u->business;
        $pasos = OnboardingController::pasos($b);
        $oblig = collect($pasos)->filter(fn($p) => empty($p['opcional']))->pluck('key');
        $b->forceFill(['onboarding' => $oblig->mapWithKeys(fn($k) => [$k => true])->all()])->save();
        $p2 = OnboardingController::pasos($b->fresh()); $s = OnboardingController::siguiente($p2);
        $this->assertTrue($s === null || ! empty($s['opcional']));
        $b->forceFill(['onboarding' => collect($p2)->pluck('key')->mapWithKeys(fn($k) => [$k => true])->all()])->save();
        $this->assertNull(OnboardingController::siguiente(OnboardingController::pasos($b->fresh())));
    }

    public function test_la_guia_de_implementacion_no_pide_certificado_y_explica_big_connect(): void
    {
        $g = file_get_contents(base_path('docs/ayuda/implementacion.md'));
        $this->assertStringContainsString('BIG Connect', $g);
        $this->assertStringContainsString('Administrador de Relaciones de Clave Fiscal', $g);
        $this->assertStringNotContainsString('.key', $g);
    }
}
