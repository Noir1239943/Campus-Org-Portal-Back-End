<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Organization extends Model
{
    public const CATEGORIES = ['Academic', 'Governance', 'Arts & Culture', 'Service', 'Sports', 'Media'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PROBATION = 'probation';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'slug',
        'acronym',
        'category',
        'tagline',
        'description',
        'about',
        'color',
        'adviser_name',
        'contact_email',
        'facebook_url',
        'meeting_schedule',
        'tags',
        'accepting_members',
        'capacity',
        'application_deadline',
        'application_question',
        'requires_all_students',
        'status',
        'recognized_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'accepting_members' => 'boolean',
            'requires_all_students' => 'boolean',
            'capacity' => 'integer',
            'application_deadline' => 'date',
            'recognized_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization) {
            if (! $organization->slug) {
                $organization->slug = Str::slug($organization->name);
            }
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', Membership::STATUS_ACTIVE);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot(['position', 'is_officer', 'status', 'joined_at'])
            ->wherePivot('status', Membership::STATUS_ACTIVE)
            ->withTimestamps();
    }

    public function officerMemberships(): HasMany
    {
        return $this->activeMemberships()->where('is_officer', true);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    /** Is the organization currently taking new applications? */
    public function isOpenForApplications(): bool
    {
        if (! $this->accepting_members || $this->requires_all_students || $this->status === self::STATUS_INACTIVE) {
            return false;
        }

        if ($this->application_deadline && $this->application_deadline->endOfDay()->isPast()) {
            return false;
        }

        if ($this->capacity && $this->activeMemberships()->count() >= $this->capacity) {
            return false;
        }

        return true;
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_INACTIVE);
    }
}
