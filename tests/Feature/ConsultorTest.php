<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\CotizacionConsultor;
use App\Models\ProveedorArticulo;
use Tests\ErpTestCase;

// Fase 32.4–32.6: el Consultor de precios (empleados, comprador, pantalla del salón), la cotización por WhatsApp y el ticket de 58 mm.
class ConsultorTest extends ErpTestCase
{
    public function test_busca_como_te_acordas_con_codigo_exacto_primero_y_el_vendedor_no_ve_costos(): void
    {
        $coca = $this->articulo(['name' => 'Coca Cola 2.25 lt', 'sku' => '1001', 'barcode' => '7790895000997', 'cost' => 1000, 'price' => 1500, 'iva' => 21, 'stock_inicial' => 12]);
        $this->articulo(['name' => 'Coca Cola 500 ml', 'sku' => '1002', 'barcode' => '7790895000010', 'cost' => 500, 'price' => 800, 'stock_inicial' => 3]);
        $viejo = $this->articulo(['name' => 'Discontinuado X', 'sku' => '9999', 'barcode' => '7790000009999', 'price' => 100]); $viejo->update(['active' => false]);
        // palabras al revés, cualquier orden
        $r = $this->getJson('/stock/consultor/buscar?q=2.25%20coca')->assertOk()->json();
        $this->assertSame($coca->id, $r['items'][0]['id']); $this->assertEquals(1815, $r['items'][0]['precio']); $this->assertEquals(12, $r['items'][0]['stock']);
        $this->assertArrayHasKey('costo', $r['items'][0]); // el dueño ve costo y margen
        $this->assertEquals(1000, $r['items'][0]['costo']); $this->assertEquals(33.3, $r['items'][0]['margen']);
        // un código exacto siempre aparece primero, aunque esté discontinuado
        $r = $this->getJson('/stock/consultor/buscar?q=7790000009999')->assertOk()->json();
        $this->assertTrue($r['exacto']); $this->assertSame($viejo->id, $r['items'][0]['id']); $this->assertTrue($r['items'][0]['discontinuado']);
        // 3*código: entra con 3
        $r = $this->getJson('/stock/consultor/buscar?q=3*7790895000997')->assertOk()->json();
        $this->assertEquals(3, $r['cantidad']); $this->assertSame($coca->id, $r['items'][0]['id']);
        // un número que no es código de nadie lo dice
        $r = $this->getJson('/stock/consultor/buscar?q=778899')->assertOk()->json();
        $this->assertStringContainsString('No hay ningún artículo con el código 778899', $r['sin_codigo']);
        // el vendedor no ve costo ni margen ni proveedores; con el tilde, sí
        $v = $this->usuarioConRol('vendedor');
        $r = $this->actingAs($v)->getJson('/stock/consultor/buscar?q=coca')->assertOk()->json();
        $this->assertArrayNotHasKey('costo', $r['items'][0]);
        $this->actingAs($v)->get('/stock/consultor')->assertInertia(fn($p) => $p->component('Stock/Consultor', false)->where('veCostos', false)->where('veProveedores', false));
        $this->actingAs($this->dueno)->post("/configuracion/usuarios/{$v->id}", ['name' => $v->name, 'email' => $v->email, 'role_id' => $v->role_id, 'status' => 'active', 've_costos' => true, 've_proveedores' => true, 'sucursales' => [['id' => $this->sucursal->id]]])->assertSessionHasNoErrors();
        $this->actingAs($v->fresh())->getJson('/stock/consultor/buscar?q=coca')->assertOk()->assertJsonPath('items.0.costo', 1000);
        $this->actingAs($v->fresh())->getJson("/stock/consultor/articulo/{$coca->id}")->assertOk()->assertJsonStructure(['proveedores']);
    }

    public function test_el_precio_para_un_cliente_y_a_que_proveedores_comprar(): void
    {
        $p = $this->articulo(['name' => 'Cemento', 'sku' => 'CEM', 'cost' => 1000, 'price' => 1500, 'iva' => 21, 'stock_inicial' => 10]);
        $cli = $this->cliente(['lista_precios' => 1, 'descuento' => 10]);
        $r = $this->getJson("/stock/consultor/articulo/{$p->id}?contact_id={$cli->id}")->assertOk()->json();
        $this->assertEqualsWithDelta(1633.5, $r['cliente']['precio'], 0.01); $this->assertEquals(10, $r['cliente']['descuento']);
        // proveedores: uno aprendido de facturas con precio viejo, dos con compras reales
        $a = $this->proveedor(['name' => 'Loma Negra', 'cuit' => '30-50000000-1']); $b = $this->proveedor(['name' => 'Corralón Mayorista', 'cuit' => '30-60000000-2']);
        ProveedorArticulo::create(['business_id' => $this->empresa->id, 'contact_id' => $a->id, 'codigo' => 'LN-1', 'descripcion' => 'cemento', 'product_id' => $p->id, 'ultimo_costo' => 900, 'ultima_fecha' => '2026-09-01']);
        foreach ([[$a, 950, '2026-10-01', '0001-00000001'], [$b, 1100, '2026-10-15', '0002-00000002']] as [$prov, $precio, $fecha, $num]) {
            $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => $num, 'fecha' => $fecha, 'registrar' => true, 'condicion' => 'cta_cte', 'items' => [['product_id' => $p->id, 'descripcion' => 'Cemento', 'cantidad' => 10, 'precio_unit' => $precio, 'alicuota_iva' => 21]]])->assertSessionHasNoErrors();
        }
        $r = $this->getJson("/stock/consultor/articulo/{$p->id}")->assertOk()->json();
        $this->assertCount(2, $r['proveedores']);
        $this->assertSame('Loma Negra', $r['proveedores'][0]['proveedor']); $this->assertEquals(950, $r['proveedores'][0]['costo']); $this->assertSame('2026-10-01', $r['proveedores'][0]['fecha']); $this->assertEquals(0, $r['proveedores'][0]['mas_caro_pct']);
        $this->assertSame('Corralón Mayorista', $r['proveedores'][1]['proveedor']); $this->assertEquals(15.8, $r['proveedores'][1]['mas_caro_pct']); $this->assertTrue($r['proveedores'][1]['es_ultimo']);
    }

    public function test_la_cotizacion_del_simulador_sale_por_whatsapp_con_pdf_que_dura_30_dias(): void
    {
        $p = $this->articulo(['name' => 'Arena', 'price' => 1000, 'iva' => 21]);
        $cli = $this->cliente(['name' => 'Juan Obra', 'mobile' => '11 4444-5555']);
        $r = $this->postJson('/stock/consultor/cotizar', ['items' => [['id' => $p->id, 'nombre' => 'Arena', 'cantidad' => 3, 'precio' => 1210]], 'contact_id' => $cli->id, 'lista' => 1])->assertOk()->json();
        $this->assertEquals(3630, $r['total']); $this->assertStringStartsWith('https://wa.me/5491144445555?text=', $r['whatsapp']); $this->assertStringContainsString('Juan Obra', $r['texto']);
        $c = CotizacionConsultor::first(); $this->assertSame($cli->id, $c->contact_id); $this->assertTrue($c->expira_en->isFuture());
        // pública, sin login
        auth()->logout();
        $this->get("/q/{$c->token}")->assertOk()->assertSee('NO ES UN COMPROBANTE')->assertSee('Arena');
        $this->get("/q/{$c->token}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $c->update(['expira_en' => now()->subDay()]);
        $this->get("/q/{$c->token}")->assertStatus(410);
        $this->get('/q/nada')->assertNotFound();
    }

    public function test_la_pantalla_del_salon_tiene_su_llave_baja_el_catalogo_sin_costos_y_se_da_de_baja(): void
    {
        $p = $this->articulo(['name' => 'Panceta', 'sku' => '3329', 'price' => 10220, 'iva' => 21, 'pesable' => true, 'plu' => '3329', 'cost' => 6000, 'desc_cant_min' => 3, 'desc_cant_pct' => 10]);
        $this->articulo(['name' => 'Insumo', 'tipo' => 'insumo', 'price' => 1]);
        $this->post('/configuracion/empresa/cartelera', ['archivos' => [\Illuminate\Http\UploadedFile::fake()->image('promo.jpg')]])->assertSessionHas('success');
        $r = $this->postJson('/stock/consultor/pantallas', ['nombre' => 'Góndola fiambres', 'lista' => 2])->assertOk()->json();
        $this->assertStringContainsString('/salon/', $r['url']);
        $token = substr($r['url'], strrpos($r['url'], '/') + 1);
        $this->get('/stock/consultor')->assertInertia(fn($pg) => $pg->has('pantallas', 1)->where('pantallas.0.nombre', 'Góndola fiambres'));
        auth()->logout();
        $this->get("/salon/{$token}")->assertOk()->assertSee('Pasá el producto por el lector')->assertSee('Góndola fiambres');
        $cat = $this->getJson("/salon/{$token}/catalogo")->assertOk()->json();
        $this->assertSame(2, $cat['pantalla']['lista']); $this->assertCount(1, $cat['items']); // el insumo no va
        $it = $cat['items'][0]; $this->assertSame('Panceta', $it['n']); $this->assertEquals(12366.2, $it['p']); $this->assertSame('3329', $it['plu']); $this->assertEquals(10, $it['promo']['pct']);
        $this->assertArrayNotHasKey('cost', $it); $this->assertArrayNotHasKey('costo', $it);
        $this->get("/salon/{$token}/cartelera/" . $this->empresa->fresh()->pos['cartelera'][0]['id'])->assertOk();
        // otra empresa no la toca; la baja mata la llave
        $this->actingAs($this->dueno)->delete('/stock/consultor/pantallas/' . $r['id'])->assertSessionHas('success');
        auth()->logout();
        $this->get("/salon/{$token}")->assertNotFound(); $this->getJson("/salon/{$token}/catalogo")->assertNotFound();
        $this->get('/salon/cualquiera')->assertNotFound();
    }

    public function test_el_ticket_sale_de_58_mm_cuando_la_impresora_es_angosta(): void
    {
        $p = $this->articulo(['price' => 121, 'stock_inicial' => 5]);
        $v = $this->postJson('/retail/vender', ['items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 121, 'descuento' => 0, 'alicuota_iva' => 21]], 'medios' => [['medio' => 'efectivo', 'monto' => 121]], 'precios_con_iva' => true])->assertOk()->json();
        $this->get("/retail/ticket/{$v['comprobante_id']}")->assertOk()->assertSee('width:80mm', false);
        $this->get("/retail/ticket/{$v['comprobante_id']}?ancho=58")->assertOk()->assertSee('width:58mm', false)->assertSee('size:58mm', false);
        $this->empresa->update(['pos' => ['ancho' => 32, 'impresora' => 'ble']]);
        $this->actingAs($this->dueno->fresh())->get("/retail/ticket/{$v['comprobante_id']}")->assertOk()->assertSee('width:58mm', false);
        $this->post('/configuracion/empresa/pos', ['balanza_prefijo' => '2', 'balanza_modo' => 'peso', 'balanza_decimales' => 3, 'balanza_digitos_plu' => 5, 'imprimir_auto' => true, 'impresora' => 'ble', 'ancho' => 32])->assertSessionHasNoErrors();
    }
}
