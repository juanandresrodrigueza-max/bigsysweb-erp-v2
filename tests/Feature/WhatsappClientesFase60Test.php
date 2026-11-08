<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Alerta;
use App\Models\NotaCliente;
use App\Models\PedidoWeb;
use App\Services\Canales\AtencionClientesService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 60: Bigcito atiende a los clientes por WhatsApp (saldo, pedido, stock, reclamos, hablar con alguien) sin pisar los pedidos.
class WhatsappClientesFase60Test extends ErpTestCase
{
    private function conectar(): void
    {
        $this->empresa->forceFill(['whatsapp_settings' => ['token' => 'tk', 'phone_id' => '111', 'verify_token' => 'v']])->save();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
    }

    private function enviado(string $fragmento): bool
    {
        return collect(Http::recorded())->contains(fn($par) => str_contains($par[0]->url(), 'graph.facebook.com') && str_contains($par[0]->data()['text']['body'] ?? '', $fragmento));
    }

    public function test_saldo_pedido_y_stock_se_responden_solos_y_un_pedido_sigue_siendo_pedido(): void
    {
        $this->conectar();
        $cli = $this->cliente(['name' => 'Marta Pérez', 'mobile' => '+54 9 351 555-1234', 'cuit' => '27-22333444-5']);
        $art = $this->articulo(['name' => 'Cemento Loma Negra x 50 kg', 'price' => 9000, 'stock_inicial' => 40, 'en_tienda' => true]);
        $f = $this->factura($cli, [['product_id' => $art->id, 'cantidad' => 2, 'precio_unit' => 9000, 'alicuota_iva' => 21]], []);
        $f->forceFill(['fecha_vto' => today()->subDays(5)->toDateString()])->save(); // vencida hace 5 días
        auth()->logout();

        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515551234', 'texto' => 'Hola, ¿cuánto debo?'])->assertOk()->json();
        $this->assertSame(['saldo'], $r['atendidos']); $this->assertSame([], $r['pedidos']);
        $this->assertTrue($this->enviado('Hola Marta')); $this->assertTrue($this->enviado($f->numeroFormateado())); $this->assertTrue($this->enviado('(vencida)'));

        // Un número desconocido no recibe el saldo de nadie: se le pide el CUIT. Con el CUIT en el mensaje, lo encuentra.
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493510000000', 'texto' => 'cuanto debo'])->assertOk();
        $this->assertTrue($this->enviado('Pasame tu CUIT'));
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493510000000', 'texto' => 'mi cuit es 27-22333444-5, cuanto debo?'])->assertOk();
        $this->assertSame(2, collect(Http::recorded())->filter(fn($p) => str_contains($p[0]->data()['text']['body'] ?? '', 'Hola Marta'))->count());

        // Stock y precio: sin la cantidad exacta.
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515551234', 'texto' => '¿Tenés cemento loma negra? ¿a cuánto?'])->assertOk()->assertJsonPath('atendidos.0', 'stock');
        $this->assertTrue($this->enviado('Cemento Loma Negra x 50 kg: $ 9.000,00')); $this->assertTrue($this->enviado('(hay stock)')); $this->assertFalse($this->enviado('40'));

        // Un pedido sigue siendo pedido (no lo intercepta) y después se puede preguntar por él.
        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515551234', 'texto' => 'Quiero 3 cemento loma negra para retirar'])->assertOk()->json();
        $this->assertSame([], $r['atendidos']); $this->assertCount(1, $r['pedidos']);
        $p = PedidoWeb::withoutGlobalScopes()->first();
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515551234', 'texto' => 'che donde está mi pedido?'])->assertOk()->assertJsonPath('atendidos.0', 'pedido');
        $this->assertTrue($this->enviado($p->numeroFormateado())); $this->assertTrue($this->enviado($p->urlPublica()));
    }

    public function test_reclamos_quedan_en_la_ficha_con_aviso_y_bigcito_los_lista(): void
    {
        $this->conectar();
        $cli = $this->cliente(['name' => 'Juan Gómez', 'phone' => '3515559999']);
        auth()->logout();
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515559999', 'texto' => 'Lo que me mandaron ayer llegó todo roto'])->assertOk()->assertJsonPath('atendidos.0', 'reclamo');
        $this->assertTrue($this->enviado('Tomé nota del reclamo'));
        $nota = NotaCliente::withoutGlobalScopes()->first(); $this->assertSame($cli->id, $nota->contact_id); $this->assertStringContainsString('llegó todo roto', $nota->texto);
        $al = Alerta::withoutGlobalScopes()->where('tipo', 'reclamo_whatsapp')->first(); $this->assertStringContainsString('Juan Gómez', $al->titulo); $this->assertSame("/clientes/{$cli->id}", $al->url);

        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515559999', 'texto' => 'quiero hablar con una persona'])->assertOk()->assertJsonPath('atendidos.0', 'persona');
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('tipo', 'whatsapp_persona')->exists());

        $r = app(Catalogo::class)->llamar($this->dueno, 'reclamos_whatsapp', [], 'asistente');
        $this->assertSame(2, $r['pendientes']); $this->assertSame('reclamo', collect($r['reclamos'])->firstWhere('tipo', 'reclamo')['tipo']);
        $this->assertStringContainsString('Juan Gómez', collect($r['reclamos'])->pluck('quien')->implode(' '));
    }

    public function test_la_ia_clasifica_lo_dudoso_y_el_dueno_puede_apagarlo(): void
    {
        $this->conectar();
        $cli = $this->cliente(['name' => 'Ana Ruiz', 'phone' => '3515557777']);
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"items":[],"direccion":null,"entrega":"retiro","nombre":null,"notas":null,"respuesta":"Uy, contame qué pasó.","consulta":"reclamo","articulo":null}']]]), 'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);
        auth()->logout();
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515557777', 'texto' => 'la verdad que lo de ayer fue un desastre eh'])->assertOk()->assertJsonPath('atendidos.0', 'reclamo');
        $this->assertSame(1, NotaCliente::withoutGlobalScopes()->count());

        // Apagado: el mensaje sigue el camino de siempre.
        $this->empresa->forceFill(['whatsapp_settings' => ['token' => 'tk', 'phone_id' => '111', 'verify_token' => 'v', 'atencion_clientes' => false]])->save();
        $this->assertFalse(AtencionClientesService::activo($this->empresa->fresh()));
        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515557777', 'texto' => 'cuanto debo?'])->assertOk()->json();
        $this->assertSame([], $r['atendidos']);
    }
}
