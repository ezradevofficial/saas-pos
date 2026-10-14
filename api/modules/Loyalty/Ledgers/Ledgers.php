<?php

namespace Modules\Loyalty\Ledgers;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\CreditEntry;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\GiftCardEntry;
use Modules\Loyalty\Models\PointsEntry;
use Modules\Loyalty\Models\Programme;

/**
 * The three append-only ledgers (points, credit, gift cards) and their
 * cached balances. Every posting runs under the row lock of its account
 * or card, inside the caller's transaction: concurrent postings (two
 * tills spending one card) are serialised and no update is lost (LOY-22).
 * A posting names its source (`pos_sale` and the payment, `manual`, …);
 * the same source posted again is ignored, so retries are harmless.
 * Every entry written raises its ledger event after commit (LedgerEvents,
 * stage 7B), so Accounting can journal it without reading these tables.
 */
class Ledgers
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly LedgerEvents $events,
    ) {}

    /** LOY-02: the customer's points account at the company, locked; opened when missing. */
    public function pointsAccount(string $companyId, string $partyId, ?string $userId = null): Account
    {
        DB::connection(TenantContext::CONNECTION)->table('loyalty_accounts')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenants->require(),
            'company_id' => $companyId,
            'party_id' => $partyId,
            'kind' => Account::POINTS,
            'balance' => 0,
            'status' => Account::OPEN,
            'opened_at' => now(),
            'opened_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->lock($companyId, $partyId, Account::POINTS) ?? throw new \RuntimeException('Points account missing after insert.');
    }

    /** The account of $kind, locked for this transaction, or null. */
    public function lock(string $companyId, string $partyId, string $kind): ?Account
    {
        return Account::query()->where('company_id', $companyId)->where('party_id', $partyId)->where('kind', $kind)->lockForUpdate()->first();
    }

    public function lockAccount(string $accountId): Account
    {
        return Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
    }

    public function lockCard(string $cardId): GiftCard
    {
        return GiftCard::query()->whereKey($cardId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Post $points (signed) to a locked points account; null when this
     * source was already posted.
     *
     * @param  array{source_type: string, source_id?: ?string, sale_id?: ?string, expires_at?: ?CarbonImmutable, reason?: ?string, flags?: list<array<string, mixed>>, created_by?: ?string, occurred_at?: ?CarbonImmutable}  $attributes
     */
    public function points(Account $account, string $kind, string $points, array $attributes): ?PointsEntry
    {
        if ($this->posted(PointsEntry::query()->where('account_id', $account->id), $kind, $attributes)) {
            return null;
        }

        $entry = PointsEntry::create([
            'account_id' => $account->id,
            'company_id' => $account->company_id,
            'party_id' => $account->party_id,
            'kind' => $kind,
            'points' => $points,
            // Review #7: the value of a point when the entry is written (null: a point is worth nothing).
            'point_value_minor' => $this->pointValue($account->company_id),
            'occurred_at' => $attributes['occurred_at'] ?? CarbonImmutable::now(),
            'flags' => $attributes['flags'] ?? [],
            ...array_intersect_key($attributes, array_flip(['source_type', 'source_id', 'sale_id', 'expires_at', 'reason', 'created_by'])),
        ]);

        $account->forceFill(['balance' => (string) BigInteger::of((string) $account->balance)->plus($points)])->save();
        $this->events->points($entry, $account);

        return $entry;
    }

    /**
     * Post $amount (signed minor units of the account's currency; positive:
     * the customer owes more) to a locked credit account.
     *
     * @param  array{source_type: string, source_id?: ?string, sale_id?: ?string, reference?: ?string, note?: ?string, flags?: list<array<string, mixed>>, created_by?: ?string, occurred_at?: ?CarbonImmutable}  $attributes
     */
    public function credit(Account $account, string $kind, string $amount, array $attributes): ?CreditEntry
    {
        if ($this->posted(CreditEntry::query()->where('account_id', $account->id), $kind, $attributes)) {
            return null;
        }

        $entry = CreditEntry::create([
            'account_id' => $account->id,
            'company_id' => $account->company_id,
            'party_id' => $account->party_id,
            'kind' => $kind,
            'amount_minor' => $amount,
            'currency' => $account->currency,
            'occurred_at' => $attributes['occurred_at'] ?? CarbonImmutable::now(),
            'flags' => $attributes['flags'] ?? [],
            ...array_intersect_key($attributes, array_flip(['source_type', 'source_id', 'sale_id', 'reference', 'note', 'created_by'])),
        ]);

        $account->forceFill(['balance' => (string) BigInteger::of((string) $account->balance)->plus($amount)])->save();
        $this->events->credit($entry, $account);

        return $entry;
    }

    /**
     * Post $amount (signed minor units of the card's currency) to a locked
     * gift card.
     *
     * @param  array{source_type: string, source_id?: ?string, sale_id?: ?string, reference?: ?string, flags?: list<array<string, mixed>>, created_by?: ?string, occurred_at?: ?CarbonImmutable}  $attributes
     */
    public function giftCard(GiftCard $card, string $kind, string $amount, array $attributes): ?GiftCardEntry
    {
        if ($this->posted(GiftCardEntry::query()->where('gift_card_id', $card->id), $kind, $attributes)) {
            return null;
        }

        $entry = GiftCardEntry::create([
            'gift_card_id' => $card->id,
            'company_id' => $card->company_id,
            'kind' => $kind,
            'amount_minor' => $amount,
            'currency' => $card->currency,
            'occurred_at' => $attributes['occurred_at'] ?? CarbonImmutable::now(),
            'flags' => $attributes['flags'] ?? [],
            ...array_intersect_key($attributes, array_flip(['source_type', 'source_id', 'sale_id', 'reference', 'created_by'])),
        ]);

        $card->forceFill(['balance_minor' => (string) BigInteger::of((string) $card->balance_minor)->plus($amount)])->save();
        $this->events->giftCard($entry, $card);

        return $entry;
    }

    private function pointValue(string $companyId): ?string
    {
        $value = Programme::query()->where('company_id', $companyId)->value('point_value_minor');

        return $value === null || (int) $value <= 0 ? null : (string) $value;
    }

    /** Whether the same source was already posted with $kind (checked under the account's lock). */
    private function posted($query, string $kind, array $attributes): bool
    {
        $sourceId = $attributes['source_id'] ?? null;

        return $sourceId !== null && $query->where('kind', $kind)->where('source_type', $attributes['source_type'])->where('source_id', $sourceId)->exists();
    }
}
