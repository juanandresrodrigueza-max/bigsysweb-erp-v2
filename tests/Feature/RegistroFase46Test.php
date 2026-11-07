<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Tests\ErpTestCase;

// Fase 46: alta sola desde /registro con 15 días de prueba, protección contra robots y entrada directa a primeros pasos.
class RegistroFase46Test extends ErpTestCase
{
    private function datos(array $extra = []): array
    {
        return array_merge(['empresa' => 'Kiosco Nuevo', 'nombre' => 'Ana Pérez', 'email' => 'ana@kiosco.com', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'rubro' => 'minimarket', 'acepto' => true,
            'sitio_web' => '', 'marca' => Crypt::encryptString((string) now()->subSeconds(10)->timestamp)], $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! Plan::where('slug', 'prueba')->exists()) Plan::create(['slug' => 'prueba', 'name' => 'Prueba', 'price_monthly' => 0, 'price_yearly' => 0, 'is_active' => true]);
        Auth::logout();
    }

    public function test_se_registra_con_prueba_de_15_dias_y_entra(): void
    {
        $this->get('/registro')->assertOk()->assertInertia(fn($p) => $p->component('Auth/Registro', false)->where('dias', 15));
        $this->post('/registro', $this->datos())->assertRedirect('/primeros-pasos');
        $b = Business::where('name', 'Kiosco Nuevo')->firstOrFail();
        $u = User::where('email', 'ana@kiosco.com')->firstOrFail();
        $this->assertSame($b->owner_id, $u->id);
        $this->assertAuthenticatedAs($u);
        $sub = $b->subscriptions()->latest('id')->first();
        $this->assertSame('trial', $sub->status);
        $this->assertSame('prueba', $sub->plan->slug);
        $this->assertTrue($sub->trial_ends_at->between(now()->addDays(14), now()->addDays(16)));
    }

    public function test_robots_y_mail_repetido(): void
    {
        $this->post('/registro', $this->datos(['sitio_web' => 'http://spam']))->assertStatus(422);
        $this->post('/registro', $this->datos(['marca' => Crypt::encryptString((string) now()->timestamp)]))->assertStatus(422);
        $this->post('/registro', $this->datos(['email' => $this->dueno->email]))->assertSessionHasErrors('email');
        $this->assertFalse(Business::where('name', 'Kiosco Nuevo')->exists());
    }

    public function test_captcha_cuando_esta_configurado(): void
    {
        config(['services.turnstile.secret' => 'x', 'services.turnstile.site_key' => 'k']);
        \Illuminate\Support\Facades\Http::fake(['challenges.cloudflare.com/*' => \Illuminate\Support\Facades\Http::sequence()->push(['success' => false])->push(['success' => true])]);
        $this->post('/registro', $this->datos())->assertSessionHasErrors('captcha');
        $this->post('/registro', $this->datos(['cf-turnstile-response' => 'tok']))->assertRedirect('/primeros-pasos');
    }
}
