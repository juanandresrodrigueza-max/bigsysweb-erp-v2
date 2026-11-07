<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\Product;
use App\Services\Migracion\BigConnectService;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 41: migrar un cliente de BigSys desde la API de BIG Connect, con prueba sin guardar y repetible sin duplicar.
class BigConnectMigracionTest extends ErpTestCase
{
    private const URL = 'https://hub.test';
    private const CLAVE = 'bk_ABCDEFGH_abcdefghijkmnpqrstuvwxyz2345';

    private function hub(): void
    {
        $tablas = [
            'vendedores' => [['codven' => 3, 'nombre' => 'Vito']],
            'clientes' => [
                ['codcta' => 10, 'nombre' => 'Constructora Del Valle', 'cuit' => '30700123456', 'condicion_iva' => 'RI', 'mail' => 'a@b.com', 'telefono' => '351', 'direccion' => 'Colón 1', 'localidad' => 'Córdoba', 'provincia' => 'Córdoba', 'lista_precios' => 2, 'descuento' => 5, 'vendedor' => 3, 'limite_credito' => 100000, 'contado' => 0],
                ['codcta' => 1, 'nombre' => 'VENTA CONTADO', 'cuit' => '', 'condicion_iva' => 'CF', 'contado' => 1],
            ],
            'proveedores' => [['codcta' => 10, 'nombre' => 'Loma Negra', 'cuit' => '30500000001', 'condicion_iva' => 'RI']],
            'productos' => [['codart' => 'CEM50', 'codbar' => '779000', 'nombre' => 'Cemento x 50', 'rubro' => 'Cementos', 'unidad' => 'un', 'costo' => 7000, 'precio1' => 9000, 'precio2' => 8800, 'precio3' => 0, 'precio4' => 0, 'precio5' => 0, 'precio6' => 0, 'iva_pct' => 21, 'stock' => 40, 'stock_min' => 5]],
            'cuenta_movimientos' => [
                ['tipcta' => 'C', 'codcta' => 10, 'tipcom' => 'FACT-A', 'pv' => 1, 'nrocom' => 1234, 'fecha' => '2026-10-01', 'vencimiento' => '2026-10-31', 'saldo_pendiente' => 50000],
                ['tipcta' => 'P', 'codcta' => 10, 'tipcom' => 'FACT-A', 'pv' => 3, 'nrocom' => 99, 'fecha' => '2026-10-05', 'vencimiento' => '2026-11-04', 'saldo_pendiente' => 20000],
            ],
        ];
        Http::fake(function ($req) use ($tablas) {
            if ($req->header('Authorization')[0] !== 'Bearer ' . self::CLAVE) return Http::response(['error' => 'clave inválida'], 401);
            $path = parse_url($req->url(), PHP_URL_PATH);
            if ($path === '/api/v1/tablas') return Http::response(['negocio' => 'N1', 'empresa' => 'e01', 'tablas' => array_map(fn($t) => ['nombre' => $t], array_keys($tablas))]);
            $t = substr($path, strlen('/api/v1/tabla/'));
            parse_str(parse_url($req->url(), PHP_URL_QUERY) ?? '', $q);
            $filas = array_slice($tablas[$t] ?? [], (int) ($q['desde'] ?? 0), (int) ($q['limite'] ?? 500));
            return Http::response(['filas' => $filas]);
        });
    }

    public function test_conectar_probar_y_migrar_sin_duplicar(): void
    {
        $this->hub();
        $this->actingAs($this->dueno)->post('/configuracion/importar/bigconnect', ['url' => self::URL . '/api/v1', 'clave' => 'bk_malo'])->assertSessionHasErrors('clave');
        $this->actingAs($this->dueno)->post('/configuracion/importar/bigconnect', ['url' => self::URL, 'clave' => self::CLAVE])->assertSessionHasNoErrors();
        $this->assertSame(self::URL, $this->empresa->fresh()->bigconnect['url']);
        $this->assertStringNotContainsString(self::CLAVE, (string) \DB::table('businesses')->where('id', $this->empresa->id)->value('bigconnect')); // va cifrada

        $antes = [Contact::count(), Product::count()];
        $this->actingAs($this->dueno)->post('/configuracion/importar/bigconnect/migrar', ['prueba' => true])->assertSessionHas('migracion', fn($r) => $r['prueba'] && collect($r['pasos'])->firstWhere('paso', 'clientes')['creadas'] === 1);
        $this->assertSame($antes, [Contact::count(), Product::count()]); // la prueba no guarda

        $svc = app(BigConnectService::class);
        $r = $svc->migrar($this->empresa->fresh(), false);
        $this->assertSame(1, $r['cuenta_contado']);
        $cli = Contact::customers()->where('codigo', '10')->first();
        $this->assertSame('Constructora Del Valle', $cli->name);
        $this->assertSame(2, (int) $cli->lista_precios);
        $prov = Contact::suppliers()->where('codigo', '10')->first();
        $this->assertSame('Loma Negra', $prov->name); // mismo número de cuenta, no se mezclan
        $p = Product::where('sku', 'CEM50')->first();
        $this->assertEquals(9000, (float) $p->precioListaCruda(1));
        $this->assertEquals(40, (float) $p->stock);
        $this->assertEquals(50000, (float) CuentaCorriente::where('contact_id', $cli->id)->sum('debe'));

        // El día del corte se repite: actualiza, no duplica.
        $svc->migrar($this->empresa->fresh(), false);
        $this->assertSame(1, Contact::customers()->where('codigo', '10')->count());
        $this->assertSame(1, Product::where('sku', 'CEM50')->count());
        $this->assertEquals(50000, (float) CuentaCorriente::where('contact_id', $cli->id)->sum('debe'));
    }

    public function test_bigcito_migra_con_confirmacion(): void
    {
        $this->hub();
        $cat = app(Catalogo::class);
        $this->assertArrayHasKey('error', $cat->llamar($this->dueno, 'migrar_desde_bigconnect', ['prueba' => true], 'asistente'));
        app(BigConnectService::class)->conectar($this->empresa, self::URL, self::CLAVE);
        $p = $cat->llamar($this->dueno->fresh(), 'migrar_desde_bigconnect', ['prueba' => true], 'asistente');
        $r = $cat->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']]);
        $this->assertStringContainsString('Prueba (sin guardar)', $r['texto']);
        $this->assertSame(0, Product::where('sku', 'CEM50')->count());
    }
}
