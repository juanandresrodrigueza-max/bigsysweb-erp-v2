<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\User;
use App\Services\Usuarios\BajaUsuarioService;
use Illuminate\Support\Facades\Auth;
use Tests\ErpTestCase;

// Fase 112: eliminar usuarios. Sin movimientos se borran; con movimientos quedan anónimos y sin acceso. Nunca el dueño ni uno mismo.
class Fase112Test extends ErpTestCase
{
    public function test_un_usuario_sin_movimientos_se_borra_de_verdad(): void
    {
        $u = $this->usuarioConRol('vendedor');
        $this->delete("/configuracion/usuarios/{$u->id}")->assertSessionHas('success')->assertSessionHasNoErrors();
        $this->assertNull(User::find($u->id));
        $this->assertTrue(AuditLog::where('accion', 'eliminar')->where('descripcion', 'like', '%vendedor@test.com%')->exists());
    }

    public function test_un_usuario_con_movimientos_queda_anonimo_sin_acceso_y_fuera_de_las_listas(): void
    {
        $v = $this->usuarioConRol('vendedor');
        $p = $this->articulo(['stock_inicial' => 5]); $cli = $this->cliente(['name' => 'Cliente Uno']);
        Auth::login($v); $this->actingAs($v);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $this->assertSame($v->id, $f->user_id);
        Auth::login($this->dueno); $this->actingAs($this->dueno);

        $r = app(BajaUsuarioService::class)->eliminar($v, $this->dueno);
        $this->assertSame('anonimizado', $r['modo']);
        $v->refresh();
        $this->assertSame('Usuario eliminado', $v->name); $this->assertStringEndsWith('@borrado.local', $v->email);
        $this->assertSame('inactive', $v->status); $this->assertNull($v->business_id);
        $this->assertSame($v->id, Comprobante::withoutGlobalScopes()->find($f->id)->user_id, 'La factura sigue diciendo quién la hizo');
        $this->get('/configuracion/usuarios')->assertOk()->assertInertia(fn($a) => $a->where('usuarios', fn($lista) => collect($lista)->pluck('id')->doesntContain($v->id)));
        $this->post('/logout'); $this->post('/login', ['email' => $v->email, 'password' => 'password'])->assertSessionHasErrors();
    }

    public function test_no_se_elimina_al_dueno_ni_a_uno_mismo_ni_a_usuarios_de_otra_empresa(): void
    {
        $this->delete("/configuracion/usuarios/{$this->dueno->id}")->assertSessionHasErrors('usuario');
        $this->assertNotNull(User::find($this->dueno->id));
        [$otra, , $duenoOtra] = $this->crearEmpresa('Otra SA', 'otra');
        $ajeno = User::create(['business_id' => $otra->id, 'name' => 'Ajeno', 'email' => 'ajeno@otra.com', 'password' => 'password', 'status' => 'active']);
        $this->delete("/configuracion/usuarios/{$ajeno->id}")->assertNotFound();
        $this->assertNotNull(User::find($ajeno->id));
        $v = $this->usuarioConRol('vendedor');
        $this->actingAs($v); Auth::login($v);
        $this->delete("/configuracion/usuarios/{$v->id}")->assertStatus(403); // sin permiso de configuración
    }

    public function test_el_superadmin_elimina_desde_el_panel_y_no_puede_quedarse_sin_superadmins(): void
    {
        $super = User::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
        Auth::login($super); $this->actingAs($super);
        $v = $this->usuarioConRol('vendedor');
        $this->post("/admin/usuarios/{$v->id}", ['accion' => 'eliminar'])->assertSessionHas('success');
        $this->assertNull(User::find($v->id));
        $this->post("/admin/usuarios/{$super->id}", ['accion' => 'eliminar'])->assertSessionHasErrors('usuario');
        $otro = User::create(['name' => 'Super 2', 'email' => 'super2@test.com', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
        Auth::login($otro); $this->actingAs($otro);
        $this->post("/admin/usuarios/{$super->id}", ['accion' => 'eliminar'])->assertSessionHas('success');
        $this->post("/admin/usuarios/{$otro->id}", ['accion' => 'eliminar'])->assertSessionHasErrors('usuario');
        $this->assertNotNull(User::find($otro->id));
        // Desde la ficha de la empresa también.
        $v2 = $this->usuarioConRol('cajero', 'cajero@test.com');
        $this->post("/admin/empresas/{$this->empresa->id}/usuarios/{$v2->id}", ['accion' => 'eliminar'])->assertSessionHas('success');
        $this->assertNull(User::find($v2->id));
        $this->post("/admin/empresas/{$this->empresa->id}/usuarios/{$this->dueno->id}", ['accion' => 'eliminar'])->assertSessionHasErrors('usuario');
    }
}
