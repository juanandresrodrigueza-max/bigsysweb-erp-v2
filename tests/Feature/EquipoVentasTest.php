<?php

namespace Tests\Feature;

use App\Models\AvisoVendedor;
use App\Models\NotaCliente;
use App\Models\Relevamiento;
use App\Models\Vendedor;
use App\Models\Visita;
use Tests\ErpTestCase;

// Fase 29.6: el reporte del vendedor (lo suyo y nada más), el equipo para el encargado, avisos con vigencia, notas y relevamiento.
class EquipoVentasTest extends ErpTestCase
{
    private function vendedor(string $nombre = 'Vito', string $mail = 'vito@test.com'): array
    {
        $u = $this->usuarioConRol('vendedor', $mail);
        $v = Vendedor::create(['business_id' => $this->empresa->id, 'user_id' => $u->id, 'nombre' => $nombre, 'comision_venta' => 5, 'comision_cobro' => 1, 'activo' => true, 'modo_default' => 'calle', 'objetivo_mensual' => 10000]);
        return [$u, $v];
    }

    public function test_mi_reporte_suma_solo_lo_propio_con_objetivo_y_ritmo_y_quienes_faltan(): void
    {
        [$u, $v] = $this->vendedor(); [$u2, $v2] = $this->vendedor('Otro', 'otro@test.com');
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 100]);
        $a = $this->cliente(['name' => 'A', 'cuit' => '20-11111111-1', 'vendedor_id' => $v->id]); $b = $this->cliente(['name' => 'B', 'cuit' => '20-22222222-2', 'vendedor_id' => $v->id]);
        $c = $this->cliente(['name' => 'C', 'cuit' => '20-33333333-3', 'vendedor_id' => $v2->id]);
        $this->factura($a, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 100]], ['vendedor_id' => $v->id]);
        $this->factura($a, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 100]], ['vendedor_id' => $v->id, 'fecha' => today()->subDays(3)->toDateString()]);
        $this->factura($c, [['product_id' => $p->id, 'cantidad' => 50, 'precio_unit' => 100]], ['vendedor_id' => $v2->id]);
        $this->actingAs($u)->postJson("/vender/cliente/{$a->id}/visita", ['tipo' => 'llegue'])->assertOk();
        $r = $this->actingAs($u)->getJson('/vender/reporte?periodo=hoy')->assertOk()->json();
        $this->assertEqualsWithDelta(1210, $r['hoy']['total'], 0.01, 'Sólo lo propio: la venta de Otro no cuenta');
        $this->assertSame(1, $r['hoy']['comprobantes']); $this->assertEqualsWithDelta(1210, $r['hoy']['ticket'], 0.01);
        $this->assertEqualsWithDelta(1210 * 0.05, $r['comision']['por_ventas'], 0.01);
        $this->assertSame(2, $r['mes']['clientes_cartera']); $this->assertSame(1, $r['mes']['compraron']);
        $this->assertSame(['B'], array_column($r['faltan'], 'nombre'));
        $this->assertSame('A', $r['compraron'][0]['nombre']);
        // Objetivo 10.000: lleva 2.420 en el mes.
        $esperado = round(10000 * today()->day / today()->daysInMonth, 2);
        $this->assertEqualsWithDelta(2420, $r['objetivo']['vendido'], 0.01); $this->assertSame(2420 >= $esperado ? 'adelantado' : 'atrasado', $r['objetivo']['ritmo']);
        $this->assertSame(1, $r['periodo_resumen']['visitas']);
        $this->assertSame($p->id, $r['top'][0]['product_id']);
        // El del mes pasado está vacío.
        $this->assertEqualsWithDelta(0, $this->actingAs($u)->getJson('/vender/reporte?periodo=mes_pasado')->json('periodo_resumen.total'), 0.01);
    }

    public function test_el_encargado_ve_ranking_ruta_donde_estuvieron_y_motivos(): void
    {
        [$u, $v] = $this->vendedor(); [$u2, $v2] = $this->vendedor('Otro', 'otro@test.com');
        $p = $this->articulo(['price' => 100, 'stock_inicial' => 100]);
        $a = $this->cliente(['name' => 'A', 'cuit' => '20-11111111-1', 'vendedor_id' => $v->id, 'lat' => -34.6000, 'lng' => -58.4000]); $b = $this->cliente(['name' => 'B', 'cuit' => '20-22222222-2', 'vendedor_id' => $v->id]);
        $this->factura($a, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 100]], ['vendedor_id' => $v->id]);
        $this->factura($b, [['product_id' => $p->id, 'cantidad' => 30, 'precio_unit' => 100]], ['vendedor_id' => $v2->id]);
        $this->actingAs($u)->postJson("/vender/cliente/{$a->id}/visita", ['tipo' => 'llegue', 'lat' => -34.6005, 'lng' => -58.4000])->assertOk();   // a ~55 m
        $this->actingAs($u)->postJson("/vender/cliente/{$b->id}/visita", ['tipo' => 'no_vendi', 'motivo' => 'cerrado', 'lat' => -34.7, 'lng' => -58.5])->assertOk();
        $r = $this->actingAs($this->dueno)->get('/estadisticas/vendedores?periodo=hoy')->assertOk()->assertInertia(fn($pg) => $pg->component('Estadisticas/Vendedores', false))->inertiaProps();
        $this->assertSame('Otro', $r['ranking'][0]['nombre']); $this->assertSame('Vito', $r['ranking'][1]['nombre']);
        $vito = $r['ranking'][1];
        $this->assertSame(2, $vito['ruta']['asignados']); $this->assertSame(2, $vito['ruta']['visitados']); $this->assertSame(1, $vito['ruta']['vendidos']); $this->assertSame([], $vito['ruta']['faltan']);
        $this->assertSame(1, $vito['no_vendi']); $this->assertSame(['Estaba cerrado' => 1], $r['motivos']);
        $this->assertNotNull($vito['objetivo']);
        $donde = collect($r['donde']);
        $this->assertSame('en el cliente', $donde->firstWhere('cliente', 'A')['distancia']['texto']);
        $this->assertSame('primera vez acá', $donde->firstWhere('cliente', 'B')['distancia']['texto'], 'B no tenía ubicación: aprende con esta visita');
        $this->assertEqualsWithDelta(1210 + 3630, collect($r['por_dia'])->sum('monto'), 0.01);
        // Un vendedor no entra a lo del equipo.
        $this->actingAs($u)->get('/estadisticas/vendedores')->assertStatus(403);
    }

    public function test_avisos_con_vigencia_notas_del_cliente_y_relevamiento_con_foto(): void
    {
        [$u, $v] = $this->vendedor(); [$u2, $v2] = $this->vendedor('Otro', 'otro@test.com');
        $c = $this->cliente(['vendedor_id' => $v->id]);
        // Avisos: a todos, a uno, vencido y futuro.
        $this->actingAs($this->dueno)->post('/clientes/vendedores/avisos', ['titulo' => 'Promo galletitas', 'texto' => '3x2 toda la semana', 'urgente' => true])->assertRedirect();
        $this->actingAs($this->dueno)->post('/clientes/vendedores/avisos', ['titulo' => 'Sólo para Otro', 'vendedor_id' => $v2->id])->assertRedirect();
        $this->actingAs($this->dueno)->post('/clientes/vendedores/avisos', ['titulo' => 'Vencido', 'hasta' => today()->subDay()->toDateString()])->assertRedirect();
        $this->actingAs($this->dueno)->post('/clientes/vendedores/avisos', ['titulo' => 'Arranca el lunes', 'desde' => today()->addDays(3)->toDateString()])->assertRedirect();
        $av = $this->actingAs($u)->getJson('/vender/avisos')->assertOk()->json();
        $this->assertSame(['Promo galletitas'], array_column($av, 'titulo')); $this->assertTrue($av[0]['urgente']);
        $this->assertSame(['Promo galletitas', 'Sólo para Otro'], collect($this->actingAs($u2)->getJson('/vender/avisos')->json())->pluck('titulo')->sort()->values()->all());
        $this->actingAs($u)->get('/vender?modo=calle')->assertInertia(fn($pg) => $pg->where('avisos.0.titulo', 'Promo galletitas'));
        // El vendedor no puede publicar avisos.
        $this->actingAs($u)->post('/clientes/vendedores/avisos', ['titulo' => 'X'])->assertStatus(403);
        $this->actingAs($this->dueno)->post('/clientes/vendedores/avisos/' . AvisoVendedor::where('titulo', 'Promo galletitas')->value('id') . '/baja')->assertRedirect();
        $this->assertCount(0, $this->actingAs($u)->getJson('/vender/avisos')->json());
        // Notas: aparecen hasta que se marcan listas; no se borran.
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/notas", ['texto' => 'Prefiere entrega los martes', 'offline_id' => 'n1'])->assertOk();
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/notas", ['texto' => 'Prefiere entrega los martes', 'offline_id' => 'n1'])->assertOk()->assertJsonPath('repetida', true);
        $this->assertSame(1, NotaCliente::count());
        $f = $this->actingAs($u)->getJson("/vender/cliente/{$c->id}")->json();
        $this->assertStringStartsWith('Prefiere entrega', $f['notas'][0]['texto']);
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/notas/" . NotaCliente::first()->id . '/listo')->assertOk()->assertJsonCount(0, 'notas');
        $this->assertSame(1, NotaCliente::count());
        $this->actingAs($this->dueno)->get("/clientes/{$c->id}")->assertInertia(fn($pg) => $pg->where('notasCalle.0.listo', true));
        // Relevamiento con foto.
        $png = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\nfoto");
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/relevar", ['tipo' => 'competencia', 'nota' => 'lo tiene a $1.200', 'foto' => $png, 'lat' => -34.6, 'lng' => -58.4])->assertOk();
        $this->actingAs($u)->postJson("/vender/cliente/{$c->id}/relevar", ['tipo' => 'gondola', 'foto' => 'nada'])->assertStatus(422);
        $rel = Relevamiento::first();
        $this->assertSame('competencia', $rel->tipo); $this->assertSame($v->id, $rel->vendedor_id);
        $this->actingAs($this->dueno)->get('/estadisticas/vendedores?periodo=hoy')->assertInertia(fn($pg) => $pg->where('relevamientos.0.tipo', 'Precio de la competencia'));
        $this->actingAs($this->dueno)->get("/estadisticas/vendedores/relevamiento/{$rel->id}/foto")->assertOk();
        [$otra, $sucB, $duenoB] = $this->crearEmpresa('Otra', 'otra');
        $this->actingAs($duenoB)->get("/estadisticas/vendedores/relevamiento/{$rel->id}/foto")->assertNotFound();
        \Illuminate\Support\Facades\Storage::disk('local')->delete($rel->foto);
    }
}
