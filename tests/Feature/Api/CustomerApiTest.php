<?php
namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_list_customers(): void
    {
        Customer::factory(3)->create();

        $this->actingAs($this->user)
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_customer(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/customers', [
                'name'  => 'Juan Perez',
                'email' => 'juan@example.com',
                'phone' => '1234567890',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'juan@example.com');
    }

    public function test_cannot_create_customer_with_duplicate_email(): void
    {
        Customer::factory()->create(['email' => 'juan@example.com']);

        $this->actingAs($this->user)
            ->postJson('/api/customers', [
                'name'  => 'Otro',
                'email' => 'juan@example.com',
            ])
            ->assertUnprocessable();
    }

    public function test_can_show_customer(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->user)
            ->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);
    }

    public function test_can_update_customer(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->user)
            ->putJson("/api/customers/{$customer->id}", ['name' => 'Nuevo Nombre'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nuevo Nombre');
    }

    public function test_can_delete_customer(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->user)
            ->deleteJson("/api/customers/{$customer->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_unauthenticated_user_cannot_access(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
    }
}
