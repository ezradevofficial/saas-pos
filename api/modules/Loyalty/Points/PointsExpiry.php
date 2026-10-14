<?php

namespace Modules\Loyalty\Points;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\PointsEntry;
use Modules\Loyalty\Models\Programme;
use Modules\Loyalty\Notifications\LoyaltyNotices;

/**
 * LOY-06: points expire first in, first out (due(): a replay of the
 * ledger as lots). Points given back (reversed redemptions, refunds,
 * positive adjustments) never expire and are spent last.
 */
class PointsExpiry
{
    public function __construct(
        private readonly Ledgers $ledgers,
        private readonly LoyaltyNotices $notices,
    ) {}

    /** Post the expiries due at $at for every account of the tenant; returns the points expired. */
    public function run(CarbonImmutable $at): string
    {
        $total = BigInteger::zero();

        $accounts = PointsEntry::query()->where('kind', 'earn')->whereNotNull('expires_at')->where('expires_at', '<=', $at)->distinct()->pluck('account_id');

        foreach ($accounts as $accountId) {
            $total = $total->plus(DB::connection(TenantContext::CONNECTION)->transaction(function () use ($accountId, $at) {
                $account = $this->ledgers->lockAccount($accountId);
                $due = self::due($account, $at);

                if ($due->isZero()) {
                    return BigInteger::zero();
                }

                $this->ledgers->points($account, 'expire', (string) $due->negated(), [
                    'source_type' => 'expiry', 'source_id' => null, 'occurred_at' => $at, 'reason' => $at->format('Y-m-d'),
                ]);

                return $due;
            }));
        }

        $this->noticeUpcoming($at);

        return (string) $total;
    }

    /**
     * What expires at $at on $account. M6: the ledger replayed as lots, in order:
     *
     * - an `earn` is a lot that expires (its `expires_at`; none: never);
     * - any other positive entry (a redemption or refund given back, a
     *   positive adjustment) is a lot that never expires, consumed last;
     * - a `reverse_earn` takes its own sale's lot first (`sale_id`), then
     *   the oldest;
     * - an `expire` takes the lots that had expired by then, oldest first;
     * - any other debit takes the oldest earn lots, then the lots that
     *   never expire.
     *
     * Expiring now: what is left of the lots expired by $at.
     */
    public static function due(Account $account, CarbonImmutable $at): BigInteger
    {
        $entries = PointsEntry::query()->where('account_id', $account->id)->orderBy('occurred_at')->orderBy('id')->get(['kind', 'points', 'expires_at', 'sale_id', 'occurred_at']);
        $lots = [];

        $take = function (BigInteger $amount, callable $eligible) use (&$lots): BigInteger {
            foreach ([true, false] as $expiring) {
                foreach ($lots as $i => $lot) {
                    if ($amount->isZero()) {
                        return $amount;
                    }

                    if ($lot['expiring'] !== $expiring || ! $lot['left']->isPositive() || ! $eligible($lot)) {
                        continue;
                    }

                    $used = BigInteger::min($amount, $lot['left']);
                    $lots[$i]['left'] = $lot['left']->minus($used);
                    $amount = $amount->minus($used);
                }
            }

            return $amount;
        };

        foreach ($entries as $entry) {
            $points = BigInteger::of((string) $entry->points);

            if ($points->isPositive()) {
                $earn = $entry->kind === 'earn';
                $lots[] = ['left' => $points, 'expires_at' => $earn ? $entry->expires_at : null, 'sale_id' => $entry->sale_id, 'expiring' => $earn];

                continue;
            }

            $amount = $points->negated();

            if ($entry->kind === 'expire') {
                $take($amount, fn (array $lot) => $lot['expires_at'] !== null && $lot['expires_at']->lessThanOrEqualTo($entry->occurred_at));

                continue;
            }

            if ($entry->kind === 'reverse_earn' && $entry->sale_id !== null) {
                $amount = $take($amount, fn (array $lot) => $lot['expiring'] && $lot['sale_id'] === $entry->sale_id);
            }

            $take($amount, fn () => true);
        }

        $due = BigInteger::zero();

        foreach ($lots as $lot) {
            if ($lot['expires_at'] !== null && $lot['expires_at']->lessThanOrEqualTo($at)) {
                $due = $due->plus($lot['left']);
            }
        }

        $cap = BigInteger::max(BigInteger::of((string) $account->balance), BigInteger::zero());

        return BigInteger::max(BigInteger::zero(), BigInteger::min($due, $cap));
    }

    /** Points entering each programme's notice window today, once a day per company. */
    private function noticeUpcoming(CarbonImmutable $at): void
    {
        foreach (Programme::query()->where('active', true)->whereNotNull('expiry_months')->get() as $programme) {
            $horizon = $at->addDays($programme->expiry_notice_days);
            $points = BigInteger::zero();
            $customers = 0;

            foreach (Account::query()->where('company_id', $programme->company_id)->where('kind', Account::POINTS)->where('balance', '>', 0)->get() as $account) {
                $soon = self::due($account, $horizon)->minus(self::due($account, $at));

                if ($soon->isPositive()) {
                    $points = $points->plus($soon);
                    $customers++;
                }
            }

            if ($customers > 0) {
                $this->notices->pointsExpiring($programme, (string) $points, $customers, $horizon->format('Y-m-d'));
            }
        }
    }
}
