<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'title',
        'body',
        'audience',
        'is_pinned',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Announcements a given student is allowed to read. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $orgIds = $user->activeOrganizationIds();

        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(function (Builder $q) use ($orgIds) {
                $q->whereNull('organization_id')
                    ->orWhere('audience', 'all')
                    ->orWhereIn('organization_id', $orgIds ?: [0]);
            });
    }
}
