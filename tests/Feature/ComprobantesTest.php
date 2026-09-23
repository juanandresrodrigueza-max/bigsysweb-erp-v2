<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Cotizacion;
use App\Models\CuentaCorriente;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Validation\ValidationException;
use Tests\ErpTestCase;

class ComprobantesTest extends ErpTestCase
{
    public function test_calculo_de_item_con_descuento_e_iva(): void
    {
        $r = ComprobanteItem::calcular(3, 1000, 10, 21);
        $this->assertSame(2700.0, $r['neto']);
        $this->assertSame(567.0, $r['iva']);
        $this->assertSame(3267.0, $r['total']);
    }

    public function test_factura_a_responsable_inscripto_discrimina_iva_y_va_a_cuenta_corriente(): void
    {
        $p = $this->articulo(['price' => 1000, 'stock_inicial' => 10]);
        $c = $this->factura($this->cliente(), [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 1000, 'descuento' => 0]]);
        $this->assertSame('FA', $c->tipo);
        $this->assertSame('emitido', $c->estado);
        $this->assertEqualsWithDelta(2000, (float) $c->neto, 0.01);
        $this->assertEqualsWithDelta(420, (float) $c->iva, 0.01);
        $this->assertEqualsWithDelta(2420, (float) $c->total, 0.01);
        $this->assertEqualsWithDelta(2420, (float) $c->saldo, 0.01);
        $this->assertEqualsWithDelta(8, (float) $p->fresh()->stock, 0.001, 'La factura descuenta stock');
        $this->assertEqualsWithDelta(2420, (float) CuentaCorriente::where('comprobante_id', $c->id)->sum('debe'), 0.01);
        $this->assertEqualsWithDelta(2420, (float) $c->contact->fresh()->balance, 0.01);
        $this->assertSame('0001-00000001', $c->numeroFormateado());
        $this->assertAsientosBalancean();
    }

    public function test_consumidor_final_recibe_factura_b_y_monotributista_emite_c(): void
    {
        $p = $this->articulo(['stock_inicial' => 5]);
        $cf = $this->cliente(['name' => 'CF', 'cuit' => null, 'condicion_iva' => 'Consumidor Final']);
        $c = $this->factura($cf, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('FB', $c->tipo);
        $this->empresa->update(['condicion_iva' => 'Monotributista']);
        \Illuminate\Support\Facades\Auth::login($this->dueno->fresh()); $this->actingAs($this->dueno->fresh());
        $c2 = $this->factura($this->cliente(['name' => 'Otro']), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('FC', $c2->tipo);
        $this->assertEqualsWithDelta(0, (float) $c2->iva, 0.01, 'La C no discrimina IVA');
    }

    public function test_descuento_por_cantidad_aplica_la_escala_mayor(): void
    {
        $p = $this->articulo(['stock_inicial' => 100, 'desc_cant_min' => 10, 'desc_cant_pct' => 5, 'desc_cant2_min' => 50, 'desc_cant2_pct' => 12]);
        $this->assertSame(0.0, $p->descuentoPorCantidad(5));
        $this->assertSame(5.0, $p->descuentoPorCantidad(10));
        $this->assertSame(12.0, $p->descuentoPorCantidad(60));
        $c = $this->factura($this->cliente(), [['product_id' => $p->id, 'cantidad' => 60, 'precio_unit' => 100, 'descuento' => 0]]);
        $this->assertEqualsWithDelta(12, (float) $c->items->first()->descuento, 0.01);
        $this->assertEqualsWithDelta(5280, (float) $c->neto, 0.01);
    }

    public function test_nota_de_credito_baja_la_cuenta_corriente_y_devuelve_stock(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente();
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 4, 'precio_unit' => 100]]);
        $nc = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['tipo' => 'NCX']);
        $this->assertSame('NCA', $nc->tipo);
        $this->assertEqualsWithDelta(7, (float) $p->fresh()->stock, 0.001);
        $this->assertEqualsWithDelta(484 - 121, (float) $cli->fresh()->balance, 0.01);
        $this->assertAsientosBalancean();
    }

    public function test_anular_revierte_stock_y_cuenta_corriente(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente();
        $c = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 3, 'precio_unit' => 100]]);
        app(ComprobanteService::class)->anular($c, 'prueba');
        $this->assertSame('anulado', $c->fresh()->estado);
        $this->assertEqualsWithDelta(10, (float) $p->fresh()->stock, 0.001);
        $this->assertEqualsWithDelta(0, (float) $cli->fresh()->balance, 0.01);
    }

    public function test_limite_de_credito_frena_la_factura_en_cuenta_corriente(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente(['credit_limit' => 500]);
        $this->expectException(ValidationException::class);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 5, 'precio_unit' => 100]]);
    }

    public function test_factura_en_dolares_se_guarda_en_pesos_con_su_cotizacion(): void
    {
        Cotizacion::create(['business_id' => null, 'fecha' => today()->toDateString(), 'tipo' => 'oficial', 'compra' => 1400, 'venta' => 1450, 'fuente' => 'test']);
        $p = $this->articulo(['stock_inicial' => 10]);
        $c = $this->factura($this->cliente(), [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 10]], ['moneda' => 'USD']);
        $this->assertSame('USD', $c->moneda);
        $this->assertEqualsWithDelta(1450, (float) $c->cotizacion, 0.01);
        $this->assertEqualsWithDelta(29000, (float) $c->neto, 0.01);
        $this->assertEqualsWithDelta(24.2, (float) $c->total_me, 0.01);
    }

    public function test_presupuesto_no_toca_stock_ni_cuenta_corriente_y_se_convierte_en_factura(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente();
        $pre = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 100]], ['tipo' => 'PRE']);
        $this->assertEqualsWithDelta(10, (float) $p->fresh()->stock, 0.001);
        $this->assertEqualsWithDelta(0, (float) $cli->fresh()->balance, 0.01);
        $svc = app(ComprobanteService::class);
        $fa = $svc->emitir($svc->convertir($pre, 'FX'));
        $this->assertSame('FA', $fa->tipo);
        $this->assertSame($pre->id, $fa->origen_id);
        $this->assertEqualsWithDelta(8, (float) $p->fresh()->stock, 0.001);
    }

    public function test_numeracion_correlativa_por_tipo(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente();
        $a = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $b = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame((int) $a->numero + 1, (int) $b->numero);
        $this->assertSame(1, Comprobante::where('tipo', 'FA')->where('numero', 1)->count());
    }
}
