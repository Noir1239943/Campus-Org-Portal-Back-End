<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creating organizations and managing who leads them. */
class OrganizationService
{
    public const PRESIDENT = 'President';

    public function __construct(private NotificationService $notifications) {}

    public static function isPresidentPosition(?string $position): bool
    {
        return $position !== null && strtolower(trim($position)) === strtolower(self::PRESIDENT);
    }

    /** Create an organization and, optionally, make a student its President. */
    public function create(array $data, ?User $president = null): Organization
    {
        return DB::transaction(function () use ($data, $president) {
            $data['acronym'] = strtoupper($data['acronym']);
            $data['slug'] = $this->uniqueSlug($data['name']);
            $data['status'] = Organization::STATUS_ACTIVE;
            $data['recognized_at'] = now()->toDateString();

            $organization = Organization::query()->create($data);

            if ($president) {
                $this->assignOfficer($organization, $president, self::PRESIDENT);
            }

            return $organization;
        });
    }

    /** Give a student officer access with a position (replaces the current President when needed). */
    public function assignOfficer(Organization $organization, User $user, string $position): Membership
    {
        return DB::transaction(function () use ($organization, $user, $position) {
            $position = trim($position);

            if (self::isPresidentPosition($position)) {
                $this->demotePresident($organization, except: $user->id);
                $position = self::PRESIDENT;
            }

            $membership = Membership::query()->firstOrNew([
                'user_id' => $user->id,
                'organization_id' => $organization->id,
            ]);

            if (! $membership->exists || $membership->status !== Membership::STATUS_ACTIVE) {
                $membership->joined_at = now();
            }

            $membership->fill([
                'position' => $position,
                'is_officer' => true,
                'status' => Membership::STATUS_ACTIVE,
            ])->save();

            $this->notifications->send(
                $user,
                'officer_assigned',
                "You are now {$position} of {$organization->acronym}",
                "The Office of Student Affairs gave you officer access to {$organization->name}.",
                '/officer'
            );

            return $membership;
        });
    }

    /** Change an existing member's position / officer access. Removing access keeps them as a member. */
    public function updateOfficer(Membership $membership, string $position, bool $isOfficer): Membership
    {
        return DB::transaction(function () use ($membership, $position, $isOfficer) {
            $organization = $membership->organization;
            $position = trim($position);

            if ($isOfficer && self::isPresidentPosition($position)) {
                $this->demotePresident($organization, except: $membership->user_id);
                $position = self::PRESIDENT;
            }

            $wasOfficer = $membership->is_officer;
            $membership->update(['position' => $position, 'is_officer' => $isOfficer]);

            if ($isOfficer && ! $wasOfficer) {
                $this->notifications->send(
                    $membership->user_id,
                    'officer_assigned',
                    "You are now {$position} of {$organization->acronym}",
                    "You were given officer access to {$organization->name}.",
                    '/officer'
                );
            }

            return $membership;
        });
    }

    /** Every student is automatically a member of organizations like the student government. */
    public function enrollInAllStudentOrganizations(User $student): void
    {
        $organizationIds = Organization::query()->where('requires_all_students', true)->pluck('id');

        foreach ($organizationIds as $organizationId) {
            Membership::query()->firstOrCreate(
                ['user_id' => $student->id, 'organization_id' => $organizationId],
                ['position' => 'Member', 'is_officer' => false, 'status' => Membership::STATUS_ACTIVE, 'joined_at' => now()]
            );
        }
    }

    public function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $i = 2;

        while (Organization::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** The previous President goes back to being a regular member. */
    private function demotePresident(Organization $organization, int $except): void
    {
        $current = $organization->activeMemberships()
            ->whereRaw('LOWER(position) = ?', [strtolower(self::PRESIDENT)])
            ->where('user_id', '!=', $except)
            ->get();

        foreach ($current as $membership) {
            $membership->update(['position' => 'Member', 'is_officer' => false]);

            $this->notifications->send(
                $membership->user_id,
                'officer_changed',
                "You are no longer President of {$organization->acronym}",
                "The Office of Student Affairs assigned a new President for {$organization->name}. You remain a member.",
                "/organizations/{$organization->slug}"
            );
        }
    }
}
