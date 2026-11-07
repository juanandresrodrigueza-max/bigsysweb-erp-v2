<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\OrdenEntrega;
use App\Models\TurnoCaja;
use App\Services\Fondos\FondosService;
use App\Services\Ventas\RepartoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 43: camiones y choferes, el chofer con usuario, la foto obligatoria, el buscador de direcciones,
// el registro del reparto, las alertas del cierre de caja y los cierres por día.
class RepartoCajaFase43Test extends ErpTestCase
{
    private function viaje(array $extra = []): OrdenEntrega
    {
        $cli = $this->cliente(['name' => 'Obra Sur']);
        $p = $this->articulo(['stock_inicial' => 50]);
        $f = $this->factura($cli, [['product_id' => $p->id, 'descripcion' => 'x', 'cantidad' => 2, 'precio_unit' => 100, 'alicuota_iva' => 21]], ['entrega_pendiente' => true]);
        $this->post('/comprobantes/entregas/armar', array_merge(['fecha' => today()->toDateString(), 'paradas' => [['tipo' => 'comprobante', 'id' => $f->id]]], $extra))->assertSessionHasNoErrors();
        return OrdenEntrega::latest('id')->first();
    }

    public function test_camiones_choferes_chofer_con_usuario_y_foto_obligatoria(): void
    {
        $this->actingAs($this->dueno);
        $chofer = $this->usuarioConRol('chofer', 'chofer@x.com');
        $this->post('/comprobantes/entregas/reparto-config', ['camiones' => [['nombre' => 'Iveco blanco', 'patente' => 'AB123CD']], 'choferes' => [['nombre' => 'Carlos', 'user_id' => $chofer->id]], 'foto_obligatoria' => true])->assertSessionHas('success');
        $this->assertSame('Iveco blanco', app(RepartoService::class)->config($this->empresa->fresh())['camiones'][0]['nombre']);
        $oe = $this->viaje(['repartidor' => 'Carlos', 'vehiculo' => 'Iveco blanco']);
        $this->assertSame($chofer->id, $oe->chofer_user_id);
        // El chofer entra y va directo a sus repartos.
        $this->actingAs($chofer)->get('/dashboard')->assertRedirect('/mis-repartos');
        $this->actingAs($chofer)->get('/mis-repartos')->assertInertia(fn($pg) => $pg->component('Comprobantes/MisRepartos', false)->count('viajes', 1));
        // Sin foto no se cierra la entrega.
        $it = $oe->items()->first();
        $this->post("/reparto/{$oe->token_chofer}/parada/{$it->id}/entregue", ['recibio' => 'Juan'])->assertSessionHasErrors('foto');
        $this->post("/reparto/{$oe->token_chofer}/parada/{$it->id}/entregue", ['recibio' => 'Juan', 'foto' => UploadedFile::fake()->image('r.jpg')])->assertSessionHas('ok');
        // Cambiar camión y chofer de otro viaje armado.
        $this->actingAs($this->dueno);
        $oe2 = $this->viaje();
        $this->post("/comprobantes/entregas/{$oe2->id}/asignar", ['vehiculo' => 'Iveco blanco', 'repartidor' => 'Carlos'])->assertSessionHas('success');
        $this->assertSame($chofer->id, $oe2->fresh()->chofer_user_id);
    }

    public function test_el_chofer_no_ocupa_un_usuario_del_plan(): void
    {
        $this->actingAs($this->dueno);
        $plan = $this->empresa->activeSubscription->plan; $plan->update(['max_users' => $this->empresa->users()->count()]);
        $rol = \App\Models\Role::where('slug', 'chofer')->value('id');
        $this->post('/configuracion/usuarios', ['name' => 'Chofer Dos', 'email' => 'c2@x.com', 'password' => 'secreto123', 'password_confirmation' => 'secreto123', 'role_id' => $rol, 'status' => 'active'])->assertSessionMissing('error');
        $this->assertDatabaseHas('users', ['email' => 'c2@x.com']);
    }

    public function test_buscar_direccion_registro_y_buscador_de_repartos(): void
    {
        $this->actingAs($this->dueno);
        $this->sucursal->update(['lat' => -31.42, 'lng' => -64.18]);
        $this->cliente(['name' => 'Ferretería Centro', 'address' => 'San Martín 100', 'lat' => -31.43, 'lng' => -64.19]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['display_name' => 'San Martín 100, Río Cuarto', 'lat' => '-33.12', 'lon' => '-64.35']])]);
        $r = $this->getJson('/comprobantes/entregas/direccion?q=' . urlencode('San Martín 100'))->assertOk()->json();
        $this->assertSame('cliente', $r[0]['tipo']);          // los clientes ya ubicados, primero
        $this->assertLessThan(2, $r[0]['km']);
        $m = $this->getJson('/comprobantes/entregas/direccion?q=' . urlencode('https://www.google.com/maps/place/x/@-31.4,-64.2,17z'))->json();
        $this->assertEquals(-31.4, $m[0]['lat']);
        // El recorrido queda guardado.
        $oe = $this->viaje(['repartidor' => 'Pepe']);
        $svc = app(RepartoService::class);
        $svc->posicion($oe, -31.42, -64.18); $this->travel(1)->minutes(); $svc->posicion($oe, -31.43, -64.18); $this->travel(10)->seconds(); $svc->posicion($oe, -31.44, -64.18);
        $reg = $svc->registro($oe->fresh());
        $this->assertSame(2, $reg['puntos']);                // el tercero llegó a los 10 segundos
        $this->assertGreaterThan(1, $reg['km']);
        $this->assertStringStartsWith('https://www.google.com/maps/dir/', $reg['maps']);
        $this->get('/comprobantes/entregas?q=Pepe')->assertInertia(fn($pg) => $pg->count('ordenes', 1));
        $this->get('/comprobantes/entregas?q=' . urlencode('Obra Sur'))->assertInertia(fn($pg) => $pg->count('ordenes', 1));
    }

    public function test_alerta_de_cierre_fuera_de_lo_normal_y_cierres_por_dia(): void
    {
        $this->actingAs($this->dueno);
        $fondos = app(FondosService::class);
        foreach (range(1, 6) as $i) { $t = $fondos->abrirTurno($this->caja->fresh(), (float) $this->caja->fresh()->saldo); $fondos->registrar($this->caja->fresh(), ['origen' => 'cobro', 'concepto' => 'v', 'ingreso' => 1000]); $fondos->cerrarTurno($t->fresh(), (float) $this->caja->fresh()->saldo); }
        $this->assertSame(0, Alerta::where('tipo', 'cierre_raro')->count());
        $t = $fondos->abrirTurno($this->caja->fresh(), (float) $this->caja->fresh()->saldo);
        $fondos->registrar($this->caja->fresh(), ['origen' => 'cobro', 'concepto' => 'v', 'ingreso' => 500000]);
        $fondos->cerrarTurno($t->fresh(), (float) $this->caja->fresh()->saldo);
        $this->assertStringContainsStringIgnoringCase("mucho efectivo", Alerta::where('tipo', 'cierre_raro')->value('detalle'));
        $this->get('/fondos/cierres')->assertInertia(fn($pg) => $pg->where('turnos.0.comprobantes', 1)->has('turnos.0.hora_cierre')->has('turnos.0.dia'));
    }
}
