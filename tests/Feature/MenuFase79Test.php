<?php

namespace Tests\Feature;

use Tests\ErpTestCase;

// Fase 79: el menú va en el orden del día del negocio, con nombres de uso diario; los permisos y la ayuda no cambian.
class MenuFase79Test extends ErpTestCase
{
    private function nav($user): array
    {
        return collect($this->actingAs($user)->get('/dashboard')->assertOk()->viewData('page')['props']['nav'])->flatMap(fn($g) => array_map(fn($i) => $i + ['grupo' => $g['label']], $g['items']))->values()->all();
    }

    public function test_el_dueno_ve_el_menu_en_el_orden_del_ciclo_y_sin_alertas(): void
    {
        $nav = $this->nav($this->dueno);
        $keys = array_column($nav, 'key');
        $this->assertSame(['inicio', 'vender', 'cobrar', 'entregar', 'clientes', 'comprar', 'stock', 'plata', 'contable', 'estadisticas'], array_slice($keys, 0, 10)); // Fase 82: Sueldos vive adentro de Contable
        $this->assertSame('configuracion', end($keys)); $this->assertNotContains('alertas', $keys, 'Las alertas viven en la campana');
        $por = collect($nav)->keyBy('key');
        $this->assertSame(['Vender', '/comprobantes'], [$por['vender']['label'], $por['vender']['ruta']]);
        $this->assertSame(['Cobrar', '/clientes/cobranzas', 'clientes'], [$por['cobrar']['label'], $por['cobrar']['ruta'], $por['cobrar']['modulo']]);
        $this->assertSame(['Entregar', '/comprobantes/pendientes'], [$por['entregar']['label'], $por['entregar']['ruta']]);
        $this->assertSame(['Comprar', '/proveedores'], [$por['comprar']['label'], $por['comprar']['ruta']]);
        $this->assertSame(['Plata', '/fondos'], [$por['plata']['label'], $por['plata']['ruta']]);
        // Fase 88: los rubros son módulos como los demás (sin título de grupo) y sólo los del rubro de la empresa (corralón: sin Big Restó).
        $this->assertNotContains('gastronomia', $keys); $this->assertSame('', $por['vender']['grupo'], 'Las entradas del ciclo no llevan título de grupo');
        $this->assertEmpty(collect($nav)->where('grupo', 'Mi rubro'), 'Ya no hay grupo Mi rubro');
    }

    public function test_cada_rol_ve_solo_lo_suyo_con_los_nombres_nuevos(): void
    {
        $keys = array_column($this->nav($this->usuarioConRol('vendedor', 'v@test.com')), 'key');
        $this->assertSame(['inicio', 'vender', 'cobrar', 'entregar', 'clientes', 'stock'], $keys);
        $keys = array_column($this->nav($this->usuarioConRol('cajero', 'c@test.com')), 'key');
        $this->assertSame(['inicio', 'vender', 'entregar', 'plata'], $keys);
        $keys = array_column($this->nav($this->usuarioConRol('contador', 'k@test.com')), 'key');
        $this->assertContains('contable', $keys); $this->assertNotContains('stock', $keys);
    }

    public function test_las_etiquetas_nuevas_llegan_a_roles_permisos_y_ayuda_sin_tocar_las_claves(): void
    {
        $this->assertSame('Ventas', config('erp.modulos.comprobantes.label')); $this->assertSame('Plata', config('erp.modulos.fondos.label')); $this->assertSame('Compras', config('erp.modulos.proveedores.label'));
        $this->assertTrue($this->dueno->puede('comprobantes', 'crear'), 'Las claves de permiso no cambian');
        $r = $this->actingAs($this->dueno)->get('/configuracion/roles')->assertOk()->viewData('page')['props'];
        $this->assertContains('Ventas', collect($r['modulos'])->pluck('label')->all()); $this->assertContains('Plata', collect($r['modulos'])->pluck('label')->all());
        // Un vendedor sin permiso en Plata recibe el nombre nuevo en el aviso.
        $this->actingAs($this->usuarioConRol('vendedor', 'v2@test.com'))->get('/fondos')->assertForbidden()->assertSee('Plata');
        // El tour y Bigcito hablan del menú nuevo; la novedad quedó publicada.
        $this->assertStringContainsString('Vender, Cobrar, Entregar', json_encode(app(\App\Services\Producto\AyudaService::class)->tour('/dashboard') ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('antes Fondos', \App\Mcp\Recursos::instrucciones($this->dueno->fresh()));
        $this->assertStringContainsString('Reordenamos el menú', file_get_contents(database_path('migrations/2027_01_02_000001_fase79_novedad_menu.php')), 'La novedad se siembra en producción (no en las pruebas)');
    }
}
