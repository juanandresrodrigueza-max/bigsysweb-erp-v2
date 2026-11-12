<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\ErpTestCase;

// Fase 85: lo que faltaba del relevamiento UX, parte 2: nombres de Plata, pantallas de Stock por rubro, Alertas sin permiso propio.
class PlataStockAlertasFase85Test extends ErpTestCase
{
    private function props($user, string $url): array
    {
        return $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
    }

    public function test_plata_usa_nombres_de_uso_diario_y_cheques_y_valores_es_una_sola_entrada(): void
    {
        $s = $this->props($this->dueno, '/fondos/valores')['subnav'];
        $this->assertSame('plata', $s['key']);
        $labels = array_column($s['items'], 'label');
        $this->assertSame(['Cajas y bancos', 'Cerrar caja', 'Cheques y valores', 'Tarjetas', 'Conciliar', 'Dólares', 'Vencimientos previstos'], $labels);
        $this->assertNotContains('Valores', $labels, 'Valores vive adentro de Cheques y valores');
        $this->assertSame('cheques', collect($s['items'])->firstWhere('activo', true)['key'], 'Desde /fondos/valores la sección activa es Cheques y valores');
        $this->assertSame('cheques', collect($this->props($this->dueno, '/fondos/cheques')['subnav']['items'])->firstWhere('activo', true)['key']);
        $this->assertStringContainsString('cheques-valores-tabs', file_get_contents(resource_path('js/Pages/Fondos/Valores.vue')));
        $this->assertStringContainsString("href: '/fondos?cerrar=1'", file_get_contents(resource_path('js/Pages/Fondos/Cierres.vue')));
    }

    public function test_pesables_y_simulador_aparecen_solo_para_los_rubros_que_los_usan(): void
    {
        $keys = fn() => array_column($this->props($this->dueno, '/stock')['subnav']['items'], 'key');
        // Un corralón no es minimarket ni gastronomía y no tiene pesables: no ve ninguna de las dos.
        $this->assertNotContains('despiece', $keys()); $this->assertNotContains('consultor', $keys());
        // Apenas marca un artículo como pesable, aparece "Pesables y cortes".
        $this->articulo(['name' => 'Asado', 'pesable' => true]);
        $this->dueno->unsetRelation('business'); // en producción cada pedido trae la empresa fresca; en el test el usuario es el mismo objeto
        $this->assertContains('despiece', $keys());
        $this->assertSame('Pesables y cortes', collect($this->props($this->dueno, '/stock')['subnav']['items'])->firstWhere('key', 'despiece')['label']);
        // Un minimarket ve las dos.
        $this->empresa->forceFill(['vertical' => 'minimarket'])->save(); $this->dueno->unsetRelation('business');
        $this->assertContains('consultor', $keys());
        $this->assertSame('Simulador de precios', collect($this->props($this->dueno, '/stock')['subnav']['items'])->firstWhere('key', 'consultor')['label']);
    }

    public function test_la_campana_de_alertas_es_de_todos_y_no_se_asigna_por_rol(): void
    {
        Role::create(['business_id' => $this->empresa->id, 'nombre' => 'Sólo ventas', 'slug' => 'solo-ventas', 'permisos' => ['dashboard' => ['ver'], 'comprobantes' => ['ver', 'crear']]]);
        $u = $this->usuarioConRol('solo-ventas', 'sv@test.com');
        $this->assertTrue($u->puede('alertas'), 'Aunque el rol no lo nombre, ve las alertas');
        $this->assertContains('alertas', $u->modulosVisibles());
        $this->actingAs($u)->get('/alertas')->assertOk();
        $this->assertFalse($u->puede('fondos'), 'Lo demás sigue dependiendo del rol');
        // La matriz de roles ya no ofrece Alertas.
        $mods = array_column($this->props($this->dueno, '/configuracion/roles')['modulos'], 'key');
        $this->assertNotContains('alertas', $mods); $this->assertContains('comprobantes', $mods);
        $this->assertStringContainsString('campana', file_get_contents(base_path('database/migrations/2027_01_08_000001_fase85_novedad_plata.php')));
    }
}
