<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Services\Comprobantes\CobroService;
use App\Services\Envios\EnvioService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 25.4: comprobantes con logo, colores y datos configurables; lo fiscal sale siempre.
class MarcaComprobantesTest extends ErpTestCase
{
    private function marca(array $d = [])
    {
        return $this->post('/configuracion/empresa/marca', array_replace_recursive([
            'color_primario' => '#0a5c36', 'color_secundario' => '#f2c200', 'estilo' => 'banda', 'validez_presupuesto' => 15,
            'datos_extra' => "www.corralon.com.ar\nAlias CORRALON.PAGOS", 'pie' => 'Gracias por su compra',
            'mostrar' => ['logo' => true, 'codigo' => false, 'vendedor' => true, 'saldo' => true, 'firma_remito' => true, 'bonificacion' => true],
        ], $d));
    }

    public function test_la_factura_sale_con_colores_logo_y_datos_extra_y_siempre_con_lo_fiscal(): void
    {
        Storage::fake('local');
        $this->post('/configuracion/empresa', ['name' => 'Corralón Demo', 'razon_social' => 'Corralón Demo SRL', 'email' => 'demo@test.com', 'cuit' => '30-71234567-8', 'condicion_iva' => 'Responsable Inscripto',
            'address' => 'Av. Colón 1234', 'city' => 'Córdoba', 'province' => 'Córdoba', 'iibb' => '901-123456-7', 'inicio_actividades' => '2015-03-01'])->assertSessionHasNoErrors();
        $this->marca()->assertSessionHasNoErrors();
        $this->post('/configuracion/empresa/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)])->assertSessionHasNoErrors();
        $this->empresa->refresh();
        $this->assertNotNull($this->empresa->logo);
        Storage::disk('local')->assertExists($this->empresa->logo);

        $cli = $this->cliente(['balance' => 0]);
        $p = $this->articulo(['stock_inicial' => 5, 'sku' => 'LAD12']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]], ['tipo' => 'FA']);
        $html = $this->get("/comprobantes/{$f->id}/imprimir")->assertOk()->getContent();
        $this->assertStringContainsString('#0a5c36', $html);
        $this->assertStringContainsString('#f2c200', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html, 'Logo embebido');
        $this->assertStringContainsString('www.corralon.com.ar', $html);
        $this->assertStringContainsString('Gracias por su compra', $html);
        $this->assertStringContainsString('Saldo de su cuenta corriente', $html);
        $this->assertStringNotContainsString('<th>Código</th>', $html, 'Columna de código apagada');
        // Lo fiscal no se puede apagar.
        foreach (['Corralón Demo SRL', 'Av. Colón 1234', '30-71234567-8', 'Ingresos Brutos: 901-123456-7', 'Inicio de actividades: 01/03/2015', 'Responsable Inscripto', 'COD. 001'] as $t) $this->assertStringContainsString($t, $html, "Falta: {$t}");
        // Sobre verde oscuro el texto va blanco; sobre amarillo, oscuro.
        $this->assertStringContainsString('color:#ffffff', $html);
        $this->assertSame('#1c1a18', Business::textoSobre('#f2c200'));
        $this->assertSame('#ffffff', Business::textoSobre('#0a5c36'));

        // Recibo, ticket y presupuesto usan la misma identidad.
        $cobro = app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 1210]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 1210]]]);
        $this->get("/clientes/cobros/{$cobro->id}/imprimir")->assertOk()->assertSee('#0a5c36', false)->assertSee('Alias CORRALON.PAGOS');
        $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
        $pre = $svc->emitir($svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'PRE', 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 10]]]));
        $this->get("/comprobantes/{$pre->id}/imprimir")->assertSee('válido por 15 días');

        // El PDF que se manda por mail se arma (factura y recibo) con el logo.
        [$pdf] = app(EnvioService::class)->pdf($f->fresh());
        $this->assertStringStartsWith('%PDF', $pdf);
        [$pdfR] = app(EnvioService::class)->pdf($cobro->fresh());
        $this->assertStringStartsWith('%PDF', $pdfR);
    }

    public function test_vista_previa_sin_facturas_y_validaciones(): void
    {
        $this->get('/configuracion/empresa/muestra')->assertOk()->assertSee('Cliente de ejemplo S.A.');
        $this->get('/configuracion')->assertInertia(fn($a) => $a->component('Configuracion/Empresa', false)->where('marca.color_primario', '#e4003f')->has('estilos'));
        $this->marca(['color_primario' => 'rojo'])->assertSessionHasErrors('color_primario');
        $this->marca(['estilo' => 'otro'])->assertSessionHasErrors('estilo');
        $this->post('/configuracion/empresa/logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
        // Un vendedor no cambia el diseño.
        $this->actingAs($this->usuarioConRol('vendedor'));
        $this->marca()->assertForbidden();
    }
}
