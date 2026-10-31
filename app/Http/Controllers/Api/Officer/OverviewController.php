<?php

namespace App\Http\Controllers\Api\Officer;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\Organization;
use App\Services\AttendanceService;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function __invoke(Request $request, AttendanceService $attendance): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');
        $threshold = config('campus.member_risk_threshold');

        $organization->loadCount([
            'activeMemberships as members_count',
            'activeMemberships as new_members_count' => fn ($q) => $q->where('joined_at', '>=', Csv::academicYearStart()),
        ]);

        $pendingApplications = MembershipApplication::query()
            ->where('organization_id', $organization->id)
            ->where('status', MembershipApplication::STATUS_PENDING)
            ->with('user')
            ->oldest()
            ->get();

        $upcoming = $organization->events()->published()->upcoming()->withCount('registrations')->get();
        $nextEvent = $upcoming->first();

        $rates = $attendance->ratesForOrganizations(collect([$organization]))[$organization->id];

        $recentMembers = $organization->activeMemberships()
            ->with('user')
            ->latest('joined_at')
            ->limit(6)
            ->get();
        $memberRates = $attendance->memberRates($organization, $recentMembers->pluck('user_id')->all());

        $pendingExcuses = ExcuseRequest::query()
            ->where('status', ExcuseRequest::STATUS_PENDING)
            ->whereHas('event', fn ($q) => $q->where('organization_id', $organization->id))
            ->count();

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'acronym' => $organization->acronym,
                'slug' => $organization->slug,
                'color' => $organization->color,
                'status' => $organization->status,
            ],
            'stats' => [
                'members' => $organization->members_count,
                'new_members' => $organization->new_members_count,
                'pending_applications' => $pendingApplications->count(),
                'stale_applications' => $pendingApplications->filter(fn ($a) => $a->created_at->lt(now()->subDays(3)))->count(),
                'upcoming_events' => $upcoming->count(),
                'average_attendance' => $rates['rate'],
                'completed_events' => $rates['completed_events'],
                'pending_excuses' => $pendingExcuses,
            ],
            'pending_applications' => ApplicationResource::collection($pendingApplications->take(4)),
            'next_event' => $nextEvent ? new EventResource($nextEvent) : null,
            'recent_members' => $recentMembers->map(fn (Membership $m) => [
                'membership_id' => $m->id,
                'user' => [
                    'id' => $m->user->id,
                    'name' => $m->user->name,
                    'initials' => $m->user->initials,
                    'id_number' => $m->user->id_number,
                ],
                'position' => $m->position,
                'attendance_rate' => $memberRates[$m->user_id]['rate'],
                'status' => ($memberRates[$m->user_id]['rate'] ?? 100) < $threshold ? 'at_risk' : 'active',
            ])->values(),
            'checklist' => $this->checklist($organization, $pendingApplications->count(), $pendingExcuses, $nextEvent),
        ]);
    }

    /** Weekly to-do list built from the organization's real data. */
    private function checklist(Organization $organization, int $pendingApplications, int $pendingExcuses, ?Event $nextEvent): array
    {
        $items = [];

        $items[] = [
            'label' => $pendingApplications
                ? "Review {$pendingApplications} membership ".str('application')->plural($pendingApplications)
                : 'Membership applications are all reviewed',
            'done' => $pendingApplications === 0,
            'link' => '/officer/applications',
        ];

        $items[] = [
            'label' => $pendingExcuses
                ? "Review {$pendingExcuses} excuse ".str('request')->plural($pendingExcuses)
                : 'No excuse requests waiting',
            'done' => $pendingExcuses === 0,
            'link' => '/officer/excuses',
        ];

        $returned = $organization->events()->where('status', Event::STATUS_REJECTED)->limit(2)->get();
        foreach ($returned as $event) {
            $items[] = ['label' => "Revise \"{$event->title}\" (returned by OSA)", 'done' => false, 'link' => "/officer/events/{$event->id}/edit"];
        }

        $drafts = $organization->events()->where('status', Event::STATUS_DRAFT)->limit(2)->get();
        foreach ($drafts as $event) {
            $items[] = ['label' => "Submit \"{$event->title}\" for approval", 'done' => false, 'link' => "/officer/events/{$event->id}/edit"];
        }

        if ($nextEvent && $nextEvent->starts_at->lte(now()->addDays(7))) {
            $items[] = [
                'label' => "Open check-in for \"{$nextEvent->title}\" on ".$nextEvent->starts_at->format('M j'),
                'done' => $nextEvent->check_in_opened_at !== null,
                'link' => "/officer/events/{$nextEvent->id}/attendance",
            ];
        }

        $postedThisWeek = $organization->announcements()->where('published_at', '>=', now()->subDays(7))->exists();
        $items[] = [
            'label' => 'Post an announcement for members this week',
            'done' => $postedThisWeek,
            'link' => '/officer/announcements',
        ];

        return $items;
    }
}
