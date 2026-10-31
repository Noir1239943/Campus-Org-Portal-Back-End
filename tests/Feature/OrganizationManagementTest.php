<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationProposal;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function orgPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cordova Chess Club',
            'acronym' => 'ccc',
            'category' => 'Sports',
            'description' => 'Weekly chess games and tournaments for CPC students.',
        ], $overrides);
    }

    private function makeOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Test Society',
            'slug' => 'test-society',
            'acronym' => 'TS',
            'category' => 'Academic',
            'description' => 'A test organization.',
        ]);
    }

    private function makeOfficer(Organization $organization, string $position, ?User $user = null): Membership
    {
        $user ??= User::factory()->create();

        return Membership::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'position' => $position,
            'is_officer' => true,
            'status' => Membership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
    }

    public function test_osa_creates_organization_with_president(): void
    {
        $adviser = User::factory()->adviser()->create();
        $student = User::factory()->create(['id_number' => '2025-00777']);

        $this->actingAs($adviser, 'sanctum')
            ->postJson('/api/adviser/organizations', $this->orgPayload(['president' => '2025-00777']))
            ->assertCreated()
            ->assertJsonPath('organization.acronym', 'CCC');

        $organization = Organization::query()->where('acronym', 'CCC')->firstOrFail();
        $membership = Membership::query()->where('user_id', $student->id)->where('organization_id', $organization->id)->firstOrFail();

        $this->assertTrue($membership->is_officer);
        $this->assertSame('President', $membership->position);

        $this->app['auth']->forgetGuards();
        $this->actingAs($student->fresh(), 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('workspaces', ['student', 'officer']);
    }

    public function test_student_cannot_create_organization_directly(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/adviser/organizations', $this->orgPayload())
            ->assertForbidden();
    }

    public function test_assigning_new_president_demotes_the_old_one(): void
    {
        $adviser = User::factory()->adviser()->create();
        $organization = $this->makeOrganization();
        $old = $this->makeOfficer($organization, 'President');
        $newcomer = User::factory()->create(['id_number' => '2025-00888']);

        $this->actingAs($adviser, 'sanctum')
            ->postJson("/api/adviser/organizations/{$organization->id}/officers", [
                'identifier' => '2025-00888',
                'position' => 'president',
            ])
            ->assertOk()
            ->assertJsonPath('officers.0.is_president', true)
            ->assertJsonPath('officers.0.id_number', '2025-00888');

        $old->refresh();
        $this->assertSame('Member', $old->position);
        $this->assertFalse($old->is_officer);
        $this->assertSame(Membership::STATUS_ACTIVE, $old->status);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $old->user_id, 'type' => 'officer_changed']);
        $this->assertTrue(OrganizationService::isPresidentPosition(
            Membership::query()->where('user_id', $newcomer->id)->value('position')
        ));
    }

    public function test_officer_cannot_demote_or_remove_the_president(): void
    {
        $organization = $this->makeOrganization();
        $president = $this->makeOfficer($organization, 'President');
        $secretary = $this->makeOfficer($organization, 'Secretary');
        $member = $this->makeOfficer($organization, 'Member')->fill(['is_officer' => false]);
        $member->save();

        $headers = ['X-Organization-Id' => $organization->id];
        $this->actingAs($secretary->user, 'sanctum');

        $this->withHeaders($headers)
            ->patchJson("/api/officer/members/{$president->id}", ['position' => 'Member', 'is_officer' => false])
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->deleteJson("/api/officer/members/{$president->id}")
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->patchJson("/api/officer/members/{$member->id}", ['position' => 'President', 'is_officer' => true])
            ->assertStatus(422);

        $this->assertTrue($president->fresh()->is_officer);
        $this->assertSame('President', $president->fresh()->position);

        // Regular officer changes still work.
        $this->withHeaders($headers)
            ->patchJson("/api/officer/members/{$member->id}", ['position' => 'Treasurer', 'is_officer' => true])
            ->assertOk();
    }

    public function test_approved_proposal_creates_organization_with_proposer_as_president(): void
    {
        $adviser = User::factory()->adviser()->create();
        $student = User::factory()->create();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/proposals', [
                'name' => 'CPC Robotics Club',
                'acronym' => 'robo',
                'category' => 'Academic',
                'description' => 'A club for students who love building robots.',
                'purpose' => 'To give students hands-on experience with electronics and programming outside class.',
                'expected_members' => 20,
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending');

        $proposal = OrganizationProposal::query()->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->actingAs($adviser, 'sanctum')
            ->postJson("/api/adviser/proposals/{$proposal->id}/approve", ['note' => 'Welcome!'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $organization = Organization::query()->where('acronym', 'ROBO')->firstOrFail();
        $membership = Membership::query()->where('user_id', $student->id)->where('organization_id', $organization->id)->firstOrFail();

        $this->assertTrue($membership->is_officer);
        $this->assertSame('President', $membership->position);
        $this->assertSame($organization->id, $proposal->fresh()->organization_id);
    }
}
