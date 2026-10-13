<?php

namespace Modules\Loyalty\Sync;

use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\Sources\Iso;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\LoyaltyServiceProvider;

/**
 * LOY-21..LOY-23, NFR-04: the gift cards of the till's company, by code
 * hash (never the code): the till hashes what it scans and finds the
 * card, its balance, status and expiry offline. Inactive cards come too,
 * so the till can sell them.
 */
class GiftCardSource implements IncrementalSource
{
    public function key(): string
    {
        return 'loyalty_gift_cards';
    }

    public function module(): string
    {
        return LoyaltyServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function table(): string
    {
        return 'loyalty_gift_cards';
    }

    public function visible(Builder $query, DeviceScope $scope): Builder
    {
        return $query->where('loyalty_gift_cards.company_id', $scope->companyId());
    }

    public function rows(array $ids, DeviceScope $scope): array
    {
        return $this->visible(DB::connection(TenantContext::CONNECTION)->table('loyalty_gift_cards'), $scope)
            ->whereIn('id', $ids)
            ->get(['id', 'kind', 'code_hash', 'last4', 'currency', 'initial_minor', 'balance_minor', 'status', 'expires_on', 'updated_at'])
            ->mapWithKeys(fn (object $c) => [$c->id => [
                'id' => $c->id,
                'kind' => $c->kind,
                'code_hash' => $c->code_hash,
                'last4' => $c->last4,
                'currency' => $c->currency,
                'initial_minor' => (string) $c->initial_minor,
                'balance_minor' => (string) $c->balance_minor,
                'status' => $c->status,
                'expires_on' => $c->expires_on,
                'updated_at' => Iso::of($c->updated_at),
            ]])
            ->all();
    }
}
