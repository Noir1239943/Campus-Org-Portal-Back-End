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

    public function test_id_number_login_ignores_case_and_short_ids_work(): void
    {
        User::factory()->adviser()->create(['id_number' => 'Admin', 'email' => 'osa@cpc.edu.ph']);
        User::factory()->create(['id_number' => '0002']);

        foreach (['admin', 'ADMIN', 'Admin', 'osa@cpc.edu.ph'] as $login) {
            $this->postJson('/api/auth/login', ['login' => $login, 'password' => 'password123'])
                ->assertOk()
                ->assertJsonPath('workspaces', ['adviser']);
        }

        $this->postJson('/api/auth/login', ['login' => '0002', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('workspaces', ['student']);
    }

    public function test_cannot_register_an_id_that_differs_only_by_case(): void
    {
        User::factory()->adviser()->create(['id_number' => 'Admin']);

        $this->postJson('/api/auth/register', [
            'id_number' => 'admin',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test.user@example.com',
            'program' => 'BSIT',
            'year_level' => 1,
            'section' => 'A',
            'password' => 'cpc12345',
            'password_confirmation' => 'cpc12345',
        ])->assertStatus(422)->assertJsonValidationErrors('id_number');
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
