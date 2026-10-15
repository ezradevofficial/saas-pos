<?php

namespace Modules\Loyalty\Ledgers;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Modules\Loyalty\Events\CreditAdjusted;
use Modules\Loyalty\Events\CreditCharged;
use Modules\Loyalty\Events\CreditPaymentReceived;
use Modules\Loyalty\Events\GiftCardExpired;
use Modules\Loyalty\Events\GiftCardRedeemed;
use Modules\Loyalty\Events\GiftCardSold;
use Modules\Loyalty\Events\LoyaltyLedgerEvent;
use Modules\Loyalty\Events\PointsAdjusted;
use Modules\Loyalty\Events\PointsEarned;
use Modules\Loyalty\Events\PointsExpired;
use Modules\Loyalty\Events\PointsRedeemed;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\CreditEntry;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\GiftCardEntry;
use Modules\Loyalty\Models\LoyaltySale;
use Modules\Loyalty\Models\PointsEntry;
use Modules\Loyalty\Programmes;

/**
 * Stage 7B: which event each ledger entry raises, and its amounts in the
 * event's own direction (LoyaltyLedgerEvent). Raised by Ledgers for every
 * entry it writes, so no posting path can miss one; dispatched after the
 * entry's transaction commits. replay() builds them again for a period
 * (core EventReplays, the Accounting backfill).
 *
 * | Ledger    | Entry kinds                              | Event                 | Sign  |
 * | points    | earn, reverse_earn                       | PointsEarned          | as is |
 * | points    | redeem, reverse_redeem, refund_credit    | PointsRedeemed        | −     |
 * | points    | expire                                   | PointsExpired         | −     |
 * | points    | adjust                                   | PointsAdjusted        | as is |
 * | gift card | load, adjust (a voided sale's load)      | GiftCardSold          | as is |
 * | gift card | redeem, reverse_redeem, refund_credit    | GiftCardRedeemed      | −     |
 * | gift card | expire                                   | GiftCardExpired       | −     |
 * | credit    | charge, reverse_charge, refund_credit    | CreditCharged         | as is |
 * | credit    | payment                                  | CreditPaymentReceived | −     |
 * | credit    | adjust                                   | CreditAdjusted        | as is |
 */
class LedgerEvents
{
    /** @var array<string, array{0: ?string, 1: ?array<string, mixed>}> a sale's branch and rate, per sale (one lookup a request) */
    private array $sales = [];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Programmes $programmes,
    ) {}

    public function points(PointsEntry $entry, Account $account, bool $dispatch = true): LoyaltyLedgerEvent
    {
        [$class, $sign] = match ($entry->kind) {
            'earn', 'reverse_earn' => [PointsEarned::class, 1],
            'redeem', 'reverse_redeem', 'refund_credit' => [PointsRedeemed::class, -1],
            'expire' => [PointsExpired::class, -1],
            default => [PointsAdjusted::class, 1],
        };
        $points = self::signed((string) $entry->points, $sign);
        $programme = $this->programmes->for($account->company_id);
        // Review #7: the value stored with the entry, never today's (live and replayed alike).
        $value = $entry->point_value_minor === null ? null : (string) BigInteger::of($points)->multipliedBy((string) $entry->point_value_minor);
        $currency = $programme?->currency ?? '';

        return $this->raise($dispatch, $class, 'points', $entry->id, $entry->kind, $account->id, $account->company_id, $account->party_id, $entry->sale_id,
            (string) $entry->source_type, $entry->occurred_at, $currency, $value, $points, null);
    }

    public function giftCard(GiftCardEntry $entry, GiftCard $card, bool $dispatch = true): LoyaltyLedgerEvent
    {
        [$class, $sign] = match ($entry->kind) {
            'redeem', 'reverse_redeem', 'refund_credit' => [GiftCardRedeemed::class, -1],
            'expire' => [GiftCardExpired::class, -1],
            default => [GiftCardSold::class, 1],
        };

        return $this->raise($dispatch, $class, 'gift_card', $entry->id, $entry->kind, $card->id, $card->company_id, null, $entry->sale_id,
            (string) $entry->source_type, $entry->occurred_at, (string) $entry->currency, self::signed((string) $entry->amount_minor, $sign), null, $entry->reference);
    }

    public function credit(CreditEntry $entry, Account $account, bool $dispatch = true): LoyaltyLedgerEvent
    {
        [$class, $sign] = match ($entry->kind) {
            'payment' => [CreditPaymentReceived::class, -1],
            'adjust' => [CreditAdjusted::class, 1],
            default => [CreditCharged::class, 1],
        };

        return $this->raise($dispatch, $class, 'credit', $entry->id, $entry->kind, $account->id, $account->company_id, $account->party_id, $entry->sale_id,
            (string) $entry->source_type, $entry->occurred_at, (string) $entry->currency, self::signed((string) $entry->amount_minor, $sign), null, $entry->reference);
    }

    /** @param class-string<LoyaltyLedgerEvent> $class */
    private function raise(bool $dispatch, string $class, string $ledger, string $entryId, string $kind, string $recordId, string $companyId, ?string $partyId, ?string $saleId,
        string $sourceType, mixed $occurredAt, string $currency, ?string $amount, ?string $points, ?string $reference): LoyaltyLedgerEvent
    {
        [$branchId, $sale] = $saleId === null ? [null, null] : $this->sale($saleId);
        // ACC-17 (stage 7B closing review): an entry without its own reference (a till redemption) names its sale's receipt.
        $reference ??= $sale['receipt_number'] ?? null;
        $fx = $sale !== null && ($sale['currency'] ?? null) === $currency ? ($sale['fx'] ?? null) : null;
        $at = CarbonImmutable::parse($occurredAt ?? 'now')->utc()->format('Y-m-d\TH:i:s.u\Z');

        $event = new $class($this->tenants->require(), $companyId, $ledger, $entryId, $kind, $recordId, $partyId, $branchId, $saleId,
            self::channel($sourceType), $at, $currency, $amount, $points, is_array($fx) ? $fx : null, $reference);

        if ($dispatch) {
            event($event);
        }

        return $event;
    }

    /** Where an entry came from, by its ledger source type. */
    private static function channel(string $sourceType): string
    {
        return match ($sourceType) {
            'pos_sale', 'pos_void', 'pos_refund' => 'pos',
            'issue' => 'issued',
            'expiry' => 'system',
            default => 'back_office',
        };
    }

    /** @return array{0: ?string, 1: ?array{currency: ?string, fx: mixed, receipt_number: ?string}} the sale's branch, its currency and rate, and its receipt number */
    private function sale(string $saleId): array
    {
        if (! array_key_exists($saleId, $this->sales)) {
            $row = LoyaltySale::query()->whereKey($saleId)->selectRaw("branch_id, currency, snapshot->'base'->'fx' as fx, snapshot->>'receipt_number' as receipt_number")->first();
            $this->sales[$saleId] = $row === null ? [null, null] : [$row->branch_id, ['currency' => $row->currency, 'fx' => is_string($row->fx) ? json_decode($row->fx, true) : null,
                'receipt_number' => $row->receipt_number]];
        }

        return $this->sales[$saleId];
    }

    private static function signed(string $amount, int $sign): string
    {
        return $sign === 1 ? $amount : (string) BigInteger::of($amount)->negated();
    }

    /**
     * The events of the entries of [$from, $to], built again (not dispatched).
     *
     * @param  \Closure(object): void  $emit
     */
    public function replay(CarbonImmutable $from, CarbonImmutable $to, \Closure $emit): void
    {
        foreach (PointsEntry::query()->whereBetween('occurred_at', [$from, $to])->lazyById(200) as $entry) {
            $emit($this->points($entry, Account::query()->findOrFail($entry->account_id), false));
        }

        foreach (GiftCardEntry::query()->whereBetween('occurred_at', [$from, $to])->lazyById(200) as $entry) {
            $emit($this->giftCard($entry, GiftCard::query()->findOrFail($entry->gift_card_id), false));
        }

        foreach (CreditEntry::query()->whereBetween('occurred_at', [$from, $to])->lazyById(200) as $entry) {
            $emit($this->credit($entry, Account::query()->findOrFail($entry->account_id), false));
        }
    }
}
