<?php

namespace Modules\Loyalty\Http\Resources;

use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Credit\Ageing;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\CreditEntry;

/**
 * LOY-02, LOY-14: a points or credit account. Credit accounts show the
 * core party's limit (MD-01) and what is left of it; money is
 * `{amount_minor, currency}`, points a digit string.
 */
class AccountResource extends JsonResource
{
    /** LOY-18: when set (the ageing report), each credit account shows its open amounts by age at this moment. */
    public static ?CarbonImmutable $ageingAsOf = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Account $account */
        $account = $this->resource;
        $limit = $account->relationLoaded('party') ? $account->party?->creditLimit() : null;
        $credit = $account->kind === Account::CREDIT;

        return [
            'id' => $account->id,
            'kind' => $account->kind,
            'company' => $account->relationLoaded('company') ? ['id' => $account->company_id, 'name' => $account->company?->name] : ['id' => $account->company_id],
            'party' => $account->relationLoaded('party') ? ['id' => $account->party_id, 'name' => $account->party?->name] : ['id' => $account->party_id],
            'status' => $account->status,
            'currency' => $account->currency,
            'points' => $credit ? null : (string) $account->balance,
            'balance' => $credit ? ['amount_minor' => (string) $account->balance, 'currency' => $account->currency] : null,
            'credit_limit' => $credit && $limit !== null ? $limit->jsonSerialize() : null,
            'available' => $credit && $limit !== null && $limit->currency() === $account->currency
                ? ['amount_minor' => (string) BigInteger::of($limit->minor())->minus((string) $account->balance), 'currency' => $account->currency]
                : null,
            'opened_at' => $account->opened_at?->toIso8601String(),
            'closed_at' => $account->closed_at?->toIso8601String(),
            'ageing' => $this->when($credit && self::$ageingAsOf !== null, fn () => Ageing::of(
                CreditEntry::query()->where('account_id', $account->id)->where('occurred_at', '<=', self::$ageingAsOf)->get(),
                self::$ageingAsOf,
                ($account->relationLoaded('company') ? $account->company?->timezone : null) ?: 'UTC',
            )),
        ];
    }
}
