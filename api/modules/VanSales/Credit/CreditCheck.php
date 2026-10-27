<?php

namespace Modules\VanSales\Credit;

use Carbon\CarbonInterface;

/**
 * VAN-11: what the server thinks of a credit sale the device already made.
 * The sale is never refused (the device wins for completed sales, ADR 004);
 * the answer is a list of flags that put it in the review queue (VAN-12).
 *
 * - `credit_without_customer`: credit with no customer to owe it;
 * - `no_credit_limit`: the customer has no limit set;
 * - `limit_currency_differs`: the sale is not in the limit's currency (not
 *   converted, reviewed by a person);
 * - `over_limit`: balance before + this credit above the limit;
 * - `limit_changed`: the device decided on another limit than the server's
 *   now holds, or named none;
 * - `credit_offline`: the sale reached the server late or was sold offline
 *   (when the company asks for it). This comes from the upload itself (its
 *   offline mark and how long after the sale it arrived), never from what the
 *   device says about its own credit decision.
 */
final class CreditCheck
{
    /** A sale that reaches the server this long after it was made was made offline. */
    public const OFFLINE_LAG_MINUTES = 15;

    /**
     * @param  array{limit_minor?: mixed}|null  $device  the sale's `extensions.van_sales.credit`
     * @return array{flags: list<string>, limit_minor: ?int, decision: string}
     */
    public static function judge(
        int $creditMinor,
        string $currency,
        bool $hasCustomer,
        ?int $limitMinor,
        ?string $limitCurrency,
        int $balanceBeforeMinor,
        ?array $device,
        bool $soldOffline,
        CarbonInterface $soldAt,
        CarbonInterface $receivedAt,
        bool $flagOffline,
    ): array {
        $offline = $soldOffline || $soldAt->diffInMinutes($receivedAt, false) > self::OFFLINE_LAG_MINUTES;
        $decision = $offline ? 'offline' : 'online';
        $flags = [];

        if ($creditMinor <= 0) {
            return ['flags' => [], 'limit_minor' => $limitMinor, 'decision' => $decision];
        }

        if (! $hasCustomer) {
            $flags[] = 'credit_without_customer';
        } elseif ($limitMinor === null || $limitCurrency === null) {
            $flags[] = 'no_credit_limit';
        } elseif ($limitCurrency !== $currency) {
            $flags[] = 'limit_currency_differs';
        } elseif ($balanceBeforeMinor + $creditMinor > $limitMinor) {
            $flags[] = 'over_limit';
        }

        $deviceLimit = $device['limit_minor'] ?? null;

        if ($hasCustomer && $limitMinor !== null && (! is_numeric($deviceLimit) || (int) $deviceLimit !== $limitMinor)) {
            $flags[] = 'limit_changed';
        }

        if ($offline && $flagOffline) {
            $flags[] = 'credit_offline';
        }

        return ['flags' => $flags, 'limit_minor' => $limitMinor, 'decision' => $decision];
    }
}
