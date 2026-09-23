<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\ExpenseCategory;
use App\Models\Vendedor;
use App\Services\Contabilidad\ActivosService;
use App\Services\Estadisticas\RentabilidadService;
use App\Services\Fondos\FondosService;
use App\Services\Sueldos\SueldosService;
use Tests\ErpTestCase;

class RentabilidadTest extends ErpTestCase
{
    public function test_el_costo_queda_congelado_al_emitir_y_el_cmv_usa_ese_costo(): void
    {
        $p = $this->articulo(['cost' => 100, 'price' => 200, 'stock_inicial' => 10]);
        $f = $this->factura($this->cliente(), [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 200]]);
        $this->assertEqualsWithDelta(100, (float) $f->items->first()->costo_unit, 0.001);
        $p->update(['cost' => 150]); // sube el costo después de vender
        $r = app(RentabilidadService::class)->calcular($this->empresa, today()->startOfMonth(), today()->endOfMonth());
        $this->assertEqualsWithDelta(400, $r['kpis']['ventas'], 0.01);
        $this->assertEqualsWithDelta(200, $r['kpis']['cmv'], 0.01, 'El costo de lo vendido es el del momento de la venta, no el actual');
        $this->assertEqualsWithDelta(50.0, $r['kpis']['margen_bruto_pct'], 0.01);
        $cmv = \App\Models\Asiento::where('origen', 'venta')->where('origen_id', $f->id)->first()->lineas->firstWhere('detalle', 'Costo de venta');
        $this->assertEqualsWithDelta(200, (float) $cmv->debe, 0.01, 'El asiento también usa el costo congelado');
    }

    public function test_separa_fijos_variables_directos_indirectos_y_calcula_punto_de_equilibrio(): void
    {
        $p = $this->articulo(['cost' => 100, 'price' => 250, 'stock_inicial' => 100]);
        $v = Vendedor::create(['business_id' => $this->empresa->id, 'nombre' => 'Vito', 'comision_venta' => 10, 'comision_cobro' => 0, 'activo' => true]);
        $this->factura($this->cliente(), [['product_id' => $p->id, 'cantidad' => 40, 'precio_unit' => 250]], ['vendedor_id' => $v->id]); // ventas 10.000, cmv 4.000
        $alq = ExpenseCategory::create(['business_id' => $this->empresa->id, 'name' => 'Alquiler'] + ExpenseCategory::sugerir('Alquiler'));
        $flete = ExpenseCategory::create(['business_id' => $this->empresa->id, 'name' => 'Fletes'] + ExpenseCategory::sugerir('Fletes'));
        $this->assertSame(['tipo_costo' => 'fijo', 'imputacion' => 'indirecto'], ExpenseCategory::sugerir('Alquiler'));
        $this->assertSame(['tipo_costo' => 'variable', 'imputacion' => 'directo'], ExpenseCategory::sugerir('Fletes'));
        $fondos = app(FondosService::class);
        $fondos->registrar($this->banco, ['fecha' => today()->toDateString(), 'origen' => 'gasto', 'expense_category_id' => $alq->id, 'concepto' => 'Alquiler', 'egreso' => 1800]);
        $fondos->registrar($this->banco, ['fecha' => today()->toDateString(), 'origen' => 'gasto', 'expense_category_id' => $flete->id, 'concepto' => 'Flete', 'egreso' => 500]);
        $fondos->registrar($this->banco, ['fecha' => today()->toDateString(), 'origen' => 'gasto', 'concepto' => 'Sin categoría', 'egreso' => 200]);

        $r = app(RentabilidadService::class)->calcular($this->empresa, today()->startOfMonth(), today()->endOfMonth());
        $k = $r['kpis'];
        $this->assertEqualsWithDelta(10000, $k['ventas'], 0.01);
        $this->assertEqualsWithDelta(6000, $k['margen_bruto'], 0.01);
        $this->assertEqualsWithDelta(500 + 1000, $k['variables'], 0.01, 'Flete + comisión 10% de 10.000');
        $this->assertEqualsWithDelta(4500, $k['margen_contribucion'], 0.01);
        $this->assertEqualsWithDelta(2000, $k['fijos'], 0.01, 'Alquiler + gasto sin categoría (fijo por defecto)');
        $this->assertEqualsWithDelta(2500, $k['resultado'], 0.01);
        $this->assertEqualsWithDelta(1500, $k['directos'], 0.01);
        $this->assertEqualsWithDelta(2000, $k['indirectos'], 0.01);
        $this->assertEqualsWithDelta(2000 / 0.45, $k['punto_equilibrio'], 0.01, 'Fijos / ratio de contribución');
        $vend = collect($r['por']['vendedores'])->firstWhere('clave', $v->id);
        $this->assertEqualsWithDelta(1000, $vend['directos'], 0.01);
        $this->assertEqualsWithDelta(6000 - 2000 - 1000, $vend['resultado'], 0.01, 'Margen bruto − indirectos repartidos − comisión');
        $this->assertSame(1, collect($r['lineas'])->where('nombre', 'Gastos sin categoría')->count());

        // Sin repartir indirectos el resultado por fila no los descuenta.
        $this->empresa->update(['rentabilidad' => ['distribuir_indirectos' => false, 'sin_categoria' => 'variable']]);
        $r2 = app(RentabilidadService::class)->calcular($this->empresa->fresh(), today()->startOfMonth(), today()->endOfMonth());
        $this->assertEqualsWithDelta(1800, $r2['kpis']['fijos'], 0.01);
        $this->assertEqualsWithDelta(0, collect($r2['por']['articulos'])->first()['indirectos'], 0.01);
    }

    public function test_suma_sueldos_cargas_y_amortizaciones_como_fijos_y_no_duplica_la_categoria_sueldos(): void
    {
        Empleado::create(['business_id' => $this->empresa->id, 'legajo' => 1, 'nombre' => 'Ana', 'fecha_ingreso' => today()->subYear()->toDateString(), 'sueldo_basico' => 100000, 'modalidad' => 'mensual', 'activo' => true]);
        $sue = app(SueldosService::class);
        $liq = $sue->liquidar($this->empresa, today()->format('Y-m'), 'mensual', [], today()->toDateString());
        $sue->confirmar($liq);
        $act = app(ActivosService::class);
        $act->alta(['nombre' => 'Camioneta', 'categoria' => 'rodados', 'fecha_alta' => today()->subMonths(2)->startOfMonth()->toDateString(), 'valor_origen' => 1200000, 'valor_residual' => 0, 'vida_util_meses' => 12], 'aporte');
        $act->amortizar($this->empresa, today()->format('Y-m'));
        $catSueldos = ExpenseCategory::create(['business_id' => $this->empresa->id, 'name' => 'Sueldos', 'tipo_costo' => 'fijo', 'imputacion' => 'indirecto']);
        app(FondosService::class)->registrar($this->banco, ['fecha' => today()->toDateString(), 'origen' => 'gasto', 'expense_category_id' => $catSueldos->id, 'concepto' => 'Adelanto', 'egreso' => 5000]);

        $r = app(RentabilidadService::class)->calcular($this->empresa, today()->startOfMonth(), today()->endOfMonth());
        $liq->refresh();
        $esperado = (float) $liq->total_bruto + (float) $liq->total_no_rem + (float) $liq->total_contribuciones + 100000;
        $this->assertEqualsWithDelta($esperado, $r['kpis']['fijos'], 0.01, 'Sueldos + cargas + amortización; la categoría Sueldos de fondos no se suma dos veces');
        $this->assertSame(['Sueldos'], $r['dobles']);
        $this->assertNull($r['kpis']['punto_equilibrio'], 'Sin ventas no hay punto de equilibrio');
        $this->assertEqualsWithDelta(-$esperado, $r['kpis']['resultado'], 0.01);
    }

    public function test_pagina_clasificacion_y_configuracion(): void
    {
        $c = ExpenseCategory::create(['business_id' => $this->empresa->id, 'name' => 'Publicidad']);
        $this->get('/estadisticas/rentabilidad')->assertOk()->assertInertia(fn($p) => $p->component('Estadisticas/Rentabilidad', false)->has('kpis.punto_equilibrio')->has('categorias', 1)->has('por.articulos'));
        $this->post('/estadisticas/rentabilidad/categorias', ['categorias' => [['id' => $c->id, 'tipo_costo' => 'variable', 'imputacion' => 'directo']]])->assertSessionHas('success');
        $this->assertSame('variable', $c->fresh()->tipo_costo);
        $this->post('/estadisticas/rentabilidad/config', ['distribuir_indirectos' => false, 'compras_gastos' => 'fijo', 'sin_categoria' => 'variable'])->assertSessionHas('success');
        $this->assertFalse($this->empresa->fresh()->rentabilidad['distribuir_indirectos']);
        $this->post('/fondos/categorias', ['name' => 'Fletes urbanos'])->assertSessionHas('success');
        $this->assertSame('variable', ExpenseCategory::where('name', 'Fletes urbanos')->first()->tipo_costo, 'Sugerencia por el nombre al crear desde Fondos');
        $this->get('/estadisticas/rentabilidad?export=1')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }
}
