<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Pos\CuotasService;
use Tests\ErpTestCase;

// Fase 124: lo que apareció en Panel → Salud y en las pruebas que fallaban de a ratos.
class Fase124Test extends ErpTestCase
{
    public function test_los_planes_de_cuotas_sin_empresa_son_los_de_siempre(): void
    {
        $this->assertSame(CuotasService::DEFAULT, app(CuotasService::class)->planes(null));
        $this->assertNotEmpty(app(CuotasService::class)->planes($this->empresa));
    }

    public function test_un_superadmin_sin_empresa_que_abre_una_pantalla_de_empresa_va_al_panel(): void
    {
        $su = User::factory()->create(['is_superadmin' => true, 'business_id' => null, 'status' => 'active']);
        $this->actingAs($su)->get('/retail')->assertRedirect('/admin')->assertSessionHas('error', fn($m) => str_contains($m, 'Panel → Empresas'));
        $this->actingAs($su)->getJson('/retail')->assertStatus(409);
        // Con empresa, entra normal.
        $this->actingAs($this->dueno)->get('/retail')->assertOk();
    }

    public function test_las_empresas_de_prueba_tienen_siempre_el_plan_con_todo(): void
    {
        $this->assertSame('prueba', $this->empresa->activeSubscription->plan->slug);
        $this->assertContains('hoteleria', array_keys(config('erp.modulos')));
    }
}
