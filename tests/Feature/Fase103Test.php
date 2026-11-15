<?php

namespace Tests\Feature;

use App\Models\Asiento;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Services\Comprobantes\CobroService;
use Tests\ErpTestCase;

// Fase 103 · El comprobante interno completo: por defecto por cliente, desde el mostrador, fuera de la contabilidad y de lo del contador, en el listado y en Bigcito.
class Fase103Test extends ErpTestCase
{
    public function test_interno_por_defecto_del_cliente_desde_la_factura_y_el_mostrador(): void
    {
        $p = $this->articulo(['stock_inicial' => 20, 'price' => 1000]);
        $this->post('/clientes', ['name' => 'Obra Chica', 'condicion_iva' => 'Consumidor Final', 'comprobante_interno' => true, 'lista_precios' => 1, 'dias_pago' => 30])->assertSessionHasNoErrors();
        $cli = Contact::customers()->where('name', 'Obra Chica')->firstOrFail(); $this->assertTrue($cli->comprobante_interno);
        // La factura nueva sale interna sin tocar nada; en el listado se filtra.
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 1000]]);
        $this->assertTrue((bool) $f->sin_arca); $this->assertSame('interno', $f->afip_estado); $this->assertSame('Comprobante interno', $f->nombreTipo()); $this->assertSame(18.0, (float) $p->fresh()->stock);
        $this->assertFalse(Asiento::where('origen', 'venta')->where('origen_id', $f->id)->exists(), 'No va a los libros');
        // Si se pide fiscal explícitamente, se respeta.
        $fiscal = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]], ['sin_arca' => false]);
        $this->assertFalse((bool) $fiscal->sin_arca); $this->assertTrue(Asiento::where('origen', 'venta')->where('origen_id', $fiscal->id)->exists());
        $this->assertCount(1, $this->get('/comprobantes?fiscal=internos')->viewData('page')['props']['lista']['data']);
        $this->assertSame($fiscal->id, $this->get('/comprobantes?fiscal=fiscales&contact_id=' . $cli->id)->viewData('page')['props']['lista']['data'][0]['id']);
        $this->assertTrue(collect($this->get('/comprobantes/nuevo?contact_id=' . $cli->id)->viewData('page')['props']['clientes'])->firstWhere('id', $cli->id)['comprobante_interno']);

        // Mostrador: el tilde manda el interno aunque el cliente no lo tenga por defecto.
        $cf = $this->cliente(['name' => 'Otro', 'cuit' => null, 'condicion_iva' => 'Consumidor Final']);
        $r = $this->postJson('/vender/vender', ['modo' => 'mostrador', 'contact_id' => $cf->id, 'interno' => true, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1210]], 'medios' => [['medio' => 'efectivo', 'monto' => 1210]]])->assertOk();
        $v = Comprobante::findOrFail($r->json('comprobante_id')); $this->assertTrue((bool) $v->sin_arca); $this->assertSame(16.0, (float) $p->fresh()->stock);
        $this->assertFalse(Asiento::where('origen', 'venta')->where('origen_id', $v->id)->exists());
        // El recibo del mostrador que sólo cancela internos tampoco va a los libros; la caja sí recibió la plata.
        $cobro = Cobro::latest('id')->first(); $this->assertFalse(Asiento::where('origen', 'cobro')->where('origen_id', $cobro->id)->exists());
        $this->assertEquals(1210, (float) \App\Models\MovimientoFondos::where('origen', 'cobro')->where('origen_id', $cobro->id)->sum('ingreso'));
        $r2 = $this->postJson('/vender/vender', ['modo' => 'mostrador', 'contact_id' => $cf->id, 'interno' => false, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1210]], 'medios' => [['medio' => 'efectivo', 'monto' => 1210]]])->assertOk();
        $this->assertFalse((bool) Comprobante::findOrFail($r2->json('comprobante_id'))->sin_arca);
    }

    public function test_el_contador_no_lo_ve_y_el_dueno_si(): void
    {
        $p = $this->articulo(['stock_inicial' => 50, 'price' => 1000]); $cli = $this->cliente(['name' => 'Mixto']);
        $fis = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $mes = today()->format('Y-m'); $d = today()->startOfMonth()->toDateString(); $h = today()->endOfMonth()->toDateString();
        $informe = app(\App\Services\Contabilidad\InformeContadorService::class); $indic = app(\App\Services\Contabilidad\IndicadoresContadorService::class);
        $antes = $informe->datos($this->empresa, $mes); $saludAntes = $indic->tablero($this->empresa, $mes)['salud'];
        $int = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 3, 'precio_unit' => 1000]], ['sin_arca' => true]);
        // Informe e indicadores del contador: el interno no suma ventas ni aparece como «sin asiento».
        $datos = $informe->datos($this->empresa, $mes);
        $this->assertEqualsWithDelta($antes['actual']['ventas'], $datos['actual']['ventas'], 0.01); $this->assertSame($antes['revisar'], $datos['revisar']);
        $this->assertSame($saludAntes, $indic->tablero($this->empresa, $mes)['salud']);
        $this->get("/contable/iva?libro=ventas&desde={$d}&hasta={$h}")->assertInertia(fn($pg) => $pg->has('filas', 1));
        $this->assertEquals((float) $fis->iva, app(\App\Services\Contabilidad\PosicionIvaService::class)->calcular($this->empresa, $mes)['debito']);
        // Un recibo que cancela interno + fiscal va entero a la contabilidad (no se mezcla a medias).
        $cobro = app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => (float) $int->total + (float) $fis->total, 'cuenta_fondos_id' => \App\Models\CuentaFondos::where('tipo', 'caja')->value('id')]], 'imputaciones' => [['comprobante_id' => $int->id, 'monto' => (float) $int->total], ['comprobante_id' => $fis->id, 'monto' => (float) $fis->total]]]);
        $this->assertTrue(Asiento::where('origen', 'cobro')->where('origen_id', $cobro->id)->exists());
        // Bigcito separa fiscal e interno y arma internos a pedido.
        $cat = app(\App\Mcp\Catalogo::class);
        $v = $cat->llamar($this->dueno, 'ventas_periodo', ['periodo' => 'este mes'], 'asistente');
        $this->assertEqualsWithDelta((float) $int->total, $v['internos'], 0.01); $this->assertEqualsWithDelta((float) $fis->total, $v['fiscal'], 0.01); $this->assertEqualsWithDelta($v['fiscal'] + $v['internos'], $v['total'], 0.01);
        $prop = $cat->llamar($this->dueno->fresh(), 'crear_factura', ['cliente' => 'Mixto', 'interno' => true, 'items' => [['articulo' => $p->name, 'cantidad' => 2]]], 'asistente');
        $this->assertArrayHasKey('propuesta_id', $prop, json_encode($prop));
        $r = $cat->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $prop['propuesta_id']], 'asistente');
        $this->assertTrue($r['comprobante']['interno']); $this->assertTrue((bool) Comprobante::find($r['comprobante']['id'])->sin_arca);
    }
}
