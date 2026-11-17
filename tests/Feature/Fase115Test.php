<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Suscripciones\EmpresaBigSysService;
use Illuminate\Support\Facades\Auth;
use Tests\ErpTestCase;

// Fase 115: el superadmin entra por el login común a la empresa de BigSys y va y vuelve al panel con un botón.
class Fase115Test extends ErpTestCase
{
    public function test_con_empresa_de_bigsys_entra_a_la_empresa_y_sin_ella_al_panel(): void
    {
        $super = User::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
        auth()->logout(); $this->flushSession();
        $this->post('/login', ['email' => 'super@test.com', 'password' => 'password'])->assertRedirect('/admin'); // todavía no hay empresa de BigSys
        Auth::login($super); $this->actingAs($super);
        app(EmpresaBigSysService::class)->crear($super);
        auth()->logout(); $this->flushSession();
        $this->post('/login', ['email' => 'super@test.com', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/')->assertRedirect('/dashboard');
        // Desde la empresa ve el botón al panel (props), y el panel sigue abierto para él; desde el panel vuelve a la empresa.
        $props = $this->get('/dashboard')->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['auth']['user']['is_superadmin']); $this->assertNull($props['impersonando']);
        $this->get('/admin')->assertOk();
        $this->get('/admin/empresas')->assertOk();
        $this->get('/dashboard')->assertOk();
    }

    public function test_un_dueno_comun_no_entra_al_panel(): void
    {
        $this->get('/admin')->assertStatus(403);
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_adentro_de_su_empresa_el_superadmin_ve_solo_su_empresa_y_en_el_panel_ve_todo(): void
    {
        // Datos de un cliente (Empresa Test): un cliente y una factura.
        $p = $this->articulo(['stock_inicial' => 5]); $cli = $this->cliente(['name' => 'Cliente Del Corralón']);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $super = User::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
        Auth::login($super); $this->actingAs($super);
        $bigsys = app(EmpresaBigSysService::class)->crear($super);
        Auth::login($super->fresh()); $this->actingAs($super->fresh());
        // En su empresa: tablero sin la plata del cliente, clientes sólo los suyos.
        $props = $this->get('/dashboard')->assertOk()->viewData('page')['props'];
        $this->assertSame($bigsys->id, $props['empresa']['id'] ?? $bigsys->id);
        $this->assertSame(0, \App\Models\Comprobante::count(), 'Sin pantalla de panel, la consulta queda en su empresa');
        $this->assertFalse(\App\Models\Contact::where('name', 'Cliente Del Corralón')->exists());
        $lista = $this->get('/clientes')->assertOk()->viewData('page')['props'];
        $this->assertStringNotContainsString('Cliente Del Corralón', json_encode($lista));
        // En el panel sigue viendo a todos: la ficha del cliente y el uso global.
        $this->get("/admin/empresas/{$this->empresa->id}")->assertOk();
        $this->assertGreaterThanOrEqual(1, $this->get('/admin/uso')->assertOk()->viewData('page')['props']['kpis']['empresas'] ?? 1);
        // Un superadmin sin empresa (todavía no hay BigSys) sigue viendo todo en cualquier lado, como antes.
        \App\Models\SistemaConfig::set(EmpresaBigSysService::CLAVE, null);
        $otro = User::create(['name' => 'Super 2', 'email' => 'super2@test.com', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
        Auth::login($otro); $this->actingAs($otro);
        $this->assertTrue(\App\Models\Contact::where('name', 'Cliente Del Corralón')->exists());
    }
}
