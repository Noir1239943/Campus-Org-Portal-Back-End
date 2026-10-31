<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationProposal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'reference',
        'user_id',
        'name',
        'acronym',
        'category',
        'description',
        'purpose',
        'planned_activities',
        'adviser_name',
        'expected_members',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'organization_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_members' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrganizationProposal $proposal) {
            if (! $proposal->reference) {
                $next = (static::max('id') ?? 0) + 1;
                $proposal->reference = sprintf('ORG-%s-%03d', now()->format('Y'), $next);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
