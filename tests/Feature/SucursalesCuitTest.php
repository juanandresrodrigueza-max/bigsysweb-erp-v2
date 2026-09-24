<?php

namespace Tests\Feature;

use App\Models\BusinessLocation;
use App\Models\Comprobante;
use App\Models\PuntoVenta;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;
use Tests\Feature\ArcaFalsa;

// Sucursales que facturan con su propio CUIT y certificado, y casa central con vista consolidada.
class SucursalesCuitTest extends ErpTestCase
{
    private ArcaFalsa $arca;
    private BusinessLocation $rosario;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->arca = new ArcaFalsa();
        app()->instance('afip.fake', $this->arca);
        $this->empresa->update(['afip_cert_path' => 'afip/1/cert.crt.enc', 'afip_key_path' => 'afip/1/private.key.enc', 'cuit' => '30-71234567-8', 'razon_social' => 'Central SRL']);
        $this->rosario = BusinessLocation::create(['business_id' => $this->empresa->id, 'name' => 'Rosario', 'short_name' => 'ROS', 'is_active' => true, 'cuit' => '30-99999999-3', 'razon_social' => 'Rosario Sur SA', 'condicion_iva' => 'Responsable Inscripto', 'afip_cert_path' => 'afip/1/suc-9/cert.crt.enc', 'afip_key_path' => 'afip/1/suc-9/private.key.enc', 'afip_produccion' => false]);
        \App\Models\Deposito::porDefecto($this->rosario->id);
    }

    private function enSucursal(BusinessLocation $l): void { $this->dueno->forceFill(['current_location_id' => $l->id])->save(); $this->dueno->refresh(); }

    public function test_la_sucursal_con_cuit_propio_factura_con_su_cuit_y_sus_puntos_de_venta(): void
    {
        $p = $this->articulo(['stock_inicial' => 20]);
        $cli = $this->cliente();

        // Sin punto de venta propio no puede emitir: no se puede numerar con los de la casa central.
        $this->enSucursal($this->rosario);
        try { $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['tipo' => 'FA']); $this->fail('Tendría que rechazar por falta de punto de venta'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertStringContainsString('propio CUIT', $e->getMessage()); }

        PuntoVenta::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->rosario->id, 'numero' => 7, 'modo' => 'electronico', 'activo' => true]);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['tipo' => 'FA']);
        $this->assertSame(7, (int) $f->punto_venta, 'Numera con el punto de venta de la sucursal');
        $this->assertSame('30-99999999-3', $this->arca->emisor->cuit, 'Pidió el CAE con el CUIT de la sucursal');
        $this->assertSame('afip/1/suc-9/cert.crt.enc', $this->arca->emisor->afip_cert_path, 'y con su certificado');
        $this->assertSame('Rosario Sur SA', $f->emisor()->razon_social);
        $this->assertSame('Central SRL', $this->empresa->fresh()->razon_social, 'La empresa no se toca');
        $this->get("/comprobantes/{$f->id}/imprimir")->assertOk()->assertSee('Rosario Sur SA')->assertSee('30-99999999-3')->assertDontSee('Central SRL');
        $qr = \App\Services\Afip\QrArca::datos($f->fresh());
        $this->assertSame(30999999993, $qr['cuit']);

        // La casa central sigue facturando con el CUIT de la empresa y su punto de venta.
        $this->enSucursal($this->sucursal);
        $f2 = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['tipo' => 'FA']);
        $this->assertSame(1, (int) $f2->punto_venta);
        $this->assertSame('30-71234567-8', $this->arca->emisor->cuit);
        $this->get("/comprobantes/{$f2->id}/imprimir")->assertOk()->assertSee('Central SRL');

        // Libro IVA Digital por CUIT: solo los comprobantes de esa sucursal.
        $svc = app(\App\Services\Fiscal\LibroIvaDigitalService::class);
        $todo = $svc->ventas($this->empresa, today()->toDateString(), today()->toDateString());
        $solo = $svc->ventas($this->empresa, today()->toDateString(), today()->toDateString(), $this->rosario->id);
        $this->assertSame(2, substr_count($todo['LIBRO_IVA_DIGITAL_VENTAS_CBTE.txt'], "\r\n"));
        $this->assertSame(1, substr_count($solo['LIBRO_IVA_DIGITAL_VENTAS_CBTE.txt'], "\r\n"));
        $this->get('/contable/fiscal')->assertOk()->assertInertia(fn($a) => $a->component('Contable/Fiscal', false)->where('sucursalesCuit.0.cuit', '30-99999999-3'));
    }

    public function test_casa_central_ve_el_consolidado_de_todas_las_sucursales(): void
    {
        $p = $this->articulo(['stock_inicial' => 20]);
        $cli = $this->cliente();
        PuntoVenta::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->rosario->id, 'numero' => 7, 'modo' => 'electronico', 'activo' => true]);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]], ['tipo' => 'FA']); // central 1210
        $this->enSucursal($this->rosario);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 3000]], ['tipo' => 'FA']); // rosario 3630
        $this->enSucursal($this->sucursal);

        $ventas = fn() => collect($this->get('/dashboard')->assertOk()->viewData('page')['props']['kpis'])->firstWhere('key', 'ventas')['valor'];
        $this->assertEqualsWithDelta(1210, $ventas(), 0.01, 'Parado en la central ve solo la central');
        $this->post('/sucursal/consolidado')->assertRedirect();
        $this->assertTrue($this->dueno->fresh()->ver_consolidado);
        $this->assertEqualsWithDelta(4840, $ventas(), 0.01, 'Consolidado: central + Rosario');
        $this->get('/dashboard')->assertInertia(fn($a) => $a->where('sucursales.consolidado', true)->where('sucursales.puede_consolidar', true));
        $this->post('/sucursal/consolidado');
        $this->assertEqualsWithDelta(1210, $ventas(), 0.01);

        // Un vendedor no puede consolidar.
        $this->actingAs($this->usuarioConRol('vendedor'))->post('/sucursal/consolidado')->assertForbidden();
    }

    public function test_pantalla_de_sucursales_guarda_cuit_propio_y_certificados(): void
    {
        $this->get('/configuracion/sucursales')->assertOk()->assertInertia(fn($a) => $a->component('Configuracion/Sucursales', false)->where('items.1.cuit', '30-99999999-3')->where('items.1.cert', true)->where('empresa.cuit', '30-71234567-8'));
        // CUIT sin razón social: error claro.
        $this->post('/configuracion/sucursales', ['name' => 'Córdoba', 'is_active' => true, 'cuit' => '30-55555555-5'])->assertSessionHasErrors('razon_social');
        $this->post('/configuracion/sucursales', ['name' => 'Córdoba', 'is_active' => true, 'cuit' => '123'])->assertSessionHasErrors('cuit');
        $this->post('/configuracion/sucursales', ['name' => 'Córdoba', 'is_active' => true, 'cuit' => '30-55555555-5', 'razon_social' => 'Córdoba Norte SRL', 'condicion_iva' => 'Monotributista'])->assertSessionHasNoErrors();
        $cba = BusinessLocation::where('name', 'Córdoba')->first();
        $this->assertSame('Córdoba Norte SRL', $cba->razon_social);
        $this->assertTrue($cba->tieneCuitPropio()); $this->assertFalse($cba->arcaConfigurado());
        $this->assertSame('Monotributista', $this->empresa->emisorPara($cba)->condicion_iva);

        $this->post("/configuracion/sucursales/{$cba->id}/certificados", ['cert' => UploadedFile::fake()->createWithContent('c.crt', "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----"), 'key' => UploadedFile::fake()->createWithContent('k.key', "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----")])->assertSessionHasNoErrors();
        $this->assertTrue($cba->fresh()->arcaConfigurado());
        Storage::disk('local')->assertExists("afip/{$this->empresa->id}/suc-{$cba->id}/cert.crt.enc");
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', Storage::disk('local')->get("afip/{$this->empresa->id}/suc-{$cba->id}/cert.crt.enc"), 'Se guarda cifrado');

        // Quitar el CUIT propio limpia los datos fiscales y vuelve a facturar con la empresa.
        $this->post("/configuracion/sucursales/{$cba->id}", ['name' => 'Córdoba', 'is_active' => true, 'cuit' => ''])->assertSessionHasNoErrors();
        $this->assertNull($cba->fresh()->cuit);
        $this->assertSame('30-71234567-8', $this->empresa->emisorPara($cba->fresh())->cuit);
    }
}
