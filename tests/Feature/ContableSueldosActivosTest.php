<?php

namespace Tests\Feature;

use App\Models\ActivoFijo;
use App\Models\Asiento;
use App\Models\Cotizacion;
use App\Models\CuentaContable;
use App\Models\CuentaFondos;
use App\Models\Empleado;
use App\Services\Contabilidad\ActivosService;
use App\Services\Contabilidad\ContabilidadService;
use App\Services\Fondos\FondosService;
use App\Services\Fondos\MonedaService;
use App\Services\Sueldos\SueldosService;
use Tests\ErpTestCase;

class ContableSueldosActivosTest extends ErpTestCase
{
    public function test_el_estado_de_resultados_refleja_la_venta_y_todos_los_asientos_balancean(): void
    {
        $p = $this->articulo(['cost' => 60, 'stock_inicial' => 10]);
        $this->factura($this->cliente(), [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 100]]);
        app(FondosService::class)->registrar($this->caja, ['fecha' => today(), 'origen' => 'gasto', 'concepto' => 'Librería', 'egreso' => 50]);
        $r = app(ContabilidadService::class)->resultado($this->empresa->id, today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString());
        $this->assertEqualsWithDelta(200, $r['total_ingresos'], 0.01, 'Ventas netas');
        $this->assertEqualsWithDelta(120 + 50, $r['total_egresos'], 0.01, 'Costo de venta (2 × 60) más el gasto');
        $this->assertEqualsWithDelta(30, $r['resultado'], 0.01);
        $this->assertAsientosBalancean();
        $this->assertGreaterThanOrEqual(2, Asiento::count());
    }

    public function test_sincronizar_no_duplica_asientos(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $this->factura($this->cliente(), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $n = Asiento::count();
        app(ContabilidadService::class)->sincronizar($this->empresa->id);
        $this->assertSame($n, Asiento::count());
    }

    public function test_liquidacion_de_sueldos_calcula_antiguedad_extras_y_deducciones_y_contabiliza(): void
    {
        $e = Empleado::create(['business_id' => $this->empresa->id, 'legajo' => 1, 'nombre' => 'Juan', 'cuil' => '20-11111111-1', 'fecha_ingreso' => today()->subYears(3)->subDay()->toDateString(), 'sueldo_basico' => 1000000, 'modalidad' => 'mensual', 'activo' => true]);
        $svc = app(SueldosService::class);
        $liq = $svc->liquidar($this->empresa, today()->format('Y-m'), 'mensual', [$e->id => ['dias' => 30, 'horas_extra_50' => 10, 'anticipos' => 50000]]);
        $i = $liq->items->first();
        $det = collect($i->detalle)->keyBy('codigo');
        $this->assertEqualsWithDelta(30000, $det['ANT']['monto'], 0.01, 'Antigüedad: 1% por año × 3 años');
        $this->assertEqualsWithDelta(83300, $det['PRE']['monto'], 0.01, 'Presentismo 8,33%');
        $this->assertEqualsWithDelta(10 * 5000 * 1.5, $det['HE50']['monto'], 0.01, 'Hora = básico/200, al 50%');
        $bruto = 1000000 + 75000 + 30000 + 83300;
        $this->assertEqualsWithDelta($bruto, (float) $i->bruto, 0.01);
        $this->assertEqualsWithDelta($bruto * 0.19, (float) $i->deducciones, 0.5, 'Jubilación 11 + ley 19032 3 + obra social 3 + sindicato 2');
        $this->assertEqualsWithDelta($bruto - $bruto * 0.19 - 50000, (float) $i->neto, 0.5);
        $this->assertGreaterThan(0, (float) $i->contribuciones);
        $svc->confirmar($liq);
        $this->assertSame('confirmada', $liq->fresh()->estado);
        $this->assertNotNull($liq->fresh()->asiento_id);
        $svc->pagar($liq->fresh(), $this->banco, today()->toDateString(), true);
        $this->assertSame('pagada', $liq->fresh()->estado);
        $this->assertEqualsWithDelta(-((float) $i->neto + (float) $i->deducciones - 50000 + (float) $i->contribuciones), (float) $this->banco->fresh()->saldo, 0.5, 'Del banco salen netos y cargas');
        $this->assertAsientosBalancean();
    }

    public function test_aguinaldo_es_la_mitad_del_mejor_sueldo(): void
    {
        $e = Empleado::create(['business_id' => $this->empresa->id, 'legajo' => 1, 'nombre' => 'Ana', 'fecha_ingreso' => today()->subYear()->toDateString(), 'sueldo_basico' => 800000, 'modalidad' => 'mensual', 'activo' => true]);
        $liq = app(SueldosService::class)->liquidar($this->empresa, today()->format('Y-m'), 'sac', [$e->id => ['dias' => 180]]);
        $this->assertEqualsWithDelta(800000 * 1.01 / 2, (float) $liq->items->first()->bruto, 0.01);
    }

    public function test_importar_liquidacion_del_contador(): void
    {
        Empleado::create(['business_id' => $this->empresa->id, 'legajo' => 7, 'nombre' => 'Pedro', 'cuil' => '20-22222222-2', 'fecha_ingreso' => today()->toDateString(), 'sueldo_basico' => 1, 'activo' => true]);
        $liq = app(SueldosService::class)->importar($this->empresa, today()->format('Y-m'), 'mensual', "legajo;bruto;no rem;deducciones;neto;contribuciones\n7;900.000,00;50000;171000;779000;240000\n");
        $this->assertSame(1, $liq->items()->count());
        $this->assertEqualsWithDelta(900000, (float) $liq->total_bruto, 0.01);
        $this->assertEqualsWithDelta(779000, (float) $liq->total_neto, 0.01);
    }

    public function test_bienes_de_uso_amortizan_lineal_sin_duplicar_y_la_baja_da_resultado(): void
    {
        $svc = app(ActivosService::class);
        $a = $svc->alta(['nombre' => 'Camioneta', 'categoria' => 'rodados', 'fecha_alta' => today()->subMonths(2)->startOfMonth()->toDateString(), 'valor_origen' => 1200000, 'valor_residual' => 0, 'vida_util_meses' => 12], 'aporte');
        $this->assertEqualsWithDelta(100000, $a->cuotaMensual(), 0.01);
        $per = today()->subMonth()->format('Y-m');
        $r1 = $svc->amortizar($this->empresa, $per); $r2 = $svc->amortizar($this->empresa, $per);
        $this->assertSame(1, $r1['n']); $this->assertSame(0, $r2['n'], 'El mismo período no se amortiza dos veces');
        $this->assertEqualsWithDelta(100000, (float) $a->fresh()->amortizado, 0.01);
        $b = $svc->baja($a->fresh(), today()->toDateString(), 900000);
        $this->assertSame('vendido', $b->estado);
        $this->assertEqualsWithDelta(1100000, $a->fresh()->valorResidualContable(), 0.01);
        $this->assertAsientosBalancean();
        $this->assertNotNull(Asiento::where('origen', 'activo_baja')->first());
    }

    public function test_revaluar_la_tenencia_en_dolares_genera_diferencia_de_cambio(): void
    {
        Cotizacion::create(['business_id' => null, 'fecha' => today()->toDateString(), 'tipo' => 'oficial', 'compra' => 1400, 'venta' => 1450, 'fuente' => 'test']);
        $usd = CuentaFondos::create(['business_id' => $this->empresa->id, 'tipo' => 'caja', 'nombre' => 'Caja USD', 'moneda' => 'USD', 'activa' => true, 'cotizacion_cierre' => 1400]);
        app(FondosService::class)->registrar($usd, ['fecha' => today(), 'origen' => 'apertura', 'concepto' => 'Saldo inicial', 'ingreso' => 100, 'cotizacion' => 1400]);
        $r = app(MonedaService::class)->revaluar($this->empresa, 1450);
        $this->assertSame(1, $r['n']);
        $this->assertEqualsWithDelta(5000, $r['total'], 0.01, '100 USD × (1450 − 1400)');
        $this->assertEqualsWithDelta(1450, (float) $usd->fresh()->cotizacion_cierre, 0.01);
        $this->assertAsientosBalancean();
        $this->assertTrue(CuentaContable::where('clave', 'dif_cambio')->exists());
    }
}
