<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\Comprobante;
use App\Models\TurnoCaja;
use App\Models\Vendedor;
use App\Services\Estadisticas\PlataService;
use App\Services\Estadisticas\QueHacerService;
use Illuminate\Support\Carbon;
use Tests\ErpTestCase;

// Fase 33.5–33.6: Plata (qué entra y cuándo, cartera por semana, cobranza por cobrador, IVA del mes) y Qué hacer (hallazgos y control de usuarios).
class PlataQueHacerTest extends ErpTestCase
{
    public function test_que_entra_y_cuando_cartera_por_semana_cobradores_e_iva_del_mes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00'));
        $cli = $this->cliente(['name' => 'Deudor']); $p = $this->articulo(['price' => 1000, 'iva' => 21, 'stock_inicial' => 100]);
        $cf = $this->cliente(['name' => 'Consumidor Final']);
        $vencida = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]], ['fecha_vto' => '2026-10-01']);          // 1210 vencida
        $pronto = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 1000]], ['fecha_vto' => '2026-11-15']);           // 2420 a 18 días
        $lejos = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 3, 'precio_unit' => 1000]], ['fecha_vto' => '2027-03-01']);            // 3630 a más de 90
        $this->factura($cf, [['product_id' => $p->id, 'cantidad' => 10, 'precio_unit' => 1000]], ['fecha_vto' => '2026-09-01']);                     // consumidor final: no entra
        foreach ([[$vencida, '2026-10-01'], [$pronto, '2026-11-15'], [$lejos, '2027-03-01']] as [$f, $vto]) { $f->forceFill(['fecha_vto' => $vto])->saveQuietly(); $this->assertGreaterThan(0, (float) $f->fresh()->saldo); }
        Cheque::create(['business_id' => $this->empresa->id, 'tipo' => 'tercero', 'numero' => '1', 'banco' => 'Galicia', 'emisor' => 'Pepe', 'fecha_emision' => '2026-10-20', 'fecha_pago' => '2026-11-05', 'monto' => 500, 'estado' => 'cartera']);
        Cheque::create(['business_id' => $this->empresa->id, 'tipo' => 'tercero', 'numero' => '2', 'banco' => 'Nación', 'emisor' => 'Juan', 'fecha_emision' => '2026-10-20', 'fecha_pago' => '2026-12-20', 'monto' => 700, 'estado' => 'cartera']);
        Cheque::create(['business_id' => $this->empresa->id, 'tipo' => 'tercero', 'numero' => '3', 'banco' => 'Nación', 'emisor' => 'Ana', 'fecha_emision' => '2026-10-20', 'fecha_pago' => '2026-11-05', 'monto' => 300, 'estado' => 'depositado']); // ya no está en cartera
        $pl = app(PlataService::class);
        $e = $pl->queEntra($this->empresa);
        $this->assertEquals(1210, $e['tramos']['vencido']); $this->assertEquals(2920, $e['tramos']['d30']); $this->assertEquals(700, $e['tramos']['d60']); $this->assertEquals(3630, $e['tramos']['mas90']); $this->assertEquals(8460, $e['total']);
        $this->assertEquals(500, $e['cheques']['d30']); $this->assertSame(3, $e['cantidad_facturas']); $this->assertSame(2, $e['cantidad_cheques']);
        $c = $pl->carteraPorSemana($this->empresa);
        $this->assertCount(2, $c['semanas']); $this->assertSame('2026-11-02', $c['semanas'][0]['semana']); $this->assertEquals(500, $c['semanas'][0]['monto']); $this->assertEquals(1200, $c['total']);
        // cobranza por cobrador
        $cob = Vendedor::create(['business_id' => $this->empresa->id, 'nombre' => 'Cobrador Carlos', 'activo' => true]);
        app(\App\Services\Comprobantes\CobroService::class)->registrar($cli, ['fecha' => '2026-10-10', 'cobrador_id' => $cob->id, 'medios' => [['medio' => 'efectivo', 'monto' => 1210]], 'imputaciones' => [['comprobante_id' => $vencida->id, 'monto' => 1210]]]);
        app(\App\Services\Comprobantes\CobroService::class)->registrar($cli, ['fecha' => '2026-10-12', 'medios' => [['medio' => 'retencion', 'monto' => 200, 'datos' => ['impuesto' => 'iva']], ['medio' => 'efectivo', 'monto' => 2220]], 'imputaciones' => [['comprobante_id' => $pronto->id, 'monto' => 2420]]]);
        $r = $pl->cobranzaPorCobrador($this->empresa, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertEquals(3630, $r['total']); $carlos = collect($r['items'])->firstWhere('nombre', 'Cobrador Carlos'); $this->assertEquals(1210, $carlos['monto']); $this->assertEquals(33.3, $carlos['parte']);
        // IVA del mes: débito 21 % de 16.000 = 3.360; crédito de una compra; retención de IVA de 200
        $prov = $this->proveedor(['cuit' => '30-50000000-1']);
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'numero_proveedor' => '0001-00000001', 'fecha' => '2026-10-05', 'registrar' => true, 'condicion' => 'cta_cte', 'items' => [['product_id' => $p->id, 'descripcion' => 'x', 'cantidad' => 10, 'precio_unit' => 500, 'alicuota_iva' => 21]], 'impuestos' => [['tipo' => 'iva', 'monto' => 150]]])->assertSessionHasNoErrors();
        $iva = $pl->ivaDelMes($this->empresa, Carbon::parse('2026-10-15'));
        $this->assertEquals(3360, $iva['debito']); $this->assertEquals(1050, $iva['credito']); $this->assertEquals(150, $iva['percepciones']); $this->assertEquals(200, $iva['retenciones']); $this->assertEquals(1960, $iva['posicion']); $this->assertEquals(1960, $iva['a_pagar']);
        $this->get('/estadisticas/plata?mes=2026-10')->assertOk()->assertInertia(fn($pg) => $pg->component('Estadisticas/Plata', false)->where('entra.total', 4830)->where('iva.posicion', 1960)->has('cartera.semanas', 2));
        Carbon::setTestNow();
    }

    public function test_hallazgos_de_la_semana_y_control_de_usuarios(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00'));
        $qh = app(QueHacerService::class);
        // lista al revés
        $p = $this->articulo(['name' => 'Al revés', 'price' => 100, 'prices' => ['2' => 120, '3' => 90], 'stock_inicial' => 50]);
        // cliente que dejó de comprar: 3 facturas hace 4 meses, nada en 60 días
        $fiel = $this->cliente(['name' => 'Fiel que se fue', 'mobile' => '11 2222 3333']); $sigue = $this->cliente(['name' => 'Sigue']);
        foreach ([1, 2, 3] as $i) { $f = $this->factura($fiel, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]); $f->forceFill(['fecha' => "2026-06-1{$i}", 'created_at' => "2026-06-1{$i} 10:00:00", 'emitido_en' => "2026-06-1{$i} 10:00:00"])->saveQuietly(); $g = $this->factura($sigue, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]); $g->forceFill(['fecha' => "2026-06-1{$i}", 'created_at' => "2026-06-1{$i} 10:00:00", 'emitido_en' => "2026-06-1{$i} 10:00:00"])->saveQuietly(); }
        $this->factura($sigue, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        // deuda vieja: vencida hace 80 días
        $vieja = $this->factura($fiel, [['product_id' => $p->id, 'cantidad' => 5, 'precio_unit' => 100]], ['fecha_vto' => '2026-08-01']); $vieja->forceFill(['fecha' => '2026-07-01', 'fecha_vto' => '2026-08-01', 'created_at' => '2026-07-01 10:00:00', 'emitido_en' => '2026-07-01 10:00:00'])->saveQuietly();
        // caja sin cerrar hace 2 días
        TurnoCaja::create(['business_id' => $this->empresa->id, 'cuenta_fondos_id' => $this->caja->id, 'user_id' => $this->dueno->id, 'apertura' => now()->subDays(2), 'saldo_inicial' => 0]);
        // margen bajo el mínimo
        $this->post('/estadisticas/rentabilidad/minimos', ['general' => 50]); $flojo = $this->articulo(['name' => 'Flojo', 'cost' => 100, 'price' => 110]);
        $h = $qh->hallazgos($this->empresa->fresh()); $por = collect($h['hallazgos'])->keyBy('clave');
        $this->assertSame(1, $por['listas']['numero']); $this->assertSame('Al revés', $por['listas']['items'][0]['nombre']); $this->assertSame(2, $por['listas']['items'][0]['lista']);
        $this->assertSame(1, $por['clientes']['numero']); $this->assertSame('Fiel que se fue', $por['clientes']['items'][0]['nombre']);
        $this->assertSame(1, $por['deuda']['numero']); $this->assertEquals(605, $por['deuda']['items'][0]['saldo']);
        $this->assertSame(1, $por['caja']['numero']); $this->assertSame(48, $por['caja']['items'][0]['horas']);
        $this->assertGreaterThanOrEqual(1, $por['margen']['numero']); $this->assertContains('Flojo', array_column($por['margen']['items'], 'nombre')); $this->assertSame(5, $h['graves']);
        // control de usuarios: un anulado, uno a deshora (domingo), uno con fecha atrasada, uno modificado después
        $this->post("/comprobantes/{$vieja->id}/anular", ['motivo' => 'prueba'])->assertRedirect();
        $dom = $this->factura($sigue, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]); $dom->forceFill(['emitido_en' => Carbon::parse('2026-10-25 11:00')])->saveQuietly(); // domingo
        $atras = $this->factura($sigue, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]); $atras->forceFill(['fecha' => '2026-10-20', 'emitido_en' => now()])->saveQuietly();
        AuditLog::registrar('editar', $dom, 'Editó la factura después'); AuditLog::where('descripcion', 'Editó la factura después')->update(['created_at' => now()->addHour()]);
        $c = $qh->controlUsuarios($this->empresa->fresh(), 30);
        $this->assertCount(1, $c['anulados']); $this->assertCount(1, $c['deshora']); $this->assertSame('domingo', $c['deshora'][0]['motivo']); $this->assertCount(1, $c['atrasados']); $this->assertCount(1, $c['modificados']);
        $u = collect($c['por_usuario'])->firstWhere('usuario', $this->dueno->name); $this->assertSame(1, $u['anulados']); $this->assertSame(1, $u['deshora']); $this->assertSame(1, $u['atrasados']); $this->assertSame(1, $u['modificados']);
        $this->get('/estadisticas/que-hacer')->assertOk()->assertInertia(fn($pg) => $pg->component('Estadisticas/QueHacer', false)->has('hallazgos.hallazgos', 5)->has('control.por_usuario', 1));
        Carbon::setTestNow();
    }
}
