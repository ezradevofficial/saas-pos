<?php

namespace Modules\Loyalty\Contracts;

use App\Core\Http\ApiException;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\GiftCardEntry;
use Modules\Loyalty\Models\PointsEntry;
use Modules\Loyalty\Programmes;
use Modules\Loyalty\Promotions\PromotionEngine;
use Modules\Loyalty\Promotions\Promotions;

/**
 * A read and write contract for online orders (docs/modules/ecommerce.md
 * ECOM-33), so E-commerce never reads or writes `loyalty_*` tables:
 *
 * - promotions(): the engine's discounts for a cart (LOY-10), with the
 *   versions in force at the order time; coupons counted on use();
 * - points and gift cards as part payment, redeemed when an order is
 *   placed (under the account's or card's row lock, never below zero) and
 *   given back when it is cancelled or returned;
 * - points earned on delivery and reversed on return.
 *
 * Every ledger entry has source type `ecommerce_order` and the order id,
 * so each happens once (Ledgers is idempotent by source). Methods answer
 * null (reads) or refuse (`loyalty_inactive`) while Loyalty is off.
 */
final class OnlineLoyalty
{
    public const SOURCE = 'ecommerce_order';

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Promotions $promotions,
        private readonly Programmes $programmes,
        private readonly Ledgers $ledgers,
    ) {}

    public function active(): bool
    {
        return $this->modules->isActive(LoyaltyServiceProvider::MODULE);
    }

    /**
     * @param  list<array{id: string, item_id: string, qty: string, unit_price_minor: string}>  $lines
     * @param  list<string>  $coupons
     * @return array{lines: list<array{line_id: string, promotion_id: string, version: int, discount_minor: string}>, promotions: list<array<string, mixed>>, total_discount_minor: string}|null
     */
    public function promotions(string $companyId, string $branchId, ?string $partyId, string $currency, CarbonImmutable $at, array $lines, array $coupons): ?array
    {
        if (! $this->active() || $lines === []) {
            return null;
        }

        return $this->promotions->evaluateSale($companyId, $branchId, $partyId, $currency, $at, $lines, $coupons);
    }

    /** LOY-09: whether $code is a coupon of the company with uses left. */
    public function couponUsable(string $companyId, string $code): bool
    {
        $coupon = Coupon::query()->where('company_id', $companyId)->where('code', PromotionEngine::normaliseCode($code))->whereNull('archived_at')->first();

        return $coupon !== null && ($coupon->max_uses === null || $coupon->uses < $coupon->max_uses);
    }

    /**
     * LOY-09: count one use of each code an order applied, atomically (one
     * `update … where uses < max_uses`): a code another order used up in
     * the meantime updates no row and the order is refused.
     *
     * @throws ApiException coupon_used_up
     */
    public function useCoupons(string $companyId, array $codes): void
    {
        foreach (self::codes($codes) as $code) {
            $updated = Coupon::query()->where('company_id', $companyId)->where('code', $code)->whereNull('archived_at')
                ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'))
                ->update(['uses' => DB::raw('uses + 1'), 'updated_at' => now()]);

            if ($updated === 0) {
                throw self::refusal('coupon_used_up');
            }
        }
    }

    /** LOY-09: an order cancelled or expired gives its coupon uses back (never below zero). */
    public function releaseCoupons(string $companyId, array $codes): void
    {
        foreach (self::codes($codes) as $code) {
            Coupon::query()->where('company_id', $companyId)->where('code', $code)->where('uses', '>', 0)
                ->update(['uses' => DB::raw('uses - 1'), 'updated_at' => now()]);
        }
    }

    /** @return list<string> */
    private static function codes(array $codes): array
    {
        return array_values(array_unique(array_map(fn ($code) => PromotionEngine::normaliseCode((string) $code), $codes)));
    }

    /**
     * The customer's points and what they are worth, for the checkout.
     *
     * @return array{points: string, point_value_minor: string, currency: string, min_redeem_points: int}|null
     */
    public function points(string $companyId, string $partyId): ?array
    {
        $programme = $this->active() ? $this->programmes->active($companyId) : null;

        if ($programme === null || (int) $programme->point_value_minor <= 0) {
            return null;
        }

        $balance = Account::query()->where('company_id', $companyId)->where('party_id', $partyId)->where('kind', 'points')->value('balance');

        return ['points' => (string) ($balance ?? '0'), 'point_value_minor' => (string) $programme->point_value_minor, 'currency' => (string) $programme->currency,
            'min_redeem_points' => (int) $programme->min_redeem_points];
    }

    /** A gift card's spendable balance in $currency, or null (unknown, blocked, inactive, expired or another currency). */
    public function giftCardBalance(string $companyId, string $code, string $currency): ?string
    {
        $card = $this->usableCard($companyId, $code, $currency);

        return $card === null ? null : (string) max(0, (int) $card->balance_minor);
    }

    /**
     * LOY-05: redeem $points for an order (in the current transaction).
     *
     * @return string the value in minor units of the programme currency
     *
     * @throws ApiException points_unavailable, points_below_minimum, points_insufficient
     */
    public function redeemPoints(string $companyId, string $partyId, string $points, string $orderId, string $currency): string
    {
        $programme = $this->active() ? $this->programmes->active($companyId) : null;

        if ($programme === null || (int) $programme->point_value_minor <= 0 || $programme->currency !== $currency) {
            throw self::refusal('points_unavailable');
        }

        if (BigInteger::of($points)->isLessThan((int) $programme->min_redeem_points)) {
            throw self::refusal('points_below_minimum', ['points' => (string) $programme->min_redeem_points]);
        }

        $account = $this->ledgers->lock($companyId, $partyId, 'points');

        if ($account === null || BigInteger::of((string) $account->balance)->isLessThan($points)) {
            throw self::refusal('points_insufficient');
        }

        $this->ledgers->points($account, 'redeem', '-'.$points, ['source_type' => self::SOURCE, 'source_id' => $orderId]);

        return PromotionEngine::redeemValue($points, (string) $programme->point_value_minor);
    }

    /**
     * LOY-22: take $amount (minor units of $currency) off a gift card for an order (in the current transaction).
     *
     * @throws ApiException gift_card_unusable, gift_card_insufficient
     */
    public function redeemGiftCard(string $companyId, string $code, string $amount, string $currency, string $orderId): string
    {
        $card = $this->active() ? $this->usableCard($companyId, $code, $currency) : null;

        if ($card === null) {
            throw self::refusal('gift_card_unusable');
        }

        $card = $this->ledgers->lockCard($card->id);

        if (BigInteger::of((string) $card->balance_minor)->isLessThan($amount)) {
            throw self::refusal('gift_card_insufficient');
        }

        $this->ledgers->giftCard($card, 'redeem', '-'.$amount, ['source_type' => self::SOURCE, 'source_id' => $orderId]);

        return $card->id;
    }

    /** ECOM-19: give back what an order redeemed (points and gift cards), once. */
    public function giveBack(string $orderId): void
    {
        if (! $this->active()) {
            return;
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($orderId) {
            foreach (PointsEntry::query()->where('source_type', self::SOURCE)->where('source_id', $orderId)->where('kind', 'redeem')->get() as $entry) {
                $account = $this->ledgers->lockAccount($entry->account_id);
                $this->ledgers->points($account, 'reverse_redeem', (string) BigInteger::of((string) $entry->points)->negated(), ['source_type' => self::SOURCE, 'source_id' => $orderId]);
            }

            foreach (GiftCardEntry::query()->where('source_type', self::SOURCE)->where('source_id', $orderId)->where('kind', 'redeem')->get() as $entry) {
                $card = $this->ledgers->lockCard($entry->gift_card_id);
                $this->ledgers->giftCard($card, 'reverse_redeem', (string) BigInteger::of((string) $entry->amount_minor)->negated(), ['source_type' => self::SOURCE, 'source_id' => $orderId]);
            }
        });
    }

    /**
     * LOY-03: points for a delivered order: floor(eligible × earn_points ÷
     * earn_spend_minor), eligible in the programme currency (only an order
     * in that currency earns). Once per order.
     */
    public function earn(string $companyId, string $partyId, string $currency, string $eligibleMinor, string $orderId, CarbonImmutable $at): string
    {
        $programme = $this->active() ? $this->programmes->active($companyId) : null;

        if ($programme === null || $programme->currency !== $currency) {
            return '0';
        }

        $points = PromotionEngine::pointsEarned($eligibleMinor, (int) $programme->earn_points, (string) $programme->earn_spend_minor);

        if ($points === '0') {
            return '0';
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($companyId, $partyId, $points, $orderId, $at, $programme) {
            $account = $this->ledgers->pointsAccount($companyId, $partyId);
            $account = $this->ledgers->lockAccount($account->id);
            $this->ledgers->points($account, 'earn', $points, [
                'source_type' => self::SOURCE, 'source_id' => $orderId, 'occurred_at' => $at,
                'expires_at' => $programme->expiry_months === null ? null : $at->addMonthsNoOverflow((int) $programme->expiry_months),
            ]);
        });

        return $points;
    }

    /** LOY-04: a returned order's earned points come off, once (never below what was earned). */
    public function reverseEarn(string $orderId): void
    {
        if (! $this->active()) {
            return;
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($orderId) {
            foreach (PointsEntry::query()->where('source_type', self::SOURCE)->where('source_id', $orderId)->where('kind', 'earn')->get() as $entry) {
                $account = $this->ledgers->lockAccount($entry->account_id);
                $this->ledgers->points($account, 'reverse_earn', (string) BigInteger::of((string) $entry->points)->negated(), ['source_type' => self::SOURCE, 'source_id' => $orderId]);
            }
        });
    }

    private function usableCard(string $companyId, string $code, string $currency): ?GiftCard
    {
        $card = GiftCard::query()->where('code_hash', GiftCards::hash($code))->where('company_id', $companyId)->first();

        if ($card === null || $card->status !== GiftCard::ACTIVE || $card->currency !== $currency || $card->isExpired(CarbonImmutable::now()->format('Y-m-d'))) {
            return null;
        }

        return $card;
    }

    /** @param array<string, string> $replace */
    private static function refusal(string $code, array $replace = []): ApiException
    {
        $message = __("ecommerce.errors.{$code}", $replace);

        return new ApiException(422, $code, $message, ['payment' => [$message]]);
    }
}
