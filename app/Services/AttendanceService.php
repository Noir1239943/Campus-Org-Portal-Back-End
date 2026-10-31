<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ExcuseRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Attendance math in one place.
 *
 * Who is "expected" at an event?
 *  - everyone who registered, plus
 *  - every active member of the organization when the event is mandatory, plus
 *  - anyone who actually checked in (walk-ins).
 *
 * Attendance rate = attended (present + late) / (expected − excused).
 */
class AttendanceService
{
    /**
     * Build the expected / attended / excused sets for each event.
     *
     * @param  Collection<int, Event>  $events
     * @return array<int, array{universe: array<int,bool>, attended: array<int,bool>, excused: array<int,bool>, present: int, late: int}>
     */
    public function buildSets(Collection $events): array
    {
        $ids = $events->pluck('id')->all();

        if (! $ids) {
            return [];
        }

        $registrations = EventRegistration::query()
            ->whereIn('event_id', $ids)
            ->get(['event_id', 'user_id'])
            ->groupBy('event_id');

        $attendances = Attendance::query()
            ->whereIn('event_id', $ids)
            ->get(['event_id', 'user_id', 'status'])
            ->groupBy('event_id');

        $mandatoryOrgIds = $events->where('is_mandatory', true)->pluck('organization_id')->unique()->values()->all();

        $members = $mandatoryOrgIds
            ? Membership::query()
                ->whereIn('organization_id', $mandatoryOrgIds)
                ->where('status', Membership::STATUS_ACTIVE)
                ->get(['organization_id', 'user_id', 'joined_at'])
                ->groupBy('organization_id')
            : collect();

        $sets = [];

        foreach ($events as $event) {
            $universe = [];
            $attended = [];
            $excused = [];
            $present = 0;
            $late = 0;

            foreach ($registrations->get($event->id, collect()) as $registration) {
                $universe[$registration->user_id] = true;
            }

            if ($event->is_mandatory) {
                foreach ($members->get($event->organization_id, collect()) as $membership) {
                    if (! $membership->joined_at || $membership->joined_at->lte($event->starts_at)) {
                        $universe[$membership->user_id] = true;
                    }
                }
            }

            foreach ($attendances->get($event->id, collect()) as $attendance) {
                $universe[$attendance->user_id] = true;

                if ($attendance->status === Attendance::STATUS_PRESENT) {
                    $attended[$attendance->user_id] = true;
                    $present++;
                } elseif ($attendance->status === Attendance::STATUS_LATE) {
                    $attended[$attendance->user_id] = true;
                    $late++;
                } elseif ($attendance->status === Attendance::STATUS_EXCUSED) {
                    $excused[$attendance->user_id] = true;
                }
            }

            $sets[$event->id] = compact('universe', 'attended', 'excused', 'present', 'late');
        }

        return $sets;
    }

    /** Summary numbers for one event from its sets. */
    public function statsFromSet(array $set, Event $event): array
    {
        $expected = count($set['universe']);
        $attended = count($set['attended']);
        $excused = count($set['excused']);
        $notCheckedIn = max(0, $expected - $attended - $excused);
        $denominator = $expected - $excused;

        return [
            'expected' => $expected,
            'present' => $set['present'],
            'late' => $set['late'],
            'attended' => $attended,
            'excused' => $excused,
            'not_checked_in' => $notCheckedIn,
            'absent' => $event->hasEnded() ? $notCheckedIn : 0,
            'rate' => $denominator > 0 ? (int) round($attended / $denominator * 100) : null,
        ];
    }

    public function eventStats(Event $event): array
    {
        $sets = $this->buildSets(collect([$event]));

        return $this->statsFromSet($sets[$event->id], $event);
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return array<int, array> stats keyed by event id
     */
    public function statsForEvents(Collection $events): array
    {
        $sets = $this->buildSets($events);
        $stats = [];

        foreach ($events as $event) {
            $stats[$event->id] = $this->statsFromSet($sets[$event->id], $event);
        }

        return $stats;
    }

    /** Expected attendee ids for a single event (used by the live roster). */
    public function expectedUserIds(Event $event): array
    {
        $sets = $this->buildSets(collect([$event]));

        return array_keys($sets[$event->id]['universe']);
    }

    /**
     * Average attendance rate of completed events for each organization.
     *
     * @param  Collection<int, Organization>  $organizations
     * @return array<int, array{rate: int|null, completed_events: int, last_event_at: ?string}>
     */
    public function ratesForOrganizations(Collection $organizations): array
    {
        $orgIds = $organizations->pluck('id')->all();

        $events = Event::query()
            ->published()
            ->ended()
            ->whereIn('organization_id', $orgIds ?: [0])
            ->get();

        $stats = $this->statsForEvents($events);
        $result = [];

        foreach ($organizations as $organization) {
            $orgEvents = $events->where('organization_id', $organization->id);
            $rates = $orgEvents->map(fn (Event $e) => $stats[$e->id]['rate'])->filter(fn ($r) => $r !== null);

            $result[$organization->id] = [
                'rate' => $rates->isNotEmpty() ? (int) round($rates->avg()) : null,
                'completed_events' => $orgEvents->count(),
                'last_event_at' => $orgEvents->max('starts_at')?->toIso8601String(),
            ];
        }

        return $result;
    }

    /**
     * Attendance rate of each member within one organization.
     *
     * @param  array<int>  $userIds
     * @return array<int, array{expected: int, attended: int, rate: int|null}>
     */
    public function memberRates(Organization $organization, array $userIds): array
    {
        $events = $organization->events()->published()->ended()->get();
        $sets = $this->buildSets($events);
        $result = [];

        foreach ($userIds as $userId) {
            $expected = 0;
            $attended = 0;
            $excused = 0;

            foreach ($sets as $set) {
                if (! isset($set['universe'][$userId])) {
                    continue;
                }

                $expected++;

                if (isset($set['attended'][$userId])) {
                    $attended++;
                } elseif (isset($set['excused'][$userId])) {
                    $excused++;
                }
            }

            $denominator = $expected - $excused;

            $result[$userId] = [
                'expected' => $expected,
                'attended' => $attended,
                'rate' => $denominator > 0 ? (int) round($attended / $denominator * 100) : null,
            ];
        }

        return $result;
    }

    /**
     * Ended, published events a student was expected at (or attended).
     *
     * @return Collection<int, Event>
     */
    public function relevantEndedEvents(User $user): Collection
    {
        $memberships = $user->activeMemberships()->get(['organization_id', 'joined_at']);
        $registeredIds = EventRegistration::query()->where('user_id', $user->id)->pluck('event_id');
        $attendedIds = Attendance::query()->where('user_id', $user->id)->pluck('event_id');
        $ids = $registeredIds->merge($attendedIds)->unique()->values()->all();

        return Event::query()
            ->published()
            ->ended()
            ->where(function ($query) use ($ids, $memberships) {
                $query->whereIn('id', $ids ?: [0]);

                foreach ($memberships as $membership) {
                    $query->orWhere(function ($q) use ($membership) {
                        $q->where('organization_id', $membership->organization_id)
                            ->where('is_mandatory', true);

                        if ($membership->joined_at) {
                            $q->where('starts_at', '>=', $membership->joined_at);
                        }
                    });
                }
            })
            ->with('organization:id,name,acronym,slug,color')
            ->orderByDesc('starts_at')
            ->get();
    }

    /** Overall attendance and service hours of a student for the term. */
    public function studentSummary(User $user): array
    {
        $events = $this->relevantEndedEvents($user);
        $rows = Attendance::query()->where('user_id', $user->id)->get()->keyBy('event_id');

        $attended = $events->filter(fn (Event $e) => $rows->get($e->id)?->attended())->count();
        $excused = $events->filter(fn (Event $e) => $rows->get($e->id)?->status === Attendance::STATUS_EXCUSED)->count();
        $total = $events->count();
        $denominator = $total - $excused;

        $attendedEventIds = $rows->filter(fn (Attendance $a) => $a->attended())->keys()->all();
        $hours = (float) Event::query()->published()->whereIn('id', $attendedEventIds ?: [0])->sum('service_hours');

        return [
            'total' => $total,
            'attended' => $attended,
            'excused' => $excused,
            'missed' => max(0, $total - $attended - $excused),
            'rate' => $denominator > 0 ? (int) round($attended / $denominator * 100) : null,
            'service_hours' => round($hours, 1),
            'service_hours_goal' => config('campus.service_hours_goal'),
        ];
    }

    /**
     * Clearance status per organization: a student is cleared when every
     * mandatory event since they joined was attended or excused.
     */
    public function clearance(User $user): array
    {
        $memberships = $user->activeMemberships()->with('organization')->get();
        $rows = Attendance::query()->where('user_id', $user->id)->get()->keyBy('event_id');
        $excuses = ExcuseRequest::query()->where('user_id', $user->id)->get()->keyBy('event_id');
        $windowDays = config('campus.excuse_window_days');

        $items = [];

        foreach ($memberships as $membership) {
            $organization = $membership->organization;

            $events = Event::query()
                ->published()
                ->ended()
                ->where('organization_id', $organization->id)
                ->where('is_mandatory', true)
                ->when($membership->joined_at, fn ($q) => $q->where('starts_at', '>=', $membership->joined_at))
                ->orderBy('starts_at')
                ->get();

            $attended = 0;
            $excused = 0;
            $missed = [];

            foreach ($events as $event) {
                $row = $rows->get($event->id);

                if ($row?->attended()) {
                    $attended++;

                    continue;
                }

                if ($row?->status === Attendance::STATUS_EXCUSED) {
                    $excused++;

                    continue;
                }

                $excuse = $excuses->get($event->id);

                $missed[] = [
                    'event_id' => $event->id,
                    'title' => $event->title,
                    'starts_at' => $event->starts_at->toIso8601String(),
                    'venue' => $event->venue,
                    'excuse_status' => $excuse?->status,
                    'can_file_excuse' => ! $excuse && $event->ends_at->copy()->addDays($windowDays)->isFuture(),
                ];
            }

            $pendingOnly = $missed && collect($missed)->every(fn ($m) => $m['excuse_status'] === ExcuseRequest::STATUS_PENDING);

            $items[] = [
                'organization' => [
                    'id' => $organization->id,
                    'slug' => $organization->slug,
                    'name' => $organization->name,
                    'acronym' => $organization->acronym,
                    'color' => $organization->color,
                ],
                'position' => $membership->position,
                'required' => $events->count(),
                'attended' => $attended,
                'excused' => $excused,
                'missed' => $missed,
                'status' => ! $missed ? 'cleared' : ($pendingOnly ? 'under_review' : 'action_needed'),
            ];
        }

        $cleared = collect($items)->where('status', 'cleared')->count();

        return [
            'overall' => $cleared === count($items) ? 'cleared' : 'pending',
            'cleared' => $cleared,
            'total' => count($items),
            'items' => $items,
        ];
    }
}
