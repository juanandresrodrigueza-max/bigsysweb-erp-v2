<?php

namespace Tests\Feature;

use App\Models\Acopio;
use App\Models\Contact;
use App\Models\Deposito;
use App\Models\OrdenEntrega;
use App\Models\OrdenEntregaItem;
use App\Models\TransferenciaStock;
use App\Services\Ventas\RepartoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 37: armar el viaje (parciales, acopios, paradas a mano, planilla de carga), el recorrido, el chofer y el cliente; los pases entre sucursales.
class RepartoPasesTest extends ErpTestCase
{
    public function test_armar_viaje_con_parciales_acopio_parada_y_planilla_de_carga(): void
    {
        $cli = $this->cliente(['name' => 'Obra Norte', 'address' => 'Ruta 9 km 3']);
        $cem = $this->articulo(['name' => 'Cemento', 'unit' => 'bolsa', 'stock_inicial' => 100]); $are = $this->articulo(['name' => 'Arena', 'unit' => 'm3', 'stock_inicial' => 50]);
        $f = $this->factura($cli, [['product_id' => $cem->id, 'descripcion' => 'Cemento', 'cantidad' => 40, 'precio_unit' => 8000, 'alicuota_iva' => 21], ['product_id' => $are->id, 'descripcion' => 'Arena', 'cantidad' => 6, 'precio_unit' => 30000, 'alicuota_iva' => 21]], ['entrega_pendiente' => true]);
        $fac2 = $this->factura($this->cliente(['name' => 'Acopiador', 'cuit' => null]), [['product_id' => $cem->id, 'descripcion' => 'Cemento', 'cantidad' => 20, 'precio_unit' => 8000, 'alicuota_iva' => 21]], ['es_acopio' => true]);
        $ac = Acopio::where('comprobante_id', $fac2->id)->first();
        $p = collect(app(RepartoService::class)->pendientes())->keyBy('cliente');
        $this->assertSame('comprobante', $p['Obra Norte']['documentos'][0]['tipo']); $this->assertEquals(40, $p['Obra Norte']['documentos'][0]['items'][0]['pendiente']);
        $this->assertSame('acopio', $p['Acopiador']['documentos'][0]['tipo']);
        $iCem = $f->items->firstWhere('product_id', $cem->id);
        $this->post('/comprobantes/entregas/armar', ['fecha' => today()->toDateString(), 'repartidor' => 'Carlos', 'paradas' => [
            ['tipo' => 'comprobante', 'id' => $f->id, 'cantidades' => [$iCem->id => 15]],
            ['tipo' => 'acopio', 'id' => $ac->id, 'cantidades' => [$ac->items->first()->id => 5]],
            ['tipo' => 'parada', 'nombre' => 'Retirar cheques en el estudio', 'direccion' => 'San Martín 100'],
        ]])->assertSessionHasNoErrors();
        $oe = OrdenEntrega::latest('id')->first(); $this->assertCount(3, $oe->items); $this->assertNotNull($oe->token_chofer);
        $carga = collect(app(RepartoService::class)->planillaCarga($oe))->keyBy('descripcion');
        $this->assertEquals(20, $carga['Cemento']['cantidad']); $this->assertSame(2, $carga['Cemento']['paradas']); $this->assertArrayNotHasKey('Arena', $carga->all());
        // Pedir más de lo pendiente no deja
        $this->post('/comprobantes/entregas/armar', ['fecha' => today()->toDateString(), 'paradas' => [['tipo' => 'comprobante', 'id' => $f->id, 'cantidades' => [$iCem->id => 99]]]])->assertSessionHasErrors('paradas');
        $this->get("/comprobantes/entregas/{$oe->id}")->assertOk()->assertInertia(fn($pg) => $pg->component('Comprobantes/EntregaVer', false)->has('carga', 1)->where('link_chofer', url("/reparto/{$oe->token_chofer}")));
    }

    public function test_recorrido_mas_corto_y_maps_de_a_cuatro(): void
    {
        \App\Models\BusinessLocation::find($this->sucursal->id)->update(['lat' => -31.40, 'lng' => -64.18]);
        $mk = fn($n, $lat) => $this->cliente(['name' => $n, 'cuit' => null, 'lat' => $lat, 'lng' => -64.18]);
        $lejos = $mk('Lejos', -31.60); $cerca = $mk('Cerca', -31.42); $medio = $mk('Medio', -31.50);
        $paradas = []; foreach ([$lejos, $cerca, $medio] as $c) $paradas[] = ['tipo' => 'parada', 'contact_id' => $c->id];
        $paradas[] = ['tipo' => 'parada', 'nombre' => 'Sin ubicación'];
        foreach (range(1, 2) as $k) $paradas[] = ['tipo' => 'parada', 'contact_id' => $mk("Extra {$k}", -31.70 - $k / 100)->id];
        $this->post('/comprobantes/entregas/armar', ['fecha' => today()->toDateString(), 'paradas' => $paradas]);
        $oe = OrdenEntrega::latest('id')->first();
        $this->post("/comprobantes/entregas/{$oe->id}/ordenar")->assertSessionHas('success');
        $this->assertSame(['Cerca', 'Medio', 'Lejos', 'Extra 1', 'Extra 2', 'Sin ubicación'], $oe->items()->orderBy('orden')->get()->map(fn($i) => $i->nombre)->all());
        $maps = app(RepartoService::class)->maps($oe->fresh());
        $this->assertCount(2, $maps); $this->assertStringContainsString('origin=-31.4', $maps[0]); $this->assertStringContainsString('waypoints=', $maps[0]);
        $ids = $oe->items()->orderBy('orden')->pluck('id')->reverse()->values()->all();
        $this->post("/comprobantes/entregas/{$oe->id}/reordenar", ['ids' => $ids]);
        $this->assertSame('Sin ubicación', $oe->items()->orderBy('orden')->first()->nombre);
    }

    public function test_chofer_sale_entrega_con_foto_aprende_la_ubicacion_y_el_cliente_sigue_el_viaje(): void
    {
        Storage::fake();
        $cli = $this->cliente(['name' => 'Ferretería Sur', 'cuit' => null]);
        $cem = $this->articulo(['name' => 'Cemento', 'stock_inicial' => 100]);
        $f = $this->factura($cli, [['product_id' => $cem->id, 'descripcion' => 'Cemento', 'cantidad' => 10, 'precio_unit' => 8000, 'alicuota_iva' => 21]], ['entrega_pendiente' => true]);
        $f2 = $this->factura($this->cliente(['name' => 'Cerrado', 'cuit' => null]), [['product_id' => $cem->id, 'descripcion' => 'Cemento', 'cantidad' => 2, 'precio_unit' => 8000, 'alicuota_iva' => 21]], ['entrega_pendiente' => true]);
        $this->post('/comprobantes/entregas/armar', ['fecha' => today()->toDateString(), 'repartidor' => 'Carlos', 'paradas' => [['tipo' => 'comprobante', 'id' => $f2->id], ['tipo' => 'comprobante', 'id' => $f->id, 'cantidades' => [$f->items->first()->id => 6]]]]);
        $oe = OrdenEntrega::latest('id')->first(); [$p1, $p2] = $oe->items()->orderBy('orden')->get()->all();
        $stock = (float) $cem->fresh()->stock;
        auth()->logout();
        $this->get("/reparto/{$oe->token_chofer}")->assertOk()->assertSee('Salí');
        $this->post("/reparto/{$oe->token_chofer}/salir"); $this->assertSame('en_curso', $oe->fresh()->estado);
        $this->postJson("/reparto/{$oe->token_chofer}/posicion", ['lat' => -31.41, 'lng' => -64.19])->assertOk();
        // El cliente ve que viene en camino, a cuántos km y cuántas antes
        $p2->contact->update(['lat' => -31.45, 'lng' => -64.19]); $p2->update(['lat' => -31.45, 'lng' => -64.19]);
        $s = $this->getJson("/seguimiento/{$p2->token}")->assertOk()->json();
        $this->assertSame('en_camino', $s['estado']); $this->assertSame(1, $s['paradas_antes']); $this->assertEqualsWithDelta(4.45, $s['km'], 0.1);
        $this->get("/seguimiento/{$p2->token}")->assertOk()->assertSee('en camino');
        // No se pudo: vuelve a pendientes
        $this->post("/reparto/{$oe->token_chofer}/parada/{$p1->id}/no", ['motivo' => 'Cerrado']);
        $this->assertSame('no_entregado', $p1->fresh()->estado);
        // Entregué con nombre y foto: remito por las 6, el resto sigue pendiente; aprende la ubicación
        $this->post("/reparto/{$oe->token_chofer}/parada/{$p2->id}/entregue", ['recibio' => 'Juan', 'foto' => UploadedFile::fake()->image('e.jpg'), 'lat' => -31.4502, 'lng' => -64.1902])->assertSessionHas('ok');
        $p2->refresh(); $this->assertSame('entregado', $p2->estado); $this->assertSame('Juan', $p2->recibio); $this->assertNotNull($p2->foto_path);
        $this->assertEqualsWithDelta($stock - 6, (float) $cem->fresh()->stock, 0.001); $this->assertEqualsWithDelta(4, $f->fresh()->pendienteEntrega(), 0.001);
        $this->assertEqualsWithDelta(-31.4501, Contact::find($cli->id)->lat, 0.0002, 'promedia la ubicación aprendida');
        $this->assertSame('entregada', $oe->fresh()->estado);
        $this->assertSame('entregado', $this->getJson("/seguimiento/{$p2->token}")->json('estado'));
        // Lo que no se pudo y lo que falta de la otra vuelven a pendientes
        $this->actingAs($this->dueno);
        $pend = collect(app(RepartoService::class)->pendientes())->keyBy('cliente');
        $this->assertArrayHasKey('Cerrado', $pend->all()); $this->assertEquals(4, $pend['Ferretería Sur']['documentos'][0]['items'][0]['pendiente']);
    }

    public function test_pase_entre_sucursales_se_envia_viaja_se_recibe_contando_y_muestra_diferencias(): void
    {
        $norte = Deposito::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => 'Sucursal Norte', 'activo' => true]);
        $p = $this->articulo(['name' => 'Tornillo', 'barcode' => '7790000000011', 'stock_inicial' => 100]); $q = $this->articulo(['name' => 'Tuerca', 'stock_inicial' => 50]);
        $l = $this->postJson('/stock/pases/leer', ['codigo' => '3*7790000000011'])->assertOk()->json(); $this->assertTrue($l['ok']); $this->assertEquals(3, $l['cantidad']); $this->assertSame($p->id, $l['product_id']);
        $this->assertFalse($this->postJson('/stock/pases/leer', ['codigo' => 'NOEXISTE'])->json('ok'));
        $this->post('/stock/pases', ['origen_id' => $this->deposito->id, 'destino_id' => $norte->id, 'items' => [['product_id' => $p->id, 'cantidad' => 3], ['product_id' => $p->id, 'cantidad' => 7], ['product_id' => $q->id, 'cantidad' => 5]]])->assertSessionHasNoErrors();
        $t = TransferenciaStock::latest('id')->first(); $this->assertSame('en_viaje', $t->estado); $this->assertCount(2, $t->items);
        $this->assertEquals(90, $p->fresh()->stockEn($this->deposito->id)); $this->assertEquals(0, $p->fresh()->stockEn($norte->id), 'en viaje no está en ningún lado');
        $this->post('/stock/pases', ['origen_id' => $this->deposito->id, 'destino_id' => $norte->id, 'items' => [['product_id' => $q->id, 'cantidad' => 999]]])->assertSessionHasErrors('items');
        // Recibir contando: llegaron 9 tornillos de 10
        [$it1, $it2] = $t->items->sortBy('id')->values()->all();
        $this->post("/stock/pases/{$t->id}/recibir", ['conteos' => [$it1->id => 9, $it2->id => 5]])->assertSessionHas('error');
        $t->refresh(); $this->assertSame('con_diferencias', $t->estado); $this->assertEquals(9, $p->fresh()->stockEn($norte->id));
        $r = app(\App\Services\Stock\PasesService::class)->resumen($t); $this->assertEquals(-1, collect($r['items'])->firstWhere('articulo', 'Tornillo')['diferencia']);
        $this->get('/stock/pases')->assertOk()->assertInertia(fn($pg) => $pg->component('Stock/Pases', false)->where('pases.0.estado', 'con_diferencias'));
        $this->get("/pase/{$t->token}")->assertOk()->assertHeader('content-type', 'application/pdf');
        // Lo que faltó vuelve al origen
        $this->post("/stock/pases/{$t->id}/devolver")->assertSessionHas('success');
        $this->assertEquals(91, $p->fresh()->stockEn($this->deposito->id)); $this->assertSame('recibida', $t->fresh()->estado);
        $this->post("/stock/pases/{$t->id}/recibir", ['conteos' => [$it1->id => 1]])->assertStatus(422);
    }
}
