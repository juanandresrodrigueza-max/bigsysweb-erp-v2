<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use App\Services\Ventas\CobranzasService;
use App\Support\Catalogo;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Tests\ErpTestCase;

// Fase 16: lo que tiene que seguir andando con muchos datos y en cualquier motor.
class EscalaTest extends ErpTestCase
{
    public function test_sql_portable_da_mes_hora_y_dias_en_el_motor_actual(): void
    {
        $f = $this->factura($this->cliente(), [['product_id' => $this->articulo(['stock_inicial' => 5])->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $r = DB::table('comprobantes')->where('id', $f->id)->selectRaw(Sql::mes('fecha') . ' as mes, ' . Sql::hora('emitido_en') . ' as hora, ' . Sql::diasHasta('fecha', today()->addDays(3)->toDateString()) . ' as dias')->first();
        $this->assertSame(today()->format('Y-m'), $r->mes);
        $this->assertSame((int) now()->format('G'), (int) $r->hora);
        $this->assertSame(3, (int) $r->dias);
        $this->assertContains(Sql::like(), ['like', 'ilike']);
    }

    public function test_catalogo_chico_va_completo_y_grande_va_parcial_con_busqueda(): void
    {
        for ($i = 0; $i < 5; $i++) $this->articulo(['name' => "Hierro del {$i}", 'sku' => "H{$i}"]);
        [$rows, $parcial] = Catalogo::productos('venta');
        $this->assertFalse($parcial); $this->assertCount(5, $rows); $this->assertArrayHasKey('precios', $rows[0]);
        $this->assertCount(1, Catalogo::buscar('articulos', 'venta', 'del 3'));
        $this->assertSame('H3', Catalogo::buscar('articulos', 'venta', 'h3')[0]['sku'], 'Busca por código sin importar mayúsculas');
        $this->getJson('/buscar/articulos/venta?q=hierro')->assertOk()->assertJsonCount(5);
        $this->getJson('/buscar/contactos/cliente?q=consumidor')->assertOk()->assertJsonCount(1);
        $this->getJson('/buscar/otra/cosa?q=x')->assertNotFound();
        // Con muchos registros el formulario recibe solo los que ya usa (no todo el catálogo).
        $limite = Catalogo::LIMITE; $viejo = (new \ReflectionClass(Catalogo::class))->getConstant('LIMITE');
        $ids = []; $ahora = now()->toDateTimeString();
        for ($k = 0; $k < $viejo + 10; $k += 500) { $rows = []; for ($i = $k; $i < min($viejo + 10, $k + 500); $i++) $rows[] = ['business_id' => $this->empresa->id, 'name' => "Masivo {$i}", 'sku' => "M{$i}", 'tipo' => 'producto', 'unit' => 'un', 'cost' => 1, 'price' => 2, 'iva' => 21, 'stock' => 0, 'stock_min' => 0, 'active' => true, 'controla_stock' => true, 'moneda' => 'ARS', 'created_at' => $ahora, 'updated_at' => $ahora]; DB::table('products')->insert($rows); }
        $usado = Product::where('sku', 'M7')->value('id');
        [$rows, $parcial] = Catalogo::productos('venta', [$usado]);
        $this->assertTrue($parcial); $this->assertCount(1, $rows); $this->assertSame($usado, $rows[0]['id']);
        $this->get('/comprobantes/nuevo')->assertOk()->assertInertia(fn($p) => $p->component('Comprobantes/Form', false)->where('catalogoParcial.productos', true)->where('catalogoParcial.clientes', false));
        $this->assertLessThanOrEqual(30, Catalogo::buscar('articulos', 'venta', 'Masivo')->count(), 'La búsqueda devuelve como mucho 30');
    }

    public function test_sku_es_unico_por_empresa_pero_dos_empresas_pueden_repetirlo(): void
    {
        $this->articulo(['sku' => 'REPETIDO']);
        [$otra] = $this->crearEmpresa('Otra', 'otra');
        Product::withoutGlobalScopes()->create(['business_id' => $otra->id, 'name' => 'De la otra', 'sku' => 'REPETIDO', 'tipo' => 'producto', 'unit' => 'un', 'cost' => 1, 'price' => 2, 'iva' => 21, 'stock' => 0, 'active' => true]);
        $this->assertSame(2, Product::withoutGlobalScopes()->where('sku', 'REPETIDO')->count());
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->articulo(['sku' => 'REPETIDO']);
    }

    public function test_deudores_se_calculan_con_una_consulta_agrupada_y_los_kpis_cubren_toda_la_cartera(): void
    {
        $p = $this->articulo(['stock_inicial' => 100]);
        $a = $this->cliente(['name' => 'Moroso', 'cuit' => '30-1', 'interes_mora' => 3]);
        $b = $this->cliente(['name' => 'Al día', 'cuit' => '30-2']);
        $fa = $this->factura($a, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]], ['fecha' => today()->subDays(40)->toDateString()]);
        $this->factura($b, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 500]]);
        $fa->update(['fecha_vto' => today()->subDays(10)->toDateString()]);
        $svc = app(CobranzasService::class);
        $d = $svc->deudores();
        $this->assertCount(2, $d);
        $m = $d->firstWhere('nombre', 'Moroso');
        $this->assertEqualsWithDelta(1210, $m['vencido'], 0.01); $this->assertSame(10, $m['dias']); $this->assertSame(1, $m['facturas']);
        $this->assertEqualsWithDelta(1210 * 3 / 100 / 30 * 10, $m['mora'], 0.01, 'Mora = saldo × interés mensual / 30 × días');
        $this->assertEqualsWithDelta(0, $d->firstWhere('nombre', 'Al día')['vencido'], 0.01);
        $k = $svc->resumenDeudores();
        $this->assertEqualsWithDelta(1210 + 605, $k['por_cobrar'], 0.01); $this->assertEqualsWithDelta(1210, $k['vencido'], 0.01); $this->assertSame(1, $k['deudores']);
        $this->assertCount(1, $svc->deudores(1), 'El límite corta la lista pero no los totales');
        $this->get('/clientes/cobranzas')->assertOk();
    }
}
