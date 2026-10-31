<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ExcuseRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Adds the logged-in student's point of view to events:
 * registered? checked in? can they still register, check in or file an excuse?
 */
class StudentEventPresenter
{
    /** @var array<int, bool> */
    private array $registered;

    /** @var Collection<int, Attendance> */
    private Collection $attendance;

    /** @var Collection<int, ExcuseRequest> */
    private Collection $excuses;

    /** @var Collection<int, \App\Models\Membership> */
    private Collection $memberships;

    public function __construct(private User $user)
    {
        $this->registered = EventRegistration::query()
            ->where('user_id', $user->id)
            ->pluck('event_id')
            ->flip()
            ->map(fn () => true)
            ->all();

        $this->attendance = Attendance::query()->where('user_id', $user->id)->get()->keyBy('event_id');
        $this->excuses = ExcuseRequest::query()->where('user_id', $user->id)->get()->keyBy('event_id');
        $this->memberships = $user->activeMemberships()->get()->keyBy('organization_id');
    }

    public function myOrganizationIds(): array
    {
        return $this->memberships->keys()->all();
    }

    public function registeredEventIds(): array
    {
        return array_keys($this->registered);
    }

    public function decorate(Event $event): Event
    {
        $membership = $this->memberships->get($event->organization_id);
        $isMember = (bool) $membership;
        $registered = isset($this->registered[$event->id]);
        $attendance = $this->attendance->get($event->id);
        $excuse = $this->excuses->get($event->id);
        $attended = $attendance?->attended() ?? false;

        $mandatoryForMe = $event->is_mandatory && $isMember
            && (! $membership->joined_at || $membership->joined_at->lte($event->starts_at));

        $canSee = $event->visibility === Event::VISIBILITY_OPEN || $isMember;
        $registrations = $event->registrations_count ?? $event->registrations()->count();
        $full = $event->capacity && $registrations >= $event->capacity;

        $event->setAttribute('viewer_state', [
            'is_member' => $isMember,
            'registered' => $registered,
            'expected' => $registered || $mandatoryForMe,
            'mandatory_for_me' => $mandatoryForMe,
            'attendance_status' => $attendance?->status,
            'checked_in_at' => $attendance?->checked_in_at?->toIso8601String(),
            'excuse_status' => $excuse?->status,
            'excuse_note' => $excuse?->review_note,
            'is_full' => (bool) $full,
            'can_register' => $event->isPublished() && ! $event->hasEnded() && ! $registered && $canSee && ! $full && ! $attended,
            'can_cancel' => $registered && ! $event->hasStarted() && ! $attended,
            'can_check_in' => $event->isCheckInOpen() && $canSee && ! $attended,
            'can_file_excuse' => $event->isPublished()
                && ! $attended
                && ! $excuse
                && ($registered || $mandatoryForMe)
                && $event->ends_at->copy()->addDays(config('campus.excuse_window_days'))->isFuture(),
        ]);

        return $event;
    }

    /**
     * @param  iterable<Event>  $events
     * @return Collection<int, Event>
     */
    public function decorateMany(iterable $events): Collection
    {
        return collect($events)->map(fn (Event $event) => $this->decorate($event));
    }
}
