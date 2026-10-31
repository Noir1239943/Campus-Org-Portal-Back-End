<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Membership;
use App\Models\Organization;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use App\Services\OrganizationHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index(Request $request, OrganizationHealthService $health): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'health' => ['nullable', Rule::in(['healthy', 'monitor', 'at_risk', 'no_data'])],
        ]);

        $report = $health->report(Organization::query()->orderBy('name')->get());

        if ($request->filled('search')) {
            $term = strtolower((string) $request->input('search'));
            $report = $report->filter(fn ($o) => str_contains(strtolower($o['name'].' '.$o['acronym'].' '.$o['category']), $term));
        }

        if ($request->filled('health')) {
            $report = $report->where('health', $request->input('health'));
        }

        return response()->json($report->values());
    }

    public function show(Organization $organization, OrganizationHealthService $health, AttendanceService $attendance): JsonResponse
    {
        $row = $health->report(collect([$organization]))->first();

        $officers = $organization->officerMemberships()->with('user')->get()->map(fn (Membership $m) => [
            'name' => $m->user->name,
            'initials' => $m->user->initials,
            'id_number' => $m->user->id_number,
            'email' => $m->user->email,
            'position' => $m->position,
        ]);

        $events = $organization->events()->published()->ended()->orderByDesc('starts_at')->limit(10)->get();
        $stats = $attendance->statsForEvents($events);
        $events->each(fn ($e) => $e->setAttribute('stats', $stats[$e->id]));

        $upcoming = $organization->events()->whereIn('status', ['published', 'pending'])->upcoming()->withCount('registrations')->limit(5)->get();

        return response()->json([
            'organization' => new OrganizationResource($organization->loadCount('activeMemberships as members_count')),
            'health' => $row,
            'officers' => $officers,
            'recent_events' => EventResource::collection($events),
            'upcoming_events' => EventResource::collection($upcoming),
        ]);
    }

    /** Change recognition status (active / probation / inactive). */
    public function updateStatus(Request $request, Organization $organization, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Organization::STATUS_ACTIVE, Organization::STATUS_PROBATION, Organization::STATUS_INACTIVE])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $organization->update(['status' => $data['status']]);

        $label = match ($data['status']) {
            'active' => 'in good standing',
            'probation' => 'on probation',
            'inactive' => 'inactive',
        };

        $notifications->toOfficers(
            $organization,
            'organization_status',
            "{$organization->acronym} is now {$label}",
            $data['note'] ?? 'The Office of Student Affairs updated your organization status.',
            '/officer'
        );

        return response()->json(['message' => "Status updated to {$label}."]);
    }
}
