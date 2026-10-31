<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\CheckInCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    private function setUpEvent(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Junior Information Technology Society',
            'acronym' => 'JITS',
            'category' => 'Academic',
            'description' => 'Tech organization',
        ]);

        $officer = User::factory()->create();
        Membership::query()->create([
            'user_id' => $officer->id,
            'organization_id' => $organization->id,
            'position' => 'President',
            'is_officer' => true,
            'joined_at' => now()->subYear(),
        ]);

        $event = Event::query()->create([
            'organization_id' => $organization->id,
            'title' => 'IT Seminar',
            'type' => 'Seminar',
            'venue' => 'AVR',
            'starts_at' => now()->addMinutes(10),
            'ends_at' => now()->addHours(2),
            'visibility' => 'open',
            'status' => 'published',
        ]);

        return [$organization, $officer, $event];
    }

    public function test_officer_opens_check_in_and_student_checks_in_with_code(): void
    {
        [, $officer, $event] = $this->setUpEvent();
        $student = User::factory()->create();

        $code = $this->actingAs($officer, 'sanctum')
            ->postJson("/api/officer/events/{$event->id}/check-in/open")
            ->assertOk()
            ->assertJsonPath('open', true)
            ->json('code');

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/check-in', ['code' => strtolower($code)])
            ->assertOk()
            ->assertJsonPath('attendance.status', 'present')
            ->assertJsonPath('already_checked_in', false);

        // Scanning again doesn't create a duplicate.
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/check-in', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('already_checked_in', true);

        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_wrong_code_is_rejected(): void
    {
        [, $officer, $event] = $this->setUpEvent();
        $this->actingAs($officer, 'sanctum')->postJson("/api/officer/events/{$event->id}/check-in/open")->assertOk();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/check-in', ['code' => 'ZZZZZZ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_codes_rotate_by_time_window(): void
    {
        [, , $event] = $this->setUpEvent();
        $codes = app(CheckInCodeService::class);
        $codes->ensureSecret($event);

        $window = $codes->currentWindow();

        $this->assertNotSame($codes->codeFor($event, $window), $codes->codeFor($event, $window - 5));
        $this->assertTrue($codes->verify($event, $codes->codeFor($event, $window - 1)));
        $this->assertFalse($codes->verify($event, $codes->codeFor($event, $window - 3)));
    }
}
