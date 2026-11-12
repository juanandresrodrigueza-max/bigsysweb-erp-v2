<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\Producto\UsoService;
use App\Services\Suscripciones\EmpresaBigSysService;
use Tests\ErpTestCase;

// Fase 87: la empresa propia de BigSys en el ERP. Los superadmins la usan como su empresa y saltan entre el panel y ella.
class EmpresaBigSysFase87Test extends ErpTestCase
{
    private function superadmin(string $email = 'super@test.com'): User
    {
        return User::create(['name' => 'Soporte BigSys', 'email' => $email, 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
    }

    public function test_el_superadmin_crea_la_empresa_de_bigsys_y_queda_adentro_como_dueno(): void
    {
        $super = $this->superadmin();
        $this->assertNull($super->business_id);
        $this->actingAs($super)->post('/admin/sistema/empresa-bigsys', [])->assertSessionHas('success');
        $super->refresh();
        $b = Business::where('slug', 'bigsysweb')->first();
        $this->assertNotNull($b); $this->assertSame($b->id, $super->business_id); $this->assertSame($super->id, $b->owner_id);
        $this->assertSame($b->id, app(EmpresaBigSysService::class)->actual()->id);
        $this->assertTrue($super->esDueno());
        foreach (['comprobantes', 'contable', 'sueldos', 'fondos'] as $m) $this->assertContains($m, $super->modulosVisibles(), "Todo activado ($m)");
        $this->assertSame('active', $b->fresh()->activeSubscription?->status);
        // La empresa anda como cualquiera (menú, sub-navegación) y el panel sigue siendo el panel.
        $props = $this->actingAs($super->fresh())->get('/dashboard')->assertOk()->viewData('page')['props'];
        $this->assertContains('vender', collect($props['nav'])->flatMap(fn($g) => array_column($g['items'], 'key'))->all());
        $this->assertNull($props['impersonando'], 'Es su empresa: no hay cartel de soporte');
        $this->get('/admin')->assertOk();
        $this->get('/')->assertRedirect('/admin');
        // La página Sistema la muestra como definida.
        $this->assertSame($b->id, $this->get('/admin/sistema')->viewData('page')['props']['bigsys']['id']);
    }

    public function test_los_superadmins_nuevos_y_los_que_entran_quedan_adentro_solos(): void
    {
        $super = $this->superadmin();
        app(EmpresaBigSysService::class)->crear($super);
        $otro = $this->superadmin('otro@test.com');
        $this->assertNull($otro->business_id);
        // Al iniciar sesión se lo suma y va al panel (primero se cierra la sesión del dueño que abre el caso base).
        auth()->logout(); $this->flushSession();
        $this->post('/login', ['email' => 'otro@test.com', 'password' => 'password'])->assertRedirect('/admin');
        $this->assertSame($super->fresh()->business_id, $otro->fresh()->business_id);
        // Un superadmin que ya es de otra empresa no se mueve.
        $this->dueno->forceFill(['is_superadmin' => true])->save();
        app(EmpresaBigSysService::class)->sumar($this->dueno->fresh());
        $this->assertSame($this->empresa->id, $this->dueno->fresh()->business_id);
    }

    public function test_usar_una_empresa_existente_y_el_modo_soporte_sigue_volviendo_al_superadmin(): void
    {
        $super = $this->superadmin();
        $this->actingAs($super)->post('/admin/sistema/empresa-bigsys', ['business_id' => $this->empresa->id])->assertSessionHas('success');
        $this->assertSame($this->empresa->id, $super->fresh()->business_id);
        // Entra como dueño de otra empresa para dar soporte y vuelve: sigue siendo él, con su empresa.
        [$otra] = $this->crearEmpresa('Otra SA', 'otra-sa');
        $this->actingAs($super->fresh())->post("/admin/empresas/{$otra->id}/entrar")->assertRedirect('/dashboard');
        $this->assertNotNull($this->get('/dashboard')->viewData('page')['props']['impersonando']);
        $this->post('/admin/volver')->assertRedirect('/admin/empresas');
        $this->assertSame($super->id, auth()->id()); $this->assertSame($this->empresa->id, auth()->user()->business_id);
    }

    public function test_la_empresa_de_bigsys_no_cuenta_como_cliente_en_las_metricas(): void
    {
        $super = $this->superadmin();
        $antes = app(UsoService::class)->global(30)['kpis']['empresas'];
        app(EmpresaBigSysService::class)->crear($super);
        $this->assertSame($antes, app(UsoService::class)->global(30)['kpis']['empresas'], 'BigSysWeb no es un cliente');
        $this->assertStringContainsString('ir-bigsys', file_get_contents(resource_path('js/Layouts/AdminLayout.vue')));
        $this->assertStringContainsString('ir-panel', file_get_contents(resource_path('js/Layouts/AppLayout.vue')));
    }
}
