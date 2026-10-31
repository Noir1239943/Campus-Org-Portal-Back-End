<?php

namespace App\Services;

use App\Models\Event;
use App\Models\MembershipApplication;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Organization health for the Office of Student Affairs:
 *  healthy  – average attendance comfortably above the threshold
 *  monitor  – just above the threshold or on probation
 *  at_risk  – below the attendance threshold
 *  no_data  – no completed events yet this term
 */
class OrganizationHealthService
{
    public function __construct(private AttendanceService $attendance) {}

    /**
     * @param  Collection<int, Organization>|null  $organizations
     * @return Collection<int, array>
     */
    public function report(?Collection $organizations = null): Collection
    {
        $organizations ??= Organization::query()->visible()->orderBy('name')->get();

        if (! $organizations instanceof EloquentCollection) {
            $organizations = new EloquentCollection($organizations->all());
        }

        $organizations->loadCount([
            'activeMemberships as members_count',
            'events as events_count' => fn ($q) => $q->where('status', Event::STATUS_PUBLISHED),
            'events as upcoming_events_count' => fn ($q) => $q->where('status', Event::STATUS_PUBLISHED)->where('ends_at', '>=', now()),
            'applications as pending_applications_count' => fn ($q) => $q->where('status', MembershipApplication::STATUS_PENDING),
        ]);

        $rates = $this->attendance->ratesForOrganizations($organizations);
        $threshold = config('campus.attendance_threshold');

        return $organizations->map(function (Organization $organization) use ($rates, $threshold) {
            $rate = $rates[$organization->id]['rate'] ?? null;
            $lastEventAt = $rates[$organization->id]['last_event_at'] ?? null;

            $health = match (true) {
                $rate === null => 'no_data',
                $rate < $threshold => 'at_risk',
                $rate < $threshold + 5 || $organization->status === Organization::STATUS_PROBATION => 'monitor',
                default => 'healthy',
            };

            return [
                'id' => $organization->id,
                'slug' => $organization->slug,
                'name' => $organization->name,
                'acronym' => $organization->acronym,
                'category' => $organization->category,
                'color' => $organization->color,
                'status' => $organization->status,
                'adviser_name' => $organization->adviser_name,
                'members_count' => $organization->members_count,
                'events_count' => $organization->events_count,
                'upcoming_events_count' => $organization->upcoming_events_count,
                'completed_events' => $rates[$organization->id]['completed_events'] ?? 0,
                'pending_applications_count' => $organization->pending_applications_count,
                'attendance_rate' => $rate,
                'last_event_at' => $lastEventAt,
                'health' => $health,
            ];
        })->values();
    }
}
