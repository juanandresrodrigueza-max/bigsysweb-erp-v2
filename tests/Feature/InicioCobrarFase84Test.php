<?php

namespace Tests\Feature;

use App\Models\PrecioPactado;
use Tests\ErpTestCase;

// Fase 84: lo que faltaba del relevamiento UX, parte 1: accesos del día en Inicio, Cobrar con "Registrar cobro",
// Comprar con "Nueva orden", la ficha del cliente con Ficha primero, Estadísticas con el Analista segundo, el encabezado y "Por entregar".
class InicioCobrarFase84Test extends ErpTestCase
{
    private function props($user, string $url): array
    {
        return $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
    }

    public function test_inicio_tiene_los_accesos_del_dia_y_plata_abre_el_cierre_desde_la_url(): void
    {
        $d = file_get_contents(resource_path('js/Pages/Dashboard.vue'));
        foreach (['accesos-del-dia', "href: '/vender'", "href: '/clientes/cobranzas'", "href: '/comprobantes/pendientes'", "href: '/fondos?cerrar=1'"] as $x) $this->assertStringContainsString($x, $d);
        $f = file_get_contents(resource_path('js/Pages/Fondos/Index.vue'));
        $this->assertStringContainsString("q.get('cerrar')", $f); $this->assertStringContainsString("q.get('nuevo')", $f, 'La paleta llega con ?nuevo=egreso');
    }

    public function test_cobrar_lleva_a_la_ficha_con_el_cobro_abierto_y_la_lista_trae_el_saldo(): void
    {
        $c = $this->cliente(['name' => 'Pérez Hnos']);
        $f = $this->factura($c, [['product_id' => $this->articulo()->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        $p = $this->props($this->dueno, '/clientes/cobranzas');
        $fila = collect($p['clientesLista'])->firstWhere('id', $c->id);
        $this->assertNotNull($fila); $this->assertArrayHasKey('saldo', $fila);
        $v = file_get_contents(resource_path('js/Pages/Clientes/Cobranzas.vue'));
        $this->assertStringContainsString("label: 'Registrar cobro'", $v); $this->assertStringContainsString('?cobro=1', $v);
        $this->assertStringContainsString("q.get('cobro')", file_get_contents(resource_path('js/Pages/Clientes/Ver.vue')));
    }

    public function test_la_ficha_del_cliente_muestra_acopios_y_precios_pactados_solo_si_la_empresa_los_usa(): void
    {
        $c = $this->cliente();
        $p = $this->props($this->dueno, "/clientes/{$c->id}");
        $this->assertFalse($p['usaAcopios']); $this->assertFalse($p['usaPactados']);
        PrecioPactado::create(['business_id' => $this->empresa->id, 'contact_id' => $c->id, 'product_id' => $this->articulo()->id, 'precio' => 10]);
        $this->assertTrue($this->props($this->dueno, "/clientes/{$c->id}")['usaPactados']);
        $v = file_get_contents(resource_path('js/Pages/Clientes/Ver.vue'));
        $this->assertStringContainsString("{ key: 'datos', label: 'Ficha' }, { key: 'cc'", $v, 'Ficha va primera');
    }

    public function test_comprar_estadisticas_encabezado_y_entregar(): void
    {
        $this->assertStringContainsString("label: 'Nueva orden', href: '/proveedores/ordenes/nueva'", file_get_contents(resource_path('js/Pages/Proveedores/Index.vue')));
        $e = file_get_contents(resource_path('js/Components/EstadisticasTabs.vue'));
        $this->assertLessThan(strpos($e, "key: 'ventas'"), strpos($e, "key: 'analista'"), 'Analista IA va segundo, después de Resumen');
        $l = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));
        $this->assertStringContainsString('Mi negocio</Link>', $l); $this->assertStringContainsString("(sucursales?.lista?.length ?? 0) < 2", $l);
        $s = $this->props($this->dueno, '/comprobantes/pendientes')['subnav'];
        $this->assertSame('Por entregar', collect($s['items'])->firstWhere('key', 'pendientes')['label']);
        $this->assertStringContainsString('accesos del día', file_get_contents(base_path('database/migrations/2027_01_07_000001_fase84_novedad_inicio.php')));
    }
}
