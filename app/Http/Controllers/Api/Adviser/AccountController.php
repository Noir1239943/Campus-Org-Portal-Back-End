<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OrganizationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The OSA creates and manages accounts (students and advisers).
 * Accounts are disabled, never deleted, so attendance and clearance history is kept.
 */
class AccountController extends Controller
{
    public const PROGRAMS = ['BSIT', 'BEEd', 'BSEd', 'BSBA', 'BSHM'];

    private const MAX_IMPORT_ROWS = 2000;

    private const IMPORT_COLUMNS = ['id_number', 'first_name', 'last_name', 'email', 'program', 'year_level', 'section'];

    public function __construct(
        private OrganizationService $organizations,
        private NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['all', 'student', 'adviser'])],
            'status' => ['nullable', Rule::in(['active', 'disabled', 'all'])],
        ]);

        $role = $request->input('role', 'all');
        $status = $request->input('status', 'active');

        $accounts = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.strtolower((string) $request->input('search')).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(first_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(first_name || \' \' || last_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(id_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            })
            ->when($role !== 'all', fn ($q) => $q->where('role', $role))
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->withCount(['memberships as organizations_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->through(fn (User $u) => $this->row($u));

        return response()->json($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $temporary = $this->temporaryPassword($request->input('password'));

        $user = DB::transaction(function () use ($data, $temporary) {
            $user = User::create([
                ...$data,
                'password' => $temporary,
                'must_change_password' => true,
                'is_active' => true,
            ]);

            if ($user->isStudent()) {
                $this->organizations->enrollInAllStudentOrganizations($user);
            }

            return $user;
        });

        return response()->json([
            'message' => "Account created for {$user->name}.",
            'account' => $this->row($user->loadCount(['memberships as organizations_count' => fn ($q) => $q->where('status', 'active')])),
            'temporary_password' => $temporary,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user);

        if ($user->id === $request->user()->id && $data['role'] !== $user->role) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }

        $becameStudent = $data['role'] === User::ROLE_STUDENT && $user->role !== User::ROLE_STUDENT;

        $user->update($data);

        if ($becameStudent) {
            $this->organizations->enrollInAllStudentOrganizations($user);
        }

        return response()->json([
            'message' => 'Account updated.',
            'account' => $this->row($user->loadCount(['memberships as organizations_count' => fn ($q) => $q->where('status', 'active')])),
        ]);
    }

    public function resetPassword(User $user): JsonResponse
    {
        $temporary = $this->temporaryPassword(null);

        $user->update(['password' => $temporary, 'must_change_password' => true]);
        $user->tokens()->delete();

        $this->notifications->send(
            $user,
            'password_reset',
            'Your password was reset',
            'The Office of Student Affairs reset your password. Please change it from your profile page now.',
            '/profile'
        );

        return response()->json([
            'message' => "Temporary password created for {$user->name}.",
            'temporary_password' => $temporary,
        ]);
    }

    public function disable(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['account' => 'You cannot disable your own account.']);
        }

        if ($user->isAdviser() && $user->is_active && ! $this->hasAnotherActiveAdviser($user)) {
            throw ValidationException::withMessages(['account' => 'You cannot disable the last active adviser account.']);
        }

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        return response()->json(['message' => "{$user->name}'s account was disabled."]);
    }

    public function enable(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        return response()->json(['message' => "{$user->name}'s account was enabled."]);
    }

    /** Create student accounts from a CSV file. Rows that already exist or fail validation are skipped. */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'temporary_password' => ['required', 'string', Password::min(8)],
        ]);

        $rows = $this->readCsv($request->file('file'));

        if (count($rows) > self::MAX_IMPORT_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has more than '.self::MAX_IMPORT_ROWS.' rows. Split it into smaller files.']);
        }

        $temporary = (string) $request->input('temporary_password');
        $created = 0;
        $skipped = [];
        $seenIds = [];
        $seenEmails = [];

        DB::transaction(function () use ($rows, $temporary, &$created, &$skipped, &$seenIds, &$seenEmails) {
            foreach ($rows as $line => $row) {
                $row['id_number'] = trim($row['id_number']);
                $row['email'] = strtolower(trim($row['email']));

                $validator = Validator::make($row, [
                    'id_number' => ['required', 'string', 'max:32'],
                    'first_name' => ['required', 'string', 'max:100'],
                    'last_name' => ['required', 'string', 'max:100'],
                    'email' => ['required', 'email', 'max:255'],
                    'program' => ['required', Rule::in(self::PROGRAMS)],
                    'year_level' => ['required', 'integer', 'between:1,4'],
                    'section' => ['nullable', 'string', 'max:20'],
                ]);

                if ($validator->fails()) {
                    $skipped[] = ['row' => $line, 'reason' => $validator->errors()->first()];

                    continue;
                }

                $idKey = strtoupper($row['id_number']);

                if (isset($seenIds[$idKey]) || $this->idNumberTaken($row['id_number'])) {
                    $skipped[] = ['row' => $line, 'reason' => "ID number {$row['id_number']} already exists."];

                    continue;
                }

                if (isset($seenEmails[$row['email']]) || User::query()->whereRaw('LOWER(email) = ?', [$row['email']])->exists()) {
                    $skipped[] = ['row' => $line, 'reason' => "Email {$row['email']} already exists."];

                    continue;
                }

                $student = User::create([
                    'id_number' => $row['id_number'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'email' => $row['email'],
                    'role' => User::ROLE_STUDENT,
                    'program' => $row['program'],
                    'year_level' => (int) $row['year_level'],
                    'section' => $row['section'] ?: null,
                    'password' => $temporary,
                    'must_change_password' => true,
                    'is_active' => true,
                ]);

                $this->organizations->enrollInAllStudentOrganizations($student);

                $seenIds[$idKey] = true;
                $seenEmails[$row['email']] = true;
                $created++;
            }
        });

        return response()->json(['created' => $created, 'skipped' => $skipped]);
    }

    /** @return array<int, array<string, string>> rows keyed by their line number in the file */
    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if (! $header) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $missing = array_diff(self::IMPORT_COLUMNS, $header);

        if ($missing) {
            throw ValidationException::withMessages(['file' => 'Missing column(s): '.implode(', ', $missing).'. The header row must be '.implode(',', self::IMPORT_COLUMNS).'.']);
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle)) !== false) {
            $line++;

            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }

            $row = [];
            foreach (self::IMPORT_COLUMNS as $column) {
                $index = array_search($column, $header, true);
                $row[$column] = trim((string) ($cells[$index] ?? ''));
            }

            $rows[$line] = $row;

            if (count($rows) > self::MAX_IMPORT_ROWS) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function validated(Request $request, ?User $current = null): array
    {
        $request->merge([
            'id_number' => trim((string) $request->input('id_number')),
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $isStudent = $request->input('role') === User::ROLE_STUDENT;

        $data = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_STUDENT, User::ROLE_ADVISER])],
            'id_number' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail) use ($current) {
                if ($this->idNumberTaken((string) $value, $current?->id)) {
                    $fail('This ID number is already used by another account.');
                }
            }],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($current?->id)],
            'program' => [$isStudent ? 'required' : 'nullable', Rule::in(self::PROGRAMS)],
            'year_level' => [$isStudent ? 'required' : 'nullable', 'integer', 'between:1,4'],
            'section' => ['nullable', 'string', 'max:20'],
            'title' => [$isStudent ? 'nullable' : 'required', 'string', 'max:80'],
            'password' => ['nullable', Password::min(8)],
        ]);

        unset($data['password']);

        // Each role only keeps its own fields.
        if ($isStudent) {
            $data['title'] = null;
        } else {
            $data['program'] = null;
            $data['year_level'] = null;
            $data['section'] = null;
        }

        return $data;
    }

    private function idNumberTaken(string $idNumber, ?int $ignoreId = null): bool
    {
        return User::query()
            ->whereRaw('UPPER(id_number) = ?', [strtoupper(trim($idNumber))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    private function hasAnotherActiveAdviser(User $except): bool
    {
        return User::query()
            ->where('role', User::ROLE_ADVISER)
            ->where('is_active', true)
            ->where('id', '!=', $except->id)
            ->exists();
    }

    private function temporaryPassword(?string $given): string
    {
        return $given ?: 'CPC-'.Str::upper(Str::random(4)).'-'.random_int(1000, 9999);
    }

    private function row(User $user): array
    {
        return [
            'id' => $user->id,
            'id_number' => $user->id_number,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role' => $user->role,
            'program' => $user->program,
            'year_level' => $user->year_level,
            'section' => $user->section,
            'program_label' => $user->program_label,
            'title' => $user->title,
            'is_active' => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'organizations_count' => (int) ($user->organizations_count ?? 0),
        ];
    }
}
