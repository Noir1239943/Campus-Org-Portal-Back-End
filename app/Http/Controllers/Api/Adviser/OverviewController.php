<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\Organization;
use App\Services\AttendanceService;
use App\Services\OrganizationHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/** Student Affairs oversight dashboard. */
class OverviewController extends Controller
{
    public function __invoke(OrganizationHealthService $health, AttendanceService $attendance): JsonResponse
    {
        $threshold = config('campus.attendance_threshold');
        $report = $health->report();

        $memberships = Membership::query()->where('status', Membership::STATUS_ACTIVE);
        $distinctStudents = (clone $memberships)->distinct()->count('user_id');

        // Events per month across the semester.
        $months = config('campus.semester_months');
        $year = now()->month >= min($months) ? now()->year : now()->year - 1;
        $published = Event::query()->published()->get(['id', 'starts_at', 'organization_id']);

        $activity = collect($months)->map(function (int $month) use ($published, $year) {
            $date = Carbon::create($year, $month, 1);

            return [
                'month' => $date->format('M'),
                'events' => $published->filter(fn ($e) => $e->starts_at->year === $year && $e->starts_at->month === $month)->count(),
                'is_current' => now()->year === $year && now()->month === $month,
            ];
        })->values();

        $rated = $report->whereNotNull('attendance_rate');
        $completed = Event::query()->published()->ended()->get();
        $eventStats = $attendance->statsForEvents($completed);
        $eventRates = collect($eventStats)->pluck('rate')->filter(fn ($r) => $r !== null);

        $alerts = collect();

        foreach ($report->where('health', 'at_risk') as $org) {
            $alerts->push([
                'level' => 'danger',
                'title' => $org['name'],
                'message' => "Average attendance is {$org['attendance_rate']}%, below the {$threshold}% threshold.",
                'link' => "/adviser/organizations/{$org['id']}",
            ]);
        }

        foreach ($report as $org) {
            $last = $org['last_event_at'] ? Carbon::parse($org['last_event_at']) : null;

            if ($org['upcoming_events_count'] === 0 && (! $last || $last->lt(now()->subDays(45)))) {
                $alerts->push([
                    'level' => 'warning',
                    'title' => $org['name'],
                    'message' => 'No events in the past 45 days and nothing scheduled.',
                    'link' => "/adviser/organizations/{$org['id']}",
                ]);
            }
        }

        $pendingEvents = Event::query()->where('status', Event::STATUS_PENDING)->count();
        if ($pendingEvents) {
            $alerts->prepend([
                'level' => 'info',
                'title' => 'Event approvals',
                'message' => "{$pendingEvents} event ".str('proposal')->plural($pendingEvents).' waiting for your review.',
                'link' => '/adviser/events',
            ]);
        }

        $staleApplications = MembershipApplication::query()
            ->where('status', MembershipApplication::STATUS_PENDING)
            ->where('created_at', '<', now()->subDays(7))
            ->count();
        if ($staleApplications) {
            $alerts->push([
                'level' => 'warning',
                'title' => 'Slow application reviews',
                'message' => "{$staleApplications} membership ".str('application')->plural($staleApplications).' have waited more than 7 days.',
                'link' => '/adviser/organizations',
            ]);
        }

        return response()->json([
            'stats' => [
                'organizations' => $report->count(),
                'organizations_good_standing' => $report->whereIn('health', ['healthy', 'no_data'])->count(),
                'organizations_under_review' => $report->whereIn('health', ['monitor', 'at_risk'])->count(),
                'memberships' => (clone $memberships)->count(),
                'students_involved' => $distinctStudents,
                'events_this_term' => $published->count(),
                'events_next_30_days' => $published->filter(fn ($e) => $e->starts_at->between(now(), now()->addDays(30)))->count(),
                'attendance_risks' => $report->where('health', 'at_risk')->count(),
                'pending_event_approvals' => $pendingEvents,
                'probation' => Organization::query()->where('status', Organization::STATUS_PROBATION)->count(),
            ],
            'activity' => $activity,
            'attendance' => [
                'average' => $eventRates->isNotEmpty() ? round($eventRates->avg(), 1) : null,
                'completed_events' => $completed->count(),
                'buckets' => [
                    ['label' => '90–100%', 'count' => $rated->where('attendance_rate', '>=', 90)->count(), 'tone' => 'success'],
                    ['label' => "{$threshold}–89%", 'count' => $rated->where('attendance_rate', '>=', $threshold)->where('attendance_rate', '<', 90)->count(), 'tone' => 'primary'],
                    ['label' => "Below {$threshold}%", 'count' => $rated->where('attendance_rate', '<', $threshold)->count(), 'tone' => 'warning'],
                ],
            ],
            'organizations' => $report->sortByDesc('members_count')->values(),
            'alerts' => $alerts->take(6)->values(),
        ]);
    }
}
