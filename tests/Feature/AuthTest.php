<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_log_in_with_id_number(): void
    {
        User::factory()->create(['id_number' => '2024-00145', 'email' => 'kyla@cpc.edu.ph']);

        $this->postJson('/api/auth/login', ['login' => '2024-00145', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'id_number', 'name'], 'workspaces', 'campus'])
            ->assertJsonPath('workspaces', ['student']);
    }

    public function test_student_can_log_in_with_email_in_any_case(): void
    {
        User::factory()->create(['email' => 'kyla@cpc.edu.ph']);

        $this->postJson('/api/auth/login', ['login' => 'KYLA@cpc.edu.ph', 'password' => 'password123'])
            ->assertOk();
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['id_number' => '2024-00145']);

        $this->postJson('/api/auth/login', ['login' => '2024-00145', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_token_gives_access_and_logout_revokes_it(): void
    {
        User::factory()->create(['id_number' => '2024-00145']);
        $token = $this->postJson('/api/auth/login', ['login' => '2024-00145', 'password' => 'password123'])->json('token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_adviser_cannot_open_student_pages(): void
    {
        $adviser = User::factory()->adviser()->create();

        $this->actingAs($adviser, 'sanctum')->getJson('/api/dashboard')->assertForbidden();
        $this->actingAs($adviser, 'sanctum')->getJson('/api/adviser/overview')->assertOk();
    }
}
