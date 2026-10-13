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
 * LOY-06: points expire first in, first out. For an account at a moment:
 * the earn lots due by then, less what redemptions and other debits used
 * (they take the oldest lots first), less what already expired, is what
 * expires now (never more than the balance). Points given back
 * (reversed redemptions, refunds, positive adjustments) do not expire.
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

    /** What expires at $at on $account (FIFO). */
    public static function due(Account $account, CarbonImmutable $at): BigInteger
    {
        $entries = PointsEntry::query()->where('account_id', $account->id)->get(['kind', 'points', 'expires_at']);
        $lots = BigInteger::zero();
        $debits = BigInteger::zero();
        $expired = BigInteger::zero();

        foreach ($entries as $entry) {
            $points = BigInteger::of((string) $entry->points);

            match (true) {
                $entry->kind === 'earn' && $entry->expires_at !== null && $entry->expires_at->lessThanOrEqualTo($at) => $lots = $lots->plus($points),
                $entry->kind === 'expire' => $expired = $expired->plus($points->negated()),
                $entry->kind !== 'earn' => $debits = $debits->minus($points),
                default => null,
            };
        }

        $due = $lots->minus(BigInteger::max($debits, BigInteger::zero()))->minus($expired);
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
