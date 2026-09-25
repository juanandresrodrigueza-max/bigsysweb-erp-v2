<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\SueldoConcepto;
use App\Services\Sueldos\SueldosService;
use Tests\ErpTestCase;

// Reproduce al centavo los recibos reales de BIGSYS S.R.L. (Comercio CCT 130/75, agosto 2026).
class SueldosComercioTest extends ErpTestCase
{
    private function preparar(): array
    {
        $b = $this->empresa;
        SueldoConcepto::cargarPlantilla($b->id, 'comercio');
        $b->forceFill(['sueldos' => ['redondeo' => 'peso', 'codigo_redondeo' => '3950', 'deposito_banco' => 'BCO.REGIONAL']])->save();
        $base = ['business_id' => $b->id, 'sueldo_basico' => 586482.50, 'jornada' => 0.5, 'modalidad' => 'mensual', 'activo' => true, 'categoria' => 'ADMINISTRATIVO CAT. A 1/2 DIA', 'centro_costo' => 'ADMINISTRACION', 'lugar_trabajo' => 'MENDOZA'];
        $barboza = Empleado::create($base + ['legajo' => 11, 'nombre' => 'BARBOZA, PAOLA CARLA', 'cuil' => '27-25781816-6', 'documento' => '25781816', 'fecha_ingreso' => '2012-06-08']);
        $maure = Empleado::create($base + ['legajo' => 15, 'nombre' => 'MAURE JUAN PABLO', 'cuil' => '20-26314728-7', 'documento' => '26314728', 'fecha_ingreso' => '2015-06-01']);
        $maure->conceptos()->attach(SueldoConcepto::where('codigo', '2151')->value('id'));
        $liq = app(SueldosService::class)->liquidar($b->fresh(), '2026-08', 'mensual', [
            $barboza->id => ['dias' => 22, 'feriados' => 1, 'vacaciones' => 7],
            $maure->id => ['dias' => 29, 'feriados' => 1],
        ]);
        return [$liq->items->firstWhere('empleado_id', $barboza->id), $liq->items->firstWhere('empleado_id', $maure->id), $liq];
    }

    private function montos($item): array
    {
        return collect($item->detalle)->mapWithKeys(fn($d) => [$d['codigo'] => round((float) $d['monto'], 2)])->all();
    }

    public function test_recibo_de_barboza_coincide_al_centavo(): void
    {
        [$i] = $this->preparar();
        $m = $this->montos($i);
        foreach (['1000' => 430087.17, '1042' => 23459.30, '1051' => 164215.10, '1102' => 86486.62, '1200' => 12500, '1238' => 74100, '1295' => 58687.35,
            '2000' => 83922.91, '2001' => 22888.07, '2100' => 41516.96, '2104' => 8705.17, '2120' => 100, '2150' => 16740.71, '2152' => 4185.18, '3950' => 0.46,
            '5000' => 81779.62, '5002' => 12062.09, '5003' => 35703.26, '5004' => 7156.90, '5040' => 1827, '5041' => 25825.88, '5100' => 82866.52, '5101' => 17577.75, '5905' => 424.62, '5907' => 28000] as $c => $v)
            $this->assertEqualsWithDelta($v, $m[$c] ?? null, 0.001, "Concepto {$c}");
        $this->assertArrayNotHasKey('2151', $m, 'CEC solo para los asignados');
        $this->assertEqualsWithDelta(762935.54, (float) $i->bruto, 0.001);
        $this->assertEqualsWithDelta(86600, (float) $i->no_rem, 0.001);
        $this->assertEqualsWithDelta(178059.00, (float) $i->deducciones, 0.001);
        $this->assertEqualsWithDelta(671477.00, (float) $i->neto, 0.001);
        $this->assertEqualsWithDelta(293223.64, (float) $i->contribuciones, 0.001);
    }

    public function test_recibo_de_maure_con_cec_coincide_al_centavo(): void
    {
        [, $i, $liq] = $this->preparar();
        $m = $this->montos($i);
        foreach (['1000' => 566933.08, '1042' => 23459.30, '1102' => 64943.16, '1238' => 72150, '1295' => 54611.30, '2000' => 78094.15, '2100' => 38792.00, '2151' => 7820.97, '2152' => 3910.48, '3950' => 0.92, '5000' => 76073.53, '5041' => 24155.74] as $c => $v)
            $this->assertEqualsWithDelta($v, $m[$c] ?? null, 0.001, "Concepto {$c}");
        $this->assertEqualsWithDelta(709946.84, (float) $i->bruto, 0.001);
        $this->assertEqualsWithDelta(84650, (float) $i->no_rem, 0.001);
        $this->assertEqualsWithDelta(173791.76, (float) $i->deducciones, 0.001);
        $this->assertEqualsWithDelta(620806.00, (float) $i->neto, 0.001);
        $this->assertEqualsWithDelta(275422.62, (float) $i->contribuciones, 0.001);
        $this->assertSame('2026-07', $liq->fresh()->deposito_periodo);
        $this->assertSame('BCO.REGIONAL', $liq->fresh()->deposito_banco);
    }

    public function test_el_recibo_legal_muestra_los_datos_del_legajo_el_deposito_y_el_costo(): void
    {
        [$i, , $liq] = $this->preparar();
        $this->post("/sueldos/{$liq->id}/deposito", ['deposito_fecha' => '2026-08-07', 'deposito_banco' => 'BCO.REGIONAL', 'deposito_periodo' => '2026-07'])->assertSessionHasNoErrors();
        $this->get("/sueldos/{$liq->id}/recibo/{$i->id}")->assertOk()
            ->assertSee('BARBOZA, PAOLA CARLA')->assertSee('DU 25781816')->assertSee('ADMINISTRACION')->assertSee('04/09/2026')
            ->assertSee('DIAS VACACIONES')->assertSee('671.477,00')->assertSee('07/08/26, BCO.REGIONAL, mes 07/26')
            ->assertSee('SON PESOS SEISCIENTOS SETENTA Y UN MIL CUATROCIENTOS SETENTA Y SIETE CON 00/100')
            ->assertSee('1.142.759,18')->assertSee('58,76%')->assertDontSee('SEGURO ASISTENCIAL CEC');
    }

    public function test_plantilla_por_pantalla_y_conceptos_asignados_al_empleado(): void
    {
        $this->post('/sueldos/plantilla/comercio')->assertSessionHasNoErrors();
        $this->assertSame('peso', $this->empresa->fresh()->sueldos['redondeo']);
        $this->assertSame(25, SueldoConcepto::where('activo', true)->count());
        $cec = SueldoConcepto::where('codigo', '2151')->value('id');
        $this->post('/sueldos/empleados', ['nombre' => 'Pérez', 'fecha_ingreso' => '2020-01-01', 'sueldo_basico' => 1000000, 'modalidad' => 'mensual', 'jornada' => 0.5, 'documento' => '30111222', 'asignados' => [['id' => $cec, 'valor' => 1.5]]])->assertSessionHasNoErrors();
        $e = Empleado::where('nombre', 'Pérez')->first();
        $this->assertEqualsWithDelta(0.5, (float) $e->jornada, 0.001);
        $this->assertEqualsWithDelta(1.5, (float) $e->conceptos->first()->pivot->valor, 0.001, 'El valor propio pisa al del concepto');
        $this->post('/sueldos/conceptos', ['codigo' => 'X1', 'nombre' => 'Mal', 'tipo' => 'haber', 'modo' => 'porcentaje', 'valor' => 1, 'base' => 'REM; DROP'])->assertSessionHasErrors('base');
    }
}
