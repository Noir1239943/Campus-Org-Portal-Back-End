<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Smoke test: every main page loads with the sample data for each role. */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_student_pages_load(): void
    {
        $student = User::query()->where('id_number', '2024-00145')->firstOrFail();
        $this->actingAs($student, 'sanctum');

        $this->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['stats', 'upcoming_events', 'organizations', 'clearance']);
        $this->getJson('/api/organizations')->assertOk()->assertJsonCount(10, 'data');
        $this->getJson('/api/organizations/junior-information-technology-society')->assertOk();
        $this->getJson('/api/my/organizations')->assertOk();
        $this->getJson('/api/events?scope=mine')->assertOk();
        $this->getJson('/api/events?scope=browse')->assertOk();
        $this->getJson('/api/events?scope=past')->assertOk();
        $this->getJson('/api/applications')->assertOk();
        $this->getJson('/api/clearance')->assertOk()->assertJsonPath('clearance.overall', 'pending');
        $this->getJson('/api/announcements')->assertOk();
        $this->getJson('/api/notifications')->assertOk();
        $this->getJson('/api/excuses')->assertOk();
    }

    public function test_officer_pages_load(): void
    {
        $officer = User::query()->where('id_number', '2023-00089')->firstOrFail();
        $this->actingAs($officer, 'sanctum');

        $this->getJson('/api/officer/overview')->assertOk()->assertJsonPath('organization.acronym', 'JITS');
        $this->getJson('/api/officer/applications')->assertOk();
        $this->getJson('/api/officer/members')->assertOk();
        $this->getJson('/api/officer/events?tab=upcoming')->assertOk();
        $this->getJson('/api/officer/events?tab=past')->assertOk();
        $this->getJson('/api/officer/events?tab=drafts')->assertOk();
        $this->getJson('/api/officer/excuses')->assertOk();
        $this->getJson('/api/officer/announcements')->assertOk();
        $this->getJson('/api/officer/organization')->assertOk();
    }

    public function test_adviser_pages_load(): void
    {
        $adviser = User::query()->where('id_number', 'FAC-0012')->firstOrFail();
        $this->actingAs($adviser, 'sanctum');

        $this->getJson('/api/adviser/overview')->assertOk()->assertJsonStructure(['stats', 'activity', 'attendance', 'organizations', 'alerts']);
        $this->getJson('/api/adviser/organizations')->assertOk();
        $this->getJson('/api/adviser/events?tab=pending')->assertOk();
        $this->getJson('/api/adviser/attendance')->assertOk();
        $this->getJson('/api/adviser/students')->assertOk();
        $this->get('/api/adviser/reports/organizations')->assertOk();
    }
}
