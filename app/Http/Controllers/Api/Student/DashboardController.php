<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\EventResource;
use App\Models\Announcement;
use App\Models\Event;
use App\Models\MembershipApplication;
use App\Services\AttendanceService;
use App\Support\StudentEventPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AttendanceService $attendance): JsonResponse
    {
        $user = $request->user();
        $presenter = new StudentEventPresenter($user);
        $orgIds = $presenter->myOrganizationIds();

        // Events I registered for, plus anything from my organizations.
        $upcoming = Event::query()
            ->published()
            ->upcoming()
            ->where(function ($q) use ($presenter, $orgIds) {
                $q->whereIn('id', $presenter->registeredEventIds() ?: [0])
                    ->orWhereIn('organization_id', $orgIds ?: [0]);
            })
            ->with('organization')
            ->withCount('registrations')
            ->get();

        $presenter->decorateMany($upcoming);

        // Check-in is open right now for an event I can attend.
        $checkInNow = $upcoming->filter(fn (Event $e) => $e->viewer_state['can_check_in'])->values();

        $memberships = $user->activeMemberships()
            ->with(['organization' => fn ($q) => $q->withCount('activeMemberships as members_count')])
            ->get()
            ->map(fn ($m) => [
                'id' => $m->organization->id,
                'slug' => $m->organization->slug,
                'name' => $m->organization->name,
                'acronym' => $m->organization->acronym,
                'color' => $m->organization->color,
                'category' => $m->organization->category,
                'position' => $m->position,
                'is_officer' => $m->is_officer,
                'members_count' => $m->organization->members_count,
            ]);

        $application = MembershipApplication::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipApplication::STATUS_PENDING)
            ->with('organization')
            ->latest()
            ->first();

        $announcements = Announcement::query()
            ->visibleTo($user)
            ->with(['organization', 'author'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        $clearance = $attendance->clearance($user);
        $summary = $attendance->studentSummary($user);

        return response()->json([
            'stats' => [
                'organizations' => $memberships->count(),
                'upcoming_events' => $upcoming->filter(fn ($e) => $e->viewer_state['expected'])->count(),
                'upcoming_this_week' => $upcoming->filter(fn ($e) => $e->viewer_state['expected'] && $e->starts_at->lte(now()->addDays(7)))->count(),
                'attendance' => $summary,
            ],
            'check_in_now' => EventResource::collection($checkInNow),
            'upcoming_events' => EventResource::collection($upcoming->take(4)->values()),
            'organizations' => $memberships->values(),
            'pending_application' => $application ? new ApplicationResource($application) : null,
            'announcements' => AnnouncementResource::collection($announcements),
            'clearance' => [
                'overall' => $clearance['overall'],
                'cleared' => $clearance['cleared'],
                'total' => $clearance['total'],
                'action_needed' => collect($clearance['items'])->where('status', 'action_needed')->count(),
            ],
        ]);
    }
}
