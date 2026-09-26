<?php

namespace Tests\Feature;

use App\Models\Despiece;
use App\Models\Product;
use App\Models\StockDeposito;
use App\Support\Balanza;
use Tests\ErpTestCase;

// Fase 27.3: pesables con PLU, etiqueta de balanza en la factura, archivo para la balanza y despiece con costo por corte.
class PesablesDespieceTest extends ErpTestCase
{
    public function test_etiqueta_de_balanza_por_plu_peso_e_importe(): void
    {
        $asado = $this->articulo(['name' => 'Asado', 'unit' => 'kg', 'price' => 10000, 'iva' => 10.5, 'pesable' => true, 'plu' => '123', 'sku' => 'ASA']);
        $this->empresa->update(['pos' => ['balanza_prefijo' => '2', 'balanza_modo' => 'peso', 'balanza_decimales' => 3, 'balanza_digitos_plu' => 5]]);
        // 2 00123 01250 X → 1,250 kg
        $r = Balanza::leer('2001230125000', $this->empresa->fresh());
        $this->assertSame($asado->id, $r['product']->id); $this->assertEqualsWithDelta(1.25, $r['cantidad'], 0.0001);
        $this->getJson('/comprobantes/leer-codigo?codigo=2001230125000')->assertOk()->assertJsonPath('producto.id', $asado->id)->assertJsonPath('cantidad', 1.25)->assertJsonPath('balanza', true);
        $this->getJson('/comprobantes/leer-codigo?codigo=ASA')->assertOk()->assertJsonPath('balanza', false);
        $this->getJson('/comprobantes/leer-codigo?codigo=2999990000000')->assertNotFound();
        // Modo importe en pesos (0 decimales): $ 11.050 con IVA → 1 kg (10.000 + 10,5 %).
        $this->empresa->update(['pos' => ['balanza_prefijo' => '2', 'balanza_modo' => 'importe', 'balanza_decimales' => 0, 'balanza_digitos_plu' => 5]]);
        $r = Balanza::leer('2001231105000', $this->empresa->fresh());
        $this->assertEqualsWithDelta(1, $r['cantidad'], 0.001); $this->assertEqualsWithDelta(11050, $r['importe'], 0.001);
        // Archivo PLU y PLU único.
        $csv = $this->get('/stock/despiece/plu.csv')->assertOk()->getContent();
        $this->assertStringContainsString('123;Asado;11050.00;P', $csv);
        $this->post('/stock/articulos', ['name' => 'Otro', 'tipo' => 'producto', 'unit' => 'kg', 'iva' => 21, 'price' => 1, 'cost' => 1, 'pesable' => true, 'plu' => '123'])->assertSessionHasErrors('plu');
    }

    public function test_despiece_reparte_el_costo_por_valor_de_venta_y_mueve_el_stock(): void
    {
        $media = $this->articulo(['name' => 'Media res', 'unit' => 'kg', 'cost' => 4000, 'stock_inicial' => 300]);
        $asado = $this->articulo(['name' => 'Asado', 'unit' => 'kg', 'price' => 9000]);
        $nalga = $this->articulo(['name' => 'Nalga', 'unit' => 'kg', 'price' => 12000]);
        $picada = $this->articulo(['name' => 'Picada', 'unit' => 'kg', 'price' => 6000]);
        $this->post('/stock/despiece/plantillas', ['nombre' => 'Media res novillo', 'product_id' => $media->id, 'cortes' => [
            ['product_id' => $asado->id, 'rinde' => 20], ['product_id' => $nalga->id, 'rinde' => 15], ['product_id' => $picada->id, 'rinde' => 40]]])->assertSessionHasNoErrors();
        $d = Despiece::first();
        $this->post("/stock/despiece/{$d->id}/ejecutar", ['kg_entrada' => 100, 'cortes' => [$asado->id => 20, $nalga->id => 14, $picada->id => 41]])->assertSessionHasNoErrors();
        // Costo 100 kg × 4000 = 400.000. Valor de venta: 180.000 + 168.000 + 246.000 = 594.000.
        $asadoCk = 400000 * (20 * 9000 / 594000) / 20;
        $this->assertEqualsWithDelta($asadoCk, (float) $asado->fresh()->cost, 0.01, 'Costo por kg del asado según su valor');
        $this->assertEqualsWithDelta(200, (float) StockDeposito::where('product_id', $media->id)->value('cantidad'), 0.001, 'Salieron 100 kg de media res');
        $this->assertEqualsWithDelta(41, (float) $picada->fresh()->stock, 0.001);
        $op = $d->fresh()->load('cortes');
        $r = $this->get('/stock/despiece')->assertOk()->assertInertia(fn($pg) => $pg->component('Stock/Despiece', false)->where('operaciones.0.merma_kg', 25)->where('operaciones.0.costo_total', 400000)->has('operaciones.0.items', 3));
        // Costo total repartido = costo de la media res.
        $total = Product::whereIn('id', [$asado->id, $nalga->id, $picada->id])->get()->sum(fn($p) => (float) $p->cost * ['Asado' => 20, 'Nalga' => 14, 'Picada' => 41][$p->name]);
        $this->assertEqualsWithDelta(400000, $total, 1);
        // No se puede sacar más de lo que entró.
        $this->post("/stock/despiece/{$d->id}/ejecutar", ['kg_entrada' => 10, 'cortes' => [$asado->id => 11]])->assertSessionHasErrors('cortes');
    }
}
