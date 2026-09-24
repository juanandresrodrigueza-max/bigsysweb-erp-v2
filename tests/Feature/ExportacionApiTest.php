<?php

namespace Tests\Feature;

use App\Models\Contact;
use Laravel\Sanctum\Sanctum;
use Tests\ErpTestCase;

class ExportacionApiTest extends ErpTestCase
{
    public function test_catalogo_y_tabla_paginada_solo_de_la_empresa_del_token(): void
    {
        [$otra] = $this->crearEmpresa('Otra', 'otra');
        Contact::withoutGlobalScopes()->create(['business_id' => $otra->id, 'type' => 'customer', 'name' => 'Ajeno SA', 'condicion_iva' => 'Responsable Inscripto', 'is_active' => true, 'lista_precios' => 1]);
        $this->cliente(['name' => 'Cliente Uno']); $this->cliente(['name' => 'Cliente Dos', 'cuit' => '20-11111111-1']);
        $p = $this->articulo(['stock_inicial' => 5]);
        $this->factura(Contact::where('name', 'Cliente Uno')->first(), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]]);
        Sanctum::actingAs($this->dueno);

        $cat = $this->getJson('/api/exportar')->assertOk()->json();
        $this->assertContains('clientes', array_column($cat['tablas'], 'tabla'));
        $this->assertContains('cuit', collect($cat['tablas'])->firstWhere('tabla', 'clientes')['columnas']);
        $this->assertNotContains('portal_token', collect($cat['tablas'])->firstWhere('tabla', 'clientes')['columnas'], 'Nunca salen secretos');

        $r = $this->getJson('/api/exportar/clientes?per_page=2')->assertOk()->json();
        $this->assertSame(3, $r['total'], 'Consumidor Final + 2 clientes; el de la otra empresa no');
        $this->assertCount(2, $r['datos']);
        $this->assertNotNull($r['siguiente']);
        $this->assertNotContains('Ajeno SA', array_column($r['datos'], 'name'));

        $items = $this->getJson('/api/exportar/comprobante_items')->assertOk()->json();
        $this->assertSame(1, $items['total']);
        $this->assertEqualsWithDelta(1000, $items['datos'][0]['precio_unit'], 0.01);

        $this->getJson('/api/exportar/comprobantes_venta?desde=' . today()->addDay()->toDateString())->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/exportar/comprobantes_venta?actualizado_desde=' . now()->subMinute()->toDateTimeString())->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/exportar/inexistente')->assertNotFound();
        $this->getJson('/api/exportar/clientes?desde=ayer')->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['accion' => 'exportar', 'business_id' => $this->empresa->id]);
    }

    public function test_csv_y_permisos(): void
    {
        $this->cliente(['name' => 'Cliente CSV']);
        Sanctum::actingAs($this->dueno);
        $csv = $this->get('/api/exportar/clientes?formato=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('id;business_id;type;name', $csv);
        $this->assertStringContainsString('Cliente CSV', $csv);

        $vendedor = $this->usuarioConRol('vendedor');
        Sanctum::actingAs($vendedor);
        $this->getJson('/api/exportar/clientes')->assertForbidden();
        $this->getJson('/api/docs')->assertOk()->assertSee('Exportar tablas')->assertSee('Mandar tus tablas a otra plataforma');
        $this->actingAs($this->dueno)->get('/ayuda/integrar-otras-plataformas')->assertOk()->assertInertia(fn($a) => $a->component('Ayuda', false)->where('articulo.slug', 'integrar-otras-plataformas'));
        $this->get('/ayuda?q=Power+BI')->assertOk()->assertInertia(fn($a) => $a->component('Ayuda', false)->where('resultados.0.slug', 'integrar-otras-plataformas'));
    }
}
