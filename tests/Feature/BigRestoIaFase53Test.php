<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Comanda;
use App\Models\Mesa;
use App\Models\Product;
use App\Services\Gastronomia\PedidoRestoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 53.2 · IA en Big Restó: la carta desde una foto, el mozo le dicta la comanda a Bigcito y los pedidos por WhatsApp entran como comanda.
class BigRestoIaFase53Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->forceFill(['vertical' => 'gastronomia', 'ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
    }

    private function bife(): Product
    {
        $p = $this->articulo(['name' => 'Bife de chorizo', 'price' => 10000, 'iva' => 0]);
        $p->forceFill(['va_cocina' => true, 'modificadores' => [['nombre' => 'Punto', 'obligatorio' => true, 'multiple' => false, 'opciones' => [['nombre' => 'Jugoso', 'precio' => 0], ['nombre' => 'A punto', 'precio' => 0]]], ['nombre' => 'Guarnición', 'obligatorio' => false, 'multiple' => false, 'opciones' => [['nombre' => 'Papas fritas', 'precio' => 1500], ['nombre' => 'Ensalada', 'precio' => 0]]], ['nombre' => 'Sin', 'multiple' => true, 'opciones' => [['nombre' => 'Sin sal', 'precio' => 0]]]]])->save();
        return $p->fresh();
    }

    public function test_opciones_dichas_como_se_habla_se_cruzan_con_la_carta(): void
    {
        [$el, $sobra] = PedidoRestoService::opciones($this->bife(), ['jugoso', 'con papas', 'sin sal', 'bien caliente']);
        $this->assertSame(['Punto' => ['Jugoso'], 'Guarnición' => ['Papas fritas'], 'Sin' => ['Sin sal']], $el);
        $this->assertSame(['bien caliente'], $sobra, 'Lo que no es una opción queda como nota');
    }

    public function test_el_mozo_le_dicta_la_comanda_a_bigcito(): void
    {
        $bife = $this->bife(); $coca = $this->articulo(['name' => 'Coca Cola 500', 'price' => 2500, 'iva' => 0]);
        Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '4', 'sector' => 'Salón', 'capacidad' => 4, 'activa' => true]);
        $mcp = fn($h, $a) => app(Catalogo::class)->llamar($this->dueno, $h, $a, 'asistente');
        // Falta el punto: Bigcito lo pregunta en vez de inventarlo
        $r = $mcp('cargar_comanda', ['mesa' => 'mesa 4', 'items' => [['plato' => 'bife', 'cantidad' => 2]]]);
        $this->assertStringContainsString('Jugoso, A punto', $r['error']);
        $p = $mcp('cargar_comanda', ['mesa' => '4', 'items' => [['plato' => 'bife de chorizo', 'cantidad' => 2, 'opciones' => ['jugoso', 'con papas']], ['plato' => 'coca', 'cantidad' => 1, 'nota' => 'sin hielo']]]);
        $this->assertStringContainsString('Mesa 4', $p['titulo']); $this->assertStringContainsString('Papas fritas', $p['detalle']);
        $ok = $mcp('confirmar_accion', ['propuesta_id' => $p['propuesta_id']]);
        $this->assertTrue($ok['ok']);
        $c = Comanda::latest('id')->first(); $its = $c->items()->orderBy('id')->get();
        $this->assertSame('mesa', $c->tipo); $this->assertCount(2, $its);
        $this->assertEqualsWithDelta(11500, (float) $its[0]->precio_unit, 0.01, 'Bife + papas');
        $this->assertSame('cocina', $its[0]->estado, 'Ya fue a cocina'); $this->assertSame('sin hielo', $its[1]->notas);
        // Vuelve a dictar sobre la misma mesa: agrega a la comanda abierta
        $p2 = $mcp('cargar_comanda', ['mesa' => '4', 'items' => [['plato' => 'coca', 'cantidad' => 2]]]); $mcp('confirmar_accion', ['propuesta_id' => $p2['propuesta_id']]);
        $this->assertSame(1, Comanda::count()); $this->assertCount(3, $c->fresh()->items);
    }

    public function test_pedido_por_whatsapp_entra_como_comanda_de_delivery_con_opciones(): void
    {
        $bife = $this->bife();
        $this->empresa->forceFill(['whatsapp_settings' => ['token' => 'tok', 'phone_id' => '1', 'auto_pedidos' => true, 'auto_responder' => true]])->save();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['items' => [['id' => $bife->id, 'cantidad' => 1, 'opciones' => ['a punto', 'ensalada'], 'nota' => null]], 'direccion' => 'Colón 1234', 'entrega' => 'envio', 'nombre' => 'Marta', 'respuesta' => 'Anotado, Marta.'])]]])
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['items' => [['id' => $bife->id, 'cantidad' => 1, 'opciones' => []]], 'entrega' => 'retiro', 'respuesta' => 'Dale.'])]]]), 'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]])]);
        auth()->logout();
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '3515550000', 'texto' => 'Hola soy Marta, un bife a punto con ensalada a Colón 1234'])->assertOk()->assertJsonCount(1, 'pedidos');
        $c = Comanda::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('delivery', $c->tipo); $this->assertSame('Marta', $c->cliente); $this->assertSame('Colón 1234', $c->direccion); $this->assertSame('3515550000', $c->telefono);
        $it = $c->items()->first(); $this->assertStringContainsString('A punto, Ensalada', $it->descripcion); $this->assertSame('cocina', $it->estado);
        Http::assertSent(fn($r) => str_contains($r->url(), 'graph.facebook.com') && str_contains($r->body(), $c->numeroFormateado()));
        $this->assertTrue(\App\Models\Alerta::withoutGlobalScopes()->where('tipo', 'pedido_whatsapp')->exists());
        $this->assertSame(0, \App\Models\PedidoWeb::withoutGlobalScopes()->count(), 'No duplica en Pedidos web');

        // Si falta una opción obligatoria, se la pregunta por WhatsApp y el plato no se carga
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '3515550001', 'texto' => 'un bife para retirar'])->assertOk()->assertJsonCount(0, 'pedidos');
        $this->assertSame(1, Comanda::withoutGlobalScopes()->count(), 'No queda una comanda vacía');
        Http::assertSent(fn($r) => str_contains($r->url(), 'graph.facebook.com') && str_contains($r->body(), 'Jugoso, A punto'));
    }

    public function test_la_carta_desde_una_foto_se_revisa_y_se_carga_sin_duplicar(): void
    {
        $existente = $this->articulo(['name' => 'Café con leche', 'price' => 2000, 'iva' => 21]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['rubros' => [['nombre' => 'Cafetería', 'platos' => [['nombre' => 'Cafe con Leche', 'precio' => 3630], ['nombre' => 'Medialunas x3', 'precio' => 2420, 'descripcion' => 'De manteca', 'opciones' => [['grupo' => 'Relleno', 'obligatorio' => false, 'multiple' => false, 'opciones' => [['nombre' => 'Jamón y queso', 'precio' => 1000]]]]]]], ['nombre' => 'Almuerzos', 'platos' => [['nombre' => 'Menú ejecutivo', 'precio' => 12100]]]]])]]])]);
        $r = $this->post('/gastronomia/carta/leer', ['archivo' => UploadedFile::fake()->image('carta.jpg')])->assertOk()->json();
        $this->assertTrue($r['ok']); $this->assertSame(3, $r['platos']);
        $this->assertSame($existente->id, $r['rubros'][0]['platos'][0]['existe_id'], 'Lo reconoce aunque cambie mayúsculas y tildes');
        $r['rubros'][1]['platos'][0]['cargar'] = false;
        $this->post('/gastronomia/carta/importar', ['rubros' => $r['rubros']])->assertSessionHas('success');
        $this->assertEqualsWithDelta(3000, (float) $existente->fresh()->price, 0.01, 'Precio final 3630 → neto 3000; no se duplica');
        $medias = Product::where('name', 'Medialunas x3')->first();
        $this->assertNotNull($medias); $this->assertEqualsWithDelta(2000, (float) $medias->price, 0.01); $this->assertSame('Relleno', $medias->modificadores[0]['nombre']); $this->assertSame('Cafetería', $medias->rubro->nombre); $this->assertTrue((bool) $medias->va_cocina);
        $this->assertNull(Product::where('name', 'Menú ejecutivo')->first(), 'Lo destildado no se carga');
        $this->assertSame(2, Product::count());
    }
}
