<?php

namespace Tests\Feature;

use App\Models\AsientoLinea;
use App\Models\CuentaContable;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\Contabilidad\ContabilidadService;
use Tests\ErpTestCase;

// Fase 26.2: datos por rubro que heredan subrubros y artículos.
class RubrosDatosTest extends ErpTestCase
{
    private function arbol(): array
    {
        $alm = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Almacén', 'iva' => 10.5, 'perecedero' => true, 'perc_iva' => 1.5]);
        $lac = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Lácteos', 'parent_id' => $alm->id, 'control_turno' => true]);
        return [$alm, $lac];
    }

    public function test_el_subrubro_hereda_y_aplicar_lleva_los_datos_a_los_articulos(): void
    {
        [$alm, $lac] = $this->arbol();
        $e = $lac->fresh()->efectivos();
        $this->assertEquals(10.5, $e['iva']['valor']); $this->assertSame('Almacén', $e['iva']['de']);
        $this->assertTrue($e['control_turno']['valor']); $this->assertNull($e['control_turno']['de']);
        $p = $this->articulo(['rubro_id' => $lac->id, 'iva' => 21]);
        $this->post("/stock/rubros/{$alm->id}/aplicar", ['campos' => ['iva', 'perecedero', 'control_turno']])->assertSessionHasNoErrors();
        $p = $p->fresh();
        $this->assertEquals(10.5, (float) $p->iva); $this->assertTrue($p->perecedero); $this->assertTrue($p->control_turno);
        // Guardar por pantalla: nulo = hereda.
        $this->post("/stock/rubros/{$lac->id}", ['nombre' => 'Lácteos', 'parent_id' => $alm->id, 'iva' => null, 'seriado' => false, 'perc_iibb' => 2])->assertSessionHasNoErrors();
        $this->assertNull($lac->fresh()->iva); $this->assertFalse($lac->fresh()->seriado); $this->assertEquals(2, (float) $lac->fresh()->perc_iibb);
    }

    public function test_percepcion_especial_del_rubro_y_cuenta_de_ventas_propia(): void
    {
        [$alm, $lac] = $this->arbol();
        $padre = CuentaContable::where('tipo', 'ingreso')->where('imputable', true)->first();
        $cta = CuentaContable::create(['business_id' => $this->empresa->id, 'parent_id' => $padre->parent_id, 'codigo' => '4.1.99', 'nombre' => 'Ventas almacén', 'tipo' => 'ingreso', 'imputable' => true, 'activa' => true]);
        $alm->update(['cuenta_ventas_id' => $cta->id]);
        $this->empresa->update(['impuestos' => ['percepcion_iva' => ['activo' => true, 'alicuota' => 3, 'minimo' => 0, 'solo_ri' => true]]]);
        $leche = $this->articulo(['rubro_id' => $lac->id, 'stock_inicial' => 10, 'iva' => 21]);
        $otro = $this->articulo(['stock_inicial' => 10, 'iva' => 21]);
        $f = $this->factura($this->cliente(['percepcion_iva' => true]), [['product_id' => $leche->id, 'cantidad' => 10, 'precio_unit' => 100], ['product_id' => $otro->id, 'cantidad' => 2, 'precio_unit' => 500]]);
        $pv = $f->impuestos()->where('tipo', 'perc_iva')->orderBy('alicuota')->get();
        $this->assertCount(2, $pv, 'Una línea por alícuota');
        $this->assertEqualsWithDelta(15, $pv[0]->monto, 0.01, '1,5 % sobre los 1000 del rubro con percepción especial');
        $this->assertEqualsWithDelta(30, $pv[1]->monto, 0.01, '3 % general sobre los otros 1000');
        app(ContabilidadService::class)->sincronizar($this->empresa->id);
        $this->assertEqualsWithDelta(1000, (float) AsientoLinea::where('cuenta_id', $cta->id)->sum('haber'), 0.01, 'Lo del rubro va a su cuenta de ventas');
        $this->assertAsientosBalancean();
    }
}
