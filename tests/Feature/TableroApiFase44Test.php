<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Canal;
use App\Models\Catalogo;
use App\Models\Role;
use App\Services\Canales\PublicacionesService;
use App\Services\Ventas\CatalogoService;
use Illuminate\Support\Facades\Artisan;
use Tests\ErpTestCase;

// Fase 44: unidades por producto, lo que salió, partes de estadísticas por rol, colchón mínimo, API con buscar/columnas/tokens limitados, stock de otra empresa en el catálogo y alerta de IVA.
class TableroApiFase44Test extends ErpTestCase
{
    public function test_unidades_por_producto_con_rubro_y_lo_que_salio(): void
    {
        $p = $this->articulo(['name' => 'Tornillo', 'stock_inicial' => 50]);
        $this->factura($this->cliente(), [['product_id' => $p->id, 'descripcion' => 'x', 'cantidad' => 7, 'precio_unit' => 100, 'alicuota_iva' => 21]]);
        $abc = $this->get('/estadisticas/ventas')->assertOk()->viewData('page')['props']['abc']['items'];
        $this->assertSame(7.0, (float) collect($abc)->firstWhere('id', $p->id)['unidades']);
        $this->assertArrayHasKey('rubro', $abc[0]);
        $salio = $this->get('/estadisticas/stock')->assertOk()->viewData('page')['props']['salio']['items'];
        $this->assertEqualsWithDelta(7, collect($salio)->firstWhere('id', $p->id)['salio'], 0.001);
        $this->assertArrayNotHasKey('valor', $salio[0], 'Sin importes');
    }

    public function test_rol_sin_la_parte_plata_no_la_ve(): void
    {
        $r = Role::create(['business_id' => $this->empresa->id, 'nombre' => 'Mirón', 'slug' => 'miron', 'permisos' => ['estadisticas' => ['ver'], '_estadisticas' => ['ventas']]]);
        $u = $this->usuarioConRol('miron', 'miron@test.com');
        $this->actingAs($u)->get('/estadisticas/ventas')->assertOk();
        $this->actingAs($u)->get('/estadisticas/plata')->assertForbidden();
        $this->actingAs($u)->get('/estadisticas/stock')->assertForbidden();
    }

    public function test_colchon_fijo_y_minimo(): void
    {
        $p = $this->articulo(['stock_inicial' => 12]);
        $c = Canal::create(['business_id' => $this->empresa->id, 'tipo' => 'woocommerce', 'nombre' => 'Woo', 'credenciales' => [], 'activo' => true, 'config' => ['colchon' => 2]]);
        $s = app(PublicacionesService::class);
        $this->assertSame(10, $s->stockPara($c, $p->fresh()));
        $this->post("/configuracion/tienda/canales/{$c->id}/config", ['colchon' => 2, 'colchon_modo' => 'minimo'])->assertSessionHasNoErrors();
        $this->assertSame(12, $s->stockPara($c->fresh(), $p->fresh()));
        $p->update(['stock' => 2]);
        $this->assertSame(0, $s->stockPara($c->fresh(), $p->fresh()));
    }

    public function test_api_buscar_sin_tildes_columnas_y_token_limitado(): void
    {
        $this->cliente(['name' => 'Peña Muñoz']); $this->cliente(['name' => 'Otro']);
        $tok = $this->dueno->createToken('todo')->plainTextToken;
        $r = $this->withToken($tok)->getJson('/api/exportar/clientes?buscar=PENA&columnas=id,name')->assertOk()->json();
        $this->assertSame(1, $r['total']);
        $this->assertSame(['id', 'name'], array_keys($r['datos'][0]));

        $this->post('/configuracion/seguridad/tokens', ['nombre' => 'Limitado', 'tablas' => 'clientes:id,name'])->assertSessionHasNoErrors();
        $lim = session('token_nuevo'); $this->assertSame(['exportar:clientes:id,name'], \Laravel\Sanctum\PersonalAccessToken::latest('id')->first()->abilities);
        $this->app['auth']->guard('web')->logout(); $this->app['auth']->forgetGuards();
        $res = $this->withToken($lim)->getJson('/api/exportar/clientes')->assertOk(); $d = $res->json('datos');
        $this->assertSame(['id', 'name'], array_keys($d[0]));
        $this->app['auth']->guard('web')->logout(); $this->app['auth']->forgetGuards();
        $this->withToken($lim)->getJson('/api/exportar/articulos')->assertForbidden();
    }

    public function test_catalogo_con_stock_de_otra_empresa_del_grupo(): void
    {
        [$otra, , $duenoOtra] = $this->crearEmpresa('Depósito Central', 'central');
        $this->dueno->empresas()->syncWithoutDetaching([$otra->id => ['role_id' => $this->dueno->role_id]]);
        $mio = $this->articulo(['name' => 'Cemento', 'sku' => 'CEM1', 'stock_inicial' => 1]);
        \App\Models\Product::withoutGlobalScopes()->create(['business_id' => $otra->id, 'name' => 'Cemento', 'sku' => 'CEM1', 'stock' => 40, 'active' => true, 'price' => 100, 'cost' => 50]);
        $cat = Catalogo::create(['business_id' => $this->empresa->id, 'nombre' => 'Lista', 'lista' => 1, 'mostrar_stock' => true, 'stock_de' => (string) $otra->id, 'token' => 'abc123', 'activo' => true]);
        $this->assertEqualsWithDelta(40, CatalogoService::stockDe($cat)['cem1'], 0.01);
        $cat->update(['stock_de' => 'todas']);
        $this->assertEqualsWithDelta(41, CatalogoService::stockDe($cat->fresh())['cem1'], 0.01);
        $cat->update(['stock_de' => '999999']);
        $this->assertNull(CatalogoService::stockDe($cat->fresh()), 'Una empresa ajena no cuenta');
    }

    public function test_alerta_de_condicion_de_iva(): void
    {
        $this->cliente(['name' => 'Raro SA', 'condicion_iva' => 'Consumidor Final', 'cuit' => '30-71234567-1']);
        Artisan::call('alertas:generar');
        $this->assertTrue(Alerta::where('tipo', 'condicion_iva')->whereNull('resuelta_en')->exists());
        $this->get('/clientes?estado=iva')->assertOk()->assertInertia(fn($p) => $p->where('lista.data.0.name', 'Raro SA')->etc());
    }
}
