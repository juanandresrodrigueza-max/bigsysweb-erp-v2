<?php

namespace Tests\Feature;

use App\Models\DescuentoLista;
use App\Models\PrecioPactado;
use App\Models\Rubro;
use Tests\ErpTestCase;

// Fase 26.4: descuentos especiales por lista de precios, por artículo o rubro, desde una cantidad, con vigencia.
class DescuentosListaTest extends ErpTestCase
{
    public function test_la_lista_aplica_su_descuento_por_cantidad_rubro_precio_especial_y_vigencia(): void
    {
        $bebidas = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Bebidas']);
        $a = $this->articulo(['price' => 120, 'prices' => ['2' => 100], 'stock_inicial' => 50]);
        $b = $this->articulo(['price' => 120, 'prices' => ['2' => 100], 'stock_inicial' => 50, 'rubro_id' => $bebidas->id]);
        $c = $this->articulo(['price' => 120, 'prices' => ['2' => 100], 'stock_inicial' => 50]);
        $d = $this->articulo(['price' => 120, 'prices' => ['2' => 100], 'stock_inicial' => 50]);
        $this->post('/stock/descuentos-lista', ['lista' => 2, 'product_id' => $a->id, 'cantidad_minima' => 10, 'descuento' => 15])->assertSessionHasNoErrors();
        $this->post('/stock/descuentos-lista', ['lista' => 2, 'rubro_id' => $bebidas->id, 'descuento' => 5, 'precio' => 1])->assertSessionHasNoErrors();
        $this->assertNull(DescuentoLista::where('rubro_id', $bebidas->id)->value('precio'), 'Un rubro solo lleva %');
        $this->post('/stock/descuentos-lista', ['lista' => 2, 'product_id' => $c->id, 'precio' => 80])->assertSessionHasNoErrors();
        $this->post('/stock/descuentos-lista', ['lista' => 2, 'product_id' => $d->id, 'descuento' => 30, 'vigente_hasta' => today()->subDay()->toDateString()])->assertSessionHasNoErrors();
        $this->post('/stock/descuentos-lista', ['lista' => 1, 'product_id' => $a->id, 'descuento' => 50])->assertSessionHasNoErrors(); // otra lista: no aplica

        $this->getJson('/comprobantes/descuentos-lista/2')->assertOk()->assertJsonPath("articulos.{$a->id}.0.min", 10)->assertJsonPath("rubros.{$bebidas->id}.0.descuento", 5);

        $cli = $this->cliente(['lista_precios' => 2]);
        $f = $this->factura($cli, [
            ['product_id' => $a->id, 'cantidad' => 5, 'precio_unit' => 100], ['product_id' => $a->id, 'cantidad' => 12, 'precio_unit' => 100],
            ['product_id' => $b->id, 'cantidad' => 1, 'precio_unit' => 100], ['product_id' => $c->id, 'cantidad' => 1, 'precio_unit' => 100],
            ['product_id' => $d->id, 'cantidad' => 1, 'precio_unit' => 100],
        ]);
        $it = $f->items()->orderBy('orden')->get();
        $this->assertEquals(0, (float) $it[0]->descuento, 'Menos de 10: sin descuento');
        $this->assertEquals(15, (float) $it[1]->descuento, 'Desde 10: 15 %');
        $this->assertEquals(5, (float) $it[2]->descuento, 'Rubro Bebidas: 5 %');
        $this->assertEquals(80, (float) $it[3]->precio_unit, 'Precio especial de la lista');
        $this->assertEquals(0, (float) $it[4]->descuento, 'Vencido: no aplica');

        // El precio pactado del cliente manda sobre la lista.
        PrecioPactado::create(['business_id' => $this->empresa->id, 'contact_id' => $cli->id, 'product_id' => $a->id, 'precio' => 90, 'origen' => 'manual']);
        $f2 = $this->factura($cli, [['product_id' => $a->id, 'cantidad' => 12, 'precio_unit' => 90]]);
        $this->assertEquals(0, (float) $f2->items()->first()->descuento);
        $this->assertEquals(90, (float) $f2->items()->first()->precio_unit);

        // Pantalla.
        $this->get('/stock/descuentos-lista?lista=2')->assertOk()->assertInertia(fn($p) => $p->component('Stock/DescuentosLista', false)->has('reglas', 4));
        $this->post('/stock/descuentos-lista', ['lista' => 2, 'product_id' => $a->id])->assertStatus(422);
    }
}
