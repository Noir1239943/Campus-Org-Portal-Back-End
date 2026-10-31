<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Attendance & Clearance" — the page students check before end-of-term
 * clearance signing instead of chasing officers for paper records.
 */
class ClearanceController extends Controller
{
    public function __invoke(Request $request, AttendanceService $attendance): JsonResponse
    {
        $user = $request->user();
        $rows = Attendance::query()->where('user_id', $user->id)->get()->keyBy('event_id');

        $history = $attendance->relevantEndedEvents($user)->map(fn ($event) => [
            'id' => $event->id,
            'title' => $event->title,
            'type' => $event->type,
            'venue' => $event->venue,
            'starts_at' => $event->starts_at->toIso8601String(),
            'is_mandatory' => $event->is_mandatory,
            'service_hours' => $event->service_hours,
            'organization' => [
                'id' => $event->organization->id,
                'slug' => $event->organization->slug,
                'name' => $event->organization->name,
                'acronym' => $event->organization->acronym,
                'color' => $event->organization->color,
            ],
            'status' => $rows->get($event->id)?->status ?? Attendance::STATUS_ABSENT,
            'checked_in_at' => $rows->get($event->id)?->checked_in_at?->toIso8601String(),
        ]);

        return response()->json([
            'summary' => $attendance->studentSummary($user),
            'clearance' => $attendance->clearance($user),
            'history' => $history->values(),
        ]);
    }
}
