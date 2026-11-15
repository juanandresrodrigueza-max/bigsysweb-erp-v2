<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\DespachoImportacion;
use App\Models\OrdenCompra;
use App\Models\PedidoWeb;
use Tests\ErpTestCase;

// Fase 97 · Lo de Tango en compras y stock: autorización de órdenes por monto, stock comprometido y disponible real,
// despachos de importación con costo nacionalizado.
class Fase97Test extends ErpTestCase
{
    public function test_orden_grande_espera_autorizacion_y_el_dueno_la_autoriza_o_rechaza(): void
    {
        $prov = $this->proveedor(); $art = $this->articulo(['name' => 'Cemento', 'cost' => 1000]);
        $this->post('/proveedores/ordenes/config', ['autoriza_desde' => 50000, 'autorizadores' => []])->assertSessionHas('success');
        $comprador = $this->usuarioConRol('compras');

        // El comprador manda una orden chica: sale directo. Una grande: espera autorización.
        $this->actingAs($comprador)->post('/proveedores/ordenes', ['contact_id' => $prov->id, 'fecha' => today()->toDateString(), 'enviar' => true, 'items' => [['product_id' => $art->id, 'cantidad' => 10, 'precio_unit' => 1000]]]);
        $chica = OrdenCompra::latest('id')->first(); $this->assertSame('enviada', $chica->estado); $this->assertNull($chica->autorizacion);
        $this->actingAs($comprador)->post('/proveedores/ordenes', ['contact_id' => $prov->id, 'fecha' => today()->toDateString(), 'enviar' => true, 'items' => [['product_id' => $art->id, 'cantidad' => 100, 'precio_unit' => 1000]]]);
        $grande = OrdenCompra::latest('id')->first();
        $this->assertSame('borrador', $grande->estado); $this->assertSame('pendiente', $grande->autorizacion);
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('tipo', 'oc_por_autorizar')->where('modelo_id', $grande->id)->exists());
        $this->actingAs($comprador)->get("/proveedores/ordenes/{$grande->id}/recibir")->assertStatus(422);
        $this->actingAs($comprador)->post("/proveedores/ordenes/{$grande->id}/autorizar")->assertForbidden();
        $this->assertSame(1, $this->actingAs($comprador)->get('/proveedores/ordenes')->viewData('page')['props']['autorizacion']['pendientes']);

        // El dueño la ve pendiente, la autoriza: queda enviada y la alerta resuelta.
        $this->actingAs($this->dueno);
        $r = app(\App\Mcp\Catalogo::class)->llamar($this->dueno, 'ordenes_por_autorizar', [], 'asistente');
        $this->assertCount(1, $r['pendientes']); $this->assertTrue($r['podes_autorizar']);
        $this->post("/proveedores/ordenes/{$grande->id}/autorizar", ['nota' => 'Dale'])->assertSessionHas('success');
        $grande->refresh(); $this->assertSame('enviada', $grande->estado); $this->assertSame('autorizada', $grande->autorizacion); $this->assertSame($this->dueno->id, $grande->autorizada_por);
        $this->assertNotNull(Alerta::withoutGlobalScopes()->where('tipo', 'oc_por_autorizar')->where('modelo_id', $grande->id)->value('resuelta_en'));
        // Si la manda el dueño, sale directo aunque sea grande. Y un rechazo con motivo vuelve a borrador.
        $this->post('/proveedores/ordenes', ['contact_id' => $prov->id, 'fecha' => today()->toDateString(), 'enviar' => true, 'items' => [['product_id' => $art->id, 'cantidad' => 200, 'precio_unit' => 1000]]]);
        $this->assertSame('enviada', OrdenCompra::latest('id')->first()->estado);
        $this->actingAs($comprador)->post('/proveedores/ordenes', ['contact_id' => $prov->id, 'fecha' => today()->toDateString(), 'enviar' => true, 'items' => [['product_id' => $art->id, 'cantidad' => 300, 'precio_unit' => 1000]]]);
        $otra = OrdenCompra::latest('id')->first();
        $cat = app(\App\Mcp\Catalogo::class);
        $p = $cat->llamar($this->dueno->fresh(), 'autorizar_orden', ['orden' => $otra->numero, 'decision' => 'rechazar', 'nota' => 'Precio alto'], 'asistente');
        $this->assertStringContainsString('Rechazar', $p['titulo']);
        $cat->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $otra->refresh(); $this->assertSame('rechazada', $otra->autorizacion); $this->assertSame('borrador', $otra->estado); $this->assertSame('Precio alto', $otra->autorizacion_nota);
    }

    public function test_stock_comprometido_y_disponible_real(): void
    {
        $art = $this->articulo(['name' => 'Ladrillo', 'stock_inicial' => 100]);
        PedidoWeb::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'numero' => 1, 'canal' => 'whatsapp', 'cliente' => ['nombre' => 'Ana'], 'items' => [['product_id' => $art->id, 'descripcion' => 'Ladrillo', 'cantidad' => 30, 'precio_unit' => 100, 'total' => 3000]], 'subtotal' => 3000, 'total' => 3000, 'estado' => 'confirmado', 'token' => 'tok-97-1']);
        PedidoWeb::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'numero' => 2, 'canal' => 'tienda', 'cliente' => ['nombre' => 'Beto'], 'items' => [['product_id' => $art->id, 'descripcion' => 'Ladrillo', 'cantidad' => 90, 'precio_unit' => 100, 'total' => 9000]], 'subtotal' => 9000, 'total' => 9000, 'estado' => 'entregado', 'token' => 'tok-97-2']); // entregado: no cuenta
        $fila = collect($this->get('/stock')->viewData('page')['props']['lista']['data'])->firstWhere('id', $art->id);
        $this->assertEquals(30, $fila['comprometido']); $this->assertEquals(70, $fila['disponible']);
        $c = $this->get("/stock/{$art->id}")->viewData('page')['props']['comprometido'];
        $this->assertEquals(30, $c['pedidos']); $this->assertEquals(70, $c['disponible']);
        // Bigcito: "¿cuánto puedo vender de ladrillo?" y los faltantes.
        $r = app(\App\Mcp\Catalogo::class)->llamar($this->dueno, 'stock_disponible', ['articulo' => 'Ladrillo'], 'asistente');
        $this->assertEquals(70, $r['disponible']);
        PedidoWeb::create(['business_id' => $this->empresa->id, 'numero' => 3, 'canal' => 'whatsapp', 'cliente' => [], 'items' => [['product_id' => $art->id, 'cantidad' => 100]], 'total' => 0, 'estado' => 'nuevo', 'token' => 'tok-97-3']);
        $r = app(\App\Mcp\Catalogo::class)->llamar($this->dueno->fresh(), 'stock_disponible', [], 'asistente');
        $this->assertCount(1, $r['faltantes']); $this->assertEquals(-30, $r['faltantes'][0]['disponible']);
    }

    public function test_despacho_de_importacion_prorratea_gastos_y_nacionaliza_al_stock(): void
    {
        $prov = $this->proveedor(['name' => 'Shenzhen Tools', 'cuit' => '55-00000000-1', 'condicion_iva' => 'Exterior']);
        $a = $this->articulo(['name' => 'Taladro', 'cost' => 50000, 'stock_inicial' => 0]); $b = $this->articulo(['name' => 'Amoladora', 'cost' => 30000, 'stock_inicial' => 5]);
        $this->get('/proveedores/importaciones')->assertOk()->assertInertia(fn($p) => $p->component('Proveedores/Importaciones', false)->where('resumen.en_curso', 0));
        $this->post('/proveedores/importaciones', ['contact_id' => $prov->id, 'despacho' => '25001IC04000123X', 'fecha' => today()->toDateString(), 'moneda' => 'USD', 'cotizacion' => 1000,
            'items' => [['product_id' => $a->id, 'cantidad' => 10, 'fob_unit' => 30], ['product_id' => $b->id, 'cantidad' => 20, 'fob_unit' => 10]], // FOB: 300.000 + 200.000 = 500.000 pesos
            'gastos' => [['tipo' => 'flete', 'monto' => 60000], ['tipo' => 'derechos', 'monto' => 40000], ['tipo' => 'iva', 'monto' => 105000], ['tipo' => 'percepcion_iva', 'monto' => 20000]]])->assertRedirect();
        $d = DespachoImportacion::latest('id')->first();
        $this->assertSame('borrador', $d->estado); $this->assertEquals(500000, (float) $d->fob_total); $this->assertEquals(100000, (float) $d->gastos_total); $this->assertEquals(125000, (float) $d->credito_fiscal); $this->assertEquals(600000, (float) $d->costo_total);
        $ia = $d->items()->where('product_id', $a->id)->first(); $ib = $d->items()->where('product_id', $b->id)->first();
        $this->assertEquals(60000, (float) $ia->gastos_ars, 'El taladro se lleva el 60 % de los gastos (60 % del FOB)'); $this->assertEquals(36000, (float) $ia->costo_unit_ars); // (300.000 + 60.000) / 10
        $this->assertEquals(40000, (float) $ib->gastos_ars); $this->assertEquals(12000, (float) $ib->costo_unit_ars);   // (200.000 + 40.000) / 20
        $this->assertEquals(0, (float) $a->fresh()->stock, 'Todavía no entró al stock');

        $this->post("/proveedores/importaciones/{$d->id}/nacionalizar")->assertSessionHas('success');
        $d->refresh(); $this->assertSame('nacionalizado', $d->estado); $this->assertNotNull($d->nacionalizado_en);
        $this->assertEquals(10, (float) $a->fresh()->stock); $this->assertEquals(25, (float) $b->fresh()->stock);
        $this->assertEquals(36000, (float) $a->fresh()->cost); $this->assertEquals(12000, (float) $b->fresh()->cost);
        $this->post("/proveedores/importaciones/{$d->id}/nacionalizar")->assertStatus(422);
        // Bigcito: el detalle del despacho por su número de aduana.
        $r = app(\App\Mcp\Catalogo::class)->llamar($this->dueno, 'importaciones_en_curso', ['despacho' => '25001IC04000123X'], 'asistente');
        $this->assertEquals(1.2, $r['coeficiente']); $this->assertCount(2, $r['items']);
        $this->assertSame(1, app(\App\Mcp\Catalogo::class)->llamar($this->dueno->fresh(), 'importaciones_en_curso', [], 'asistente')['nacionalizados_anio']);
    }
}
