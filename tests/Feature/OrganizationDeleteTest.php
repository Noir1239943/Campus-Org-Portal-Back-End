<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrganization(string $acronym = 'TS'): Organization
    {
        return Organization::query()->create([
            'name' => "Test Society {$acronym}",
            'slug' => 'test-society-'.strtolower($acronym),
            'acronym' => $acronym,
            'category' => 'Academic',
            'description' => 'A test organization.',
        ]);
    }

    private function join(Organization $organization, User $user, bool $officer = false): Membership
    {
        return Membership::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'position' => $officer ? 'President' : 'Member',
            'is_officer' => $officer,
            'status' => Membership::STATUS_ACTIVE,
            'joined_at' => now()->subMonth(),
        ]);
    }

    public function test_osa_deletes_unused_organization_with_correct_confirmation(): void
    {
        $adviser = User::factory()->adviser()->create();
        $organization = $this->makeOrganization();
        $member = User::factory()->create();
        $this->join($organization, $member);

        $this->actingAs($adviser, 'sanctum')
            ->deleteJson("/api/adviser/organizations/{$organization->id}", ['confirm' => 'ts'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $member->id, 'type' => 'organization_removed']);
    }

    public function test_wrong_confirmation_is_rejected(): void
    {
        $adviser = User::factory()->adviser()->create();
        $organization = $this->makeOrganization();

        $this->actingAs($adviser, 'sanctum')
            ->deleteJson("/api/adviser/organizations/{$organization->id}", ['confirm' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');

        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);
    }

    public function test_organization_with_event_history_cannot_be_deleted(): void
    {
        $adviser = User::factory()->adviser()->create();
        $organization = $this->makeOrganization();

        Event::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Past seminar',
            'venue' => 'Hall',
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $this->actingAs($adviser, 'sanctum')
            ->deleteJson("/api/adviser/organizations/{$organization->id}", ['confirm' => 'TS'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organization has event and attendance history. Archive it instead so student records are kept.');

        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);

        $this->getJson("/api/adviser/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('can_delete', false)
            ->assertJsonPath('history.events', 1);
    }

    public function test_students_and_officers_cannot_delete(): void
    {
        $organization = $this->makeOrganization();
        $student = User::factory()->create();
        $officer = User::factory()->create();
        $this->join($organization, $officer, officer: true);

        $this->actingAs($student, 'sanctum')
            ->deleteJson("/api/adviser/organizations/{$organization->id}", ['confirm' => 'TS'])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($officer, 'sanctum')
            ->deleteJson("/api/adviser/organizations/{$organization->id}", ['confirm' => 'TS'])
            ->assertForbidden();

        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);
    }

    public function test_archived_organization_is_hidden_from_members_and_officers(): void
    {
        $adviser = User::factory()->adviser()->create();
        $organization = $this->makeOrganization();
        $member = User::factory()->create();
        $officer = User::factory()->create();
        $this->join($organization, $member);
        $this->join($organization, $officer, officer: true);

        $this->actingAs($member, 'sanctum')->getJson('/api/my/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'memberships');

        $this->app['auth']->forgetGuards();
        $this->actingAs($officer, 'sanctum')->getJson('/api/auth/me')
            ->assertJsonPath('workspaces', ['student', 'officer']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($adviser, 'sanctum')
            ->patchJson("/api/adviser/organizations/{$organization->id}/status", ['status' => 'inactive'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($member, 'sanctum')->getJson('/api/my/organizations')
            ->assertOk()
            ->assertJsonCount(0, 'memberships');

        $this->app['auth']->forgetGuards();
        $this->actingAs($officer, 'sanctum')->getJson('/api/auth/me')
            ->assertJsonPath('workspaces', ['student'])
            ->assertJsonCount(0, 'officer_organizations');

        $this->withHeaders(['X-Organization-Id' => $organization->id])
            ->getJson('/api/officer/overview')
            ->assertForbidden();

        // Restoring brings everything back.
        $this->app['auth']->forgetGuards();
        $this->actingAs($adviser, 'sanctum')
            ->patchJson("/api/adviser/organizations/{$organization->id}/status", ['status' => 'active'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($officer, 'sanctum')->getJson('/api/auth/me')
            ->assertJsonPath('workspaces', ['student', 'officer']);
    }
}
