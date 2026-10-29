<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\CuentaFondos;
use App\Models\RendicionCalle;
use App\Models\Vendedor;
use Tests\ErpTestCase;

// Fase 29.5: cobrar en la calle (recibo firmado, plata "en mano" hasta rendir), rendición recibida por el encargado,
// devoluciones con motivo y confirmación de entrega.
class CobranzaCalleTest extends ErpTestCase
{
    private function vendedor(): array
    {
        $u = $this->usuarioConRol('vendedor');
        $v = Vendedor::create(['business_id' => $this->empresa->id, 'user_id' => $u->id, 'nombre' => 'Vito', 'comision_venta' => 0, 'comision_cobro' => 2, 'activo' => true, 'modo_default' => 'calle']);
        return [$u, $v];
    }

    public function test_cobrar_en_la_calle_baja_la_deuda_pero_la_plata_queda_a_nombre_del_vendedor_hasta_rendir(): void
    {
        [$u, $v] = $this->vendedor();
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 50]); $c = $this->cliente(['vendedor_id' => $v->id, 'dias_pago' => 0]);
        $f1 = $this->factura($c, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['fecha' => today()->subDays(30)->toDateString()]);
        $f2 = $this->factura($c, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 100]]);
        $this->assertEqualsWithDelta(363, $c->fresh()->balance, 0.01);
        $r = $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/cobrar", ['medios' => [['medio' => 'efectivo', 'monto' => 200], ['medio' => 'transferencia', 'monto' => 50]], 'firma' => 'data:image/png;base64,AAAA', 'firma_nombre' => 'Juan', 'lat' => -34.6, 'lng' => -58.4, 'offline_id' => 'cob-1'])->assertOk()->json();
        $this->assertSame('REC 00000001', $r['numero']); $this->assertEqualsWithDelta(113, $r['saldo'], 0.01, 'La deuda baja en el momento');
        // Imputado a la factura más vieja primero.
        $this->assertEqualsWithDelta(0, $f1->fresh()->saldo, 0.01); $this->assertEqualsWithDelta(113, $f2->fresh()->saldo, 0.01);
        $cobro = Cobro::find($r['cobro_id']);
        $this->assertSame('calle', $cobro->modo); $this->assertSame('Juan', $cobro->firma_nombre); $this->assertSame($v->id, $cobro->cobrador_id);
        // La plata está en la caja "En mano · Vito", no en la caja del local.
        $enMano = CuentaFondos::where('vendedor_id', $v->id)->first();
        $this->assertNotNull($enMano); $this->assertEqualsWithDelta(250, $enMano->saldo, 0.01);
        $this->assertEqualsWithDelta(0, $this->caja->fresh()->saldo - $this->caja->saldo, 0.01);
        // Repetido sin señal: no entra dos veces.
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/cobrar", ['medios' => [['medio' => 'efectivo', 'monto' => 200]], 'offline_id' => 'cob-1'])->assertOk()->assertJsonPath('repetida', true);
        $this->assertSame(1, Cobro::count());
        // El vendedor no puede anular un cobro.
        $this->actingAs($u)->post("/clientes/cobros/{$cobro->id}/anular", ['motivo' => 'x'])->assertStatus(403);
        // Lo de hoy.
        $h = $this->actingAs($u)->getJson('/vender/hoy')->assertOk()->json();
        $this->assertEqualsWithDelta(200, $h['en_mano']['efectivo'], 0.01); $this->assertEqualsWithDelta(50, $h['en_mano']['transferencias'], 0.01); $this->assertCount(1, $h['cobros']); $this->assertTrue($h['cobros'][0]['firmado']);
        $this->assertAsientosBalancean();
    }

    public function test_cheque_repetido_avisa_y_la_rendicion_la_recibe_el_encargado_con_la_diferencia(): void
    {
        [$u, $v] = $this->vendedor();
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 50]); $c = $this->cliente(['vendedor_id' => $v->id]);
        $this->factura($c, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 100]]);
        $chq = ['medio' => 'cheque', 'monto' => 300, 'datos' => ['numero' => '12345', 'banco' => 'Banco Galicia', 'fecha_pago' => today()->addDays(30)->toDateString(), 'titular' => 'Cliente RI']];
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/cobrar", ['medios' => [$chq, ['medio' => 'efectivo', 'monto' => 400]]])->assertOk();
        $this->assertSame('cartera', Cheque::where('numero', '12345')->first()->estado);
        // El mismo cheque otra vez: avisa; con "es otro" entra.
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/cobrar", ['medios' => [$chq]])->assertStatus(422)->assertJsonValidationErrors('cheque_repetido');
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/cobrar", ['medios' => [$chq], 'forzar_cheque' => true])->assertOk();
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/cobrar", ['medios' => [['medio' => 'tarjeta', 'monto' => 10]]])->assertStatus(422);
        // Nada que rendir dos veces: cierra el turno.
        $r = $this->actingAs($u)->postJson('/vender/rendir')->assertOk()->json();
        $this->assertEqualsWithDelta(400, $r['efectivo'], 0.01); $this->assertEqualsWithDelta(600, $r['cheques'], 0.01); $this->assertSame(2, $r['recibos']);
        $this->actingAs($u)->postJson('/vender/rendir')->assertStatus(422);
        $this->assertSame(2, Cobro::whereNotNull('rendicion_id')->count());
        $rend = RendicionCalle::first(); $this->assertSame('rendida', $rend->estado);
        // El encargado la ve y la recibe: contó 390 (faltan 10).
        $this->actingAs($this->dueno)->get('/clientes/vendedores')->assertInertia(fn($pg) => $pg->where('rendiciones.pendientes.0.numero', 'RC 000001')->where('rendiciones.pendientes.0.vendedor', 'Vito'));
        $cajaAntes = (float) $this->caja->fresh()->saldo;
        $this->actingAs($this->dueno)->post("/clientes/vendedores/rendiciones/{$rend->id}/recibir", ['caja_id' => $this->caja->id, 'efectivo_contado' => 390])->assertRedirect();
        $rend->refresh();
        $this->assertSame('recibida', $rend->estado); $this->assertEqualsWithDelta(-10, $rend->diferencia, 0.01); $this->assertSame($this->dueno->id, $rend->recibida_por);
        $this->assertEqualsWithDelta($cajaAntes + 390, (float) $this->caja->fresh()->saldo, 0.01, 'El efectivo contado entra a la caja del local');
        $this->assertEqualsWithDelta(0, CuentaFondos::where('vendedor_id', $v->id)->first()->saldo, 0.01, 'En mano queda en cero: el faltante se registró');
        $this->actingAs($this->dueno)->post("/clientes/vendedores/rendiciones/{$rend->id}/recibir", ['caja_id' => $this->caja->id])->assertSessionHasErrors('rendir');
        $this->assertTrue(AuditLog::where('accion', 'recibir_rendicion')->exists());
        // La comisión por cobranza es del vendedor que cobró.
        $liq = app(\App\Services\Comprobantes\ComisionesService::class)->liquidar(today()->toDateString(), today()->toDateString(), $v->id);
        $this->assertEqualsWithDelta(1000 * 0.02, collect($liq)->first()['com_cobro'], 0.01);
        $this->assertAsientosBalancean();
    }

    public function test_devolucion_desde_la_calle_sale_como_nota_de_credito_con_motivo_y_resta_de_la_comision(): void
    {
        [$u, $v] = $this->vendedor();
        $v->update(['comision_venta' => 10]);
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 50]); $c = $this->cliente(['vendedor_id' => $v->id]);
        $this->factura($c, [['product_id' => $p->id, 'cantidad' => 5, 'precio_unit' => 100]], ['vendedor_id' => $v->id]);
        $this->actingAs($this->dueno)->post('/clientes/vendedores/calle', ['calle_comprobante' => 'FX', 'calle_emite' => true])->assertRedirect();
        $r = $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/devolucion", ['items' => [['product_id' => $p->id, 'cantidad' => 2]], 'motivo' => 'fallado', 'nota' => 'rotos'])->assertOk()->json();
        $nc = Comprobante::find($r['comprobante_id']);
        $this->assertSame('NCA', $nc->tipo); $this->assertSame('emitido', $nc->estado); $this->assertSame('fallado', $nc->motivo_devolucion); $this->assertStringContainsString('Vino fallado', $nc->notas);
        $this->assertEqualsWithDelta(47, (float) $p->fresh()->stock, 0.001, 'Vuelve al stock');
        $this->assertEqualsWithDelta(500 * 1.21 - 200 * 1.21, $c->fresh()->balance, 0.01);
        $liq = collect(app(\App\Services\Comprobantes\ComisionesService::class)->liquidar(today()->toDateString(), today()->toDateString(), $v->id))->first();
        $this->assertEqualsWithDelta(300 * 0.10, $liq['com_venta'], 0.01, 'La devolución se resta de la comisión');
        // Sin permiso de comprobantes no se devuelve.
        $this->actingAs($this->usuarioConRol('deposito'))->postJson("/vender/cliente/{$c->id}/devolucion", ['items' => [['product_id' => $p->id, 'cantidad' => 1]], 'motivo' => 'otro'])->assertStatus(403);
        $this->assertAsientosBalancean();
    }

    public function test_confirmacion_de_entrega_con_nombre_firma_y_foto(): void
    {
        [$u, $v] = $this->vendedor();
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 50]); $c = $this->cliente(['vendedor_id' => $v->id]);
        $f = $this->factura($c, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['entrega_pendiente' => true]);
        $this->actingAs($u)->postJson("/vender/comprobante/{$f->id}/entrega", ['firma' => 'x'])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $png = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\nfoto");
        $this->actingAs($u)->postJson("/vender/comprobante/{$f->id}/entrega", ['nombre' => 'María Pérez', 'firma' => 'data:image/png;base64,AAAA', 'foto' => $png])->assertOk()->assertJsonPath('entregado.nombre', 'María Pérez');
        $f->refresh();
        $this->assertNotNull($f->entregado_at); $this->assertSame($u->id, $f->entregado_por); $this->assertNotNull($f->entrega_foto);
        $this->assertEqualsWithDelta(0, $f->pendienteEntrega(), 0.001, 'La factura con entrega pendiente queda entregada');
        $this->actingAs($u)->get("/vender/comprobante/{$f->id}/entrega/foto")->assertOk();
        $this->actingAs($this->dueno)->get("/comprobantes/{$f->id}")->assertInertia(fn($pg) => $pg->where('c.entrega.nombre', 'María Pérez'));
        // Un presupuesto no se "entrega".
        $pre = app(\App\Services\Comprobantes\ComprobanteService::class)->guardarBorrador(['contact_id' => $c->id, 'tipo' => 'PRE', 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]]);
        $this->actingAs($u)->postJson("/vender/comprobante/{$pre->id}/entrega", ['nombre' => 'X'])->assertStatus(422);
        // Otra empresa no la ve.
        [$otra, $sucB, $duenoB] = $this->crearEmpresa('Otra', 'otra');
        $this->actingAs($duenoB)->get("/vender/comprobante/{$f->id}/entrega/foto")->assertNotFound();
        \Illuminate\Support\Facades\Storage::disk('local')->delete($f->entrega_foto);
    }
}
