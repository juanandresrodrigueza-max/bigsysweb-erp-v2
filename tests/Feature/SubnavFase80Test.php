<?php

namespace Tests\Feature;

use Tests\ErpTestCase;

// Fase 80: cada módulo comparte su sub-navegación (columna izquierda) según la pantalla; las fichas y formularios van a pantalla completa.
class SubnavFase80Test extends ErpTestCase
{
    private function subnav($user, string $url): ?array
    {
        return $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props']['subnav'];
    }

    public function test_cada_seccion_trae_la_sub_navegacion_de_su_modulo_con_el_item_activo(): void
    {
        $s = $this->subnav($this->dueno, '/comprobantes');
        $this->assertSame(['vender', 'Vender', '/comprobantes'], [$s['key'], $s['titulo'], $s['ruta']]);
        $this->assertSame(['comprobantes', 'pedidos', 'abonos', 'lote'], array_column($s['items'], 'key'));
        $this->assertSame('comprobantes', collect($s['items'])->firstWhere('activo', true)['key']);

        $s = $this->subnav($this->dueno, '/fondos/conciliar');
        $this->assertSame('plata', $s['key']); $this->assertSame('conciliar', collect($s['items'])->firstWhere('activo', true)['key']);

        $s = $this->subnav($this->dueno, '/stock');
        $this->assertSame(['articulos', 'precios', 'inventario', 'movimientos', 'lotes', 'series', 'almacen', 'etiquetas', 'informes', 'desc-lista', 'catalogos', 'tienda-online', 'importar', 'despiece', 'consultor'], array_column($s['items'], 'key'));
        $this->assertSame('De vez en cuando', collect($s['items'])->firstWhere('key', 'catalogos')['grupo']);
    }

    public function test_la_ruta_mas_larga_gana_asi_pases_es_de_entregar_y_conexiones_ia_de_configuracion(): void
    {
        $s = $this->subnav($this->dueno, '/stock/pases');
        $this->assertSame('entregar', $s['key']); $this->assertSame(['pendientes', 'entregas', 'pases'], array_column($s['items'], 'key'));
        $this->assertSame('pases', collect($s['items'])->firstWhere('activo', true)['key']);

        $s = $this->subnav($this->dueno, '/conexiones-ia');
        $this->assertSame('configuracion', $s['key']); $this->assertSame('conexiones-ia', collect($s['items'])->firstWhere('activo', true)['key']);
        $this->assertSame('suscripcion', end($s['items'])['key']);
    }

    public function test_fichas_y_formularios_van_a_pantalla_completa(): void
    {
        $this->assertNull($this->subnav($this->dueno, '/comprobantes/nuevo'), 'El formulario de factura no lleva sub-menú');
        $this->assertNull($this->subnav($this->dueno, '/proveedores/compras/nueva'));
        $this->assertNull($this->subnav($this->dueno, '/dashboard'), 'Inicio no tiene secciones');
        $c = $this->cliente();
        $this->assertNull($this->subnav($this->dueno, "/clientes/{$c->id}"), 'La ficha del cliente va a pantalla completa');
    }

    public function test_cada_rol_ve_solo_las_secciones_que_puede_usar(): void
    {
        $vendedor = $this->usuarioConRol('vendedor', 'v@test.com');
        $s = $this->subnav($vendedor, '/stock');
        $this->assertSame('stock', $s['key']);
        $this->assertNotContains('precios', array_column($s['items'], 'key'), 'Precios en masa pide stock.editar');
        $this->assertNotContains('inventario', array_column($s['items'], 'key'));
        $this->assertContains('movimientos', array_column($s['items'], 'key'));
        // Un cajero no ve Stock: en Entregar no le aparece "Pases entre sucursales".
        $cajero = $this->usuarioConRol('cajero', 'c@test.com');
        $s = $this->subnav($cajero, '/comprobantes/pendientes');
        $this->assertSame('entregar', $s['key']); $this->assertSame(['pendientes', 'entregas'], array_column($s['items'], 'key'));
    }

    public function test_bigcito_y_la_ayuda_cuentan_la_sub_navegacion(): void
    {
        $this->assertStringContainsString('columna', \App\Mcp\Recursos::instrucciones($this->dueno));
        $this->assertStringContainsString('Más', file_get_contents(base_path('docs/ayuda/primeros-pasos.md')));
        $this->assertStringContainsString('sub-navegación', file_get_contents(base_path('database/migrations/2027_01_03_000001_fase80_novedad_subnav.php')));
    }
}
