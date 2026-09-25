<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Validation\ValidationException;
use Tests\ErpTestCase;

// Fase 26.7: límite de facturas por mes y de artículos según el plan.
class LimitesPlanTest extends ErpTestCase
{
    public function test_el_plan_corta_la_factura_numero_n_mas_1_del_mes_pero_no_presupuestos(): void
    {
        $plan = $this->empresa->activeSubscription->plan; $plan->update(['max_facturas_mes' => 2]);
        $p = $this->articulo(['stock_inicial' => 50]); $cli = $this->cliente();
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['tipo' => 'PRE']); // presupuesto: no cuenta
        try {
            $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
            $this->fail('Tenía que cortar la tercera factura');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('2 facturas del mes', $e->errors()['tipo'][0]);
        }
        $this->get('/suscripcion')->assertOk()->assertInertia(fn($pg) => $pg->where('uso.facturas', 2)->etc());
        $plan->update(['max_facturas_mes' => -1]);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertTrue(true);
    }

    public function test_el_plan_limita_los_articulos(): void
    {
        $this->articulo();
        $this->empresa->activeSubscription->plan->update(['max_products' => Product::count()]);
        $this->post('/stock/articulos', ['name' => 'Uno más', 'tipo' => 'producto', 'unit' => 'un', 'iva' => 21, 'price' => 10, 'cost' => 5])->assertSessionHasErrors('name');
        $this->empresa->activeSubscription->plan->update(['max_products' => -1]);
        $this->actingAs($this->dueno->fresh());
        $this->post('/stock/articulos', ['name' => 'Uno más', 'tipo' => 'producto', 'unit' => 'un', 'iva' => 21, 'price' => 10, 'cost' => 5])->assertSessionHasNoErrors();
    }
}
