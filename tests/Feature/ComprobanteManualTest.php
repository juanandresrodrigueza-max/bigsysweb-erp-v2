<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Services\Comprobantes\ComprobanteService;
use Tests\ErpTestCase;

// Fase 25.2: comprobante manual de talonario.
class ComprobanteManualTest extends ErpTestCase
{
    private function manual(array $extra = [])
    {
        $cli = $this->cliente(['name' => 'López SRL']);
        $p = $this->articulo(['stock_inicial' => 10, 'price' => 1000]);
        return $this->post('/comprobantes', array_merge([
            'tipo' => 'FX', 'contact_id' => $cli->id, 'fecha' => today()->subDays(2)->toDateString(), 'condicion' => 'cta_cte', 'emitir' => true,
            'manual' => true, 'pv_manual' => 2, 'numero_manual' => 1523, 'cai' => '51234567890123', 'cai_vto' => today()->addMonth()->toDateString(),
            'items' => [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 1000, 'alicuota_iva' => 21]],
        ], $extra));
    }

    public function test_se_carga_con_el_numero_del_papel_sin_arca_y_entra_en_iva_cuenta_corriente_y_stock(): void
    {
        $this->manual()->assertSessionHasNoErrors()->assertSessionHas('success', fn($m) => str_contains($m, 'manual de talonario'));
        $c = Comprobante::where('manual', true)->firstOrFail();
        $this->assertSame('FA', $c->tipo);
        $this->assertSame([2, 1523, 'manual', null], [(int) $c->punto_venta, (int) $c->numero, $c->afip_estado, $c->cae]);
        $this->assertSame('0002-00001523', $c->numeroFormateado());
        $this->assertFalse($c->sin_arca, 'No es interno: va a los libros de IVA');
        $this->assertSame(1, Comprobante::fiscales()->where('id', $c->id)->count());
        $this->assertEquals(2420, (float) $c->contact->fresh()->balance);
        $this->assertEquals(8, (float) $c->items->first()->product->fresh()->stock);
        $this->assertAsientosBalancean();
        // La numeración electrónica no se tocó: la próxima factura electrónica sigue en el punto de venta normal.
        $e = $this->factura($c->contact, [['product_id' => $c->items->first()->product_id, 'cantidad' => 1, 'precio_unit' => 100]], ['tipo' => 'FA']);
        $this->assertNotSame(2, (int) $e->punto_venta);
        // Se ve e imprime con el CAI.
        $this->get("/comprobantes/{$c->id}")->assertInertia(fn($a) => $a->where('c.manual', true)->where('c.cai', '51234567890123'));
        $this->get("/comprobantes/{$c->id}/imprimir")->assertOk()->assertSee('Comprobante manual de talonario')->assertSee('51234567890123');
    }

    public function test_validaciones_numero_repetido_cai_y_permiso(): void
    {
        $this->manual(['numero_manual' => null])->assertSessionHasErrors('numero_manual');
        $this->manual(['cai' => '123'])->assertSessionHasErrors('cai');
        $this->manual(['cai_vto' => today()->subMonth()->toDateString()])->assertSessionHasErrors('cai_vto');
        $this->manual()->assertSessionHasNoErrors();
        $this->manual()->assertSessionHasErrors('numero_manual');
        $this->assertSame(1, Comprobante::where('manual', true)->where('estado', 'emitido')->count());
        // Un presupuesto no puede ser manual.
        $this->manual(['tipo' => 'PRE', 'numero_manual' => 9])->assertStatus(422);
        // Un vendedor (sin permiso de anular) no carga manuales.
        $this->actingAs($this->usuarioConRol('vendedor'));
        $this->manual(['numero_manual' => 77])->assertForbidden();
    }

    public function test_un_manual_cargado_por_error_se_puede_anular(): void
    {
        $this->manual()->assertSessionHasNoErrors();
        $c = Comprobante::where('manual', true)->firstOrFail();
        $this->post("/comprobantes/{$c->id}/anular", ['motivo' => 'cargado dos veces'])->assertSessionHasNoErrors();
        $this->assertSame('anulado', $c->fresh()->estado);
        $this->assertEquals(0, (float) $c->contact->fresh()->balance);
    }
}
