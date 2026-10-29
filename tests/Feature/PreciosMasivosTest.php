<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Rubro;
use Tests\ErpTestCase;

// Fase 34.1 / 34.5 / 34.6: asistente de precios en masa, datos en masa, historial y deshacer, y la herramienta de IA.
class PreciosMasivosTest extends ErpTestCase
{
    private function art(string $nombre, array $extra = []): Product
    {
        return $this->articulo(array_merge(['name' => $nombre, 'sku' => strtoupper(substr(md5($nombre), 0, 8)), 'price' => 1000, 'cost' => 600, 'iva' => 21, 'stock' => 10, 'active' => true, 'moneda' => 'ARS'], $extra));
    }

    public function test_subir_porcentaje_a_un_rubro_con_alertas_todo_o_nada_y_deshacer(): void
    {
        $rubro = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Bebidas test']);
        $hijo = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Gaseosas test', 'parent_id' => $rubro->id]);
        $a = $this->art('Coca test', ['rubro_id' => $rubro->id, 'price' => 1000]);
        $b = $this->art('Sprite test', ['rubro_id' => $hijo->id, 'price' => 2000, 'prices' => ['2' => 1800]]);
        $c = $this->art('Agua test', ['rubro_id' => $rubro->id, 'price' => 500, 'cost' => 700]); // la lista 1 ya está bajo el costo: alerta
        $otro = $this->art('Fideos test', ['price' => 300]);

        $sel = ['rubro_id' => $rubro->id]; $como = ['campo' => '1', 'modo' => 'porcentaje', 'valor' => 15, 'redondeo' => '10'];
        $prev = $this->postJson('/stock/precios/masivo/previsualizar', ['sel' => $sel, 'como' => $como])->assertOk()->json();
        $this->assertSame(3, $prev['n']); // incluye el rubro hijo, no a Fideos
        $filas = collect($prev['filas'])->keyBy('id');
        $this->assertSame(1150.0, (float) $filas[$a->id]['despues']);
        $this->assertSame(2300.0, (float) $filas[$b->id]['despues']);
        $this->assertSame('bajo_costo', $filas[$c->id]['alerta']);
        $this->assertFalse($filas[$c->id]['incluir']);
        $this->assertSame(1, $prev['avisos']['bajo_costo']);

        // Graba destildando el agua
        $this->post('/stock/precios/masivo', ['sel' => $sel, 'como' => $como, 'excluir' => [$c->id]])->assertSessionHas('success');
        $this->assertSame(1150.0, (float) $a->fresh()->price);
        $this->assertSame(2300.0, (float) $b->fresh()->price);
        $this->assertSame(1800.0, (float) $b->fresh()->prices['2']); // la lista 2 no se tocó
        $this->assertSame(500.0, (float) $c->fresh()->price);
        $this->assertSame(300.0, (float) $otro->fresh()->price);
        $log = AuditLog::where('accion', 'precios_masivo')->latest('id')->first();
        $this->assertStringContainsString('Subió 15% la lista 1 en el rubro Bebidas test (2 artículos)', $log->descripcion);
        $this->assertSame(1000.0, (float) $log->antes[$a->id]['price']);

        // Historial y deshacer
        $h = $this->get('/stock/precios')->assertOk()->assertInertia(fn($p) => $p->component('Stock/Precios', false)->where('historial.0.id', $log->id)->where('historial.0.se_puede_deshacer', true));
        $this->post("/stock/precios/deshacer/{$log->id}")->assertSessionHas('success');
        $this->assertSame(1000.0, (float) $a->fresh()->price);
        $this->assertSame(2000.0, (float) $b->fresh()->price);
        $this->post("/stock/precios/deshacer/{$log->id}")->assertStatus(422); // no se puede dos veces

        // Si nada cambia, no graba (todo o nada)
        $this->post('/stock/precios/masivo', ['sel' => ['texto' => 'zzz-no-existe'], 'como' => $como])->assertSessionHasErrors('como');
    }

    public function test_margen_exacto_monto_sobre_lista_y_costo_recalcula(): void
    {
        $a = $this->art('Margen test', ['price' => 1000, 'cost' => 500, 'prices' => ['2' => 900], 'margenes' => ['1' => 100, '2' => 80]]);
        $sinCosto = $this->art('Sin costo test', ['price' => 100, 'cost' => 0]);
        $svc = app(\App\Services\Stock\PreciosMasivosService::class);

        $this->assertSame(['price' => 650.0], $svc->calcular($a, ['campo' => '1', 'modo' => 'margen', 'valor' => 30]));
        $this->assertSame([], $svc->calcular($sinCosto, ['campo' => '1', 'modo' => 'margen', 'valor' => 30]));
        $this->assertSame(['price' => 777.0], $svc->calcular($a, ['campo' => '1', 'modo' => 'exacto', 'valor' => 777]));
        $this->assertSame(['price' => 1250.0], $svc->calcular($a, ['campo' => '1', 'modo' => 'monto', 'valor' => 250]));
        $this->assertSame(['prices' => ['2' => 1100.0]], $svc->calcular($a, ['campo' => '2', 'modo' => 'sobre_lista', 'lista_base' => 1, 'valor' => 10]));
        $this->assertSame(['prices' => ['2' => 900.0]], $svc->calcular($a, ['campo' => '2', 'modo' => 'exacto', 'valor' => 900, 'redondeo' => 100]));
        $this->assertSame(['cost' => 550.0], $svc->calcular($a, ['campo' => 'cost', 'modo' => 'porcentaje', 'valor' => 10]));

        // Subir el costo y recalcular las listas con los márgenes del artículo
        $this->post('/stock/precios/masivo', ['sel' => ['ids' => [$a->id]], 'como' => ['campo' => 'cost', 'modo' => 'porcentaje', 'valor' => 10, 'recalcular_listas' => true]])->assertSessionHas('success');
        $a->refresh();
        $this->assertSame(550.0, (float) $a->cost);
        $this->assertSame(1100.0, (float) $a->price);
        $this->assertSame(990.0, (float) $a->prices['2']);
    }

    public function test_cambiar_datos_en_masa_con_historial_y_deshacer_y_permisos(): void
    {
        $rubro = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Limpieza test']);
        $a = $this->art('Lavandina test', ['marca' => 'Ayudín']); $rubroOriginal = $a->rubro_id;
        $b = $this->art('Detergente test', ['marca' => 'Ayudín', 'ubicacion' => 'góndola 2']);
        $prev = $this->postJson('/stock/precios/datos/previsualizar', ['sel' => ['marca' => 'Ayudín'], 'cambios' => ['rubro_id' => $rubro->id, 'ubicacion' => 'góndola 2', 'iva' => '10.5', 'active' => '']])->assertOk()->json();
        $this->assertSame(2, $prev['n']);
        $fa = collect($prev['filas'])->firstWhere('id', $a->id); $fb = collect($prev['filas'])->firstWhere('id', $b->id);
        $this->assertArrayHasKey('ubicacion', $fa['cambios']); $this->assertArrayNotHasKey('ubicacion', $fb['cambios']); // b ya está en góndola 2
        $this->assertArrayNotHasKey('active', $prev['cambios']); // vacío = no tocar

        $this->post('/stock/precios/datos', ['sel' => ['marca' => 'Ayudín'], 'cambios' => ['rubro_id' => $rubro->id, 'ubicacion' => 'góndola 2', 'iva' => '10.5']])->assertSessionHas('success');
        $this->assertSame($rubro->id, $a->fresh()->rubro_id); $this->assertSame('góndola 2', $a->fresh()->ubicacion); $this->assertSame(10.5, (float) $b->fresh()->iva);
        $log = AuditLog::where('accion', 'datos_masivo')->latest('id')->first();
        $this->assertStringContainsString('Rubro → Limpieza test', $log->descripcion);
        $this->post("/stock/precios/deshacer/{$log->id}")->assertSessionHas('success');
        $this->assertSame($rubroOriginal, $a->fresh()->rubro_id); $this->assertNull($a->fresh()->ubicacion); $this->assertSame(21.0, (float) $a->fresh()->iva);

        // Un vendedor sin editar stock no entra
        $vend = $this->usuarioConRol('vendedor');
        $this->actingAs($vend)->get('/stock/precios')->assertStatus(403);
        $this->actingAs($vend)->post('/stock/precios/masivo', ['sel' => ['ids' => [$a->id]], 'como' => ['campo' => '1', 'modo' => 'porcentaje', 'valor' => 10]])->assertStatus(403);
    }

    public function test_herramienta_ia_subime_el_rubro(): void
    {
        $rubro = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Bebidas ia']);
        $a = $this->art('Coca ia', ['rubro_id' => $rubro->id, 'price' => 1000]);
        $h = app(\App\Mcp\Catalogo::class)->todas()['precios_en_masa'];
        $prop = ($h['proponer'])($this->dueno, ['porcentaje' => 15, 'rubro' => 'bebidas ia']);
        $this->assertStringContainsString('Subir 15% a 1 artículos', $prop['titulo']);
        $this->assertStringContainsString('Coca ia', $prop['detalle']);
        $this->assertArrayHasKey('error', ($h['proponer'])($this->dueno, ['porcentaje' => 15]));
        $r = ($h['confirmar'])($this->dueno, $prop['datos']);
        $this->assertSame(1150.0, (float) $a->fresh()->price);
        $this->assertStringContainsString('Deshacer', $r['texto']);
    }
}
