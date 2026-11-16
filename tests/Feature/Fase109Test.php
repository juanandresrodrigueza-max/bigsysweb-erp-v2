<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Cheque;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Empleado;
use App\Models\MovimientoFondos;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Comprobantes\CobroService;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Facades\Auth;
use Tests\ErpTestCase;

// Fase 109: todo por voz. Editar, dar de baja, compras, notas de crédito, remitos, usuarios, empleados, turnos, cheques y caja
// por Bigcito, siempre con propuesta → confirmación y con el permiso del módulo.
class Fase109Test extends ErpTestCase
{
    private function hacer(User $u, string $tool, array $args): array
    {
        $cat = app(Catalogo::class); $p = $cat->llamar($u, $tool, $args, 'asistente');
        if (isset($p['error'])) return $p;
        $this->assertArrayHasKey('propuesta_id', $p, "La acción {$tool} tiene que proponer antes de ejecutar");
        return $cat->llamar($u->fresh(), 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
    }

    public function test_que_podes_hacer_lista_las_acciones_segun_permisos(): void
    {
        $r = app(Catalogo::class)->llamar($this->dueno, 'que_podes_hacer', [], 'asistente');
        $this->assertGreaterThan(50, $r['total_acciones']);
        $nombres = collect($r['grupos'])->flatMap(fn($g) => collect($g['acciones'])->pluck('accion'));
        foreach (['editar_cliente', 'dar_de_baja', 'cargar_compra', 'nota_de_credito', 'crear_usuario', 'cerrar_caja'] as $n) $this->assertContains($n, $nombres);
        $v = $this->usuarioConRol('vendedor');
        $rv = app(Catalogo::class)->llamar($v, 'que_podes_hacer', [], 'asistente');
        $this->assertLessThan($r['total_acciones'], $rv['total_acciones']);
        $this->assertNotContains('crear_usuario', collect($rv['grupos'])->flatMap(fn($g) => collect($g['acciones'])->pluck('accion')));
    }

    public function test_editar_cliente_proveedor_y_articulo_con_antes_y_despues(): void
    {
        $c = $this->cliente(['name' => 'Ferretería López', 'phone' => '351 1111']);
        $p = app(Catalogo::class)->llamar($this->dueno, 'editar_cliente', ['cliente' => 'López', 'cambios' => ['telefono' => '351 2222', 'dias_pago' => 30, 'comprobante_interno' => true]], 'asistente');
        $this->assertStringContainsString('351 1111 → 351 2222', $p['detalle']);
        app(Catalogo::class)->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $c->refresh(); $this->assertSame('351 2222', $c->phone); $this->assertSame(30, (int) $c->dias_pago); $this->assertTrue((bool) $c->comprobante_interno);
        $this->assertArrayHasKey('error', app(Catalogo::class)->llamar($this->dueno, 'editar_cliente', ['cliente' => 'López', 'cambios' => ['color' => 'rojo']], 'asistente'));
        $this->assertArrayHasKey('error', app(Catalogo::class)->llamar($this->dueno, 'editar_cliente', ['cliente' => 'López', 'cambios' => ['cuit' => '20-11111111-1']], 'asistente'));

        $prov = $this->proveedor(['name' => 'Distribuidora Norte']);
        $this->hacer($this->dueno, 'editar_proveedor', ['proveedor' => 'Norte', 'cambios' => ['dias_pago' => 45, 'email' => 'ventas@norte.com']]);
        $this->assertSame('ventas@norte.com', $prov->fresh()->email);

        $r = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Herrajes']);
        $a = $this->articulo(['name' => 'Tornillo 3 mm', 'price' => 100]);
        $this->hacer($this->dueno, 'editar_articulo', ['articulo' => 'Tornillo', 'cambios' => ['precio' => 120, 'rubro' => 'Herrajes', 'proveedor' => 'Norte', 'stock_minimo' => 50]]);
        $a->refresh(); $this->assertEquals(120, (float) $a->price); $this->assertSame($r->id, $a->rubro_id); $this->assertSame($prov->id, $a->proveedor_id); $this->assertEquals(50, (float) $a->stock_min);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('accion', 'editar')->where('descripcion', 'like', '%Tornillo%Bigcito%')->exists());
    }

    public function test_dar_de_baja_con_la_regla_de_cada_cosa_y_el_permiso(): void
    {
        $conMov = $this->cliente(['name' => 'Con Movimientos']); $art = $this->articulo(['name' => 'Cemento', 'price' => 1000]);
        $f = $this->factura($conMov, [['product_id' => $art->id, 'cantidad' => 1, 'precio_unit' => 1000, 'alicuota_iva' => 21]]);
        $sinMov = $this->cliente(['name' => 'Sin Movimientos', 'cuit' => '30-71234567-1']);
        $nuevo = $this->articulo(['name' => 'Nunca vendido']);

        $r = $this->hacer($this->dueno, 'dar_de_baja', ['tipo' => 'cliente', 'cual' => 'Con Movimientos']);
        $this->assertStringContainsString('desactivado', $r['texto']); $this->assertFalse((bool) $conMov->fresh()->is_active); $this->assertNotNull(Contact::find($conMov->id));
        $r = $this->hacer($this->dueno, 'dar_de_baja', ['tipo' => 'cliente', 'cual' => 'Sin Movimientos']);
        $this->assertStringContainsString('borrado', $r['texto']); $this->assertNull(Contact::find($sinMov->id));
        $this->hacer($this->dueno, 'dar_de_baja', ['tipo' => 'articulo', 'cual' => 'Cemento']); $this->assertFalse((bool) $art->fresh()->active);
        $this->hacer($this->dueno, 'dar_de_baja', ['tipo' => 'articulo', 'cual' => 'Nunca vendido']); $this->assertNull(Product::find($nuevo->id));
        // Una factura emitida no se da de baja por acá.
        $this->assertArrayHasKey('error', app(Catalogo::class)->llamar($this->dueno, 'dar_de_baja', ['tipo' => 'presupuesto', 'cual' => (string) $f->id], 'asistente'));
        // Un cobro se anula con motivo; sin motivo, pide.
        $cobro = app(CobroService::class)->registrar($conMov, ['fecha' => today()->toDateString(), 'medios' => [['medio' => 'efectivo', 'monto' => 500]], 'imputaciones' => []]);
        $this->assertArrayHasKey('error', app(Catalogo::class)->llamar($this->dueno, 'dar_de_baja', ['tipo' => 'cobro', 'cual' => (string) $cobro->numero], 'asistente'));
        $r = $this->hacer($this->dueno, 'dar_de_baja', ['tipo' => 'cobro', 'cual' => (string) $cobro->numero, 'motivo' => 'Cargado dos veces']);
        $this->assertSame('anulado', $cobro->fresh()->estado);
        // Un gasto se borra y la plata vuelve.
        $g = app(FondosService::class)->registrar($this->caja, ['origen' => 'gasto', 'concepto' => 'Nafta', 'egreso' => 3000]);
        $saldo = (float) $this->caja->fresh()->saldo;
        $this->hacer($this->dueno, 'dar_de_baja', ['tipo' => 'gasto', 'cual' => 'Nafta']);
        $this->assertNull(MovimientoFondos::find($g->id)); $this->assertEquals($saldo + 3000, (float) $this->caja->fresh()->saldo);
        // Permiso: el vendedor no puede dar de baja clientes.
        $v = $this->usuarioConRol('vendedor');
        $this->assertStringContainsString('permiso', app(Catalogo::class)->llamar($v, 'dar_de_baja', ['tipo' => 'cliente', 'cual' => 'Con Movimientos'], 'asistente')['error']);
    }

    public function test_proveedor_compra_nota_de_credito_y_remito(): void
    {
        $r = $this->hacer($this->dueno, 'crear_proveedor', ['nombre' => 'Aceros Sur', 'cuit' => '30-50000000-3', 'dias_pago' => 30]);
        $prov = Contact::suppliers()->where('name', 'Aceros Sur')->firstOrFail(); $this->assertSame('Responsable Inscripto', $prov->condicion_iva);
        $art = $this->articulo(['name' => 'Hierro 8', 'price' => 2000, 'cost' => 1000, 'stock_inicial' => 0]);
        $r = $this->hacer($this->dueno, 'cargar_compra', ['proveedor' => 'Aceros Sur', 'tipo' => 'A', 'numero' => '0003-00000123', 'items' => [['articulo' => 'Hierro 8', 'cantidad' => 10, 'precio' => 1100, 'iva' => 21]]]);
        $this->assertStringContainsString('registrada', $r['texto']);
        $compra = Comprobante::compras()->where('contact_id', $prov->id)->firstOrFail(); $this->assertSame('emitido', $compra->estado); $this->assertEquals(13310, (float) $compra->total);
        $this->assertEquals(10, (float) $art->fresh()->stock);
        $r2 = $this->hacer($this->dueno, 'cargar_compra', ['proveedor' => 'Aceros Sur', 'total' => 12100, 'concepto' => 'Flete']);
        $this->assertEquals(12100, (float) Comprobante::compras()->where('contact_id', $prov->id)->latest('id')->first()->total);

        $cli = $this->cliente(); $f = $this->factura($cli, [['product_id' => $art->id, 'cantidad' => 2, 'precio_unit' => 2000, 'alicuota_iva' => 21]]);
        $stock = (float) $art->fresh()->stock;
        $r = $this->hacer($this->dueno, 'nota_de_credito', ['factura' => (string) $f->id, 'importe' => 1210, 'motivo' => 'Bonificación']);
        $nc = Comprobante::where('origen_id', $f->id)->where('tipo', 'like', 'NC%')->firstOrFail();
        $this->assertSame('emitido', $nc->estado); $this->assertEquals(1210, (float) $nc->total); $this->assertEquals($stock, (float) $art->fresh()->stock); // parcial por importe: no mueve stock
        $r = $this->hacer($this->dueno, 'nota_de_credito', ['factura' => (string) $f->id, 'items' => [['articulo' => 'Hierro 8', 'cantidad' => 1]]]);
        $this->assertEquals($stock + 1, (float) $art->fresh()->stock);
        $r = $this->hacer($this->dueno, 'crear_remito', ['factura' => (string) $f->id]);
        $this->assertStringContainsString('Remito', $r['texto']); $this->assertTrue(Comprobante::where('tipo', 'REM')->where('origen_id', $f->id)->where('estado', 'emitido')->exists());
    }

    public function test_usuario_empleado_turno_cheque_y_caja(): void
    {
        $r = $this->hacer($this->dueno, 'crear_usuario', ['nombre' => 'Caja Uno', 'email' => 'caja1@test.com', 'rol' => 'cajero']);
        $this->assertStringContainsString('Contraseña provisoria', $r['texto']);
        $x = User::where('email', 'caja1@test.com')->firstOrFail(); $this->assertSame('cajero', $x->role?->slug);
        $this->hacer($this->dueno, 'editar_usuario', ['usuario' => 'caja1@test.com', 'rol' => 'vendedor', 'activo' => false]);
        $x->refresh(); $this->assertSame('vendedor', $x->role?->slug); $this->assertSame('inactive', $x->status);
        $this->assertArrayHasKey('error', app(Catalogo::class)->llamar($this->dueno, 'editar_usuario', ['usuario' => $this->dueno->email, 'activo' => false], 'asistente'));

        $r = $this->hacer($this->dueno, 'cargar_empleado', ['nombre' => 'Marta Gómez', 'cuil' => '27-12345678-0', 'sueldo_basico' => 900000, 'puesto' => 'Vendedora']);
        $e = Empleado::where('nombre', 'Marta Gómez')->firstOrFail(); $this->assertSame(1, (int) $e->legajo); $this->assertSame('27-12345678-0', $e->cuil);

        $cli = $this->cliente(['name' => 'Ana Turno', 'mobile' => '351 999']); $srv = $this->articulo(['name' => 'Corte de pelo', 'tipo' => 'servicio', 'price' => 8000]);
        $fecha = today()->addDay()->toDateString();
        $r = $this->hacer($this->dueno, 'agendar_turno', ['cliente' => 'Ana Turno', 'fecha' => $fecha, 'hora' => '10:00', 'minutos' => 45, 'servicio' => 'Corte', 'profesional' => $this->dueno->name]);
        $t = Booking::where('contact_id', $cli->id)->firstOrFail(); $this->assertSame('confirmed', $t->status); $this->assertEquals(8000, (float) $t->price);
        $this->assertArrayHasKey('error', app(Catalogo::class)->llamar($this->dueno, 'agendar_turno', ['nombre' => 'Otro', 'fecha' => $fecha, 'hora' => '10:15', 'profesional' => $this->dueno->name], 'asistente')); // choca
        $this->hacer($this->dueno, 'dar_de_baja', ['tipo' => 'turno', 'cual' => 'Ana Turno']); $this->assertSame('cancelled', $t->fresh()->status);

        $ch = Cheque::create(['business_id' => $this->empresa->id, 'tipo' => 'tercero', 'numero' => '00123456', 'banco' => 'Nación', 'emisor' => 'Pérez', 'fecha_emision' => today(), 'fecha_pago' => today(), 'monto' => 50000, 'estado' => 'cartera']);
        $r = $this->hacer($this->dueno, 'depositar_cheque', ['cheque' => '123456', 'banco' => $this->banco->nombre]);
        $this->assertStringContainsString('depositado', $r['texto']); $this->assertNotSame('cartera', $ch->fresh()->estado);

        TurnoCaja::whereNull('cierre')->update(['cierre' => now()]);
        $r = $this->hacer($this->dueno, 'abrir_caja', ['saldo_inicial' => 10000]);
        $this->assertStringContainsString('abierta', $r['texto']);
        $this->assertArrayHasKey('error', app(Catalogo::class)->llamar($this->dueno, 'abrir_caja', ['saldo_inicial' => 1], 'asistente'));
        $r = $this->hacer($this->dueno, 'cerrar_caja', ['contado' => 9500]);
        $this->assertStringContainsString('Faltante', $r['texto']);
        $this->assertEquals(-500, (float) TurnoCaja::latest('id')->first()->diferencia);
    }
}
