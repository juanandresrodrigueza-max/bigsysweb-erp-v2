<?php
namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $this->postJson('/api/auth/register', [
            'name'                  => 'Juan Test',
            'email'                 => 'juan@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->assertCreated()
        ->assertJsonStructure(['token', 'user']);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])
        ->assertOk()
        ->assertJsonStructure(['token', 'user']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'wrong',
        ])
        ->assertUnprocessable();
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/auth/logout')
            ->assertOk();
    }

    public function test_user_can_get_own_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }
}
