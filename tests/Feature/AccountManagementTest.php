<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private function adviser(): User
    {
        return User::factory()->adviser()->create();
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('students.csv', $contents);
    }

    public function test_adviser_creates_adviser_account_that_can_log_in(): void
    {
        $this->actingAs($this->adviser(), 'sanctum');

        $response = $this->postJson('/api/adviser/accounts', [
            'role' => 'adviser',
            'id_number' => 'FAC-9001',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.santos@cpc.edu.ph',
            'title' => 'Faculty Adviser',
        ])->assertCreated();

        $temporary = $response->json('temporary_password');
        $this->assertMatchesRegularExpression('/^CPC-[A-Z0-9]{4}-\d{4}$/', $temporary);
        $this->assertTrue(User::query()->where('id_number', 'FAC-9001')->value('must_change_password'));

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/auth/login', ['login' => 'fac-9001', 'password' => $temporary])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true)
            ->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/adviser/overview')->assertOk();
    }

    public function test_adviser_creates_student_who_joins_all_student_organizations(): void
    {
        $ssg = Organization::query()->create([
            'name' => 'Supreme Student Government',
            'slug' => 'ssg',
            'acronym' => 'SSG',
            'category' => 'Governance',
            'description' => 'Student government.',
            'requires_all_students' => true,
        ]);
        $other = Organization::query()->create([
            'name' => 'Chess Club',
            'slug' => 'chess',
            'acronym' => 'CHESS',
            'category' => 'Sports',
            'description' => 'Chess.',
        ]);

        $this->actingAs($this->adviser(), 'sanctum')
            ->postJson('/api/adviser/accounts', [
                'role' => 'student',
                'id_number' => '2026-00001',
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'email' => 'ana.reyes@cpc.edu.ph',
                'program' => 'BSIT',
                'year_level' => 1,
                'password' => 'welcome123',
            ])
            ->assertCreated()
            ->assertJsonPath('account.organizations_count', 1)
            ->assertJsonPath('temporary_password', 'welcome123');

        $student = User::query()->where('id_number', '2026-00001')->firstOrFail();

        $this->assertTrue(Membership::query()->where('user_id', $student->id)->where('organization_id', $ssg->id)->where('status', 'active')->exists());
        $this->assertFalse(Membership::query()->where('user_id', $student->id)->where('organization_id', $other->id)->exists());
    }

    public function test_duplicate_id_number_is_rejected_in_any_letter_case(): void
    {
        User::factory()->adviser()->create(['id_number' => 'Admin']);

        $this->actingAs(User::query()->firstOrFail(), 'sanctum')
            ->postJson('/api/adviser/accounts', [
                'role' => 'adviser',
                'id_number' => 'ADMIN',
                'first_name' => 'Dup',
                'last_name' => 'Licate',
                'email' => 'dup@cpc.edu.ph',
                'title' => 'Adviser',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_number');
    }

    public function test_student_cannot_manage_accounts(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/adviser/accounts')
            ->assertForbidden();
    }

    public function test_disabled_user_cannot_log_in_and_old_token_stops_working(): void
    {
        $adviser = $this->adviser();
        $student = User::factory()->create(['id_number' => '2025-00555']);

        $token = $this->postJson('/api/auth/login', ['login' => '2025-00555', 'password' => 'password123'])->json('token');

        $this->app['auth']->forgetGuards();
        $this->actingAs($adviser, 'sanctum')
            ->postJson("/api/adviser/accounts/{$student->id}/disable")
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['login' => '2025-00555', 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'This account is disabled. Please visit the Office of Student Affairs.');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();

        // Enabling lets them back in.
        $this->app['auth']->forgetGuards();
        $this->actingAs($adviser, 'sanctum')->postJson("/api/adviser/accounts/{$student->id}/enable")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['login' => '2025-00555', 'password' => 'password123'])->assertOk();
    }

    public function test_cannot_disable_yourself_or_the_last_active_adviser(): void
    {
        $adviser = $this->adviser();

        $this->actingAs($adviser, 'sanctum')
            ->postJson("/api/adviser/accounts/{$adviser->id}/disable")
            ->assertStatus(422)
            ->assertJsonValidationErrors('account');

        $this->assertTrue($adviser->fresh()->is_active);
    }

    public function test_reset_password_forces_a_change_and_changing_it_clears_the_flag(): void
    {
        $adviser = $this->adviser();
        $student = User::factory()->create(['id_number' => '2025-00777']);

        $temporary = $this->actingAs($adviser, 'sanctum')
            ->postJson("/api/adviser/accounts/{$student->id}/reset-password")
            ->assertOk()
            ->json('temporary_password');

        $this->assertTrue($student->fresh()->must_change_password);

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/auth/login', ['login' => '2025-00777', 'password' => $temporary])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => $temporary,
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->assertFalse($student->fresh()->must_change_password);
    }

    public function test_adviser_cannot_change_their_own_role(): void
    {
        $adviser = $this->adviser();

        $this->actingAs($adviser, 'sanctum')
            ->putJson("/api/adviser/accounts/{$adviser->id}", [
                'role' => 'student',
                'id_number' => $adviser->id_number,
                'first_name' => $adviser->first_name,
                'last_name' => $adviser->last_name,
                'email' => $adviser->email,
                'program' => 'BSIT',
                'year_level' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_csv_import_creates_accounts_and_reports_skipped_rows(): void
    {
        User::factory()->create(['id_number' => '2026-00002', 'email' => 'existing@cpc.edu.ph']);

        $csv = implode("\n", [
            'id_number,first_name,last_name,email,program,year_level,section',
            '2026-00010,Juan,Dela Cruz,juan@cpc.edu.ph,BSIT,1,BSIT 1-A',
            '2026-00011,Maria,Clara,maria@cpc.edu.ph,BSED,2,',
            '2026-00002,Dup,Licate,dup@cpc.edu.ph,BSIT,1,',
            '2026-00012,Pedro,Penduko,existing@cpc.edu.ph,BSIT,1,',
            '2026-00013,Jose,Rizal,jose@cpc.edu.ph,BSBA,3,BSBA 3-A',
            '2026-00010,Again,Same,again@cpc.edu.ph,BSIT,1,',
        ]);

        $response = $this->actingAs($this->adviser(), 'sanctum')
            ->post('/api/adviser/accounts/import', [
                'file' => $this->csv($csv),
                'temporary_password' => 'welcome123',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('created', 2);

        $skipped = collect($response->json('skipped'));
        $this->assertCount(4, $skipped);
        $this->assertSame([3, 4, 5, 7], $skipped->pluck('row')->all());

        $juan = User::query()->where('id_number', '2026-00010')->firstOrFail();
        $this->assertTrue($juan->must_change_password);
        $this->assertSame('student', $juan->role);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['login' => '2026-00010', 'password' => 'welcome123'])->assertOk();
    }
}
