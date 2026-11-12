<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\ErpTestCase;

// Fase 82: Configuración en cuatro grupos con buscador, Contable en tres grupos con Sueldos adentro; Ctrl K encuentra las secciones.
class GruposFase82Test extends ErpTestCase
{
    private function props($user, string $url): array
    {
        return $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
    }

    public function test_configuracion_va_en_cuatro_grupos_con_palabras_para_el_buscador(): void
    {
        $s = $this->props($this->dueno, '/configuracion/usuarios')['subnav'];
        $grupos = array_values(array_unique(array_column($s['items'], 'grupo')));
        $this->assertSame(['Mi empresa', 'Ventas y fiscal', 'Integraciones', 'Datos y cuenta'], $grupos);
        $por = collect($s['items'])->keyBy('key');
        $this->assertSame('Integraciones', $por['conexiones-ia']['grupo']); $this->assertSame('Datos y cuenta', $por['suscripcion']['grupo']);
        $this->assertStringContainsString('arca', $por['puntos-venta']['palabras']); $this->assertStringContainsString('backup', $por['datos']['palabras']);
        $this->assertTrue($por['usuarios']['activo']);
    }

    public function test_contable_va_en_tres_grupos_y_sueldos_adentro(): void
    {
        $s = $this->props($this->dueno, '/sueldos')['subnav'];
        $this->assertSame('contable', $s['key'], 'Liquidaciones es una sección de Contable');
        $grupos = array_values(array_unique(array_column($s['items'], 'grupo')));
        $this->assertSame(['Lo diario', 'Para el contador', 'Sueldos', 'Puesta a punto'], $grupos);
        $this->assertTrue(collect($s['items'])->firstWhere('key', 'liquidaciones')['activo']);
        $this->assertSame(['resumen', 'asientos', 'mayor', 'iva'], collect($s['items'])->where('grupo', 'Lo diario')->pluck('key')->values()->all());
        // El menú principal del dueño ya no lleva Sueldos suelto.
        $keys = collect($this->props($this->dueno, '/dashboard')['nav'])->flatMap(fn($g) => array_column($g['items'], 'key'))->all();
        $this->assertNotContains('sueldos', $keys); $this->assertContains('contable', $keys);
    }

    public function test_quien_ve_sueldos_pero_no_contable_lo_tiene_suelto_en_el_menu(): void
    {
        Role::create(['business_id' => $this->empresa->id, 'nombre' => 'RRHH', 'slug' => 'rrhh', 'permisos' => ['dashboard' => ['ver'], 'sueldos' => ['ver', 'crear', 'editar']]]);
        $u = $this->usuarioConRol('rrhh', 'rrhh@test.com');
        $keys = collect($this->props($u, '/dashboard')['nav'])->flatMap(fn($g) => array_column($g['items'], 'key'))->all();
        $this->assertContains('sueldos', $keys); $this->assertNotContains('contable', $keys);
        $s = $this->props($u, '/sueldos')['subnav'];
        $this->assertSame('sueldos', $s['key']); $this->assertSame(['liquidaciones', 'recibos'], array_column($s['items'], 'key'));
    }

    public function test_ctrl_k_encuentra_las_secciones_porque_viajan_con_el_menu(): void
    {
        $nav = collect($this->props($this->dueno, '/dashboard')['nav'])->flatMap(fn($g) => $g['items'])->keyBy('key');
        $this->assertContains('Liquidaciones', array_column($nav['contable']['secciones'], 'label'));
        $this->assertContains('Puntos de venta y AFIP', array_column($nav['configuracion']['secciones'], 'label'));
        $this->assertSame([], $nav['inicio']['secciones']);
        $this->assertStringContainsString('secciones', file_get_contents(resource_path('js/Components/Paleta.vue')));
        $this->assertStringContainsString('subnav-buscar', file_get_contents(resource_path('js/Components/SubNav.vue')));
        $this->assertStringContainsString('cuatro grupos', \App\Mcp\Recursos::instrucciones($this->dueno));
        $this->assertStringContainsString('cuatro grupos', file_get_contents(base_path('database/migrations/2027_01_05_000001_fase82_novedad_grupos.php')));
    }
}
