<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Cheque;
use App\Models\Comanda;
use App\Models\CuentaFondos;
use App\Models\Estadia;
use App\Models\Habitacion;
use App\Models\Mesa;
use App\Models\MovimientoFondos;
use App\Models\PedidoWeb;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Services\Canales\TiendaService;
use App\Services\Hoteleria\HoteleriaService;
use App\Services\Produccion\ProduccionService;
use App\Services\Servicios\OrdenesService;
use Illuminate\Support\Facades\Auth;
use Tests\ErpTestCase;

// Fase 119.6: las acciones que le faltaban a Bigcito (pedidos web, taller, hotel, mesas, cheques, producción, ingresos).
class Fase119fTest extends ErpTestCase
{
    private function hacer(string $h, array $args): array
    {
        $cat = app(Catalogo::class);
        $p = $cat->llamar($this->dueno, $h, $args, 'asistente');
        $this->assertArrayNotHasKey('error', $p, $p['error'] ?? '');
        $r = $cat->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $this->assertArrayNotHasKey('error', $r, $r['error'] ?? '');
        return [$p, $r];
    }

    private function error(string $h, array $args): string
    {
        $p = app(Catalogo::class)->llamar($this->dueno, $h, $args, 'asistente');
        $this->assertArrayHasKey('error', $p);
        return $p['error'];
    }

    public function test_pedidos_web_listar_confirmar_y_avanzar(): void
    {
        $art = $this->articulo(['name' => 'Cemento 50 kg', 'price' => 1000, 'stock_inicial' => 20]);
        $p = app(TiendaService::class)->crearPedido($this->empresa, ['items' => [['product_id' => $art->id, 'cantidad' => 2]], 'cliente' => ['nombre' => 'Ana Pérez', 'telefono' => '3515550000'], 'entrega' => 'retiro', 'pago' => 'efectivo'], 'tienda');
        $cat = app(Catalogo::class);
        $l = $cat->llamar($this->dueno, 'pedidos_web_pendientes', [], 'asistente')['pedidos'];
        $this->assertCount(1, $l); $this->assertSame($p->numeroFormateado(), $l[0]['numero']); $this->assertSame('Nuevo', $l[0]['estado']);

        [$prop, $r] = $this->hacer('confirmar_pedido_web', ['pedido' => 'Ana']);
        $this->assertStringContainsString($p->numeroFormateado(), $prop['titulo']); $this->assertStringContainsString('confirmado', $r['texto']);
        $p->refresh(); $this->assertSame('confirmado', $p->estado); $this->assertNotNull($p->comprobante_id);
        $this->assertStringContainsString('ya está confirmado', $this->error('confirmar_pedido_web', ['pedido' => (string) $p->numero]));

        $this->hacer('cambiar_estado_pedido_web', ['pedido' => $p->numeroFormateado(), 'estado' => 'preparando']);
        $this->assertSame('preparando', $p->fresh()->estado);
        $this->hacer('cambiar_estado_pedido_web', ['pedido' => (string) $p->numero, 'estado' => 'entregado']);
        $this->assertSame('entregado', $p->fresh()->estado);
        $this->assertStringContainsString('ya está entregado', $this->error('cambiar_estado_pedido_web', ['pedido' => (string) $p->numero, 'estado' => 'cancelado']));
        $this->assertStringContainsString('No encuentro', $this->error('confirmar_pedido_web', ['pedido' => 'Nadie Jamás']));
    }

    public function test_el_taller_avanza_una_orden_por_voz(): void
    {
        $this->empresa->update(['verticales_extra' => ['servicios']]);
        $o = app(OrdenesService::class)->crear(['contact_id' => $this->cliente(['name' => 'Juan Gómez'])->id, 'equipo' => 'Gol Trend', 'falla' => 'Ruido al frenar']);
        [$prop, $r] = $this->hacer('cambiar_estado_orden_servicio', ['orden' => (string) $o->numero, 'estado' => 'en_curso']);
        $this->assertStringContainsString('En reparación', $prop['titulo']); $this->assertSame('en_curso', $o->fresh()->estado);
        [$prop, $r] = $this->hacer('cambiar_estado_orden_servicio', ['orden' => 'Gol Trend', 'estado' => 'listo']);
        $this->assertSame('listo', $o->fresh()->estado); $this->assertStringContainsString('listo para retirar', $r['texto']);
        $this->assertStringContainsString('ya está', $this->error('cambiar_estado_orden_servicio', ['orden' => (string) $o->numero, 'estado' => 'listo']));
        $this->assertStringContainsString('no existe', $this->error('cambiar_estado_orden_servicio', ['orden' => (string) $o->numero, 'estado' => 'volando']));
    }

    public function test_hotel_reserva_checkin_y_checkout(): void
    {
        $this->empresa->update(['verticales_extra' => ['hoteleria']]);
        $h = Habitacion::create(['business_id' => $this->empresa->id, 'nombre' => 'Cabaña 1', 'tipo' => 'cabania', 'capacidad' => 4, 'tarifa' => 12100, 'activa' => true]);
        $cat = app(Catalogo::class);
        [$prop, $r] = $this->hacer('reservar_habitacion', ['huesped' => 'Familia Ruiz', 'desde' => today()->toDateString(), 'hasta' => today()->addDays(2)->toDateString(), 'personas' => 3]);
        $this->assertStringContainsString('Cabaña 1', $prop['titulo']); $this->assertStringContainsString('2 noches', $prop['detalle']);
        $e = Estadia::first(); $this->assertSame('reservada', $e->estado); $this->assertSame($h->id, $e->habitacion_id);
        $this->assertStringContainsString('No hay habitaciones libres', $this->error('reservar_habitacion', ['huesped' => 'Otro', 'desde' => today()->addDay()->toDateString(), 'hasta' => today()->addDays(3)->toDateString()]));

        $o = $cat->llamar($this->dueno, 'ocupacion_hotel', [], 'asistente');
        $this->assertSame('Familia Ruiz', $o['llegan_hoy'][0]['huesped']); $this->assertSame([], $o['alojados']);

        $this->hacer('checkin_huesped', ['huesped' => 'ruiz']);
        $this->assertSame('checkin', $e->fresh()->estado); $this->assertSame('ocupada', $h->fresh()->estado);
        $this->assertStringContainsString('No encuentro una reserva pendiente', $this->error('checkin_huesped', ['huesped' => 'ruiz']));
        app(HoteleriaService::class)->consumo($e->fresh(), ['descripcion' => 'Desayuno', 'cantidad' => 2, 'precio_unit' => 1210]);

        [$prop, $r] = $this->hacer('checkout_huesped', ['huesped' => 'Cabaña 1']);
        $e->refresh(); $this->assertSame('checkout', $e->estado); $this->assertNotNull($e->comprobante_id);
        $this->assertEqualsWithDelta(12100 * 2 + 2420, (float) $e->comprobante->total, 0.5); $this->assertStringContainsString('Falta cobrarla', $r['texto']);
    }

    public function test_cobra_y_cierra_una_mesa(): void
    {
        $this->empresa->forceFill(['vertical' => 'gastronomia'])->save(); $this->actingAs($this->dueno->fresh());
        $bife = $this->articulo(['name' => 'Bife de chorizo', 'price' => 10000, 'iva' => 0, 'stock_inicial' => 50]);
        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '4', 'sector' => 'Salón', 'capacidad' => 4, 'activa' => true]);
        $this->post('/gastronomia/comandas', ['mesa_id' => $m->id])->assertSessionHasNoErrors();
        $c = Comanda::latest('id')->first();
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $bife->id, 'cantidad' => 2])->assertSessionHasNoErrors();

        $this->assertStringContainsString('No encuentro una mesa abierta', $this->error('cobrar_mesa', ['mesa' => 'mesa 99']));
        [$prop, $r] = $this->hacer('cobrar_mesa', ['mesa' => 'mesa 4', 'medio' => 'efectivo', 'propina' => 1000]);
        $this->assertStringContainsString('Mesa 4', $prop['titulo']); $this->assertStringContainsString('21.000,00', $prop['titulo']); $this->assertStringContainsString('propina', $prop['detalle']);
        $c->refresh(); $this->assertSame('cerrada', $c->estado); $this->assertNotNull($c->comprobante_id);
        $this->assertEqualsWithDelta(20000, (float) $c->comprobante->total, 0.01);
        $this->assertEqualsWithDelta(0, (float) $c->comprobante->fresh()->saldo, 0.01, 'quedó cobrada');
        $this->assertStringContainsString('No encuentro una mesa abierta', $this->error('cobrar_mesa', ['mesa' => '4']));
    }

    public function test_cheques_acreditar_y_rechazar(): void
    {
        $cli = $this->cliente(['name' => 'López SRL']); $banco = CuentaFondos::where('tipo', 'banco')->first() ?? CuentaFondos::create(['business_id' => $this->empresa->id, 'nombre' => 'Banco Nación', 'tipo' => 'banco', 'moneda' => 'ARS', 'activa' => true]);
        $mk = fn(string $n, string $estado) => Cheque::create(['business_id' => $this->empresa->id, 'tipo' => 'tercero', 'numero' => $n, 'banco' => 'Galicia', 'emisor' => 'López SRL', 'fecha_emision' => today()->subDays(10)->toDateString(), 'fecha_pago' => today()->toDateString(), 'monto' => 25000, 'estado' => $estado, 'contact_id' => $cli->id, 'cuenta_fondos_id' => $estado === 'depositado' ? $banco->id : null]);
        $a = $mk('4455', 'depositado'); $b = $mk('7788', 'cartera');
        $this->assertStringContainsString('primero hay que depositarlo', $this->error('acreditar_cheque', ['cheque' => '7788']));
        $this->hacer('acreditar_cheque', ['cheque' => '4455']);
        $this->assertSame('cobrado', $a->fresh()->estado);
        $p = app(Catalogo::class)->llamar($this->dueno, 'rechazar_cheque', ['cheque' => '7788', 'motivo' => 'Sin fondos'], 'asistente');
        $this->assertTrue($p['delicada']); $this->assertStringContainsString('Sin fondos', $p['detalle']);
        app(Catalogo::class)->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $this->assertSame('rechazado', $b->fresh()->estado);
        $this->assertStringContainsString('No encuentro', $this->error('rechazar_cheque', ['cheque' => '4455', 'motivo' => 'x']));
    }

    public function test_produccion_crea_y_termina_una_orden(): void
    {
        $this->empresa->update(['verticales_extra' => ['produccion']]);
        $harina = $this->articulo(['name' => 'Harina 000', 'stock_inicial' => 100, 'cost' => 500]); $pan = $this->articulo(['name' => 'Pan francés', 'stock_inicial' => 0]);
        app(ProduccionService::class)->guardarFormula(['product_id' => $pan->id, 'name' => 'Pan francés', 'yield_quantity' => 10, 'yield_unit' => 'kg', 'items' => [['product_id' => $harina->id, 'quantity' => 8]]]);
        $this->assertSame(1, Recipe::count());
        $cat = app(Catalogo::class);
        $this->assertStringContainsString('No encuentro una fórmula', $this->error('crear_orden_produccion', ['producto' => 'Medialunas', 'cantidad' => 5]));
        [$prop, $r] = $this->hacer('crear_orden_produccion', ['producto' => 'pan frances', 'cantidad' => 20]);
        $this->assertStringContainsString('20 kg de Pan francés', $prop['titulo']);
        $o = ProductionOrder::first(); $this->assertSame('pending', $o->status);
        $l = $cat->llamar($this->dueno, 'ordenes_produccion', [], 'asistente');
        $this->assertSame('Pan francés', $l['abiertas'][0]['producto']); $this->assertSame('Pan francés', $l['formulas'][0]['formula']);
        [$prop, $r] = $this->hacer('terminar_orden_produccion', ['orden' => 'pan', 'producida' => 19]);
        $o->refresh(); $this->assertSame('completed', $o->status); $this->assertEqualsWithDelta(19, (float) $o->cantidad_producida, 0.001);
        $this->assertEqualsWithDelta(19, (float) $pan->fresh()->stock, 0.001); $this->assertLessThan(100, (float) $harina->fresh()->stock);
        $this->assertStringContainsString('No encuentro una orden de producción abierta', $this->error('terminar_orden_produccion', ['orden' => 'pan']));
    }

    public function test_registra_un_ingreso_de_plata_y_respeta_permisos(): void
    {
        $caja = CuentaFondos::where('tipo', 'caja')->where('activa', true)->first();
        $antes = (float) $caja->saldo;
        [$prop, $r] = $this->hacer('registrar_ingreso', ['concepto' => 'Aporte del dueño', 'monto' => 50000]);
        $this->assertStringContainsString('Ingreso de $ 50.000,00: Aporte del dueño', $prop['titulo']);
        $m = MovimientoFondos::latest('id')->first(); $this->assertSame('ingreso', $m->origen); $this->assertEqualsWithDelta(50000, (float) $m->ingreso, 0.01);
        $this->assertEqualsWithDelta($antes + 50000, (float) CuentaFondos::find($m->cuenta_fondos_id)->saldo, 0.01);

        // Un vendedor no toca la plata, ni el hotel, ni la producción.
        $v = $this->usuarioConRol('vendedor'); $this->actingAs($v); Auth::login($v); $cat = app(Catalogo::class);
        foreach (['registrar_ingreso' => ['concepto' => 'x', 'monto' => 1], 'rechazar_cheque' => ['cheque' => '1', 'motivo' => 'x'], 'checkout_huesped' => ['huesped' => 'x'], 'terminar_orden_produccion' => ['orden' => '1']] as $h => $args) {
            $this->assertStringContainsString('no tiene permiso', $cat->llamar($v, $h, $args, 'asistente')['error'], $h);
        }
    }
}
