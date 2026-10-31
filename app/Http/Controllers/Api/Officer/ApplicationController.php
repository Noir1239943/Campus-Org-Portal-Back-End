<?php

namespace App\Http\Controllers\Api\Officer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\Organization;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'withdrawn', 'all'])],
        ]);

        $organization = $this->organization($request);
        $status = $request->input('status', 'pending');

        $applications = MembershipApplication::query()
            ->where('organization_id', $organization->id)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['user', 'reviewer', 'organization'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->get();

        $counts = MembershipApplication::query()
            ->where('organization_id', $organization->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => ApplicationResource::collection($applications),
            'counts' => $counts,
        ]);
    }

    public function approve(ReviewRequest $request, MembershipApplication $application): JsonResponse
    {
        $organization = $this->organization($request);
        $this->guard($application, $organization);

        if ($organization->capacity && $organization->activeMemberships()->count() >= $organization->capacity) {
            throw ValidationException::withMessages(['application' => 'The organization has reached its member capacity.']);
        }

        DB::transaction(function () use ($application, $organization, $request) {
            $application->update([
                'status' => MembershipApplication::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $request->input('note'),
            ]);

            Membership::query()->updateOrCreate(
                ['user_id' => $application->user_id, 'organization_id' => $organization->id],
                ['status' => Membership::STATUS_ACTIVE, 'position' => 'Member', 'is_officer' => false, 'joined_at' => now()]
            );
        });

        $this->notifications->send(
            $application->user_id,
            'application_approved',
            "Welcome to {$organization->acronym}!",
            "Your application to {$organization->name} was approved.".($request->input('note') ? ' Note: '.$request->input('note') : ''),
            "/organizations/{$organization->slug}"
        );

        return response()->json(new ApplicationResource($application->fresh(['user', 'reviewer', 'organization'])));
    }

    public function reject(ReviewRequest $request, MembershipApplication $application): JsonResponse
    {
        $organization = $this->organization($request);
        $this->guard($application, $organization);

        $application->update([
            'status' => MembershipApplication::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('note'),
        ]);

        $this->notifications->send(
            $application->user_id,
            'application_rejected',
            "Update on your {$organization->acronym} application",
            "Your application to {$organization->name} was not approved this time.".($request->input('note') ? ' Note: '.$request->input('note') : ''),
            "/applications/{$application->id}"
        );

        return response()->json(new ApplicationResource($application->fresh(['user', 'reviewer', 'organization'])));
    }

    private function guard(MembershipApplication $application, Organization $organization): void
    {
        abort_unless($application->organization_id === $organization->id, 404);

        if (! $application->isPending()) {
            throw ValidationException::withMessages(['application' => 'This application has already been reviewed.']);
        }
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
