<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\Lote;
use App\Services\Stock\LotesService;
use App\Services\Stock\StockService;
use Tests\ErpTestCase;

class StockTest extends ErpTestCase
{
    public function test_entrada_salida_y_ajuste(): void
    {
        $p = $this->articulo();
        $svc = app(StockService::class);
        $svc->entrada($p, 10, 'compra', null, $this->deposito, 100);
        $svc->salida($p, 3, 'venta', null, $this->deposito);
        $this->assertEqualsWithDelta(7, (float) $p->fresh()->stock, 0.001);
        $svc->ajustar($p, $this->deposito, 5, 'inventario');
        $this->assertEqualsWithDelta(5, (float) $p->fresh()->stock, 0.001);
    }

    public function test_transferencia_entre_depositos(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $d2 = Deposito::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => 'Galpón', 'activo' => true]);
        app(StockService::class)->transferir($this->deposito, $d2, [['product_id' => $p->id, 'cantidad' => 4]], today()->toDateString());
        $this->assertEqualsWithDelta(6, $p->fresh()->stockEn($this->deposito->id), 0.001);
        $this->assertEqualsWithDelta(4, $p->fresh()->stockEn($d2->id), 0.001);
        $this->assertEqualsWithDelta(10, (float) $p->fresh()->stock, 0.001, 'El total no cambia');
    }

    public function test_lotes_salen_por_vencimiento_mas_proximo_y_los_vencidos_al_final(): void
    {
        $p = $this->articulo(['perecedero' => true]);
        $svc = app(StockService::class);
        $svc->entrada($p, 10, 'lote B', null, $this->deposito, 100, null, ['lote' => 'B', 'vencimiento' => today()->addMonths(6)->toDateString()]);
        $svc->entrada($p, 10, 'lote A', null, $this->deposito, 100, null, ['lote' => 'A', 'vencimiento' => today()->addMonth()->toDateString()]);
        $svc->entrada($p, 5, 'lote vencido', null, $this->deposito, 100, null, ['lote' => 'Z', 'vencimiento' => today()->subDay()->toDateString()]);
        $salidas = app(LotesService::class)->salida($p, $this->deposito, 12);
        
        $this->assertEqualsWithDelta(8, Lote::where('product_id', $p->id)->where('lote', 'B')->value('cantidad'), 0.001, 'Del lote B (vence más tarde) salen solo los 2 que faltaban');
        $this->assertEqualsWithDelta(0, Lote::where('product_id', $p->id)->where('lote', 'A')->value('cantidad'), 0.001, 'Primero sale el que vence antes');
        $this->assertEqualsWithDelta(5, Lote::where('product_id', $p->id)->where('lote', 'Z')->value('cantidad'), 0.001, 'El vencido queda para el final');
    }

    public function test_inventario_cierra_con_ajustes_por_diferencia(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        app(StockService::class)->cerrarInventario($this->deposito, [$p->id => 8], today()->toDateString());
        $this->assertEqualsWithDelta(8, (float) $p->fresh()->stock, 0.001);
    }
}
