<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AutorizacionDescuento;
use App\Models\Comprobante;
use App\Models\Vendedor;
use App\Models\Visita;
use Tests\ErpTestCase;

// Fase 29: Comprobantes → Vender. Modos calle / mostrador / caja, ruta del día, ficha del cliente, crédito y trabajo sin señal.
class VenderCalleTest extends ErpTestCase
{
    private function vendedor(): array
    {
        $u = $this->usuarioConRol('vendedor');
        $v = Vendedor::create(['business_id' => $this->empresa->id, 'user_id' => $u->id, 'nombre' => 'Vito', 'comision_venta' => 0, 'comision_cobro' => 0, 'activo' => true, 'modo_default' => 'calle']);
        return [$u, $v];
    }

    public function test_vender_abre_en_el_modo_del_vendedor_y_retail_entra_en_caja(): void
    {
        $this->get('/vender')->assertOk()->assertInertia(fn($p) => $p->component('Comprobantes/Vender', false)->where('modo', 'mostrador')->where('vendedor', null));
        $this->get('/retail')->assertOk()->assertInertia(fn($p) => $p->where('modo', 'caja'));
        [$u, $v] = $this->vendedor();
        $this->actingAs($u)->get('/vender')->assertOk()->assertInertia(fn($p) => $p->where('modo', 'calle')->where('vendedor.nombre', 'Vito')->where('encargado', true)->where('puede.facturar', true));
        $this->actingAs($u)->get('/vender?modo=caja')->assertInertia(fn($p) => $p->where('modo', 'caja'));
        $this->actingAs($this->dueno)->get('/comprobantes')->assertOk();
    }

    public function test_la_ruta_agrupa_por_agenda_ritmo_de_compra_y_sin_historial(): void
    {
        [$u, $v] = $this->vendedor();
        $p = $this->articulo(['stock_inicial' => 100]);
        $hoy = \App\Services\Ventas\VenderService::DIAS[today()->dayOfWeekIso - 1];
        $agendado = $this->cliente(['name' => 'Agendado', 'cuit' => '20-11111111-1', 'vendedor_id' => $v->id, 'visitas' => [$hoy => 'dia']]);
        $nuevo = $this->cliente(['name' => 'Nuevo', 'cuit' => '20-22222222-2', 'vendedor_id' => $v->id]);
        $semanal = $this->cliente(['name' => 'Semanal', 'cuit' => '20-33333333-3', 'vendedor_id' => $v->id]);
        $dejo = $this->cliente(['name' => 'Dejó', 'cuit' => '20-44444444-4', 'vendedor_id' => $v->id]);
        $ajeno = $this->cliente(['name' => 'De otro', 'cuit' => '20-55555555-5', 'vendedor_id' => $v->id + 100]);
        $this->factura($agendado, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]); // la última venta del sistema es hoy
        foreach ([35, 28, 21, 14] as $d) $this->factura($semanal, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['fecha' => today()->subDays($d)->toDateString()]);
        foreach ([120, 113, 106, 99] as $d) $this->factura($dejo, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['fecha' => today()->subDays($d)->toDateString()]);
        $r = $this->actingAs($u)->getJson('/vender/ruta')->assertOk()->json();
        $n = fn($g) => collect($r['grupos'][$g] ?? [])->pluck('nombre')->all();
        $this->assertSame(['Agendado'], $n('agenda'));
        $this->assertContains('Nuevo', $n('sin_historial'));
        $this->assertSame(['Semanal'], $n('toca'), 'Compra cada 7 días y hace 14 que no compra: le toca');
        $this->assertSame(['Dejó'], $n('dejo'));
        $this->assertNotContains('De otro', array_merge(...array_values(array_map($n, array_keys($r['grupos'])))));
        // La agenda se carga desde la ficha del cliente.
        $this->actingAs($this->dueno)->post("/clientes/{$nuevo->id}/agenda", ['visitas' => ['lun' => 'man', 'mar' => 'no']])->assertRedirect();
        $this->assertSame(['lun' => 'man'], $nuevo->fresh()->visitas);
        $this->get("/clientes/{$nuevo->id}")->assertInertia(fn($p) => $p->where('cliente.visitas.lun', 'man'));
    }

    public function test_la_ficha_trae_lo_que_suele_llevar_el_sugerido_y_las_condiciones(): void
    {
        [$u, $v] = $this->vendedor();
        $a = $this->articulo(['name' => 'Agua', 'stock_inicial' => 100]); $b = $this->articulo(['name' => 'Gaseosa', 'stock_inicial' => 100]);
        $c = $this->cliente(['vendedor_id' => $v->id, 'descuento' => 10]);
        for ($i = 5; $i >= 1; $i--) $this->factura($c, [['product_id' => $a->id, 'cantidad' => 6, 'precio_unit' => 100]], ['fecha' => today()->subDays($i * 7)->toDateString()]);
        $this->factura($c, [['product_id' => $b->id, 'cantidad' => 1, 'precio_unit' => 50]], ['fecha' => today()->subDay()->toDateString()]);
        $f = $this->actingAs($u)->getJson("/vender/cliente/{$c->id}")->assertOk()->json();
        $this->assertSame($a->id, $f['sugerido'][0]['product_id']); $this->assertEquals(6, $f['sugerido'][0]['cantidad']);
        $this->assertEquals(10, $f['condiciones']['descuento']);
        $this->assertSame($b->id, $f['ultimo']['items'][0]['product_id']);
        $this->assertSame($a->id, $f['suele_llevar'][0]['product_id']);
    }

    public function test_en_la_calle_arranca_como_presupuesto_y_con_la_config_sale_factura_emitida(): void
    {
        [$u, $v] = $this->vendedor();
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 10]); $c = $this->cliente(['vendedor_id' => $v->id, 'descuento' => 10]);
        $items = [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 100, 'descuento' => 10]];
        $r = $this->actingAs($u)->postJson('/vender/vender', ['modo' => 'calle', 'contact_id' => $c->id, 'items' => $items, 'lat' => -34.6, 'lng' => -58.4])->assertOk()->json();
        $this->assertTrue($r['presupuesto']); $this->assertSame('borrador', $r['estado']);
        $cmp = Comprobante::find($r['comprobante_id']);
        $this->assertSame('PRE', $cmp->tipo); $this->assertSame('calle', $cmp->modo_venta); $this->assertSame($v->id, $cmp->vendedor_id); $this->assertEqualsWithDelta(-34.6, $cmp->lat, 0.001);
        $this->assertEqualsWithDelta(10, (float) $p->fresh()->stock, 0.001, 'El presupuesto no mueve stock');
        $this->assertSame(1, Visita::where('contact_id', $c->id)->where('tipo', 'venta')->count());
        $this->assertEqualsWithDelta(-34.6, $c->fresh()->lat, 0.001, 'Aprende dónde queda el cliente');
        // El encargado decide que en la calle se factura y se emite en el momento.
        $this->actingAs($this->dueno)->post('/clientes/vendedores/calle', ['calle_comprobante' => 'FX', 'calle_emite' => true, 'tope_descuento' => 15, 'dias_mora' => 5, 'avisar_datos_dias' => 7])->assertRedirect();
        $this->assertSame('FX', $this->empresa->fresh()->ventas_config['calle_comprobante']);
        $r = $this->actingAs($u)->postJson('/vender/vender', ['modo' => 'calle', 'contact_id' => $c->id, 'items' => $items])->assertOk()->json();
        $this->assertFalse($r['presupuesto']); $this->assertSame('emitido', $r['estado']);
        $this->assertEqualsWithDelta(8, (float) $p->fresh()->stock, 0.001);
        $this->assertEqualsWithDelta(2 * 90 * 1.21, $r['total'], 0.01, 'Precio con el descuento pactado del cliente');
        $this->assertAsientosBalancean();
    }

    public function test_el_vendedor_no_cambia_precios_sin_permiso_y_el_tope_de_descuento_pide_el_ok_del_encargado(): void
    {
        [$u, $v] = $this->vendedor();
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 10]); $c = $this->cliente(['vendedor_id' => $v->id]);
        $this->actingAs($this->dueno)->post('/clientes/vendedores/calle', ['calle_comprobante' => 'PRE', 'tope_descuento' => 15])->assertRedirect();
        $linea = fn($desc, $tok = null) => ['modo' => 'calle', 'contact_id' => $c->id, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100, 'descuento' => $desc, 'autorizado' => $tok]]];
        // Hasta el tope, solo.
        $this->actingAs($u)->postJson('/vender/vender', $linea(15))->assertOk();
        // Más del tope: necesita el OK del encargado.
        $this->actingAs($u)->postJson('/vender/vender', $linea(20))->assertStatus(422)->assertJsonValidationErrors('descuento');
        // Un token inventado no sirve; autorizarse a sí mismo tampoco; una clave mala tampoco; un usuario sin permiso de anular tampoco.
        $this->actingAs($u)->postJson('/vender/vender', $linea(20, 'cualquiera'))->assertStatus(422);
        $this->actingAs($u)->postJson('/vender/autorizar', ['contact_id' => $c->id, 'pct' => 20, 'email' => $u->email, 'password' => 'password'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->actingAs($u)->postJson('/vender/autorizar', ['contact_id' => $c->id, 'pct' => 20, 'email' => $this->dueno->email, 'password' => 'mala'])->assertStatus(422)->assertJsonValidationErrors('password');
        $caj = $this->usuarioConRol('cajero');
        $this->actingAs($u)->postJson('/vender/autorizar', ['contact_id' => $c->id, 'pct' => 20, 'email' => $caj->email, 'password' => 'password'])->assertStatus(422)->assertJsonValidationErrors('email');
        // El dueño pone su clave en el teléfono del vendedor.
        $r = $this->actingAs($u)->postJson('/vender/autorizar', ['contact_id' => $c->id, 'pct' => 20, 'email' => $this->dueno->email, 'password' => 'password'])->assertOk()->json();
        $this->assertSame($this->dueno->name, $r['encargado']);
        // No sirve para más porcentaje ni para otro cliente.
        $this->actingAs($u)->postJson('/vender/vender', $linea(25, $r['token']))->assertStatus(422);
        $otro = $this->cliente(['name' => 'Otro', 'cuit' => '20-12345678-6']);
        $this->actingAs($u)->postJson('/vender/vender', ['modo' => 'calle', 'contact_id' => $otro->id, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100, 'descuento' => 20, 'autorizado' => $r['token']]]])->assertStatus(422);
        // Con el OK entra, y se quema: la segunda vez ya no sirve.
        $ok = $this->actingAs($u)->postJson('/vender/vender', $linea(20, $r['token']))->assertOk()->json();
        $a = AutorizacionDescuento::where('token', $r['token'])->first();
        $this->assertSame($ok['comprobante_id'], $a->comprobante_id); $this->assertNotNull($a->usada_at);
        $this->assertTrue(AuditLog::where('accion', 'descuento_autorizado')->exists());
        $this->actingAs($u)->postJson('/vender/vender', $linea(20, $r['token']))->assertStatus(422);
        // Vencida a los 15 minutos.
        $r2 = $this->actingAs($u)->postJson('/vender/autorizar', ['contact_id' => $c->id, 'pct' => 20, 'email' => $this->dueno->email, 'password' => 'password'])->json();
        AutorizacionDescuento::where('token', $r2['token'])->update(['vence_at' => now()->subMinute()]);
        $this->actingAs($u)->postJson('/vender/vender', $linea(20, $r2['token']))->assertStatus(422);
        // El panel del encargado: 2 pedidas, 1 usada.
        $this->actingAs($this->dueno)->get('/clientes/vendedores')->assertInertia(fn($pg) => $pg->where('autorizaciones.pedidas', 2)->where('autorizaciones.usadas', 1)->where('autorizaciones.items.1.cliente', $c->name));
        // Un cajero no puede tocar el precio ni descontar.
        $this->actingAs($caj)->postJson('/vender/vender', ['modo' => 'calle', 'contact_id' => $c->id, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 80]]])->assertStatus(403);
        $this->actingAs($caj)->postJson('/vender/vender', $linea(5))->assertStatus(403);
        $this->actingAs($caj)->postJson('/vender/vender', ['modo' => 'calle', 'contact_id' => $c->id, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]])->assertOk();
    }

    public function test_promos_pactados_y_descuentos_de_lista_no_piden_permiso_ni_cuentan_para_el_tope(): void
    {
        [$u, $v] = $this->vendedor();
        $this->actingAs($this->dueno)->post('/clientes/vendedores/calle', ['calle_comprobante' => 'FX', 'calle_emite' => true, 'tope_descuento' => 10])->assertRedirect();
        $promo = $this->articulo(['price' => 100, 'stock_inicial' => 50, 'desc_cant_min' => 10, 'desc_cant_pct' => 25]);
        $comun = $this->articulo(['price' => 100, 'stock_inicial' => 50]);
        $c = $this->cliente(['vendedor_id' => $v->id, 'descuento' => 5]);
        \App\Models\PrecioPactado::create(['business_id' => $this->empresa->id, 'contact_id' => $c->id, 'product_id' => $comun->id, 'descuento' => 30, 'origen' => 'manual']);
        $caj = $this->usuarioConRol('cajero'); // sin permiso de editar comprobantes
        $r = $this->actingAs($caj)->postJson('/vender/vender', ['modo' => 'calle', 'contact_id' => $c->id, 'items' => [
            ['product_id' => $promo->id, 'cantidad' => 10, 'precio_unit' => 100, 'descuento' => 25],  // promo por cantidad del artículo
            ['product_id' => $comun->id, 'cantidad' => 1, 'precio_unit' => 100, 'descuento' => 30],   // pactado con el cliente
        ]])->assertOk()->json();
        $this->assertEqualsWithDelta((10 * 75 + 70) * 1.21, $r['total'], 0.01, 'El mayor entre promo, pactado y general; nunca se suman');
        // Si no manda descuento, el servidor pone el automático igual.
        $r = $this->actingAs($caj)->postJson('/vender/vender', ['modo' => 'calle', 'contact_id' => $c->id, 'items' => [['product_id' => $promo->id, 'cantidad' => 10, 'precio_unit' => 100]]])->assertOk()->json();
        $this->assertEqualsWithDelta(10 * 75 * 1.21, $r['total'], 0.01);
        // Con menos cantidad no hay promo: 25% sería un descuento del vendedor.
        $this->actingAs($caj)->postJson('/vender/vender', ['modo' => 'calle', 'contact_id' => $c->id, 'items' => [['product_id' => $promo->id, 'cantidad' => 2, 'precio_unit' => 100, 'descuento' => 25]]])->assertStatus(403);
    }

    public function test_control_de_credito_avisa_y_se_puede_vender_igual_con_registro(): void
    {
        [$u, $v] = $this->vendedor();
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 10]); $c = $this->cliente(['vendedor_id' => $v->id, 'credit_limit' => 150, 'dias_pago' => 0]);
        $this->factura($c, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['fecha' => today()->subDays(20)->toDateString()]);
        $this->actingAs($this->dueno)->post('/clientes/vendedores/calle', ['calle_comprobante' => 'FX', 'calle_emite' => false])->assertRedirect();
        $datos = ['modo' => 'calle', 'contact_id' => $c->id, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]];
        $r = $this->actingAs($u)->postJson('/vender/vender', $datos)->assertStatus(422)->json();
        $this->assertStringContainsString('límite de crédito', $r['errors']['credito'][0]); $this->assertStringContainsString('vencidos', $r['errors']['credito'][0]);
        $r = $this->actingAs($u)->postJson('/vender/vender', $datos + ['forzar' => true])->assertOk()->json();
        $this->assertSame('borrador', $r['estado'], 'Queda pendiente de emitir en el local'); $this->assertNotNull($r['aviso']);
        $this->assertTrue(AuditLog::where('accion', 'venta_forzada')->exists());
        // Un presupuesto nunca se frena por crédito.
        $this->actingAs($u)->postJson('/vender/vender', ['tipo' => 'PRE'] + $datos)->assertOk();
    }

    public function test_ventas_y_visitas_sin_senal_no_se_duplican(): void
    {
        [$u, $v] = $this->vendedor();
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 10]); $c = $this->cliente(['vendedor_id' => $v->id]);
        $datos = ['modo' => 'calle', 'contact_id' => $c->id, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], 'offline_id' => 'calle-1', 'fecha_offline' => now()->subHours(3)->toDateTimeString()];
        $r1 = $this->actingAs($u)->postJson('/vender/vender', $datos)->assertOk()->json();
        $r2 = $this->actingAs($u)->postJson('/vender/vender', $datos)->assertOk()->json();
        $this->assertSame($r1['comprobante_id'], $r2['comprobante_id']); $this->assertTrue($r2['repetida']);
        $this->assertSame(1, Comprobante::where('offline_id', 'calle-1')->count());
        $vis = ['tipo' => 'no_vendi', 'motivo' => 'cerrado', 'offline_id' => 'vis-1'];
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/visita", $vis)->assertOk();
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/visita", $vis)->assertOk()->assertJsonPath('repetida', true);
        $this->assertSame(1, Visita::where('tipo', 'no_vendi')->count());
        $f = $this->actingAs($u)->getJson("/vender/cliente/{$c->id}")->json();
        $this->assertSame('Estaba cerrado', $f['visitas'][0]['motivo']);
        // El paquete para trabajar sin señal trae catálogo, ruta y la config.
        $pq = $this->actingAs($u)->getJson('/vender/paquete')->assertOk()->json();
        $this->assertNotEmpty($pq['productos']); $this->assertArrayHasKey('grupos', $pq['ruta']); $this->assertSame('PRE', $pq['config']['calle_comprobante']);
    }

    public function test_mostrador_y_caja_venden_de_contado_por_el_pos_y_sellan_el_modo(): void
    {
        $p = $this->articulo(['price' => 121, 'stock_inicial' => 10]);
        $datos = ['items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100, 'descuento' => 0, 'alicuota_iva' => 21]], 'medios' => [['medio' => 'efectivo', 'monto' => 121]], 'precios_con_iva' => false];
        $r = $this->postJson('/vender/vender', $datos + ['modo' => 'mostrador'])->assertOk()->json();
        $this->assertSame('emitido', $r['estado']); $this->assertSame('mostrador', Comprobante::find($r['comprobante_id'])->modo_venta);
        $r = $this->postJson('/retail/vender', $datos)->assertOk()->json();
        $this->assertSame('caja', Comprobante::find($r['comprobante_id'])->modo_venta);
        $this->assertEqualsWithDelta(8, (float) $p->fresh()->stock, 0.001);
        // Sin permiso de comprobantes no se vende; otra empresa no ve a estos clientes.
        $this->actingAs($this->usuarioConRol('deposito'))->postJson('/vender/vender', $datos)->assertStatus(403);
        [$otra, $sucB, $duenoB] = $this->crearEmpresa('Otra', 'otra'); $c = $this->cliente();
        $this->actingAs($duenoB)->getJson("/vender/cliente/{$c->id}")->assertNotFound();
    }
}
