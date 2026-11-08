<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\Stock\AltaArticuloIaService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 61: el artículo se completa con IA desde el nombre o una foto (rubro, unidad, IVA, marca, código, descripciones y atributos de ML).
class AltaArticuloIaFase61Test extends ErpTestCase
{
    private function ia(array $json): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        $this->dueno->refresh(); // la relación business ya estaba cargada sin clave
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "```json\n" . json_encode($json) . "\n```"]]])]);
    }

    public function test_desde_el_nombre_completa_rubro_unidad_iva_descripciones_y_atributos(): void
    {
        $this->postJson('/stock/articulos/completar-ia', ['nombre' => 'cemento loma negra 50'])->assertStatus(422); // sin clave de IA
        $con = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Construcción']);
        Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Pinturas']);
        $this->ia(['name' => 'Cemento Loma Negra x 50 kg', 'marca' => 'Loma Negra', 'modelo' => 'CPC40', 'tipo' => 'producto', 'unit' => 'bolsa', 'iva' => 21, 'rubro' => 'construccion', 'rubro_nuevo' => null, 'barcode' => null,
            'contenido_neto' => 50, 'contenido_unidad' => 'kg', 'description' => 'Cemento portland compuesto para obra general.', 'descripcion_tienda' => 'Cemento Loma Negra de 50 kg, el clásico para hormigón y revoques. Fragüe parejo y rendimiento garantizado.',
            'titulo_publicacion' => 'Cemento Loma Negra CPC40 Bolsa 50 Kg', 'ml_atributos' => ['BRAND' => 'Loma Negra', 'MODEL' => 'CPC40', 'WEIGHT' => '50 kg', 'raro!' => '']]);
        $r = $this->postJson('/stock/articulos/completar-ia', ['nombre' => 'cemento loma negra 50'])->assertOk()->json();
        $c = $r['campos'];
        $this->assertSame('Cemento Loma Negra x 50 kg', $c['name']); $this->assertSame($con->id, $c['rubro_id']); $this->assertSame('bolsa', $c['unit']); $this->assertEquals(21, $c['iva']); $this->assertSame('kg', $c['contenido_unidad']);
        $this->assertSame(['BRAND' => 'Loma Negra', 'MODEL' => 'CPC40', 'WEIGHT' => '50 kg'], $c['atributos']['ml']); $this->assertSame('Cemento Loma Negra CPC40 Bolsa 50 Kg', $c['atributos']['titulo_publicacion']);
        $this->assertNull($r['rubro_sugerido']);
        $pedido = Http::recorded()[0][0]->data();
        $this->assertStringContainsString('Construcción | Pinturas', $pedido['system']); $this->assertStringContainsString('cemento loma negra 50', $pedido['messages'][0]['content'][0]['text']);

        // Se guarda con todo, y la publicación usa el título y los atributos del artículo.
        $this->post('/stock/articulos', $c + ['sku' => 'CEM50', 'price' => 12000, 'cost' => 9000, 'controla_stock' => true, 'en_tienda' => true])->assertSessionHasNoErrors();
        $p = Product::where('sku', 'CEM50')->first();
        $this->assertSame('50 kg', $p->atributos['ml']['WEIGHT']); $this->assertSame($con->id, $p->rubro_id); $this->assertStringContainsString('Fragüe parejo', $p->descripcion_tienda);
    }

    public function test_desde_una_foto_lee_el_codigo_de_barras_y_lo_que_no_cuadra_se_normaliza(): void
    {
        $this->ia(['name' => 'Aceite Natura Girasol 1,5 L', 'marca' => 'Natura', 'tipo' => 'cualquiera', 'unit' => 'botella', 'iva' => 13, 'rubro' => 'Almacén', 'rubro_nuevo' => 'Almacén', 'barcode' => '7790070012345', 'contenido_neto' => 1.5, 'contenido_unidad' => 'l',
            'description' => 'Aceite de girasol', 'descripcion_tienda' => 'Aceite de girasol Natura 1,5 L.', 'titulo_publicacion' => 'Aceite Natura Girasol 1,5 L', 'ml_atributos' => ['BRAND' => 'Natura']]);
        $png = 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $r = $this->postJson('/stock/articulos/completar-ia', ['foto' => $png])->assertOk()->json();
        $c = $r['campos'];
        $this->assertSame('7790070012345', $c['barcode']); $this->assertSame('un', $c['unit'], 'unidad desconocida → unidad'); $this->assertEquals(21, $c['iva'], 'IVA inválido → 21'); $this->assertSame('producto', $c['tipo']);
        $this->assertNull($c['rubro_id']); $this->assertSame('Almacén', $r['rubro_sugerido']);
        $contenido = Http::recorded()[0][0]->data()['messages'][0]['content'];
        $this->assertSame('image', $contenido[0]['type']); $this->assertSame('image/png', $contenido[0]['source']['media_type']);
        $this->postJson('/stock/articulos/completar-ia', [])->assertStatus(422);
    }

    public function test_bigcito_crea_el_articulo_completo(): void
    {
        $san = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Sanitarios']);
        $this->ia(['name' => 'Caño PVC 110 mm x 4 m', 'marca' => 'Tigre', 'tipo' => 'producto', 'unit' => 'un', 'iva' => 21, 'rubro' => 'Sanitarios', 'description' => 'Caño de PVC para desagüe cloacal.', 'descripcion_tienda' => 'Caño PVC Tigre 110 mm de 4 metros para desagües.', 'titulo_publicacion' => 'Caño PVC Tigre 110 Mm X 4 M', 'ml_atributos' => ['BRAND' => 'Tigre', 'DIAMETER' => '110 mm', 'LENGTH' => '4 m']]);
        $r = app(Catalogo::class)->llamar($this->dueno, 'crear_articulo', ['nombre' => 'caño pvc 110 x 4', 'precio' => 12500], 'asistente');
        $this->assertStringContainsString('rubro Sanitarios', $r['detalle']); $this->assertStringContainsString('completado con IA', $r['detalle']);
        app(Catalogo::class)->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $r['propuesta_id']], 'asistente');
        $p = Product::where('name', 'caño pvc 110 x 4')->first();
        $this->assertSame($san->id, $p->rubro_id); $this->assertSame('Tigre', $p->marca); $this->assertSame('110 mm', $p->atributos['ml']['DIAMETER']); $this->assertStringContainsString('desagües', $p->descripcion_tienda);
    }
}
