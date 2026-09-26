<?php

namespace Tests\Feature;

use App\Models\Lote;
use Tests\ErpTestCase;

// Fase 27.5: centro de etiquetas: precio por kg/litro, desde una compra (series y lotes), lotes elegidos y cambios de precio.
class CentroEtiquetasTest extends ErpTestCase
{
    public function test_origenes_y_precio_por_medida(): void
    {
        $yerba = $this->articulo(['name' => 'Yerba 500 g', 'price' => 3000, 'contenido_neto' => 500, 'contenido_unidad' => 'g', 'precio_actualizado_en' => now()]);
        $this->assertSame(['precio' => 6000.0, 'unidad' => 'kg'], $yerba->precioPorMedida(3000));
        $cel = $this->articulo(['name' => 'Celular', 'seriado' => true, 'precio_actualizado_en' => now()->subMonth()]);
        $leche = $this->articulo(['name' => 'Leche', 'perecedero' => true, 'precio_actualizado_en' => now()->subMonth()]);
        $prov = $this->proveedor();
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'fecha' => today()->toDateString(), 'numero_proveedor' => '0001-00000099', 'registrar' => true, 'condicion' => 'cta_cte', 'items' => [
            ['product_id' => $cel->id, 'descripcion' => 'x', 'cantidad' => 2, 'precio_unit' => 100, 'alicuota_iva' => 21, 'serie' => 'IMEI1,IMEI2'],
            ['product_id' => $leche->id, 'descripcion' => 'x', 'cantidad' => 12, 'precio_unit' => 100, 'alicuota_iva' => 21, 'lote' => 'L55', 'vencimiento' => today()->addDays(20)->toDateString()],
        ]])->assertSessionHasNoErrors();
        $compra = \App\Models\Comprobante::compras()->latest('id')->first();

        $this->get("/stock/etiquetas?compra={$compra->id}")->assertOk()->assertInertia(fn($p) => $p->component('Stock/Etiquetas', false)->where('preseleccion', true)->has('articulos', 3)
            ->where('articulos.0.serie', 'IMEI1')->where('articulos.1.serie', 'IMEI2')->where('articulos.2.lote', 'L55')->where('articulos.2.cantidad', 12)->etc());
        $lote = Lote::where('lote', 'L55')->first();
        $this->get("/stock/etiquetas?lotes={$lote->id}")->assertOk()->assertInertia(fn($p) => $p->has('articulos', 1)->where('articulos.0.lote', 'L55')->etc());
        $this->get('/stock/etiquetas?cambios=7')->assertOk()->assertInertia(fn($p) => $p->has('articulos', 1)->where('articulos.0.nombre', 'Yerba 500 g')->where('articulos.0.medida.precio', 6000)->etc());
        $this->get('/stock/etiquetas?lista=2&q=Yerba')->assertOk()->assertInertia(fn($p) => $p->where('articulos.0.contenido', '500 g')->etc());
        $this->post('/stock/articulos', ['name' => 'X', 'tipo' => 'producto', 'unit' => 'un', 'iva' => 21, 'price' => 1, 'cost' => 1, 'contenido_neto' => 1, 'contenido_unidad' => 'tonelada'])->assertSessionHasErrors('contenido_unidad');
    }
}
