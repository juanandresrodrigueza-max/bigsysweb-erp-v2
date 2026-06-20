<?php
namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_list_products(): void
    {
        Product::factory(5)->create();

        $this->actingAs($this->user)
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_can_create_product(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/products', [
                'name'  => 'Producto Test',
                'sku'   => 'SKU-001',
                'price' => 100.00,
            ])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'SKU-001');
    }

    public function test_cannot_create_product_with_duplicate_sku(): void
    {
        Product::factory()->create(['sku' => 'SKU-001']);

        $this->actingAs($this->user)
            ->postJson('/api/products', [
                'name'  => 'Otro',
                'sku'   => 'SKU-001',
                'price' => 50,
            ])
            ->assertUnprocessable();
    }

    public function test_can_update_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->user)
            ->putJson("/api/products/{$product->id}", ['price' => 999.99])
            ->assertOk()
            ->assertJsonPath('data.price', '999.99');
    }

    public function test_can_delete_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->user)
            ->deleteJson("/api/products/{$product->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }
}
