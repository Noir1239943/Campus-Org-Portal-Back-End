<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Campus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Log in with a school ID number or email address. */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->whereLogin((string) $request->input('login'))->first();

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'login' => 'The ID number/email or password is incorrect.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $device = Str::limit((string) $request->userAgent(), 80, '') ?: 'web';
        $expiresAt = $request->boolean('remember') ? now()->addDays(30) : now()->addMinutes((int) config('sanctum.token_minutes'));

        $token = $user->createToken($device, ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token' => $token,
            ...$this->profile($user),
        ]);
    }

    /** Self-service sign-up for students. Advisers/OSA accounts are created by the OSA. */
    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'id_number' => strtoupper(trim((string) $request->input('id_number'))),
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'id_number' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9\-]+$/', 'unique:users,id_number'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'program' => ['required', 'string', 'max:20'],
            'year_level' => ['required', 'integer', 'between:1,6'],
            'section' => ['required', 'string', 'max:10'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        User::create([
            ...$data,
            'role' => User::ROLE_STUDENT,
            'program' => strtoupper($data['program']),
            'section' => strtoupper($data['section']),
        ]);

        return response()->json(['message' => 'Account created. You can now sign in.'], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]*$/'],
        ]);

        $request->user()->update($data);

        return response()->json($this->profile($request->user()->fresh()));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => (string) $request->input('password')]);

        // Sign out every other device.
        $currentId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();

        return response()->json(['message' => 'Password updated. Other devices have been signed out.']);
    }

    /**
     * Students can't reset passwords by email (the school doesn't run a mail
     * server here), so the request goes to the Office of Student Affairs.
     */
    public function forgotPassword(Request $request, NotificationService $notifications): JsonResponse
    {
        $request->validate(['login' => ['required', 'string', 'max:255']]);

        $user = User::query()->whereLogin((string) $request->input('login'))->first();

        if ($user && $user->isStudent()) {
            $notifications->toAdvisers(
                'password_reset',
                'Password reset requested',
                "{$user->name} ({$user->id_number}) can't log in and asked for a password reset.",
                '/adviser/students?search='.urlencode($user->id_number)
            );
        }

        // Same answer whether or not the account exists.
        return response()->json([
            'message' => 'If that account exists, the Office of Student Affairs has been notified. Visit the OSA with your school ID to receive a temporary password.',
        ]);
    }

    public function meta(): JsonResponse
    {
        return response()->json(Campus::meta());
    }

    private function profile(User $user): array
    {
        $officerOrganizations = $user->officerMemberships()
            ->with('organization:id,slug,name,acronym,color')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->organization->id,
                'slug' => $m->organization->slug,
                'name' => $m->organization->name,
                'acronym' => $m->organization->acronym,
                'color' => $m->organization->color,
                'position' => $m->position,
            ])
            ->values();

        $workspaces = $user->isAdviser()
            ? ['adviser']
            : array_values(array_filter(['student', $officerOrganizations->isNotEmpty() ? 'officer' : null]));

        return [
            'user' => new UserResource($user),
            'workspaces' => $workspaces,
            'officer_organizations' => $officerOrganizations,
            'campus' => Campus::meta(),
        ];
    }
}
