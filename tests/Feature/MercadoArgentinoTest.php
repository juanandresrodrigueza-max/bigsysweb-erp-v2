<?php

namespace Tests\Feature;

use App\Models\Canal;
use App\Models\Comprobante;
use App\Models\PedidoWeb;
use App\Services\Canales\CanalesService;
use App\Services\Comprobantes\AfipEmisor;
use App\Services\Fiscal\CotService;
use App\Services\MercadoPago\MercadoPagoCobrosService;
use App\Services\Pos\CuotasService;
use App\Services\Pos\PosService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 21 · Mercado argentino: percepciones IVA/Ganancias, remito electrónico (COT), cuotas con tarjeta, Mercado Pago QR/Point y Mercado Envíos.
class MercadoArgentinoTest extends ErpTestCase
{
    public function test_percepciones_de_iva_y_ganancias_van_a_la_factura_y_a_arca_como_tributos_6_y_9(): void
    {
        $this->empresa->update(['impuestos' => ['percepcion_iva' => ['activo' => true, 'alicuota' => 3, 'minimo' => 0, 'solo_ri' => true], 'percepcion_ganancias' => ['activo' => true, 'alicuota' => 2, 'minimo' => 0]]]);
        $p = $this->articulo(['stock_inicial' => 10, 'iva' => 21]);
        $ex = $this->articulo(['stock_inicial' => 10, 'iva' => 0]);
        $cli = $this->cliente(['percepcion_iva' => true, 'percepcion_ganancias' => true]);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 100], ['product_id' => $ex->id, 'cantidad' => 1, 'precio_unit' => 500]]);

        $imp = $f->impuestos()->orderBy('tipo')->get();
        $this->assertSame(['perc_ganancias', 'perc_iva'], $imp->pluck('tipo')->all());
        // Base: solo el neto gravado (1000), no el exento (500).
        $this->assertEqualsWithDelta(30, $imp->firstWhere('tipo', 'perc_iva')->monto, 0.01);
        $this->assertEqualsWithDelta(20, $imp->firstWhere('tipo', 'perc_ganancias')->monto, 0.01);
        $this->assertEqualsWithDelta(50, $f->percepciones, 0.01);
        $this->assertEqualsWithDelta(1000 + 500 + 210 + 50, $f->total, 0.01);

        $d = app(AfipEmisor::class)->armarDatos($f->fresh(['items', 'contact', 'impuestos']), 1, 1);
        $ids = array_column($d['Tributos'], 'Id');
        sort($ids);
        $this->assertSame([6, 9], $ids, 'Percepción IVA = tributo 6, Ganancias = tributo 9');
        $this->assertEqualsWithDelta(50, $d['ImpTrib'], 0.01);
        $this->assertEqualsWithDelta($d['ImpNeto'] + $d['ImpOpEx'] + $d['ImpIVA'] + $d['ImpTrib'], $d['ImpTotal'], 0.001);
        $this->assertAsientosBalancean();
    }

    public function test_percepcion_de_iva_no_se_aplica_a_monotributistas_ni_a_clientes_sin_la_marca(): void
    {
        $this->empresa->update(['impuestos' => ['percepcion_iva' => ['activo' => true, 'alicuota' => 3, 'minimo' => 0, 'solo_ri' => true], 'percepcion_ganancias' => ['activo' => true, 'alicuota' => 2, 'minimo' => 0]]]);
        $p = $this->articulo(['stock_inicial' => 10]);
        $mono = $this->cliente(['name' => 'Mono', 'cuit' => '20-12345678-6', 'condicion_iva' => 'Monotributista', 'percepcion_iva' => true, 'percepcion_ganancias' => true]);
        $f = $this->factura($mono, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $this->assertSame(0, $f->impuestos()->count());
        $sin = $this->cliente(['name' => 'RI sin marca']);
        $f2 = $this->factura($sin, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $this->assertSame(0, $f2->impuestos()->count());
    }

    public function test_la_pantalla_fiscal_y_la_exportacion_incluyen_las_percepciones_de_iva_y_ganancias(): void
    {
        $this->empresa->update(['impuestos' => ['percepcion_iva' => ['activo' => true, 'alicuota' => 3, 'minimo' => 0, 'solo_ri' => true]]]);
        $p = $this->articulo(['stock_inicial' => 10]);
        $this->factura($this->cliente(['percepcion_iva' => true]), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $desde = today()->startOfMonth()->toDateString(); $hasta = today()->endOfMonth()->toDateString();
        $this->get("/contable/fiscal?desde={$desde}&hasta={$hasta}")->assertOk()->assertInertia(fn($pg) => $pg->component('Contable/Fiscal', false)->where('percepciones.0.jurisdiccion', 'IVA')->where('totalPercepciones', 30));
        $txt = app(\App\Services\Fiscal\ExportacionesService::class)->percepciones($desde, $hasta);
        $this->assertStringContainsString(';IVA', $txt);
        $this->assertStringContainsString('30,00', $txt);
    }

    public function test_remito_con_transporte_genera_el_archivo_cot_de_arba_y_guarda_el_codigo(): void
    {
        $p = $this->articulo(['stock_inicial' => 10, 'sku' => 'CEM50']);
        $cli = $this->cliente(['address' => 'Ruta 9 km 12', 'city' => 'Pilar', 'postal_code' => '1629']);
        $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
        $rem = $svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'REM', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'items' => [['product_id' => $p->id, 'cantidad' => 4, 'precio_unit' => 100]],
            'transportista' => 'Transportes Pérez', 'transportista_cuit' => '20-12345678-6', 'patente' => 'AB 123 CD', 'bultos' => 4, 'peso_kg' => 200, 'domicilio_entrega' => 'Obra Ruta 9 km 12']);
        $rem = $svc->emitir($rem);
        $this->assertSame('Transportes Pérez', $rem->transportista); $this->assertSame(4, (int) $rem->bultos);

        $txt = app(CotService::class)->archivo($rem->fresh(['items.product', 'contact', 'business']));
        $lineas = explode("\r\n", trim($txt));
        $this->assertStringStartsWith('01|' . preg_replace('/\D/', '', $this->empresa->cuit), $lineas[0]);
        $this->assertStringStartsWith('02|', $lineas[1]);
        $this->assertStringContainsString('|20123456786|Transportes Pérez|AB123CD|4|200.00|', $lineas[1]);
        $this->assertStringContainsString('03|CEM50|', $lineas[2]);
        $this->assertSame('04|1', $lineas[3]);
        $this->assertMatchesRegularExpression('/^TB_\d{11}_001_\d{8}_\d{6}\.txt$/', app(CotService::class)->nombreArchivo($rem));

        $this->get("/comprobantes/{$rem->id}/cot")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="' . app(CotService::class)->nombreArchivo($rem) . '"');
        $this->post("/comprobantes/{$rem->id}/cot", ['cot' => '011234567890123'])->assertSessionHas('success');
        $this->assertSame('011234567890123', $rem->fresh()->cot);
        $this->get("/comprobantes/{$rem->id}/imprimir")->assertOk()->assertSee('COT ARBA')->assertSee('011234567890123')->assertSee('Transportes Pérez');
        $this->get("/comprobantes/{$rem->id}")->assertInertia(fn($pg) => $pg->component('Comprobantes/Ver', false)->where('c.transporte.patente', 'AB 123 CD')->where('c.transporte.cot', '011234567890123'));

        // Una factura no genera COT.
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->get("/comprobantes/{$f->id}/cot")->assertStatus(422);
    }

    public function test_venta_en_cuotas_con_tarjeta_agrega_el_recargo_del_plan_como_item_y_cierra_la_factura(): void
    {
        $this->empresa->update(['tarjetas' => [['nombre' => 'Visa', 'planes' => [['cuotas' => 1, 'recargo' => 0], ['cuotas' => 3, 'recargo' => 10], ['cuotas' => 6, 'recargo' => 20]]]]]);
        $this->assertSame(10.0, app(CuotasService::class)->recargo($this->empresa, 'Visa', 3));
        $this->assertSame(0.0, app(CuotasService::class)->recargo($this->empresa, 'Visa', 12), 'Plan inexistente: sin recargo');
        $p = $this->articulo(['stock_inicial' => 10, 'price' => 1210]);
        // Precio final 1210 (con IVA). En 3 cuotas con 10%: el cliente paga 1331.
        $r = app(PosService::class)->vender(['items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1210]], 'precios_con_iva' => true, 'medios' => [['medio' => 'tarjeta', 'monto' => 1331, 'datos' => ['tarjeta' => 'Visa', 'cuotas' => 3]]]]);
        $c = $r['comprobante'];
        $this->assertEqualsWithDelta(1331, $c->total, 0.01);
        $this->assertSame(0.0, (float) $c->saldo);
        $this->assertSame(0.0, (float) $r['vuelto']);
        $recargo = $c->items->first(fn($i) => str_starts_with($i->descripcion, 'Recargo'));
        $this->assertNotNull($recargo); $this->assertStringContainsString('Visa 3 cuotas (10%)', $recargo->descripcion);
        $this->assertEqualsWithDelta(121, $recargo->total, 0.01);
        $medio = $r['cobro']->medios->first();
        $this->assertSame('tarjeta', $medio->medio); $this->assertSame(3, $medio->datos['cuotas']); $this->assertSame('Visa', $medio->datos['tarjeta']);
        $this->assertAsientosBalancean();
        // Sin cuotas: nada cambia.
        $r2 = app(PosService::class)->vender(['items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1210]], 'precios_con_iva' => true, 'medios' => [['medio' => 'tarjeta', 'monto' => 1210, 'datos' => ['tarjeta' => 'Visa', 'cuotas' => 1]]]]);
        $this->assertCount(1, $r2['comprobante']->items);
    }

    public function test_planes_de_cuotas_y_mercadopago_se_configuran_desde_empresa(): void
    {
        $this->post('/configuracion/empresa/tarjetas', ['tarjetas' => [['nombre' => 'Naranja', 'planes' => [['cuotas' => 1, 'recargo' => 0], ['cuotas' => 3, 'recargo' => 12.5]]]]])->assertSessionHas('success');
        $this->assertSame(12.5, app(CuotasService::class)->recargo($this->empresa->fresh(), 'Naranja', 3));
        $this->post('/configuracion/empresa/mercadopago', ['access_token' => 'APP_USR-secreto', 'user_id' => '123', 'pos_external_id' => 'CAJA01', 'point_device_id' => 'PAX_1'])->assertSessionHas('success');
        $b = $this->empresa->fresh();
        $this->assertSame('APP_USR-secreto', $b->mercadopago_settings['access_token']);
        $this->assertTrue(app(MercadoPagoCobrosService::class)->qrConfigurado($b)); $this->assertTrue(app(MercadoPagoCobrosService::class)->pointConfigurado($b));
        // Volver a guardar con el token enmascarado no lo pisa.
        $this->post('/configuracion/empresa/mercadopago', ['access_token' => '••••reto', 'user_id' => '123', 'pos_external_id' => 'CAJA01', 'point_device_id' => ''])->assertSessionHas('success');
        $this->assertSame('APP_USR-secreto', $this->empresa->fresh()->mercadopago_settings['access_token']);
        $this->assertFalse(app(MercadoPagoCobrosService::class)->pointConfigurado($this->empresa->fresh()));
        $this->get('/configuracion')->assertInertia(fn($pg) => $pg->component('Configuracion/Empresa', false)->where('mercadopago.tiene_token', true)->where('mercadopago.access_token', '••••reto')->where('tarjetas.0.nombre', 'Naranja'));
        $this->get('/retail')->assertInertia(fn($pg) => $pg->component('Pos/Index', false)->where('planesCuotas.0.nombre', 'Naranja')->where('mp.qr', true));
    }

    public function test_cobro_con_qr_de_mercadopago_desde_el_pos(): void
    {
        $this->empresa->update(['mercadopago_settings' => ['access_token' => 'TOKEN', 'user_id' => '123', 'pos_external_id' => 'CAJA01']]);
        Http::fake([
            'api.mercadopago.com/instore/orders/qr/seller/collectors/123/pos/CAJA01/qrs' => Http::response(['qr_data' => '00020101021243650016COM.MERCADOLIBRE', 'in_store_order_id' => 'ord-1']),
            'api.mercadopago.com/merchant_orders/search*' => Http::sequence()
                ->push(['elements' => []])
                ->push(['elements' => [['order_status' => 'paid', 'status' => 'closed', 'payments' => [['id' => 987, 'status' => 'approved', 'transaction_amount' => 1500]]]]]),
        ]);
        $r = $this->postJson('/retail/mp/iniciar', ['tipo' => 'qr', 'monto' => 1500])->assertOk()->json();
        $this->assertSame('qr', $r['tipo']); $this->assertStringStartsWith('pos-', $r['id']); $this->assertStringStartsWith('0002', $r['qr_data']);
        Http::assertSent(fn($req) => str_contains($req->url(), '/qrs') && $req['total_amount'] == 1500 && $req['external_reference'] === $r['id']);
        $this->getJson('/retail/mp/estado?tipo=qr&id=' . $r['id'])->assertOk()->assertJson(['estado' => 'pendiente']);
        $this->getJson('/retail/mp/estado?tipo=qr&id=' . $r['id'])->assertOk()->assertJson(['estado' => 'pagado', 'pago_id' => '987', 'monto' => 1500]);
    }

    public function test_cobro_con_point_de_mercadopago_manda_el_importe_en_centavos(): void
    {
        $this->empresa->update(['mercadopago_settings' => ['access_token' => 'TOKEN', 'point_device_id' => 'PAX_1']]);
        Http::fake([
            'api.mercadopago.com/point/integration-api/devices/PAX_1/payment-intents' => Http::response(['id' => 'intent-1']),
            'api.mercadopago.com/point/integration-api/payment-intents/intent-1' => Http::response(['state' => 'FINISHED', 'amount' => 250050, 'payment' => ['id' => 555]]),
            'api.mercadopago.com/point/integration-api/devices/PAX_1/payment-intents/intent-1' => Http::response([], 200),
        ]);
        $r = $this->postJson('/retail/mp/iniciar', ['tipo' => 'point', 'monto' => 2500.5])->assertOk()->json();
        $this->assertSame('intent-1', $r['id']);
        Http::assertSent(fn($req) => str_contains($req->url(), 'payment-intents') && $req['amount'] === 250050);
        $this->getJson('/retail/mp/estado?tipo=point&id=intent-1')->assertOk()->assertJson(['estado' => 'pagado', 'pago_id' => '555', 'monto' => 2500.5]);
        $this->postJson('/retail/mp/cancelar', ['tipo' => 'point', 'id' => 'intent-1'])->assertOk();
        // Sin configurar: mensaje claro.
        $this->empresa->update(['mercadopago_settings' => []]);
        $this->postJson('/retail/mp/iniciar', ['tipo' => 'qr', 'monto' => 100])->assertStatus(422);
    }

    public function test_pedido_de_mercadolibre_trae_el_envio_y_se_baja_la_etiqueta_de_mercado_envios(): void
    {
        $canal = Canal::create(['business_id' => $this->empresa->id, 'tipo' => 'mercadolibre', 'nombre' => 'ML', 'credenciales' => ['access_token' => 'ML-TOKEN', 'user_id' => '77'], 'activo' => true, 'importar_pedidos' => true]);
        $p = $this->articulo(['stock_inicial' => 10, 'sku' => 'SKU-ML']);
        $svc = app(CanalesService::class);
        $raw = ['id' => 2000001, 'status' => 'paid', 'total_amount' => 400, 'buyer' => ['first_name' => 'Ana', 'last_name' => 'López', 'nickname' => 'ANALOPEZ'], 'shipping' => ['id' => 4321, 'status' => 'ready_to_ship', 'logistic_type' => 'drop_off', 'receiver_address' => ['address_line' => 'Belgrano 100']], 'order_items' => [['item' => ['title' => 'Cemento', 'seller_sku' => 'SKU-ML'], 'quantity' => 2, 'unit_price' => 200]]];
        $pedido = $svc->alta($this->empresa, $canal, $svc->normalizar('mercadolibre', $raw));
        $this->assertSame('4321', $pedido->envio_datos['shipment_id']); $this->assertSame('mercadoenvios', $pedido->envio_datos['proveedor']);
        $this->assertSame($p->id, $pedido->items[0]['product_id']);

        Http::fake([
            'api.mercadolibre.com/shipment_labels*' => Http::response('%PDF-1.4 etiqueta', 200, ['Content-Type' => 'application/pdf']),
            'api.mercadolibre.com/shipments/4321' => Http::response(['status' => 'shipped', 'substatus' => null, 'tracking_number' => 'AR123456', 'logistic_type' => 'drop_off']),
        ]);
        $this->get("/comprobantes/pedidos/{$pedido->id}/etiqueta")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Http::assertSent(fn($req) => str_contains($req->url(), 'shipment_labels') && $req['shipment_ids'] === '4321' && $req->hasHeader('Authorization', 'Bearer ML-TOKEN'));
        $this->post("/comprobantes/pedidos/{$pedido->id}/envio")->assertSessionHas('success');
        $pedido->refresh();
        $this->assertSame('AR123456', $pedido->envio_datos['tracking']); $this->assertSame('shipped', $pedido->envio_datos['estado']);
        $this->assertSame('enviado', $pedido->estado, 'El pedido pasa a enviado cuando Mercado Envíos lo despacha');
        $this->get('/comprobantes/pedidos?estado=enviado')->assertInertia(fn($pg) => $pg->component('Comprobantes/Pedidos', false)->where('pedidos.0.envio_datos.shipment_id', '4321'));
        // Un pedido sin envío de ML no tiene etiqueta.
        $otro = PedidoWeb::create(['business_id' => $this->empresa->id, 'canal' => 'tienda', 'cliente' => ['nombre' => 'X'], 'items' => [], 'subtotal' => 0, 'envio' => 0, 'descuento' => 0, 'total' => 0, 'entrega' => 'retiro', 'pago' => 'efectivo', 'estado' => 'nuevo']);
        $this->get("/comprobantes/pedidos/{$otro->id}/etiqueta")->assertStatus(422);
    }

    public function test_cliente_guarda_las_marcas_de_percepcion_y_el_formulario_las_muestra(): void
    {
        $cli = $this->cliente(['cuit' => '30-71234567-1']);
        $this->post("/clientes/{$cli->id}", ['name' => $cli->name, 'condicion_iva' => 'Responsable Inscripto', 'cuit' => '30-71234567-1', 'lista_precios' => 1, 'percepcion_iva' => true, 'percepcion_ganancias' => true])->assertSessionHas('success');
        $cli->refresh();
        $this->assertTrue((bool) $cli->percepcion_iva); $this->assertTrue((bool) $cli->percepcion_ganancias);
        $this->get('/comprobantes/nuevo')->assertInertia(fn($pg) => $pg->component('Comprobantes/Form', false)->where('clientes.0.percepcion_iva', true));
    }

    public function test_el_cot_se_pide_a_arba_por_web_service_con_la_clave_cit(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
        $rem = $svc->emitir($svc->guardarBorrador(['contact_id' => $this->cliente()->id, 'tipo' => 'REM', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], 'transportista' => 'Pérez', 'patente' => 'AB123CD']));
        // Sin clave: mensaje claro.
        $this->post("/comprobantes/{$rem->id}/cot/pedir")->assertSessionHas("error");
        $this->post('/configuracion/impuestos', ['impuestos' => ['agente_retencion' => false], 'cierre_mes' => 12, 'arba_clave' => 'CIT-secreta', 'arba_produccion' => false])->assertSessionHas('success');
        $this->assertSame('CIT-secreta', $this->empresa->fresh()->arba_settings['clave']);
        $this->get('/configuracion/impuestos')->assertInertia(fn($pg) => $pg->component('Configuracion/Impuestos', false)->where('arba.clave_set', true)->missing('arba.clave'));
        Http::fake(['cot.test.arba.gov.ar/*' => Http::sequence()
            ->push('<?xml version="1.0"?><COT><tipoError></tipoError><validacionesRemitos><remito><numeroUnico>R000100000003</numeroUnico><procesado>SI</procesado><cot>011234567890123</cot></remito></validacionesRemitos></COT>')
            ->push('<?xml version="1.0"?><COT><tipoError>ERROR</tipoError><mensajeError>Clave incorrecta</mensajeError></COT>')]);
        $this->post("/comprobantes/{$rem->id}/cot/pedir")->assertSessionHas('success');
        $this->assertSame('011234567890123', $rem->fresh()->cot);
        Http::assertSent(fn($r) => str_contains($r->url(), 'cot.test.arba.gov.ar') && $r->isMultipart() && collect($r->data())->contains(fn($d) => ($d['name'] ?? '') === 'password' && ($d['contents'] ?? '') === 'CIT-secreta'));
        \App\Models\Comprobante::where('id', $rem->id)->update(['cot' => null]);
        $this->post("/comprobantes/{$rem->id}/cot/pedir")->assertSessionHas('error');
        $this->assertNull($rem->fresh()->cot);
        // Interpretación de errores por remito.
        $r = app(\App\Services\Fiscal\CotService::class)->interpretar('<COT><validacionesRemitos><remito><procesado>NO</procesado><errores><error><codigo>15</codigo><descripcion>Patente inválida</descripcion></error></errores></remito></validacionesRemitos></COT>');
        $this->assertNull($r['cot']); $this->assertStringContainsString('Patente inválida', $r['error']);
    }

    public function test_sin_el_tilde_de_arca_la_factura_sale_como_comprobante_interno_y_no_entra_en_los_libros(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente();
        // Una fiscal primero: numeración 1.
        $fiscal = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame(1, (int) $fiscal->numero);
        $this->post('/comprobantes', ['tipo' => 'FX', 'contact_id' => $cli->id, 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'sin_arca' => true, 'emitir' => true, 'items' => [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 100]]])->assertSessionHas('success');
        $int = \App\Models\Comprobante::ventas()->where('sin_arca', true)->firstOrFail();
        $this->assertSame('emitido', $int->estado); $this->assertSame('interno', $int->afip_estado); $this->assertNull($int->cae);
        $this->assertSame(1, (int) $int->numero, 'Numera aparte: no gasta el número fiscal');
        $this->assertSame('Comprobante interno', $int->nombreTipo());
        // Impacta en cuenta corriente y stock como cualquier venta.
        $this->assertEqualsWithDelta(242, (float) $int->saldo, 0.01); $this->assertSame(7.0, (float) $p->fresh()->stock);
        // La siguiente fiscal sigue en 2.
        $f2 = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame(2, (int) $f2->numero);
        // Libros de IVA y fiscal no la incluyen; el contable sí (es una venta real).
        $desde = today()->startOfMonth()->toDateString(); $hasta = today()->endOfMonth()->toDateString();
        $this->get("/contable/iva?libro=ventas&desde={$desde}&hasta={$hasta}")->assertInertia(fn($pg) => $pg->component('Contable/Iva', false)->has('filas', 2));
        $this->get("/contable/contador?desde={$desde}&hasta={$hasta}")->assertInertia(fn($pg) => $pg->component('Contable/Contador', false)->where('resumen.ventas', 2));
        $this->assertTrue(\App\Models\Asiento::where('origen', 'venta')->where('origen_id', $int->id)->exists());
        $this->get("/comprobantes/{$int->id}/imprimir")->assertOk()->assertSee('NO VÁLIDO COMO FACTURA')->assertSee('COMPROBANTE INTERNO')->assertDontSee('CAE:');
        $this->get("/comprobantes/{$int->id}")->assertInertia(fn($pg) => $pg->component('Comprobantes/Ver', false)->where('c.interno', true)->where('c.fiscal', false));
        // Una nota de crédito sobre un interno también es interna.
        $this->post("/comprobantes/{$int->id}/convertir", ['tipo' => 'NCX'])->assertRedirect();
        $nc = \App\Models\Comprobante::ventas()->where('origen_id', $int->id)->firstOrFail();
        $this->assertTrue((bool) $nc->sin_arca);
        $this->assertAsientosBalancean();
    }
}
