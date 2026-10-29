<?php

namespace Tests\Feature;

use App\Models\CompraBorrador;
use App\Models\Comprobante;
use App\Models\OrdenCompra;
use App\Models\ProveedorArticulo;
use App\Models\ProveedorRegla;
use App\Services\Compras\LecturaCompraService;
use Tests\ErpTestCase;

// Fase 31.1–31.4: el QR de ARCA, entender al proveedor (aprendido, bultos, bonificaciones, descuentos, pie), revisar antes de mandar,
// remito sin precios y la factura de ese remito, comparar con la orden, facturas a medio cargar.
class LecturaCompraTest extends ErpTestCase
{
    public function test_el_qr_de_arca_da_la_cabecera_exacta_y_reconoce_al_proveedor(): void
    {
        $svc = app(LecturaCompraService::class);
        $prov = $this->proveedor(['cuit' => '30-50000000-1']);
        $json = base64_encode(json_encode(['ver' => 1, 'fecha' => '2026-10-20', 'cuit' => 30500000001, 'ptoVta' => 3, 'tipoCmp' => 1, 'nroCmp' => 12345, 'importe' => 12100.5, 'moneda' => 'PES', 'ctz' => 1, 'tipoDocRec' => 80, 'nroDocRec' => 30700123456, 'tipoCodAut' => 'E', 'codAut' => 74123456789012]));
        $qr = $svc->qrAfip("https://www.afip.gob.ar/fe/qr/?p={$json}");
        $this->assertSame('30-50000000-1', $qr['cuit']); $this->assertSame('FA', $qr['tipo']); $this->assertSame('0003-00012345', $qr['numero']); $this->assertSame('2026-10-20', $qr['fecha']); $this->assertEqualsWithDelta(12100.5, $qr['total'], 0.001); $this->assertSame('74123456789012', $qr['cae']);
        $this->assertNull($svc->qrAfip('https://otra.cosa/x'));
        // La lectura de la IA traía el número mal: el QR la corrige y reconoce al proveedor aunque el nombre viniera distinto.
        $d = $svc->entender(['proveedor' => ['nombre' => 'Proveedr S.A.'], 'tipo' => 'FA', 'numero' => '0003-00012348', 'fecha' => '2026-10-20', 'items' => [], 'percepciones' => []], null, $qr);
        $this->assertSame('0003-00012345', $d['numero']); $this->assertSame($prov->id, $d['proveedor_id']); $this->assertTrue($d['cabecera_qr']);
        $this->assertStringContainsString('salieron del código QR', $d['avisos'][0]); $this->assertStringContainsString('numero', $d['avisos'][0]);
    }

    public function test_entiende_al_proveedor_aprendido_bultos_bonificacion_descuento_y_pie(): void
    {
        $svc = app(LecturaCompraService::class);
        $prov = $this->proveedor(['cuit' => '30-50000000-1']);
        $cem = $this->articulo(['name' => 'Cemento x 50 kg', 'sku' => 'CEM50', 'cost' => 1000, 'price' => 1500, 'stock_inicial' => 10]);
        $gal = $this->articulo(['name' => 'Galletitas (12)', 'sku' => 'GAL', 'cost' => 100, 'price' => 160, 'stock_inicial' => 10]);
        $agua = $this->articulo(['name' => 'Agua 500', 'sku' => 'AGU', 'cost' => 50, 'price' => 90, 'stock_inicial' => 10, 'unidades_bulto' => 6]);
        $prov->update(['compras_config' => ['factor_parentesis' => true]]);
        ProveedorArticulo::create(['business_id' => $this->empresa->id, 'contact_id' => $prov->id, 'codigo' => 'P-77', 'descripcion' => ProveedorArticulo::normalizar('CEMENTO PORTLAND BOLSA 50KG'), 'product_id' => $cem->id]);
        ProveedorRegla::create(['cuit' => '30500000001', 'concepto' => ProveedorRegla::normalizar('Perc. IIBB CABA 3%'), 'tratamiento' => 'percepcion_iibb']);
        $d = $svc->entender(['proveedor' => ['cuit' => '30-50000000-1'], 'items' => [
            ['descripcion' => 'CEMENTO PORTLAND BOLSA 50KG', 'codigo' => 'P-77', 'cantidad' => 10, 'precio_unit' => 1100, 'alicuota_iva' => 21],            // aprendido por código
            ['descripcion' => 'CEMENTO PORTLAND BOLSA 50KG', 'cantidad' => 2, 'precio_unit' => 0, 'alicuota_iva' => 21],                               // bonificación en mercadería
            ['descripcion' => 'GALLETITAS DULCES CAJA', 'product_id' => $gal->id, 'cantidad' => 3, 'precio_unit' => 1200, 'alicuota_iva' => 21],        // bulto de 12 por el paréntesis del nombre (y el precio ≈ 12 × costo)
            ['descripcion' => 'AGUA 500 PACK', 'product_id' => $agua->id, 'cantidad' => 4, 'precio_unit' => 300, 'alicuota_iva' => 21],                 // bulto de 6 por la ficha
            ['descripcion' => 'DESCUENTO CEMENTO', 'cantidad' => 1, 'precio_unit' => -1100, 'alicuota_iva' => 21],                                       // renglón negativo: descuento sobre el cemento
        ], 'percepciones' => [['tipo' => 'otro', 'concepto' => 'Perc. IIBB CABA 3%', 'monto' => 300], ['tipo' => 'otro', 'concepto' => 'Gastos administrativos', 'monto' => 80]]], $prov);
        $it = collect($d['items']);
        $c = $it->firstWhere('codigo', 'P-77'); $this->assertSame($cem->id, $c['product_id']); $this->assertSame('aprendido', $c['por']); $this->assertEqualsWithDelta(10, $c['descuento'], 0.01, 'El descuento del cemento (1.100 sobre 11.000) se repartió en el cemento');
        $bon = $it->first(fn($x) => ! empty($x['sin_cargo'])); $this->assertSame($cem->id, $bon['product_id']); $this->assertFalse($bon['actualiza_costo']);
        $g = $it->first(fn($x) => $x['product_id'] === $gal->id); $this->assertEquals(36, $g['cantidad']); $this->assertEqualsWithDelta(100, $g['precio_unit'], 0.01); $this->assertSame(12, $g['bulto']);
        $a = $it->first(fn($x) => $x['product_id'] === $agua->id); $this->assertEquals(24, $a['cantidad']); $this->assertEqualsWithDelta(50, $a['precio_unit'], 0.01);
        $this->assertCount(4, $d['items'], 'El renglón de descuento desapareció como renglón');
        $this->assertSame([['tipo' => 'iibb', 'monto' => 300.0]], $d['percepciones'], 'La percepción conocida se aplicó sola');
        $this->assertSame('Gastos administrativos', $d['preguntar'][0]['concepto'], 'Lo desconocido se pregunta');
        $this->assertStringContainsString('ya lo dijiste', implode(' ', $d['avisos']));
        // Se contesta una vez y queda para ese CUIT.
        $this->postJson('/proveedores/compras/regla-pie', ['contact_id' => $prov->id, 'concepto' => 'Gastos administrativos', 'tratamiento' => 'flete'])->assertOk();
        $d2 = $svc->entender(['proveedor' => ['cuit' => '30-50000000-1'], 'items' => [['descripcion' => 'x', 'product_id' => $cem->id, 'cantidad' => 1, 'precio_unit' => 1000]], 'percepciones' => [['tipo' => 'otro', 'concepto' => 'Gastos administrativos', 'monto' => 80]]], $prov);
        $this->assertSame([], $d2['preguntar']); $this->assertSame('Gastos administrativos', end($d2['items'])['descripcion']);
    }

    public function test_revisar_antes_de_mandar_y_aprender_al_registrar(): void
    {
        $prov = $this->proveedor(['cuit' => '30-50000000-1']);
        $cem = $this->articulo(['name' => 'Cemento', 'sku' => 'CEM50', 'cost' => 1000, 'price' => 1500, 'stock_inicial' => 10]);
        $r = $this->postJson('/proveedores/compras/revisar', ['items' => [['product_id' => $cem->id, 'descripcion' => 'Cemento', 'cantidad' => 10, 'precio_unit' => 1300, 'descuento' => 0, 'alicuota_iva' => 21], ['product_id' => $cem->id, 'descripcion' => 'Cemento', 'cantidad' => 1, 'precio_unit' => 1700, 'descuento' => 0, 'alicuota_iva' => 21]], 'total' => 20000])->assertOk()->json();
        $this->assertFalse($r['cierra']); $this->assertTrue(collect($r['alertas'])->contains('tipo', 'no_cierra'));
        $this->assertEqualsWithDelta(30, $r['filas'][0]['variacion'], 0.1); $this->assertTrue($r['filas'][0]['cambia_mucho']); $this->assertTrue($r['filas'][0]['repetido']);
        $this->assertTrue($r['filas'][1]['bajo_costo'], '1.700 de costo con 1.500 de precio');
        // Registrar: sólo el renglón tildado escribe el costo; el proveedor aprende la descripción y el bulto.
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000001', 'fecha' => today()->toDateString(), 'registrar' => true,
            'items' => [['product_id' => $cem->id, 'descripcion' => 'CEMENTO PORTLAND BOLSA', 'codigo' => 'P-77', 'cantidad' => 10, 'precio_unit' => 1300, 'alicuota_iva' => 21, 'actualiza_costo' => true, 'bulto' => 10], ['product_id' => $cem->id, 'descripcion' => 'CEMENTO PORTLAND BOLSA', 'cantidad' => 2, 'precio_unit' => 0, 'alicuota_iva' => 21, 'actualiza_costo' => false]]])->assertRedirect();
        $this->assertEqualsWithDelta(1300, (float) $cem->fresh()->cost, 0.01, 'El renglón sin cargo no bajó el costo a 0');
        $this->assertEqualsWithDelta(22, (float) $cem->fresh()->stock, 0.001, '10 pagadas + 2 de regalo');
        $pa = ProveedorArticulo::where('contact_id', $prov->id)->first();
        $this->assertSame($cem->id, $pa->product_id); $this->assertSame('P-77', $pa->codigo); $this->assertSame(10, $pa->unidades_bulto); $this->assertSame(10, $cem->fresh()->unidades_bulto);
        $this->assertAsientosBalancean();
    }

    public function test_remito_sin_precios_solo_stock_y_la_factura_de_ese_remito_solo_contable(): void
    {
        $prov = $this->proveedor(['cuit' => '30-50000000-1']);
        $cem = $this->articulo(['name' => 'Cemento', 'sku' => 'CEM50', 'cost' => 1000, 'price' => 1500, 'stock_inicial' => 10]);
        $saldoAntes = (float) $prov->fresh()->balance;
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'REM', 'numero_proveedor' => '0001-00000777', 'fecha' => today()->toDateString(), 'registrar' => true, 'items' => [['product_id' => $cem->id, 'descripcion' => 'Cemento', 'cantidad' => 5, 'precio_unit' => 0, 'alicuota_iva' => 21]]])->assertRedirect();
        $rem = Comprobante::compras()->where('tipo', 'REM')->first();
        $this->assertTrue($rem->solo_stock); $this->assertSame('emitido', $rem->estado);
        $this->assertEqualsWithDelta(15, (float) $cem->fresh()->stock, 0.001, 'El remito entró al stock');
        $this->assertEqualsWithDelta($saldoAntes, (float) $prov->fresh()->balance, 0.01, 'No tocó la cuenta del proveedor');
        $this->assertEqualsWithDelta(1000, (float) $cem->fresh()->cost, 0.01, 'Sin precios no se toca el costo');
        $this->assertSame(0, \App\Models\Asiento::where('origen', 'comprobante')->where('origen_id', $rem->id)->count(), 'Sin asiento');
        $this->getJson("/proveedores/compras/abiertos/{$prov->id}")->assertOk()->assertJsonPath('remitos.0.id', $rem->id);
        // La factura que llega después: entra a la cuenta y a contabilidad, no vuelve a mover stock, y ahora sí escribe el costo.
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000900', 'fecha' => today()->toDateString(), 'registrar' => true, 'remito_id' => $rem->id, 'items' => [['product_id' => $cem->id, 'descripcion' => 'Cemento', 'cantidad' => 5, 'precio_unit' => 1200, 'alicuota_iva' => 21]]])->assertRedirect();
        $fa = Comprobante::compras()->where('tipo', 'FA')->first();
        $this->assertTrue($fa->solo_contable); $this->assertSame($rem->id, $fa->remito_id);
        $this->assertEqualsWithDelta(15, (float) $cem->fresh()->stock, 0.001, 'El stock no entró dos veces');
        $this->assertEqualsWithDelta($saldoAntes + 5 * 1200 * 1.21, (float) $prov->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(1200, (float) $cem->fresh()->cost, 0.01);
        $this->assertStringContainsString('Facturado por', $rem->fresh()->notas);
        $this->getJson("/proveedores/compras/abiertos/{$prov->id}")->assertOk()->assertJsonCount(0, 'remitos');
        $this->assertAsientosBalancean();
    }

    public function test_comparar_con_la_orden_de_compra_y_facturas_a_medio_cargar(): void
    {
        $prov = $this->proveedor(['cuit' => '30-50000000-1']);
        $cem = $this->articulo(['name' => 'Cemento', 'sku' => 'CEM50', 'cost' => 1000, 'stock_inicial' => 10]); $cal = $this->articulo(['name' => 'Cal', 'sku' => 'CAL', 'cost' => 300, 'stock_inicial' => 10]);
        $oc = app(\App\Services\Compras\OrdenCompraService::class)->guardar(['contact_id' => $prov->id, 'fecha' => today()->toDateString(), 'items' => [['product_id' => $cem->id, 'cantidad' => 10, 'precio_unit' => 1000], ['product_id' => $cal->id, 'cantidad' => 5, 'precio_unit' => 300]]]);
        $r = $this->postJson('/proveedores/compras/comparar-oc', ['orden_compra_id' => $oc->id, 'items' => [['product_id' => $cem->id, 'cantidad' => 8, 'precio_unit' => 1100]]])->assertOk()->json();
        $tipos = collect($r['diferencias'])->pluck('tipo')->all();
        $this->assertContains('de_menos', $tipos); $this->assertContains('precio', $tipos); $this->assertContains('no_vino', $tipos);
        $this->assertStringContainsString('Facturaron menos', $r['reclamo']);
        $this->assertEmpty($this->postJson('/proveedores/compras/comparar-oc', ['orden_compra_id' => $oc->id, 'items' => [['product_id' => $cem->id, 'cantidad' => 10, 'precio_unit' => 1015], ['product_id' => $cal->id, 'cantidad' => 5, 'precio_unit' => 300]]])->json('diferencias'), '1,5 % de diferencia entra en la tolerancia');
        // A medio cargar: se guarda, se lista, se retoma y se borra al registrar.
        $datos = ['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000005', 'fecha' => today()->toDateString(), 'items' => [['product_id' => $cem->id, 'descripcion' => 'Cemento', 'cantidad' => 2, 'precio_unit' => 1000, 'alicuota_iva' => 21]]];
        $id = $this->postJson('/proveedores/compras/borradores', ['datos' => $datos])->assertOk()->json('id');
        $this->getJson('/proveedores/compras/borradores')->assertOk()->assertJsonPath('0.proveedor', 'Proveedor SA')->assertJsonPath('0.total', 2420);
        $this->getJson("/proveedores/compras/borradores/{$id}")->assertOk()->assertJsonPath('datos.numero_proveedor', '0001-00000005');
        $this->post('/proveedores/compras', $datos + ['registrar' => true, 'borrador_id' => $id])->assertRedirect();
        $this->assertSame(0, CompraBorrador::count());
        // Otra empresa no ve el borrador de ésta.
        $id2 = $this->postJson('/proveedores/compras/borradores', ['datos' => $datos])->json('id');
        [$otra, $sucB, $duenoB] = $this->crearEmpresa('Otra', 'otra');
        $this->actingAs($duenoB)->getJson("/proveedores/compras/borradores/{$id2}")->assertNotFound();
    }
}
