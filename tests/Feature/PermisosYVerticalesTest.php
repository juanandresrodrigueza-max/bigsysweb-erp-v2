<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Estadia;
use App\Models\Habitacion;
use App\Models\OrdenTrabajo;
use App\Models\Product;
use App\Models\Proyecto;
use App\Services\Hoteleria\HoteleriaService;
use App\Services\Obras\ObrasService;
use App\Services\Servicios\OrdenesService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\ErpTestCase;

class PermisosYVerticalesTest extends ErpTestCase
{
    public function test_cada_empresa_ve_solo_lo_suyo(): void
    {
        $mio = $this->articulo(['name' => 'Mío']);
        [$b2, $s2, $u2] = $this->crearEmpresa('Otra', 'otra');
        Auth::login($u2); $this->actingAs($u2);
        $ajeno = Product::create(['business_id' => $b2->id, 'business_location_id' => $s2->id, 'name' => 'Ajeno', 'sku' => 'AJ1', 'tipo' => 'producto', 'unit' => 'un', 'cost' => 1, 'price' => 2, 'iva' => 21, 'stock' => 0, 'active' => true, 'controla_stock' => true]);
        $this->assertNull(Product::find($mio->id), 'La empresa 2 no ve el artículo de la empresa 1');
        $this->assertNotNull(Product::find($ajeno->id));
        $this->get("/stock/{$mio->id}")->assertNotFound();
        Auth::login($this->dueno); $this->actingAs($this->dueno);
        $this->assertNull(Product::find($ajeno->id));
    }

    public function test_el_cajero_no_entra_a_sueldos_ni_configura_y_el_dueno_si(): void
    {
        $cajero = $this->usuarioConRol('cajero');
        $this->actingAs($cajero)->get('/sueldos')->assertForbidden();
        $this->actingAs($cajero)->get('/configuracion/importar')->assertForbidden();
        $this->actingAs($cajero)->get('/fondos')->assertOk();
        $this->actingAs($this->dueno)->get('/sueldos')->assertOk();
        $this->assertFalse($cajero->puede('stock', 'editar'));
        $this->assertTrue($this->dueno->puede('sueldos', 'anular'));
    }

    public function test_los_verticales_solo_se_ven_si_estan_habilitados(): void
    {
        $this->assertNotContains('hoteleria', $this->empresa->modulosActivos());
        $this->empresa->update(['verticales_extra' => ['hoteleria']]);
        $this->assertContains('hoteleria', $this->empresa->fresh()->modulosActivos());
        $this->actingAs($this->dueno->fresh())->get('/hoteleria')->assertOk();
    }

    public function test_obra_certifica_avance_con_factura_y_costea_partes(): void
    {
        $cli = $this->cliente(); $p = $this->articulo(['cost' => 100, 'stock_inicial' => 50]);
        $svc = app(ObrasService::class);
        $obra = $svc->guardar(['nombre' => 'Galpón', 'contact_id' => $cli->id, 'presupuesto_venta' => 121000, 'presupuesto_costo' => 60000, 'estado' => 'en_curso']);
        $svc->parte($obra, ['tipo' => 'material', 'product_id' => $p->id, 'cantidad' => 10, 'descripcion' => '']);
        $svc->parte($obra, ['tipo' => 'mano_obra', 'descripcion' => 'Albañil', 'cantidad' => 8, 'costo_unit' => 5000]);
        $this->assertEqualsWithDelta(40, (float) $p->fresh()->stock, 0.001, 'El material del parte sale del stock');
        $r = $svc->resumen($obra->fresh());
        $this->assertEqualsWithDelta(41000, $r['costo_real'], 0.01);
        $c = $svc->certificar($obra->fresh(), 50);
        $this->assertSame('FA', $c->tipo);
        $this->assertEqualsWithDelta(60500, (float) $c->total, 0.01, 'El 50% del presupuesto con IVA');
        $this->assertSame($obra->id, $c->proyecto_id);
        $this->assertEqualsWithDelta(50, (float) $obra->fresh()->avance_certificado, 0.01);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->certificar($obra->fresh(), 40);
    }

    public function test_orden_de_trabajo_se_aprueba_por_link_y_se_factura_con_la_hoja_de_trabajo(): void
    {
        $cli = $this->cliente(); $rep = $this->articulo(['name' => 'Capacitor', 'price' => 12100, 'stock_inicial' => 3]);
        $svc = app(OrdenesService::class);
        $ot = $svc->crear(['contact_id' => $cli->id, 'equipo' => 'Hormigonera', 'falla' => 'No arranca']);
        $this->assertSame('OT-00001', $ot->numeroFormateado());
        $svc->actualizar($ot, ['equipo' => 'Hormigonera', 'falla' => 'No arranca', 'diagnostico' => 'Capacitor', 'presupuesto' => 20000]);
        $svc->item($ot, ['product_id' => $rep->id, 'cantidad' => 1, 'actualizar_presupuesto' => true]);
        $svc->item($ot, ['descripcion' => 'Mano de obra', 'tipo' => 'mano_obra', 'cantidad' => 1, 'precio_unit' => 12100, 'actualizar_presupuesto' => true]);
        $this->assertEqualsWithDelta(24200, (float) $ot->fresh()->presupuesto, 0.01);
        $svc->estado($ot->fresh(), 'presupuestado');
        $this->post("/ot/{$ot->token}", ['respuesta' => 'aprobar', 'nombre' => 'Cliente'])->assertRedirect("/ot/{$ot->token}");
        $this->assertSame('aprobado', $ot->fresh()->estado);
        $this->get("/ot/{$ot->token}")->assertOk()->assertSee('Presupuesto aprobado');
        $c = $svc->facturar($ot->fresh());
        $this->assertEqualsWithDelta(24200, (float) $c->total, 0.01);
        $this->assertEqualsWithDelta(2, (float) $rep->fresh()->stock, 0.001, 'El repuesto sale del stock al facturar');
        $this->assertSame('entregado', $ot->fresh()->estado);
    }

    public function test_hoteleria_reserva_sin_choques_y_el_checkout_factura_con_la_senia(): void
    {
        $this->empresa->update(['verticales_extra' => ['hoteleria']]);
        $h = Habitacion::create(['business_id' => $this->empresa->id, 'nombre' => 'Cabaña 1', 'tipo' => 'cabania', 'capacidad' => 4, 'tarifa' => 12100, 'activa' => true]);
        $svc = app(HoteleriaService::class);
        $e = $svc->reservar(['habitacion_id' => $h->id, 'nombre' => 'Familia', 'personas' => 2, 'desde' => today()->toDateString(), 'hasta' => today()->addDays(2)->toDateString()]);
        $this->assertSame(2, $e->noches());
        try { $svc->reservar(['habitacion_id' => $h->id, 'nombre' => 'Otro', 'personas' => 2, 'desde' => today()->addDay()->toDateString(), 'hasta' => today()->addDays(3)->toDateString()]); $this->fail('Debía chocar'); } catch (ValidationException) {}
        $svc->checkin($e); $this->assertSame('ocupada', $h->fresh()->estado);
        $svc->consumo($e, ['descripcion' => 'Desayuno', 'cantidad' => 2, 'precio_unit' => 1210]);
        $svc->senia($e, 5000, $this->caja);
        $this->assertEqualsWithDelta(24200 + 2420 - 5000, $e->fresh()->saldo(), 0.01);
        $c = $svc->checkout($e->fresh());
        $this->assertEqualsWithDelta(21620, (float) $c->total, 0.01, 'Alojamiento + consumos − seña');
        $this->assertSame('limpieza', $h->fresh()->estado);
        $this->assertSame('checkout', $e->fresh()->estado);
        $this->assertAsientosBalancean();
    }

    public function test_venta_del_pos_sin_conexion_es_idempotente(): void
    {
        $p = $this->articulo(['price' => 121, 'stock_inicial' => 10]);
        $datos = ['items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100, 'descuento' => 0, 'alicuota_iva' => 21]], 'medios' => [['medio' => 'efectivo', 'monto' => 121]], 'precios_con_iva' => false, 'offline_id' => 'off-abc', 'fecha_offline' => now()->toDateTimeString()];
        $r1 = $this->postJson('/retail/vender', $datos)->assertOk()->json();
        $r2 = $this->postJson('/retail/vender', $datos)->assertOk()->json();
        $this->assertSame($r1['comprobante_id'], $r2['comprobante_id']);
        $this->assertTrue($r2['repetida']);
        $this->assertSame(1, Comprobante::where('offline_id', 'off-abc')->count());
        $this->assertEqualsWithDelta(9, (float) $p->fresh()->stock, 0.001, 'El stock se descontó una sola vez');
    }
}
