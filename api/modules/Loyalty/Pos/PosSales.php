<?php

namespace Modules\Loyalty\Pos;

use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Loyalty\Credit\CreditAccounts;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Models\CreditEntry;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\GiftCardEntry;
use Modules\Loyalty\Models\LoyaltySale;
use Modules\Loyalty\Models\PointsEntry;
use Modules\Loyalty\Models\Programme;
use Modules\Loyalty\Models\Promotion;
use Modules\Loyalty\Models\PromotionApplication;
use Modules\Loyalty\Notifications\LoyaltyNotices;
use Modules\Loyalty\Programmes;
use Modules\Loyalty\Promotions\PromotionEngine;
use Modules\Loyalty\Promotions\Promotions;
use Throwable;

/**
 * LOY-03..LOY-06, LOY-12, LOY-15, LOY-21, LOY-22, LOY-25: what a POS sale
 * means for loyalty, from the snapshot POS sends with its events (never
 * from POS tables). Device wins (ADR 004): nothing here refuses a sale;
 * what does not add up is posted anyway when money changed hands, and
 * flagged on Loyalty's own copy of the sale (`loyalty_sales.flags`).
 *
 * - The sale is kept (`loyalty_sales`, pending) before it is processed,
 *   in its own transaction, so a failure leaves it `failed` for
 *   `loyalty:reprocess`; a Loyalty failure never fails the POS upload.
 * - Postings name their source (the payment, the void, the refund), so
 *   processing twice posts nothing twice.
 */
class PosSales
{
    public const PROMOTION_SOURCE = 'loyalty.promotion';

    public function __construct(
        private readonly Ledgers $ledgers,
        private readonly Programmes $programmes,
        private readonly Promotions $promotions,
        private readonly GiftCards $giftCards,
        private readonly CreditAccounts $credit,
        private readonly LoyaltyNotices $notices,
    ) {}

    /** SaleCompleted: keep the sale, then process it. */
    public function completed(array $sale): void
    {
        if (($sale['id'] ?? null) === null || LoyaltySale::query()->whereKey($sale['id'])->exists()) {
            return;
        }

        $this->db()->transaction(fn () => LoyaltySale::query()->insertOrIgnore([
            'id' => $sale['id'],
            'tenant_id' => app(TenantContext::class)->require(),
            'company_id' => $sale['company_id'],
            'branch_id' => $sale['branch_id'],
            'party_id' => $sale['customer_id'] ?? null,
            'receipt_number' => $sale['receipt_number'],
            'currency' => $sale['currency'],
            'total_minor' => $sale['totals']['total_minor'],
            'sold_at' => CarbonImmutable::parse($sale['sold_at']),
            'status' => LoyaltySale::PENDING,
            'flags' => '[]',
            'snapshot' => json_encode($sale),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->process($sale['id']);
    }

    /** Process a kept sale (also `loyalty:reprocess`); never throws. */
    public function process(string $saleId): bool
    {
        try {
            $this->db()->transaction(function () use ($saleId) {
                $sale = LoyaltySale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();

                if ($sale->status === LoyaltySale::PROCESSED) {
                    return;
                }

                $flags = [];
                $snapshot = $sale->snapshot;

                if ($sale->voided_at !== null) {
                    // H1: voided before it could be processed: nothing is posted (nothing to reverse).
                    $flags[] = ['code' => 'voided_before_processing'];
                } else {
                    $programme = $this->programmes->active($sale->company_id);
                    $this->promotionsOf($sale, $snapshot, $flags);
                    $this->tenders($sale, $snapshot, $programme, $flags);
                    $this->giftCardSales($sale, $snapshot, $programme, $flags);
                    $this->earn($sale, $snapshot, $programme, $flags);
                }

                $sale->forceFill(['status' => LoyaltySale::PROCESSED, 'flags' => $flags, 'processed_at' => now(), 'error' => null])->save();

                // H1: refunds that arrived before the sale was processed, applied in order now.
                $pending = (array) ($sale->pending_refunds ?? []);

                if ($pending !== []) {
                    $sale->forceFill(['pending_refunds' => []])->save();

                    foreach ($pending as $refund) {
                        $this->applyRefund($sale, (array) $refund);
                    }
                }
            });

            return true;
        } catch (Throwable $e) {
            Log::error('loyalty: sale processing failed', ['sale_id' => $saleId, 'error' => $e->getMessage()]);
            $this->db()->transaction(fn () => LoyaltySale::query()->whereKey($saleId)->update(['status' => LoyaltySale::FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]));

            return false;
        }
    }

    /** SaleVoided: reverse everything the sale earned, redeemed, charged and loaded. */
    public function voided(string $saleId, string $voidId): void
    {
        $this->guard($saleId, function () use ($saleId, $voidId) {
            $sale = LoyaltySale::query()->whereKey($saleId)->lockForUpdate()->first();

            if ($sale === null || $sale->voided_at !== null) {
                return;
            }

            // H1: a void before processing is kept on the sale; processing then posts nothing.
            if ($sale->status !== LoyaltySale::PROCESSED) {
                $sale->forceFill(['voided_at' => now(), 'void_id' => $voidId])->save();

                return;
            }

            $attributes = ['source_type' => 'pos_void', 'source_id' => $voidId, 'sale_id' => $saleId];

            foreach (PointsEntry::query()->where('sale_id', $saleId)->whereIn('kind', ['earn', 'redeem'])->get()->groupBy('account_id') as $accountId => $entries) {
                $account = $this->ledgers->lockAccount($accountId);

                foreach ($entries->groupBy('kind') as $kind => $rows) {
                    $sum = $rows->reduce(fn (BigInteger $s, PointsEntry $e) => $s->plus((string) $e->points), BigInteger::zero());
                    $reversed = PointsEntry::query()->where('sale_id', $saleId)->where('account_id', $accountId)->where('kind', $kind === 'earn' ? 'reverse_earn' : 'reverse_redeem')
                        ->sum('points');
                    $left = $sum->plus((string) $reversed);

                    if (! $left->isZero()) {
                        $this->ledgers->points($account, $kind === 'earn' ? 'reverse_earn' : 'reverse_redeem', (string) $left->negated(), $attributes);
                    }
                }
            }

            foreach (CreditEntry::query()->where('sale_id', $saleId)->where('kind', 'charge')->get()->groupBy('account_id') as $accountId => $rows) {
                $account = $this->ledgers->lockAccount($accountId);
                $charged = $rows->reduce(fn (BigInteger $s, CreditEntry $e) => $s->plus((string) $e->amount_minor), BigInteger::zero());
                $this->ledgers->credit($account, 'reverse_charge', (string) $charged->negated(), $attributes);
            }

            foreach (GiftCardEntry::query()->where('sale_id', $saleId)->whereIn('kind', ['redeem', 'load'])->get()->groupBy('gift_card_id') as $cardId => $rows) {
                $card = $this->ledgers->lockCard($cardId);
                $net = $rows->reduce(fn (BigInteger $s, GiftCardEntry $e) => $s->plus((string) $e->amount_minor), BigInteger::zero());

                // A redemption is put back; a card sold on the voided sale loses its load.
                if (! $net->isZero()) {
                    $this->ledgers->giftCard($card, $net->isNegative() ? 'reverse_redeem' : 'adjust', (string) $net->negated(), $attributes);
                }
            }

            // LOY-09: a voided sale gives its coupon uses back.
            foreach ($sale->applications()->whereNotNull('coupon')->get() as $application) {
                Coupon::query()->where('company_id', $sale->company_id)->where('code', $application->coupon)->where('uses', '>', 0)->decrement('uses');
            }

            $sale->forceFill(['voided_at' => now(), 'void_id' => $voidId, 'points_reversed' => $sale->points_earned])->save();
        });
    }

    /** SaleRefunded: reverse earned points in proportion; put back what the refund paid as points, a card or credit. */
    public function refunded(array $refund): void
    {
        $saleId = $refund['sale_id'] ?? null;

        if ($saleId === null) {
            return;
        }

        $this->guard($saleId, function () use ($refund, $saleId) {
            $sale = LoyaltySale::query()->whereKey($saleId)->lockForUpdate()->first();

            // A replayed SaleRefunded changes nothing (refund_ids, or already queued).
            if ($sale === null || ! isset($refund['id']) || in_array($refund['id'], (array) ($sale->refund_ids ?? []), true)
                || in_array($refund['id'], array_column((array) ($sale->pending_refunds ?? []), 'id'), true)) {
                return;
            }

            // H1: a refund before processing waits on the sale and is applied after the sale's postings.
            if ($sale->status !== LoyaltySale::PROCESSED) {
                $sale->forceFill(['pending_refunds' => [...(array) ($sale->pending_refunds ?? []), $refund]])->save();

                return;
            }

            $this->applyRefund($sale, $refund);
        });
    }

    /** Post one refund on a processed sale (once: its id joins refund_ids). A voided sale has nothing left to give back. */
    private function applyRefund(LoyaltySale $sale, array $refund): void
    {
        $saleId = $sale->id;

        if (in_array($refund['id'], (array) ($sale->refund_ids ?? []), true)) {
            return;
        }

        if ($sale->voided_at !== null) {
            $sale->forceFill(['refund_ids' => [...(array) ($sale->refund_ids ?? []), $refund['id']]])->save();

            return;
        }

        $flags = $sale->flags ?? [];
        $attributes = ['source_type' => 'pos_refund', 'source_id' => $refund['id'], 'sale_id' => $saleId];
        $refunded = BigInteger::of((string) $sale->refunded_minor)->plus((string) $refund['total_minor']);
        $reverse = PromotionEngine::pointsToReverse((string) $sale->points_earned, (string) $sale->total_minor, (string) $refunded, (string) $sale->points_reversed);

        if ($reverse !== '0' && $sale->party_id !== null) {
            $account = $this->ledgers->pointsAccount($sale->company_id, $sale->party_id);
            $this->ledgers->points($account, 'reverse_earn', '-'.$reverse, $attributes);
        }

        foreach ($refund['payments'] ?? [] as $index => $payment) {
            $this->refundTender($sale, $payment, $index, $attributes, $flags);
        }

        $sale->forceFill([
            'refunded_minor' => (string) $refunded,
            'points_reversed' => (string) BigInteger::of((string) $sale->points_reversed)->plus($reverse),
            'flags' => $flags,
            'refund_ids' => [...(array) ($sale->refund_ids ?? []), $refund['id']],
        ])->save();

    }

    /** LOY-12: re-evaluate the promotions the till applied, compare, and count coupon uses. */
    private function promotionsOf(LoyaltySale $sale, array $snapshot, array &$flags): void
    {
        $applied = [];

        foreach ($snapshot['lines'] ?? [] as $line) {
            foreach ($line['adjustments'] ?? [] as $adjustment) {
                if (($adjustment['source'] ?? null) === self::PROMOTION_SOURCE) {
                    $reference = (string) ($adjustment['reference'] ?? '');
                    $applied[$reference] = BigInteger::of((string) $adjustment['amount_minor'])->plus($applied[$reference] ?? 0);
                }
            }
        }

        $loyalty = is_array($snapshot['extensions']['loyalty'] ?? null) ? $snapshot['extensions']['loyalty'] : [];
        $coupons = array_values(array_unique(array_map(fn ($code) => PromotionEngine::normaliseCode((string) $code), array_filter((array) ($loyalty['coupons'] ?? []), 'is_string'))));
        $result = $this->promotions->evaluateSale($sale->company_id, $sale->branch_id, $sale->party_id, $sale->currency, CarbonImmutable::parse($snapshot['sold_at']),
            array_map(fn (array $l) => ['id' => $l['id'], 'item_id' => $l['item_id'], 'qty' => $l['qty'], 'unit_price_minor' => $l['unit_price_minor']], $snapshot['lines'] ?? []), $coupons);
        $expected = collect($result['promotions'])->keyBy('promotion_id');
        $versions = collect((array) ($loyalty['promotions'] ?? []))->keyBy('id');
        $known = Promotion::query()->where('company_id', $sale->company_id)->whereIn('id', array_filter(array_keys($applied), fn ($id) => preg_match('/^[0-9a-f-]{36}$/i', (string) $id) === 1))->pluck('id')->all();

        foreach (array_unique([...array_keys($applied), ...$expected->keys()->all()]) as $reference) {
            $reference = (string) $reference;
            $till = $applied[$reference] ?? BigInteger::zero();
            $server = $expected->has($reference) ? BigInteger::of($expected[$reference]['discount_minor']) : BigInteger::zero();
            $codes = match (true) {
                ! in_array($reference, $known, true) && ! $expected->has($reference) => ['promotion_unknown'],
                ! $expected->has($reference) => ['promotion_not_applicable'],
                $till->isZero() => ['promotion_not_applied'],
                ! $till->isEqualTo($server) => ['promotion_differs'],
                default => [],
            };

            PromotionApplication::create([
                'sale_id' => $sale->id,
                'promotion_id' => in_array($reference, $known, true) || $expected->has($reference) ? $reference : null,
                'reference' => mb_substr($reference, 0, 100),
                'version' => $versions[$reference]['version'] ?? ($expected[$reference]['version'] ?? null),
                'applied_minor' => (string) $till,
                'expected_minor' => (string) $server,
                'coupon' => $expected[$reference]['coupon'] ?? null,
                'flags' => array_map(fn (string $code) => ['code' => $code], $codes),
            ]);

            foreach ($codes as $code) {
                $flags[] = ['code' => $code, 'detail' => ['promotion' => $reference, 'applied_minor' => (string) $till, 'expected_minor' => (string) $server]];
            }
        }

        // LOY-09: each code named on the sale counts one use; past its limit it is flagged.
        foreach ($coupons as $code) {
            $coupon = Coupon::query()->where('company_id', $sale->company_id)->where('code', $code)->lockForUpdate()->first();

            if ($coupon === null) {
                $flags[] = ['code' => 'coupon_unknown', 'detail' => ['coupon' => $code]];

                continue;
            }

            $coupon->increment('uses');

            if ($coupon->max_uses !== null && $coupon->uses > $coupon->max_uses) {
                $flags[] = ['code' => 'coupon_overused', 'detail' => ['coupon' => $code, 'uses' => $coupon->uses, 'max_uses' => $coupon->max_uses]];
            }
        }
    }

    /** LOY-05, LOY-15, LOY-22: points, credit and gift card tenders posted. */
    private function tenders(LoyaltySale $sale, array $snapshot, ?Programme $programme, array &$flags): void
    {
        foreach ($snapshot['payments'] ?? [] as $index => $payment) {
            $tender = (array) ($payment['tender'] ?? []);
            $attributes = ['source_type' => 'pos_sale', 'source_id' => $payment['id'], 'sale_id' => $sale->id, 'occurred_at' => $sale->sold_at];
            $note = function (string $code, array $detail = []) use (&$flags, $index): void {
                $flags[] = ['code' => $code, 'detail' => ['payment' => $index + 1, ...$detail]];
            };

            match ($payment['method_type']) {
                'points' => $this->redeemPoints($sale, $payment, $tender, $programme, $attributes, $note),
                'voucher' => $this->redeemCard($sale, $payment, $tender, $attributes, $note),
                'credit' => $this->charge($sale, $snapshot, $payment, $attributes, $note),
                default => null,
            };
        }
    }

    private function redeemPoints(LoyaltySale $sale, array $payment, array $tender, ?Programme $programme, array $attributes, \Closure $note): void
    {
        $points = (string) ($tender['points'] ?? '');

        if ($sale->party_id === null) {
            $note('points_no_customer');

            return;
        }

        if (preg_match('/^[1-9]\d{0,17}$/', $points) !== 1) {
            // Without a count, the points the amount is worth at the programme's value.
            $points = $programme === null ? '' : (string) BigInteger::of((string) $payment['amount_minor'])->quotient((string) $programme->point_value_minor);
            $note('points_invalid');

            if ($points === '' || $points === '0') {
                return;
            }
        }

        $account = $this->ledgers->pointsAccount($sale->company_id, $sale->party_id);
        $this->ledgers->points($account, 'redeem', '-'.$points, $attributes);

        if (BigInteger::of((string) $account->balance)->isNegative()) {
            $note('points_overdrawn', ['balance' => (string) $account->balance]);
        }
    }

    private function redeemCard(LoyaltySale $sale, array $payment, array $tender, array $attributes, \Closure $note): void
    {
        $card = isset($tender['code_hash']) ? GiftCard::query()->where('code_hash', GiftCards::hashProof((string) $tender['code_hash']))->where('company_id', $sale->company_id)->first() : null;

        if ($card === null) {
            $note('gift_card_unknown');

            return;
        }

        $card = $this->ledgers->lockCard($card->id);
        $amount = match ($card->currency) {
            $payment['currency'] => (string) $payment['amount_minor'],
            $sale->currency => (string) $payment['amount_in_sale_minor'],
            default => null,
        };

        if ($amount === null) {
            $note('gift_card_currency_mismatch', ['card' => $card->last4]);

            return;
        }

        foreach ([GiftCard::INACTIVE => 'gift_card_inactive', GiftCard::BLOCKED => 'gift_card_blocked'] as $status => $code) {
            if ($card->status === $status) {
                $note($code, ['card' => $card->last4]);
            }
        }

        if ($card->isExpired($sale->sold_at->format('Y-m-d'))) {
            $note('gift_card_expired', ['card' => $card->last4]);
        }

        $this->ledgers->giftCard($card, 'redeem', '-'.$amount, $attributes);

        if (BigInteger::of((string) $card->balance_minor)->isNegative()) {
            $note('gift_card_overdrawn', ['card' => $card->last4, 'balance' => (string) $card->balance_minor]);
        }

        $this->notices->giftCardLevel($card, $this->programmes->for($card->company_id));
    }

    private function charge(LoyaltySale $sale, array $snapshot, array $payment, array $attributes, \Closure $note): void
    {
        if ($sale->party_id === null) {
            $note('credit_no_customer');

            return;
        }

        $account = $this->ledgers->lock($sale->company_id, $sale->party_id, Account::CREDIT);

        if ($account === null || ! $account->isOpen()) {
            // Device wins: the goods left on credit, so the debt is recorded on an account opened for it.
            $note('credit_no_account');
            $account ??= $this->openForSale($sale);
        }

        $amount = $this->inAccountCurrency($account, $snapshot, $payment);

        if ($amount === null) {
            $note('credit_currency_mismatch', ['currency' => $payment['currency']]);

            return;
        }

        if ($payment['currency'] !== $account->currency) {
            $note('credit_currency_mismatch', ['currency' => $payment['currency']]);
        }

        $cashier = isset($snapshot['cashier_id']) ? User::query()->find($snapshot['cashier_id']) : null;

        if ($cashier === null || ! $cashier->can('loyalty.credit.sell', Scope::location($snapshot['location_id']))) {
            $note('credit_not_permitted');
        }

        $this->ledgers->credit($account, 'charge', $amount, [...$attributes, 'reference' => $sale->receipt_number]);
        $excess = $this->credit->excess($account, BigInteger::of((string) $account->balance));

        if ($excess !== null && $excess->isPositive()) {
            $note('credit_limit_exceeded', ['excess_minor' => (string) $excess]);
        }

        $this->notices->creditLevel($account, $this->programmes->for($account->company_id));
    }

    /** LOY-21: cards sold on the sale (extensions.loyalty.gift_cards) are loaded and activated. */
    /**
     * LOY-21, M2: cards sold on the sale (`extensions.loyalty.gift_cards`,
     * `[{line_id, code_hash, amount_minor}]`, `code_hash` the code's proof,
     * H(code)). Each must name a line of the sale whose item is the
     * programme's gift card item; the card is loaded with the smaller of
     * the amount and the line's total, in the card's currency (the sale's).
     * Anything else is flagged `gift_card_sale_mismatch`, never a failure.
     */
    private function giftCardSales(LoyaltySale $sale, array $snapshot, ?Programme $programme, array &$flags): void
    {
        $sold = $snapshot['extensions']['loyalty']['gift_cards'] ?? [];
        $lines = collect(is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [])->keyBy('id');
        $mismatch = function (int $index, string $reason) use (&$flags): void {
            $flags[] = ['code' => 'gift_card_sale_mismatch', 'detail' => ['sold' => $index + 1, 'reason' => $reason]];
        };

        if (! is_array($sold)) {
            $mismatch(0, 'malformed');

            return;
        }

        foreach (array_values($sold) as $index => $entry) {
            if (! is_array($entry) || ! is_string($entry['line_id'] ?? null) || ! is_string($entry['code_hash'] ?? null)
                || preg_match('/^\d{1,18}$/', (string) ($entry['amount_minor'] ?? '')) !== 1) {
                $mismatch($index, 'malformed');

                continue;
            }

            $line = $lines->get($entry['line_id']);

            if ($line === null || $programme?->gift_card_item_id === null || ($line['item_id'] ?? null) !== $programme->gift_card_item_id) {
                $mismatch($index, 'line');

                continue;
            }

            $card = GiftCard::query()->where('code_hash', GiftCards::hashProof($entry['code_hash']))->where('company_id', $sale->company_id)->first();

            if ($card === null) {
                $flags[] = ['code' => 'gift_card_unknown', 'detail' => ['sold' => $index + 1]];

                continue;
            }

            if ($card->currency !== $sale->currency) {
                $mismatch($index, 'currency');

                continue;
            }

            $amount = BigInteger::min(BigInteger::of($entry['amount_minor']), BigInteger::of((string) ($line['total_minor'] ?? '0')));

            if (! $amount->isPositive()) {
                $mismatch($index, 'amount');

                continue;
            }

            if (! $amount->isEqualTo($entry['amount_minor'])) {
                $mismatch($index, 'amount');
            }

            $card = $this->ledgers->lockCard($card->id);
            $soldFlags = [];

            if ($card->status !== GiftCard::INACTIVE) {
                $soldFlags[] = ['code' => 'gift_card_already_active'];
                $flags[] = ['code' => 'gift_card_already_active', 'detail' => ['card' => $card->last4]];
            }

            $this->giftCards->load($card, (string) $amount, 'pos_sale', $entry['line_id'], $sale->receipt_number, null, $sale->id, $soldFlags);
        }
    }

    /** LOY-03: points earned on the sale's total less what points paid and gift cards sold, in the programme currency. */
    private function earn(LoyaltySale $sale, array $snapshot, ?Programme $programme, array &$flags): void
    {
        if ($programme === null || $sale->party_id === null) {
            return;
        }

        $eligible = BigInteger::of((string) $snapshot['totals']['total_minor']);

        foreach ($snapshot['payments'] ?? [] as $payment) {
            if ($payment['method_type'] === 'points') {
                $eligible = $eligible->minus((string) $payment['amount_in_sale_minor']);
            }
        }

        $giftLines = array_column((array) ($snapshot['extensions']['loyalty']['gift_cards'] ?? []), 'line_id');

        foreach ($snapshot['lines'] ?? [] as $line) {
            if (in_array($line['id'], $giftLines, true)) {
                $eligible = $eligible->minus((string) $line['total_minor']);
            }
        }

        if (! $eligible->isPositive()) {
            return;
        }

        $inProgramme = $this->toProgrammeCurrency($eligible, $sale->currency, $programme->currency, $snapshot);

        if ($inProgramme === null) {
            $flags[] = ['code' => 'points_currency', 'detail' => ['currency' => $sale->currency]];

            return;
        }

        $points = PromotionEngine::pointsEarned((string) $inProgramme, (int) $programme->earn_points, (string) $programme->earn_spend_minor);

        if ($points === '0') {
            return;
        }

        $account = $this->ledgers->pointsAccount($sale->company_id, $sale->party_id);
        $this->ledgers->points($account, 'earn', $points, [
            'source_type' => 'pos_sale', 'source_id' => $sale->id, 'sale_id' => $sale->id, 'occurred_at' => $sale->sold_at,
            'expires_at' => $programme->expiry_months === null ? null : $sale->sold_at->addMonthsNoOverflow($programme->expiry_months),
        ]);
        $sale->points_earned = $points;
    }

    /** LOY-25: money given back as points, onto a card or off a credit account. */
    private function refundTender(LoyaltySale $sale, array $payment, int $index, array $attributes, array &$flags): void
    {
        $tender = (array) ($payment['tender'] ?? []);
        $attributes = [...$attributes, 'source_id' => $payment['id'] ?? $attributes['source_id']];
        $note = function (string $code) use (&$flags, $index) {
            $flags[] = ['code' => $code, 'detail' => ['refund_payment' => $index + 1]];
        };

        if ($payment['method_type'] === 'voucher') {
            $card = isset($tender['code_hash']) ? GiftCard::query()->where('code_hash', GiftCards::hashProof((string) $tender['code_hash']))->where('company_id', $sale->company_id)->first() : null;

            if ($card === null) {
                $note('gift_card_unknown');

                return;
            }

            $card = $this->ledgers->lockCard($card->id);
            $this->ledgers->giftCard($card, 'refund_credit', (string) $payment['amount_minor'], $attributes);

            return;
        }

        if ($sale->party_id === null || ! in_array($payment['method_type'], ['points', 'credit'], true)) {
            return;
        }

        if ($payment['method_type'] === 'points') {
            $programme = $this->programmes->for($sale->company_id);
            $points = (string) ($tender['points'] ?? ($programme === null ? '0' : BigInteger::of((string) $payment['amount_minor'])->quotient((string) $programme->point_value_minor)));

            if ($points !== '0' && preg_match('/^\d{1,18}$/', $points) === 1) {
                $this->ledgers->points($this->ledgers->pointsAccount($sale->company_id, $sale->party_id), 'refund_credit', $points, $attributes);
            }

            return;
        }

        $account = $this->ledgers->lock($sale->company_id, $sale->party_id, Account::CREDIT);

        if ($account === null) {
            $note('credit_no_account');

            return;
        }

        $amount = $payment['currency'] === $account->currency ? (string) $payment['amount_minor'] : null;

        if ($amount === null) {
            $note('credit_currency_mismatch');

            return;
        }

        $this->ledgers->credit($account, 'refund_credit', '-'.$amount, $attributes);
    }

    /** The credit payment in the account's currency: as paid, else its share of the sale, else at the sale's base rate. */
    private function inAccountCurrency(Account $account, array $snapshot, array $payment): ?string
    {
        if ($payment['currency'] === $account->currency) {
            return (string) $payment['amount_minor'];
        }

        if ($snapshot['currency'] === $account->currency) {
            return (string) $payment['amount_in_sale_minor'];
        }

        $converted = $this->toProgrammeCurrency(BigInteger::of((string) $payment['amount_in_sale_minor']), $snapshot['currency'], $account->currency, $snapshot);

        return $converted === null ? null : (string) $converted;
    }

    /** An amount of the sale converted with the sale's own base snapshot (CUR-04), or null. */
    private function toProgrammeCurrency(BigInteger $amount, string $from, string $to, array $snapshot): ?BigInteger
    {
        if ($from === $to) {
            return $amount;
        }

        $fx = $snapshot['base']['fx'] ?? null;

        if (($snapshot['base']['currency'] ?? null) !== $to || ! is_array($fx) || ! in_array($from, [$fx['base'] ?? null, $fx['quote'] ?? null], true)) {
            return null;
        }

        $rate = new FxSnapshot((string) $fx['rate'], (string) $fx['base'], (string) $fx['quote'], $fx['kind'] ?? null, null);

        return BigInteger::of($rate->convert(Money::ofMinor((string) $amount, $from))->minor());
    }

    private function openForSale(LoyaltySale $sale): Account
    {
        $party = Party::query()->findOrFail($sale->party_id);

        Account::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => app(TenantContext::class)->require(),
            'company_id' => $sale->company_id,
            'party_id' => $party->id,
            'kind' => Account::CREDIT,
            'currency' => $party->creditLimit()?->currency() ?? $sale->currency,
            'balance' => 0,
            'status' => Account::OPEN,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->ledgers->lock($sale->company_id, $party->id, Account::CREDIT);
    }

    /** Run a reversal in a transaction; a failure is logged and kept on the sale, never thrown. */
    private function guard(string $saleId, \Closure $fn): void
    {
        try {
            $this->db()->transaction($fn);
        } catch (Throwable $e) {
            Log::error('loyalty: reversal failed', ['sale_id' => $saleId, 'error' => $e->getMessage()]);
            $this->db()->transaction(fn () => LoyaltySale::query()->whereKey($saleId)->update(['error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]));
        }
    }

    private function db(): Connection
    {
        return DB::connection(TenantContext::CONNECTION);
    }
}
