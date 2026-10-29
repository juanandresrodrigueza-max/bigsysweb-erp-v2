<?php

namespace Tests\Feature;

use App\Models\AliasArticulo;
use App\Models\Canal;
use App\Models\PedidoWeb;
use App\Models\Publicacion;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 30.3: lo que ya estaba publicado se lee, se sugiere (segura / dudosa / ninguna), se ata con un clic, se adopta y aprende.
class EmparejarTest extends ErpTestCase
{
    private function canalMl(): Canal
    {
        return Canal::create(['business_id' => $this->empresa->id, 'tipo' => 'mercadolibre', 'nombre' => 'ML', 'credenciales' => ['access_token' => 'tok', 'user_id' => '77'], 'external_user_id' => '77', 'activo' => true, 'config' => ['lista' => 1, 'colchon' => 1]]);
    }

    private function fakeItems(array $items): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events'))); // los stubs anteriores no se pisan: se arranca de cero
        Http::fake([
            'api.mercadolibre.com/users/77/items/search*' => Http::response(['results' => array_keys($items), 'scroll_id' => null]),
            'api.mercadolibre.com/items?ids=*' => Http::response(array_map(fn($id, $b) => ['code' => 200, 'body' => ['id' => $id, 'status' => 'active', 'price' => 1000, 'available_quantity' => 3, 'sold_quantity' => 8, 'thumbnail' => "https://img/{$id}.jpg", 'permalink' => "https://ml/{$id}"] + $b], array_keys($items), $items)),
            'api.mercadolibre.com/items/*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_lee_la_tienda_y_sugiere_segura_por_codigo_dudosa_por_cantidad_y_ninguna(): void
    {
        $c = $this->canalMl();
        $cem = $this->articulo(['name' => 'Cemento Loma Negra x 50 kg', 'sku' => 'CEM50', 'stock_inicial' => 10]);
        $p25 = $this->articulo(['name' => 'Pellet de madera x 25 kg', 'sku' => 'PEL25', 'stock_inicial' => 10]);
        $p5 = $this->articulo(['name' => 'Pellet de madera x 5 kg', 'sku' => 'PEL5', 'barcode' => '779000111', 'stock_inicial' => 10]);
        $this->fakeItems([
            'MLA1' => ['title' => 'Cemento Loma Negra bolsa', 'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'CEM50']]],
            'MLA2' => ['title' => 'Pellet de madera 25 KG premium', 'attributes' => []],
            'MLA3' => ['title' => 'Pellet madera bolsa', 'attributes' => [['id' => 'GTIN', 'value_name' => '779000111']]],
            'MLA4' => ['title' => 'Taladro percutor 800W', 'attributes' => []],
            'MLA5' => ['title' => 'Alimento Old Prince Premium Cemento', 'attributes' => [], 'variations' => [['id' => 1]]],
        ]);
        $r = $this->getJson("/stock/tienda-online/{$c->id}/existentes?leer=1")->assertOk()->json();
        $this->assertCount(5, $r['items']);
        $by = collect($r['items'])->keyBy('external_id');
        $this->assertSame('segura', $by['MLA1']['sugerencia']['tipo']); $this->assertSame($cem->id, $by['MLA1']['sugerencia']['product_id']); $this->assertStringContainsString('código', $by['MLA1']['sugerencia']['por']);
        $this->assertSame('segura', $by['MLA3']['sugerencia']['tipo']); $this->assertSame($p5->id, $by['MLA3']['sugerencia']['product_id']);
        // "25 KG" vs los dos pellets: el nombre se parece a ambos → dudosa, con los candidatos.
        $this->assertSame('dudosa', $by['MLA2']['sugerencia']['tipo']); $this->assertContains($p25->id, array_column($by['MLA2']['sugerencia']['candidatos'], 'id'));
        $this->assertSame('ninguna', $by['MLA4']['sugerencia']['tipo']);
        // Palabras que no existen en el catálogo ("alimento", "old", "prince") se descuentan y queda dudosa con la aclaración.
        $this->assertSame('dudosa', $by['MLA5']['sugerencia']['tipo']); $this->assertStringContainsString('sin contar', $by['MLA5']['sugerencia']['por']);
        $this->assertTrue($by['MLA5']['con_variantes']); $this->assertSame(8, $by['MLA1']['vendidas']);
        $this->assertSame(2, $r['seguras']);
        $this->assertSame(0, Publicacion::whereNotNull('product_id')->count(), 'Nada se ata solo');
    }

    public function test_atar_aprende_pasa_la_foto_arregla_las_ventas_pendientes_y_adoptar_manda_precio_y_stock(): void
    {
        $c = $this->canalMl();
        $cem = $this->articulo(['name' => 'Cemento x 50 kg', 'sku' => 'CEM50', 'price' => 1000, 'stock_inicial' => 10]);
        $this->fakeItems(['MLA1' => ['title' => 'Cemento Portland bolsa 50', 'attributes' => []], 'MLA5' => ['title' => 'Cemento variantes', 'attributes' => [], 'variations' => [['id' => 1]]]]);
        $this->getJson("/stock/tienda-online/{$c->id}/existentes?leer=1")->assertOk();
        $pub = Publicacion::where('external_id', 'MLA1')->first();
        // Una venta pendiente de esa publicación, sin artículo.
        $pw = app(\App\Services\Canales\CanalesService::class)->alta($this->empresa, $c, ['external_id' => 'ORD1', 'cliente' => ['nombre' => 'Ana'], 'items' => [['external_item' => 'MLA1', 'descripcion' => 'Cemento Portland bolsa 50', 'cantidad' => 2, 'precio_unit' => 1000]], 'total' => 2000]);
        $this->assertNull($pw->items[0]['product_id']);
        $this->assertSame(['ventas' => 1, 'publicaciones' => 1], $this->getJson("/stock/tienda-online/{$c->id}/existentes")->json('pendientes'));
        // Atar.
        $this->postJson("/stock/tienda-online/publicaciones/{$pub->id}/atar", ['product_id' => $cem->id])->assertOk()->assertJsonPath('adoptada', false);
        $pub->refresh();
        $this->assertSame($cem->id, $pub->product_id); $this->assertSame('atada', $pub->origen);
        $this->assertSame($cem->id, AliasArticulo::where('texto', AliasArticulo::normalizar('Cemento Portland bolsa 50'))->value('product_id'), 'Aprende el título');
        $this->assertSame('https://img/MLA1.jpg', $cem->fresh()->imagen, 'La foto de la tienda pasa al catálogo porque el artículo no tenía');
        $this->assertSame($cem->id, $pw->fresh()->items[0]['product_id'], 'La venta pendiente queda con el artículo');
        Http::assertNotSent(fn($req) => $req->method() === 'PUT' && str_contains($req->url(), '/items/MLA1'));
        // La próxima venta de esa publicación entra con el artículo puesto.
        $pw2 = app(\App\Services\Canales\CanalesService::class)->alta($this->empresa, $c, ['external_id' => 'ORD2', 'cliente' => ['nombre' => 'Ana'], 'items' => [['external_item' => 'MLA1', 'descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 1000]], 'total' => 1000]);
        $this->assertSame($cem->id, $pw2->items[0]['product_id']);
        // Adoptar: el ERP le manda precio (lista 1 con IVA) y stock (menos el colchón).
        $this->postJson("/stock/tienda-online/publicaciones/{$pub->id}/adoptar")->assertOk()->assertJsonPath('adoptada', true);
        Http::assertSent(fn($req) => $req->method() === 'PUT' && str_ends_with($req->url(), '/items/MLA1') && (float) $req['price'] === 1210.0 && $req['available_quantity'] === 9);
        // Una con variantes se ata pero no se adopta.
        $pv = Publicacion::where('external_id', 'MLA5')->first();
        $this->postJson("/stock/tienda-online/publicaciones/{$pv->id}/atar", ['product_id' => $cem->id, 'adoptar' => true])->assertOk()->assertJsonPath('adoptada', false);
        $this->postJson("/stock/tienda-online/publicaciones/{$pv->id}/adoptar")->assertStatus(422);
        // Desatar olvida el alias.
        $this->postJson("/stock/tienda-online/publicaciones/{$pub->id}/desatar")->assertOk();
        $pub->refresh(); $this->assertNull($pub->product_id); $this->assertFalse($pub->adoptada); $this->assertTrue(AliasArticulo::where('texto', AliasArticulo::normalizar('Cemento Portland bolsa 50'))->doesntExist());
        // Y después de desatar, una lectura nueva la sugiere igual por nombre, no por memoria.
        $this->fakeItems(['MLA1' => ['title' => 'Cemento Portland bolsa 50', 'attributes' => []], 'MLA6' => ['title' => 'Cemento Portland bolsa 50', 'attributes' => []]]);
        $this->postJson("/stock/tienda-online/publicaciones/{$pub->id}/atar", ['product_id' => $cem->id])->assertOk();
        $s = collect($this->getJson("/stock/tienda-online/{$c->id}/existentes?leer=1")->json('items'))->firstWhere('external_id', 'MLA6')['sugerencia'];
        $this->assertSame('segura', $s['tipo']); $this->assertSame('ya lo ataste antes', $s['por']);
    }

    public function test_emparejar_las_seguras_de_un_clic_es_adoptar_y_buscar_articulo_mira_la_descripcion(): void
    {
        $c = $this->canalMl();
        $a = $this->articulo(['name' => 'BIG BAG X UN', 'sku' => 'BB1', 'description' => 'bolsa para escombros de 1 m3', 'price' => 500, 'stock_inicial' => 4]);
        $b = $this->articulo(['name' => 'Arena fina m3', 'sku' => 'ARE1', 'price' => 300, 'stock_inicial' => 4]);
        $this->fakeItems(['MLA1' => ['title' => 'Big bag', 'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'BB1']]], 'MLA2' => ['title' => 'Arena', 'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'ARE1']]], 'MLA3' => ['title' => 'Otra cosa', 'attributes' => []]]);
        $this->getJson("/stock/tienda-online/{$c->id}/existentes?leer=1")->assertOk()->assertJsonPath('seguras', 2);
        $this->postJson("/stock/tienda-online/{$c->id}/atar-seguras")->assertOk()->assertJsonPath('atadas', 2);
        $this->assertSame(2, Publicacion::where('adoptada', true)->count());
        Http::assertSent(fn($req) => $req->method() === 'PUT' && str_ends_with($req->url(), '/items/MLA1') && $req['available_quantity'] === 3);
        $r = $this->getJson('/stock/tienda-online/buscar-articulo?q=escombros')->assertOk()->json();
        $this->assertSame($a->id, $r[0]['id']); $this->assertStringContainsString('escombros', $r[0]['por']);
        // Un usuario sin permiso de editar stock no ata.
        $this->actingAs($this->usuarioConRol('vendedor'))->postJson('/stock/tienda-online/publicaciones/' . Publicacion::where('external_id', 'MLA3')->value('id') . '/atar', ['product_id' => $a->id])->assertStatus(403);
    }
}
