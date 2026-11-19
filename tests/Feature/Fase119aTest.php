<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Services\IA\PantallaService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 119.1: Bigcito sabe qué está viendo el usuario y lo lleva por el sistema (abrir pantallas, fichas e imprimir).
class Fase119aTest extends ErpTestCase
{
    private function conIa(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-ant-test', 'modelo' => 'claude-haiku-5-5']])->save(); $this->actingAs($this->dueno->fresh());
    }

    public function test_de_la_url_saca_que_esta_viendo_el_usuario(): void
    {
        $svc = app(PantallaService::class);
        $c = $this->cliente(['name' => 'López SRL', 'cuit' => '30-71234567-8']); $a = $this->articulo(['name' => 'Cemento 50 kg']);
        $f = $this->factura($c, [['descripcion' => 'Cemento', 'cantidad' => 2, 'precio_unit' => 1000, 'alicuota_iva' => 21]]);

        $v = $svc->contexto($this->dueno, "/clientes/{$c->id}?tab=cuenta");
        $this->assertSame('cliente', $v['tipo']); $this->assertSame($c->id, $v['id']); $this->assertStringContainsString('López SRL', $v['texto']); $this->assertStringContainsString('Saldo', $v['texto']);
        $v = $svc->contexto($this->dueno, "/stock/{$a->id}");
        $this->assertSame('articulo', $v['tipo']); $this->assertStringContainsString('Cemento 50 kg', $v['texto']);
        $v = $svc->contexto($this->dueno, "/comprobantes/{$f->id}");
        $this->assertSame('comprobante', $v['tipo']); $this->assertStringContainsString('López SRL', $v['texto']); $this->assertStringContainsString("id {$f->id}", $v['texto']);
        $v = $svc->contexto($this->dueno, '/fondos/cierres');
        $this->assertSame('pantalla', $v['tipo']); $this->assertSame('Cerrar caja', $v['nombre']);
        $this->assertSame('pantalla', $svc->contexto($this->dueno, '/clientes/999999')['tipo'], 'una ficha que no existe (o de otra empresa) no da contexto de ficha ni rompe');
        $this->assertNull($svc->contexto($this->dueno, ''));
    }

    public function test_bigcito_recibe_lo_que_ve_el_usuario_en_el_prompt(): void
    {
        $this->conIa(); $c = $this->cliente(['name' => 'García Hnos']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'García Hnos no debe nada.']]])]);
        $this->postJson('/agente/chat', ['mensaje' => '¿cuánto me debe este?', 'pantalla' => 'Clientes/Ver', 'url' => "/clientes/{$c->id}"])->assertOk()->assertJsonPath('respuesta', 'García Hnos no debe nada.');
        Http::assertSent(fn($r) => str_contains($r['system'], 'Lo que está viendo ahora: Ficha del cliente «García Hnos»') && str_contains($r['system'], "id {$c->id}"));
        // Sin url: no hay ficha.
        $this->postJson('/agente/chat', ['mensaje' => 'hola'])->assertOk();
        Http::assertSent(fn($r) => str_contains($r['system'], 'Lo que está viendo ahora: nada en particular'));
    }

    public function test_abrir_pantalla_encuentra_por_nombre_y_respeta_permisos(): void
    {
        $cat = app(Catalogo::class);
        $r = $cat->llamar($this->dueno, 'abrir_pantalla', ['pantalla' => 'cerrar caja'], 'asistente');
        $this->assertSame('/fondos/cierres', $r['_navegar']); $this->assertStringContainsString('Cerrar caja', $r['abierta']);
        $this->assertSame('/comprobantes/pedidos', $cat->llamar($this->dueno, 'abrir_pantalla', ['pantalla' => 'los pedidos web'], 'asistente')['_navegar']);
        $this->assertSame('/contable/iva', $cat->llamar($this->dueno, 'abrir_pantalla', ['pantalla' => 'libros de IVA'], 'asistente')['_navegar']);
        $this->assertSame('/clientes', $cat->llamar($this->dueno, 'abrir_pantalla', ['pantalla' => 'clientes'], 'asistente')['_navegar']);
        $this->assertSame('/fondos', $cat->llamar($this->dueno, 'abrir_pantalla', ['pantalla' => 'fondos'], 'asistente')['_navegar'], 'el nombre viejo también sirve');
        // Nueva factura con el cliente ya elegido.
        $c = $this->cliente(['name' => 'López SRL']);
        $this->assertSame("/comprobantes/nuevo?contact_id={$c->id}", $cat->llamar($this->dueno, 'abrir_pantalla', ['pantalla' => 'nueva factura', 'cliente' => 'lopez'], 'asistente')['_navegar']);
        $this->assertSame("/comprobantes/nuevo?tipo=PRE&contact_id={$c->id}", $cat->llamar($this->dueno, 'abrir_pantalla', ['pantalla' => 'nuevo presupuesto', 'cliente' => 'López SRL'], 'asistente')['_navegar']);
        $this->assertArrayHasKey('error', $cat->llamar($this->dueno, 'abrir_pantalla', ['pantalla' => 'xyzzy inexistente'], 'asistente'));

        // Un vendedor no llega a Contable ni a Configuración.
        $v = $this->usuarioConRol('vendedor'); $this->actingAs($v); \Illuminate\Support\Facades\Auth::login($v);
        $this->assertArrayHasKey('error', $cat->llamar($v, 'abrir_pantalla', ['pantalla' => 'libros IVA'], 'asistente'));
        $this->assertArrayHasKey('_navegar', $cat->llamar($v, 'abrir_pantalla', ['pantalla' => 'clientes'], 'asistente'));
    }

    public function test_abrir_ficha_e_imprimir(): void
    {
        $cat = app(Catalogo::class);
        $c = $this->cliente(['name' => 'López SRL']); $p = $this->proveedor(['name' => 'Acindar']); $a = $this->articulo(['name' => 'Cemento 50 kg']);
        $f = $this->factura($c, [['descripcion' => 'Cemento', 'cantidad' => 2, 'precio_unit' => 1000, 'alicuota_iva' => 21]]);
        $this->assertSame("/clientes/{$c->id}", $cat->llamar($this->dueno, 'abrir_ficha', ['tipo' => 'cliente', 'buscar' => 'lopez'], 'asistente')['_navegar']);
        $this->assertSame("/proveedores/{$p->id}", $cat->llamar($this->dueno, 'abrir_ficha', ['tipo' => 'proveedor', 'buscar' => 'Acindar'], 'asistente')['_navegar']);
        $this->assertSame("/stock/{$a->id}", $cat->llamar($this->dueno, 'abrir_ficha', ['tipo' => 'articulo', 'buscar' => 'cemento'], 'asistente')['_navegar']);
        $this->assertSame("/comprobantes/{$f->id}", $cat->llamar($this->dueno, 'abrir_ficha', ['tipo' => 'comprobante', 'buscar' => 'López'], 'asistente')['_navegar'], 'por cliente abre el último');
        $this->assertSame("/comprobantes/{$f->id}", $cat->llamar($this->dueno, 'abrir_ficha', ['tipo' => 'comprobante', 'buscar' => (string) $f->id], 'asistente')['_navegar']);
        $this->assertArrayHasKey('error', $cat->llamar($this->dueno, 'abrir_ficha', ['tipo' => 'cliente', 'buscar' => 'Nadie Existe'], 'asistente'));
        $this->assertSame("/comprobantes/{$f->id}/imprimir", $cat->llamar($this->dueno, 'imprimir', ['buscar' => (string) $f->id], 'asistente')['_abrir']);
    }

    public function test_el_panel_recibe_la_orden_de_navegar_y_por_whatsapp_solo_el_enlace(): void
    {
        $this->conIa(); $c = $this->cliente(['name' => 'López SRL']);
        $sec = fn() => Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'abrir_ficha', 'input' => ['tipo' => 'cliente', 'buscar' => 'López']]]])
            ->push(['content' => [['type' => 'text', 'text' => 'Te abrí la ficha de López SRL.']]])]);
        $sec();
        $r = $this->postJson('/agente/chat', ['mensaje' => 'abrime la ficha de López', 'url' => '/dashboard'])->assertOk()->json();
        $this->assertSame("/clientes/{$c->id}", $r['navegar']); $this->assertSame('Te abrí la ficha de López SRL.', $r['respuesta']); $this->assertArrayNotHasKey('tabla', $r);
        Http::assertSent(fn($req) => str_contains($req->body(), 'tool_result') ? ! str_contains($req->body(), '_navegar') : true);

        $sec();
        $r = app(\App\Services\IA\BigcitoService::class)->responder($this->dueno->fresh(), ['mensaje' => 'abrime la ficha de López', 'origen' => 'whatsapp']);
        $this->assertArrayNotHasKey('navegar', $r);
    }
    public function test_sin_clave_de_ia_tambien_abre_pantallas_y_fichas(): void
    {
        config(['services.anthropic.api_key' => null]);
        $c = $this->cliente(['name' => 'LOPEZ SRL']);
        $r = $this->postJson('/agente/chat', ['mensaje' => 'Abrime la ficha de López'])->assertOk()->json();
        $this->assertSame("/clientes/{$c->id}", $r['navegar']); $this->assertSame('local', $r['modo']);
        $this->assertSame('/fondos/cierres', $this->postJson('/agente/chat', ['mensaje' => 'llevame a cerrar caja'])->json('navegar'));
        $this->assertSame('/stock', $this->postJson('/agente/chat', ['mensaje' => 'ir a stock'])->json('navegar'));
        $this->assertArrayNotHasKey('navegar', $this->postJson('/agente/chat', ['mensaje' => 'abrime algo que no existe xyzzy'])->json());
    }
}
