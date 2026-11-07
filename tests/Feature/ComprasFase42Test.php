<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Product;
use App\Models\ProveedorArticulo;
use App\Models\StockMovement;
use App\Services\Compras\CompraService;
use App\Services\Compras\LecturaCompraService;
use App\Services\Compras\OrdenCompraService;
use Illuminate\Http\UploadedFile;
use Tests\ErpTestCase;

// Fase 42.3 a 42.7: ajustar stock con una planilla, una factura contra varias órdenes, factura por metro y artículo por pieza,
// la mercadería que entra en otra empresa y la fecha con la que entra.
class ComprasFase42Test extends ErpTestCase
{
    private function compra(array $items, array $extra = []): Comprobante
    {
        $svc = app(CompraService::class);
        $c = $svc->guardarBorrador(array_merge(['contact_id' => $this->proveedor()->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT), 'fecha' => '2026-10-01', 'condicion' => 'cta_cte', 'items' => $items], $extra));
        return $svc->registrar($c);
    }

    public function test_ajustar_stock_con_una_planilla(): void
    {
        $this->actingAs($this->dueno);
        $a = $this->articulo(['sku' => 'A1', 'stock_inicial' => 10]); $b = $this->articulo(['sku' => 'B2', 'barcode' => '779111', 'stock_inicial' => 5]);
        $csv = UploadedFile::fake()->createWithContent('stock.csv', "codigo;stock\nA1;7\n779111;5\nNOEXISTE;3\n");
        $r = $this->post('/stock/precios/stock/previsualizar', ['archivo' => $csv, 'deposito_id' => $this->deposito->id])->assertOk()->json();
        $this->assertCount(1, $r['cambios']); // B2 ya tiene 5: no cambia
        $this->assertEquals(-3, $r['cambios'][0]['diferencia']);
        $this->assertSame(['NOEXISTE'], $r['no_encontrados']);
        $this->post('/stock/precios/stock', ['deposito_id' => $this->deposito->id, 'cambios' => $r['cambios']])->assertSessionHas('success');
        $this->assertEquals(7, $a->fresh()->stockEn($this->deposito->id));
        $this->assertStringContainsString($this->dueno->name, StockMovement::where('product_id', $a->id)->latest('id')->value('reason'));
    }

    public function test_una_factura_contra_varias_ordenes(): void
    {
        $this->actingAs($this->dueno);
        $prov = $this->proveedor(); $p = $this->articulo(['stock_inicial' => 0]);
        $ocs = app(OrdenCompraService::class);
        $o1 = $ocs->guardar(['contact_id' => $prov->id, 'fecha' => today()->toDateString(), 'items' => [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 900]]]); $ocs->enviar($o1);
        $o2 = $ocs->guardar(['contact_id' => $prov->id, 'fecha' => today()->toDateString(), 'items' => [['product_id' => $p->id, 'cantidad' => 5, 'precio_unit' => 950]]]); $ocs->enviar($o2);
        $this->get("/proveedores/compras/nueva?orden={$o1->id},{$o2->id}")->assertInertia(fn($pg) => $pg->where('desdeOrden.ordenes_compra', [$o1->id, $o2->id])->count('desdeOrden.items', 2)->where('desdeOrden.items.1.precio_unit', 950));
        $svc = app(CompraService::class);
        $c = $svc->guardarBorrador(['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000077', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'ordenes_compra' => [$o1->id, $o2->id], 'items' => [['product_id' => $p->id, 'cantidad' => 12, 'precio_unit' => 900]]]);
        $svc->registrar($c);
        $this->assertSame('recibida', $o1->fresh()->estado);   // la más vieja se llena primero
        $this->assertSame('parcial', $o2->fresh()->estado);
        $this->assertEquals(2, (float) $o2->items()->first()->recibido);
    }

    public function test_factura_por_metro_y_articulo_por_pieza_queda_aprendido(): void
    {
        $svc = app(LecturaCompraService::class);
        $prov = $this->proveedor(['cuit' => '30-60000000-1']);
        $cano = $this->articulo(['name' => 'Caño PVC 110 x 4 m', 'cost' => 8000, 'price' => 12000]);
        $d = $svc->entender(['proveedor' => ['cuit' => '30-60000000-1'], 'items' => [['descripcion' => 'CAÑO PVC 110', 'product_id' => $cano->id, 'cantidad' => 1800, 'unidad' => 'm', 'precio_unit' => 2000, 'alicuota_iva' => 21]], 'percepciones' => []], $prov);
        $it = $d['items'][0];
        $this->assertEquals(450, $it['cantidad']);
        $this->assertEquals(8000, $it['precio_unit']);
        $svc->aprender($prov, $d['items'], today()->toDateString());
        $this->assertEquals(4, (float) ProveedorArticulo::where('contact_id', $prov->id)->value('divisor'));
        // La próxima vez, aunque no diga «m», lo aprendido manda.
        $d2 = $svc->entender(['proveedor' => ['cuit' => '30-60000000-1'], 'items' => [['descripcion' => 'CAÑO PVC 110', 'cantidad' => 400, 'precio_unit' => 2000, 'alicuota_iva' => 21]], 'percepciones' => []], $prov);
        $this->assertEquals(100, $d2['items'][0]['cantidad']);
    }

    public function test_la_mercaderia_entra_en_otra_empresa_y_con_la_fecha_del_comprobante(): void
    {
        [$sucB, , $duenoB, , , $depB] = $this->crearEmpresa('Sucursal Sur SRL', 'sur');
        $this->dueno->empresas()->attach($sucB->id, ['role_id' => \App\Models\Role::withoutGlobalScopes()->where('business_id', $sucB->id)->where('slug', 'dueno')->value('id')]);
        $this->actingAs($this->dueno->fresh());
        $p = $this->articulo(['sku' => 'TAL-1', 'name' => 'Taladro', 'stock_inicial' => 0]);
        $c = $this->compra([['product_id' => $p->id, 'cantidad' => 3, 'precio_unit' => 50000]], ['stock_en_business_id' => $sucB->id]);
        $this->assertEquals(0, $p->fresh()->stock);                 // acá no entra
        $this->assertSame(1, \App\Models\CuentaCorriente::where('comprobante_id', $c->id)->count()); // la factura queda acá
        $pB = Product::withoutGlobalScopes()->where('business_id', $sucB->id)->where('sku', 'TAL-1')->first();
        $this->assertEquals(3, (float) $pB->stock);
        $this->assertEquals(50000, (float) $pB->cost);
        // No se puede mandar a una empresa sin acceso.
        [$ajena] = $this->crearEmpresa('Ajena', 'ajena');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->compra([['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1]], ['stock_en_business_id' => $ajena->id]);
    }

    public function test_fecha_de_ingreso_del_comprobante(): void
    {
        $this->actingAs($this->dueno);
        $this->post('/proveedores/compras/config', ['fecha_ingreso' => 'comprobante'])->assertSessionHas('success');
        $p = $this->articulo(['stock_inicial' => 0]);
        $this->compra([['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 100]]);
        $this->assertSame('2026-10-01', StockMovement::where('product_id', $p->id)->latest('id')->first()->created_at->toDateString());
    }
}
