<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExcuseRequest;
use App\Http\Resources\EventResource;
use App\Http\Resources\ExcuseResource;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ExcuseRequest;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use App\Support\StudentEventPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    /**
     * scope=mine    upcoming events I registered for or from my organizations
     * scope=browse  every upcoming event I can join
     * scope=past    events I was expected at, with my attendance
     */
    public function index(Request $request, AttendanceService $attendance): JsonResponse
    {
        $request->validate([
            'scope' => ['nullable', Rule::in(['mine', 'browse', 'past'])],
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(Event::TYPES)],
        ]);

        $user = $request->user();
        $presenter = new StudentEventPresenter($user);
        $orgIds = $presenter->myOrganizationIds();
        $scope = $request->input('scope', 'mine');

        if ($scope === 'past') {
            $events = $attendance->relevantEndedEvents($user)->loadCount('registrations');
        } else {
            $events = Event::query()
                ->published()
                ->upcoming()
                ->when($scope === 'mine', function ($q) use ($presenter, $orgIds) {
                    $q->where(function ($q) use ($presenter, $orgIds) {
                        $q->whereIn('id', $presenter->registeredEventIds() ?: [0])
                            ->orWhereIn('organization_id', $orgIds ?: [0]);
                    });
                })
                ->when($scope === 'browse', function ($q) use ($orgIds) {
                    $q->where(function ($q) use ($orgIds) {
                        $q->where('visibility', Event::VISIBILITY_OPEN)
                            ->orWhereIn('organization_id', $orgIds ?: [0]);
                    });
                })
                ->when($request->filled('search'), function ($q) use ($request) {
                    $term = '%'.strtolower((string) $request->input('search')).'%';
                    $q->where(fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', [$term])->orWhereRaw('LOWER(venue) LIKE ?', [$term]));
                })
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
                ->with('organization')
                ->withCount('registrations')
                ->get();
        }

        $presenter->decorateMany($events);

        return response()->json(EventResource::collection($events->values()));
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        abort_unless(in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED], true), 404);

        $event->load('organization')->loadCount('registrations');
        (new StudentEventPresenter($request->user()))->decorate($event);

        return response()->json(new EventResource($event));
    }

    public function register(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();
        $presenter = new StudentEventPresenter($user);
        $event->loadCount('registrations');
        $presenter->decorate($event);

        $state = $event->viewer_state;

        if ($state['registered']) {
            throw ValidationException::withMessages(['event' => 'You are already registered for this event.']);
        }

        if ($state['is_full']) {
            throw ValidationException::withMessages(['event' => 'Sorry, this event is already full.']);
        }

        if (! $state['can_register']) {
            throw ValidationException::withMessages(['event' => 'Registration is not available for this event.']);
        }

        EventRegistration::query()->firstOrCreate(['event_id' => $event->id, 'user_id' => $user->id]);

        return $this->fresh($request, $event, 'You are registered. See you there!');
    }

    public function cancelRegistration(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();

        if ($event->hasStarted()) {
            throw ValidationException::withMessages(['event' => 'The event has already started. File an excuse instead if you cannot attend.']);
        }

        EventRegistration::query()->where('event_id', $event->id)->where('user_id', $user->id)->delete();

        return $this->fresh($request, $event, 'Your registration was cancelled.');
    }

    /** File an excuse for an event I can't attend (or missed). */
    public function excuse(StoreExcuseRequest $request, Event $event, NotificationService $notifications): JsonResponse
    {
        $user = $request->user();
        $event->loadCount('registrations');
        (new StudentEventPresenter($user))->decorate($event);

        if (! $event->viewer_state['can_file_excuse']) {
            throw ValidationException::withMessages(['event' => 'An excuse can no longer be filed for this event.']);
        }

        $excuse = ExcuseRequest::query()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'reason' => $request->input('reason'),
            'details' => $request->input('details'),
        ]);

        $notifications->toOfficers(
            $event->organization,
            'excuse_submitted',
            'New excuse request',
            "{$user->name} filed an excuse for \"{$event->title}\".",
            '/officer/excuses'
        );

        $excuse->load('event.organization');

        return response()->json(new ExcuseResource($excuse), 201);
    }

    /** My excuse requests. */
    public function excuses(Request $request): JsonResponse
    {
        $excuses = ExcuseRequest::query()
            ->where('user_id', $request->user()->id)
            ->with(['event.organization', 'reviewer'])
            ->latest()
            ->get();

        return response()->json(ExcuseResource::collection($excuses));
    }

    private function fresh(Request $request, Event $event, string $message): JsonResponse
    {
        $event = $event->fresh()->load('organization')->loadCount('registrations');
        (new StudentEventPresenter($request->user()))->decorate($event);

        return response()->json([
            'message' => $message,
            'event' => new EventResource($event),
        ]);
    }
}
