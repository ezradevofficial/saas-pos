<?php

namespace App\Core\Limits;

use App\Core\Api\Sandbox\Sandbox;
use App\Core\Api\Sandbox\SandboxCaps;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Limits\Models\Overage;
use App\Core\Limits\Models\UsageMeter;
use App\Core\Onboarding\Trial\TrialStatus;
use App\Core\Onboarding\Trial\TrialStatusProvider;
use App\Core\Rbac\OwnerGuard;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

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
        Units::USER => ['invite a user', 'reactivate a user', 'provision a user at SSO sign-in'],
        // HR-32: hiring and rehiring (HR's Employees service; the employees import hires through it).
        Units::EMPLOYEE => ['hire an employee', 'rehire an employee'],
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PlanAllowances $plans,
        private readonly UsageCounter $counter,
        private readonly OverageCost $cost,
        private readonly UsageNotices $notices,
        private readonly OwnerGuard $owners,
        private readonly Sandbox $sandbox,
        private readonly SandboxCaps $sandboxCaps,
    ) {}

    /**
     * LIM-01: meter the current tenant at $at and follow its overages.
     *
     * @return array<string, int> unit => count
     */
    public function meter(CarbonImmutable $at): array
    {
        // API-05: a developer sandbox is never metered or billed (ADR 020).
        if ($this->sandbox->active()) {
            return [];
        }

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
     * passed and one more would still be above the allowance
     * (`count + 1 > allowed`). It never closes an overage: only the meter
     * does, once the count is within the plan, so archiving down for a
     * moment and adding back cannot escape the window.
     *
     * Call it inside the transaction that creates the item: it takes a
     * per-tenant, per-unit advisory lock and counts after it, so two adds
     * at once cannot both pass.
     *
     * Owners get the counts and the upgrade link; anyone else only the
     * unit and "ask an Owner to upgrade".
     *
     * @throws ApiException 402 usage_limit_reached
     * @throws LogicException outside a transaction
     */
    public function ensureCanAdd(string $unit): void
    {
        $db = DB::connection(TenantContext::CONNECTION);

        if ($db->transactionLevel() === 0) {
            throw new LogicException('ensureCanAdd() must run inside the transaction that adds the item.');
        }

        $tenantId = $this->tenants->require();
        $db->select("select pg_advisory_xact_lock(hashtext('usage:' || ? || ':' || ?))", [$tenantId, $unit]);

        // API-05: a developer sandbox has no plan; its hard caps apply instead (ADR 020).
        if ($this->sandbox->active()) {
            $this->sandboxCaps->assertCanAdd($unit, $this->counter->count($unit));

            return;
        }

        $overage = Overage::query()->open()->where('unit', $unit)->first();

        if ($overage === null || ! $overage->isBlocking(CarbonImmutable::now())) {
            return;
        }

        $allowed = $this->plans->for($tenantId)->of($unit);

        if ($allowed === null) {
            return;
        }

        $count = $this->counter->count($unit);

        if ($count + 1 <= $allowed) {
            return;
        }

        $item = __("limits.items.{$unit}");
        $user = Auth::user();

        if (! $user instanceof User || ! $this->owners->isOwner($user)) {
            throw new ApiException(402, 'usage_limit_reached', __('limits.errors.blocked_ask_owner', ['item' => $item]), extra: ['unit' => $unit]);
        }

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
                $overage?->isBlocking($at) && $allowed !== null && $count + 1 > $allowed => 'blocked',
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
            'trial' => $this->trial(),
            'extra_cost' => $cost !== null && $cost['total'] > 0 ? ['amount_minor' => (string) $cost['total'], 'currency' => $cost['currency']] : null,
            'window_days' => Overage::windowDays(),
            'upgrade_url' => (string) config('limits.upgrade_path', '/settings/subscription'),
            'metered_at' => $metered === null ? null : CarbonImmutable::parse($metered)->toIso8601String(),
        ];
    }

    /**
     * LIM-02, ONB-04: the trial's real state and end date, so the banner
     * says the trial allows the extra units instead of counting days of a
     * window that has not been metered yet.
     *
     * @return array{state: string, ends_at: ?string, days_left: int}
     */
    private function trial(): array
    {
        $provider = app()->bound(TrialStatusProvider::class) ? app(TrialStatusProvider::class) : null;

        return ($provider?->status() ?? TrialStatus::none())->toArray();
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
            // Back over within a window of the last close: the original window goes on.
            $previous = Overage::query()->where('unit', $unit)->where('close_reason', Overage::WITHIN_ALLOWANCE)
                ->where('closed_at', '>=', $at->subDays(Overage::windowDays()))->latest('closed_at')->first();

            $overage = Overage::create([
                'unit' => $unit,
                'allowed' => $allowed,
                'count' => $count,
                'started_at' => $previous->started_at ?? $at,
                'reminders_sent' => $previous->reminders_sent ?? 0,
                'blocked_notified_at' => $previous?->blocked_notified_at,
                'last_notified_at' => $at,
            ]);

            if ($previous === null) {
                $this->notify(UsageNotices::EXCEEDED, $overage, $cost, $at);

                return;
            }
        }

        $overage->fill(['allowed' => $allowed, 'count' => $count]);
        $day = $overage->dayOfWindow($at);

        if ($overage->isBlocking($at)) {
            if ($overage->blocked_notified_at === null) {
                $overage->fill(['blocked_notified_at' => $at, 'last_notified_at' => $at])->save();
                $this->notify(UsageNotices::BLOCKED, $overage, $cost, $at);

                return;
            }
        } elseif ($day > $overage->reminders_sent) {
            // One reminder a UTC day (LIM-03).
            $overage->fill(['reminders_sent' => $day, 'last_notified_at' => $at])->save();
            $this->notify(UsageNotices::REMINDER, $overage, $cost, $at);

            return;
        }

        if ($overage->isDirty()) {
            $overage->save();
        }
    }

    /** Sent once the meter's transaction commits, never for a rolled-back run. */
    private function notify(string $type, Overage $overage, callable $cost, CarbonImmutable $at): void
    {
        $snapshot = clone $overage;
        $price = $cost();

        DB::connection(TenantContext::CONNECTION)->afterCommit(fn () => $this->notices->send($type, $snapshot, $price, $at));
    }

    private function close(Overage $overage, string $reason, CarbonImmutable $at): void
    {
        $overage->fill(['closed_at' => $at, 'close_reason' => $reason])->save();
    }
}
