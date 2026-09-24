<?php

namespace Tests\Feature;

use App\Models\Asiento;
use App\Models\Cotizacion;
use App\Models\CuentaCorriente;
use App\Services\Comprobantes\CobroService;
use App\Services\Compras\CompraService;
use App\Services\Compras\PagoService;
use Tests\ErpTestCase;

class MultimonedaTest extends ErpTestCase
{
    private function cotizar(float $valor): void
    {
        Cotizacion::create(['business_id' => null, 'fecha' => today()->toDateString(), 'tipo' => 'oficial', 'compra' => $valor, 'venta' => $valor, 'fuente' => 'manual']);
    }

    // Factura en USD cobrada a una cotización más alta: cancela toda la factura y la diferencia es ganancia por diferencia de cambio.
    public function test_cobro_de_factura_en_dolares_registra_diferencia_de_cambio(): void
    {
        $this->cotizar(1000);
        $p = $this->articulo(['stock_inicial' => 10, 'iva' => 0]);
        $cli = $this->cliente();
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['moneda' => 'USD', 'cotizacion' => 1000]); // USD 100 = $ 100.000
        $this->assertEqualsWithDelta(100000, (float) $f->total, 0.01);
        $this->assertEqualsWithDelta(100, (float) $f->total_me, 0.01);

        $cobro = app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'cotizacion' => 1200, 'medios' => [['medio' => 'efectivo', 'monto' => 120000]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 120000]]]);
        $this->assertEqualsWithDelta(0, (float) $f->fresh()->saldo, 0.01, 'Los USD 100 a 1.200 cancelan la factura completa');
        $this->assertEqualsWithDelta(20000, (float) $cobro->imputaciones()->sum('dif_cambio'), 0.01);
        $this->assertEqualsWithDelta(0, (float) $cobro->a_cuenta, 0.01);
        $this->assertEqualsWithDelta(0, (float) $cli->fresh()->balance, 0.01, 'La cuenta corriente queda en cero: los 20.000 de más son diferencia de cambio, no saldo a favor');
        $asiento = Asiento::withoutGlobalScopes()->where('origen', 'cobro')->where('origen_id', $cobro->id)->with('lineas.cuenta')->first();
        $this->assertNotNull($asiento);
        $this->assertEqualsWithDelta(20000, (float) $asiento->lineas->first(fn($l) => $l->cuenta->clave === 'dif_cambio')?->haber, 0.01);
        $this->assertAsientosBalancean();

        app(CobroService::class)->anular($cobro, 'prueba');
        $this->assertEqualsWithDelta(100000, (float) $f->fresh()->saldo, 0.01, 'Al anular vuelve el saldo original en pesos');
    }

    public function test_cobro_parcial_en_dolares_a_menor_cotizacion_es_perdida(): void
    {
        $this->cotizar(1000);
        $p = $this->articulo(['stock_inicial' => 10, 'iva' => 0]);
        $cli = $this->cliente();
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['moneda' => 'USD', 'cotizacion' => 1000]);
        $cobro = app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'cotizacion' => 900, 'medios' => [['medio' => 'efectivo', 'monto' => 36000]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 36000]]]); // USD 40
        $this->assertEqualsWithDelta(60000, (float) $f->fresh()->saldo, 0.01, 'Quedan USD 60 a la cotización de la factura');
        $this->assertEqualsWithDelta(-4000, (float) $cobro->imputaciones()->sum('dif_cambio'), 0.01);
        $this->assertAsientosBalancean();
        $pos = $this->get('/fondos/moneda');
        $pos->assertOk();
    }

    public function test_compra_en_dolares_guarda_pesos_y_el_pago_registra_diferencia(): void
    {
        $this->cotizar(1000);
        $p = $this->articulo(['stock_inicial' => 0]);
        $prov = $this->proveedor();
        $svc = app(CompraService::class);
        $c = $svc->registrar($svc->guardarBorrador(['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000001', 'fecha' => today()->toDateString(), 'moneda' => 'USD', 'cotizacion' => 1000, 'items' => [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 5, 'alicuota_iva' => 21]]]));
        $this->assertEqualsWithDelta(5000, (float) $c->items->first()->precio_unit, 0.01, 'USD 5 a 1.000 se guarda como $ 5.000');
        $this->assertEqualsWithDelta(60500, (float) $c->total, 0.01);
        $this->assertEqualsWithDelta(60.5, (float) $c->total_me, 0.01);
        $this->assertEqualsWithDelta(5000, (float) $p->fresh()->cost, 0.01, 'El costo del artículo queda en pesos');

        $this->get("/proveedores/compras/{$c->id}")->assertOk()->assertInertia(fn($a) => $a->component('Proveedores/CompraVer', false)->where('c.moneda', 'USD')->where('c.total_me', fn($v) => abs($v - 60.5) < 0.01));
        $this->get("/proveedores/{$prov->id}")->assertOk()->assertInertia(fn($a) => $a->component('Proveedores/Ver', false)->where('pendientes.0.saldo_usd', fn($v) => abs($v - 60.5) < 0.01));

        $pago = app(PagoService::class)->registrar($prov, ['fecha' => today()->toDateString(), 'cotizacion' => 1100, 'medios' => [['medio' => 'transferencia', 'monto' => 66550]], 'imputaciones' => [['comprobante_id' => $c->id, 'monto' => 66550]]]);
        $this->assertEqualsWithDelta(0, (float) $c->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(6050, (float) $pago->imputaciones()->sum('dif_cambio'), 0.01, 'Pagar a 1.100 lo que se registró a 1.000 es pérdida por diferencia de cambio');
        $this->assertEqualsWithDelta(0, (float) $prov->fresh()->balance, 0.01);
        $this->assertAsientosBalancean();
    }

    public function test_pantalla_de_cobro_muestra_saldo_en_dolares_y_recibe_cotizacion(): void
    {
        $this->cotizar(1000);
        $p = $this->articulo(['stock_inicial' => 10, 'iva' => 0]);
        $cli = $this->cliente();
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['moneda' => 'USD', 'cotizacion' => 1000]);
        $this->get("/clientes/{$cli->id}")->assertOk()->assertInertia(fn($a) => $a->component('Clientes/Ver', false)->where('pendientes.0.saldo_usd', fn($v) => abs($v - 100) < 0.01)->where('cotizacionUsd', fn($v) => abs($v - 1000) < 0.01));
        $this->post("/clientes/{$cli->id}/cobros", ['fecha' => today()->toDateString(), 'cotizacion' => 1250, 'medios' => [['medio' => 'efectivo', 'monto' => 125000]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 125000]]])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(0, (float) $f->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(25000, (float) \App\Models\CobroImputacion::sum('dif_cambio'), 0.01);
    }

    // Recibo sin comprobantes (a cuenta) que después se aplica a facturas, incluso en dólares.
    public function test_recibo_a_cuenta_se_aplica_despues_a_facturas(): void
    {
        $this->cotizar(1000);
        $p = $this->articulo(['stock_inicial' => 10, 'iva' => 0]);
        $cli = $this->cliente();
        $cobro = app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 150000]], 'imputaciones' => []]);
        $this->assertEqualsWithDelta(150000, (float) $cobro->a_cuenta, 0.01, 'Sin comprobantes todo queda a cuenta');
        $this->assertEqualsWithDelta(-150000, (float) $cli->fresh()->balance, 0.01);

        $fArs = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 30000]]);
        $fUsd = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['moneda' => 'USD', 'cotizacion' => 1000]);
        $this->assertEqualsWithDelta(-20000, (float) $cli->fresh()->balance, 0.01);

        $this->post("/clientes/cobros/{$cobro->id}/aplicar", ['cotizacion' => 1100, 'imputaciones' => [['comprobante_id' => $fArs->id, 'monto' => 30000], ['comprobante_id' => $fUsd->id, 'monto' => 110000]]])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(0, (float) $fArs->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(0, (float) $fUsd->fresh()->saldo, 0.01, 'USD 100 a 1.100 cancelan la factura en dólares');
        $this->assertEqualsWithDelta(10000, (float) $cobro->fresh()->a_cuenta, 0.01, '150.000 − 30.000 − 110.000');
        $this->assertEqualsWithDelta(10000, (float) $cobro->imputaciones()->sum('dif_cambio'), 0.01);
        $this->assertEqualsWithDelta(-10000, (float) $cli->fresh()->balance, 0.01, 'Solo queda a favor lo no aplicado: la diferencia de cambio se ajustó en la cuenta corriente');
        $this->assertTrue(CuentaCorriente::where('cobro_id', $cobro->id)->where('tipo', 'ajuste')->exists());
        $this->assertAsientosBalancean();

        // No se puede aplicar más de lo que hay a cuenta.
        $f3 = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 50000]]);
        $this->post("/clientes/cobros/{$cobro->id}/aplicar", ['imputaciones' => [['comprobante_id' => $f3->id, 'monto' => 50000]]])->assertSessionHasErrors('imputaciones');

        // Anular el recibo devuelve el saldo a todas las facturas.
        app(CobroService::class)->anular($cobro->fresh(), 'prueba');
        $this->assertEqualsWithDelta(100000, (float) $fUsd->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(30000, (float) $fArs->fresh()->saldo, 0.01);
        $this->assertSame(0, CuentaCorriente::where('cobro_id', $cobro->id)->count());
    }

    public function test_orden_de_pago_a_cuenta_se_aplica_despues(): void
    {
        $p = $this->articulo(['stock_inicial' => 0]);
        $prov = $this->proveedor();
        $pago = app(PagoService::class)->registrar($prov, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'transferencia', 'monto' => 1000]], 'imputaciones' => []]);
        $this->assertEqualsWithDelta(1000, (float) $pago->a_cuenta, 0.01);
        $svc = app(CompraService::class);
        $c = $svc->registrar($svc->guardarBorrador(['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000002', 'fecha' => today()->toDateString(), 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 500, 'alicuota_iva' => 21]]])); // 605
        $this->post("/proveedores/pagos/{$pago->id}/aplicar", ['imputaciones' => [['comprobante_id' => $c->id, 'monto' => 605]]])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(0, (float) $c->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(395, (float) $pago->fresh()->a_cuenta, 0.01);
        $this->assertEqualsWithDelta(-395, (float) $prov->fresh()->balance, 0.01);
        $this->assertAsientosBalancean();
    }
}
