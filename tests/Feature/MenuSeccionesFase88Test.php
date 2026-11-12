<?php

namespace Tests\Feature;

use Tests\ErpTestCase;

// Fase 88: las secciones cuelgan de cada módulo en el menú de la izquierda (se despliegan y se pliegan), la página va a todo el ancho,
// y cada rubro habilitado es un módulo como los demás con sus pantallas debajo.
class MenuSeccionesFase88Test extends ErpTestCase
{
    private function nav($user, string $url = '/dashboard'): \Illuminate\Support\Collection
    {
        return collect($this->actingAs($user)->get($url)->assertOk()->viewData('page')['props']['nav'])->flatMap(fn($g) => $g['items'])->keyBy('key');
    }

    public function test_las_secciones_viajan_completas_con_cada_modulo_del_menu(): void
    {
        $nav = $this->nav($this->dueno);
        $stock = $nav['stock']['secciones'];
        $this->assertSame('articulos', $stock[0]['key']); $this->assertSame('/stock', $stock[0]['ruta']);
        $this->assertSame('De vez en cuando', collect($stock)->firstWhere('key', 'catalogos')['grupo'], 'El grupo viaja para titular dentro del menú');
        $this->assertSame('Mi empresa', $nav['configuracion']['secciones'][0]['grupo']);
        $this->assertSame([], $nav['inicio']['secciones']);
        $this->assertNotNull(collect($nav['plata']['secciones'])->firstWhere('key', 'cierres')['ayuda']);
    }

    public function test_los_rubros_prendidos_son_modulos_como_los_demas(): void
    {
        $this->empresa->update(['verticales_extra' => ['gastronomia', 'hoteleria']]);
        $nav = $this->nav($this->dueno->fresh());
        $this->assertArrayNotHasKey('rubro', $nav->all());
        $this->assertSame('Big Restó', $nav['gastronomia']['label']);
        $this->assertSame(['mesas', 'cocina', 'carta', 'reservas', 'llamador'], array_column($nav['gastronomia']['secciones'], 'key'));
        $this->assertSame('', $nav['gastronomia']['grupo'] ?? '', 'Sin título de grupo: al mismo nivel que Vender o Stock');
        // Hotelería tiene una sola pantalla, que es su raíz: no hay nada que desplegar.
        $this->assertCount(1, $nav['hoteleria']['secciones']); $this->assertSame('/hoteleria', $nav['hoteleria']['secciones'][0]['ruta']);
        // La sección activa se resuelve con la clave del módulo del rubro.
        $s = $this->actingAs($this->dueno->fresh())->get('/gastronomia/cocina')->assertOk()->viewData('page')['props']['subnav'];
        $this->assertSame('gastronomia', $s['key']); $this->assertTrue(collect($s['items'])->firstWhere('key', 'cocina')['activo']);
    }

    public function test_la_pagina_va_a_todo_el_ancho_y_el_menu_despliega(): void
    {
        $layout = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));
        $this->assertStringNotContainsString('SubNav', $layout, 'La columna dentro de la página desapareció');
        $this->assertStringContainsString('<MenuLateral', $layout);
        $this->assertFileDoesNotExist(resource_path('js/Components/SubNav.vue'));
        $menu = file_get_contents(resource_path('js/Components/MenuLateral.vue'));
        foreach (['plegar-', 'data-secciones', 'aria-expanded', 'data-ir-'] as $x) $this->assertStringContainsString($x, $menu);
        $this->assertStringContainsString('se despliegan', \App\Services\Producto\AyudaService::tour()[0]['texto']);
        $this->assertStringContainsString('cuelgan del menú', file_get_contents(base_path('database/migrations/2027_01_10_000001_fase88_novedad_menu_secciones.php')));
    }
}
