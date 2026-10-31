<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Activity proposals: replaces routing paper proposals for signatures. */
class EventController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request, AttendanceService $attendance): JsonResponse
    {
        $request->validate([
            'tab' => ['nullable', Rule::in(['pending', 'upcoming', 'completed', 'rejected'])],
            'organization_id' => ['nullable', 'integer'],
        ]);

        $tab = $request->input('tab', 'pending');

        $query = Event::query()
            ->with(['organization', 'creator', 'reviewer'])
            ->withCount('registrations')
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->integer('organization_id')));

        match ($tab) {
            'pending' => $query->where('status', Event::STATUS_PENDING)->orderBy('starts_at'),
            'upcoming' => $query->published()->where('ends_at', '>=', now())->orderBy('starts_at'),
            'completed' => $query->published()->where('ends_at', '<', now())->orderByDesc('starts_at'),
            'rejected' => $query->where('status', Event::STATUS_REJECTED)->orderByDesc('reviewed_at'),
        };

        $events = $query->get();

        if ($tab === 'completed') {
            $stats = $attendance->statsForEvents($events);
            $events->each(fn ($e) => $e->setAttribute('stats', $stats[$e->id]));
        }

        return response()->json([
            'data' => EventResource::collection($events),
            'counts' => [
                'pending' => Event::query()->where('status', Event::STATUS_PENDING)->count(),
                'upcoming' => Event::query()->published()->where('ends_at', '>=', now())->count(),
                'completed' => Event::query()->published()->where('ends_at', '<', now())->count(),
                'rejected' => Event::query()->where('status', Event::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    public function approve(ReviewRequest $request, Event $event): JsonResponse
    {
        $this->ensurePending($event);

        $event->update([
            'status' => Event::STATUS_PUBLISHED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('note'),
        ]);

        $event->load('organization');

        $this->notifications->toOfficers(
            $event->organization,
            'event_approved',
            'Event approved',
            "\"{$event->title}\" was approved and is now visible to students.",
            "/officer/events"
        );
        $this->notifications->eventPublished($event);

        return response()->json(new EventResource($event->fresh(['organization', 'creator', 'reviewer'])));
    }

    public function reject(ReviewRequest $request, Event $event): JsonResponse
    {
        $this->ensurePending($event);

        $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']], [
            'note.required' => 'Tell the officers what to revise.',
        ]);

        $event->update([
            'status' => Event::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('note'),
        ]);

        $event->load('organization');

        $this->notifications->toOfficers(
            $event->organization,
            'event_rejected',
            'Event returned for revision',
            "\"{$event->title}\": {$request->input('note')}",
            "/officer/events/{$event->id}/edit"
        );

        return response()->json(new EventResource($event->fresh(['organization', 'creator', 'reviewer'])));
    }

    private function ensurePending(Event $event): void
    {
        if ($event->status !== Event::STATUS_PENDING) {
            throw ValidationException::withMessages(['event' => 'This event is not waiting for approval.']);
        }
    }
}
