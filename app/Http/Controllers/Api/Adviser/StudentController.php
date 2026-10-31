<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Student lookup: participation summary and password resets (forgot password flow). */
class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $students = User::query()
            ->students()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.strtolower((string) $request->input('search')).'%';
                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(first_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(id_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            })
            ->withCount(['memberships as organizations_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('last_name')
            ->limit(50)
            ->get();

        return response()->json($students->map(fn (User $u) => [
            ...(new UserResource($u))->resolve(),
            'organizations_count' => $u->organizations_count,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
        ]));
    }

    public function show(User $user, AttendanceService $attendance): JsonResponse
    {
        abort_unless($user->isStudent(), 404);

        return response()->json([
            'student' => new UserResource($user),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'summary' => $attendance->studentSummary($user),
            'clearance' => $attendance->clearance($user),
        ]);
    }

    /** Generates a one-time temporary password for the student to change after logging in. */
    public function resetPassword(User $user, NotificationService $notifications): JsonResponse
    {
        abort_unless($user->isStudent(), 404);

        $temporary = 'CPC-'.Str::upper(Str::random(4)).'-'.random_int(1000, 9999);

        $user->update(['password' => $temporary, 'must_change_password' => true]);
        $user->tokens()->delete();

        $notifications->send(
            $user,
            'password_reset',
            'Your password was reset',
            'The Office of Student Affairs reset your password. Please change it from your profile page now.',
            '/profile'
        );

        return response()->json([
            'message' => "Temporary password created for {$user->name}. Give it to the student in person.",
            'temporary_password' => $temporary,
        ]);
    }
}
