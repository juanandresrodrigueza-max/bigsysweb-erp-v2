<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use App\Services\Comprobantes\CobroService;
use App\Services\Empresas\EliminarEmpresaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ErpTestCase;

// Fase 113: eliminar una empresa entera desde el panel: todo lo suyo se borra, lo de las demás queda intacto.
class Fase113Test extends ErpTestCase
{
    private function superadmin(): User
    {
        $s = User::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
        Auth::login($s); $this->actingAs($s);
        return $s;
    }

    private function cargarDatos(): void
    {
        $p = $this->articulo(['name' => 'Cemento', 'stock_inicial' => 20, 'price' => 1000]);
        $cli = $this->cliente(['name' => 'Cliente Borrable']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 1000]]);
        app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 500]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 500]]]);
        $this->usuarioConRol('vendedor');
    }

    public function test_el_mapa_alcanza_las_tablas_con_business_id_y_las_que_cuelgan_por_clave_foranea(): void
    {
        $m = app(EliminarEmpresaService::class)->mapa();
        foreach (['comprobantes', 'contacts', 'products', 'users', 'cobros', 'audit_logs', 'subscriptions', 'roles'] as $t) $this->assertArrayHasKey($t, $m['mapa'], $t);
        $this->assertArrayNotHasKey('plans', $m['mapa']); $this->assertArrayNotHasKey('migrations', $m['mapa']); $this->assertArrayNotHasKey('businesses', $m['mapa']);
        $items = collect($m['mapa'])->filter(fn($r) => $r['via'] === 'fk');
        $this->assertNotEmpty($items, 'Hay tablas sin business_id que entran por clave foránea (renglones, pivotes)');
        // Las que referencian van antes que las referenciadas.
        $pos = array_flip($m['orden']);
        $this->assertLessThan($pos['comprobantes'], $pos['cobros'] ?? PHP_INT_MAX, 'cobros (imputa comprobantes) se borra antes');
        $this->assertLessThan($pos['comprobantes'], $pos['comprobante_items'], 'los renglones (por clave foránea) se borran antes que los comprobantes');
    }

    public function test_elimina_todo_lo_de_la_empresa_y_no_toca_a_las_demas(): void
    {
        $this->cargarDatos();
        $mia = [$this->empresa, $this->sucursal, $this->dueno, $this->caja, $this->banco, $this->deposito];
        [$otra, $sucOtra, $duenoOtra, $cajaOtra, $bancoOtra, $depOtra] = $this->crearEmpresa('Otra SA', 'otra');
        [$this->empresa, $this->sucursal, $this->dueno, $this->caja, $this->banco, $this->deposito] = [$otra, $sucOtra, $duenoOtra, $cajaOtra, $bancoOtra, $depOtra];
        Auth::login($duenoOtra); $this->actingAs($duenoOtra);
        $po = $this->articulo(['name' => 'Arena', 'stock_inicial' => 5, 'price' => 100]); $co = $this->cliente(['name' => 'Cliente Otra']);
        $fo = $this->factura($co, [['product_id' => $po->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        [$this->empresa, $this->sucursal, $this->dueno, $this->caja, $this->banco, $this->deposito] = $mia;
        $idEmpresa = $this->empresa->id;

        $this->superadmin();
        $svc = app(EliminarEmpresaService::class);
        $plan = $svc->plan($this->empresa);
        $this->assertGreaterThan(0, $plan['comprobantes']); $this->assertGreaterThan(0, $plan['users']);
        $r = $svc->eliminar($this->empresa, Auth::user());
        $this->assertGreaterThan(20, $r['filas']);

        $this->assertNull(Business::find($idEmpresa));
        foreach (['comprobantes', 'comprobante_items', 'contacts', 'products', 'cobros', 'users', 'roles', 'subscriptions', 'cuentas_fondos', 'movimientos_fondos', 'asientos'] as $t) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($t)) continue;
            $cols = \Illuminate\Support\Facades\Schema::getColumnListing($t);
            if (in_array('business_id', $cols, true)) $this->assertSame(0, DB::table($t)->where('business_id', $idEmpresa)->count(), "quedaron filas en {$t}");
        }
        $this->assertSame(0, DB::table('comprobante_items')->whereNotIn('comprobante_id', DB::table('comprobantes')->select('id'))->count(), 'Sin renglones huérfanos');
        // La otra empresa sigue entera.
        $this->assertNotNull(Business::find($otra->id)); $this->assertNotNull(User::find($duenoOtra->id));
        $this->assertNotNull(Comprobante::withoutGlobalScopes()->find($fo->id)); $this->assertNotNull(Product::withoutGlobalScopes()->find($po->id)); $this->assertNotNull(Contact::withoutGlobalScopes()->find($co->id));
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('accion', 'eliminar')->where('descripcion', 'like', '%Empresa eliminada definitivamente: Empresa Test%')->exists());
    }

    public function test_desde_el_panel_pide_el_nombre_exacto_y_no_deja_borrar_la_empresa_de_bigsys(): void
    {
        $this->cargarDatos();
        $this->superadmin();
        $this->getJson("/admin/empresas/{$this->empresa->id}/eliminar")->assertOk()->assertJsonPath('empresa', 'Empresa Test')->assertJsonStructure(['filas', 'tablas', 'detalle', 'usuarios']);
        $this->delete("/admin/empresas/{$this->empresa->id}", ['nombre' => 'Otra cosa'])->assertSessionHasErrors('nombre');
        $this->assertNotNull(Business::find($this->empresa->id));
        $this->delete("/admin/empresas/{$this->empresa->id}", ['nombre' => ' empresa test '])->assertRedirect('/admin/empresas')->assertSessionHas('success');
        $this->assertNull(Business::find($this->empresa->id));
        $this->assertSame(0, Cobro::withoutGlobalScopes()->count());
    }

    public function test_un_dueno_comun_no_puede_eliminar_empresas(): void
    {
        $this->delete("/admin/empresas/{$this->empresa->id}", ['nombre' => 'Empresa Test'])->assertStatus(403);
        $this->assertNotNull(Business::find($this->empresa->id));
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(EliminarEmpresaService::class)->eliminar($this->empresa, $this->dueno);
    }
}
