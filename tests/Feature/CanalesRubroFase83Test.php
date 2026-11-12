<?php

namespace Tests\Feature;

use App\Models\Pago;
use Tests\ErpTestCase;

// Fase 83: los canales de venta bajo Vender, "Qué reponer" y "Pagos" en Comprar, y "Mi rubro" como una sola entrada del menú.
class CanalesRubroFase83Test extends ErpTestCase
{
    private function props($user, string $url): array
    {
        return $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
    }

    public function test_los_canales_de_venta_viven_en_vender_aunque_sus_urls_no_cambien(): void
    {
        $s = $this->props($this->dueno, '/comprobantes')['subnav'];
        $this->assertSame(['mostrador', 'comprobantes', 'abonos', 'lote', 'pedidos', 'tienda-online', 'canales'], array_column($s['items'], 'key'));
        $this->assertSame('Canales', collect($s['items'])->firstWhere('key', 'pedidos')['grupo']);
        // Tienda online sigue en /stock/tienda-online, pero es una sección de Vender; la configuración de canales sigue siendo de Configuración.
        $s = $this->props($this->dueno, '/stock/tienda-online')['subnav'];
        $this->assertSame('vender', $s['key']); $this->assertTrue(collect($s['items'])->firstWhere('key', 'tienda-online')['activo']);
        $this->assertNotContains('tienda-online', array_column($this->props($this->dueno, '/stock')['subnav']['items'], 'key'));
        $this->assertSame('configuracion', $this->props($this->dueno, '/configuracion/tienda')['subnav']['key']);
        $this->assertNull($this->props($this->dueno, '/vender')['subnav'], 'El mostrador va a pantalla completa');
    }

    public function test_comprar_arranca_por_que_reponer_y_termina_en_pagos(): void
    {
        $this->assertSame(['reponer', 'proveedores', 'ordenes', 'compras', 'pagos'], array_column($this->props($this->dueno, '/proveedores')['subnav']['items'], 'key'));

        $prov = $this->proveedor(['name' => 'Cementos del Sur']);
        $this->articulo(['name' => 'Cemento', 'controla_stock' => true, 'stock_min' => 10, 'stock' => 2, 'proveedor_id' => $prov->id, 'cost' => 100]);
        $this->articulo(['name' => 'Arena', 'controla_stock' => true, 'stock_min' => 5, 'stock' => 50]);
        $this->articulo(['name' => 'Cal', 'controla_stock' => true, 'stock_min' => 0, 'stock' => 0]); // sin mínimo pero sin stock: entra, sin cantidad sugerida
        $p = $this->props($this->dueno, '/proveedores/reponer');
        $this->assertSame(2, $p['total']); $this->assertSame('Cementos del Sur', $p['grupos'][0]['proveedor']); $this->assertSame(1, $p['sinProveedor']);
        $this->assertNull(collect($p['grupos'])->last()['items'][0]['sugerido']);
        $this->assertSame(18.0, $p['grupos'][0]['items'][0]['sugerido'], 'Hasta el doble del mínimo'); $this->assertSame(1800.0, $p['grupos'][0]['estimado']);
    }

    public function test_pagos_lista_las_ordenes_de_pago_con_filtros(): void
    {
        $prov = $this->proveedor(['name' => 'Ferretera Norte']);
        $this->actingAs($this->dueno);
        Pago::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'contact_id' => $prov->id, 'user_id' => $this->dueno->id, 'numero' => 1, 'fecha' => today(), 'total' => 1500, 'estado' => 'confirmado']);
        Pago::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'contact_id' => $prov->id, 'user_id' => $this->dueno->id, 'numero' => 2, 'fecha' => today(), 'total' => 400, 'estado' => 'anulado']);
        $p = $this->props($this->dueno, '/proveedores/pagos');
        $this->assertSame(1, $p['resumen']['cantidad']); $this->assertSame(1500.0, $p['resumen']['total']);
        $this->assertSame(['OP 00000002', 'OP 00000001'], array_column($p['lista']['data'], 'numero'), 'Los últimos primero');
        $p = $this->props($this->dueno, '/proveedores/pagos?estado=anulado&buscar=Norte');
        $this->assertSame(['OP 00000002'], array_column($p['lista']['data'], 'numero')); $this->assertSame('anulado', $p['filtros']['estado']);
        $this->assertSame('comprar', $p['subnav']['key']);
    }

    public function test_mi_rubro_es_una_sola_entrada_con_los_verticales_adentro(): void
    {
        $nav = $this->props($this->dueno, '/dashboard')['nav'];
        $rubro = collect($nav)->firstWhere('label', 'Mi rubro');
        $verticales = array_values(array_intersect(['gastronomia', 'retail', 'minimarket', 'servicios', 'hoteleria', 'obras', 'produccion', 'agenda'], $this->dueno->modulosVisibles()));
        if (! $verticales) { $this->assertNull($rubro); return; }
        $this->assertCount(1, $rubro['items'], 'Una sola entrada, no una por vertical');
        $e = $rubro['items'][0];
        $this->assertSame('rubro', $e['key']); $this->assertSame($verticales, $e['verticales']);
        $sinAgenda = array_values(array_diff($verticales, ['agenda']));
        $this->assertSame(count($sinAgenda) === 1 ? config('erp.modulos.' . $sinAgenda[0] . '.label') : 'Mi rubro', $e['label']);
        $this->assertNotEmpty($e['secciones']);
        // La sub-navegación del vertical cuelga de esa entrada (los mostradores van a pantalla completa, así que se prueba otra sección).
        $sec = collect($e['secciones'])->first(fn($x) => ! in_array($x['key'], ['retail', 'minimarket'], true));
        if ($sec) $this->assertSame('rubro', $this->props($this->dueno, $sec['ruta'])['subnav']['key']);
    }

    public function test_ctrl_k_y_bigcito_saben_de_los_cambios(): void
    {
        $paleta = file_get_contents(resource_path('js/Components/Paleta.vue'));
        $this->assertStringContainsString('i.modulo === modulo', $paleta, 'La paleta mira el módulo, no la clave del menú');
        $this->assertStringContainsString('buscarMostrador', $paleta);
        $this->assertStringContainsString('Qué reponer', \App\Mcp\Recursos::instrucciones($this->dueno));
        $this->assertStringContainsString('Mi rubro', file_get_contents(base_path('database/migrations/2027_01_06_000001_fase83_novedad_canales.php')));
    }
}
