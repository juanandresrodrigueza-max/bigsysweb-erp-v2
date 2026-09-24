<?php

namespace Tests\Feature;

use App\Models\Vendedor;
use App\Services\Comprobantes\CobroService;
use App\Services\Comprobantes\ComisionesService;
use App\Services\Fiscal\ExportacionesService;
use App\Services\Stock\InformesStockService;
use App\Support\JurisdiccionesIibb;
use Tests\ErpTestCase;

// Fase 25.7: SIFERE (IIBB sufrido), mínimo de stock estadístico y cobrador con comisión por cobranza.
class Fase257Test extends ErpTestCase
{
    public function test_sifere_retenciones_y_percepciones_sufridas(): void
    {
        $this->assertSame('904', JurisdiccionesIibb::codigo('CBA'));
        $this->assertSame('901', JurisdiccionesIibb::codigo('caba'));
        $this->assertSame('921', JurisdiccionesIibb::codigo('Santa Fe'));
        $this->assertNull(JurisdiccionesIibb::codigo('Narnia'));

        // Un cliente nos retuvo IIBB Córdoba al pagar.
        $cli = $this->cliente(['name' => 'Gran Cliente SA', 'cuit' => '30-71234567-8']);
        $p = $this->articulo(['stock_inicial' => 10]);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 10000]], ['tipo' => 'FA']);
        app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [
            ['medio' => 'transferencia', 'monto' => 11800], ['medio' => 'retencion', 'monto' => 300, 'datos' => ['impuesto' => 'iibb', 'jurisdiccion' => 'CBA', 'certificado' => '0001-00004567']],
        ], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 12100]]]);
        // Una retención de Ganancias no va a SIFERE.
        app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'retencion', 'monto' => 50, 'datos' => ['impuesto' => 'ganancias']]]]);

        // Un proveedor nos percibió IIBB ARBA en su factura A 0003-00001234.
        $prov = $this->proveedor(['name' => 'Loma Negra', 'cuit' => '30-50000000-1']);
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'fecha' => today()->toDateString(), 'numero_proveedor' => '0003-00001234', 'registrar' => true, 'condicion' => 'cta_cte',
            'items' => [['product_id' => $p->id, 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 10000, 'alicuota_iva' => 21]], 'impuestos' => [['tipo' => 'iibb_arba', 'monto' => 250]]])->assertSessionHasNoErrors();

        $exp = app(ExportacionesService::class);
        $d = today()->startOfMonth()->toDateString(); $h = today()->endOfMonth()->toDateString();
        $ret = $exp->sifereRetenciones($d, $h);
        $lineas = array_values(array_filter(explode("\r\n", $ret)));
        $this->assertCount(1, $lineas);
        $this->assertSame(79, strlen($lineas[0]), 'Registro de retenciones: 79 posiciones');
        $this->assertSame('904' . '30-71234567-8' . today()->format('d/m/Y') . '0000' . '0000000100004567' . 'R ' . str_repeat('0', 20) . '00000300,00', $lineas[0]);

        $perc = array_values(array_filter(explode("\r\n", $exp->siferePercepciones($d, $h))));
        $this->assertCount(1, $perc);
        $this->assertSame(51, strlen($perc[0]), 'Registro de percepciones: 51 posiciones');
        $this->assertSame('902' . '30-50000000-1' . today()->format('d/m/Y') . '0003' . '00001234' . 'FA' . '00000250,00', $perc[0]);

        $this->get("/contable/fiscal/exportar?tipo=sifere_percepciones&desde={$d}&hasta={$h}")->assertOk()->assertHeader('Content-Disposition', "attachment; filename=SIFERE_PERCEPCIONES_{$d}_{$h}.txt");
        $this->get("/contable/fiscal?desde={$d}&hasta={$h}")->assertInertia(fn($a) => $a->has('sufridas.retenciones', 1)->has('sufridas.percepciones', 1)->where('sufridas.retenciones.0.jurisdiccion', '904'));
    }

    public function test_minimo_estadistico_cubre_la_variacion_de_la_demanda(): void
    {
        $cli = $this->cliente();
        $parejo = $this->articulo(['name' => 'Parejo', 'stock_inicial' => 5000]);
        $irregular = $this->articulo(['name' => 'Irregular', 'stock_inicial' => 5000]);
        $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
        // Los dos venden 90 unidades en 90 días: uno 1 por día, el otro 45 en dos días.
        for ($i = 1; $i <= 90; $i++) {
            $c = $svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'FX', 'fecha' => today()->subDays($i)->toDateString(), 'items' => array_values(array_filter([
                ['product_id' => $parejo->id, 'cantidad' => 1, 'precio_unit' => 10],
                in_array($i, [10, 50], true) ? ['product_id' => $irregular->id, 'cantidad' => 45, 'precio_unit' => 10] : null,
            ]))]);
            $svc->emitir($c);
        }
        $inf = app(InformesStockService::class);
        $simple = collect($inf->sugerirMinimos(15, 90, 'simple')['filas'])->keyBy('nombre');
        $this->assertEquals($simple['Parejo']['sugerido'], $simple['Irregular']['sugerido'], 'El simple solo mira el promedio');
        $est = collect($inf->sugerirMinimos(15, 90, 'estadistico')['filas'])->keyBy('nombre');
        $this->assertEquals(15, $est['Parejo']['sugerido'], 'Venta pareja: 1 × 15 + 1,65 × 0');
        $this->assertEquals(58, $est['Irregular']['sugerido'], 'Venta irregular (dos picos de 45): 15 + 1,65 × 6,67 × √15 = 58, contra 18 del simple');
        $this->assertEquals(18, $simple['Irregular']['sugerido']);
        $this->assertGreaterThan(0, $est['Irregular']['desvio']);
        $this->get('/stock/informes?tipo=minimos&metodo=estadistico')->assertInertia(fn($a) => $a->where('datos.metodo', 'estadistico')->where('filtros.metodo', 'estadistico'));
    }

    public function test_la_comision_por_cobranza_es_del_cobrador(): void
    {
        $vend = Vendedor::create(['business_id' => $this->empresa->id, 'nombre' => 'Vito Vendedor', 'comision_venta' => 3, 'comision_cobro' => 1, 'activo' => true]);
        $cob = Vendedor::create(['business_id' => $this->empresa->id, 'nombre' => 'Carlos Cobrador', 'comision_venta' => 0, 'comision_cobro' => 2, 'activo' => true]);
        $cli = $this->cliente(['vendedor_id' => $vend->id]);
        $this->post("/clientes/{$cli->id}", ['name' => $cli->name, 'condicion_iva' => 'Responsable Inscripto', 'cuit' => '20-12345678-6', 'vendedor_id' => $vend->id, 'cobrador_id' => $cob->id])->assertSessionHasNoErrors();
        $p = $this->articulo(['stock_inicial' => 10]);
        $f = $this->factura($cli->fresh(), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 10000]], ['tipo' => 'FA']);
        // Recibo sin elegir cobrador: toma el asignado al cliente.
        $this->post("/clientes/{$cli->id}/cobros", ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 6000]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 6000]]])->assertSessionHasNoErrors();
        // Otro recibo en que cobró el vendedor en persona.
        $this->post("/clientes/{$cli->id}/cobros", ['fecha' => today()->toDateString(), 'cobrador_id' => $vend->id, 'medios' => [['medio' => 'efectivo', 'monto' => 6100]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 6100]]])->assertSessionHasNoErrors();

        $liq = collect(app(ComisionesService::class)->liquidar(today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()))->keyBy('nombre');
        $this->assertEquals(300, $liq['Vito Vendedor']['com_venta']);
        $this->assertEquals(6100, $liq['Vito Vendedor']['cobrado']);
        $this->assertEquals(61, $liq['Vito Vendedor']['com_cobro']);
        $this->assertEquals(6000, $liq['Carlos Cobrador']['cobrado']);
        $this->assertEquals(120, $liq['Carlos Cobrador']['com_cobro']);
        $this->assertSame('cobrador', $liq['Carlos Cobrador']['detalle_cobros'][0]['como']);
    }
}
