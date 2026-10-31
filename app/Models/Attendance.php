<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_EXCUSED = 'excused';

    public const STATUS_ABSENT = 'absent';

    /** Statuses that count as "attended". */
    public const ATTENDED = [self::STATUS_PRESENT, self::STATUS_LATE];

    protected $fillable = [
        'event_id',
        'user_id',
        'status',
        'method',
        'checked_in_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function attended(): bool
    {
        return in_array($this->status, self::ATTENDED, true);
    }
}
