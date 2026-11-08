<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Comanda;
use App\Models\Mesa;
use Tests\ErpTestCase;

// Fase 51: la carta QR de la mesa con las opciones de cada plato, a la estación que corresponde, y llamar al mozo o pedir la cuenta.
class GastronomiaQrFase51Test extends ErpTestCase
{
    public function test_pedido_qr_con_opciones_estacion_y_llamar_al_mozo(): void
    {
        $this->empresa->forceFill(['vertical' => 'gastronomia', 'tienda' => ['activa' => true, 'menu_activo' => true, 'slug' => 'parrilla-test']])->save();
        $this->post('/gastronomia/estaciones', ['estaciones' => [['nombre' => 'Cocina', 'alerta' => 20], ['nombre' => 'Parrilla', 'alerta' => 25]]]);
        $bife = $this->articulo(['name' => 'Bife', 'price' => 10000, 'iva' => 0]);
        $bife->forceFill(['en_tienda' => true])->save();
        $this->post("/gastronomia/carta/{$bife->id}", ['va_cocina' => true, 'estacion' => 'Parrilla', 'modificadores' => [['nombre' => 'Punto', 'obligatorio' => true, 'opciones' => [['nombre' => 'Jugoso'], ['nombre' => 'Cocido']]], ['nombre' => 'Extras', 'multiple' => true, 'opciones' => [['nombre' => '+ Huevo', 'precio' => 800]]]]]);
        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '7', 'sector' => 'Patio', 'capacidad' => 4, 'activa' => true]);
        auth()->logout();

        $this->get("/m/parrilla-test?mesa={$m->id}")->assertOk()->assertSee('Llamar al mozo')->assertSee('Jugoso', false);
        $this->post('/m/parrilla-test/pedir', ['mesa_id' => $m->id, 'items' => [['product_id' => $bife->id, 'cantidad' => 1]]])->assertSessionHasErrors('modificadores');
        $this->post('/m/parrilla-test/pedir', ['mesa_id' => $m->id, 'items' => [['product_id' => $bife->id, 'cantidad' => 1, 'modificadores' => ['Punto' => ['Jugoso'], 'Extras' => ['+ Huevo']]]]])->assertOk();
        $c = Comanda::withoutGlobalScopes()->latest('id')->first(); $it = $c->items()->first();
        $this->assertSame('Parrilla', $it->estacion);
        $this->assertStringContainsString('Jugoso', $it->descripcion);
        $this->assertEqualsWithDelta(10800, (float) $it->precio_unit, 0.01);
        $this->assertSame('cocina', $it->estado);

        $this->post('/m/parrilla-test/llamar', ['mesa_id' => $m->id, 'motivo' => 'cuenta'])->assertSessionHas('llamado', 'cuenta');
        $c->refresh(); $this->assertSame('cuenta', $c->llamado); $this->assertSame('cuenta', $c->estado);
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('tipo', 'llamado_mesa')->exists());

        $this->actingAs($this->dueno);
        $mesas = $this->get('/gastronomia')->viewData('page')['props']['mesas'];
        $this->assertSame('cuenta', collect($mesas)->firstWhere('id', $m->id)['comanda']['llamado']);
        $this->get("/gastronomia/comandas/{$c->id}")->assertOk();
        $this->assertNull($c->fresh()->llamado, 'El mozo la atendió');
    }

    public function test_si_el_mozo_confirma_los_pedidos_del_qr_quedan_sin_enviar(): void
    {
        $this->empresa->forceFill(['vertical' => 'gastronomia', 'tienda' => ['activa' => true, 'menu_activo' => true, 'slug' => 'bar-test']])->save();
        $this->post('/gastronomia/estaciones', ['qr_confirma' => true, 'estaciones' => [['nombre' => 'Cocina', 'alerta' => 20]]]);
        $p = $this->articulo(['name' => 'Papas', 'price' => 5000]); $p->forceFill(['en_tienda' => true, 'va_cocina' => true])->save();
        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '2', 'sector' => 'Salón', 'capacidad' => 2, 'activa' => true]);
        auth()->logout();
        $this->post('/m/bar-test/pedir', ['mesa_id' => $m->id, 'items' => [['product_id' => $p->id, 'cantidad' => 2]]])->assertOk();
        $this->assertSame('pedido', Comanda::withoutGlobalScopes()->latest('id')->first()->items()->first()->estado);
    }
}
