<?php

namespace Tests\Feature;

use App\Models\SistemaConfig;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\ErpTestCase;

// Fase 23 · Claves de IA y correo desde el panel superadmin: cifradas, con prioridad sobre el .env y probables desde la pantalla.
class ClavesPanelTest extends ErpTestCase
{
    private function admin(): User { $a = User::create(['name' => 'BigSys', 'email' => 'admin@bigsys.test', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]); Auth::login($a); $this->actingAs($a); return $a; }

    public function test_las_claves_se_guardan_cifradas_y_pisan_al_env(): void
    {
        $this->admin();
        $base = ['dias_prueba' => 14, 'dias_gracia' => 7];
        $this->post('/admin/sistema', $base + ['ia_api_key' => 'sk-ant-secreta', 'ia_modelo' => 'claude-sonnet-5', 'mail_host' => 'smtp.ejemplo.com', 'mail_port' => 465, 'mail_username' => 'u', 'mail_password' => 'clave-smtp', 'mail_encryption' => 'ssl', 'mail_from_address' => 'no-responder@bigsys.com.ar', 'mail_from_name' => 'BigSys'])->assertSessionHas('success');
        $crudo = \Illuminate\Support\Facades\DB::table('sistema_config')->where('clave', 'ia_api_key')->value('valor');
        $this->assertStringNotContainsString('sk-ant-secreta', $crudo, 'La clave no queda en texto plano');
        $this->assertSame('sk-ant-secreta', SistemaConfig::get('ia_api_key'));
        $this->assertSame('clave-smtp', SistemaConfig::get('mail_password'));
        SistemaConfig::aplicar();
        $this->assertSame('sk-ant-secreta', config('services.anthropic.api_key')); $this->assertSame('claude-sonnet-5', config('services.anthropic.model'));
        $this->assertSame('smtp', config('mail.default')); $this->assertSame('smtp.ejemplo.com', config('mail.mailers.smtp.host')); $this->assertSame(465, config('mail.mailers.smtp.port')); $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('no-responder@bigsys.com.ar', config('mail.from.address'));
        // Guardar de nuevo sin la clave no la pisa; la pantalla no la muestra.
        $this->post('/admin/sistema', $base + ['ia_api_key' => '', 'mail_password' => ''])->assertSessionHas('success');
        $this->assertSame('sk-ant-secreta', SistemaConfig::get('ia_api_key'));
        $this->get('/admin/sistema')->assertInertia(fn($p) => $p->component('Superadmin/Sistema', false)->where('config.ia_api_key_set', true)->where('config.mail_password_set', true)->where('config.mail_host', 'smtp.ejemplo.com')->missing('config.ia_api_key'));
        // Borrar la clave vuelve al .env.
        $this->post('/admin/sistema/clave/borrar', ['clave' => 'ia_api_key'])->assertSessionHas('success');
        $this->assertNull(SistemaConfig::get('ia_api_key'));
        $this->post('/admin/sistema/clave/borrar', ['clave' => 'dias_prueba'])->assertSessionHasErrors('clave');
    }

    public function test_prueba_de_correo_y_de_ia_desde_el_panel(): void
    {
        $this->admin(); Mail::fake();
        $this->post('/admin/sistema/probar-correo', ['a' => 'yo@ejemplo.com'])->assertSessionHas('success');
        Mail::assertSent(\App\Mail\CorreoPrueba::class);
        $this->post('/admin/sistema/probar-ia')->assertSessionHas('error'); // sin clave
        SistemaConfig::set('ia_api_key', 'sk-test');
        \Illuminate\Support\Facades\Http::fake(['api.anthropic.com/*' => \Illuminate\Support\Facades\Http::sequence()->push(['content' => [['text' => 'OK']]])->push(['error' => ['message' => 'invalid x-api-key']], 401)]);
        $this->post('/admin/sistema/probar-ia')->assertSessionHas('success');
        \Illuminate\Support\Facades\Http::assertSent(fn($r) => $r->hasHeader('x-api-key', 'sk-test'));
        $this->post('/admin/sistema/probar-ia')->assertSessionHas('error');
    }

    public function test_un_usuario_comun_no_ve_el_panel(): void
    {
        $this->get('/admin/sistema')->assertForbidden();
        $this->post('/admin/sistema/probar-ia')->assertForbidden();
    }
}
