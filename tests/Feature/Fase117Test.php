<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\PagoSuscripcion;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Suscripciones\MercadoPagoSuscripcionService;
use App\Services\Suscripciones\SuscripcionService;
use Tests\ErpTestCase;

// Fase 117: débito automático con Mercado Pago (preapproval): autorizar, primer cobro, cobros recurrentes por webhook, cancelación,
// corte informado por Mercado Pago y reconciliación cuando se pierde un aviso.
class Fase117Test extends ErpTestCase
{
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = Plan::where('slug', 'pro')->first() ?? Plan::where('is_free', false)->first();
    }

    public function test_sin_credenciales_el_debito_se_simula_y_el_plan_queda_activo_y_renovandose_solo(): void
    {
        $r = $this->post('/suscripcion/pagar', ['plan_id' => $this->plan->id, 'ciclo' => 'monthly', 'medio' => 'debito']);
        $r->assertStatus(302); // Inertia::location sin cabecera X-Inertia = redirección común
        $url = $r->headers->get('Location');
        $this->assertStringContainsString('/suscripcion/retorno-debito?preapproval_id=SIMULADO-DEB-', $url);
        $pend = PagoSuscripcion::where('business_id', $this->empresa->id)->where('medio', 'debito')->first();
        $this->assertSame('pendiente', $pend->estado); $this->assertStringStartsWith('SIMULADO-DEB-', $pend->preference_id);
        $this->assertTrue($this->get('/suscripcion')->viewData('page')['props']['debito']['pendiente']);

        $this->get(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY))->assertRedirect('/suscripcion');
        $sub = $this->empresa->fresh()->subscription;
        $this->assertTrue((bool) $sub->auto_renew); $this->assertSame($pend->preference_id, $sub->mp_preapproval_id); $this->assertSame('debito', $sub->payment_method);
        $this->assertSame('active', $sub->status); $this->assertSame($this->plan->id, $sub->plan_id);
        $this->assertSame('aprobado', $pend->fresh()->estado); $this->assertStringStartsWith('SIMULADO-COBRO-', $pend->fresh()->external_id);
        $props = $this->get('/suscripcion')->viewData('page')['props'];
        $this->assertTrue($props['debito']['activo']); $this->assertSame('Débito automático (Mercado Pago)', $props['pagos'][0]['medio']);
        // Cancelar: el plan sigue hasta el vencimiento, sin débito.
        $vence = $sub->ends_at;
        $this->post('/suscripcion/debito/cancelar')->assertRedirect('/suscripcion');
        $sub = $sub->fresh(); $this->assertFalse((bool) $sub->auto_renew); $this->assertNull($sub->mp_preapproval_id); $this->assertSame('active', $sub->status); $this->assertEquals($vence, $sub->ends_at);
    }

    private function mpFalso(array $metodos): void
    {
        $this->mock(MercadoPagoSuscripcionService::class, function ($m) use ($metodos) {
            $m->shouldReceive('configurado')->andReturn(true);
            foreach ($metodos as $k => $v) $m->shouldReceive($k)->andReturnUsing(is_callable($v) ? $v : fn() => $v);
        });
    }

    public function test_los_cobros_recurrentes_llegan_por_webhook_renuevan_el_plan_y_no_se_duplican(): void
    {
        $sub = app(SuscripcionService::class)->activar($this->empresa, $this->plan, 'monthly', 'debito');
        $sub->update(['auto_renew' => true, 'mp_preapproval_id' => 'pre-1', 'ends_at' => now()->addDays(3)]);
        $vence = $sub->fresh()->ends_at;
        $this->mpFalso(['pagoAutorizado' => fn($id) => ['id' => $id, 'preapproval_id' => 'pre-1', 'estado' => 'processed', 'pago_id' => 'pay-' . $id, 'pago_estado' => 'approved', 'monto' => (float) $this->plan->price_monthly, 'fecha' => now()->toIso8601String()]]);
        auth()->logout();
        $this->postJson('/api/webhooks/mercadopago/suscripcion?type=subscription_authorized_payment&data_id=777', ['data' => ['id' => '777']])->assertOk();
        $sub = $sub->fresh();
        $this->assertEquals($vence->copy()->addMonth()->toDateString(), $sub->ends_at->toDateString(), 'Renueva desde el vencimiento, no desde hoy');
        $pago = PagoSuscripcion::where('business_id', $this->empresa->id)->where('external_id', 'pay-777')->first();
        $this->assertSame('aprobado', $pago->estado); $this->assertSame('debito', $pago->medio); $this->assertEquals((float) $this->plan->price_monthly, (float) $pago->monto);
        // El mismo aviso otra vez: nada cambia.
        $this->postJson('/api/webhooks/mercadopago/suscripcion?type=subscription_authorized_payment&data_id=777', ['data' => ['id' => '777']])->assertOk();
        $this->assertEquals($vence->copy()->addMonth()->toDateString(), $sub->fresh()->ends_at->toDateString());
        $this->assertSame(1, PagoSuscripcion::where('business_id', $this->empresa->id)->where('medio', 'debito')->count());
    }

    public function test_mercado_pago_informa_el_corte_del_debito_y_la_reconciliacion_rescata_un_cobro_perdido(): void
    {
        $svc = app(SuscripcionService::class);
        $sub = $svc->activar($this->empresa, $this->plan, 'monthly', 'debito');
        $sub->update(['auto_renew' => true, 'mp_preapproval_id' => 'pre-2']);
        $this->mpFalso(['consultarDebito' => fn($id) => ['id' => $id, 'status' => 'cancelled', 'external_reference' => 'debito-' . $this->empresa->id], 'cancelarDebito' => null,
            'pagosDelDebito' => fn($id) => [['id' => 'ap-9', 'preapproval_id' => $id, 'estado' => 'processed', 'pago_id' => 'pay-9', 'pago_estado' => 'approved', 'monto' => 1000.0, 'fecha' => now()->toIso8601String()]]]);
        // Reconciliación: la suscripción venció ayer pero Mercado Pago ya cobró; revisar() la mantiene activa.
        $sub->update(['ends_at' => now()->subDay()]);
        $r = $svc->revisar();
        $sub = $sub->fresh();
        $this->assertSame('active', $sub->status); $this->assertSame(1, $r['debito_reconciliado'] ?? 0); $this->assertTrue($sub->ends_at->isFuture());
        $this->assertSame('aprobado', PagoSuscripcion::where('external_id', 'pay-9')->value('estado'));
        // Corte avisado por Mercado Pago: queda sin débito y con aviso al dueño.
        auth()->logout();
        $this->postJson('/api/webhooks/mercadopago/suscripcion?type=subscription_preapproval&data_id=pre-2', ['data' => ['id' => 'pre-2']])->assertOk();
        $this->assertFalse((bool) $sub->fresh()->auto_renew);
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('business_id', $this->empresa->id)->where('titulo', 'Se cortó el débito automático')->exists());
    }

    public function test_un_vendedor_no_puede_tocar_el_debito(): void
    {
        $v = $this->usuarioConRol('vendedor'); $this->actingAs($v); \Illuminate\Support\Facades\Auth::login($v);
        $this->post('/suscripcion/pagar', ['plan_id' => $this->plan->id, 'ciclo' => 'monthly', 'medio' => 'debito'])->assertStatus(403);
        $this->post('/suscripcion/debito/cancelar')->assertStatus(403);
    }
}
