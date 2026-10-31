<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    public const TYPES = ['Seminar', 'Workshop', 'Meeting', 'Assembly', 'Outreach', 'Competition', 'Social'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const VISIBILITY_OPEN = 'open';

    public const VISIBILITY_MEMBERS = 'members';

    protected $fillable = [
        'organization_id',
        'created_by',
        'title',
        'type',
        'description',
        'venue',
        'starts_at',
        'ends_at',
        'capacity',
        'visibility',
        'is_mandatory',
        'service_hours',
        'speaker',
        'status',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'check_in_secret',
        'check_in_opened_at',
        'check_in_closed_at',
    ];

    protected $hidden = ['check_in_secret'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'check_in_opened_at' => 'datetime',
            'check_in_closed_at' => 'datetime',
            'is_mandatory' => 'boolean',
            'capacity' => 'integer',
            'service_hours' => 'float',
        ];
    }

    /* ----------------------------------------------------------------- */
    /* Relationships                                                      */
    /* ----------------------------------------------------------------- */

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function registrants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_registrations')->withTimestamps();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function excuses(): HasMany
    {
        return $this->hasMany(ExcuseRequest::class);
    }

    /* ----------------------------------------------------------------- */
    /* State helpers                                                      */
    /* ----------------------------------------------------------------- */

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }

    public function hasEnded(): bool
    {
        return $this->ends_at->isPast();
    }

    public function isOngoing(): bool
    {
        return $this->hasStarted() && ! $this->hasEnded();
    }

    public function isCheckInOpen(): bool
    {
        return $this->isPublished()
            && $this->check_in_opened_at !== null
            && $this->check_in_closed_at === null;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED, self::STATUS_PENDING], true)
            || ($this->isPublished() && ! $this->hasStarted());
    }

    /**
     * Status shown in the UI: draft, pending, rejected, cancelled,
     * upcoming, ongoing or completed.
     */
    public function displayStatus(): string
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            return $this->status;
        }

        if ($this->hasEnded()) {
            return 'completed';
        }

        return $this->hasStarted() ? 'ongoing' : 'upcoming';
    }

    /* ----------------------------------------------------------------- */
    /* Scopes                                                             */
    /* ----------------------------------------------------------------- */

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('ends_at', '>=', now())->orderBy('starts_at');
    }

    public function scopeEnded(Builder $query): Builder
    {
        return $query->where('ends_at', '<', now());
    }
}
