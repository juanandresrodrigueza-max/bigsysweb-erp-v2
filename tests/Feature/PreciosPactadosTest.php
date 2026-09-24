<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PrecioPactado;
use App\Models\Rubro;
use App\Services\Ventas\CondicionesClienteService;
use Tests\ErpTestCase;

// Fase 25.1: precio pactado por cliente y artículo, descuento por cliente y rubro, último precio facturado.
class PreciosPactadosTest extends ErpTestCase
{
    public function test_orden_pactado_rubro_y_lista_con_herencia_de_subrubros(): void
    {
        $hierros = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Hierros']);
        $aletado = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Aletado', 'parent_id' => $hierros->id]);
        $liso = Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Liso', 'parent_id' => $hierros->id]);
        $cli = $this->cliente(['lista_precios' => 2, 'descuento' => 3]);
        $h8 = $this->articulo(['price' => 1000, 'prices' => ['2' => 900], 'rubro_id' => $aletado->id]);
        $h10 = $this->articulo(['price' => 1500, 'prices' => ['2' => 1400], 'rubro_id' => $liso->id]);
        $otro = $this->articulo(['price' => 500, 'prices' => ['2' => 450]]);

        $this->post("/clientes/{$cli->id}/condiciones", ['rubro_id' => $hierros->id, 'descuento' => 10])->assertSessionHasNoErrors();
        $this->post("/clientes/{$cli->id}/condiciones", ['rubro_id' => $liso->id, 'descuento' => 15])->assertSessionHasNoErrors();
        $this->post("/clientes/{$cli->id}/condiciones", ['product_id' => $h8->id, 'precio' => 800])->assertSessionHasNoErrors();

        $svc = app(CondicionesClienteService::class);
        // Artículo con precio pactado: es el precio final, sin el descuento del rubro ni el general.
        $this->assertSame(['precio' => 800.0, 'descuento' => 0.0, 'origen' => 'pactado'], $svc->para($cli, $h8));
        // Un descuento pactado para el artículo (sin precio fijo) pisa al del rubro.
        $this->post("/clientes/{$cli->id}/condiciones", ['product_id' => $otro->id, 'descuento' => 20])->assertSessionHasNoErrors();
        $this->assertSame(['precio' => 450.0, 'descuento' => 20.0, 'origen' => 'pactado'], $svc->para($cli, $otro));
        \App\Models\PrecioPactado::where('product_id', $otro->id)->delete();
        // Subrubro con descuento propio pisa al del padre; precio de la lista del cliente.
        $this->assertSame(['precio' => 1400.0, 'descuento' => 15.0, 'origen' => 'rubro'], $svc->para($cli, $h10));
        // Sin condiciones: lista 2 y el descuento general del cliente.
        $this->assertSame(['precio' => 450.0, 'descuento' => 3.0, 'origen' => 'lista'], $svc->para($cli, $otro));

        // Lo que recibe el formulario.
        $this->getJson("/comprobantes/condiciones-de/{$cli->id}")->assertOk()
            ->assertJsonPath('lista', 2)->assertJsonPath("articulos.{$h8->id}.precio", 800)
            ->assertJsonPath("rubros.{$aletado->id}", 10)->assertJsonPath("rubros.{$liso->id}", 15);

        // Una condición vencida deja de aplicar.
        PrecioPactado::where('product_id', $h8->id)->update(['vigente_hasta' => today()->subDay()]);
        $this->assertSame('rubro', $svc->para($cli, $h8)['origen']);

        // La ficha lista las condiciones y se pueden borrar.
        $r = $this->getJson("/clientes/{$cli->id}/condiciones")->assertOk()->assertJsonCount(3, 'condiciones');
        $this->delete("/clientes/{$cli->id}/condiciones/" . $r->json('condiciones.0.id'))->assertSessionHasNoErrors();
        $this->assertSame(2, PrecioPactado::where('contact_id', $cli->id)->count());
    }

    public function test_facturar_al_precio_pactado_no_se_audita_como_precio_modificado(): void
    {
        $cli = $this->cliente();
        $p = $this->articulo(['price' => 1000, 'stock_inicial' => 10]);
        $this->post("/clientes/{$cli->id}/condiciones", ['product_id' => $p->id, 'precio' => 850])->assertSessionHasNoErrors();
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 850]], ['tipo' => 'FA']);
        $this->assertSame(0, AuditLog::where('accion', 'precio_modificado')->count(), 'El precio pactado no es una novedad');
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 700]], ['tipo' => 'FA']);
        $this->assertSame(1, AuditLog::where('accion', 'precio_modificado')->count(), 'Por debajo del pactado sí');
    }

    public function test_recordar_el_ultimo_precio_facturado_sin_pisar_lo_pactado_a_mano(): void
    {
        $cli = $this->cliente(['recordar_precio' => true]);
        $a = $this->articulo(['price' => 1000, 'stock_inicial' => 10]);
        $b = $this->articulo(['price' => 2000, 'stock_inicial' => 10]);
        $this->post("/clientes/{$cli->id}/condiciones", ['product_id' => $b->id, 'precio' => 1800])->assertSessionHasNoErrors();
        $this->factura($cli, [['product_id' => $a->id, 'cantidad' => 1, 'precio_unit' => 950, 'descuento' => 2], ['product_id' => $b->id, 'cantidad' => 1, 'precio_unit' => 1700]], ['tipo' => 'FA']);

        $svc = app(CondicionesClienteService::class);
        $this->assertSame(['precio' => 950.0, 'descuento' => 2.0, 'origen' => 'ultimo'], $svc->para($cli->fresh(), $a));
        $this->assertSame(1800.0, $svc->para($cli->fresh(), $b)['precio'], 'El pactado a mano se mantiene');

        // Un presupuesto no cambia el último precio; un cliente sin la opción tampoco guarda nada.
        $svc2 = app(\App\Services\Comprobantes\ComprobanteService::class);
        $svc2->emitir($svc2->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'PRE', 'items' => [['product_id' => $a->id, 'cantidad' => 1, 'precio_unit' => 10]]]));
        $this->assertSame(950.0, $svc->para($cli->fresh(), $a)['precio']);
        $otro = $this->cliente(['name' => 'Sin recordar']);
        $this->factura($otro, [['product_id' => $a->id, 'cantidad' => 1, 'precio_unit' => 900]], ['tipo' => 'FA']);
        $this->assertSame(0, PrecioPactado::where('contact_id', $otro->id)->count());

        // La opción se prende desde la ficha.
        $this->post("/clientes/{$otro->id}", ['name' => 'Sin recordar', 'condicion_iva' => 'Responsable Inscripto', 'cuit' => '20-12345678-6', 'recordar_precio' => true])->assertSessionHasNoErrors();
        $this->assertTrue($otro->fresh()->recordar_precio);
    }

    public function test_validaciones_y_permisos(): void
    {
        $cli = $this->cliente();
        $p = $this->articulo();
        $this->post("/clientes/{$cli->id}/condiciones", [])->assertSessionHasErrors(['product_id', 'rubro_id']);
        $this->post("/clientes/{$cli->id}/condiciones", ['product_id' => $p->id])->assertStatus(422);
        $this->post("/clientes/{$cli->id}/condiciones", ['product_id' => $p->id, 'descuento' => 150])->assertSessionHasErrors('descuento');
        // El cajero factura con las condiciones pero no las edita.
        $this->actingAs($this->usuarioConRol('cajero'));
        $this->getJson("/comprobantes/condiciones-de/{$cli->id}")->assertOk();
        $this->post("/clientes/{$cli->id}/condiciones", ['product_id' => $p->id, 'precio' => 1])->assertForbidden();
    }
}
