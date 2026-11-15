<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Plan;
use App\Models\SistemaConfig;
use App\Models\User;
use App\Services\Suscripciones\AprobacionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\ErpTestCase;

// Fase 106: altas con aprobación (como en el CRM): acceso provisional, bloqueo al vencer, Panel → Pendientes con clave, avisos a BigSys.
class Fase106Test extends ErpTestCase
{
    private function superadmin(string $email = 'super@bigsys.test'): User
    {
        return User::create(['name' => 'Juan BigSys', 'email' => $email, 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
    }

    private function registro(array $extra = []): array
    {
        return array_merge(['empresa' => 'Kiosco Nuevo', 'nombre' => 'Ana Pérez', 'email' => 'ana@kiosco.com', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'rubro' => 'minimarket', 'acepto' => '1',
            'sitio_web' => '', 'marca' => Crypt::encryptString((string) now()->subSeconds(10)->timestamp)], $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! Plan::where('slug', 'prueba')->exists()) Plan::create(['slug' => 'prueba', 'name' => 'Prueba', 'price_monthly' => 0, 'price_yearly' => 0, 'is_active' => true]);
        Mail::fake();
    }

    public function test_el_registro_entra_pero_queda_pendiente_y_avisa_a_bigsys(): void
    {
        $this->superadmin();
        Auth::logout();
        $this->post('/registro', $this->registro())->assertRedirect('/primeros-pasos');
        $b = Business::where('name', 'Kiosco Nuevo')->firstOrFail();
        $this->assertSame('pendiente', $b->aprobacion);
        $this->assertSame('pendiente', $b->owner->aprobacion);
        $this->assertNull($b->aprobacion_pedida_por);
        // Entra con normalidad y ve el banner de acceso provisional.
        $this->get('/dashboard')->assertOk()->assertInertia(fn($p) => $p->where('suscripcion.provisional.hasta', app(AprobacionService::class)->corte($b)->format('d/m H:i')));
        // A BigSys le llegó el aviso con la hora de corte.
        Mail::assertSent(fn(\App\Mail\AvisoAprobacion $m) => str_contains($m->asunto, 'Alta nueva: Kiosco Nuevo') && $m->hasTo('super@bigsys.test'));
    }

    public function test_pasadas_las_horas_sin_aprobar_se_bloquea_y_al_aprobar_entra(): void
    {
        $super = $this->superadmin();
        app(AprobacionService::class)->guardarClave('la-clave-de-juan');
        SistemaConfig::set('horas_provisional', 72);
        Auth::logout();
        $this->post('/registro', $this->registro());
        $b = Business::where('name', 'Kiosco Nuevo')->firstOrFail();
        $dueno = $b->owner;

        $this->travel(71)->hours();
        $this->get('/dashboard')->assertOk(); // todavía provisional
        $this->travel(2)->hours();
        $this->get('/dashboard')->assertRedirect('/esperando-aprobacion');
        $this->get('/esperando-aprobacion')->assertOk()->assertInertia(fn($p) => $p->component('Auth/EsperandoAprobacion', false)->where('empresa', 'Kiosco Nuevo'));
        // Si cierra sesión y vuelve a entrar, el login la frena con el mensaje.
        $this->post('/logout');
        $this->post('/login', ['email' => 'ana@kiosco.com', 'password' => 'secreta123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // El superadmin aprueba: clave incorrecta queda auditada; con la correcta la empresa entra en el acto.
        $this->actingAs($super);
        $this->post("/admin/pendientes/{$b->id}/aprobar", ['clave' => 'otra'])->assertSessionHasErrors('clave');
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('accion', 'aprobacion_clave_incorrecta')->where('business_id', $b->id)->exists());
        $this->assertSame('pendiente', $b->fresh()->aprobacion);
        $this->post("/admin/pendientes/{$b->id}/aprobar", ['clave' => 'la-clave-de-juan'])->assertSessionHas('success');
        $b->refresh();
        $this->assertSame('aprobada', $b->aprobacion);
        $this->assertSame($super->id, $b->aprobada_por);
        $this->assertSame('aprobada', $dueno->fresh()->aprobacion);
        Mail::assertSent(fn(\App\Mail\AvisoAprobacion $m) => $m->hasTo('ana@kiosco.com') && str_contains($m->asunto, 'habilitada'));

        Auth::logout();
        $this->post('/login', ['email' => 'ana@kiosco.com', 'password' => 'secreta123'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertInertia(fn($p) => $p->missing('suscripcion.provisional'));
    }

    public function test_sin_clave_configurada_no_se_puede_aprobar_y_la_clave_se_carga_desde_sistema(): void
    {
        $super = $this->superadmin();
        Auth::logout();
        $this->post('/registro', $this->registro());
        $b = Business::where('name', 'Kiosco Nuevo')->firstOrFail();
        $this->actingAs($super);
        $this->get('/admin/pendientes')->assertOk()->assertInertia(fn($p) => $p->component('Superadmin/Pendientes', false)->where('claveConfigurada', false)->has('lista', 1)->where('lista.0.nombre', 'Kiosco Nuevo')->where('lista.0.bloqueada', false));
        $this->post("/admin/pendientes/{$b->id}/aprobar", ['clave' => 'x'])->assertSessionHasErrors('clave');
        // Se carga desde Panel → Sistema (queda como hash, nunca en claro) junto con las horas y los avisos.
        $this->post('/admin/sistema', ['dias_prueba' => 15, 'dias_gracia' => 7, 'horas_provisional' => 48, 'clave_aprobacion' => 'clave-nueva', 'aviso_registros_emails' => 'ventas@bigsys.test, juan@bigsys.test'])->assertSessionHas('success');
        $this->assertTrue(app(AprobacionService::class)->claveConfigurada());
        $this->assertNotSame('clave-nueva', SistemaConfig::get('clave_aprobacion'));
        $this->assertSame(48, app(AprobacionService::class)->horas());
        $this->assertSame(['ventas@bigsys.test', 'juan@bigsys.test'], app(AprobacionService::class)->destinatarios());
        $this->post("/admin/pendientes/{$b->id}/aprobar", ['clave' => 'clave-nueva'])->assertSessionHas('success');
        $this->assertSame('aprobada', $b->fresh()->aprobacion);
    }

    public function test_el_alta_desde_el_panel_tambien_queda_pendiente_y_se_puede_rechazar(): void
    {
        $empleado = $this->superadmin('empleado@bigsys.test');
        app(AprobacionService::class)->guardarClave('la-clave-de-juan');
        $plan = Plan::where('slug', 'prueba')->first();
        $this->actingAs($empleado);
        $this->post('/admin/empresas', ['name' => 'Ferretería Sur', 'dueno_nombre' => 'Luis', 'dueno_email' => 'luis@sur.com', 'dueno_password' => 'secreta1', 'plan_id' => $plan->id, 'modo' => 'trial', 'dias_prueba' => 15])->assertRedirect();
        $b = Business::where('name', 'Ferretería Sur')->firstOrFail();
        $this->assertSame('pendiente', $b->aprobacion);
        $this->assertSame($empleado->id, $b->aprobacion_pedida_por);
        $this->get('/admin')->assertOk()->assertInertia(fn($p) => $p->where('kpis.aprobaciones', 1));
        $this->get("/admin/empresas/{$b->id}")->assertOk()->assertInertia(fn($p) => $p->where('empresa.aprobacion', 'pendiente')->where('claveAprobacion', true));

        $this->post("/admin/pendientes/{$b->id}/rechazar", ['clave' => 'la-clave-de-juan', 'motivo' => 'Prueba interna duplicada'])->assertSessionHas('success');
        $b->refresh();
        $this->assertSame('rechazada', $b->aprobacion);
        $this->assertNotNull($b->suspended_at);
        $this->assertStringContainsString('duplicada', $b->suspension_motivo);
        // Nadie de la empresa entra.
        Auth::logout();
        $this->actingAs($b->owner)->get('/dashboard')->assertRedirect('/suscripcion');
    }

    public function test_avisos_por_vencer_y_bloqueada_una_sola_vez_y_resumen_de_vencimientos(): void
    {
        $this->superadmin();
        Auth::logout();
        $this->post('/registro', $this->registro());
        $b = Business::where('name', 'Kiosco Nuevo')->firstOrFail();
        $svc = app(AprobacionService::class);

        $this->assertSame(['por_vencer' => 0, 'bloqueadas' => 0], $svc->avisarPendientes());
        $this->travel(61)->hours();
        $this->assertSame(['por_vencer' => 1, 'bloqueadas' => 0], $svc->avisarPendientes());
        $this->assertSame(['por_vencer' => 0, 'bloqueadas' => 0], $svc->avisarPendientes()); // no repite
        $this->travel(12)->hours();
        $this->artisan('aprobaciones:avisar')->expectsOutputToContain('bloqueadas: 1')->assertSuccessful();
        $this->assertSame(['por_vencer' => 0, 'bloqueadas' => 0], $svc->avisarPendientes());
        Mail::assertSent(fn(\App\Mail\AvisoAprobacion $m) => str_contains($m->asunto, 'se bloquea a las'));
        Mail::assertSent(fn(\App\Mail\AvisoAprobacion $m) => str_contains($m->asunto, 'quedó bloqueada'));

        // La revisión diaria le cuenta a BigSys qué pruebas terminaron (siguen en Gratis) y cuáles vencen.
        if (! Plan::where('is_free', true)->exists()) Plan::create(['slug' => 'gratis', 'name' => 'Gratis', 'price_monthly' => 0, 'price_yearly' => 0, 'is_active' => true, 'is_free' => true, 'max_facturas_mes' => 10]);
        $this->travel(16)->days();
        app(\App\Services\Suscripciones\SuscripcionService::class)->revisar();
        Mail::assertSent(fn(\App\Mail\AvisoAprobacion $m) => str_contains($m->asunto, 'terminaron hoy') && str_contains($m->texto, 'Kiosco Nuevo'));
    }

    public function test_crear_un_superadmin_pide_la_clave_y_bigcito_lista_las_pendientes(): void
    {
        $super = $this->superadmin();
        app(AprobacionService::class)->guardarClave('la-clave-de-juan');
        $this->actingAs($super);
        $this->post('/admin/usuarios', ['name' => 'Otro', 'email' => 'otro@bigsys.test', 'password' => 'secreta1'])->assertSessionHasErrors('clave');
        $this->assertFalse(User::where('email', 'otro@bigsys.test')->exists());
        $this->post('/admin/usuarios', ['name' => 'Otro', 'email' => 'otro@bigsys.test', 'password' => 'secreta1', 'clave' => 'la-clave-de-juan'])->assertSessionHas('success');
        $this->assertTrue(User::where('email', 'otro@bigsys.test')->where('is_superadmin', true)->exists());

        Auth::logout();
        $this->post('/registro', $this->registro());
        $r = app(\App\Mcp\Catalogo::class)->llamar($super, 'altas_pendientes', [], 'asistente');
        $this->assertSame(1, $r['pendientes']);
        $this->assertSame('Kiosco Nuevo', $r['empresas'][0]['empresa']);
        $this->assertTrue($r['clave_configurada']);
        $r = app(\App\Mcp\Catalogo::class)->llamar($this->dueno, 'altas_pendientes', [], 'asistente');
        $this->assertArrayHasKey('error', $r);
    }
}
