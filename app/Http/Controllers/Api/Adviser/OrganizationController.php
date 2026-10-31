<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveOrganizationRequest;
use App\Http\Resources\EventResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use App\Services\OrganizationHealthService;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $history = $this->history($organization);

        return response()->json([
            'organization' => new OrganizationResource($organization->loadCount('activeMemberships as members_count')),
            'health' => $row,
            'can_delete' => $history['events'] === 0 && $history['attendance'] === 0,
            'history' => $history,
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

    /**
     * Permanently delete an organization that was never used. Anything with
     * event or attendance history must be archived instead so records are kept.
     */
    public function destroy(Request $request, Organization $organization, NotificationService $notifications): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);

        if (strcasecmp(trim((string) $request->input('confirm')), $organization->acronym) !== 0) {
            throw ValidationException::withMessages(['confirm' => "Type {$organization->acronym} to confirm."]);
        }

        $history = $this->history($organization);

        if ($history['events'] > 0 || $history['attendance'] > 0) {
            return response()->json([
                'message' => 'This organization has event and attendance history. Archive it instead so student records are kept.',
            ], 422);
        }

        DB::transaction(function () use ($organization, $notifications) {
            $notifications->toMembers(
                $organization,
                'organization_removed',
                "{$organization->name} was removed by the Office of Student Affairs"
            );

            $organization->delete();
        });

        return response()->json(['message' => "{$organization->name} was deleted."]);
    }

    /** Published/cancelled events and attendance records that block a permanent delete. */
    private function history(Organization $organization): array
    {
        $events = $organization->events()
            ->whereIn('status', [Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED])
            ->count();

        $attendance = Attendance::query()
            ->whereIn('event_id', $organization->events()->select('id'))
            ->count();

        return ['events' => $events, 'attendance' => $attendance];
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
