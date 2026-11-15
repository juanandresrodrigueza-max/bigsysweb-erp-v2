<?php

namespace Tests\Feature;

use App\Models\Asiento;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\CuentaCorriente;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 104 · «Facturar después»: un comprobante interno pasa a factura fiscal con CAE sin tocar stock, cuenta corriente ni cobros; y el aviso al cobrar un interno con medio electrónico.
class Fase104Test extends ErpTestCase
{
    private ArcaFalsa $arca;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->arca = new ArcaFalsa();
        app()->instance('afip.fake', $this->arca);
        $this->empresa->update(['afip_cert_path' => 'afip/1/cert.crt.enc', 'afip_key_path' => 'afip/1/private.key.enc', 'cuit' => '30-71234567-8']);
    }

    public function test_el_interno_pasa_a_fiscal_con_cae_y_conserva_stock_cuenta_corriente_y_cobros(): void
    {
        $p = $this->articulo(['stock_inicial' => 10, 'iva' => 21]); $cli = $this->cliente(['name' => 'Cliente Mixto']);
        $int = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 3, 'precio_unit' => 1000]], ['sin_arca' => true, 'condicion' => 'cta_cte', 'fecha' => today()->subDays(20)->toDateString()]);
        $this->assertSame('interno', $int->afip_estado); $this->assertSame(7.0, (float) $p->fresh()->stock); $numeroInterno = $int->numeroFormateado();
        // Un cobro parcial, en efectivo, imputado al interno: no va a los libros.
        $cobro = app(\App\Services\Comprobantes\CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 1000, 'cuenta_fondos_id' => \App\Models\CuentaFondos::where('tipo', 'caja')->value('id')]], 'imputaciones' => [['comprobante_id' => $int->id, 'monto' => 1000]]]);
        $this->assertFalse(Asiento::where('origen', 'cobro')->where('origen_id', $cobro->id)->exists());
        $saldoAntes = (float) $int->fresh()->saldo; $this->assertEqualsWithDelta((float) $int->total - 1000, $saldoAntes, 0.01);
        $this->assertTrue($this->get("/comprobantes/{$int->id}")->viewData('page')['props']['puedeFacturar']);

        $this->post("/comprobantes/{$int->id}/facturar")->assertRedirect()->assertSessionHas('success', fn($m) => str_contains($m, 'con CAE'));
        $f = $int->fresh();
        $this->assertFalse((bool) $f->sin_arca); $this->assertSame('aprobado', $f->afip_estado); $this->assertSame(42, (int) $f->numero); $this->assertSame('71234567890123', $f->cae); $this->assertSame('FA', $f->tipo);
        $this->assertSame(today()->toDateString(), $f->fecha->toDateString()); $this->assertStringContainsString("antes comprobante interno {$numeroInterno}", $f->notas);
        $this->assertSame(7.0, (float) $p->fresh()->stock, 'El stock no se descuenta dos veces');
        $this->assertEqualsWithDelta($saldoAntes, (float) $f->saldo, 0.01, 'La cuenta corriente y el cobro quedan como estaban');
        $this->assertSame(1, $cobro->fresh()->imputaciones()->count());
        $this->assertStringContainsString('Factura A', CuentaCorriente::where('comprobante_id', $f->id)->where('debe', '>', 0)->value('concepto'));
        // Recién ahora entra en los libros: asiento propio y del recibo; libro de IVA lo lista con la fecha de hoy.
        $this->assertTrue(Asiento::where('origen', 'venta')->where('origen_id', $f->id)->exists()); $this->assertTrue(Asiento::where('origen', 'cobro')->where('origen_id', $cobro->id)->exists());
        $this->assertSame(1, count($this->arca->enviados)); $this->assertSame(today()->format('Ymd'), $this->arca->enviados[0]['CbteFch']);
        $d = today()->startOfMonth()->toDateString(); $h = today()->endOfMonth()->toDateString();
        $this->get("/contable/iva?libro=ventas&desde={$d}&hasta={$h}")->assertInertia(fn($pg) => $pg->has('filas', 1));
        $this->assertFalse($this->get("/comprobantes/{$f->id}")->viewData('page')['props']['puedeFacturar']);
        // Ya no es interno: no se puede volver a pasar. Y el PDF ya no dice «no válido».
        $this->post("/comprobantes/{$f->id}/facturar")->assertStatus(422);
        $this->get("/comprobantes/{$f->id}/imprimir")->assertOk()->assertDontSee('NO VÁLIDO COMO FACTURA')->assertSee('CAE');
        $this->assertAsientosBalancean();
    }

    public function test_bloqueos_bigcito_y_el_aviso_en_el_recibo(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente(['name' => 'Otro Cliente']);
        // Un interno con nota de crédito no se pasa solo.
        $conNc = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 500]], ['sin_arca' => true]);
        $this->post("/comprobantes/{$conNc->id}/convertir", ['tipo' => 'NCX'])->assertRedirect();
        app(ComprobanteService::class)->emitir(Comprobante::where('origen_id', $conNc->id)->firstOrFail());
        $this->post("/comprobantes/{$conNc->id}/facturar")->assertStatus(422);
        // Si al cliente hoy le corresponde otra letra, tampoco (se hace a mano).
        $otro = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 500]], ['sin_arca' => true]);
        $cli->update(['condicion_iva' => 'Consumidor Final', 'cuit' => null]);
        $this->post("/comprobantes/{$otro->id}/facturar")->assertStatus(422);
        $cli->update(['condicion_iva' => 'Responsable Inscripto', 'cuit' => '30-70012345-6']);
        // Bigcito lo hace con confirmación.
        $cat = app(\App\Mcp\Catalogo::class);
        $prop = $cat->llamar($this->dueno, 'facturar_interno', ['comprobante' => (string) $otro->id], 'asistente');
        $this->assertStringContainsString('Pasar a factura fiscal', $prop['titulo']);
        $r = $cat->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $prop['propuesta_id']], 'asistente');
        $this->assertStringContainsString('con CAE', $r['texto']); $this->assertFalse((bool) $otro->fresh()->sin_arca); $this->assertFalse($r['comprobante']['interno']);
        $this->assertArrayHasKey('error', $cat->llamar($this->dueno->fresh(), 'facturar_interno', ['comprobante' => (string) $otro->id], 'asistente'));
        // La ficha del cliente marca qué pendientes son internos, para el aviso del recibo.
        $pend = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 500]], ['sin_arca' => true, 'condicion' => 'cta_cte']);
        $fila = collect($this->get("/clientes/{$cli->id}")->viewData('page')['props']['pendientes'])->firstWhere('id', $pend->id);
        $this->assertTrue($fila['interno']);
    }
}
