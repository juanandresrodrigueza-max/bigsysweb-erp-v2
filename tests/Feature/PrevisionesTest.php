<?php

namespace Tests\Feature;

use App\Models\Abono;
use App\Models\Alerta;
use App\Models\MovimientoFondos;
use App\Models\Prevision;
use App\Services\Fondos\PrevisionesService;
use Tests\ErpTestCase;

class PrevisionesTest extends ErpTestCase
{
    public function test_prevision_mensual_y_cada_tres_meses_calculan_vencimientos_y_entran_en_el_cash_flow(): void
    {
        $this->post('/fondos/previsiones', ['tipo' => 'egreso', 'descripcion' => 'Alquiler', 'monto' => 300000, 'cada_meses' => 1, 'dia' => 10, 'desde' => today()->startOfMonth()->toDateString(), 'avisar_dias' => 5])->assertSessionHasNoErrors();
        $this->post('/fondos/previsiones', ['tipo' => 'egreso', 'descripcion' => 'Seguro', 'monto' => 90000, 'cada_meses' => 3, 'dia' => 5, 'desde' => today()->startOfMonth()->toDateString()])->assertSessionHasNoErrors();
        $this->post('/fondos/previsiones', ['tipo' => 'ingreso', 'descripcion' => 'Alquiler que cobramos', 'monto' => 120000, 'cada_meses' => 1, 'dia' => 1, 'desde' => today()->startOfMonth()->toDateString()])->assertSessionHasNoErrors();
        $this->post('/fondos/previsiones', ['tipo' => 'egreso', 'descripcion' => 'Sin monto', 'monto' => 0, 'cada_meses' => 1, 'dia' => 10, 'desde' => today()->toDateString()])->assertSessionHasErrors('monto');

        $alq = Prevision::where('descripcion', 'Alquiler')->first();
        $seg = Prevision::where('descripcion', 'Seguro')->first();
        $this->assertNotNull($alq->proximo);
        $this->assertGreaterThanOrEqual(today(), $alq->proximo, 'El próximo vencimiento nunca queda en el pasado');
        $this->assertSame(10, $alq->proximo->day);
        $venc = $seg->vencimientosEntre(today()->startOfMonth(), today()->startOfMonth()->addMonths(12)->subDay());
        $this->assertCount(4, $venc, 'Cada 3 meses en 12 meses: 4 vencimientos');
        $this->assertSame(3, (int) $venc[0]->diffInMonths($venc[1]));

        $p = app(\App\Services\Fondos\CashFlowService::class)->proyectar($this->empresa, 13);
        $this->assertGreaterThanOrEqual(3 * 300000, $p['filas']['previsiones_out']['total'], 'El alquiler aparece todas las semanas que vence en 13 semanas');
        $this->assertGreaterThanOrEqual(3 * 120000, $p['filas']['previsiones_in']['total']);
        $this->get('/fondos/previsiones')->assertOk()->assertInertia(fn($a) => $a->component('Fondos/Previsiones', false)->has('items', 3)->where('kpis.egresos_mes', 330000)->where('kpis.ingresos_mes', 120000)->has('calendario', 12));
    }

    public function test_registrar_pasa_a_fondos_y_corre_al_siguiente_y_el_automatico_lo_hace_solo(): void
    {
        $svc = app(PrevisionesService::class);
        $p = $svc->guardar(['tipo' => 'egreso', 'descripcion' => 'Monotributo', 'monto' => 50000, 'cada_meses' => 1, 'dia' => today()->day, 'desde' => today()->startOfMonth()->toDateString(), 'cuenta_fondos_id' => $this->banco->id, 'avisar_dias' => 3]);
        $this->assertTrue($p->proximo->isSameDay(today()));
        $this->post("/fondos/previsiones/{$p->id}/registrar")->assertSessionHasNoErrors();
        $m = MovimientoFondos::where('prevision_id', $p->id)->first();
        $this->assertNotNull($m);
        $this->assertEqualsWithDelta(50000, (float) $m->egreso, 0.01);
        $this->assertEqualsWithDelta(-50000, (float) $this->banco->fresh()->saldo, 0.01, 'Sale del banco elegido');
        $this->assertTrue($p->fresh()->proximo->isSameDay(today()->addMonthNoOverflow()), 'Corre un mes');
        $this->assertAsientosBalancean();

        // Automática vencida: el proceso diario la registra sola; la que vence pronto solo avisa.
        $auto = $svc->guardar(['tipo' => 'egreso', 'descripcion' => 'Internet', 'monto' => 20000, 'cada_meses' => 1, 'dia' => today()->day, 'desde' => today()->startOfMonth()->toDateString(), 'registrar_auto' => true, 'avisar_dias' => 3]);
        $pronto = $svc->guardar(['tipo' => 'egreso', 'descripcion' => 'Seguro', 'monto' => 30000, 'cada_meses' => 1, 'dia' => today()->addDays(2)->day, 'desde' => today()->startOfMonth()->toDateString(), 'avisar_dias' => 3]);
        [$reg, $avis] = $svc->procesar();
        $this->assertSame(1, $reg);
        $this->assertGreaterThanOrEqual(1, $avis);
        $this->assertSame(1, MovimientoFondos::where('prevision_id', $auto->id)->count());
        $this->assertTrue(Alerta::where('tipo', 'prevision')->where('modelo_id', $pronto->id)->whereNull('resuelta_en')->exists(), 'Avisa la que vence en 2 días');
        $this->assertFalse(Alerta::where('tipo', 'prevision')->where('modelo_id', $auto->id)->whereNull('resuelta_en')->exists(), 'La registrada no queda avisando');
        // Procesar dos veces no duplica.
        $svc->procesar();
        $this->assertSame(1, MovimientoFondos::where('prevision_id', $auto->id)->count());
        $this->assertSame(1, Alerta::where('tipo', 'prevision')->where('modelo_id', $pronto->id)->count());
        $this->artisan('previsiones:procesar')->assertSuccessful();
    }

    public function test_recuerda_facturar_los_abonos_que_vencen_y_los_que_quedaron_en_borrador(): void
    {
        $p = $this->articulo(['stock_inicial' => 0, 'tipo' => 'servicio']);
        $cli = $this->cliente();
        $a = Abono::create(['business_id' => $this->empresa->id, 'contact_id' => $cli->id, 'descripcion' => 'Abono mantenimiento', 'items' => [['product_id' => $p->id, 'descripcion' => 'Abono {periodo}', 'cantidad' => 1, 'precio_unit' => 10000, 'alicuota_iva' => 21]], 'condicion' => 'cta_cte', 'frecuencia' => 'mensual', 'dia_emision' => today()->day, 'desde' => today()->startOfMonth()->toDateString(), 'emitir_auto' => false, 'activo' => true, 'cuota_actual' => 0, 'proximo' => today()->addDays(2)->toDateString()]);
        app(PrevisionesService::class)->recordarAbonos();
        $al = Alerta::where('tipo', 'abono_por_facturar')->where('modelo_id', $a->id)->whereNull('resuelta_en')->first();
        $this->assertNotNull($al, 'Avisa 3 días antes que hay que facturar el abono');
        $this->assertStringContainsString('Facturar: Abono mantenimiento', $al->titulo);
        $this->assertStringContainsString('borrador', $al->detalle);

        // Vence hoy: el proceso diario genera la factura en borrador y el recordatorio pasa a "sin emitir".
        $a->update(['proximo' => today()->toDateString()]);
        app(\App\Services\Ventas\AbonosService::class)->emitir($a->fresh());
        app(PrevisionesService::class)->recordarAbonos();
        $c = \App\Models\Comprobante::where('abono_id', $a->id)->first();
        $this->assertSame('borrador', $c->estado);
        $this->assertTrue(Alerta::where('tipo', 'abono_borrador')->where('modelo_id', $c->id)->whereNull('resuelta_en')->exists(), 'Insiste hasta que se emita');
        $this->assertFalse(Alerta::where('tipo', 'abono_por_facturar')->where('modelo_id', $a->id)->whereNull('resuelta_en')->exists(), 'El aviso de vencimiento se resolvió porque corrió al mes que viene');

        app(\App\Services\Comprobantes\ComprobanteService::class)->emitir($c);
        app(PrevisionesService::class)->recordarAbonos();
        $this->assertFalse(Alerta::where('tipo', 'abono_borrador')->where('modelo_id', $c->id)->whereNull('resuelta_en')->exists(), 'Emitida: se resuelve el recordatorio');
    }
}
