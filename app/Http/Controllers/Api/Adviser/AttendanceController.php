<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\Organization;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Attendance across all organizations, for spotting low-turnout events. */
class AttendanceController extends Controller
{
    public function __invoke(Request $request, AttendanceService $attendance): JsonResponse
    {
        $request->validate([
            'organization_id' => ['nullable', 'integer'],
            'below_threshold' => ['nullable', 'boolean'],
        ]);

        $threshold = config('campus.attendance_threshold');

        $events = Event::query()
            ->published()
            ->ended()
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->integer('organization_id')))
            ->with('organization')
            ->withCount('registrations')
            ->orderByDesc('starts_at')
            ->get();

        $stats = $attendance->statsForEvents($events);
        $events->each(fn ($e) => $e->setAttribute('stats', $stats[$e->id]));

        if ($request->boolean('below_threshold')) {
            $events = $events->filter(fn ($e) => $stats[$e->id]['rate'] !== null && $stats[$e->id]['rate'] < $threshold)->values();
        }

        $all = collect($stats);

        return response()->json([
            'summary' => [
                'events' => $all->count(),
                'expected' => $all->sum('expected'),
                'attended' => $all->sum('attended'),
                'excused' => $all->sum('excused'),
                'absent' => $all->sum('absent'),
                'average' => $all->pluck('rate')->filter(fn ($r) => $r !== null)->avg(),
                'below_threshold' => $all->filter(fn ($s) => $s['rate'] !== null && $s['rate'] < $threshold)->count(),
                'threshold' => $threshold,
            ],
            'events' => EventResource::collection($events),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name', 'acronym']),
        ]);
    }
}
