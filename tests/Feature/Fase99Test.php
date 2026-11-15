<?php

namespace Tests\Feature;

use App\Models\ConciliacionRegla;
use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Models\ExtractoItem;
use App\Models\MovimientoFondos;
use App\Models\PosicionIva;
use App\Services\Contabilidad\PosicionIvaService;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 99 · Lo de Xubio en Plata: Mercado Pago por API, reglas de conciliación que actúan solas, posición de IVA con arrastre e indicadores del contador.
class Fase99Test extends ErpTestCase
{
    public function test_mercado_pago_entra_solo_y_se_concilia_con_los_cobros_del_sistema(): void
    {
        $this->empresa->update(['mercadopago_settings' => ['access_token' => 'APP_USR-test', 'oauth' => true, 'user_id' => '1']]);
        $mp = CuentaFondos::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'tipo' => 'billetera', 'nombre' => 'Mercado Pago', 'moneda' => 'ARS', 'saldo' => 0, 'activa' => true]);
        ExpenseCategory::create(['business_id' => $this->empresa->id, 'name' => 'Comisiones bancarias y MP']);
        // El mostrador ya registró el cobro con QR por 15.000.
        app(FondosService::class)->registrar($mp, ['fecha' => '2026-05-10', 'origen' => 'cobro', 'concepto' => 'Cobro QR venta 0001-00000042', 'ingreso' => 15000, 'referencia' => '123456789']);
        $pago = fn($id, $monto, $fee, $estado = 'approved', $dev = 0) => ['id' => $id, 'status' => $estado, 'date_approved' => '2026-05-10T14:03:00.000-03:00', 'date_last_updated' => '2026-05-12T10:00:00.000-03:00', 'transaction_amount' => $monto, 'transaction_amount_refunded' => $dev, 'payment_method_id' => 'account_money', 'payment_type_id' => 'account_money', 'description' => 'Venta mostrador', 'payer' => ['email' => 'c@x.com'], 'fee_details' => $fee ? [['type' => 'mercadopago_fee', 'amount' => $fee]] : [], 'external_reference' => null];
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['paging' => ['total' => 2, 'limit' => 50, 'offset' => 0], 'results' => [$pago(123456789, 15000, 600), $pago(555, 2000, 0, 'refunded', 2000)]])]);

        $this->post('/contable/conciliacion/mercadopago', ['cuenta' => $mp->id, 'desde' => '2026-05-01'])->assertSessionHas('success');
        $items = ExtractoItem::where('cuenta_fondos_id', $mp->id)->orderBy('id')->get();
        $this->assertCount(4, $items, 'Cobro bruto + comisión + cobro devuelto + devolución');
        $cobro = $items->firstWhere('referencia', '123456789'); $this->assertSame('conciliado', $cobro->estado); $this->assertSame('auto', $cobro->match);
        $fee = $items->firstWhere('referencia', 'fee-123456789'); $this->assertSame('conciliado', $fee->estado); $this->assertEquals(-600, (float) $fee->monto);
        $gasto = MovimientoFondos::find($fee->movimiento_fondos_id); $this->assertEquals(600, (float) $gasto->egreso); $this->assertSame('Comisiones bancarias y MP', $gasto->categoria?->name ?? ExpenseCategory::find($gasto->expense_category_id)?->name);
        $this->assertSame('pendiente', $items->firstWhere('referencia', 'dev-555')->estado, 'La devolución la mira alguien');
        $cfg = $mp->fresh()->conciliacion_config; $this->assertTrue($cfg['mercadopago']); $this->assertTrue($cfg['mp_automatico']); $this->assertNotNull($cfg['mp_ultima']);
        Http::assertSent(fn($r) => str_contains($r->url(), '/v1/payments/search') && str_contains($r->url(), 'range=date_approved'));
        // Segunda vez: nada nuevo. Y el comando de la madrugada corre sin errores.
        $this->post('/contable/conciliacion/mercadopago', ['cuenta' => $mp->id, 'desde' => '2026-05-01'])->assertSessionHas('success', fn($m) => str_contains($m, 'sin movimientos nuevos'));
        $this->assertCount(4, ExtractoItem::where('cuenta_fondos_id', $mp->id)->get());
        $this->artisan('mercadopago:movimientos')->expectsOutputToContain('0 nuevos')->assertSuccessful();
        $p = $this->get("/contable/conciliacion?cuenta={$mp->id}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($p['mercadopago']['activo']); $this->assertTrue($p['mercadopago']['conectado']); $this->assertTrue($p['mercadopago']['es_billetera']);
        // Bigcito
        $cat = app(\App\Mcp\Catalogo::class);
        $prop = $cat->llamar($this->dueno, 'traer_mercadopago', [], 'asistente'); $this->assertStringContainsString('Mercado Pago', $prop['titulo']);
        $r = $cat->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $prop['propuesta_id']], 'asistente'); $this->assertSame(0, $r['nuevos']);
        $r = $cat->llamar($this->dueno->fresh(), 'conciliacion_pendiente', [], 'asistente');
        $this->assertSame(2, collect($r['cuentas'])->firstWhere('cuenta', 'Mercado Pago')['extracto_pendiente'], 'El cobro devuelto y su devolución quedan para mirar');
    }

    public function test_reglas_de_conciliacion_que_aprenden_y_actuan_solas(): void
    {
        $banco = CuentaFondos::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'tipo' => 'banco', 'nombre' => 'Banco Nación', 'moneda' => 'ARS', 'saldo' => 500000, 'activa' => true]);
        $luz = ExpenseCategory::create(['business_id' => $this->empresa->id, 'name' => 'Luz']); $bancarios = ExpenseCategory::create(['business_id' => $this->empresa->id, 'name' => 'Gastos bancarios']);
        $svc = app(\App\Services\Contabilidad\ConciliacionService::class);
        $svc->importarFilas($banco, [['fecha' => '2026-05-05', 'descripcion' => 'DEBITO AUTOMATICO EDEMSA 00123456', 'referencia' => null, 'monto' => -5000, 'saldo' => null], ['fecha' => '2026-05-06', 'descripcion' => 'COMISION MANTENIMIENTO CUENTA 05/2026', 'referencia' => null, 'monto' => -1200, 'saldo' => null], ['fecha' => '2026-05-07', 'descripcion' => 'TRANSF ENTRE CUENTAS PROPIAS', 'referencia' => null, 'monto' => 90000, 'saldo' => null]], 'mayo.csv');
        $this->assertSame(3, ExtractoItem::where('cuenta_fondos_id', $banco->id)->where('estado', 'pendiente')->count());

        // Regla a mano que se aplica enseguida a los pendientes.
        $this->post('/contable/conciliacion/reglas', ['patron' => 'edemsa', 'accion' => 'gasto', 'expense_category_id' => $luz->id, 'concepto' => 'Luz del local', 'aplicar' => $banco->id])->assertSessionHas('success', fn($m) => str_contains($m, 'Resolvió 1'));
        $it = ExtractoItem::where('descripcion', 'like', 'DEBITO AUTOMATICO EDEMSA%')->first(); $this->assertSame('conciliado', $it->estado); $this->assertSame('regla', $it->match);
        $m = MovimientoFondos::find($it->movimiento_fondos_id); $this->assertEquals(5000, (float) $m->egreso); $this->assertSame($luz->id, $m->expense_category_id); $this->assertSame('Luz del local', $m->concepto);
        // "Recordar" al registrar a mano: queda la regla sin los números.
        $com = ExtractoItem::where('descripcion', 'like', 'COMISION%')->first();
        $this->post("/contable/conciliacion/{$com->id}/registrar", ['expense_category_id' => $bancarios->id, 'recordar' => true])->assertSessionHas('success', fn($m) => str_contains($m, 'se registra solo'));
        $regla = ConciliacionRegla::where('accion', 'gasto')->where('expense_category_id', $bancarios->id)->first(); $this->assertSame('comision mantenimiento cuenta', $regla->patron); $this->assertSame('egreso', $regla->sentido);
        // "Recordar" al ignorar.
        $tr = ExtractoItem::where('descripcion', 'like', 'TRANSF ENTRE%')->first();
        $this->post("/contable/conciliacion/{$tr->id}/ignorar", ['recordar' => true])->assertSessionHas('success'); $this->assertSame('ignorado', $tr->fresh()->estado);
        $this->assertSame(3, ConciliacionRegla::count());
        // El extracto del mes siguiente se resuelve solo.
        $svc->importarFilas($banco, [['fecha' => '2026-06-05', 'descripcion' => 'DEBITO AUTOMATICO EDEMSA 00123999', 'referencia' => null, 'monto' => -5300, 'saldo' => null], ['fecha' => '2026-06-06', 'descripcion' => 'COMISION MANTENIMIENTO CUENTA 06/2026', 'referencia' => null, 'monto' => -1250, 'saldo' => null], ['fecha' => '2026-06-07', 'descripcion' => 'TRANSF ENTRE CUENTAS PROPIAS', 'referencia' => null, 'monto' => 50000, 'saldo' => null], ['fecha' => '2026-06-08', 'descripcion' => 'ALGO NUEVO', 'referencia' => null, 'monto' => -10, 'saldo' => null]], 'junio.csv');
        $junio = ExtractoItem::where('cuenta_fondos_id', $banco->id)->whereMonth('fecha', 6)->orderBy('fecha')->orderBy('id')->get();
        $this->assertSame(['conciliado', 'conciliado', 'ignorado', 'pendiente'], $junio->pluck('estado')->all());
        $this->assertSame(2, (int) ConciliacionRegla::where('patron', 'edemsa')->value('veces'));
        $lista = $this->get("/contable/conciliacion?cuenta={$banco->id}")->viewData('page')['props']['reglas']; $this->assertCount(3, $lista); $this->assertSame('edemsa', $lista[0]['patron']);
        $this->post("/contable/conciliacion/reglas/{$regla->id}/borrar")->assertSessionHas('success'); $this->assertSame(2, ConciliacionRegla::count());
    }

    public function test_posicion_de_iva_con_arrastre_e_indicadores_del_contador(): void
    {
        $cli = $this->cliente(['name' => 'Cliente RI', 'condicion_iva' => 'Responsable Inscripto', 'cuit' => '30-70000000-1']); $prov = $this->proveedor(['name' => 'Proveedor RI']);
        $p = $this->articulo(['name' => 'Cosa', 'price' => 1000, 'cost' => 500, 'alicuota_iva' => 21]);
        $compras = app(\App\Services\Compras\CompraService::class);
        // Marzo: ventas 100.000 + 21.000 de IVA; compras 200.000 + 42.000 más una percepción de IVA de 3.000 → a favor técnico 21.000 y libre 3.000.
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 100, 'precio_unit' => 1000]], ['tipo' => 'FA', 'fecha' => '2026-03-10']);
        $compras->registrar($compras->guardarBorrador(['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000001', 'fecha' => '2026-03-12', 'items' => [['product_id' => $p->id, 'cantidad' => 400, 'precio_unit' => 500, 'descuento' => 0, 'alicuota_iva' => 21]], 'impuestos' => [['tipo' => 'perc_iva', 'base' => 200000, 'alicuota' => 1.5, 'monto' => 3000]]]));
        $svc = app(PosicionIvaService::class); $c = $svc->calcular($this->empresa, '2026-03');
        $this->assertEquals(21000, $c['debito']); $this->assertEquals(42000, $c['credito']); $this->assertEquals(3000, $c['percepciones']); $this->assertEquals(21000, $c['ventas']['21']);
        $this->assertEquals(0, $c['a_pagar']); $this->assertEquals(21000, $c['saldo_tecnico']); $this->assertEquals(3000, $c['saldo_libre']); $this->assertSame('a_favor', $c['resultado']);
        $this->post('/contable/iva/posicion', ['mes' => '2026-03', 'cerrar' => true])->assertSessionHas('success');
        $this->assertSame('cerrada', PosicionIva::where('mes', '2026-03')->value('estado'));
        // Abril: ventas 300.000 + 63.000; compras 50.000 + 10.500. Técnico: 63.000 − 10.500 − 21.000 = 31.500; menos el libre 3.000 → a pagar 28.500.
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 300, 'precio_unit' => 1000]], ['tipo' => 'FA', 'fecha' => '2026-04-05']);
        $compras->registrar($compras->guardarBorrador(['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000002', 'fecha' => '2026-04-08', 'items' => [['product_id' => $p->id, 'cantidad' => 100, 'precio_unit' => 500, 'descuento' => 0, 'alicuota_iva' => 21]]]));
        $c = $svc->calcular($this->empresa, '2026-04');
        $this->assertEquals(21000, $c['saldo_anterior_tecnico']); $this->assertEquals(3000, $c['saldo_anterior_libre']); $this->assertEquals(31500, $c['tecnico']); $this->assertEquals(28500, $c['a_pagar']); $this->assertEquals(0, $c['saldo_libre']); $this->assertSame('a_pagar', $c['resultado']);
        $this->assertSame('2026-05-', substr($c['vencimiento'], 0, 8));
        // Un ajuste del contador (crédito no computable de 1.000) sube lo que se paga; la posición se cierra y el mes cerrado no se toca sin reabrir.
        $this->post('/contable/iva/posicion', ['mes' => '2026-04', 'ajuste' => 1000, 'ajuste_nota' => 'Crédito no computable', 'cerrar' => true]);
        $abril = PosicionIva::where('mes', '2026-04')->first(); $this->assertEquals(29500, (float) $abril->a_pagar); $this->assertSame('cerrada', $abril->estado);
        $this->post('/contable/iva/posicion', ['mes' => '2026-04', 'ajuste' => 0])->assertStatus(422);
        $this->post("/contable/iva/posicion/{$abril->id}/reabrir")->assertSessionHas('success'); $this->assertSame('borrador', $abril->fresh()->estado);
        $page = $this->get('/contable/iva/posicion?mes=2026-04')->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString('A PAGAR: $ 29.500,00', $page['texto']); $this->assertCount(2, $page['historial']);
        $r = app(\App\Mcp\Catalogo::class)->llamar($this->dueno, 'posicion_iva', ['mes' => '2026-04'], 'asistente');
        $this->assertEquals(29500, $r['a_pagar']); $this->assertEquals(21000, $r['saldo_anterior']['tecnico']);
        // Indicadores
        $t = app(\App\Services\Contabilidad\IndicadoresContadorService::class)->tablero($this->empresa, '2026-04');
        $this->assertCount(12, $t['serie']); $this->assertEquals(300000, $t['kpis']['ventas_mes']); $this->assertGreaterThanOrEqual(400000, $t['kpis']['ventas_12']); $this->assertGreaterThanOrEqual(250000, $t['kpis']['compras_12']);
        $margen = round(($t['kpis']['ventas_12'] - $t['kpis']['compras_12']) / $t['kpis']['ventas_12'] * 100, 1); $this->assertEquals($margen, $t['kpis']['margen_bruto']); $this->assertEquals($t['kpis']['ventas_12'], round(array_sum(array_column($t['serie'], 'ventas_neto')), 2));
        $this->assertNotNull($t['kpis']['dias_cobro']);
        $this->get('/contable/indicadores?mes=2026-04')->assertOk()->assertInertia(fn($a) => $a->component('Contable/Indicadores', false)->where('tablero.periodo', 'Abril 2026'));
        $r = app(\App\Mcp\Catalogo::class)->llamar($this->dueno->fresh(), 'indicadores_contador', ['mes' => '2026-04'], 'asistente');
        $this->assertEquals($margen, $r['margen_bruto']); $this->assertCount(6, $r['ultimos_meses']);
        $this->assertContains('posicion_iva', array_column(collect($this->get('/contable')->viewData('page')['props']['subnav']['items'])->where('grupo', 'Para el contador')->all(), 'key'));
    }
}
