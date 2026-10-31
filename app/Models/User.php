<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    public const ROLE_STUDENT = 'student';

    public const ROLE_ADVISER = 'adviser';

    protected $fillable = [
        'id_number',
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'program',
        'year_level',
        'section',
        'phone',
        'title',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'year_level' => 'integer',
        ];
    }

    /* ----------------------------------------------------------------- */
    /* Accessors                                                          */
    /* ----------------------------------------------------------------- */

    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    protected function initials(): Attribute
    {
        return Attribute::get(fn () => strtoupper(
            mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1)
        ));
    }

    /** "BSIT · 3rd Year" style label used throughout the UI. */
    protected function programLabel(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->program) {
                return $this->title;
            }

            $year = $this->year_level ? ' · '.self::ordinal($this->year_level).' Year' : '';

            return $this->program.$year;
        });
    }

    public static function ordinal(int $n): string
    {
        return match ($n) {
            1 => '1st', 2 => '2nd', 3 => '3rd', default => $n.'th',
        };
    }

    /* ----------------------------------------------------------------- */
    /* Relationships                                                      */
    /* ----------------------------------------------------------------- */

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', Membership::STATUS_ACTIVE);
    }

    public function officerMemberships(): HasMany
    {
        return $this->activeMemberships()->where('is_officer', true);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function excuses(): HasMany
    {
        return $this->hasMany(ExcuseRequest::class);
    }

    public function savedOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'saved_organizations')->withTimestamps();
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    /* ----------------------------------------------------------------- */
    /* Helpers                                                            */
    /* ----------------------------------------------------------------- */

    public function isAdviser(): bool
    {
        return $this->role === self::ROLE_ADVISER;
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    public function isMemberOf(int $organizationId): bool
    {
        return $this->activeMemberships()->where('organization_id', $organizationId)->exists();
    }

    public function isOfficerOf(int $organizationId): bool
    {
        return $this->officerMemberships()->where('organization_id', $organizationId)->exists();
    }

    /** @return array<int> */
    public function activeOrganizationIds(): array
    {
        return $this->activeMemberships()->pluck('organization_id')->all();
    }

    /** Log in with either the school ID number or the email address. */
    public function scopeWhereLogin(Builder $query, string $login): Builder
    {
        $login = trim($login);

        return $query->where(function (Builder $q) use ($login) {
            $q->where('id_number', strtoupper($login))
                ->orWhereRaw('LOWER(email) = ?', [strtolower($login)]);
        });
    }

    public function scopeStudents(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_STUDENT);
    }
}
