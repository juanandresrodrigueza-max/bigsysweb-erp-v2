<?php

namespace Tests\Feature;

use App\Jobs\EnviarCatalogoJob;
use App\Models\Catalogo;
use App\Models\Envio;
use App\Models\Rubro;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\ErpTestCase;

// Fase 25.3: catálogo por lista de precios con la identidad de la empresa, para mandar a clientes.
class CatalogosTest extends ErpTestCase
{
    private function catalogo(array $d = []): Catalogo
    {
        $this->post('/stock/catalogos', array_merge(['nombre' => 'Mayorista septiembre', 'lista' => 2, 'iva_incluido' => true, 'mostrar_fotos' => true, 'mostrar_codigo' => true, 'nota' => 'Pedido mínimo $ 50.000'], $d))->assertSessionHasNoErrors();
        return Catalogo::latest('id')->firstOrFail();
    }

    public function test_el_link_publico_muestra_la_lista_con_iva_rubros_y_colores(): void
    {
        $this->empresa->update(['marca' => ['color_primario' => '#0a5c36']]);
        $hierros = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Hierros']);
        $aletado = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Aletado', 'parent_id' => $hierros->id]);
        $h8 = $this->articulo(['name' => 'Hierro 8', 'sku' => 'H8', 'price' => 1000, 'prices' => ['2' => 800], 'rubro_id' => $aletado->id, 'stock_inicial' => 5]);
        $sin = $this->articulo(['name' => 'Cal sin stock', 'price' => 300, 'prices' => ['2' => 250], 'stock_inicial' => 0]);
        $insumo = $this->articulo(['name' => 'Insumo interno', 'tipo' => 'insumo', 'price' => 10]);
        $cat = $this->catalogo();
        $this->assertSame(32, strlen($cat->token));

        auth()->logout();
        $html = $this->get("/catalogo/{$cat->token}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->getContent();
        $this->assertStringContainsString('Hierro 8', $html);
        $this->assertStringContainsString('$ 968,00', $html, 'Lista 2 con IVA: 800 × 1,21');
        $this->assertStringContainsString('Hierros › Aletado', $html);
        $this->assertStringContainsString('#0a5c36', $html);
        $this->assertStringContainsString('Pedido mínimo $ 50.000', $html);
        $this->assertStringNotContainsString('Insumo interno', $html);
        $this->assertStringContainsString('Cal sin stock', $html);
        $this->assertSame(1, $cat->fresh()->vistas);
        // PDF.
        $r = $this->get("/catalogo/{$cat->token}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $r->getContent());
        // Link inválido o catálogo pausado → 404.
        $this->get('/catalogo/no-existe')->assertNotFound();
        $cat->update(['activo' => false]);
        $this->get("/catalogo/{$cat->token}")->assertNotFound();
    }

    public function test_filtros_por_rubro_solo_con_stock_y_sin_iva(): void
    {
        $hierros = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Hierros']);
        $aletado = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Aletado', 'parent_id' => $hierros->id]);
        $this->articulo(['name' => 'Hierro 8', 'price' => 1000, 'rubro_id' => $aletado->id, 'stock_inicial' => 5]);
        $this->articulo(['name' => 'Cemento', 'price' => 500, 'stock_inicial' => 5]);
        $this->articulo(['name' => 'Hierro agotado', 'price' => 900, 'rubro_id' => $aletado->id, 'stock_inicial' => 0]);
        $cat = $this->catalogo(['lista' => 1, 'rubros' => [$hierros->id], 'solo_con_stock' => true, 'iva_incluido' => false]);
        $html = $this->get("/catalogo/{$cat->token}")->getContent();
        $this->assertStringContainsString('Hierro 8', $html, 'El rubro padre incluye los subrubros');
        $this->assertStringNotContainsString('Cemento', $html);
        $this->assertStringNotContainsString('Hierro agotado', $html);
        $this->assertStringContainsString('$ 1.000,00', $html);
        $this->assertStringContainsString('Precios netos, más IVA.', $html);
    }

    public function test_para_un_cliente_muestra_sus_precios_pactados_y_se_manda_por_mail_y_whatsapp(): void
    {
        Mail::fake();
        $cli = $this->cliente(['name' => 'López SRL', 'lista_precios' => 3, 'descuento' => 10, 'email' => 'lopez@mail.com', 'mobile' => '3515551234']);
        $p = $this->articulo(['name' => 'Ladrillo', 'price' => 1000, 'prices' => ['2' => 900, '3' => 800]]);
        $q = $this->articulo(['name' => 'Arena', 'price' => 500, 'prices' => ['2' => 450, '3' => 400]]);
        $this->post("/clientes/{$cli->id}/condiciones", ['product_id' => $p->id, 'precio' => 700])->assertSessionHasNoErrors();
        $cat = $this->catalogo(['iva_incluido' => false]);

        $url = $cat->url($cli);
        $this->assertStringContainsString('?c=', $url);
        $html = $this->get(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY))->getContent();
        $this->assertStringContainsString('Precios para <b>López SRL</b>', $html);
        $this->assertStringContainsString('$ 700,00', $html, 'Precio pactado');
        $this->assertStringContainsString('$ 360,00', $html, 'Lista 3 del cliente con su 10%');
        // Un token de cliente de otra empresa no aplica: queda la lista del catálogo.
        $this->assertStringContainsString('$ 450,00', $this->get("/catalogo/{$cat->token}?c=inventado")->getContent());

        $this->post("/stock/catalogos/{$cat->id}/enviar", ['contact_id' => $cli->id, 'canal' => 'mail'])->assertSessionHas('success', fn($m) => str_contains($m, 'lopez@mail.com'));
        Mail::assertSent(\App\Mail\DocumentoMail::class);
        $this->post("/stock/catalogos/{$cat->id}/enviar", ['contact_id' => $cli->id, 'canal' => 'whatsapp'])->assertSessionHas('abrir', fn($l) => str_starts_with($l, 'https://wa.me/543515551234?text=') && str_contains(urldecode($l), '/catalogo/' . $cat->token . '?c='));
        $this->assertSame(2, Envio::where('modelo', 'Catalogo')->where('modelo_id', $cat->id)->where('contact_id', $cli->id)->count());
    }

    public function test_mandar_a_toda_la_lista_en_cola_y_permisos(): void
    {
        Queue::fake();
        $this->cliente(['name' => 'A', 'lista_precios' => 2, 'email' => 'a@x.com']);
        $this->cliente(['name' => 'B', 'lista_precios' => 2, 'email' => 'b@x.com']);
        $this->cliente(['name' => 'C sin mail', 'lista_precios' => 2, 'email' => null]);
        $this->cliente(['name' => 'D otra lista', 'lista_precios' => 1, 'email' => 'd@x.com']);
        $cat = $this->catalogo();
        $this->post("/stock/catalogos/{$cat->id}/enviar-lista", ['canal' => 'mail'])->assertSessionHas('success', fn($m) => str_contains($m, '2 clientes'));
        Queue::assertPushed(EnviarCatalogoJob::class, 2);
        $this->post("/stock/catalogos/{$cat->id}/enviar-lista", ['canal' => 'whatsapp'])->assertStatus(422);

        // Renovar el link invalida el anterior.
        $viejo = $cat->token;
        $this->post("/stock/catalogos/{$cat->id}/renovar")->assertSessionHasNoErrors();
        $this->get("/catalogo/{$viejo}")->assertNotFound();

        // El vendedor ve y manda, pero no crea ni edita.
        $this->actingAs($this->usuarioConRol('vendedor'));
        $this->get('/stock/catalogos')->assertOk()->assertInertia(fn($a) => $a->component('Stock/Catalogos', false)->has('catalogos', 1)->where('catalogos.0.clientes_lista', 3));
        $this->post('/stock/catalogos', ['nombre' => 'x', 'lista' => 1])->assertForbidden();
    }

    public function test_el_job_manda_con_los_precios_del_cliente(): void
    {
        Mail::fake();
        $cli = $this->cliente(['lista_precios' => 2, 'email' => 'a@x.com']);
        $cat = $this->catalogo();
        (new EnviarCatalogoJob($cat->id, $cli->id, 'mail', $this->dueno->id))->handle(app(\App\Services\Envios\EnvioService::class));
        Mail::assertSent(\App\Mail\DocumentoMail::class, 1);
        $this->assertSame('enviado', Envio::where('modelo', 'Catalogo')->first()->estado);
    }
}
