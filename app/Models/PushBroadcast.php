<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushBroadcast extends Model
{
    use HasFactory, SoftDeletes;

    public const SCHEDULE_IMMEDIATE = 'immediate';
    public const SCHEDULE_ONCE = 'once';
    public const SCHEDULE_DAILY = 'daily';
    public const SCHEDULE_INTERVAL = 'interval';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'title',
        'body',
        'image_id',
        'image_url',
        'redirect_type',
        'redirect_target',
        'schedule_type',
        'scheduled_at',
        'daily_time',
        'interval_minutes',
        'starts_at',
        'ends_at',
        'next_run_at',
        'last_sent_at',
        'sent_count',
        'status',
        'created_by_id',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'next_run_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'interval_minutes' => 'integer',
        'sent_count' => 'integer',
        'image_id' => 'integer',
    ];

    protected $with = ['image'];

    protected $appends = ['resolved_image_url'];

    public function image(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'image_id');
    }

    /** Resolved absolute image URL (explicit URL wins, else attachment). */
    public function getResolvedImageUrlAttribute(): ?string
    {
        if (!empty($this->image_url)) {
            return $this->image_url;
        }
        return $this->image?->original_url ?: null;
    }

    public function isRecurring(): bool
    {
        return in_array($this->schedule_type, [self::SCHEDULE_DAILY, self::SCHEDULE_INTERVAL], true);
    }

    public function isDue(\DateTimeInterface $now): bool
    {
        if (in_array($this->status, [self::STATUS_PAUSED, self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            return false;
        }
        if (!$this->next_run_at) {
            return false;
        }
        if ($this->ends_at && $now > $this->ends_at) {
            return false;
        }
        if ($this->starts_at && $now < $this->starts_at) {
            return false;
        }
        return $this->next_run_at <= $now;
    }

    /** Compute the first/next run time from scheduling fields. Returns null when nothing left to send. */
    public static function computeNextRunAt(array $attrs, ?\DateTimeInterface $from = null): ?\DateTimeInterface
    {
        $from = $from ? \Carbon\Carbon::parse($from) : now();
        $type = $attrs['schedule_type'] ?? self::SCHEDULE_IMMEDIATE;

        if ($type === self::SCHEDULE_IMMEDIATE) {
            return $from;
        }

        if ($type === self::SCHEDULE_ONCE) {
            return isset($attrs['scheduled_at']) && $attrs['scheduled_at']
                ? \Carbon\Carbon::parse($attrs['scheduled_at'])
                : null;
        }

        if ($type === self::SCHEDULE_DAILY) {
            if (empty($attrs['daily_time'])) {
                return null;
            }
            $time = \Carbon\Carbon::parse($attrs['daily_time'])->format('H:i:s');
            $candidate = \Carbon\Carbon::parse($from->format('Y-m-d') . ' ' . $time);
            if ($candidate <= $from) {
                $candidate->addDay();
            }
            if (!empty($attrs['starts_at']) && $candidate < \Carbon\Carbon::parse($attrs['starts_at'])) {
                $start = \Carbon\Carbon::parse($attrs['starts_at']);
                $candidate = \Carbon\Carbon::parse($start->format('Y-m-d') . ' ' . $time);
                if ($candidate < $start) {
                    $candidate->addDay();
                }
            }
            if (!empty($attrs['ends_at']) && $candidate > \Carbon\Carbon::parse($attrs['ends_at'])) {
                return null;
            }
            return $candidate;
        }

        if ($type === self::SCHEDULE_INTERVAL) {
            $minutes = (int) ($attrs['interval_minutes'] ?? 0);
            if ($minutes <= 0) {
                return null;
            }
            $base = !empty($attrs['last_sent_at'])
                ? \Carbon\Carbon::parse($attrs['last_sent_at'])
                : (!empty($attrs['starts_at']) ? \Carbon\Carbon::parse($attrs['starts_at']) : $from);
            $next = (clone $base)->addMinutes($minutes);
            if ($next <= $from) {
                // catch up: schedule relative to now so we don't burst-send missed runs
                $next = (clone $from)->addMinutes($minutes);
            }
            if (!empty($attrs['ends_at']) && $next > \Carbon\Carbon::parse($attrs['ends_at'])) {
                return null;
            }
            return $next;
        }

        return null;
    }
}
