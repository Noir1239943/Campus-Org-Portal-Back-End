<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationProposalRequest;
use App\Http\Resources\ProposalResource;
use App\Models\OrganizationProposal;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Students cannot create organizations directly; they propose one to the OSA. */
class ProposalController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $proposals = OrganizationProposal::query()
            ->where('user_id', $request->user()->id)
            ->with(['reviewer', 'organization'])
            ->latest()
            ->get();

        return response()->json(ProposalResource::collection($proposals));
    }

    public function store(OrganizationProposalRequest $request): JsonResponse
    {
        $user = $request->user();

        $hasPending = OrganizationProposal::query()
            ->where('user_id', $user->id)
            ->where('status', OrganizationProposal::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages(['name' => 'You already have a proposal waiting for review. Withdraw it first to submit another.']);
        }

        $proposal = OrganizationProposal::query()->create([
            ...$request->validated(),
            'user_id' => $user->id,
            'status' => OrganizationProposal::STATUS_PENDING,
        ]);

        $this->notifications->toAdvisers(
            'proposal_submitted',
            'New organization proposal',
            "{$user->name} ({$user->program_label}) proposed \"{$proposal->name}\".",
            '/adviser/proposals'
        );

        return response()->json(new ProposalResource($proposal->load(['reviewer', 'organization'])), 201);
    }

    public function withdraw(Request $request, OrganizationProposal $proposal): JsonResponse
    {
        abort_unless($proposal->user_id === $request->user()->id, 404);

        if (! $proposal->isPending()) {
            throw ValidationException::withMessages(['proposal' => 'Only pending proposals can be withdrawn.']);
        }

        $proposal->update(['status' => OrganizationProposal::STATUS_WITHDRAWN]);

        return response()->json(new ProposalResource($proposal->load(['reviewer', 'organization'])));
    }
}
