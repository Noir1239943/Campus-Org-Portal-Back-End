<?php

namespace App\Http\Controllers\Api\Officer;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\CheckInCodeService;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Live attendance: QR / code check-in, roster, walk-ins and manual corrections. */
class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceService $attendance,
        private CheckInCodeService $codes,
    ) {}

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->guard($request, $event);

        $roster = $this->roster($event);
        $stats = $this->attendance->eventStats($event);
        $event->load('organization')->loadCount('registrations');

        $recent = Attendance::query()
            ->where('event_id', $event->id)
            ->whereIn('status', Attendance::ATTENDED)
            ->with('user')
            ->latest('checked_in_at')
            ->limit(6)
            ->get()
            ->map(fn (Attendance $a) => [
                'name' => $a->user->name,
                'initials' => $a->user->initials,
                'method' => $a->method,
                'status' => $a->status,
                'checked_in_at' => $a->checked_in_at?->toIso8601String(),
            ]);

        return response()->json([
            'event' => new EventResource($event),
            'stats' => $stats,
            'check_in' => [
                'open' => $event->isCheckInOpen(),
                'opened_at' => $event->check_in_opened_at?->toIso8601String(),
                'closed_at' => $event->check_in_closed_at?->toIso8601String(),
                'late_after_minutes' => config('campus.late_after_minutes'),
            ],
            'roster' => $roster,
            'recent' => $recent,
        ]);
    }

    public function open(Request $request, Event $event): JsonResponse
    {
        $this->guard($request, $event);

        if (! $event->isPublished()) {
            throw ValidationException::withMessages(['event' => 'Check-in is only available for published events.']);
        }

        if ($event->ends_at->copy()->addHours(2)->isPast()) {
            throw ValidationException::withMessages(['event' => 'This event ended more than 2 hours ago. Use manual marking for corrections.']);
        }

        $this->codes->ensureSecret($event);
        $event->update([
            'check_in_opened_at' => $event->check_in_opened_at ?? now(),
            'check_in_closed_at' => null,
        ]);

        return $this->code($request, $event);
    }

    public function close(Request $request, Event $event): JsonResponse
    {
        $this->guard($request, $event);
        $event->update(['check_in_closed_at' => now()]);

        return response()->json(['message' => 'Check-in closed.', 'open' => false]);
    }

    /** Current rotating code + QR link. The officer screen polls this. */
    public function code(Request $request, Event $event): JsonResponse
    {
        $this->guard($request, $event);
        $event->refresh();

        if (! $event->isCheckInOpen()) {
            return response()->json(['open' => false]);
        }

        $code = $this->codes->codeFor($event);

        return response()->json([
            'open' => true,
            'code' => $code,
            'url' => $this->codes->checkInUrl($event, $code),
            'expires_in' => $this->codes->secondsRemaining(),
            'period' => $this->codes->period(),
        ]);
    }

    /**
     * Mark a student manually: walk-ins (by ID number), corrections,
     * or settling an absence as excused.
     */
    public function mark(Request $request, Event $event): JsonResponse
    {
        $this->guard($request, $event);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'identifier' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['present', 'late', 'excused', 'absent', 'clear'])],
        ]);

        $student = isset($data['user_id'])
            ? User::query()->students()->find($data['user_id'])
            : User::query()->students()->whereLogin($data['identifier'])->first();

        if (! $student) {
            throw ValidationException::withMessages(['identifier' => 'No student account matches that ID number or email.']);
        }

        if ($event->visibility === Event::VISIBILITY_MEMBERS && ! $student->isMemberOf($event->organization_id)) {
            throw ValidationException::withMessages(['identifier' => "{$student->name} is not a member of this organization."]);
        }

        if ($data['status'] === 'clear') {
            Attendance::query()->where('event_id', $event->id)->where('user_id', $student->id)->delete();

            return response()->json(['message' => "Attendance record cleared for {$student->name}."]);
        }

        $existing = Attendance::query()->where('event_id', $event->id)->where('user_id', $student->id)->first();
        $isWalkIn = ! $existing && ! isset($data['user_id']);

        Attendance::query()->updateOrCreate(
            ['event_id' => $event->id, 'user_id' => $student->id],
            [
                'status' => $data['status'],
                'method' => $isWalkIn ? 'walk_in' : 'manual',
                'checked_in_at' => in_array($data['status'], Attendance::ATTENDED, true)
                    ? ($existing?->checked_in_at ?? now())
                    : null,
                'recorded_by' => $request->user()->id,
            ]
        );

        return response()->json(['message' => "{$student->name} marked as {$data['status']}."]);
    }

    public function export(Request $request, Event $event): StreamedResponse
    {
        $this->guard($request, $event);

        $rows = collect($this->roster($event))->map(fn ($r) => [
            $r['user']['id_number'],
            $r['user']['name'],
            $r['user']['program_label'],
            $r['registered'],
            Str::headline($r['status']),
            $r['method'] ? Str::headline($r['method']) : '',
            $r['checked_in_at'] ? \Illuminate\Support\Carbon::parse($r['checked_in_at'])->setTimezone(config('app.timezone'))->format('Y-m-d g:i A') : '',
        ]);

        return Csv::download(
            Str::slug($event->title).'-attendance.csv',
            ['ID Number', 'Name', 'Program', 'Registered', 'Status', 'Method', 'Checked in'],
            $rows
        );
    }

    /** Everyone expected at the event plus walk-ins, with their status. */
    private function roster(Event $event): array
    {
        $userIds = $this->attendance->expectedUserIds($event);
        $registered = $event->registrations()->pluck('user_id')->flip();
        $rows = Attendance::query()->where('event_id', $event->id)->get()->keyBy('user_id');

        $users = User::query()->whereIn('id', $userIds ?: [0])->orderBy('last_name')->orderBy('first_name')->get();

        return $users->map(function (User $user) use ($registered, $rows, $event) {
            $row = $rows->get($user->id);
            $status = $row?->status ?? ($event->hasEnded() ? 'absent' : 'pending');

            return [
                'user' => [
                    'id' => $user->id,
                    'id_number' => $user->id_number,
                    'name' => $user->name,
                    'initials' => $user->initials,
                    'program_label' => $user->program_label,
                ],
                'registered' => $registered->has($user->id),
                'status' => $status,
                'method' => $row?->method,
                'checked_in_at' => $row?->checked_in_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    private function guard(Request $request, Event $event): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');
        abort_unless($event->organization_id === $organization->id, 404);

        return $organization;
    }
}
