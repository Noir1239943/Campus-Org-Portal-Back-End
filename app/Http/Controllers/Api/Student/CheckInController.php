<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Attendance;
use App\Models\Event;
use App\Services\AttendanceService;
use App\Services\CheckInCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Students check in by scanning the event QR (which opens /check-in?event=..&code=..)
 * or by typing the 6-character code shown on the officer's screen.
 */
class CheckInController extends Controller
{
    public function store(Request $request, CheckInCodeService $codes, AttendanceService $attendance): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:12'],
            'event_id' => ['nullable', 'integer'],
            'via' => ['nullable', 'in:qr,code'],
        ]);

        $user = $request->user();
        $code = CheckInCodeService::normalize((string) $request->input('code'));

        $candidates = Event::query()
            ->published()
            ->whereNotNull('check_in_opened_at')
            ->whereNull('check_in_closed_at')
            ->when($request->filled('event_id'), fn ($q) => $q->where('id', $request->integer('event_id')))
            ->with('organization')
            ->get();

        $event = $candidates->first(fn (Event $e) => $codes->verify($e, $code));

        if (! $event) {
            throw ValidationException::withMessages([
                'code' => 'That code is invalid or has expired. Codes change every minute — ask the officer for the current one or scan the QR again.',
            ]);
        }

        if ($event->visibility === Event::VISIBILITY_MEMBERS && ! $user->isMemberOf($event->organization_id)) {
            throw ValidationException::withMessages([
                'code' => "This event is for {$event->organization->acronym} members only.",
            ]);
        }

        $existing = Attendance::query()->where('event_id', $event->id)->where('user_id', $user->id)->first();
        $alreadyCheckedIn = $existing?->attended() ?? false;

        if (! $alreadyCheckedIn) {
            $late = now()->gt($event->starts_at->copy()->addMinutes(config('campus.late_after_minutes')));

            $existing = Attendance::query()->updateOrCreate(
                ['event_id' => $event->id, 'user_id' => $user->id],
                [
                    'status' => $late ? Attendance::STATUS_LATE : Attendance::STATUS_PRESENT,
                    'method' => $request->input('via', 'code'),
                    'checked_in_at' => now(),
                    'recorded_by' => null,
                ]
            );
        }

        return response()->json([
            'already_checked_in' => $alreadyCheckedIn,
            'attendance' => [
                'status' => $existing->status,
                'method' => $existing->method,
                'checked_in_at' => $existing->checked_in_at?->toIso8601String(),
                'reference' => sprintf('ATD-%d-%05d', $event->id, $existing->id),
            ],
            'event' => EventResource::brief($event),
            'summary' => $attendance->studentSummary($user),
        ]);
    }
}
