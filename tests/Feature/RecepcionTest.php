<?php

namespace Tests\Feature;

use App\Models\CodigoPistola;
use App\Models\Comprobante;
use App\Models\Recepcion;
use App\Models\ReclamoCompra;
use App\Services\Compras\RecepcionService;
use App\Support\Gs1;
use Tests\ErpTestCase;

// Fase 31.5: recepción con pistola. Contar contra la factura, multiplicador, códigos internos aprendidos, GS1-128 con lote y
// vencimiento, lo que no está en la factura, diferencias renglón por renglón, reclamos que cierra la nota de crédito y el control exigido.
class RecepcionTest extends ErpTestCase
{
    private function compra(array $items, bool $registrar = false): Comprobante
    {
        $prov = $this->proveedor(['cuit' => '30-50000000-1', 'mobile' => '11 5555-1234']);
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-0000' . rand(1000, 9999), 'fecha' => today()->toDateString(), 'registrar' => $registrar, 'condicion' => 'cta_cte',
            'items' => array_map(fn($i) => ['product_id' => $i[0]?->id, 'descripcion' => $i[0]?->name ?? $i[2], 'cantidad' => $i[1], 'precio_unit' => 100, 'alicuota_iva' => 21], $items)])->assertSessionHasNoErrors();
        return Comprobante::compras()->orderByDesc('id')->first();
    }

    public function test_gs1_128_trae_gtin_lote_vencimiento_y_cantidad(): void
    {
        $g = Gs1::parse("]C1010779123456789317260200" . "\x1d" . "10L55" . "\x1d" . "3012");
        $this->assertSame('7791234567893', $g['gtin']); $this->assertSame('2026-02-28', $g['vencimiento']); $this->assertSame('L55', $g['lote']); $this->assertEquals(12, $g['cantidad']);
        $this->assertNull(Gs1::parse('7791234567893')); // un EAN común no es GS1-128
        $this->assertNull(Gs1::parse('ABC-123'));
    }

    public function test_la_pistola_cuenta_contra_la_factura_con_multiplicador_y_la_barra_avanza(): void
    {
        $a = $this->articulo(['name' => 'Aceite 900', 'barcode' => '7791234567893', 'stock_inicial' => 0]);
        $b = $this->articulo(['name' => 'Yerba 1kg', 'sku' => 'YER1', 'stock_inicial' => 0]);
        $c = $this->compra([[$a, 24], [$b, 2]]);
        $this->get("/proveedores/compras/{$c->id}/recepcion")->assertOk()->assertInertia(fn($p) => $p->component('Proveedores/Recepcion', false)->where('recepcion.avance', 0)->where('recepcion.estado', 'abierta'));
        $r = Recepcion::where('comprobante_id', $c->id)->first();
        // ×12 dos veces = 24: el renglón queda en verde
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => '7791234567893', 'mult' => 12])->assertOk()->assertJsonPath('estado', 'ok')->assertJsonPath('contado', 12);
        $res = $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => '7791234567893', 'mult' => 12])->assertOk()->assertJsonPath('contado', 24)->json();
        $this->assertSame('ok', collect($res['recepcion']['items'])->firstWhere('product_id', $a->id)['estado']);
        $this->assertSame(92, $res['recepcion']['avance']);
        // uno de más: sobra (suena mal)
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => '7791234567893'])->assertJsonPath('estado', 'sobra');
        // el SKU también sirve como código; la cantidad a mano reemplaza, no suma
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => 'YER1'])->assertJsonPath('estado', 'ok')->assertJsonPath('contado', 1);
        $it = $r->items()->where('product_id', $b->id)->first();
        $res = $this->postJson("/proveedores/compras/{$c->id}/recepcion/contar", ['item_id' => $it->id, 'contado' => 2])->assertOk()->json();
        $this->assertTrue($res['recepcion']['completa']);
        // se retoma: volver a abrir la misma compra devuelve el mismo control, con lo contado
        $this->get("/proveedores/compras/{$c->id}/recepcion")->assertInertia(fn($p) => $p->where('recepcion.id', $r->id)->where('recepcion.lecturas', 4));
    }

    public function test_un_codigo_que_no_esta_se_asigna_y_queda_aprendido_y_el_gs1_anota_lote_y_vencimiento(): void
    {
        $a = $this->articulo(['name' => 'Queso cremoso', 'barcode' => '7790000000012', 'perecedero' => true, 'stock_inicial' => 0]);
        $otro = $this->articulo(['name' => 'Otro artículo', 'barcode' => '7790000000999', 'stock_inicial' => 0]);
        $c = $this->compra([[$a, 3]]);
        $r = Recepcion::where('comprobante_id', $c->id)->first() ?? app(RecepcionService::class)->abrir($c);
        // código interno de la caja: desconocido
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => 'CAJA-77'])->assertOk()->assertJsonPath('estado', 'desconocido');
        // es de un artículo que no está en la factura
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => '7790000000999'])->assertJsonPath('estado', 'no_esta')->assertJsonPath('producto.id', $otro->id);
        // «Es éste»: cuenta y aprende; la próxima lectura lo reconoce sola
        $it = $r->items()->first();
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/asignar", ['codigo' => 'CAJA-77', 'item_id' => $it->id])->assertOk()->assertJsonPath('estado', 'ok')->assertJsonPath('contado', 1);
        $this->assertDatabaseHas('codigos_pistola', ['business_id' => $this->empresa->id, 'codigo' => 'CAJA-77', 'product_id' => $a->id]);
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => 'CAJA-77'])->assertJsonPath('estado', 'ok')->assertJsonPath('contado', 2);
        // GS1-128 del bulto: GTIN-14 del EAN de la ficha, lote y vencimiento se anotan solos
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => "01077900000000121726113010LOTE9"])->assertJsonPath('estado', 'ok')->assertJsonPath('contado', 3)->assertJsonPath('lote', 'LOTE9')->assertJsonPath('vencimiento', '2026-11-30');
        // al cerrar sin diferencias, el renglón de la compra queda con el lote y el vencimiento y al registrar entra como lote
        $this->post("/proveedores/compras/{$c->id}/recepcion/cerrar", [])->assertRedirect("/proveedores/compras/{$c->id}")->assertSessionHas('success', 'Control terminado: llegó todo lo facturado.');
        $ci = $c->items()->first(); $this->assertSame('LOTE9', $ci->lote); $this->assertSame('2026-11-30', (string) $ci->vencimiento); $this->assertEquals(3, $ci->cantidad_recibida);
        $this->assertSame(0, ReclamoCompra::count());
        $this->post("/proveedores/compras/{$c->id}/registrar")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('lotes', ['product_id' => $a->id, 'lote' => 'LOTE9', 'cantidad' => 3]);
    }

    public function test_diferencias_renglon_por_renglon_entra_lo_contado_y_queda_el_reclamo_que_cierra_la_nota_de_credito(): void
    {
        $a = $this->articulo(['name' => 'Harina', 'barcode' => '7790000000029', 'stock_inicial' => 10]);
        $b = $this->articulo(['name' => 'Azúcar', 'barcode' => '7790000000036', 'stock_inicial' => 10]);
        $d = $this->articulo(['name' => 'Sal', 'barcode' => '7790000000043', 'stock_inicial' => 10]);
        $c = $this->compra([[$a, 10], [$b, 5], [$d, 4]]);
        $r = app(RecepcionService::class)->abrir($c);
        $ia = $r->items()->where('product_id', $a->id)->first(); $ib = $r->items()->where('product_id', $b->id)->first(); $id = $r->items()->where('product_id', $d->id)->first();
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => '7790000000029', 'mult' => 6]); // 6 de 10
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => '7790000000036', 'mult' => 6]); // 6 de 5: sobra
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/contar", ['item_id' => $id->id, 'contado' => 2]); // 2 de 4
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/agregar", ['descripcion' => 'Caja de fideos que no estaba', 'cantidad' => 1])->assertOk();
        // harina: entra lo contado · azúcar: entra lo facturado · sal: pendiente
        $this->post("/proveedores/compras/{$c->id}/recepcion/cerrar", ['resoluciones' => [$ia->id => 'contado', $ib->id => 'facturado', $id->id => 'pendiente']])->assertRedirect("/proveedores/compras/{$c->id}")->assertSessionHas('success', 'Control terminado: quedó un reclamo al proveedor listo para mandar.');
        $c->refresh();
        $this->assertEquals(6, $c->items->firstWhere('product_id', $a->id)->cantidad_recibida);
        $this->assertEquals(5, $c->items->firstWhere('product_id', $b->id)->cantidad_recibida);
        $this->assertEquals(2, $c->items->firstWhere('product_id', $d->id)->cantidad_recibida);
        // al registrar entra lo recibido, no lo facturado; la cuenta corriente sigue por el total de la factura
        $this->post("/proveedores/compras/{$c->id}/registrar")->assertSessionHasNoErrors();
        $this->assertEquals(16, $a->fresh()->stock); $this->assertEquals(15, $b->fresh()->stock); $this->assertEquals(12, $d->fresh()->stock);
        $this->assertEquals($c->fresh()->total, $c->fresh()->saldo);
        $rc = ReclamoCompra::first();
        $this->assertSame('abierto', $rc->estado); $this->assertCount(4, $rc->detalle); // harina faltó, azúcar sobró (entró facturado, se avisa igual), sal pendiente, fideos no facturado
        $tipos = collect($rc->detalle)->pluck('tipo')->sort()->values()->all();
        $this->assertSame(['falto', 'no_facturado', 'pendiente'], array_values(array_diff($tipos, ['sobro'])));
        $this->assertStringContainsString('Harina: facturado 10, llegó 6 (faltan 4)', $rc->texto);
        $this->assertStringContainsString('no está facturado', $rc->texto);
        $this->get("/proveedores/compras/{$c->id}")->assertInertia(fn($p) => $p->where('recepcion.estado', 'cerrada')->where('reclamos.0.estado', 'abierto')->where('reclamos.0.telefono', '1155551234'));
        $this->getJson('/proveedores/compras/reclamos')->assertOk()->assertJsonCount(1)->assertJsonPath('0.proveedor', $c->contact->name);
        // la nota de crédito de esa factura cierra el reclamo sola
        $this->post("/proveedores/compras/{$c->id}/nota-credito")->assertRedirect();
        $nc = Comprobante::compras()->orderByDesc('id')->first();
        $this->post("/proveedores/compras/{$nc->id}", ['contact_id' => $c->contact_id, 'tipo' => $nc->tipo, 'numero_proveedor' => '0001-00000777', 'fecha' => today()->toDateString(), 'registrar' => true, 'condicion' => 'cta_cte', 'origen_id' => $c->id,
            'items' => [['product_id' => $a->id, 'descripcion' => 'Harina', 'cantidad' => 4, 'precio_unit' => 100, 'alicuota_iva' => 21]]])->assertSessionHasNoErrors();
        $rc->refresh();
        $this->assertSame('resuelto', $rc->estado); $this->assertStringContainsString('Nota de crédito', $rc->resolucion); $this->assertSame($nc->id, $rc->nota_credito_id);
        $this->getJson('/proveedores/compras/reclamos')->assertJsonCount(0);
    }

    public function test_compra_ya_registrada_se_controla_y_el_stock_se_corrige_y_el_reclamo_se_resuelve_a_mano(): void
    {
        $a = $this->articulo(['name' => 'Lavandina', 'barcode' => '7790000000050', 'stock_inicial' => 0]);
        $c = $this->compra([[$a, 10]], true);
        $this->assertEquals(10, $a->fresh()->stock);
        $r = app(RecepcionService::class)->abrir($c);
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => '7790000000050', 'mult' => 6]);
        $this->post("/proveedores/compras/{$c->id}/recepcion/cerrar", ['todas' => 'contado'])->assertRedirect();
        $this->assertEquals(6, $a->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $a->id, 'quantity' => 4, 'type' => 'out']);
        $rc = ReclamoCompra::first();
        $this->postJson("/proveedores/compras/reclamos/{$rc->id}/resolver", ['como' => 'mandaron las 4 que faltaban'])->assertOk();
        $this->assertSame('resuelto', $rc->fresh()->estado);
        $this->postJson("/proveedores/compras/reclamos/{$rc->id}/resolver", ['como' => 'otra vez'])->assertNotFound();
    }

    public function test_exigir_el_control_frena_el_registro_hasta_que_se_cuente(): void
    {
        $a = $this->articulo(['name' => 'Detergente', 'barcode' => '7790000000067', 'stock_inicial' => 0]);
        $this->post('/proveedores/compras/config', ['exigir_control' => true])->assertSessionHas('success');
        $c = $this->compra([[$a, 2]]);
        $this->get('/proveedores/compras')->assertInertia(fn($p) => $p->where('exigeControl', true));
        $this->post("/proveedores/compras/{$c->id}/registrar")->assertStatus(422);
        $this->assertSame('borrador', $c->fresh()->estado);
        app(RecepcionService::class)->abrir($c);
        $this->postJson("/proveedores/compras/{$c->id}/recepcion/leer", ['codigo' => '7790000000067', 'mult' => 2])->assertJsonPath('estado', 'ok');
        $this->post("/proveedores/compras/{$c->id}/recepcion/cerrar", [])->assertRedirect();
        $this->post("/proveedores/compras/{$c->id}/registrar")->assertSessionHasNoErrors();
        $this->assertSame('emitido', $c->fresh()->estado);
        // una nota de crédito no pide control
        $prov = $c->contact;
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'NCA', 'numero_proveedor' => '0001-00000002', 'fecha' => today()->toDateString(), 'registrar' => true, 'condicion' => 'cta_cte', 'origen_id' => $c->id, 'items' => [['product_id' => $a->id, 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 100, 'alicuota_iva' => 21]]])->assertSessionHasNoErrors();
    }
}
