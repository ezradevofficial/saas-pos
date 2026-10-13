<?php

namespace App\Core\Limits;

use App\Core\Http\ApiException;
use App\Core\Limits\Models\Overage;
use App\Core\Limits\Models\UsageMeter;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * LIM-01..LIM-04: soft limits on outlets, devices, users and employees, in
 * the current tenant's context (row-level security, TEN-01).
 *
 * - meter(): the daily count (LIM-01). Writes the day's `usage_meters`
 *   rows with the plan's allowances, then follows each unit's overage:
 *   opens one when the count goes above the allowance and warns the Owners
 *   with the extra cost (LIM-02); reminds them once a day for the 7-day
 *   window; tells them when adding more is paused (LIM-03); closes it when
 *   the count is back within the allowance.
 * - ensureCanAdd(): the only guard (LIM-03, LIM-04). Called by exactly the
 *   actions that add one more of a unit (GUARDED, checked by
 *   UsageLimitGuardsTest): it refuses with 402 once the unit's window has
 *   passed and one more would still be above the allowance. Nothing else
 *   ever consults the limits: sales, sync, payroll, approvals and
 *   workflows are never blocked (LIM-04).
 * - status(): counts, allowances, overages and the extra cost for the
 *   Owner's banner and Usage card.
 */
class UsageLimits
{
    /** LIM-04: every action that consults the limits, by unit. Nothing else may. */
    public const GUARDED = [
        Units::OUTLET => ['create an outlet location', 'change a location to an outlet', 'restore an archived outlet'],
        Units::DEVICE => ['add a device', 'issue a pairing code', 'pair a device', 'resume a suspended device'],
        Units::USER => ['invite a user', 'reactivate a user'],
        Units::EMPLOYEE => [],
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PlanAllowances $plans,
        private readonly UsageCounter $counter,
        private readonly OverageCost $cost,
        private readonly UsageNotices $notices,
    ) {}

    /**
     * LIM-01: meter the current tenant at $at and follow its overages.
     *
     * @return array<string, int> unit => count
     */
    public function meter(CarbonImmutable $at): array
    {
        $allowances = $this->plans->for($this->tenants->require());
        $counts = $this->counter->all();
        $cost = null;
        $priced = false;
        $priceOnce = function () use (&$cost, &$priced, $allowances, $counts) {
            if (! $priced) {
                $cost = $this->cost->monthly($allowances, $counts);
                $priced = true;
            }

            return $cost;
        };

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($at, $allowances, $counts, $priceOnce) {
            foreach ($counts as $unit => $count) {
                UsageMeter::query()->updateOrCreate(
                    ['day' => $at->utc()->toDateString(), 'unit' => $unit],
                    ['count' => $count, 'allowed' => $allowances->of($unit), 'metered_at' => $at],
                );

                $this->follow($unit, $count, $allowances->of($unit), $at, $priceOnce);
            }
        });

        return $counts;
    }

    /**
     * LIM-03: refuse adding one more $unit once its overage window has
     * passed and one more would still be above the allowance. An overage
     * already back within the allowance (items archived, plan upgraded) is
     * closed here, without waiting for the next meter run.
     *
     * @throws ApiException 402 usage_limit_reached
     */
    public function ensureCanAdd(string $unit): void
    {
        $overage = Overage::query()->open()->where('unit', $unit)->first();
        $now = CarbonImmutable::now();

        if ($overage === null || ! $overage->isBlocking($now)) {
            return;
        }

        $allowed = $this->plans->for($this->tenants->require())->of($unit);

        if ($allowed === null) {
            $this->close($overage, Overage::UNLIMITED, $now);

            return;
        }

        $count = $this->counter->count($unit);

        if ($count < $allowed) {
            $this->close($overage, Overage::WITHIN_ALLOWANCE, $now);

            return;
        }

        $item = __("limits.items.{$unit}");

        throw new ApiException(402, 'usage_limit_reached', __('limits.errors.blocked', ['allowed' => $allowed, 'count' => $count, 'item' => $item]), extra: [
            'unit' => $unit,
            'allowed' => $allowed,
            'count' => $count,
            'upgrade_url' => (string) config('limits.upgrade_path', '/settings/subscription'),
        ]);
    }

    /**
     * The Usage card and the over-limit banner: each unit's live count,
     * allowance and state (`unlimited`, `within`, `over` inside the window,
     * `blocked` after it), the open overage, and the extra monthly cost.
     *
     * @return array<string, mixed>
     */
    public function status(CarbonImmutable $at): array
    {
        $allowances = $this->plans->for($this->tenants->require());
        $counts = $this->counter->all();
        $overages = Overage::query()->open()->get()->keyBy('unit');
        $cost = $this->cost->monthly($allowances, $counts);
        $units = [];

        foreach (Units::ALL as $unit) {
            $allowed = $allowances->of($unit);
            $count = $counts[$unit];
            /** @var ?Overage $overage */
            $overage = $overages->get($unit);
            $state = match (true) {
                $overage?->isBlocking($at) && $allowed !== null && $count >= $allowed => 'blocked',
                $allowed !== null && $count > $allowed => 'over',
                $allowed === null => 'unlimited',
                default => 'within',
            };

            $units[] = [
                'unit' => $unit,
                'count' => $count,
                'allowed' => $allowed,
                'over' => $allowed === null ? 0 : max(0, $count - $allowed),
                'state' => $state,
                'overage' => $overage === null ? null : [
                    'started_at' => $overage->started_at->toIso8601String(),
                    'blocks_at' => $overage->blocksAt()->toIso8601String(),
                    'days_left' => $overage->daysLeft($at),
                ],
                'extra_cost' => isset($cost['units'][$unit]) ? ['amount_minor' => (string) $cost['units'][$unit], 'currency' => $cost['currency']] : null,
            ];
        }

        $metered = UsageMeter::query()->max('metered_at');

        return [
            'units' => $units,
            'extra_cost' => $cost !== null && $cost['total'] > 0 ? ['amount_minor' => (string) $cost['total'], 'currency' => $cost['currency']] : null,
            'window_days' => Overage::windowDays(),
            'upgrade_url' => (string) config('limits.upgrade_path', '/settings/subscription'),
            'metered_at' => $metered === null ? null : CarbonImmutable::parse($metered)->toIso8601String(),
        ];
    }

    /** @param  callable(): ?array  $cost */
    private function follow(string $unit, int $count, ?int $allowed, CarbonImmutable $at, callable $cost): void
    {
        $overage = Overage::query()->open()->where('unit', $unit)->lockForUpdate()->first();
        $over = $allowed !== null && $count > $allowed;

        if (! $over) {
            if ($overage !== null) {
                $this->close($overage, $allowed === null ? Overage::UNLIMITED : Overage::WITHIN_ALLOWANCE, $at);
            }

            return;
        }

        if ($overage === null) {
            $overage = Overage::create(['unit' => $unit, 'allowed' => $allowed, 'count' => $count, 'started_at' => $at, 'last_notified_at' => $at]);
            $this->notices->send(UsageNotices::EXCEEDED, $overage, $cost());

            return;
        }

        $overage->fill(['allowed' => $allowed, 'count' => $count]);
        $day = $overage->dayOfWindow($at);

        if ($overage->isBlocking($at)) {
            if ($overage->blocked_notified_at === null) {
                $overage->fill(['blocked_notified_at' => $at, 'last_notified_at' => $at])->save();
                $this->notices->send(UsageNotices::BLOCKED, $overage, $cost());

                return;
            }
        } elseif ($day > $overage->reminders_sent) {
            // One reminder a day, whatever time the meter runs (LIM-03).
            $overage->fill(['reminders_sent' => $day, 'last_notified_at' => $at])->save();
            $this->notices->send(UsageNotices::REMINDER, $overage, $cost());

            return;
        }

        if ($overage->isDirty()) {
            $overage->save();
        }
    }

    private function close(Overage $overage, string $reason, CarbonImmutable $at): void
    {
        $overage->fill(['closed_at' => $at, 'close_reason' => $reason])->save();
    }
}
