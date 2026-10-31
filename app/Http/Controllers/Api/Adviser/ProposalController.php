<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRequest;
use App\Http\Resources\ProposalResource;
use App\Models\Organization;
use App\Models\OrganizationProposal;
use App\Services\NotificationService;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** OSA review of student proposals to start a new organization. */
class ProposalController extends Controller
{
    public function __construct(
        private OrganizationService $organizations,
        private NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,withdrawn,all']]);

        $status = $request->input('status', 'pending');

        $proposals = OrganizationProposal::query()
            ->with(['user', 'reviewer', 'organization'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("status = 'pending' desc")
            ->latest()
            ->get();

        $counts = OrganizationProposal::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => ProposalResource::collection($proposals),
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'all' => (int) $counts->sum(),
            ],
        ]);
    }

    /** Create the organization; the proposer becomes its President. */
    public function approve(ReviewRequest $request, OrganizationProposal $proposal): JsonResponse
    {
        $this->ensurePending($proposal);

        if (Organization::query()->whereRaw('LOWER(name) = ?', [strtolower($proposal->name)])->exists()) {
            throw ValidationException::withMessages(['proposal' => 'An organization with this name already exists.']);
        }

        if (Organization::query()->where('acronym', strtoupper($proposal->acronym))->exists()) {
            throw ValidationException::withMessages(['proposal' => 'That acronym is already used by another organization.']);
        }

        $about = trim($proposal->purpose.($proposal->planned_activities ? "\n\nPlanned activities\n".$proposal->planned_activities : ''));

        $organization = $this->organizations->create([
            'name' => $proposal->name,
            'acronym' => $proposal->acronym,
            'category' => $proposal->category,
            'description' => $proposal->description,
            'about' => $about,
            'adviser_name' => $proposal->adviser_name,
        ], $proposal->user);

        $proposal->update([
            'status' => OrganizationProposal::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('note'),
            'organization_id' => $organization->id,
        ]);

        $this->notifications->send(
            $proposal->user_id,
            'proposal_approved',
            "{$proposal->name} was approved",
            $request->input('note') ?: "You are now President of {$organization->acronym}. Set up your organization profile to get started.",
            '/officer/organization'
        );

        return response()->json(new ProposalResource($proposal->load(['user', 'reviewer', 'organization'])));
    }

    public function reject(Request $request, OrganizationProposal $proposal): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']]);

        $this->ensurePending($proposal);

        $proposal->update([
            'status' => OrganizationProposal::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['note'],
        ]);

        $this->notifications->send(
            $proposal->user_id,
            'proposal_rejected',
            "{$proposal->name} was returned",
            $data['note'],
            '/applications?tab=proposals'
        );

        return response()->json(new ProposalResource($proposal->load(['user', 'reviewer', 'organization'])));
    }

    private function ensurePending(OrganizationProposal $proposal): void
    {
        if (! $proposal->isPending()) {
            throw ValidationException::withMessages(['proposal' => 'This proposal has already been reviewed.']);
        }
    }
}
