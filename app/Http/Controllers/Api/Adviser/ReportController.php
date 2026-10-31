<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Membership;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\OrganizationHealthService;
use App\Support\Csv;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV exports that open in Excel / Google Sheets for OSA reports. */
class ReportController extends Controller
{
    public function organizations(OrganizationHealthService $health): StreamedResponse
    {
        $rows = $health->report()->map(fn ($o) => [
            $o['name'],
            $o['acronym'],
            $o['category'],
            Str::headline($o['status']),
            $o['adviser_name'],
            $o['members_count'],
            $o['events_count'],
            $o['completed_events'],
            $o['attendance_rate'] !== null ? $o['attendance_rate'].'%' : '',
            Str::headline($o['health']),
        ]);

        return Csv::download(
            'organizations-report-'.now()->format('Y-m-d').'.csv',
            ['Organization', 'Acronym', 'Category', 'Status', 'Adviser', 'Members', 'Published events', 'Completed events', 'Avg attendance', 'Health'],
            $rows
        );
    }

    public function attendance(AttendanceService $attendance): StreamedResponse
    {
        $events = Event::query()->published()->ended()->with('organization')->orderBy('starts_at')->get();
        $stats = $attendance->statsForEvents($events);

        $rows = $events->map(fn (Event $e) => [
            $e->starts_at->format('Y-m-d'),
            $e->organization->acronym,
            $e->title,
            $e->type,
            $e->venue,
            $e->is_mandatory,
            $stats[$e->id]['expected'],
            $stats[$e->id]['present'],
            $stats[$e->id]['late'],
            $stats[$e->id]['excused'],
            $stats[$e->id]['absent'],
            $stats[$e->id]['rate'] !== null ? $stats[$e->id]['rate'].'%' : '',
        ]);

        return Csv::download(
            'event-attendance-'.now()->format('Y-m-d').'.csv',
            ['Date', 'Organization', 'Event', 'Type', 'Venue', 'Mandatory', 'Expected', 'Present', 'Late', 'Excused', 'Absent', 'Rate'],
            $rows
        );
    }

    public function memberships(): StreamedResponse
    {
        $rows = Membership::query()
            ->where('status', Membership::STATUS_ACTIVE)
            ->with(['user', 'organization'])
            ->get()
            ->sortBy(fn ($m) => $m->organization->name.' '.$m->user->last_name)
            ->map(fn (Membership $m) => [
                $m->organization->acronym,
                $m->user->id_number,
                $m->user->name,
                $m->user->program_label,
                $m->position,
                $m->is_officer,
                $m->joined_at?->format('Y-m-d'),
            ]);

        return Csv::download(
            'membership-roster-'.now()->format('Y-m-d').'.csv',
            ['Organization', 'ID Number', 'Name', 'Program', 'Position', 'Officer', 'Joined'],
            $rows
        );
    }

    /** One row per student: attendance rate, service hours and clearance — useful at clearance time. */
    public function participation(AttendanceService $attendance): StreamedResponse
    {
        $students = User::query()->students()->orderBy('last_name')->get();

        $rows = $students->map(function (User $u) use ($attendance) {
            $summary = $attendance->studentSummary($u);
            $clearance = $attendance->clearance($u);

            return [
                $u->id_number,
                $u->name,
                $u->program_label,
                $clearance['total'],
                $summary['attended'],
                $summary['missed'],
                $summary['excused'],
                $summary['rate'] !== null ? $summary['rate'].'%' : '',
                $summary['service_hours'],
                $clearance['overall'] === 'cleared' ? 'Cleared' : 'Pending',
            ];
        });

        return Csv::download(
            'student-participation-'.now()->format('Y-m-d').'.csv',
            ['ID Number', 'Name', 'Program', 'Organizations', 'Events attended', 'Missed', 'Excused', 'Attendance rate', 'Service hours', 'Clearance'],
            $rows
        );
    }
}
