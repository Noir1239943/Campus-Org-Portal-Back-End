<?php

namespace App\Http\Controllers\Api\Officer;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\Organization;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private AttendanceService $attendance,
    ) {}

    /** tab = upcoming | past | drafts (draft, pending, rejected) | cancelled */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['tab' => ['nullable', Rule::in(['upcoming', 'past', 'drafts', 'cancelled'])]]);

        $organization = $this->organization($request);
        $tab = $request->input('tab', 'upcoming');

        $query = $organization->events()->withCount('registrations')->with('creator');

        match ($tab) {
            'upcoming' => $query->published()->where('ends_at', '>=', now())->orderBy('starts_at'),
            'past' => $query->published()->where('ends_at', '<', now())->orderByDesc('starts_at'),
            'drafts' => $query->whereIn('status', [Event::STATUS_DRAFT, Event::STATUS_PENDING, Event::STATUS_REJECTED])->orderBy('starts_at'),
            'cancelled' => $query->where('status', Event::STATUS_CANCELLED)->orderByDesc('starts_at'),
        };

        $events = $query->get();

        if (in_array($tab, ['upcoming', 'past'], true)) {
            $stats = $this->attendance->statsForEvents($events);
            $events->each(fn (Event $e) => $e->setAttribute('stats', $stats[$e->id]));
        }

        $base = $organization->events();

        return response()->json([
            'data' => EventResource::collection($events),
            'counts' => [
                'upcoming' => (clone $base)->published()->where('ends_at', '>=', now())->count(),
                'past' => (clone $base)->published()->where('ends_at', '<', now())->count(),
                'drafts' => (clone $base)->whereIn('status', [Event::STATUS_DRAFT, Event::STATUS_PENDING, Event::STATUS_REJECTED])->count(),
                'cancelled' => (clone $base)->where('status', Event::STATUS_CANCELLED)->count(),
            ],
            'types' => Event::TYPES,
        ]);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->guard($request, $event);

        $event->load(['organization', 'creator'])->loadCount('registrations');
        $event->setAttribute('stats', $this->attendance->eventStats($event));

        return response()->json(new EventResource($event));
    }

    public function store(EventRequest $request): JsonResponse
    {
        $organization = $this->organization($request);

        $event = new Event($this->payload($request));
        $event->organization_id = $organization->id;
        $event->created_by = $request->user()->id;
        $event->status = Event::STATUS_DRAFT;
        $event->save();

        if ($request->input('action') === 'submit') {
            $this->submitEvent($event, $organization);
        }

        return response()->json(new EventResource($event->fresh(['organization', 'creator'])), 201);
    }

    public function update(EventRequest $request, Event $event): JsonResponse
    {
        $organization = $this->guard($request, $event);

        if (! $event->isEditable()) {
            throw ValidationException::withMessages(['event' => 'This event can no longer be edited.']);
        }

        $wasPublished = $event->isPublished();
        $event->fill($this->payload($request));

        // Editing a returned event puts it back to draft until it is re-submitted.
        if ($event->status === Event::STATUS_REJECTED) {
            $event->status = Event::STATUS_DRAFT;
        }

        $event->save();

        if ($request->input('action') === 'submit' && in_array($event->status, [Event::STATUS_DRAFT], true)) {
            $this->submitEvent($event, $organization);
        }

        if ($wasPublished && $event->wasChanged(['starts_at', 'ends_at', 'venue'])) {
            $this->notifications->send(
                $event->registrations()->pluck('user_id'),
                'event_updated',
                'Event details changed',
                "\"{$event->title}\" now starts {$event->starts_at->format('M j, g:i A')} at {$event->venue}.",
                "/events/{$event->id}"
            );
        }

        return response()->json(new EventResource($event->fresh(['organization', 'creator'])));
    }

    public function submit(Request $request, Event $event): JsonResponse
    {
        $organization = $this->guard($request, $event);

        if (! in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages(['event' => 'Only drafts can be submitted.']);
        }

        $this->submitEvent($event, $organization);

        return response()->json(new EventResource($event->fresh(['organization', 'creator'])));
    }

    public function cancel(Request $request, Event $event): JsonResponse
    {
        $this->guard($request, $event);

        if ($event->hasEnded() || $event->status === Event::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['event' => 'This event cannot be cancelled.']);
        }

        $notify = $event->isPublished();
        $event->update(['status' => Event::STATUS_CANCELLED, 'check_in_closed_at' => now()]);

        if ($notify) {
            $this->notifications->send(
                $event->registrations()->pluck('user_id'),
                'event_cancelled',
                'Event cancelled',
                "\"{$event->title}\" on {$event->starts_at->format('M j')} has been cancelled.",
                "/events/{$event->id}"
            );
        }

        return response()->json(new EventResource($event->fresh(['organization', 'creator'])));
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->guard($request, $event);

        if (! in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages(['event' => 'Only drafts can be deleted. Cancel published events instead.']);
        }

        $event->delete();

        return response()->json(['message' => 'Draft deleted.']);
    }

    private function submitEvent(Event $event, Organization $organization): void
    {
        if (! config('campus.require_event_approval')) {
            $event->update(['status' => Event::STATUS_PUBLISHED, 'submitted_at' => now(), 'reviewed_at' => now()]);
            $this->notifications->eventPublished($event);

            return;
        }

        $event->update(['status' => Event::STATUS_PENDING, 'submitted_at' => now(), 'review_note' => null]);

        $this->notifications->toAdvisers(
            'event_submitted',
            'Event waiting for approval',
            "{$organization->acronym} submitted \"{$event->title}\" ({$event->starts_at->format('M j')}).",
            '/adviser/events'
        );
    }

    private function payload(EventRequest $request): array
    {
        $tz = config('app.timezone');

        return [
            'title' => $request->input('title'),
            'type' => $request->input('type'),
            'description' => $request->input('description'),
            'venue' => $request->input('venue'),
            'starts_at' => Carbon::parse($request->input('starts_at'))->setTimezone($tz),
            'ends_at' => Carbon::parse($request->input('ends_at'))->setTimezone($tz),
            'capacity' => $request->input('capacity'),
            'visibility' => $request->input('visibility'),
            'is_mandatory' => $request->boolean('is_mandatory'),
            'service_hours' => (float) $request->input('service_hours', 0),
            'speaker' => $request->input('speaker'),
        ];
    }

    private function guard(Request $request, Event $event): Organization
    {
        $organization = $this->organization($request);
        abort_unless($event->organization_id === $organization->id, 404);

        return $organization;
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
