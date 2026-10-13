<?php

namespace Modules\Loyalty\Tenders;

use App\Core\Identity\Models\User;
use App\Core\Payments\Tenders\TenderProvider;
use App\Core\Payments\Tenders\TenderUse;
use App\Core\Rbac\Scope;
use Brick\Math\BigInteger;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Programmes;

/**
 * LOY-05, LOY-15, LOY-22, LOY-27: the tenders this module provides
 * (core TenderProviders): points (`points`), gift cards and vouchers
 * (`voucher`) and store credit (`credit`). POS asks for review flags when a
 * till uploads a sale or a refund; balances are checked when the sale is
 * posted (PosSales), under the account's or card's lock.
 */
final class LoyaltyTenders implements TenderProvider
{
    public function __construct(private readonly string $type) {}

    public function type(): string
    {
        return $this->type;
    }

    public function module(): string
    {
        return LoyaltyServiceProvider::MODULE;
    }

    public function check(TenderUse $use): array
    {
        return match ($this->type) {
            'points' => $this->points($use),
            'voucher' => $this->giftCard($use),
            'credit' => $this->credit($use),
            default => [],
        };
    }

    /** @return list<string> */
    private function points(TenderUse $use): array
    {
        if ($use->customerId === null) {
            return ['points_no_customer'];
        }

        $points = (string) ($use->tender['points'] ?? '');

        if (preg_match('/^[1-9]\d{0,17}$/', $points) !== 1) {
            return ['points_invalid'];
        }

        $programme = app(Programmes::class)->active($use->companyId);

        if ($programme === null) {
            return ['points_programme_inactive'];
        }

        $flags = [];

        if ($use->currency !== $programme->currency || ! BigInteger::of($points)->multipliedBy((string) $programme->point_value_minor)->isEqualTo($use->amountMinor)) {
            $flags[] = 'points_value_mismatch';
        }

        if ($use->kind === TenderUse::SALE && BigInteger::of($points)->isLessThan((string) $programme->min_redeem_points)) {
            $flags[] = 'points_below_minimum';
        }

        return $flags;
    }

    /** @return list<string> */
    private function giftCard(TenderUse $use): array
    {
        $hash = strtolower((string) ($use->tender['code_hash'] ?? ''));

        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            return ['gift_card_invalid'];
        }

        $card = GiftCard::query()->where('code_hash', $hash)->where('company_id', $use->companyId)->first();

        if ($card === null) {
            return ['gift_card_unknown'];
        }

        if ($use->kind === TenderUse::REFUND) {
            return [];
        }

        return array_values(array_filter([
            $card->status === GiftCard::INACTIVE ? 'gift_card_inactive' : null,
            $card->status === GiftCard::BLOCKED ? 'gift_card_blocked' : null,
            $card->isExpired() ? 'gift_card_expired' : null,
            $card->currency !== $use->currency ? 'gift_card_currency_mismatch' : null,
        ]));
    }

    /** @return list<string> */
    private function credit(TenderUse $use): array
    {
        if ($use->customerId === null) {
            return ['credit_no_customer'];
        }

        $account = Account::query()->where('company_id', $use->companyId)->where('party_id', $use->customerId)->where('kind', Account::CREDIT)->first();
        $flags = [];

        if ($account === null || ! $account->isOpen()) {
            $flags[] = 'credit_no_account';
        } elseif ($account->currency !== $use->currency) {
            $flags[] = 'credit_currency_mismatch';
        }

        $user = $use->userId === null ? null : User::query()->find($use->userId);

        if ($use->kind === TenderUse::SALE && ($user === null || ! $user->can('loyalty.credit.sell', Scope::location($use->locationId)))) {
            $flags[] = 'credit_not_permitted';
        }

        return $flags;
    }
}
