<?php

namespace App\Http\Controllers\Api\Officer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRequest;
use App\Http\Resources\ExcuseResource;
use App\Models\Attendance;
use App\Models\ExcuseRequest;
use App\Models\Organization;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExcuseController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])]]);

        $organization = $this->organization($request);
        $status = $request->input('status', 'pending');

        $base = ExcuseRequest::query()->whereHas('event', fn ($q) => $q->where('organization_id', $organization->id));

        $excuses = (clone $base)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['event.organization', 'user', 'reviewer'])
            ->latest()
            ->get();

        return response()->json([
            'data' => ExcuseResource::collection($excuses),
            'counts' => (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function approve(ReviewRequest $request, ExcuseRequest $excuse): JsonResponse
    {
        $organization = $this->guard($request, $excuse);

        DB::transaction(function () use ($excuse, $request) {
            $excuse->update([
                'status' => ExcuseRequest::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $request->input('note'),
            ]);

            Attendance::query()->updateOrCreate(
                ['event_id' => $excuse->event_id, 'user_id' => $excuse->user_id],
                ['status' => Attendance::STATUS_EXCUSED, 'method' => 'excuse', 'checked_in_at' => null, 'recorded_by' => $request->user()->id]
            );
        });

        $this->notifications->send(
            $excuse->user_id,
            'excuse_approved',
            'Excuse approved',
            "Your excuse for \"{$excuse->event->title}\" was approved by {$organization->acronym}. It won't count against your clearance.",
            '/clearance'
        );

        return response()->json(new ExcuseResource($excuse->fresh(['event.organization', 'user', 'reviewer'])));
    }

    public function reject(ReviewRequest $request, ExcuseRequest $excuse): JsonResponse
    {
        $organization = $this->guard($request, $excuse);

        $excuse->update([
            'status' => ExcuseRequest::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('note'),
        ]);

        $this->notifications->send(
            $excuse->user_id,
            'excuse_rejected',
            'Excuse not approved',
            "Your excuse for \"{$excuse->event->title}\" was not approved by {$organization->acronym}.".($request->input('note') ? ' Note: '.$request->input('note') : ''),
            '/clearance'
        );

        return response()->json(new ExcuseResource($excuse->fresh(['event.organization', 'user', 'reviewer'])));
    }

    private function guard(Request $request, ExcuseRequest $excuse): Organization
    {
        $organization = $this->organization($request);
        abort_unless($excuse->event->organization_id === $organization->id, 404);

        if ($excuse->status !== ExcuseRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['excuse' => 'This excuse has already been reviewed.']);
        }

        return $organization;
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
