<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\MembershipApplication;
use App\Models\Organization;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $applications = MembershipApplication::query()
            ->where('user_id', $request->user()->id)
            ->with(['organization', 'reviewer'])
            ->latest()
            ->get();

        return response()->json(ApplicationResource::collection($applications));
    }

    public function show(Request $request, MembershipApplication $application): JsonResponse
    {
        abort_unless($application->user_id === $request->user()->id, 404);

        $application->load(['organization', 'reviewer', 'user']);

        return response()->json(new ApplicationResource($application));
    }

    public function store(StoreApplicationRequest $request, Organization $organization): JsonResponse
    {
        $user = $request->user();

        if ($user->isMemberOf($organization->id)) {
            throw ValidationException::withMessages(['organization' => 'You are already a member of this organization.']);
        }

        if (! $organization->isOpenForApplications()) {
            throw ValidationException::withMessages(['organization' => 'This organization is not accepting applications right now.']);
        }

        $hasPending = MembershipApplication::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->where('status', MembershipApplication::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages(['organization' => 'You already have a pending application for this organization.']);
        }

        $application = MembershipApplication::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'reason' => $request->input('reason'),
            'experience' => $request->input('experience'),
            'availability' => $request->input('availability'),
            'answer' => $request->input('answer'),
            // Enrolled student accounts are verified automatically.
            'eligibility_verified_at' => now(),
        ]);

        $this->notifications->toOfficers(
            $organization,
            'application_submitted',
            'New membership application',
            "{$user->name} ({$user->program_label}) applied to join {$organization->acronym}.",
            '/officer/applications'
        );

        $application->load(['organization', 'user']);

        return response()->json(new ApplicationResource($application), 201);
    }

    public function withdraw(Request $request, MembershipApplication $application): JsonResponse
    {
        abort_unless($application->user_id === $request->user()->id, 404);

        if (! $application->isPending()) {
            throw ValidationException::withMessages(['application' => 'Only pending applications can be withdrawn.']);
        }

        $application->update(['status' => MembershipApplication::STATUS_WITHDRAWN]);
        $application->load(['organization', 'reviewer']);

        return response()->json(new ApplicationResource($application));
    }
}
