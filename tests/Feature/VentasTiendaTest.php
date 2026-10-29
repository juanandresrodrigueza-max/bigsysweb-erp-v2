<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Canal;
use App\Models\Comprobante;
use App\Models\PedidoDatosPersonales;
use App\Models\PedidoWeb;
use App\Models\Publicacion;
use App\Services\Canales\CanalesService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 30.4–30.6: cada venta de tienda llega lista para facturar, quién factura, modo automático, devoluciones, la factura en ML,
// el aviso de envío, los cargos de MercadoLibre, exportar pedidos, Empretienda y los pedidos de datos personales.
class VentasTiendaTest extends ErpTestCase
{
    private function canalMl(array $cfg = []): Canal
    {
        return Canal::create(['business_id' => $this->empresa->id, 'tipo' => 'mercadolibre', 'nombre' => 'ML', 'credenciales' => ['access_token' => 'tok', 'user_id' => '77'], 'external_user_id' => '77', 'activo' => true, 'config' => $cfg]);
    }

    private function ventaMl(Canal $c, array $extra = [], array $item = []): PedidoWeb
    {
        $p = \App\Models\Product::where('sku', 'CEM50')->first() ?? $this->articulo(['name' => 'Cemento', 'sku' => 'CEM50', 'price' => 1000, 'stock_inicial' => 10]);
        Publicacion::firstOrCreate(['canal_id' => $c->id, 'external_id' => 'MLA1', 'variante_id' => null], ['business_id' => $this->empresa->id, 'product_id' => $p->id, 'origen' => 'publicada', 'titulo' => 'Cemento']);
        $raw = ['id' => 5001, 'status' => 'paid', 'date_created' => now()->toIso8601String(), 'pack_id' => 9001, 'total_amount' => 2420, 'buyer' => ['id' => 333, 'first_name' => 'Ana', 'last_name' => 'Pérez', 'billing_info' => ['doc_number' => '30-70012345-6', 'additional_info' => [['type' => 'TAXPAYER_TYPE', 'value' => 'IVA Responsable Inscripto']]]],
            'order_items' => [['item' => array_replace(['id' => 'MLA1', 'title' => 'Cemento', 'seller_sku' => 'CEM50'], $item), 'quantity' => 2, 'unit_price' => 1210]],
            'payments' => [['id' => 1, 'status' => 'approved', 'total_paid_amount' => 2420, 'marketplace_fee' => 320, 'shipping_cost' => 0, 'taxes_amount' => 48.4, 'money_release_date' => now()->addDays(10)->toIso8601String()]]];
        $raw = array_replace($raw, $extra);
        $svc = app(CanalesService::class);
        return $svc->alta($this->empresa, $c, $svc->normalizar('mercadolibre', $raw));
    }

    public function test_una_venta_de_mercadolibre_no_se_confirma_hasta_decir_quien_factura_y_entra_como_remito_si_factura_ml(): void
    {
        $cuenta = $this->cliente(['name' => 'MERCADO LIBRE', 'condicion_iva' => 'Consumidor Final', 'cuit' => null]);
        $c = $this->canalMl(['contact_id' => $cuenta->id]);
        $pw = $this->ventaMl($c);
        $this->assertSame(9001, $pw->cargos['pack_id']); $this->assertSame(333, $pw->cargos['comprador_id']); $this->assertEqualsWithDelta(320, $pw->cargos['pagos'][0]['cargo'], 0.01);
        $this->assertSame('30-70012345-6', $pw->cliente['cuit']);
        // Sin elegir quién factura, no se confirma: a propósito.
        $this->post("/comprobantes/pedidos/{$pw->id}/confirmar", ['tipo' => 'FX'])->assertSessionHasErrors('pedido');
        $this->get('/comprobantes/pedidos')->assertInertia(fn($p) => $p->where('pedidos.0.listo.ok', false)->where('pedidos.0.cargos.cargo_venta', 320));
        // Factura MercadoLibre → entra como remito: baja el stock, no se factura dos veces.
        $c->update(['config' => ['contact_id' => $cuenta->id, 'factura_por' => 'tienda']]);
        $this->post("/comprobantes/pedidos/{$pw->id}/confirmar", ['tipo' => 'FX'])->assertSessionHas('success');
        $pw->refresh(); $comp = $pw->comprobante;
        $this->assertSame('REM', $comp->tipo); $this->assertSame('emitido', $comp->estado); $this->assertSame($cuenta->id, $comp->contact_id);
        $this->assertStringContainsString('Factura MercadoLibre', $comp->notas); $this->assertStringContainsString('9001', $comp->notas);
        $this->assertEqualsWithDelta(8, (float) \App\Models\Product::where('sku', 'CEM50')->first()->stock, 0.001);
        $this->assertEqualsWithDelta(0, $cuenta->fresh()->balance, 0.01, 'El remito no toca la cuenta corriente');
        // Los cargos de la venta.
        $cg = app(\App\Services\Canales\VentasTiendaService::class)->cargosDe($pw);
        $this->assertEqualsWithDelta(2420 - 320 - 48.4, $cg['recibe'], 0.01); $this->assertEqualsWithDelta(2420 / 1.21 - 320 / 1.21 - 200, $cg['deja'], 0.01);
    }

    public function test_si_factura_el_erp_sale_factura_a_con_los_datos_del_comprador_y_se_sube_y_manda_por_mercadolibre(): void
    {
        $cuenta = $this->cliente(['name' => 'MERCADO LIBRE', 'condicion_iva' => 'Consumidor Final', 'cuit' => null]);
        $c = $this->canalMl(['contact_id' => $cuenta->id, 'factura_por' => 'erp']);
        $pw = $this->ventaMl($c);
        $this->post("/comprobantes/pedidos/{$pw->id}/confirmar", ['tipo' => 'FX'])->assertSessionHas('success');
        $comp = $pw->fresh()->comprobante;
        $this->assertSame('FA', $comp->tipo, 'El comprador es responsable inscripto: letra A aunque la cuenta sea consumidor final');
        $this->assertSame('30-70012345-6', $comp->receptor['documento']); $this->assertSame('Ana Pérez', $comp->receptor['nombre']);
        Http::fake(['api.mercadolibre.com/packs/9001/fiscal_documents' => Http::response(['ok' => true], 201), 'api.mercadolibre.com/messages/attachments*' => Http::response(['id' => 'adj-1']), 'api.mercadolibre.com/messages/packs/9001/sellers/77*' => Http::response(['id' => 'msg-1'], 201)]);
        $this->post("/comprobantes/pedidos/{$pw->id}/factura-ml")->assertSessionHas('success');
        $this->post("/comprobantes/pedidos/{$pw->id}/mandar-factura")->assertSessionHas('success');
        $pw->refresh(); $this->assertNotNull($pw->factura_subida_at); $this->assertNotNull($pw->factura_enviada_at);
        Http::assertSent(fn($r) => str_contains($r->url(), '/packs/9001/fiscal_documents') && $r->hasFile('fiscal_document'));
        Http::assertSent(fn($r) => str_contains($r->url(), '/messages/packs/9001/sellers/77') && $r['to']['user_id'] === 333 && $r['attachments'] === ['adj-1']);
        // Una venta anterior a la fecha de corte no se confirma; una con un renglón sin artículo tampoco.
        $c->update(['config' => ['contact_id' => $cuenta->id, 'factura_por' => 'erp', 'fecha_corte' => today()->addDay()->toDateString()]]);
        $vieja = $this->ventaMl($c, ['id' => 5002]);
        $this->assertStringContainsString('fecha desde la que entran', app(\App\Services\Canales\VentasTiendaService::class)->listo($vieja, $c)['motivos'][0]);
        $c->update(['config' => ['contact_id' => $cuenta->id, 'factura_por' => 'erp']]);
        $sin = $this->ventaMl($c, ['id' => 5003], ['id' => 'MLA999', 'seller_sku' => null]);
        $this->assertStringContainsString('todavía no tiene artículo', app(\App\Services\Canales\VentasTiendaService::class)->listo($sin, $c)['motivos'][0]);
    }

    public function test_modo_automatico_entra_sola_y_la_cancelacion_devuelve_el_stock(): void
    {
        $cuenta = $this->cliente(['name' => 'MERCADO LIBRE', 'cuit' => null, 'condicion_iva' => 'Consumidor Final']);
        $c = $this->canalMl(['contact_id' => $cuenta->id, 'factura_por' => 'tienda', 'automatico' => true]);
        $pw = $this->ventaMl($c);
        $this->assertSame('confirmado', $pw->estado); $this->assertStringContainsString('Entró sola', $pw->notas); $this->assertSame('REM', $pw->comprobante->tipo);
        $prod = \App\Models\Product::where('sku', 'CEM50')->first(); $this->assertEqualsWithDelta(8, (float) $prod->stock, 0.001);
        // MercadoLibre cancela: como entró sola, la devolución también sale sola (ingreso de stock a nombre de la cuenta).
        app(CanalesService::class)->cancelado($c, '5001');
        $pw->refresh(); $this->assertSame('cancelado', $pw->estado); $this->assertSame('resuelta', $pw->devolucion);
        $this->assertEqualsWithDelta(10, (float) $prod->fresh()->stock, 0.001, 'El stock volvió');
        // Si factura el ERP y la confirmó una persona: queda "Devolución: hay que volver el stock" con el botón, y sale nota de crédito.
        $c->update(['config' => ['contact_id' => $cuenta->id, 'factura_por' => 'erp', 'automatico' => false]]);
        $pw2 = $this->ventaMl($c, ['id' => 5002]);
        $this->assertSame('nuevo', $pw2->estado);
        $this->post("/comprobantes/pedidos/{$pw2->id}/confirmar")->assertSessionHas('success');
        app(CanalesService::class)->cancelado($c, '5002');
        $pw2->refresh(); $this->assertSame('pendiente', $pw2->devolucion);
        $this->post("/comprobantes/pedidos/{$pw2->id}/devolver")->assertSessionHas('success');
        $this->assertTrue(Comprobante::where('origen_id', $pw2->comprobante_id)->where('tipo', 'like', 'NC%')->where('estado', 'emitido')->exists(), 'Nota de crédito emitida');
        $this->assertEqualsWithDelta(10, (float) $prod->fresh()->stock, 0.001);
        $this->assertAsientosBalancean();
    }

    public function test_marcar_enviado_avisa_en_la_tienda_con_el_seguimiento(): void
    {
        $c = Canal::create(['business_id' => $this->empresa->id, 'tipo' => 'woocommerce', 'nombre' => 'Woo', 'credenciales' => ['url' => 'https://mitienda.com', 'consumer_key' => 'k', 'consumer_secret' => 's'], 'activo' => true, 'config' => ['contact_id' => $this->cliente(['name' => 'VENTAS WEB'])->id]]);
        $p = $this->articulo(['sku' => 'CEM50', 'price' => 1000, 'stock_inicial' => 10]);
        Http::fake(['mitienda.com/wp-json/wc/v3/orders/501' => Http::response(['id' => 501, 'status' => 'completed'])]);
        $svc = app(CanalesService::class);
        $pw = $svc->alta($this->empresa, $c, $svc->normalizar('woocommerce', ['id' => 501, 'status' => 'processing', 'date_paid' => now()->toIso8601String(), 'total' => '1210', 'billing' => ['first_name' => 'Ana', 'last_name' => 'P'], 'line_items' => [['sku' => 'CEM50', 'product_id' => 9, 'name' => 'Cemento', 'quantity' => 1, 'total' => '1210']]]));
        $this->post("/comprobantes/pedidos/{$pw->id}/confirmar")->assertSessionHas('success');
        $this->post("/comprobantes/pedidos/{$pw->id}/estado", ['estado' => 'enviado', 'tracking' => 'OCA123'])->assertSessionHas('success');
        Http::assertSent(fn($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/orders/501') && $r['status'] === 'completed' && $r['meta_data'][0]['value'] === 'OCA123');
        $this->assertSame('OCA123', $pw->fresh()->tracking);
    }

    public function test_cargos_del_mes_para_el_contador_y_como_compra_a_mercado_libre(): void
    {
        $cuenta = $this->cliente(['name' => 'MERCADO LIBRE', 'cuit' => null, 'condicion_iva' => 'Consumidor Final']);
        $c = $this->canalMl(['contact_id' => $cuenta->id, 'factura_por' => 'tienda']);
        $this->ventaMl($c); $this->ventaMl($c, ['id' => 5002]);
        $mes = today()->format('Y-m');
        $r = $this->getJson("/stock/tienda-online/{$c->id}/cargos?mes={$mes}")->assertOk()->json();
        $this->assertSame(2, $r['ventas']); $this->assertEqualsWithDelta(640, $r['cargo_venta'], 0.01); $this->assertEqualsWithDelta(96.8, $r['retenciones'], 0.01); $this->assertNull($r['compra_id']);
        $csv = $this->get("/stock/tienda-online/{$c->id}/cargos?mes={$mes}&csv=1")->assertOk()->getContent();
        $this->assertSame(3, substr_count($csv, "\n")); $this->assertStringContainsString('9001', $csv);
        $this->postJson("/stock/tienda-online/{$c->id}/cargos/compra", ['mes' => $mes])->assertOk();
        $compra = Comprobante::compras()->first();
        $this->assertSame('MERCADO LIBRE S.R.L.', $compra->contact->name); $this->assertSame('30-70308853-4', $compra->contact->cuit); $this->assertSame('borrador', $compra->estado);
        $this->assertEqualsWithDelta(640 / 1.21, (float) $compra->items->first()->precio_unit, 0.01);
        $this->assertSame($compra->id, $this->getJson("/stock/tienda-online/{$c->id}/cargos?mes={$mes}")->json('compra_id'), 'No se arma dos veces');
    }

    public function test_exportar_pedidos_por_periodo_planilla_de_empretienda_y_datos_personales(): void
    {
        $cuenta = $this->cliente(['name' => 'MERCADO LIBRE', 'cuit' => null, 'condicion_iva' => 'Consumidor Final']);
        $c = $this->canalMl(['contact_id' => $cuenta->id, 'factura_por' => 'tienda']);
        $this->ventaMl($c);
        $hoy = today()->toDateString();
        $this->getJson("/comprobantes/pedidos/exportar?desde={$hoy}&hasta={$hoy}&contar=1")->assertOk()->assertJsonPath('cantidad', 1);
        $csv = $this->get("/comprobantes/pedidos/exportar?desde={$hoy}&hasta={$hoy}&excel_es=1")->assertOk()->getContent();
        $this->assertStringContainsString('"fecha";"hora";"plataforma"', $csv); $this->assertStringContainsString('"MercadoLibre"', $csv); $this->assertStringContainsString('"2420.00"', $csv);
        $ren = $this->get("/comprobantes/pedidos/exportar?desde={$hoy}&hasta={$hoy}&renglones=1&solo_renglones=1")->assertOk()->getContent();
        $this->assertStringContainsString('"CEM50"', $ren);
        $xlsx = $this->get("/comprobantes/pedidos/exportar?desde={$hoy}&hasta={$hoy}&formato=xlsx&renglones=1")->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent());
        $this->get("/comprobantes/pedidos/exportar?desde=2020-01-01&hasta=2020-01-02")->assertStatus(422);
        // Empretienda: la planilla con sus encabezados, SKU = código, precio con IVA.
        $this->articulo(['name' => 'Sin precio', 'price' => 0]);
        $r = $this->get('/stock/tienda-online/empretienda?colchon=2')->assertOk();
        $this->assertSame('1', $r->headers->get('X-Cantidad'), 'El sin precio no entra');
        $tmp = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($tmp, $r->getContent()); $z = new \ZipArchive(); $z->open($tmp); $hoja = $z->getFromName('xl/worksheets/sheet1.xml'); $z->close(); unlink($tmp);
        $this->assertStringContainsString('<t>Nombre</t>', $hoja); $this->assertStringContainsString('<t>CEM50</t>', $hoja); $this->assertStringContainsString('<v>1210</v>', $hoja); $this->assertStringContainsString('<v>8</v>', $hoja, '10 de stock menos el colchón de 2');
        // Tienda Nube: borrar datos lo hace el sistema; ver datos queda para el dueño.
        $tn = Canal::create(['business_id' => $this->empresa->id, 'tipo' => 'tiendanube', 'nombre' => 'TN', 'credenciales' => ['store_id' => '1', 'access_token' => 't'], 'activo' => true]);
        $svc = app(CanalesService::class);
        $pwtn = $svc->alta($this->empresa, $tn, ['external_id' => '801', 'cliente' => ['nombre' => 'Carla Gómez', 'email' => 'carla@x.com', 'telefono' => '11', 'cuit' => '27-1'], 'items' => [['descripcion' => 'Cemento', 'sku' => 'CEM50', 'cantidad' => 1, 'precio_unit' => 1000]], 'total' => 1000]);
        $this->postJson("/api/canales/tiendanube/{$tn->token_entrada}", ['event' => 'customers/redact', 'customer' => ['id' => 5, 'email' => 'carla@x.com'], 'orders_to_redact' => [801]])->assertOk();
        $pwtn->refresh(); $this->assertNull($pwtn->cliente['email']); $this->assertStringContainsString('datos borrados', $pwtn->cliente['nombre']); $this->assertEqualsWithDelta(1000, $pwtn->total, 0.01, 'El importe y el comprobante quedan');
        $this->assertSame('hecho', PedidoDatosPersonales::where('tipo', 'borrar')->value('estado'));
        $this->postJson("/api/canales/tiendanube/{$tn->token_entrada}", ['event' => 'customers/data_request', 'customer' => ['id' => 6, 'email' => 'otro@x.com'], 'orders_requested' => [802]])->assertOk();
        $this->assertTrue(Alerta::where('tipo', 'datos_personales')->exists());
        $reg = PedidoDatosPersonales::where('tipo', 'ver')->first(); $this->assertSame('pendiente', $reg->estado);
        $this->get('/configuracion/tienda')->assertInertia(fn($p) => $p->where('datosPersonales.0.tipo', 'ver'));
        $this->post("/configuracion/tienda/datos-personales/{$reg->id}/contestado")->assertSessionHas('success');
        $this->assertSame('hecho', $reg->fresh()->estado);
    }
}
