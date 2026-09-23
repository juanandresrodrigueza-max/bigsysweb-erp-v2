<?php

namespace Tests\Feature;

use App\Models\Cotizacion;
use App\Models\CuentaFondos;
use App\Services\Comprobantes\CobroService;
use App\Services\Compras\CompraService;
use App\Services\Compras\PagoService;
use Tests\ErpTestCase;

class CobrosPagosTest extends ErpTestCase
{
    public function test_cobro_imputado_baja_saldo_y_entra_en_caja(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente();
        $c = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $cobro = app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 1210]], 'imputaciones' => [['comprobante_id' => $c->id, 'monto' => 1210]]]);
        $this->assertEqualsWithDelta(0, (float) $c->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(0, (float) $cli->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(1210, (float) $this->caja->fresh()->saldo, 0.01);
        $this->assertSame('REC 00000001', $cobro->numeroFormateado());
        $this->assertAsientosBalancean();
    }

    public function test_cobro_con_descuento_cancela_toda_la_deuda_y_con_interes_no(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente();
        $c = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 5000]]); // 6050
        app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'descuento' => 50, 'medios' => [['medio' => 'efectivo', 'monto' => 6000]], 'imputaciones' => [['comprobante_id' => $c->id, 'monto' => 6050]]]);
        $this->assertEqualsWithDelta(0, (float) $c->fresh()->saldo, 0.01, 'Con descuento de 50 la factura de 6050 queda cancelada');
        $c2 = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]); // 1210
        app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'interes' => 100, 'medios' => [['medio' => 'efectivo', 'monto' => 1310]], 'imputaciones' => [['comprobante_id' => $c2->id, 'monto' => 1210]]]);
        $this->assertEqualsWithDelta(0, (float) $c2->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(6000 + 1310, (float) $this->caja->fresh()->saldo, 0.01, 'El interés entra en caja pero no cancela más deuda');
        $this->assertAsientosBalancean();
    }

    public function test_cobro_en_dolares_entra_en_la_cuenta_usd_y_se_imputa_en_pesos(): void
    {
        Cotizacion::create(['business_id' => null, 'fecha' => today()->toDateString(), 'tipo' => 'oficial', 'compra' => 1400, 'venta' => 1450, 'fuente' => 'test']);
        $usd = CuentaFondos::create(['business_id' => $this->empresa->id, 'tipo' => 'caja', 'nombre' => 'Caja USD', 'moneda' => 'USD', 'activa' => true]);
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente();
        $c = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 10000]]); // 12100
        app(CobroService::class)->registrar($cli, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 10, 'moneda' => 'USD', 'cotizacion' => 1450, 'cuenta_fondos_id' => $usd->id]], 'imputaciones' => [['comprobante_id' => $c->id, 'monto' => 12100]]]);
        $this->assertEqualsWithDelta(10, (float) $usd->fresh()->saldo, 0.01, 'La caja USD suma dólares');
        $this->assertEqualsWithDelta(0, (float) $c->fresh()->saldo, 0.01, 'La factura de 12.100 queda cancelada');
        $this->assertEqualsWithDelta(-2400, (float) $cli->fresh()->balance, 0.01, 'Los 2.400 pesos de más quedan a favor del cliente');
        $this->assertAsientosBalancean();
    }

    public function test_compra_y_pago_a_proveedor(): void
    {
        $p = $this->articulo(['stock_inicial' => 0]);
        $prov = $this->proveedor();
        $svc = app(CompraService::class);
        $c = $svc->registrar($svc->guardarBorrador(['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0003-00000123', 'fecha' => today()->toDateString(), 'items' => [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 80, 'descuento' => 0, 'alicuota_iva' => 21]]]));
        $this->assertSame('emitido', $c->estado);
        $this->assertEqualsWithDelta(10, (float) $p->fresh()->stock, 0.001, 'La compra suma stock');
        $this->assertEqualsWithDelta(968, (float) $prov->fresh()->balance, 0.01);
        app(PagoService::class)->registrar($prov, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'transferencia', 'monto' => 968]], 'imputaciones' => [['comprobante_id' => $c->id, 'monto' => 968]]]);
        $this->assertEqualsWithDelta(0, (float) $prov->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(-968, (float) $this->banco->fresh()->saldo, 0.01);
        $this->assertAsientosBalancean();
    }
}
