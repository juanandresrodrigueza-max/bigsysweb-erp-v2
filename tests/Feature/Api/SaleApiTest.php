<?php
namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Customer $customer;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->product  = Product::factory()->create(['price' => 100, 'stock' => 50]);
    }

    public function test_can_create_sale(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/sales', [
                'customer_id' => $this->customer->id,
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity'   => 2,
                    'unit_price' => 100,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', '200.00');
    }

    public function test_sale_decrements_stock(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/sales', [
                'customer_id' => $this->customer->id,
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity'   => 5,
                    'unit_price' => 100,
                ]],
            ])
            ->assertCreated();

        $this->assertEquals(45, $this->product->fresh()->stock);
    }

    public function test_sale_creates_stock_movement(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/sales', [
                'customer_id' => $this->customer->id,
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity'   => 3,
                    'unit_price' => 100,
                ]],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('stock_movements', [
            'product_id'   => $this->product->id,
            'type'         => 'out',
            'quantity'     => 3,
            'stock_before' => 50,
            'stock_after'  => 47,
        ]);
    }

    public function test_can_confirm_sale(): void
    {
        $sale = Sale::factory()->create(['customer_id' => $this->customer->id, 'user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->putJson("/api/sales/{$sale->id}", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_cannot_create_sale_without_items(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/sales', [
                'customer_id' => $this->customer->id,
                'items' => [],
            ])
            ->assertUnprocessable();
    }
}
