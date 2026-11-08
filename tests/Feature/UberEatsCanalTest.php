<?php

namespace Tests\Feature;

use App\Services\Canales\CanalesService;
use Tests\ErpTestCase;

// Uber Eats como canal de pedidos, igual que PedidosYa y Rappi: importes en centavos, comensal y carrito.
class UberEatsCanalTest extends ErpTestCase
{
    public function test_normaliza_un_pedido_de_uber_eats(): void
    {
        $raw = ['id' => 'f1a2', 'display_id' => 'A7C3', 'type' => 'DELIVERY_BY_UBER', 'eater' => ['first_name' => 'Ana', 'last_name' => 'P.', 'phone' => '+54 351 555'],
            'cart' => ['items' => [['title' => 'Hamburguesa', 'quantity' => 2, 'external_data' => 'HAM1', 'price' => ['unit_price' => ['amount' => 850000]], 'special_instructions' => 'sin cebolla']]],
            'payment' => ['charges' => ['total' => ['amount' => 1700000]]]];
        $p = app(CanalesService::class)->normalizar('ubereats', $raw);
        $this->assertSame('A7C3', $p['external_id']);
        $this->assertSame('Ana P.', $p['cliente']['nombre']);
        $this->assertSame('HAM1', $p['items'][0]['sku']);
        $this->assertEqualsWithDelta(8500, $p['items'][0]['precio_unit'], 0.01);
        $this->assertEqualsWithDelta(17000, $p['total'], 0.01);
        $this->assertSame('envio', $p['entrega']);
        $this->assertArrayHasKey('ubereats', \App\Models\Canal::TIPOS);
    }
}
