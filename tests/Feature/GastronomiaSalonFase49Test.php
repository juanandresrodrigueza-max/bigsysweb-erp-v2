<?php

namespace Tests\Feature;

use App\Models\Comanda;
use App\Models\Comprobante;
use App\Models\Mesa;
use Tests\ErpTestCase;

// Fase 49: modificadores de cada plato (obligatorios, con precio) y dividir la cuenta (cada uno lo suyo y partes iguales).
class GastronomiaSalonFase49Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->forceFill(['vertical' => 'gastronomia'])->save();
    }

    private function mesa(): Comanda
    {
        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '4', 'sector' => 'Salón', 'capacidad' => 4, 'activa' => true]);
        $this->post('/gastronomia/comandas', ['mesa_id' => $m->id])->assertSessionHasNoErrors();
        return Comanda::latest('id')->first();
    }

    public function test_modificadores_obligatorios_con_precio_salen_en_la_comanda(): void
    {
        $bife = $this->articulo(['name' => 'Bife de chorizo', 'price' => 10000, 'iva' => 0]);
        $this->post("/gastronomia/carta/{$bife->id}", ['va_cocina' => true, 'modificadores' => [
            ['nombre' => 'Punto', 'obligatorio' => true, 'multiple' => false, 'opciones' => [['nombre' => 'Jugoso'], ['nombre' => 'A punto']]],
            ['nombre' => 'Extras', 'obligatorio' => false, 'multiple' => true, 'opciones' => [['nombre' => '+ Huevo', 'precio' => 800], ['nombre' => '+ Queso', 'precio' => 1200]]],
        ]])->assertSessionHas('success');
        $this->get('/gastronomia/carta')->assertOk();
        $c = $this->mesa();
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $bife->id])->assertSessionHasErrors('modificadores');
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $bife->id, 'modificadores' => ['Punto' => ['Jugoso', 'A punto'], 'Extras' => ['+ Huevo', '+ Queso', 'Inventado']]])->assertSessionHasNoErrors();
        $it = $c->items()->first();
        $this->assertStringContainsString('Jugoso', $it->descripcion);
        $this->assertStringNotContainsString('A punto', $it->descripcion, 'El punto es uno solo');
        $base = (float) $this->get("/gastronomia/comandas/{$c->id}")->viewData('page')['props']['productos'][0]['price'];
        $this->assertEqualsWithDelta($base + 2000, (float) $it->precio_unit, 0.01);
        $this->assertCount(3, $it->modificadores);
    }

    public function test_dividir_la_cuenta_cada_uno_lo_suyo_y_partes_iguales(): void
    {
        $pizza = $this->articulo(['name' => 'Pizza', 'price' => 9000, 'iva' => 0]);
        $cerveza = $this->articulo(['name' => 'Cerveza', 'price' => 3000, 'iva' => 0]);
        $c = $this->mesa();
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $pizza->id, 'cantidad' => 1]);
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $cerveza->id, 'cantidad' => 3]);
        $c->refresh(); $total = (float) $c->total; [$iPizza, $iCerv] = $c->items()->orderBy('id')->get()->all();

        // Uno paga su cerveza
        $una = round($total - (float) $iPizza->precio_unit, 2) / 3;
        $this->post("/gastronomia/comandas/{$c->id}/cerrar", ['partes' => [$iCerv->id => 1], 'medios' => [['medio' => 'efectivo', 'monto' => $una]]])->assertSessionHas('success');
        $this->assertSame('abierta', $c->fresh()->estado);
        $this->assertEqualsWithDelta(1, (float) $iCerv->fresh()->pagado, 0.001);

        // Lo que falta, entre dos: dos partes iguales; la última cierra la mesa
        $falta = $total - $una;
        $this->post("/gastronomia/comandas/{$c->id}/cerrar", ['dividir' => 2, 'medios' => [['medio' => 'tarjeta', 'monto' => round($falta / 2, 2)]]])->assertSessionHas('success');
        $this->assertSame('abierta', $c->fresh()->estado);
        $this->post("/gastronomia/comandas/{$c->id}/cerrar", ['dividir' => 1, 'medios' => [['medio' => 'efectivo', 'monto' => round($falta / 2, 2) + 1]]])->assertRedirect('/gastronomia');
        $c->refresh();
        $this->assertSame('cerrada', $c->estado);
        $this->assertCount(3, $c->pagos);
        $facturado = Comprobante::whereIn('id', array_column($c->pagos, 'comprobante_id'))->sum('total');
        $this->assertEqualsWithDelta($total, (float) $facturado, 0.05, 'Las tres partes suman la cuenta');
        $this->post("/gastronomia/comandas/{$c->id}/cerrar", ['medios' => [['medio' => 'efectivo', 'monto' => 1]]])->assertStatus(422);
    }

    public function test_todo_junto_como_siempre(): void
    {
        $p = $this->articulo(['name' => 'Milanesa', 'price' => 7000, 'iva' => 0]);
        $c = $this->mesa();
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $p->id, 'cantidad' => 2]);
        $this->post("/gastronomia/comandas/{$c->id}/cerrar", ['propina' => 1000, 'medios' => [['medio' => 'efectivo', 'monto' => (float) $c->fresh()->total + 1000]]])->assertRedirect('/gastronomia');
        $c->refresh(); $this->assertSame('cerrada', $c->estado); $this->assertCount(1, $c->pagos); $this->assertEqualsWithDelta(1000, (float) $c->propina, 0.01);
    }
}
