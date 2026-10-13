<?php

namespace App\Core\Limits\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * LIM-02, LIM-03: a unit counted above the plan's allowance, from
 * `started_at` until the count is back within it (`closed_at`). The 7-day
 * window runs from `started_at`: daily reminders inside it, then adding
 * more of the unit is refused. At most one open record per unit. Opening,
 * updating and closing are audited as `core.usage_overage.*` (AUD-01).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $unit
 * @property int $allowed
 * @property int $count
 * @property CarbonImmutable $started_at
 * @property int $reminders_sent
 * @property ?CarbonImmutable $last_notified_at
 * @property ?CarbonImmutable $blocked_notified_at
 * @property ?CarbonImmutable $closed_at
 * @property ?string $close_reason
 */
class Overage extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const WITHIN_ALLOWANCE = 'within_allowance';

    public const UNLIMITED = 'unlimited';

    protected $table = 'usage_overages';

    protected string $auditResource = 'usage_overage';

    protected $fillable = ['unit', 'allowed', 'count', 'started_at', 'reminders_sent', 'last_notified_at', 'blocked_notified_at', 'closed_at', 'close_reason'];

    protected $attributes = ['reminders_sent' => 0];

    protected function casts(): array
    {
        return [
            'allowed' => 'integer',
            'count' => 'integer',
            'reminders_sent' => 'integer',
            'started_at' => 'immutable_datetime',
            'last_notified_at' => 'immutable_datetime',
            'blocked_notified_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * The 7-day window's end: the start of the UTC day 7 calendar days
     * after the day it started. Every rule (reminders, the block, days
     * left) counts UTC calendar days the same way (dayOfWindow).
     */
    public function blocksAt(): CarbonImmutable
    {
        return $this->started_at->utc()->startOfDay()->addDays(self::windowDays());
    }

    public function isBlocking(CarbonInterface $at): bool
    {
        return $this->closed_at === null && $this->dayOfWindow($at) >= self::windowDays();
    }

    /** UTC calendar days left in the window at $at (0 once blocking). */
    public function daysLeft(CarbonInterface $at): int
    {
        return max(0, self::windowDays() - $this->dayOfWindow($at));
    }

    /** UTC calendar days since the day the overage started (0 on that day). */
    public function dayOfWindow(CarbonInterface $at): int
    {
        $start = $this->started_at->utc()->startOfDay();
        $day = CarbonImmutable::parse($at)->utc()->startOfDay();

        return max(0, (int) round($start->diffInDays($day, false)));
    }

    public static function windowDays(): int
    {
        return max(1, (int) config('limits.window_days', 7));
    }
}
