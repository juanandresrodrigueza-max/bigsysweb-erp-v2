<?php

namespace Tests\Feature;

use App\Models\Conciliacion;
use App\Models\CuentaFondos;
use App\Models\CuponTarjeta;
use App\Models\LiquidacionTarjeta;
use App\Services\Comprobantes\CobroService;
use App\Services\Fondos\ConciliacionTarjetasService;
use App\Services\Fondos\LiquidacionLectorService;
use Illuminate\Http\UploadedFile;
use Tests\ErpTestCase;

// Fase 35.1, 35.2 y 35.4: qué es cada fondo, los lectores de liquidaciones y el cruce en cuatro grupos, la previa contable, probar, asentar y deshacer.
class ConciliacionTarjetasTest extends ErpTestCase
{
    private CuentaFondos $fondoTarjeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fondoTarjeta = CuentaFondos::create(['business_id' => $this->empresa->id, 'tipo' => 'tarjeta', 'nombre' => 'Posnet Fiserv', 'moneda' => 'ARS', 'saldo' => 0, 'activa' => true]);
    }

    private function cupon(string $fecha, float $monto, string $numero, ?int $fondoId = null): CuponTarjeta
    {
        $cli = $this->cliente(['name' => 'Cliente ' . $numero, 'cuit' => null]);
        $cobro = app(CobroService::class)->registrar($cli, ['fecha' => $fecha, 'medios' => [['medio' => 'tarjeta', 'monto' => $monto, 'cuenta_fondos_id' => $fondoId ?? $this->fondoTarjeta->id, 'datos' => ['tarjeta' => 'Visa', 'numero' => $numero, 'cuotas' => 1]]]]);
        return CuponTarjeta::where('cobro_id', $cobro->id)->firstOrFail();
    }

    private function csv(string $contenido, string $nombre = 'liq.csv'): string
    {
        $p = tempnam(sys_get_temp_dir(), 'liq'); file_put_contents($p, $contenido); return $p;
    }

    public function test_el_lector_reconoce_fiserv_naranja_gocuotas_mercado_pago_y_nave_con_sus_columnas(): void
    {
        $l = app(LiquidacionLectorService::class);
        $f = $l->leer($this->csv("Fiserv - Detalle de transacciones\nFecha de venta;Nro Ticket;Importe;Cuotas;Fecha de pago;Arancel;IVA Arancel;Neto;Terminal;Nro de Liquidacion\n03/10/2026;001234;12.500,00;1;05/10/2026;225,00;47,25;12.227,75;T001;L-77\n"), 'fiserv.csv');
        $this->assertSame('fiserv', $f['origen']); $this->assertCount(1, $f['operaciones']);
        $o = $f['operaciones'][0]; $this->assertSame('2026-10-03', $o['fecha_venta']); $this->assertSame(12500.0, $o['importe']); $this->assertSame('001234', $o['cupon']); $this->assertSame('2026-10-05', $o['fecha_pago']); $this->assertSame(12227.75, $o['neto']); $this->assertSame(225.0, $o['comision']); $this->assertSame('L-77', $o['acreditacion']); $this->assertSame('T001', $o['terminal']);
        $this->assertSame([], $f['faltan']);

        $n = $l->leer($this->csv("Naranja X - Historial de ventas\nFecha,Comercio,N° Cupón,Plan,Importe,Comisión,IVA,Neto,Fecha de pago\n2026-10-04,55123,445566,3,\"30,000.00\",\"900.00\",\"189.00\",\"28,911.00\",2026-10-20\n"), 'naranja.csv');
        $this->assertSame('naranjax', $n['origen']); $this->assertSame(30000.0, $n['operaciones'][0]['importe']); $this->assertSame('445566', $n['operaciones'][0]['cupon']); $this->assertSame(3, $n['operaciones'][0]['cuotas']);

        $g = $l->leer($this->csv("Número de orden;Fecha;Monto;Comisión;IVA;Retención IVA;Retención IIBB;Retención Ganancias;Neto;Fecha de pago\n98765;05/10/2026;8000,00;240,00;50,40;0,00;120,00;0,00;7589,60;07/10/2026\nTotales;;8000,00;240,00;50,40;0,00;120,00;0,00;7589,60;\n"), 'gocuotas_oct.csv');
        $this->assertSame('gocuotas', $g['origen']); $this->assertSame(8000.0, $g['control']['total_archivo']); $this->assertTrue($g['control']['cierra']); $this->assertSame(120.0, $g['operaciones'][0]['ret_iibb']);

        $m = $l->leer($this->csv("SOURCE_ID;DATE_APPROVED;TRANSACTION_AMOUNT;MP_FEE_AMOUNT;TAXES_AMOUNT;NET_RECEIVED_AMOUNT;MONEY_RELEASE_DATE;STATUS;INSTALLMENTS\n1234567890;2026-10-06T14:22:00.000-03:00;5000.00;-150.00;-31.50;4818.50;2026-10-24T00:00:00.000-03:00;approved;1\n1234567891;2026-10-06T15:00:00.000-03:00;999.00;-30.00;-6.30;962.70;2026-10-24T00:00:00.000-03:00;refunded;1\n"), 'settlement-report.csv');
        $this->assertSame('mercadopago', $m['origen']); $this->assertCount(1, $m['operaciones'], 'la devuelta no se cruza'); $this->assertSame('2026-10-06', $m['operaciones'][0]['fecha_venta']); $this->assertSame(150.0, $m['operaciones'][0]['comision']); $this->assertSame(4818.5, $m['operaciones'][0]['neto']); $this->assertSame('2026-10-24', $m['operaciones'][0]['fecha_pago']);

        $v = $l->leer($this->csv("NAVE;;;\nTotal bruto;21.000,00;;\n;;;\nCódigo de operación;Fecha;Importe;Cuotas;Fecha de acreditación;Grupo de acreditación;Comisión;IVA;Neto\nOP-1;07/10/2026;21.000,00;1;09/10/2026;G-5;630,00;132,30;20.237,70\n"), 'nave.csv');
        $this->assertSame('nave', $v['origen']); $this->assertSame(21000.0, $v['control']['total_archivo']); $this->assertTrue($v['control']['cierra']); $this->assertSame('G-5', $v['operaciones'][0]['acreditacion']);

        // Sin neto ni comisión: se avisa qué falta
        $s = $l->leer($this->csv("Fecha;Importe;Cupon\n08/10/2026;100,00;1\n"), 'otro.csv');
        $this->assertSame('otro', $s['origen']); $this->assertContains('neto', $s['faltan']); $this->assertNull($s['operaciones'][0]['neto']);
    }

    public function test_que_es_cada_fondo_y_el_cruce_en_cuatro_grupos_con_lo_pactado(): void
    {
        $svc = app(ConciliacionTarjetasService::class);
        $this->assertSame(['fiserv'], collect($svc->fondos())->firstWhere('id', $this->fondoTarjeta->id)['procesadoras'], 'sugiere la procesadora por el nombre');
        $this->post('/fondos/conciliar/fondos', ['fondos' => [['id' => $this->fondoTarjeta->id, 'concilia' => true, 'procesadoras' => ['fiserv', 'naranjax'], 'comision_pactada' => 1.5, 'plazo_pactado' => 10, 'banco_id' => $this->banco->id], ['id' => $this->caja->id, 'concilia' => false]]])->assertSessionHas('success');
        $this->assertSame(['fiserv', 'naranjax'], $this->fondoTarjeta->fresh()->conciliacion_config['procesadoras']); $this->assertFalse($this->caja->fresh()->concilia);

        $a = $this->cupon('2026-10-03', 12500, '001234');                  // cierra por importe y fecha
        $b1 = $this->cupon('2026-10-04', 3000, '777111'); $b2 = $this->cupon('2026-10-04', 3000, '777222'); // dos iguales: decide el cupón
        $c1 = $this->cupon('2026-10-05', 4000, '555001'); $c2 = $this->cupon('2026-10-05', 4000, '555002'); // dos iguales sin cupón en el archivo: a revisar
        $d = $this->cupon('2026-10-06', 9999, '999');                      // no está en el archivo: todavía no te pagaron
        $csv = "Fiserv\nFecha de venta;Nro Ticket;Importe;Fecha de pago;Arancel;IVA Arancel;Neto;Nro de Liquidacion\n03/10/2026;1234;12.500,00;05/10/2026;225,00;47,25;12.227,75;L-1\n04/10/2026;7222;3.000,00;05/10/2026;45,00;9,45;2.945,55;L-1\n05/10/2026;;4.000,00;20/10/2026;100,00;21,00;3.879,00;L-2\n07/10/2026;4444;1.500,00;20/10/2026;22,50;4,73;1.472,77;L-2\n";
        $r = $this->post('/fondos/conciliar/subir', ['cuenta_fondos_id' => $this->fondoTarjeta->id, 'archivo' => UploadedFile::fake()->createWithContent('fiserv.csv', $csv)]);
        $r->assertSessionHasNoErrors(); $lote = Conciliacion::latest("id")->first(); $r->assertRedirect("/fondos/conciliar/{$lote->id}");
        $res = $lote->resultado;
        $this->assertSame([$a->id, $b2->id], array_column($res['listo'], 'cupon_id'));
        $this->assertSame('importe, fecha y cupón', $res['listo'][1]['como']);
        $this->assertCount(2, $res['revisar']); $this->assertSame('ambiguo', $res['revisar'][0]['tipo']); $this->assertCount(2, $res['revisar'][0]['candidatos']); $this->assertSame('sin_cupon', $res['revisar'][1]['tipo']);
        $this->assertSame([$b1->id, $c1->id, $c2->id, $d->id], array_column($res['no_pagaron'], 'id'));
        $this->assertEquals(15500, $res['resumen']['cerro']); $this->assertSame(2, $res['resumen']['revisar_n']);
        $this->assertCount(1, $res['acreditaciones']); $this->assertSame('L-1', $res['acreditaciones'][0]['clave']); $this->assertEquals(15173.3, $res['acreditaciones'][0]['neto']);
        $this->assertStringContainsString('Salen $ 15.500,00 del fondo tarjeta, entran $ 15.173,30 al banco', $res['acreditaciones'][0]['texto']);
        // Lo pactado: 1,5 % y 10 días → la de 12.500 paga 1,8 % y la de 4.000 2,5 %; dos tardan más de 10 días
        $this->assertSame(2, $res['pactado']['de_mas']); $this->assertEquals(77.5, $res['pactado']['de_mas_monto']); $this->assertSame(2, $res['pactado']['tarde']);

        // Resolver a mano la ambigua con c1 y descartar la que no es nuestra
        $this->post("/fondos/conciliar/{$lote->id}/resolver", ['fila' => 3, 'cupon_id' => $c1->id])->assertSessionHas('success');
        $this->post("/fondos/conciliar/{$lote->id}/resolver", ['fila' => 4, 'cupon_id' => null])->assertSessionHas('success');
        $res = $lote->fresh()->resultado;
        $this->assertCount(3, $res['listo']); $this->assertCount(0, $res['revisar']); $this->assertCount(1, $res['descartados']); $this->assertCount(2, $res['acreditaciones']);
        $this->assertSame([$b1->id, $c2->id, $d->id], array_column($res['no_pagaron'], 'id'));

        // Aviso al duplicar el período
        $this->post('/fondos/conciliar/subir', ['cuenta_fondos_id' => $this->fondoTarjeta->id, 'archivo' => UploadedFile::fake()->createWithContent('fiserv2.csv', $csv)]);
        $this->assertStringContainsString('pisa este período', Conciliacion::latest('id')->first()->resultado['avisos'][0]);
        // Payway todavía no tiene lector
        $this->post('/fondos/conciliar/subir', ['cuenta_fondos_id' => $this->fondoTarjeta->id, 'origen' => 'payway', 'archivo' => UploadedFile::fake()->createWithContent('p.csv', $csv)])->assertSessionHasErrors('archivo');
    }

    public function test_previa_contable_probar_asentar_en_una_operacion_y_deshacer(): void
    {
        $svc = app(ConciliacionTarjetasService::class);
        $a = $this->cupon('2026-10-03', 12500, '001234'); $b = $this->cupon('2026-10-04', 3000, '7222');
        $csv = "Fiserv\nFecha de venta;Nro Ticket;Importe;Fecha de pago;Arancel;IVA Arancel;Retencion IIBB;Neto;Nro de Liquidacion\n03/10/2026;1234;12.500,00;05/10/2026;225,00;47,25;100,00;12.127,75;L-1\n04/10/2026;7222;3.000,00;06/10/2026;45,00;9,45;0,00;2.945,55;L-2\n";
        $lote = $svc->subir($this->fondoTarjeta, $this->csv($csv), 'fiserv.csv');
        // Sin banco: la previa dice qué falta y no se habilita
        $p = $svc->previa($lote); $this->assertFalse($p['puede']); $this->assertStringContainsString('banco', $p['faltan'][0]);
        $this->post("/fondos/conciliar/{$lote->id}/asentar")->assertStatus(422);
        $this->post("/fondos/conciliar/{$lote->id}/banco", ['banco_id' => $this->banco->id]);
        $p = $svc->previa($lote->fresh()); $this->assertTrue($p['puede']); $this->assertCount(2, $p['acreditaciones']);
        $l1 = $p['acreditaciones'][0]; $this->assertSame(12500.0, $l1['debe']); $this->assertSame(12500.0, $l1['haber']);
        $this->assertSame(['Banco ' . $this->banco->nombre, 'Comisiones de tarjeta (gasto)', 'IVA crédito fiscal'], array_slice(array_column($l1['lineas'], 'cuenta'), 0, 3));
        $this->assertStringContainsString('Retenciones sufridas', $l1['lineas'][3]['cuenta']); $this->assertSame(100.0, $l1['lineas'][3]['debe']);
        // Asentar sin probar: no
        $this->post("/fondos/conciliar/{$lote->id}/asentar")->assertStatus(422);
        $saldoBanco = (float) $this->banco->fresh()->saldo;
        // Probar: no escribe nada
        $this->post("/fondos/conciliar/{$lote->id}/probar")->assertSessionHas('success');
        $this->assertSame(0, LiquidacionTarjeta::count()); $this->assertSame('cartera', $a->fresh()->estado); $this->assertNotNull($lote->fresh()->probado_en);
        // Asentar: dos liquidaciones, cupones liquidados, neto en el banco, asientos balancean
        $this->post("/fondos/conciliar/{$lote->id}/asentar")->assertSessionHas('success');
        $lote->refresh(); $this->assertSame('asentada', $lote->estado); $this->assertCount(2, $lote->asentado['liquidaciones']);
        $this->assertSame('liquidado', $a->fresh()->estado); $this->assertSame('liquidado', $b->fresh()->estado);
        $liq = LiquidacionTarjeta::find($lote->asentado['liquidaciones'][0]); $this->assertSame('L-1', $liq->numero); $this->assertEqualsWithDelta(12127.75, (float) $liq->neto, 0.01); $this->assertEqualsWithDelta(100, (float) $liq->ret_iibb, 0.01);
        $this->assertEqualsWithDelta($saldoBanco + 12127.75 + 2945.55, (float) $this->banco->fresh()->saldo, 0.01);
        $this->assertAsientosBalancean();
        // Una segunda liquidación con el mismo cupón: "ya tildado"
        $lote2 = $svc->subir($this->fondoTarjeta, $this->csv($csv), 'fiserv-otra-vez.csv');
        $this->assertSame(2, $lote2->resumen['ya_tildado_n']); $this->assertSame(0, $lote2->resumen['listo_n']);
        // Deshacer: liquidaciones anuladas, cupones a cartera, banco como antes
        $this->post("/fondos/conciliar/{$lote->id}/deshacer")->assertSessionHas('success');
        $this->assertSame('deshecha', $lote->fresh()->estado); $this->assertSame('anulada', $liq->fresh()->estado); $this->assertSame('cartera', $a->fresh()->estado);
        $this->assertEqualsWithDelta($saldoBanco, (float) $this->banco->fresh()->saldo, 0.01);
        $this->get('/fondos/conciliar')->assertOk()->assertInertia(fn($pg) => $pg->component('Fondos/Conciliar', false)->has('lotes', 2));
        $this->get("/fondos/conciliar/{$lote->id}")->assertOk();
        $this->get("/fondos/conciliar/{$lote->id}/exportar")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }
}
