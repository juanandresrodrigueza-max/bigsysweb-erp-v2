<?php

namespace Tests\Feature;

use App\Models\Ausencia;
use App\Models\Empleado;
use App\Models\Liquidacion;
use App\Services\Personal\AusenciasService;
use App\Services\Personal\FeriadosService;
use App\Support\FeriadosArgentina;
use Carbon\Carbon;
use Tests\ErpTestCase;

// Fase 98 · Capital humano: feriados calculados, vacaciones según LCT, ausencias que caen solas en la liquidación, Libro de Sueldos Digital y ganancias 4ta.
class Fase98Test extends ErpTestCase
{
    private function empleado(array $x = []): Empleado
    {
        return Empleado::create(array_replace(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'legajo' => (int) Empleado::withoutGlobalScopes()->where('business_id', $this->empresa->id)->max('legajo') + 1, 'nombre' => 'Juan Pérez', 'cuil' => '20-25781816-3', 'fecha_ingreso' => '2020-03-01', 'sueldo_basico' => 1000000, 'modalidad' => 'mensual', 'activo' => true, 'jornada' => 1], $x));
    }

    public function test_feriados_nacionales_calculados_y_los_propios_de_la_empresa(): void
    {
        $f = FeriadosArgentina::anio(2026);
        $this->assertSame('Carnaval', $f['2026-02-16']['nombre']); $this->assertSame('Carnaval', $f['2026-02-17']['nombre']); $this->assertSame('Viernes Santo', $f['2026-04-03']['nombre']);
        $this->assertArrayHasKey('2026-06-15', $f, 'Güemes (17/6, miércoles) pasa al lunes anterior'); $this->assertArrayNotHasKey('2026-06-17', $f);
        $this->assertArrayHasKey('2026-11-23', $f, 'Soberanía (20/11, viernes) pasa al lunes siguiente'); $this->assertArrayHasKey('2026-10-12', $f, 'Lunes: queda'); $this->assertArrayHasKey('2026-08-17', $f, 'Lunes: queda');
        $this->assertSame('2027-03-28', FeriadosArgentina::pascua(2027)->toDateString());

        $svc = app(FeriadosService::class);
        $this->assertSame(1, $svc->enMes($this->empresa, '2026-01'), 'Enero 2026: sólo Año Nuevo');
        $this->post('/sueldos/personal/feriados', ['fecha' => '2026-01-20', 'nombre' => 'Aniversario de la empresa', 'tipo' => 'propio'])->assertSessionHas('success');
        $this->post('/sueldos/personal/feriados', ['fecha' => '2026-12-08', 'nombre' => 'Inmaculada', 'tipo' => 'quitado'])->assertSessionHas('success');
        $this->assertSame(2, $svc->enMes($this->empresa, '2026-01'));
        $this->assertFalse($svc->esFeriado($this->empresa, Carbon::parse('2026-12-08')), 'Ese nacional esta empresa lo trabaja');
        $lista = collect($this->get('/sueldos/personal?anio=2026')->assertOk()->viewData('page')['props']['feriados']);
        $this->assertTrue($lista->firstWhere('fecha', '2026-12-08')['quitado']); $this->assertSame('propio', $lista->firstWhere('fecha', '2026-01-20')['origen']);
        $r = app(\App\Mcp\Catalogo::class)->llamar($this->dueno, 'feriados', ['mes' => '2026-01'], 'asistente');
        $this->assertSame(2, $r['feriados_del_mes']);
    }

    public function test_vacaciones_por_antiguedad_ausencias_y_las_novedades_del_mes_salen_solas(): void
    {
        $e = $this->empleado(); $svc = app(AusenciasService::class);
        $this->assertSame(21, $svc->derechoVacaciones($e, 2026)['dias'], '6 años de antigüedad: 21 días');
        $this->assertSame(14, $svc->derechoVacaciones($this->empleado(['nombre' => 'Nueva', 'fecha_ingreso' => '2024-02-01', 'cuil' => '27-30000000-1']), 2026)['dias']);
        $this->assertSame(35, $svc->derechoVacaciones($this->empleado(['nombre' => 'Vieja', 'fecha_ingreso' => '2000-01-10', 'cuil' => '27-20000000-1']), 2026)['dias']);
        $reciente = $this->empleado(['nombre' => 'Reciente', 'fecha_ingreso' => '2026-10-01', 'cuil' => '20-40000000-1']);
        $this->assertSame(3, $svc->derechoVacaciones($reciente, 2026)['dias'], 'Menos de 6 meses: 1 día cada 20 trabajados (92 días × 6/7 = 79 → 3)');

        // Vacaciones de enero (se imputan al año anterior), y una falta: se pisa → 422.
        $this->post('/sueldos/personal/ausencias', ['empleado_id' => $e->id, 'tipo' => 'vacaciones', 'desde' => '2026-01-05', 'hasta' => '2026-01-18'])->assertSessionHas('success');
        $a = Ausencia::latest('id')->first(); $this->assertEquals(14, $a->dias); $this->assertSame(2025, $a->anio_vacaciones); $this->assertSame('aprobada', $a->estado);
        $this->post('/sueldos/personal/ausencias', ['empleado_id' => $e->id, 'tipo' => 'enfermedad', 'desde' => '2026-01-16', 'hasta' => '2026-01-19'])->assertStatus(422);
        $this->post('/sueldos/personal/ausencias', ['empleado_id' => $e->id, 'tipo' => 'falta_injustificada', 'desde' => '2026-01-24', 'hasta' => '2026-01-26'])->assertSessionHas('success');
        $this->assertEquals(2, Ausencia::latest('id')->first()->dias, 'Sábado y lunes: el domingo no cuenta');
        $this->post('/sueldos/personal/ausencias', ['empleado_id' => $e->id, 'tipo' => 'enfermedad', 'desde' => '2026-01-28', 'hasta' => '2026-01-29', 'certificado' => true])->assertSessionHas('success');
        $s = $svc->saldoVacaciones($e, 2025); $this->assertSame(21, $s['derecho']); $this->assertEquals(14, $s['tomadas']); $this->assertEquals(7, $s['saldo']);

        $nov = $svc->novedadesPeriodo($this->empresa, '2026-01')[$e->id];
        $this->assertEquals(14, $nov['vacaciones']); $this->assertEquals(2, $nov['faltas']); $this->assertSame(1, $nov['feriados']); $this->assertSame(13, $nov['dias']); $this->assertCount(3, $nov['detalle']);
        $p = $this->get('/sueldos?periodo=2026-01')->assertOk()->viewData('page')['props'];
        $this->assertSame(13, $p['sugeridas'][$e->id]['dias']);
        $tablero = $this->get('/sueldos/personal?mes=2026-01&anio=2025')->viewData('page')['props'];
        $this->assertCount(3, $tablero['ausencias']); $this->assertEquals(7, collect($tablero['vacaciones'])->firstWhere('id', $e->id)['saldo']);

        // Bigcito: "cargale vacaciones a Juan del 2 al 8 de marzo" y "¿cuántos días le quedan?"
        $cat = app(\App\Mcp\Catalogo::class);
        $prop = $cat->llamar($this->dueno, 'cargar_ausencia', ['empleado' => 'Juan', 'tipo' => 'vacaciones', 'desde' => '2026-03-02', 'hasta' => '2026-03-08'], 'asistente');
        $this->assertStringContainsString('Vacaciones de Juan Pérez', $prop['titulo']); $this->assertStringContainsString('7 días', $prop['detalle']);
        $cat->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $prop['propuesta_id']], 'asistente');
        $this->assertSame(2025, Ausencia::latest('id')->first()->anio_vacaciones, 'Las de marzo son del período anterior (LCT art. 154: se gozan hasta el 30/4)');
        $r = $cat->llamar($this->dueno->fresh(), 'vacaciones_empleado', ['empleado' => 'Juan', 'anio' => 2025], 'asistente');
        $this->assertEquals(0, $r['saldo']); $this->assertSame(21, $r['derecho']); $this->assertEquals(21, $r['tomadas']);
        Carbon::setTestNow('2026-03-04 10:00');
        $r = $cat->llamar($this->dueno->fresh(), 'ausencias', [], 'asistente');
        $this->assertCount(1, $r['hoy']); $this->assertSame('Juan Pérez', $r['hoy'][0]['empleado']);
        Carbon::setTestNow();
        // Pendiente → aprobada
        $this->post('/sueldos/personal/ausencias', ['empleado_id' => $e->id, 'tipo' => 'examen', 'desde' => '2026-04-06', 'hasta' => '2026-04-07', 'estado' => 'pendiente']);
        $pend = Ausencia::latest('id')->first(); $this->assertSame('pendiente', $pend->estado);
        $this->post("/sueldos/personal/ausencias/{$pend->id}/decidir", ['estado' => 'aprobada'])->assertSessionHas('success');
        $this->assertSame($this->dueno->id, $pend->fresh()->aprobada_por);
    }

    public function test_libro_de_sueldos_digital_y_ganancias_cuarta_categoria(): void
    {
        $e = $this->empleado(['lsd' => ['situacion' => '01', 'modalidad_contratacion' => '008'], 'localidad' => 'Mendoza']);
        $this->empresa->update(['cuit' => '30-71234567-8']);
        // Ganancias con una tabla simple: sin deducciones y un solo tramo al 5 %: la retención es el 5 % de (bruto − aportes), acumulada.
        $this->post('/sueldos/config', ['dia_pago' => 4, 'ganancias' => ['activo' => true, 'mni' => 0, 'deduccion_especial' => 0, 'escala' => [['desde' => 0, 'hasta' => null, 'fijo' => 0, 'pct' => 5]]]])->assertSessionHas('success');
        $this->post('/sueldos/liquidar', ['periodo' => '2026-01', 'tipo' => 'mensual', 'novedades' => [$e->id => ['dias' => 30]]])->assertRedirect();
        $l1 = Liquidacion::where('periodo', '2026-01')->first(); $i1 = $l1->items()->first();
        $aportes = collect($i1->detalle)->where('tipo', 'deduccion')->whereNotIn('codigo', ['GAN', 'ANTI'])->sum('monto');
        $gan1 = collect($i1->detalle)->firstWhere('codigo', 'GAN');
        $this->assertNotNull($gan1); $this->assertEqualsWithDelta(((float) $i1->bruto - $aportes) * 0.05, $gan1['monto'], 0.02); $this->assertSame(1, $gan1['calculo']['mes']);
        $this->post("/sueldos/{$l1->id}/confirmar");
        $this->post('/sueldos/liquidar', ['periodo' => '2026-02', 'tipo' => 'mensual', 'novedades' => [$e->id => ['dias' => 30]]]);
        $l2 = Liquidacion::where('periodo', '2026-02')->first(); $gan2 = collect($l2->items()->first()->detalle)->firstWhere('codigo', 'GAN');
        $this->assertEqualsWithDelta($gan1['monto'], $gan2['monto'], 0.02, 'Mismo sueldo: la retención acumulada menos lo retenido da lo mismo');
        $this->assertEqualsWithDelta($gan1['monto'], $gan2['calculo']['retenido_antes'], 0.02);
        // Si el contador sube el mínimo no imponible por encima del sueldo, febrero devuelve lo retenido en enero.
        $this->post('/sueldos/config', ['dia_pago' => 4, 'ganancias' => ['activo' => true, 'mni' => 99000000, 'deduccion_especial' => 0, 'escala' => [['desde' => 0, 'hasta' => null, 'fijo' => 0, 'pct' => 5]]]]);
        $this->post('/sueldos/liquidar', ['periodo' => '2026-02', 'tipo' => 'mensual', 'novedades' => [$e->id => ['dias' => 30]]]);
        $dev = collect(Liquidacion::where('periodo', '2026-02')->first()->items()->first()->detalle)->firstWhere('codigo', 'GAN');
        $this->assertEqualsWithDelta(-$gan1['monto'], $dev['monto'], 0.02); $this->assertStringContainsString('Devolución', $dev['nombre']);
        // Sin tabla cargada, no aparece el concepto.
        $this->post('/sueldos/config', ['dia_pago' => 4, 'ganancias' => ['activo' => false]]);
        $this->post('/sueldos/liquidar', ['periodo' => '2026-02', 'tipo' => 'mensual', 'novedades' => [$e->id => ['dias' => 30]]]);
        $this->assertNull(collect(Liquidacion::where('periodo', '2026-02')->first()->items()->first()->detalle)->firstWhere('codigo', 'GAN'));

        // Libro de Sueldos Digital: registros 01 a 04, ancho fijo, CUIL y conceptos de la empresa.
        $r = $this->get("/sueldos/{$l1->id}/lsd")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="LSD_30712345678_202601_MENSUAL.txt"');
        $lineas = array_values(array_filter(explode("\r\n", $r->getContent())));
        $this->assertSame('0130712345678SJ202601M', substr($lineas[0], 0, 22)); $this->assertStringEndsWith('30000001', $lineas[0]);
        $this->assertSame('0220257818163', substr($lineas[1], 0, 13));
        $conceptos = array_values(array_filter($lineas, fn($l) => str_starts_with($l, '03')));
        $this->assertNotEmpty($conceptos); $this->assertSame(strlen($conceptos[0]), 2 + 11 + 10 + 5 + 1 + 15 + 1 + 6);
        $jub = collect($conceptos)->first(fn($l) => str_starts_with(substr($l, 13, 10), 'JUB')); $this->assertSame('D', substr($jub, 44, 1), 'Los aportes van como débito');
        $this->assertEquals(collect($i1->detalle)->firstWhere('codigo', 'JUB')['monto'] * 100, (int) substr($jub, 29, 15));
        $this->assertStringNotContainsString('CJU', implode("\n", $conceptos), 'Las contribuciones patronales no van al libro');
        $r04 = collect($lineas)->first(fn($l) => str_starts_with($l, '04'));
        $this->assertSame('01', substr($r04, 13, 2)); $this->assertSame('008', substr($r04, 22, 3)); $this->assertSame('MENDOZA', trim(substr($r04, 27, 50)));
        $res = app(\App\Mcp\Catalogo::class)->llamar($this->dueno, 'libro_sueldos_digital', ['periodo' => '2026-01'], 'asistente');
        $this->assertSame(1, $res['empleados']); $this->assertStringContainsString("/sueldos/{$l1->id}/lsd", $res['descarga']); $this->assertSame([], $res['sin_cuil']);
    }
}
