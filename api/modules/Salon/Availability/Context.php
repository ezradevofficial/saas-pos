<?php

namespace Modules\Salon\Availability;

use App\Core\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\Staff;

/**
 * What a plan reads (Planner): the branch, the staff and resources that may be picked, their spans of duty and the
 * time already taken, for one window.
 */
final class Context
{
    /** @var array<string, int> */
    private array $count = [];

    /**
     * @param  Collection<string, Staff>  $staff
     * @param  Collection<string, resource>  $resources
     * @param  array<string, array{work: list<array{0: CarbonImmutable, 1: CarbonImmutable}>, off: list<array{0: CarbonImmutable, 1: CarbonImmutable}>}>  $spans
     * @param  Collection<int, Line>  $busy
     */
    public function __construct(
        public readonly Branch $branch,
        public readonly Collection $staff,
        public readonly Collection $resources,
        public readonly array $spans,
        private readonly Collection $busy,
    ) {
        foreach ($busy as $line) {
            $this->count[$line->staff_id] = ($this->count[$line->staff_id] ?? 0) + 1;
        }
    }

    public function staffBusy(string $staffId, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $this->busy->contains(fn ($line) => $line->staff_id === $staffId && $from->lessThan($line->blocked_until) && $to->greaterThan($line->starts_at));
    }

    public function resourceBusy(string $resourceId, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $this->busy->contains(fn ($line) => $line->resource_id === $resourceId && $from->lessThan($line->blocked_until) && $to->greaterThan($line->starts_at));
    }

    /** How many lines a staff member already has in the window (fewest first when anyone will do). */
    public function bookings(string $staffId): int
    {
        return $this->count[$staffId] ?? 0;
    }
}
