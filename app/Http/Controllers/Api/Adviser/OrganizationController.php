<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveOrganizationRequest;
use App\Http\Resources\EventResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use App\Services\OrganizationHealthService;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function __construct(private OrganizationService $service) {}

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

        $events = $organization->events()->published()->ended()->orderByDesc('starts_at')->limit(10)->get();
        $stats = $attendance->statsForEvents($events);
        $events->each(fn ($e) => $e->setAttribute('stats', $stats[$e->id]));

        $upcoming = $organization->events()->whereIn('status', ['published', 'pending'])->upcoming()->withCount('registrations')->limit(5)->get();

        return response()->json([
            'organization' => new OrganizationResource($organization->loadCount('activeMemberships as members_count')),
            'health' => $row,
            'officers' => $this->officers($organization),
            'recent_events' => EventResource::collection($events),
            'upcoming_events' => EventResource::collection($upcoming),
        ]);
    }

    /** Create an organization; optionally make a student its President. */
    public function store(SaveOrganizationRequest $request): JsonResponse
    {
        $president = $this->findPresident($request);

        $organization = $this->service->create($request->organizationData(), $president);

        return response()->json([
            'message' => "{$organization->name} was created.",
            'organization' => new OrganizationResource($organization->loadCount('activeMemberships as members_count')),
        ], 201);
    }

    /** Edit the official details (name, acronym, category, adviser...). */
    public function update(SaveOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $data = $request->organizationData();

        if ($data['name'] !== $organization->name) {
            $data['slug'] = $this->service->uniqueSlug($data['name'], $organization->id);
        }

        $data['acronym'] = strtoupper($data['acronym']);
        $organization->update($data);

        return response()->json(new OrganizationResource($organization->loadCount('activeMemberships as members_count')));
    }

    /** Make a student an officer (or the President) of any organization. */
    public function addOfficer(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:60'],
        ]);

        $student = User::query()->students()->whereLogin($data['identifier'])->first();

        if (! $student) {
            throw ValidationException::withMessages(['identifier' => 'No student account matches that ID number or email.']);
        }

        $this->service->assignOfficer($organization, $student, $data['position']);

        return response()->json([
            'message' => "{$student->name} is now {$data['position']}.",
            'officers' => $this->officers($organization),
        ]);
    }

    public function updateOfficer(Request $request, Organization $organization, Membership $membership): JsonResponse
    {
        abort_unless($membership->organization_id === $organization->id, 404);

        $data = $request->validate([
            'position' => ['required', 'string', 'max:60'],
            'is_officer' => ['required', 'boolean'],
        ]);

        $this->service->updateOfficer($membership, $data['position'], (bool) $data['is_officer']);

        return response()->json([
            'message' => 'Officer updated.',
            'officers' => $this->officers($organization),
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

    /** Officers with the President first. */
    private function officers(Organization $organization): array
    {
        return $organization->officerMemberships()->with('user')->get()
            ->map(fn (Membership $m) => [
                'membership_id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user->name,
                'initials' => $m->user->initials,
                'id_number' => $m->user->id_number,
                'email' => $m->user->email,
                'program_label' => $m->user->program_label,
                'position' => $m->position,
                'is_president' => OrganizationService::isPresidentPosition($m->position),
            ])
            ->sortBy([['is_president', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    private function findPresident(SaveOrganizationRequest $request): ?User
    {
        $identifier = trim((string) $request->input('president'));

        if ($identifier === '') {
            return null;
        }

        $student = User::query()->students()->whereLogin($identifier)->first();

        if (! $student) {
            throw ValidationException::withMessages(['president' => 'No student account matches that ID number or email.']);
        }

        return $student;
    }
}
