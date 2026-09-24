<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cobro;
use App\Models\ExpenseCategory;
use App\Models\MovimientoFondos;
use App\Models\Vendedor;
use App\Services\IA\AccionesService;
use Tests\ErpTestCase;

// Fase 20: el asistente que hace las cosas (con confirmación y permisos), el panel del dueño y los paneles por rol.
class AsistenteTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => null]); // modo local: se prueban las frases entendidas sin IA
    }

    public function test_entiende_frases_comunes_y_consulta_saldos_stock_deudores_y_ventas(): void
    {
        $p = $this->articulo(['name' => 'Cemento x 50 kg', 'sku' => 'CEM', 'stock_inicial' => 30, 'price' => 9000]);
        $cli = $this->cliente(['name' => 'Constructora del Valle SA']);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 9000]]);
        $r = $this->postJson('/agente/chat', ['mensaje' => '¿Cuánto me debe Constructora del Valle?'])->assertOk()->json();
        $this->assertSame('local', $r['modo']); $this->assertStringContainsString('Constructora del Valle SA: saldo $ 21.780', $r['respuesta']);
        $r = $this->postJson('/agente/chat', ['mensaje' => 'stock de cemento'])->assertOk()->json();
        $this->assertStringContainsString('Cemento x 50 kg (CEM): stock 28 un', $r['respuesta']);
        $r = $this->postJson('/agente/chat', ['mensaje' => '¿Quiénes me deben?'])->assertOk()->json();
        $this->assertStringContainsString('Constructora del Valle SA $ 21.780', $r['respuesta']);
        $r = $this->postJson('/agente/chat', ['mensaje' => 'cuánto vendí hoy'])->assertOk()->json();
        $this->assertStringContainsString('Ventas hoy: $ 21.780 en 1 facturas', $r['respuesta']);
    }

    public function test_propone_un_cobro_lo_ejecuta_al_confirmar_y_lo_audita(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente(['name' => 'Ferretería López']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 10000]]); // 12.100
        $r = $this->postJson('/agente/chat', ['mensaje' => 'Cobrale 5000 a López en transferencia'])->assertOk()->json();
        $this->assertSame('registrar_cobro', $r['propuesta']['accion']); $this->assertSame('Cobrar $ 5.000,00 a Ferretería López en transferencia', $r['propuesta']['titulo']);
        $this->assertStringContainsString($f->numeroFormateado(), $r['propuesta']['detalle'], 'Se imputa a la factura pendiente');
        $this->assertSame(0, Cobro::count(), 'Todavía no hizo nada: espera confirmación');
        $e = $this->postJson('/agente/ejecutar', ['accion' => $r['propuesta']['accion'], 'datos' => $r['propuesta']['datos']])->assertOk()->json();
        $this->assertTrue($e['ok']); $this->assertSame(1, Cobro::count());
        $this->assertEqualsWithDelta(7100, (float) $cli->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(7100, (float) $f->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(5000, (float) $this->banco->fresh()->saldo, 0.01, 'La transferencia entró al banco');
        $this->assertTrue(AuditLog::where('accion', 'ia_ejecuto')->exists());
        $this->assertAsientosBalancean();
    }

    public function test_propone_un_gasto_un_recordatorio_y_un_presupuesto(): void
    {
        ExpenseCategory::create(['business_id' => $this->empresa->id, 'name' => 'Combustible', 'tipo_costo' => 'variable', 'imputacion' => 'indirecto']);
        $r = $this->postJson('/agente/chat', ['mensaje' => 'Gasté 5000 en nafta de la camioneta'])->assertOk()->json();
        $this->assertSame('registrar_gasto', $r['propuesta']['accion']); $this->assertSame('Caja', $this->caja->nombre); $this->assertStringContainsString('Sale de Caja', $r['propuesta']['detalle']);
        $this->postJson('/agente/ejecutar', ['accion' => 'registrar_gasto', 'datos' => $r['propuesta']['datos']])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, MovimientoFondos::where('origen', 'gasto')->count()); $this->assertEqualsWithDelta(-5000, (float) $this->caja->fresh()->saldo, 0.01);
        // Recordatorio de deuda.
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente(['name' => 'Pérez Hnos', 'mobile' => '3515550000']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]); $f->update(['fecha_vto' => today()->subDays(5)->toDateString()]);
        $r = $this->postJson('/agente/chat', ['mensaje' => 'Recordale a Pérez la deuda por whatsapp'])->assertOk()->json();
        $this->assertSame('recordar_deuda', $r['propuesta']['accion']); $this->assertStringContainsString('3515550000', $r['propuesta']['detalle']);
        $this->postJson('/agente/ejecutar', ['accion' => 'recordar_deuda', 'datos' => $r['propuesta']['datos']])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, \App\Models\Envio::where('tipo', 'recordatorio')->count());
        // Presupuesto.
        $r = $this->postJson('/agente/chat', ['mensaje' => 'presupuesto para Pérez de 3 ' . $p->name . ' y 2 de ' . $p->name])->assertOk()->json();
        $this->assertSame('crear_presupuesto', $r['propuesta']['accion']);
        $e = $this->postJson('/agente/ejecutar', ['accion' => 'crear_presupuesto', 'datos' => $r['propuesta']['datos']])->assertOk()->json();
        $this->assertStringContainsString('/editar', $e['url']); $this->assertSame(1, \App\Models\Comprobante::where('tipo', 'PRE')->where('estado', 'borrador')->count());
        // Cliente inexistente: explica en vez de proponer.
        $r = $this->postJson('/agente/chat', ['mensaje' => 'Cobrale 100 a Nadie Existente'])->assertOk()->json();
        $this->assertArrayNotHasKey('propuesta', $r); $this->assertStringContainsString('No encuentro', $r['respuesta']);
    }

    public function test_el_asistente_respeta_los_permisos_del_rol(): void
    {
        $vend = $this->usuarioConRol('vendedor'); $this->actingAs($vend);
        $this->postJson('/agente/chat', ['mensaje' => 'Gasté 100 en nafta'])->assertStatus(403);
        $this->postJson('/agente/ejecutar', ['accion' => 'registrar_gasto', 'datos' => ['cuenta_fondos_id' => $this->caja->id, 'monto' => 100, 'concepto' => 'x']])->assertStatus(403);
        $this->assertSame(0, MovimientoFondos::count());
        $this->assertNotContains('registrar_gasto', array_column(app(AccionesService::class)->herramientas($vend), 'name'), 'La IA ni siquiera ve la herramienta');
        $this->assertContains('registrar_cobro', array_column(app(AccionesService::class)->herramientas($vend), 'name'));
    }

    public function test_panel_del_dueno_y_paneles_por_rol(): void
    {
        $p = $this->articulo(['stock_inicial' => 10, 'stock_min' => 20]); $cli = $this->cliente();
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 500]]);
        $this->get('/dueno')->assertOk()->assertInertia(fn($i) => $i->component('Dueno', false)->where('hoy.ventas', fn($v) => abs($v - 1210) < 0.01)->where('hoy.facturas', 1)->where('cobrar.por_cobrar', fn($v) => abs($v - 1210) < 0.01)->has('top_hoy', 1)->has('ultimas', 1));
        $this->get('/dashboard')->assertOk()->assertInertia(fn($i) => $i->where('panel', null), 'El dueño no tiene panel por rol');
        $vendU = $this->usuarioConRol('vendedor'); Vendedor::create(['business_id' => $this->empresa->id, 'user_id' => $vendU->id, 'nombre' => 'Vendedor 1', 'comision_venta' => 10, 'comision_cobro' => 0, 'activo' => true]);
        $this->actingAs($vendU)->get('/dashboard')->assertOk()->assertInertia(fn($i) => $i->where('panel.tipo', 'vendedor')->has('panel.presupuestos')->has('panel.deudores'));
        $this->actingAs($this->usuarioConRol('cajero', 'cajero2@test.com'))->get('/dashboard')->assertOk()->assertInertia(fn($i) => $i->where('panel.tipo', 'cajero')->where('panel.caja.nombre', 'Caja'));
        $this->actingAs($this->usuarioConRol('deposito', 'dep@test.com'))->get('/dashboard')->assertOk()->assertInertia(fn($i) => $i->where('panel.tipo', 'deposito')->where('panel.bajo_minimo', 1));
        $this->actingAs($this->usuarioConRol('contador', 'cont@test.com'))->get('/dashboard')->assertOk()->assertInertia(fn($i) => $i->where('panel.tipo', 'contador')->where('panel.iva_df', fn($v) => abs($v - 210) < 0.01));
    }
}
