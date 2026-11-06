<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Suscripciones\SuscripcionService;
use Database\Seeders\PlanSeeder;
use Tests\ErpTestCase;

// Planes en dólares (Inicial 39, Negocio 99, Empresa 199), prueba de 15 días que termina en el plan Gratis y sueldos aparte.
class PlanesUsdTest extends ErpTestCase
{
    public function test_planes_y_pesos_desde_la_cotizacion(): void
    {
        $this->seed(PlanSeeder::class);
        $p = Plan::whereIn('slug', ['inicial', 'negocio', 'empresa'])->get()->keyBy('slug');
        $this->assertEquals([39, 99, 199], [(float) $p['inicial']->precio_usd, (float) $p['negocio']->precio_usd, (float) $p['empresa']->precio_usd]);
        $this->assertEquals(990, (float) $p['negocio']->precio_usd_anual); // el año cuesta 10 meses
        $this->assertSame(10, (int) Plan::where('slug', 'free')->value('max_facturas_mes'));
        foreach ($p as $x) $this->assertNotContains('sueldos', $x->features); // sueldos va aparte
        $this->artisan('planes:actualizar-pesos', ['--cotizacion' => 2000])->assertSuccessful();
        $this->assertEquals(198000, (float) $p['negocio']->fresh()->price_monthly);
    }

    public function test_la_prueba_termina_en_el_plan_gratis(): void
    {
        $this->seed(PlanSeeder::class);
        $this->empresa->subscriptions()->update(['status' => 'cancelled']);
        $svc = app(SuscripcionService::class);
        $svc->iniciarPrueba($this->empresa, Plan::where('slug', 'prueba')->first(), 15);
        $this->assertContains('sueldos', $this->empresa->fresh()->modulosActivos()); // la prueba trae todo
        $this->travel(16)->days();
        $svc->revisar();
        $sub = $this->empresa->fresh()->activeSubscription;
        $this->assertSame('free', $sub->plan->slug);
        $this->assertSame('active', $sub->status);
        $this->assertNull($this->empresa->fresh()->suspended_at);
    }

    public function test_sueldos_contratado_aparte(): void
    {
        $this->seed(PlanSeeder::class);
        $this->empresa->subscriptions()->update(['status' => 'cancelled']);
        Subscription::create(['business_id' => $this->empresa->id, 'plan_id' => Plan::where('slug', 'negocio')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly', 'amount' => 99, 'starts_at' => now()]);
        $this->assertNotContains('sueldos', $this->empresa->fresh()->modulosActivos());
        $this->empresa->forceFill(['modulos_contratados' => ['sueldos']])->save();
        $this->assertContains('sueldos', $this->empresa->fresh()->modulosActivos());
    }
}
