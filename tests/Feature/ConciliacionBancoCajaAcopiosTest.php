<?php

namespace Tests\Feature;

use App\Models\Acopio;
use App\Models\AcopioItem;
use App\Models\ExtractoItem;
use App\Models\MovimientoFondos;
use App\Services\Comprobantes\CobroService;
use App\Services\Contabilidad\BancoCajonesService;
use App\Services\Fondos\FondosService;
use Illuminate\Http\UploadedFile;
use Tests\ErpTestCase;

// Fase 35.3, 35.5 y 35.6: bancos con los tres cajones y la cadena de saldos, el arqueo desde la planilla y los acopios.
class ConciliacionBancoCajaAcopiosTest extends ErpTestCase
{
    public function test_banco_tres_cajones_conceptos_aprendidos_puente_y_registrar_un_grupo(): void
    {
        $f = app(FondosService::class);
        $f->registrar($this->banco, ['fecha' => '2026-10-01', 'origen' => 'ingreso', 'concepto' => 'Saldo inicial', 'ingreso' => 100000]);
        $f->registrar($this->banco, ['fecha' => '2026-10-02', 'origen' => 'gasto', 'concepto' => 'Pago alquiler', 'egreso' => 30000, 'referencia' => '555']);
        $f->registrar($this->banco, ['fecha' => '2026-10-03', 'origen' => 'gasto', 'concepto' => 'Cheque 991 a proveedor', 'egreso' => 12000]); // no lo cobró nadie todavía
        $csv = "Fecha;Concepto;Importe;Saldo\n01/10/2026;Deposito inicial;100000,00;100000,00\n02/10/2026;Transferencia 555;-30000,00;70000,00\n03/10/2026;IMP LEY 25413 DEB;-180,00;69820,00\n03/10/2026;COMISION MANT CTA;-2500,00;67320,00\n03/10/2026;IVA RG COM;-525,00;66795,00\n04/10/2026;FISERV LIQ 77;15000,00;81795,00\n05/10/2026;PERCEP SIRCREB;-300,00;81495,00\n06/10/2026;ZZ MOVIMIENTO RARO;-40,00;81455,00\n";
        $this->post('/contable/conciliacion/importar', ['cuenta_fondos_id' => $this->banco->id, 'archivo' => UploadedFile::fake()->createWithContent('galicia.csv', $csv)])->assertSessionHasNoErrors();
        $svc = app(BancoCajonesService::class);
        $c = $svc->cajones($this->banco->fresh());
        $this->assertSame(2, $c['en_los_dos']['cantidad']);
        $this->assertSame(1, $c['solo_sistema']['cantidad']); $this->assertEquals(-12000, $c['solo_sistema']['monto']);
        $g = collect($c['solo_banco']['grupos'])->keyBy('clase');
        $this->assertSame(2, $g['impuesto']['cantidad']); $this->assertEquals(-480, $g['impuesto']['monto']);
        $this->assertSame(1, $g['comision']['cantidad']); $this->assertSame(1, $g['iva']['cantidad']);
        $this->assertSame(1, $g['tarjeta']['cantidad'], 'la acreditación de tarjeta queda aparte'); $this->assertFalse($g['tarjeta']['es_gasto']);
        $this->assertSame(1, $g['otro']['cantidad']);
        $this->assertEquals(-3505, $c['solo_banco']['para_cargar']);
        // Puente: libro 58.000 + falta registrar 11.455 − en la calle (−12.000) = 81.455 = extracto
        $this->assertEquals(0, $c['puente']['diferencia']); $this->assertStringContainsString('✓', $c['puente']['texto']);
        $this->assertTrue($c['cadena']['cierra']);
        // Clasificar el raro una vez: queda aprendido
        $raro = ExtractoItem::where('descripcion', 'like', 'ZZ%')->first();
        $this->post("/contable/conciliacion/{$raro->id}/clasificar", ['clase' => 'comision'])->assertSessionHas('success');
        $this->assertSame('comision', $svc->clasificar('ZZ MOVIMIENTO RARO 123')['clase']);
        $this->assertTrue($svc->clasificar('ZZ movimiento raro')['aprendido']);
        // Registrar todos los impuestos de una vez: quedan conciliados
        $this->post('/contable/conciliacion/registrar-grupo', ['cuenta' => $this->banco->id, 'clase' => 'impuesto'])->assertSessionHas('success');
        $this->assertSame(0, ExtractoItem::where('estado', 'pendiente')->where('descripcion', 'like', '%25413%')->count());
        $this->assertEquals(58000 - 480, (float) $this->banco->fresh()->saldo);
        $this->post('/contable/conciliacion/registrar-grupo', ['cuenta' => $this->banco->id, 'clase' => 'tarjeta'])->assertStatus(422);
        $this->get('/contable/conciliacion?cuenta=' . $this->banco->id)->assertOk()->assertInertia(fn($p) => $p->component('Contable/Conciliacion', false)->has('cajones.solo_banco.grupos'));
        $this->assertAsientosBalancean();
    }

    public function test_la_cadena_de_saldos_frena_un_pdf_mal_leido(): void
    {
        $svc = app(BancoCajonesService::class);
        $ok = $svc->cadena([['fecha' => '2026-10-01', 'descripcion' => 'a', 'monto' => 100, 'saldo' => 1100], ['fecha' => '2026-10-02', 'descripcion' => 'b', 'monto' => -50, 'saldo' => 1050]]);
        $this->assertTrue($ok['cierra']);
        $mal = $svc->cadena([['fecha' => '2026-10-01', 'descripcion' => 'a', 'monto' => 100, 'saldo' => 1100], ['fecha' => '2026-10-02', 'descripcion' => 'b', 'monto' => -50, 'saldo' => 1080]]);
        $this->assertFalse($mal['cierra']); $this->assertEquals(1050, $mal['rotos'][0]['esperado']);
        $alReves = $svc->cadena([['fecha' => '2026-10-02', 'descripcion' => 'b', 'monto' => -50, 'saldo' => 1050], ['fecha' => '2026-10-01', 'descripcion' => 'a', 'monto' => 100, 'saldo' => 1100]]);
        $this->assertTrue($alReves['cierra'], 'lo más nuevo arriba también cierra');
        $this->assertFalse($svc->cadena([['fecha' => '2026-10-01', 'descripcion' => 'a', 'monto' => 1, 'saldo' => null]])['controlable']);
    }

    public function test_arqueo_desde_la_planilla_dia_por_dia_recibos_y_cajera(): void
    {
        $cli = $this->cliente();
        $fondos = app(FondosService::class);
        $turno = $fondos->abrirTurno($this->caja, 0);
        $r1 = app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 5000, 'cuenta_fondos_id' => $this->caja->id]]]);
        $r2 = app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 2000, 'cuenta_fondos_id' => $this->caja->id]]]);
        $fondos->registrar($this->caja->fresh(), ['fecha' => today()->toDateString(), 'origen' => 'gasto', 'concepto' => 'Librería', 'egreso' => 800]);
        $fondos->cerrarTurno($turno->fresh(), 6200);
        $hoy = today()->format('d/m/Y');
        $csv = "Planilla de caja\nFecha;Concepto;Recibo;Ingreso;Egreso;Cajera\n{$hoy};Cobro;{$r1->numero};5000;;{$this->dueno->name}\n{$hoy};Cobro;99999;2500;;{$this->dueno->name}\n{$hoy};Librería;;;800;{$this->dueno->name}\n{$hoy};Arqueo de cierre;;6200;;{$this->dueno->name}\n";
        $r = $this->post('/fondos/cierres/planilla', ['cuenta_fondos_id' => $this->caja->id, 'archivo' => UploadedFile::fake()->createWithContent('caja.csv', $csv)])->assertOk()->json();
        $this->assertSame(4, $r['renglones']);
        $d = $r['dias'][0];
        $this->assertEquals(7500, $d['planilla_ingresos']); $this->assertEquals(7000, $d['sistema_ingresos']); $this->assertEquals(500, $d['dif_ingresos']);
        $this->assertEquals(0, $d['dif_egresos']); $this->assertFalse($d['cierra']);
        $this->assertEquals(6200, $d['arqueos'][0]['planilla']); $this->assertEquals(0, $d['arqueos'][0]['diferencia']);
        $this->assertSame([99999], $r['recibos_solo_planilla']);
        $this->assertCount(1, $r['recibos_solo_sistema']); $this->assertSame($r2->numeroFormateado(), $r['recibos_solo_sistema'][0]['numero']);
        $this->post('/fondos/cierres/planilla', ['cuenta_fondos_id' => $this->caja->id, 'archivo' => UploadedFile::fake()->createWithContent('x.csv', "a;b\n1;2\n")])->assertSessionHasErrors('archivo');
    }

    public function test_acopios_separa_lo_que_tiene_para_retirar_de_lo_que_debe_con_kilos_stock_y_avisos(): void
    {
        $cli = $this->cliente(['name' => 'Constructora Sur']);
        $hierro = $this->articulo(['name' => 'Hierro 8', 'unit' => 'kg', 'price' => 1000, 'stock_inicial' => 300]);
        $cemento = $this->articulo(['name' => 'Cemento', 'unit' => 'bolsa', 'price' => 8000, 'stock_inicial' => 5]);
        $fac = $this->factura($cli, [['product_id' => $hierro->id, 'descripcion' => 'Hierro 8', 'cantidad' => 500, 'precio_unit' => 1000, 'alicuota_iva' => 21], ['product_id' => $cemento->id, 'descripcion' => 'Cemento', 'cantidad' => 10, 'precio_unit' => 8000, 'alicuota_iva' => 21]], ['es_acopio' => true]);
        $acopio = Acopio::where('comprobante_id', $fac->id)->firstOrFail();
        app(\App\Services\Comprobantes\AcopioService::class)->retirar($acopio, ['fecha' => today()->toDateString(), 'retirado_por' => 'Juan', 'items' => [['acopio_item_id' => $acopio->items->firstWhere('product_id', $hierro->id)->id, 'cantidad' => 200]]]);
        // Otra factura común que sí debe
        $this->factura($cli, [['descripcion' => 'Flete', 'cantidad' => 1, 'precio_unit' => 10000, 'alicuota_iva' => 21]]);
        // Un acopio viejo de otro cliente, en negativo, sin retirar hace 7 meses
        $viejo = $this->cliente(['name' => 'Cliente Viejo', 'cuit' => null]);
        $facV = $this->factura($viejo, [['descripcion' => 'Arena', 'cantidad' => 2, 'precio_unit' => 100, 'alicuota_iva' => 21]]); $facV->forceFill(['saldo' => 0])->save();
        $av = Acopio::create(['business_id' => $this->empresa->id, 'contact_id' => $viejo->id, 'comprobante_id' => $facV->id, 'fecha' => today()->subMonths(7), 'estado' => 'abierto']);
        AcopioItem::create(['acopio_id' => $av->id, 'descripcion' => 'Arena', 'cantidad_facturada' => 2, 'cantidad_retirada' => 3, 'precio_congelado' => 100]);
        // Plata a favor que no es acopio
        $favor = $this->cliente(['name' => 'Pagó de más', 'cuit' => null]);
        app(CobroService::class)->registrar($favor, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 3000]]]);

        $r = app(\App\Services\Comprobantes\AcopiosInformeService::class)->informe();
        $c = collect($r['clientes'])->firstWhere('cliente', 'Constructora Sur');
        $this->assertEquals(580000, $c['acopiado']); $this->assertEquals(200000, $c['retirado']); $this->assertEquals(380000, $c['pendiente']);
        $this->assertEquals(12100, $c['debe'], 'lo que debe de verdad no incluye el acopio');
        $items = collect($c['acopios'][0]['items'])->keyBy('articulo');
        $this->assertEquals(300, $items['Hierro 8']['pendiente']); $this->assertSame('kg', $items['Hierro 8']['unidad']);
        $art = collect($r['articulos'])->keyBy('articulo');
        $this->assertEquals(300, $art['Hierro 8']['comprometido']); $this->assertEquals(100, $art['Hierro 8']['stock']); $this->assertTrue($art['Hierro 8']['falta']);
        $this->assertEquals(10, $art['Cemento']['comprometido']); $this->assertEquals(-5, $art['Cemento']['disponible']);
        $textos = collect($r['avisos'])->pluck('texto')->implode(' | ');
        $this->assertStringContainsString("Acopio #{$av->id} en negativo", $textos);
        $this->assertStringContainsString('7 meses sin retirar', $textos);
        $this->assertStringContainsString('a favor y no es acopio', $textos);
        $this->assertSame('rojo', $r['avisos'][0]['nivel']);
        $this->get('/clientes/acopios')->assertOk()->assertInertia(fn($p) => $p->component('Clientes/Acopios', false)->has('clientes')->has('articulos')->has('avisos'));
    }
}
