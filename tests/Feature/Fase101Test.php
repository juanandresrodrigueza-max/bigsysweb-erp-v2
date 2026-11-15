<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Empleado;
use App\Models\Product;
use App\Models\ProveedorArticulo;
use App\Models\Rubro;
use App\Models\Vendedor;
use App\Models\VentaHistorica;
use App\Services\Migracion\BigConnectService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 101 · Migración completa desde BIG Connect: rubros, vendedores, empleados, fondos, cheques, precios por proveedor, historia de ventas y control de cierre.
class Fase101Test extends ErpTestCase
{
    private const URL = 'https://hub.test';
    private const CLAVE = 'bk_ABCDEFGH_abcdefghijkmnpqrstuvwxyz2345';

    private function hub(array $cambios = []): void
    {
        $hace2 = today()->subMonths(2)->startOfMonth()->toDateString(); $hace1 = today()->subMonth()->startOfMonth()->toDateString();
        $tablas = array_replace([
            'rubros' => [['codrub' => 'CEM', 'descripcion' => 'Cementos', 'depto' => 'Obra gruesa', 'oculto' => 0], ['codrub' => 'VAR', 'descripcion' => 'Varios', 'depto' => '', 'oculto' => 1]],
            'vendedores' => [['codven' => 3, 'nombre' => 'Vito', 'com_venta' => 2.5, 'com_cobranza' => 1]],
            'clientes' => [
                ['codcta' => 10, 'tipo' => 'C', 'nombre' => 'Constructora Del Valle', 'cuit' => '30700123456', 'condicion_iva' => 'RI', 'vendedor' => 3, 'saldo' => 50000],
                ['codcta' => 1, 'tipo' => 'C', 'nombre' => 'VENTA CONTADO', 'cuit' => '', 'condicion_iva' => 'CF', 'contado' => 1],
                ['codcta' => 40, 'tipo' => 'E', 'nombre' => 'Juan Pérez', 'cuit' => '20257818163', 'telefono' => '261 555', 'direccion' => 'San Martín 100', 'localidad' => 'Mendoza', 'estado' => 'A'],
            ],
            'proveedores' => [['codcta' => 10, 'nombre' => 'Loma Negra', 'cuit' => '30500000001', 'condicion_iva' => 'RI', 'saldo' => 20000]],
            'productos' => [['codart' => 'CEM50', 'codbar' => '779000', 'nombre' => 'Cemento x 50', 'codrub' => 'CEM', 'unidad' => 'un', 'costo' => 7000, 'precio1' => 9000, 'precio2' => 8800, 'precio3' => 0, 'precio4' => 0, 'precio5' => 0, 'precio6' => 0, 'iva_pct' => 21, 'stock' => 40, 'stock_min' => 0, 'activo' => 1]],
            'cuenta_movimientos' => [
                ['tipcta' => 'C', 'codcta' => 10, 'tipcom' => 'FACT-A', 'pv' => 1, 'nrocom' => 1234, 'fecha' => '2026-10-01', 'vencimiento' => '2026-10-31', 'saldo_pendiente' => 50000],
                ['tipcta' => 'P', 'codcta' => 10, 'tipcom' => 'FACT-A', 'pv' => 3, 'nrocom' => 99, 'fecha' => '2026-10-05', 'vencimiento' => '2026-11-04', 'saldo_pendiente' => 20000],
            ],
            'fondos' => [['codfon' => 1, 'descri' => 'Caja mostrador', 'tipval' => 'EFE', 'saldo' => 125000.5, 'contable' => 0, 'valores' => 0], ['codfon' => 2, 'descri' => 'Banco Nación cta cte', 'tipval' => 'CHQ', 'saldo' => 900000, 'contable' => 0, 'valores' => 0, 'concilia' => 1], ['codfon' => 9, 'descri' => 'Cheques en cartera', 'tipval' => 'CHQ', 'saldo' => 70000, 'valores' => 1], ['codfon' => 50, 'descri' => '--- CUENTAS ---', 'contable' => 1]],
            'cheques' => [['banco' => 'Galicia', 'numero' => '00012345', 'vencimiento' => today()->addDays(20)->toDateString(), 'importe' => 70000, 'codcta' => 10, 'tipcta' => 'C', 'cliente' => 'Constructora Del Valle', 'recibido' => today()->subDays(5)->toDateString(), 'nromov' => 777, 'estado' => 'en_cartera'], ['banco' => 'Macro', 'numero' => '555', 'vencimiento' => '2026-01-01', 'importe' => 1000, 'estado' => 'depositado']],
            'proveedor_precios' => [['codart' => 'CEM50', 'tipcta' => 'P', 'codcta' => 10, 'codpro' => 'LN-CEM-50', 'precom' => 7150, 'fecha' => '2026-10-20']],
            'ventas_producto_dia' => [['codart' => 'CEM50', 'fecha' => $hace2, 'cantidad' => 30, 'importe' => 270000, 'costo' => 210000], ['codart' => 'CEM50', 'fecha' => $hace1, 'cantidad' => 90, 'importe' => 810000, 'costo' => 630000], ['codart' => 'CEM50', 'fecha' => '2020-01-05', 'cantidad' => 999, 'importe' => 1, 'costo' => 1], ['codart' => 'NOEXISTE', 'fecha' => $hace1, 'cantidad' => 5, 'importe' => 5, 'costo' => 1]],
        ], $cambios);
        Http::fake(function ($req) use ($tablas) {
            if ($req->header('Authorization')[0] !== 'Bearer ' . self::CLAVE) return Http::response(['error' => 'clave inválida'], 401);
            $path = parse_url($req->url(), PHP_URL_PATH);
            if ($path === '/api/v1/tablas') return Http::response(['negocio' => 'N1', 'empresa' => 'e01', 'tablas' => array_map(fn($t) => ['nombre' => $t], array_merge(array_keys($tablas), ['cuenta_movimientos']))]);
            $t = substr($path, strlen('/api/v1/tabla/'));
            parse_str(parse_url($req->url(), PHP_URL_QUERY) ?? '', $q);
            return Http::response(['filas' => array_slice($tablas[$t] ?? [], (int) ($q['desde'] ?? 0), (int) ($q['limite'] ?? 500))]);
        });
    }

    public function test_trae_todo_lo_demas_y_el_control_de_cierre_cierra(): void
    {
        $this->hub();
        $svc = app(BigConnectService::class); $svc->conectar($this->empresa, self::URL, self::CLAVE);
        $this->assertCount(12, BigConnectService::PASOS);
        $r = $svc->migrar($this->empresa->fresh(), false);
        $por = collect($r['pasos'])->keyBy('paso');
        // Rubros con departamento, y el artículo cuelga del rubro hijo.
        $this->assertSame(2, $por['rubros']['creadas'] + 0); $padre = Rubro::whereNull('parent_id')->where('nombre', 'Obra gruesa')->first(); $this->assertNotNull($padre);
        $cem = Rubro::where('parent_id', $padre->id)->where('nombre', 'Cementos')->first(); $this->assertNotNull($cem); $this->assertFalse(Rubro::where('nombre', 'Varios')->first()->en_tienda);
        $p = Product::where('sku', 'CEM50')->first(); $this->assertSame($cem->id, $p->rubro_id);
        // Vendedores con comisión, y el cliente con su vendedor.
        $vito = Vendedor::where('nombre', 'Vito')->first(); $this->assertEquals(2.5, (float) $vito->comision_venta);
        $cli = Contact::customers()->where('codigo', '10')->first(); $this->assertSame($vito->id, $cli->vendedor_id);
        // Empleados → legajo con el número de cuenta; el cliente E no entra como cliente.
        $e = Empleado::where('nombre', 'Juan Pérez')->first(); $this->assertSame(40, (int) $e->legajo); $this->assertSame('20-25781816-3', $e->cuil); $this->assertSame('Mendoza', $e->localidad);
        $this->assertSame(0, Contact::customers()->where('codigo', '40')->count()); $this->assertStringContainsString('fecha de ingreso', implode(' ', $por['empleados']['detalle']));
        // Fondos: caja y banco con su saldo; el de cheques y el separador contable no.
        $caja = CuentaFondos::where('nombre', 'Caja mostrador')->first(); $this->assertSame('caja', $caja->tipo); $this->assertEquals(125000.5, (float) $caja->saldo);
        $banco = CuentaFondos::where('nombre', 'Banco Nación cta cte')->first(); $this->assertSame('banco', $banco->tipo); $this->assertEquals(900000, (float) $banco->saldo); $this->assertTrue($banco->concilia);
        $this->assertSame(0, CuentaFondos::where('nombre', 'like', '%Cheques en cartera%')->count()); $this->assertSame(0, CuentaFondos::where('nombre', 'like', '%CUENTAS%')->count());
        // Cheques: sólo los que siguen en cartera, con su cliente.
        $this->assertSame(1, Cheque::count()); $ch = Cheque::first(); $this->assertSame('cartera', $ch->estado); $this->assertEquals(70000, (float) $ch->monto); $this->assertSame($cli->id, $ch->contact_id); $this->assertSame('Galicia', $ch->banco);
        // Precio por proveedor y proveedor habitual.
        $prov = Contact::suppliers()->where('codigo', '10')->first(); $pa = ProveedorArticulo::where('product_id', $p->id)->where('contact_id', $prov->id)->first();
        $this->assertSame('LN-CEM-50', $pa->codigo); $this->assertEquals(7150, (float) $pa->ultimo_costo); $this->assertSame($prov->id, $p->fresh()->proveedor_id);
        // Historia de ventas: 12 meses, sin lo viejo ni lo de artículos que no existen.
        $this->assertSame(2, VentaHistorica::where('product_id', $p->id)->count()); $this->assertSame(0, VentaHistorica::whereYear('fecha', 2020)->count());
        $this->assertStringContainsString('1 renglones', implode(' ', $por['historial_ventas']['detalle']));
        $this->assertEquals(60, VentaHistorica::promedioMensual($this->empresa->id, 6)[$p->id], '120 en 2 meses');
        // Qué reponer: sin mínimo y sin stock, sugiere por lo vendido. La ficha muestra la historia.
        $p->update(['stock' => 0, 'stock_min' => 0]);
        $fila = collect($this->get('/proveedores/reponer')->viewData('page')['props']['grupos'])->flatMap(fn($g) => $g['items'])->firstWhere('id', $p->id);
        $this->assertEquals(60, $fila['sugerido']); $this->assertTrue($fila['por_historia']);
        $this->assertCount(2, $this->get("/stock/{$p->id}")->viewData('page')['props']['historia']);
        $p->update(['stock' => 40]);

        // Repetir el día del corte: no duplica nada y el saldo de la caja se ajusta por la diferencia.
        app(\App\Services\Fondos\FondosService::class)->registrar($caja->fresh(), ['fecha' => today()->toDateString(), 'origen' => 'gasto', 'concepto' => 'Un gasto entre la prueba y el corte', 'egreso' => 25000.5]);
        $this->assertEquals(100000, (float) $caja->fresh()->saldo);
        $r2 = $svc->migrar($this->empresa->fresh(), false);
        $this->assertSame(1, Rubro::where('nombre', 'Cementos')->count()); $this->assertSame(1, Vendedor::count()); $this->assertSame(1, Empleado::count()); $this->assertSame(1, Cheque::count()); $this->assertSame(1, ProveedorArticulo::count()); $this->assertSame(2, CuentaFondos::whereIn('nombre', ['Caja mostrador', 'Banco Nación cta cte'])->count());
        $this->assertEquals(125000.5, (float) $caja->fresh()->saldo); $this->assertSame(0, collect($r2['pasos'])->firstWhere('paso', 'fondos')['creadas']);
        $this->assertSame(1, \App\Models\MovimientoFondos::where('cuenta_fondos_id', $caja->id)->where('origen', 'ajuste')->where('ingreso', 25000.5)->count());

        // Control de cierre: todo cierra.
        $c = $svc->cierre($this->empresa->fresh());
        $this->assertTrue($c['cierra'], json_encode(collect($c['filas'])->where('ok', false)->values(), JSON_UNESCAPED_UNICODE));
        $this->assertEquals(70000, collect($c['filas'])->firstWhere('que', 'Cheques en cartera')['erp']);
        $this->assertEquals(125000.5, collect($c['filas'])->firstWhere('que', 'Saldo de Caja mostrador')['erp']);
        $this->get('/configuracion/importar/bigconnect/control')->assertOk()->assertJsonPath('cierra', true);
        // Si el ERP se desvía, el control lo marca.
        $banco->update(['saldo' => 1]);
        $c = $svc->cierre($this->empresa->fresh()); $this->assertFalse($c['cierra']); $this->assertFalse(collect($c['filas'])->firstWhere('que', 'Saldo de Banco Nación cta cte')['ok']);
        $r = app(\App\Mcp\Catalogo::class)->llamar($this->dueno, 'control_migracion', [], 'asistente');
        $this->assertSame(1, $r['diferencias']); $this->assertStringContainsString('1 diferencias', $r['resumen']);
        // La pantalla ofrece los 12 pasos y la prueba por pasos elegidos no guarda.
        $this->assertCount(12, $this->get('/configuracion/importar')->viewData('page')['props']['pasos']);
        Cheque::query()->delete();
        $this->post('/configuracion/importar/bigconnect/migrar', ['prueba' => true, 'pasos' => ['cheques']])->assertSessionHas('migracion', fn($m) => count($m['pasos']) === 1 && $m['pasos'][0]['creadas'] === 1);
        $this->assertSame(0, Cheque::count());
    }

    public function test_tipo_de_fondo_por_nombre(): void
    {
        $svc = app(BigConnectService::class);
        $this->assertSame('caja', $svc->tipoFondo('Caja chica', 'EFE')); $this->assertSame('banco', $svc->tipoFondo('Bco Galicia cta cte', 'CHQ')); $this->assertSame('billetera', $svc->tipoFondo('Mercado Pago', 'EFE'));
        $this->assertSame('tarjeta', $svc->tipoFondo('Tarjetas Visa', 'CUP')); $this->assertSame('tarjeta', $svc->tipoFondo('Cupones', 'CUP')); $this->assertSame('banco', $svc->tipoFondo('Santander Río', 'EFE'));
    }
}
