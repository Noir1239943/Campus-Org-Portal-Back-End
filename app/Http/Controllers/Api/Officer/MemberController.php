<?php

namespace App\Http\Controllers\Api\Officer;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use App\Services\OrganizationService;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberController extends Controller
{
    public function __construct(
        private AttendanceService $attendance,
        private NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:all,officers,at_risk'],
        ]);

        $organization = $this->organization($request);

        return response()->json($this->rows($organization, $request->input('search'), $request->input('filter', 'all')));
    }

    /** Add a student by ID number or email (e.g. moving an old paper roster online). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:60'],
        ]);

        $organization = $this->organization($request);
        $student = User::query()->students()->whereLogin($data['identifier'])->first();

        if (! $student) {
            throw ValidationException::withMessages(['identifier' => 'No student account matches that ID number or email.']);
        }

        if ($student->isMemberOf($organization->id)) {
            throw ValidationException::withMessages(['identifier' => "{$student->name} is already a member."]);
        }

        Membership::query()->updateOrCreate(
            ['user_id' => $student->id, 'organization_id' => $organization->id],
            [
                'status' => Membership::STATUS_ACTIVE,
                'position' => $data['position'] ?: 'Member',
                'is_officer' => false,
                'joined_at' => now(),
            ]
        );

        $this->notifications->send(
            $student,
            'member_added',
            "You were added to {$organization->acronym}",
            "An officer added you as a member of {$organization->name}.",
            "/organizations/{$organization->slug}"
        );

        return response()->json(['message' => "{$student->name} was added to the organization."], 201);
    }

    public function update(Request $request, Membership $membership): JsonResponse
    {
        $organization = $this->organization($request);
        abort_unless($membership->organization_id === $organization->id, 404);

        $data = $request->validate([
            'position' => ['required', 'string', 'max:60'],
            'is_officer' => ['required', 'boolean'],
        ]);

        if ($membership->user_id === $request->user()->id && ! $data['is_officer']) {
            throw ValidationException::withMessages(['is_officer' => 'You cannot remove your own officer access. Ask another officer or the OSA.']);
        }

        $targetIsPresident = $this->isPresident($membership);
        $keepsPresident = OrganizationService::isPresidentPosition($data['position']) && $data['is_officer'];

        if ($targetIsPresident && ! $keepsPresident) {
            throw ValidationException::withMessages(['position' => 'Only the Office of Student Affairs can change the President.']);
        }

        if (! $targetIsPresident && OrganizationService::isPresidentPosition($data['position'])) {
            throw ValidationException::withMessages(['position' => 'Only the Office of Student Affairs can assign the President.']);
        }

        $becomesOfficer = $data['is_officer'] && ! $membership->is_officer;

        $membership->update($data);

        if ($becomesOfficer) {
            $this->notifications->send(
                $membership->user_id,
                'officer_assigned',
                "You are now {$data['position']} of {$organization->acronym}",
                "An officer gave you officer access to {$organization->name}.",
                '/officer'
            );
        }

        return response()->json(['message' => 'Member updated.']);
    }

    public function destroy(Request $request, Membership $membership): JsonResponse
    {
        $organization = $this->organization($request);
        abort_unless($membership->organization_id === $organization->id, 404);

        if ($membership->user_id === $request->user()->id) {
            throw ValidationException::withMessages(['membership' => 'You cannot remove yourself from the organization here.']);
        }

        if ($this->isPresident($membership)) {
            throw ValidationException::withMessages(['membership' => 'Only the Office of Student Affairs can remove the President.']);
        }

        $membership->update(['status' => Membership::STATUS_INACTIVE, 'is_officer' => false]);

        $this->notifications->send(
            $membership->user_id,
            'member_removed',
            "Your {$organization->acronym} membership ended",
            "You are no longer listed as an active member of {$organization->name}. Contact the officers if this is a mistake."
        );

        return response()->json(['message' => 'Member removed.']);
    }

    public function export(Request $request): StreamedResponse
    {
        $organization = $this->organization($request);
        $rows = collect($this->rows($organization, null, 'all')['data'])->map(fn ($r) => [
            $r['user']['id_number'],
            $r['user']['name'],
            $r['user']['email'],
            $r['user']['program_label'],
            $r['position'],
            $r['is_officer'],
            $r['joined_at'] ? substr($r['joined_at'], 0, 10) : '',
            $r['attendance_rate'] !== null ? $r['attendance_rate'].'%' : 'No events yet',
        ]);

        return Csv::download(
            Str::slug($organization->acronym).'-members-'.now()->format('Y-m-d').'.csv',
            ['ID Number', 'Name', 'Email', 'Program', 'Position', 'Officer', 'Joined', 'Attendance'],
            $rows
        );
    }

    private function rows(Organization $organization, ?string $search, string $filter): array
    {
        $threshold = config('campus.member_risk_threshold');

        $memberships = $organization->activeMemberships()
            ->with('user')
            ->when($search, function ($q) use ($search) {
                $term = '%'.strtolower($search).'%';
                $q->whereHas('user', fn ($u) => $u->where(fn ($w) => $w
                    ->whereRaw('LOWER(first_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(id_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])));
            })
            ->get()
            ->sortBy([['is_officer', 'desc'], fn ($a, $b) => strcmp($a->user->last_name, $b->user->last_name)])
            ->values();

        $rates = $this->attendance->memberRates($organization, $memberships->pluck('user_id')->all());

        $rows = $memberships->map(function (Membership $m) use ($rates, $threshold) {
            $rate = $rates[$m->user_id]['rate'];

            return [
                'membership_id' => $m->id,
                'user' => [
                    'id' => $m->user->id,
                    'id_number' => $m->user->id_number,
                    'name' => $m->user->name,
                    'initials' => $m->user->initials,
                    'email' => $m->user->email,
                    'program_label' => $m->user->program_label,
                ],
                'position' => $m->position,
                'is_officer' => $m->is_officer,
                'is_president' => $this->isPresident($m),
                'joined_at' => $m->joined_at?->toIso8601String(),
                'events_expected' => $rates[$m->user_id]['expected'],
                'events_attended' => $rates[$m->user_id]['attended'],
                'attendance_rate' => $rate,
                'status' => $rate !== null && $rate < $threshold ? 'at_risk' : 'active',
            ];
        });

        if ($filter === 'officers') {
            $rows = $rows->where('is_officer', true);
        } elseif ($filter === 'at_risk') {
            $rows = $rows->where('status', 'at_risk');
        }

        return [
            'data' => $rows->values(),
            'counts' => [
                'all' => $memberships->count(),
                'officers' => $memberships->where('is_officer', true)->count(),
                'at_risk' => collect($rates)->filter(fn ($r) => $r['rate'] !== null && $r['rate'] < $threshold)->count(),
            ],
        ];
    }

    private function isPresident(Membership $membership): bool
    {
        return $membership->is_officer && OrganizationService::isPresidentPosition($membership->position);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
